<?php
/**
 * @module Q
 */
/**
 * Runs one external command for the shell: an argv array handed straight to
 * proc_open(), so no shell ever re-reads it, with its output streamed into a
 * sink as it arrives.
 *
 * @class Q_WebServer_Shell_Exec
 * @static
 */
class Q_WebServer_Shell_Exec
{
	/**
	 * @method run
	 * @static
	 * @param {array} $argv the program and its arguments, as separate strings
	 * @param {string} $stdin what the command reads, then end of file
	 * @param {Q_WebServer_Shell_Sink} $sink
	 * @param {array} [$env=array()] extra environment, name => value
	 * @param {string|null} [$cwd=null]
	 * @return {integer} exit status (127 when it cannot start)
	 */
	static function run(array $argv, $stdin, Q_WebServer_Shell_Sink $sink, array $env = array(), $cwd = null)
	{
		$argv = array_values(array_map('strval', $argv));
		if (!$argv || $argv[0] === '') return 127;
		$environment = self::environment($env);
		$pipes = array();
		$proc = @proc_open($argv, self::descriptors(array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'))),
			$pipes, $cwd, $environment);
		self::closeExtra($pipes);
		if (!is_resource($proc)) {
			$sink->error('qsh: cannot run ' . $argv[0] . "\n");
			return 127;
		}
		if ((string) $stdin !== '') @fwrite($pipes[0], (string) $stdin);
		fclose($pipes[0]);
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);
		$open = array(1 => $pipes[1], 2 => $pipes[2]);
		$cut = false;
		while ($open) {
			$read = array_values($open);
			$w = $e = null;
			if (@stream_select($read, $w, $e, 1) === false) break;
			foreach ($read as $stream) {
				$chunk = fread($stream, 65536);
				$which = array_search($stream, $open, true);
				if ($chunk === '' || $chunk === false) {
					if (feof($stream)) { fclose($stream); unset($open[$which]); }
					continue;
				}
				if ($which === 2) { $sink->error($chunk); continue; }
				if (!$cut && !$sink->write($chunk)) {
					$cut = true;
					proc_terminate($proc, 15);
				}
			}
		}
		foreach ($open as $s) fclose($s);
		$status = proc_close($proc);
		return $cut ? 141 : ($status < 0 ? 1 : $status);
	}

	/**
	 * Variables a command inherits from the server; everything else in the
	 * server's environment (credentials, agent sockets, tokens of the
	 * process that started it) stays behind.
	 */
	const KEEP_ENV = '/^(PATH|LANG|LANGUAGE|LC_[A-Z_]+|TZ|TMPDIR|HOME|USER|LOGNAME|SHELL|QBIX_[A-Z0-9_]+|VC_[A-Z0-9_]+)$/';

	/**
	 * The environment a command gets: the server's locale, path and its own
	 * configuration variables (KEEP_ENV), plus the shell's exported
	 * variables, and colour on even though no terminal is attached.
	 * @method environment
	 * @static
	 * @param {array} $extra name => value
	 * @return {array}
	 */
	static function environment(array $extra)
	{
		$env = array();
		foreach ((array) getenv() as $k => $v) {
			if (preg_match(self::KEEP_ENV, (string) $k)) $env[$k] = $v;
		}
		if (!isset($env['PATH'])) $env['PATH'] = '/usr/local/bin:/usr/bin:/bin';
		$env['QBIX_FORCE_COLOR'] = '1';
		$env['TERM'] = 'xterm-256color';
		foreach ($extra as $k => $v) {
			if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $k)) $env[$k] = (string) $v;
		}
		return $env;
	}

	/**
	 * A proc_open() descriptor spec that also covers every other descriptor
	 * this process holds, replacing it in the child with the read end of an
	 * empty pipe (closeExtra() closes ours at once). Without it the child
	 * inherits the server's listening sockets and its clients' connections,
	 * and could accept or answer them as the server. (A pipe, not
	 * /dev/null: the server's own file:// wrapper would open that in user
	 * space, which proc_open() cannot hand to a child.)
	 * @method descriptors
	 * @static
	 * @param {array} $spec fd => descriptor, for the ones the child should get
	 * @return {array}
	 */
	static function descriptors(array $spec)
	{
		if (DIRECTORY_SEPARATOR === '\\') return $spec;
		$fds = array();
		$list = @scandir('/proc/self/fd') ?: @scandir('/dev/fd');
		if ($list) {
			foreach ($list as $f) if (ctype_digit($f)) $fds[] = (int) $f;
		} else {
			$fds = range(3, 255);
		}
		foreach ($fds as $fd) {
			if ($fd > 2 && !isset($spec[$fd])) $spec[$fd] = array('pipe', 'r');
		}
		return $spec;
	}

	/**
	 * Close our ends of the pipes descriptors() added (every pipe above 2).
	 * @method closeExtra
	 * @static
	 * @param {array} $pipes from proc_open()
	 */
	static function closeExtra(array &$pipes)
	{
		foreach ($pipes as $fd => $p) {
			if ($fd > 2) { if (is_resource($p)) @fclose($p); unset($pipes[$fd]); }
		}
	}
}
