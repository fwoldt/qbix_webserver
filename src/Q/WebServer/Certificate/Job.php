<?php
/**
 * @module Q
 */

/**
 * Runs certificate work that takes a while -- an ACME issuance, a certbot
 * renewal, a download -- away from the server's event loop, so the server
 * keeps answering meanwhile (and can answer the CA's own validation request).
 *
 * A job is a forked child: it has every class the server has loaded, does its
 * work, writes its outcome to a state file and ends with SIGKILL, so it never
 * closes a descriptor it inherited -- a TLS connection closed by a child would
 * send close_notify on the parent's socket. The server reaps it like any
 * other child. Where fork is unavailable the work runs inline.
 *
 * The state file remembers the last attempt and, after a failure, when to try
 * again: 5 minutes, doubling up to a day, so a CA that is down or refusing is
 * not hammered and its rate limits are not spent.
 *
 * @class Q_WebServer_Certificate_Job
 * @static
 */
class Q_WebServer_Certificate_Job
{
	const BACKOFF_FIRST = 300;
	const BACKOFF_MAX = 86400;

	/** @var array name => pid of jobs this process started */
	private static $pids = array();

	/**
	 * Start a job unless it is running or backing off.
	 * @method start
	 * @static
	 * @param {string} $name
	 * @param {string} $stateFile
	 * @param {callable} $work returns array('ok' => bool, 'error' => string, ...)
	 * @param {boolean} $force ignore the backoff
	 * @return {boolean} whether it started
	 */
	static function start($name, $stateFile, callable $work, $force = false)
	{
		if (self::running($name)) return false;
		$state = self::state($stateFile);
		if (!$force and !empty($state['nextAttempt']) and time() < $state['nextAttempt']) return false;
		self::write($stateFile, array('running' => true, 'started' => time()) + $state);
		$pid = (class_exists('Q_WebServer_Fork') and Q_WebServer_Fork::available()) ? Q_WebServer_Fork::fork() : -1;
		if ($pid > 0) {
			self::$pids[$name] = $pid;
			Q_WebServer_Certificate_Events::emit('job', array('name' => $name, 'pid' => $pid));
			return true;
		}
		if ($pid === 0) {
			self::finish($stateFile, $work);
			if (function_exists('posix_kill')) posix_kill(getmypid(), SIGKILL);
			exit(0);
		}
		self::finish($stateFile, $work);
		return true;
	}

	/** Whether a job this process started is still alive. */
	static function running($name)
	{
		if (empty(self::$pids[$name])) return false;
		$pid = self::$pids[$name];
		$alive = function_exists('posix_kill') ? @posix_kill($pid, 0) : false;
		if ($alive and is_readable("/proc/$pid/stat")) {
			// Reaped already, or a zombie waiting to be: done either way.
			$stat = (string) @file_get_contents("/proc/$pid/stat");
			if (preg_match('/^\d+ \(.*\) Z/', $stat)) $alive = false;
		}
		if (!$alive) unset(self::$pids[$name]);
		return $alive;
	}

	/** @return {array} the job's state: lastAttempt, lastSuccess, lastError, failures, nextAttempt */
	static function state($stateFile)
	{
		$s = is_file($stateFile) ? json_decode((string) @file_get_contents($stateFile), true) : null;
		return is_array($s) ? $s : array();
	}

	private static function finish($stateFile, callable $work)
	{
		$lock = @fopen($stateFile . '.lock', 'c');
		if ($lock and !@flock($lock, LOCK_EX | LOCK_NB)) return; // another process has it
		$state = self::state($stateFile);
		try {
			$r = $work();
		} catch (\Throwable $e) {
			$r = array('ok' => false, 'error' => get_class($e) . ': ' . $e->getMessage());
		}
		$now = time();
		$state['running'] = false;
		$state['lastAttempt'] = $now;
		if (!empty($r['ok'])) {
			$state['lastSuccess'] = $now;
			$state['failures'] = 0;
			$state['lastError'] = null;
			$state['nextAttempt'] = null;
		} else {
			$failures = (int) ($state['failures'] ?? 0) + 1;
			$wait = min(self::BACKOFF_MAX, self::BACKOFF_FIRST * (1 << min(16, $failures - 1)));
			if (!empty($r['retryAfter'])) $wait = max($wait, (int) $r['retryAfter']);
			$state['failures'] = $failures;
			$state['lastError'] = (string) ($r['error'] ?? 'failed');
			$state['nextAttempt'] = $now + $wait + random_int(0, (int) ($wait / 10));
		}
		self::write($stateFile, $state);
		if ($lock) { @flock($lock, LOCK_UN); fclose($lock); }
	}

	private static function write($file, array $state)
	{
		$dir = dirname($file);
		if (!is_dir($dir)) @mkdir($dir, 0700, true);
		Q_WebServer_Certificate_Store::writeAtomic($file, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", 0600);
	}
}
