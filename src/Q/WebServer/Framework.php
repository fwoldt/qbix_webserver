<?php
/**
 * @module Q
 */
/**
 * One registry of the PHP applications this server can recognise.
 *
 * It used to be three: the panel's Apps tab knew only Qbix apps, its
 * Frameworks tab knew five frameworks and looked only above the document root,
 * and the autohost had its own list of nineteen. An installation whose project
 * directory is the document root was invisible to all of them.
 *
 * Detectors (Q_WebServer_Framework_Detector) are tried most specific first.
 * The built-in set covers the common frameworks and CMSs; a distribution adds
 * its own with register().
 *
 *   Q_WebServer_Framework::detect('/srv/site');        // one directory
 *   Q_WebServer_Framework::scan();                     // everything known
 *   Q_WebServer_Framework::run('laravel', 'cache:clear', $dir);
 *
 * Scanning is bounded: the document root, its parent, the panel's apps
 * directory and Q.panel.appRoots, each with its immediate subdirectories, and
 * never more than MAX_DIRS directories in one scan.
 *
 * @class Q_WebServer_Framework
 * @static
 */
class Q_WebServer_Framework
{
	/** Directories looked at in one scan, at most. */
	const MAX_DIRS = 400;

	/** Seconds a command may run before it is stopped. */
	const TIMEOUT = 120;

	/** Bytes of command output kept. */
	const MAX_OUTPUT = 1048576;

	/** @var array priority => list of detectors */
	private static $detectors = array();

	/** @var bool */
	private static $builtins = false;

	/**
	 * Add a detector. Higher priority is tried first; the built-ins sit at
	 * 0 (frameworks), -50 (generic Composer apps) and -100 (plain PHP), so a
	 * distribution's specific detector goes at a positive priority.
	 * @method register
	 * @static
	 * @param {Q_WebServer_Framework_Detector} $detector
	 * @param {integer} [$priority=10]
	 */
	static function register(Q_WebServer_Framework_Detector $detector, $priority = 10)
	{
		self::$detectors[(int) $priority][] = $detector;
		krsort(self::$detectors);
	}

	/** Forget every registered detector (tests). */
	static function reset()
	{
		self::$detectors = array();
		self::$builtins = false;
	}

	/** The detectors, most specific first. */
	static function detectors()
	{
		if (!self::$builtins) {
			self::$builtins = true;
			foreach (Q_WebServer_Framework_Builtin::detectors() as $priority => $list) {
				foreach ($list as $d) self::register($d, $priority);
			}
		}
		$all = array();
		foreach (self::$detectors as $list) {
			foreach ($list as $d) $all[] = $d;
		}
		return $all;
	}

	/**
	 * What one directory holds, or null.
	 * @method detect
	 * @static
	 * @param {string} $dir
	 * @param {array} [$context] serving => bool
	 * @return {array|null}
	 */
	static function detect($dir, array $context = array())
	{
		$dir = rtrim((string) $dir, '/\\');
		if ($dir === '' or !is_dir($dir)) return null;
		foreach (self::detectors() as $detector) {
			$found = $detector->detect($dir);
			if (is_array($found) and !empty($found['kind'])) {
				return self::complete($found, $dir, $context);
			}
		}
		return null;
	}

	/** The detector's array, with the keys every consumer relies on present. */
	private static function complete(array $d, $dir, array $context)
	{
		$d += array(
			'name' => ucfirst($d['kind']),
			'version' => '',
			'state' => '',
			'edition' => '',
			'webRoot' => $dir,
			'details' => array(),
			'links' => array(),
			'cli' => array(),
			'commands' => array(),
			'composerWrite' => true,
		);
		$d['dir'] = $dir;
		$d['serving'] = !empty($context['serving']);
		$d['framework'] = $d['kind'];   // the key the panel's scripts already use
		$d['title'] = trim($d['name'] . ' ' . $d['version']
			. ($d['state'] !== '' ? ' (' . $d['state'] . ')' : ''));
		foreach ($d['commands'] as $i => $c) {
			$d['commands'][$i] += array('disruptive' => false);
		}
		return $d;
	}

	/**
	 * Every application the server can see, the served one first.
	 * @method scan
	 * @static
	 * @param {array} [$roots] extra directories to look in (and one level below)
	 * @return {array} list of detections
	 */
	static function scan(array $roots = array())
	{
		$served = class_exists('Q_WebServer', false) ? rtrim((string) Q_WebServer::$rootDir, '/\\') : '';
		$candidates = array();
		if ($served !== '') {
			$candidates[] = array($served, true);
			$candidates[] = array(dirname($served), false);
		}
		$lookIn = $roots;
		if (class_exists('Q_WebServer_Panel', false)) {
			$apps = Q_WebServer_Panel::appsDir();
			if ($apps) $lookIn[] = $apps;
		}
		if (class_exists('Q_Config', false)) {
			foreach ((array) Q_Config::get('Q', 'panel', 'appRoots', array()) as $r) {
				if (is_string($r) and $r !== '') $lookIn[] = $r;
			}
		}
		foreach ($lookIn as $root) {
			$root = rtrim((string) $root, '/\\');
			if ($root === '' or !is_dir($root)) continue;
			$candidates[] = array($root, false);
			$entries = @scandir($root);
			if (!$entries) continue;
			foreach ($entries as $e) {
				if ($e === '' or $e[0] === '.') continue;
				$sub = $root . DIRECTORY_SEPARATOR . $e;
				if (is_dir($sub) and !is_link($sub)) $candidates[] = array($sub, false);
				if (count($candidates) >= self::MAX_DIRS) break 2;
			}
		}
		$seen = array();
		$found = array();
		foreach ($candidates as $c) {
			list($dir, $serving) = $c;
			$real = realpath($dir);
			if ($real === false) continue;
			if (isset($seen[$real])) {
				if ($serving and isset($found[$seen[$real]])) $found[$seen[$real]]['serving'] = true;
				continue;
			}
			$d = self::detect($real, array('serving' => $serving));
			// A web root inside a detected project (public/, web/) is that project.
			if (!$d and $serving) {
				$parent = self::detect(dirname($real));
				if ($parent and rtrim($parent['webRoot'], '/\\') === $real) {
					$d = $parent;
					$d['serving'] = true;
					$real = $parent['dir'];
					if (isset($seen[$real])) {
						$found[$seen[$real]]['serving'] = true;
						continue;
					}
				}
			}
			$seen[$real] = count($found);
			if ($d) $found[] = $d;
			else $seen[$real] = -1;
		}
		usort($found, function ($a, $b) {
			if ($a['serving'] !== $b['serving']) return $a['serving'] ? -1 : 1;
			return strcmp($a['dir'], $b['dir']);
		});
		return $found;
	}

	/**
	 * The detection of the given kind: in $dir when given, else the first
	 * found by scan().
	 * @method find
	 * @static
	 * @param {string} $kind
	 * @param {string|null} [$dir]
	 * @return {array|null}
	 */
	static function find($kind, $dir = null)
	{
		if (is_string($dir) and $dir !== '') {
			$d = self::detect($dir);
			return ($d and $d['kind'] === $kind) ? $d : null;
		}
		foreach (self::scan() as $d) {
			if ($d['kind'] === $kind) return $d;
		}
		return null;
	}

	/**
	 * Run one of a detection's own commands, by its id, in its directory.
	 *
	 * Only a command the detector listed can run, and it runs as an argv
	 * array (no shell), with a timeout and an output cap. A command marked
	 * disruptive needs $confirmed.
	 *
	 * @method run
	 * @static
	 * @param {string} $kind
	 * @param {string} $cmd the command's id ('cmd' in the list)
	 * @param {string|null} [$dir]
	 * @param {boolean} [$confirmed=false]
	 * @return {array} output, cmd, exit | status, error
	 */
	static function run($kind, $cmd, $dir = null, $confirmed = false)
	{
		$d = self::find($kind, $dir);
		if (!$d) return array('status' => 404, 'error' => 'No such application here');
		$command = null;
		foreach ($d['commands'] as $c) {
			if (isset($c['cmd']) and $c['cmd'] === $cmd) { $command = $c; break; }
		}
		if (!$command) return array('status' => 403, 'error' => 'Command not in allowed list');
		if (!empty($command['disruptive']) and !$confirmed) {
			return array('status' => 409, 'error' => 'Confirm this command first', 'confirm' => true,
				'name' => $command['name']);
		}
		if (isset($command['output'])) {
			return array('output' => (string) $command['output'], 'cmd' => $command['name'], 'exit' => 0);
		}
		if (isset($command['argv']) and is_array($command['argv'])) {
			$argv = $command['argv'];
		} elseif ($d['cli']) {
			$argv = array_merge($d['cli'], preg_split('/\s+/', trim($cmd)));
		} else {
			return array('status' => 400, 'error' => 'This application has no command-line tool');
		}
		return self::exec($argv, $d['dir']);
	}

	/**
	 * Run an argv array in a directory: no shell, bounded time and output.
	 * @method exec
	 * @static
	 * @param {array} $argv
	 * @param {string} $cwd
	 * @return {array} output, cmd, exit
	 */
	static function exec(array $argv, $cwd)
	{
		$shown = implode(' ', array_map(function ($a) {
			return preg_match('/^[\w.\/:=@%+-]+$/', $a) ? $a : escapeshellarg($a);
		}, $argv));
		if (!function_exists('proc_open')) {
			return array('status' => 501, 'error' => 'proc_open is not available', 'cmd' => $shown);
		}
		// Pipes only: inside the server the file:// wrapper is a user-space
		// wrapper, which cannot give proc_open a descriptor, so a ('file',
		// '/dev/null') stdin made every command fail to start.
		$proc = @proc_open($argv, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'),
			2 => array('redirect', 1)), $pipes, $cwd);
		if (!is_resource($proc)) {
			$why = error_get_last();
			return array('status' => 500, 'error' => 'Could not start the command'
				. ($why ? ': ' . $why['message'] : ''), 'cmd' => $shown);
		}
		fclose($pipes[0]);
		stream_set_blocking($pipes[1], false);
		$out = '';
		$deadline = microtime(true) + self::TIMEOUT;
		$timedOut = false;
		while (true) {
			$r = array($pipes[1]); $w = null; $e = null;
			if (@stream_select($r, $w, $e, 0, 200000) > 0) {
				$chunk = fread($pipes[1], 65536);
				if ($chunk !== false and $chunk !== '' and strlen($out) < self::MAX_OUTPUT) $out .= $chunk;
			}
			$st = proc_get_status($proc);
			if (!$st['running']) {
				while (($chunk = fread($pipes[1], 65536)) !== false and $chunk !== '') {
					if (strlen($out) < self::MAX_OUTPUT) $out .= $chunk;
				}
				break;
			}
			if (microtime(true) > $deadline) {
				$timedOut = true;
				proc_terminate($proc, 15);
				usleep(300000);
				proc_terminate($proc, 9);
				break;
			}
		}
		fclose($pipes[1]);
		$code = proc_close($proc);
		if (isset($st) and !$st['running'] and $st['exitcode'] !== -1) $code = $st['exitcode'];
		if (strlen($out) >= self::MAX_OUTPUT) $out = substr($out, 0, self::MAX_OUTPUT) . "\n[output cut at 1 MB]";
		if ($timedOut) $out .= "\n[stopped after " . self::TIMEOUT . " s]";
		return array('output' => $out, 'cmd' => $shown, 'exit' => (int) $code);
	}

	/**
	 * The PHP binary to run an application's own scripts with: QBIX_PHP when
	 * set, else this PHP when it is a CLI binary, else `php` from the PATH.
	 * @method php
	 * @static
	 * @return {string}
	 */
	static function php()
	{
		$env = getenv('QBIX_PHP');
		if (is_string($env) and $env !== '') return $env;
		$b = PHP_BINARY;
		if ($b !== '' and preg_match('/php[0-9.]*(-cli)?(\.exe)?$/i', basename($b))) return $b;
		return 'php';
	}
}
