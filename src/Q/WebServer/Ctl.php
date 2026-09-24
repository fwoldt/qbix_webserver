<?php
/**
 * @module Q
 */
/**
 * The server's control commands, for qbixconsole and qbixctl.
 *
 * What apache2ctl and the a2ensite family do for Apache, against the same
 * kind of tree (see Q_WebServer_Layout): start, stop, graceful reload,
 * restart, status and a configuration test; enabling and disabling sites,
 * conf snippets and modules; clearing the response cache. Every command sees
 * the configuration the server would: the distribution, the tree stack and
 * the --config file are loaded the way qbixserver.php loads them.
 *
 * The operations are plain static methods as well, so a program driving the
 * server (a CMS's own control script, say) can call them instead of
 * re-implementing them.
 *
 * @class Q_WebServer_Ctl
 * @static
 */
class Q_WebServer_Ctl
{
	/** @var string the engine's source tree (where qbixserver.php lives) */
	public static $sourceDir = '';

	/** Options every command that reads the configuration accepts. */
	private static $contextOptions = array(
		'conf-dir' => 'Configuration directory (default: from --config, QBIX_CONF_DIR, or none)',
		'config' => 'The site file, normally <conf dir>/sites-enabled/<site>.conf',
		'distribution' => 'Distribution to load (default: QBIX_DISTRIBUTION, then the DISTRIBUTION file)',
	);

	// ── Context ──────────────────────────────────────────────────────────

	/**
	 * Load the configuration the server would see for these options.
	 * @method context
	 * @static
	 * @param {array} $opts
	 * @return {array} confDir, stack, config, files
	 */
	static function context(array $opts)
	{
		Q_WebServer_Layout::loadDistribution(isset($opts['distribution']) ? (string) $opts['distribution'] : null, self::$sourceDir);
		$config = isset($opts['config']) && is_string($opts['config']) ? $opts['config'] : null;
		$confDir = Q_WebServer_Layout::resolve(isset($opts['conf-dir']) ? (string) $opts['conf-dir'] : null, $config);
		$stack = Q_WebServer_Layout::stack($confDir);
		$files = array();
		// Every file of each tree, loaded or not: one that does not parse is
		// skipped by load(), and configtest has to see it to report it.
		foreach ($stack as $d) { Q_WebServer_Layout::load($d); $files = array_merge($files, Q_WebServer_Layout::files($d)); }
		if ($config !== null and is_file($config)) { Q_Config::load($config); $files[] = $config; }
		return array('confDir' => $confDir, 'stack' => $stack, 'config' => $config, 'files' => $files);
	}

	/** The directory whose pairs a/dis-en* commands change: the top of the stack. */
	static function targetDir(array $ctx)
	{
		return $ctx['stack'] ? end($ctx['stack']) : null;
	}

	// ── Processes and ports, without ps or ss ────────────────────────────

	/**
	 * The pid file: --pid, else Q.webserver.pidFile, else /run/qbix, else the
	 * temporary directory.
	 */
	static function pidFile(array $opts)
	{
		if (!empty($opts['pid']) and is_string($opts['pid'])) return $opts['pid'];
		$conf = Q_Config::get('Q', 'webserver', 'pidFile', null);
		if (is_string($conf) and $conf !== '') return $conf;
		return (is_dir('/run/qbix') and is_writable('/run/qbix')) ? '/run/qbix/qbixserver.pid'
			: sys_get_temp_dir() . '/qbixserver.pid';
	}

	/** The pid in a pid file, or 0. */
	static function readPid($file)
	{
		$pid = is_file($file) ? (int) trim((string) @file_get_contents($file)) : 0;
		return $pid > 0 ? $pid : 0;
	}

	/** Whether a process is running (not a zombie). */
	static function isAlive($pid)
	{
		$pid = (int) $pid;
		if ($pid <= 0) return false;
		$stat = @file_get_contents("/proc/$pid/stat");
		if ($stat !== false) {
			$state = substr($stat, strrpos($stat, ')') + 2, 1);
			return $state !== 'Z' and $state !== 'X';
		}
		return function_exists('posix_kill') ? @posix_kill($pid, 0) : false;
	}

	/**
	 * Which of these TCP ports something is listening on: from /proc/net/tcp
	 * where there is one, else by connecting to it.
	 * @param {array} $ports
	 * @return {array}
	 */
	static function listening(array $ports)
	{
		$open = array();
		$files = array_filter(array('/proc/net/tcp', '/proc/net/tcp6'), 'is_readable');
		foreach ($files as $f) {
			foreach (array_slice(file($f, FILE_IGNORE_NEW_LINES) ?: array(), 1) as $row) {
				$c = preg_split('/\s+/', trim($row));
				if (isset($c[3]) and $c[3] === '0A' and strpos($c[1], ':') !== false) {
					$open[hexdec(substr($c[1], strrpos($c[1], ':') + 1))] = true;
				}
			}
		}
		$out = array();
		foreach (array_unique(array_filter(array_map('intval', $ports))) as $p) {
			if ($files ? isset($open[$p]) : (bool) @fsockopen('127.0.0.1', $p, $en, $es, 0.5)) $out[] = $p;
		}
		return $out;
	}

	/** The ports the configuration names (HTTP, HTTPS). */
	static function configuredPorts(array $opts = array())
	{
		return array_values(array_filter(array(
			(int) ($opts['port'] ?? Q_Config::get('Q', 'webserver', 'port', 0)),
			(int) ($opts['https-port'] ?? Q_Config::get('Q', 'web', 'https', 'port', 0)),
		)));
	}

	// ── Built-in operations ──────────────────────────────────────────────

	/**
	 * Enable or disable a site, conf snippet or module, as a2ensite does:
	 * a relative symlink in <pair>-enabled to <pair>-available/<name>.conf.
	 * @return {array} array(ok, message)
	 */
	static function toggle($dir, $pair, $name, $enable)
	{
		$map = array('site' => 'sites', 'conf' => 'conf', 'mod' => 'mods');
		if ($dir === null) return array(false, 'no configuration directory (--conf-dir)');
		if (!isset($map[$pair])) return array(false, "unknown kind $pair");
		if (!preg_match('/^[A-Za-z0-9._-]+$/', $name) or $name[0] === '.') return array(false, "invalid name $name");
		$p = $map[$pair];
		$available = "$dir/$p-available/$name.conf";
		$link = "$dir/$p-enabled/$name.conf";
		if ($enable) {
			if (!is_file($available)) return array(false, "no $p-available/$name.conf");
			if (is_link($link) or file_exists($link)) return array(true, "$pair $name already enabled");
			if (!is_dir(dirname($link))) @mkdir(dirname($link), 0755, true);
			if (!@symlink("../$p-available/$name.conf", $link)) return array(false, "could not create $link");
			return array(true, "enabled $pair $name; reload to apply");
		}
		if (!is_link($link)) {
			return array(true, is_file($link) ? "$link is a file, not a link; left alone" : "$pair $name already disabled");
		}
		@unlink($link);
		return array(true, "disabled $pair $name; reload to apply");
	}

	/**
	 * Invalidate every cached page: touch the response cache's generation
	 * marker (see Q_WebServer_Cache::$generationFile).
	 * @return {array} array(ok, message)
	 */
	static function clearCache($cacheDir, $marker = null)
	{
		if ($marker === null) {
			if (!is_string($cacheDir) or $cacheDir === '') return array(false, 'no cache directory (--cache-dir, or Q.web.cache.dir)');
			if (!is_dir($cacheDir) and !@mkdir($cacheDir, 0750, true)) return array(false, "cannot create $cacheDir");
			$marker = rtrim($cacheDir, '/') . '/.generation';
		}
		if (!@touch($marker)) return array(false, "could not touch $marker");
		return array(true, 'response cache cleared: every page stored before now is rendered again on its next request');
	}

	/**
	 * Check every configuration file parses.
	 * @return {array} array(ok, list of "OK file" / "BAD file" lines)
	 */
	static function configTest(array $files)
	{
		$ok = true; $lines = array();
		foreach ($files as $f) {
			$good = is_array(json_decode((string) @file_get_contents($f), true));
			$ok = $ok && $good;
			$lines[] = ($good ? 'OK   ' : 'BAD  ') . $f;
		}
		return array($ok, $lines);
	}

	// ── Server processes ─────────────────────────────────────────────────

	/** The server script: qbixserver.php in the source tree. */
	static function serverScript()
	{
		return self::$sourceDir . '/qbixserver.php';
	}

	/**
	 * Start the server detached. Options it understands are passed on;
	 * anything after `--` goes to qbixserver.php verbatim.
	 * @return {array} array(ok, message)
	 */
	static function start(array $opts, array $extra = array())
	{
		$pidFile = self::pidFile($opts);
		if (self::isAlive(self::readPid($pidFile))) return array(false, 'already running (pid ' . self::readPid($pidFile) . ')');
		$args = array(PHP_BINARY, self::serverScript(), '--pid=' . $pidFile);
		foreach (array('conf-dir', 'config', 'root', 'host', 'port', 'https-port', 'workers', 'distribution') as $o) {
			if (isset($opts[$o]) and is_string($opts[$o]) and $opts[$o] !== '') $args[] = "--$o=" . $opts[$o];
		}
		$args = array_merge($args, $extra);
		$log = (isset($opts['log']) and is_string($opts['log'])) ? $opts['log'] : sys_get_temp_dir() . '/qbixserver.log';
		$setsid = null;
		foreach (array_merge(explode(PATH_SEPARATOR, (string) getenv('PATH')), array('/usr/bin', '/bin', '/usr/sbin', '/sbin')) as $d) {
			if ($d !== '' and is_executable("$d/setsid")) { $setsid = "$d/setsid"; break; }
		}
		$cmd = ($setsid ? escapeshellarg($setsid) . ' ' : '') . implode(' ', array_map('escapeshellarg', $args))
			. ' > ' . escapeshellarg($log) . ' 2>&1 < /dev/null &';
		@exec($cmd);
		$ports = self::configuredPorts($opts);
		$deadline = microtime(true) + (float) ($opts['wait'] ?? 20);
		while (microtime(true) < $deadline) {
			$pid = self::readPid($pidFile);
			if ($pid and self::isAlive($pid) and (!$ports or self::listening($ports))) {
				return array(true, "started (pid $pid)");
			}
			usleep(250000);
		}
		return array(false, "did not start within the time allowed; see $log");
	}

	/** Ask the server to stop (qbixserver.php --stop) and wait. */
	static function stop(array $opts)
	{
		$pidFile = self::pidFile($opts);
		$pid = self::readPid($pidFile);
		if (!self::isAlive($pid)) return array(true, 'not running');
		@exec(implode(' ', array_map('escapeshellarg', array(PHP_BINARY, self::serverScript(), '--stop', '--pid=' . $pidFile))) . ' > /dev/null 2>&1');
		$deadline = microtime(true) + (float) ($opts['wait'] ?? 15);
		while (microtime(true) < $deadline) {
			if (!self::isAlive($pid)) return array(true, "stopped (pid $pid)");
			usleep(250000);
		}
		return array(false, "pid $pid is still running");
	}

	/** Graceful reload: re-exec without dropping the listening sockets. */
	static function reload(array $opts)
	{
		$pidFile = self::pidFile($opts);
		$pid = self::readPid($pidFile);
		if (!self::isAlive($pid)) return array(false, 'not running');
		@exec(implode(' ', array_map('escapeshellarg', array(PHP_BINARY, self::serverScript(), '--reload', '--pid=' . $pidFile))) . ' > /dev/null 2>&1', $o, $code);
		return $code === 0 ? array(true, "reload requested (pid $pid)") : array(false, 'reload failed');
	}

	/** Status: the pid, whether it runs, and which configured ports listen. */
	static function status(array $opts)
	{
		$pidFile = self::pidFile($opts);
		$pid = self::readPid($pidFile);
		$alive = self::isAlive($pid);
		$ports = self::configuredPorts($opts);
		return array('running' => $alive, 'pid' => $alive ? $pid : null, 'pidFile' => $pidFile,
			'ports' => $ports, 'listening' => $alive ? self::listening($ports) : array());
	}

	// ── Commands ─────────────────────────────────────────────────────────

	/**
	 * Register the control commands with Q_Console.
	 * @method register
	 * @static
	 * @param {string} $sourceDir the engine's source tree
	 */
	static function register($sourceDir)
	{
		self::$sourceDir = rtrim($sourceDir, '/');
		$C = 'Q_Console';
		$ctxOpts = self::$contextOptions;
		$srvOpts = $ctxOpts + array('pid' => 'Pid file (default: Q.webserver.pidFile, /run/qbix, or the temp dir)');
		$say = function ($r) { $r[0] ? Q_Console::out($r[1]) : Q_Console::err($r[1]); return $r[0] ? 0 : 1; };

		$C::add('server:start', 'Start the server, detached', function ($a, $o) use ($say) {
			self::context($o);
			return $say(self::start($o, $a));
		}, $srvOpts + array('root' => 'Document root', 'port' => 'HTTP port', 'https-port' => 'HTTPS port',
			'workers' => 'Workers', 'host' => 'Bind address', 'log' => 'Where the server writes its output',
			'wait' => 'Seconds to wait for it to listen (20)'), array('start'), '[-- extra qbixserver.php options]');
		$C::add('server:stop', 'Stop the server and wait for it', function ($a, $o) use ($say) {
			self::context($o);
			return $say(self::stop($o));
		}, $srvOpts + array('wait' => 'Seconds to wait (15)'), array('stop'));
		$C::add('server:reload', 'Reload without dropping connections (graceful)', function ($a, $o) use ($say) {
			self::context($o);
			return $say(self::reload($o));
		}, $srvOpts, array('graceful', 'reload'));
		$C::add('server:restart', 'Stop, then start', function ($a, $o) use ($say) {
			self::context($o);
			$s = self::stop($o);
			if (!$s[0]) return $say($s);
			return $say(self::start($o, $a));
		}, $srvOpts, array('restart'), '[-- extra qbixserver.php options]');
		$C::add('server:status', 'Report whether the server runs, and what listens', function ($a, $o) {
			self::context($o);
			$s = self::status($o);
			if (!empty($o['json'])) { Q_Console::out(json_encode($s)); return $s['running'] ? 0 : 3; }
			Q_Console::out('  running    : ' . ($s['running'] ? 'yes (pid ' . $s['pid'] . ')' : 'no'));
			Q_Console::out('  pid file   : ' . $s['pidFile']);
			Q_Console::out('  listening  : ' . ($s['listening'] ? implode(', ', $s['listening']) : 'nothing'));
			return $s['running'] ? 0 : 3; // as LSB init scripts report "not running"
		}, $srvOpts + array('json' => 'Report as JSON'), array('status'));
		$C::add('server:configtest', 'Check that every configuration file parses', function ($a, $o) {
			$ctx = self::context($o);
			list($ok, $lines) = self::configTest($ctx['files']);
			foreach ($lines as $l) Q_Console::out('  ' . $l);
			Q_Console::out($ok ? 'Syntax OK' : 'Syntax error');
			return $ok ? 0 : 1;
		}, $ctxOpts, array('configtest'));
		$C::add('layout:show', 'Show the configuration trees, their files and what is enabled', function ($a, $o) {
			$ctx = self::context($o);
			if (!$ctx['stack']) { Q_Console::out('  no configuration directory in use'); return 0; }
			Q_Console::out('  stack      : ' . implode(' < ', $ctx['stack']) . '   (later wins)');
			foreach ($ctx['stack'] as $d) {
				$info = Q_WebServer_Layout::describe($d, $ctx['config']);
				Q_Console::out('  ' . $d);
				foreach ($info['pairs'] as $p => $l) {
					Q_Console::out(sprintf('    %-6s %s', $p, $l['available'] ? implode(', ', array_map(function ($f) use ($l) {
						return in_array($f, $l['enabled'], true) ? "$f*" : $f; }, $l['available'])) : '-'));
				}
			}
			Q_Console::out('  site file  : ' . ($ctx['config'] ?? '-'));
			Q_Console::out('  (* enabled)');
			return 0;
		}, $ctxOpts, array('layout'));
		foreach (array('site' => 'site', 'conf' => 'conf snippet', 'mod' => 'module') as $pair => $noun) {
			foreach (array('enable' => true, 'disable' => false) as $verb => $on) {
				$C::add("$pair:$verb", ucfirst($verb) . " a $noun (a2" . ($on ? 'en' : 'dis') . "$pair)", function ($a, $o) use ($pair, $on, $say) {
					if (!$a) { Q_Console::err('name required'); return 1; }
					$ctx = self::context($o);
					$code = 0;
					foreach ($a as $name) $code |= $say(self::toggle(self::targetDir($ctx), $pair, $name, $on));
					return $code;
				}, $ctxOpts, array(($on ? 'en' : 'dis') . $pair), '<name>...');
			}
		}
		$C::add('cache:clear', 'Invalidate every page in the response cache', function ($a, $o) use ($say) {
			self::context($o);
			$dir = isset($o['cache-dir']) ? (string) $o['cache-dir'] : Q_Config::get('Q', 'web', 'cache', 'dir', null);
			return $say(self::clearCache($dir, Q_Config::get('Q', 'web', 'cache', 'generationFile', null)));
		}, $ctxOpts + array('cache-dir' => 'The cache directory (default: Q.web.cache.dir)'));
	}
}
