<?php
/**
 * @module Q
 */
/**
 * The Q shell: a drop-down console in the server's own pages, opened with `
 * (or ~), in the manner of the Quake console, with zsh-style line editing
 * and zfs-style commands. See docs/shell.md.
 *
 * This class holds what the parts share: the settings (Q.shell.*), the data
 * directory, the providers and themes, and the two things the pages need --
 * the Shell item in their toolbar and the terminal's own files.
 *
 *   Q.shell.enabled      true      the shell at all
 *   Q.shell.allowSystem  false     raw OS commands (! cmd, sys cmd) in advanced
 *   Q.shell.tier         advanced  the highest tier a session may use
 *   Q.shell.timeout      120       seconds a foreground command may run
 *   Q.shell.toggleKey    `         the key that opens and closes it
 *   Q.shell.scriptsDir   null      executables that become commands
 *   Q.shell.maxOutput    8388608   bytes one command may print
 *   Q.shell.maxJobs      8         background jobs per session
 *   Q.shell.elevateMinutes 5       how long a password confirmation (sudo) lasts
 *   Q.shell.user         null      the unprivileged user commands run as when the server is root
 *                                  (unset: the owner of the document root, else nobody)
 *   Q.shell.allowRoot    false     let "sudo <command>" run one command as root (password first)
 *
 * @class Q_WebServer_Shell
 * @static
 */
class Q_WebServer_Shell
{
	/** @var array Q_WebServer_Shell_Provider instances */
	private static $providers = array();

	/**
	 * A setting, with its default.
	 * @method config
	 * @static
	 */
	static function config($name)
	{
		$defaults = array('enabled' => true, 'allowSystem' => false, 'tier' => 'advanced', 'timeout' => 120,
			'toggleKey' => '`', 'scriptsDir' => null, 'maxOutput' => 8388608, 'maxJobs' => 8, 'elevateMinutes' => 5, 'user' => null, 'allowRoot' => false, 'allowedOrigins' => array());
		$v = class_exists('Q_Config', false) ? Q_Config::get('Q', 'shell', $name, $defaults[$name] ?? null) : ($defaults[$name] ?? null);
		if ($name === 'tier' && !isset(Q_WebServer_Shell_Interpreter::TIERS[$v])) $v = 'basic';
		if ($name === 'toggleKey' && (!is_string($v) || $v === '' || strlen($v) > 12)) $v = '`';
		return $v;
	}

	static function enabled() { return self::config('enabled') !== false; }

	/**
	 * The shell's directory for history, aliases, autoexec and the audit log:
	 * beside the panel's password file.
	 * @method dataDir
	 * @static
	 */
	/**
	 * When the server runs as root: the first directory, from the shell's
	 * data directory up to /, that someone other than root could change --
	 * owned by another user, or writable by group or others without the
	 * sticky bit. Such a user could swap the directory for their own, with
	 * their own panel password and autoexec, and be let in. Null when the
	 * path is sound, or the server is not root.
	 * @method untrustedPath
	 * @static
	 * @param {string} [$dir] default dataDir()
	 * @return {string|null}
	 */
	static function untrustedPath($dir = null)
	{
		if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) return null;
		$dir = $dir ?? self::dataDir();
		$path = realpath(is_dir($dir) ? $dir : dirname($dir));
		while ($path !== false && $path !== '') {
			$st = @stat($path);
			if ($st && ($st['uid'] !== 0 || (($st['mode'] & 0022) && !($st['mode'] & 01000)))) return $path;
			if ($path === '/' || $path === dirname($path)) break;
			$path = dirname($path);
		}
		return null;
	}

	static function dataDir()
	{
		$panel = (class_exists('Q_WebServer_Panel') and (defined('APP_DIR') or function_exists('qbix_data_path')))
			? Q_WebServer_Panel::panelConfigPath() : null;
		$base = $panel ? dirname($panel) : sys_get_temp_dir();
		return $base . DIRECTORY_SEPARATOR . 'shell';
	}


	/**
	 * The user commands run as. By design never root: when the server runs
	 * as root, every runner switches to Q.shell.user (nobody unless set)
	 * before it runs anything, and refuses to run if it cannot. Root is only
	 * ever used through "sudo <command>", and only where Q.shell.allowRoot is
	 * on (it is off unless configured). A server that is not root runs
	 * commands as its own user.
	 * @method user
	 * @static
	 * @return {array} array('name', 'uid', 'gid', 'switch' => bool, 'error' => string|null)
	 */
	static function user()
	{
		$euid = function_exists('posix_geteuid') ? posix_geteuid() : -1;
		$self = ($euid >= 0 && function_exists('posix_getpwuid')) ? posix_getpwuid($euid) : false;
		if ($euid === 0) {
			$want = self::config('user');
			if (!is_string($want) || $want === '') {
				// The site's own user -- who owns the document root and can read
				// the site -- else nobody.
				$want = 'nobody';
				$root = (array) (class_exists('Q_Config', false) ? Q_Config::get('Q', 'webserver', 'startOptions', array()) : array());
				$owner = !empty($root['root']) ? @fileowner($root['root']) : false;
				if ($owner && $owner > 0 && function_exists('posix_getpwuid') && ($o = posix_getpwuid($owner))) $want = $o['name'];
			}
			$pw = function_exists('posix_getpwnam') ? posix_getpwnam($want) : false;
			if (!$pw || (int) $pw['uid'] === 0) {
				return array('name' => $want, 'uid' => -1, 'gid' => -1, 'switch' => true,
					'error' => 'the shell user "' . $want . '" does not exist or is root; set Q.shell.user to an unprivileged user');
			}
			return array('name' => $pw['name'], 'uid' => (int) $pw['uid'], 'gid' => (int) $pw['gid'], 'switch' => true, 'error' => null);
		}
		return array('name' => $self ? $self['name'] : (string) get_current_user(), 'uid' => $euid, 'gid' => -1, 'switch' => false, 'error' => null);
	}

	/** Whether "sudo <command>" may run a command as root here. */
	static function rootAllowed()
	{
		return self::config('allowRoot') === true && function_exists('posix_geteuid') && posix_geteuid() === 0;
	}

	/** The audit log: next to the shell directory, owned by the server. */
	static function auditFile()
	{
		return dirname(self::dataDir()) . DIRECTORY_SEPARATOR . 'shell-audit.log';
	}
	/** Register a distribution's provider. */
	static function addProvider(Q_WebServer_Shell_Provider $p)
	{
		self::$providers[get_class($p)] = $p;
	}

	/** @return {array} */
	static function providers() { return array_values(self::$providers); }

	/**
	 * Every theme: the shipped and installed theme-<name>.json design files,
	 * then the providers'. name => theme array.
	 * @method themes
	 * @static
	 */
	static function themes()
	{
		$out = array();
		$dirs = array();
		foreach (Q_WebServer_Design::roots() as $root) {
			foreach (array_unique(array(Q_WebServer_Design::active(), 'default')) as $design) {
				$dirs[] = "$root/$design/shell";
			}
		}
		foreach (array_reverse($dirs) as $dir) {
			foreach ((array) @glob($dir . '/theme-*.json') as $file) {
				if (!preg_match('/theme-([A-Za-z0-9_-]+)\.json$/', (string) $file, $m)) continue;
				$t = json_decode((string) @file_get_contents($file), true);
				if (is_array($t)) $out[$m[1]] = $t;
			}
		}
		foreach (self::$providers as $p) {
			foreach ((array) $p->themes() as $name => $t) {
				if (is_array($t) && preg_match('/^[A-Za-z0-9_-]+$/', (string) $name)) $out[$name] = $t;
			}
		}
		ksort($out);
		return $out;
	}

	/**
	 * Add the Shell item to a server page's toolbar, and the terminal's
	 * files. Pages without a toolbar (PHP info, error pages) get a small one.
	 * Only for the server's own pages, under /Q/.
	 * @method decorate
	 * @static
	 * @param {string} $html
	 * @return {string}
	 */
	static function decorate($html)
	{
		if (!self::enabled() || !is_string($html) || strpos($html, 'data-qshell-open') !== false) return $html;
		$key = htmlspecialchars((string) self::config('toggleKey'), ENT_QUOTES);
		$item = '<a href="#shell" class="qshell-item" data-qshell-open role="button" aria-haspopup="dialog" '
			. 'title="Shell (press ' . $key . ')">Shell <kbd>' . $key . '</kbd></a>';
		$count = 0;
		$html = preg_replace('/(<div class="qnav"[^>]*>.*?)(<\/div>)/s', '$1' . $item . '$2', $html, 1, $count);
		$assets = '<link rel="stylesheet" href="/Q/shell/shell.css">'
			. '<script src="/Q/shell/shell.js" defer data-toggle-key="' . $key . '"></script>';
		if (!$count) {
			$assets .= '<div class="qnav qshell-float" role="navigation" aria-label="Server views">' . $item . '</div>';
		}
		$pos = stripos($html, '</body>');
		return $pos === false ? $html . $assets : substr($html, 0, $pos) . $assets . substr($html, $pos);
	}

	/**
	 * The terminal's files: /Q/shell/shell.js, shell.css and
	 * /Q/shell/theme/<name>.json. Public (the page itself decides what it
	 * shows); nothing here runs anything.
	 * @method asset
	 * @static
	 * @param {string} $path
	 * @return {array|null} a response, or null when not an asset path
	 */
	static function asset($path)
	{
		if (strpos($path, '/Q/shell/') !== 0) return null;
		$name = substr($path, 9);
		if ($name === 'shell.js' || $name === 'shell.css') {
			$body = Q_WebServer_Design::read('shell', $name);
			if ($body === null) return array('status' => 404, 'body' => 'Not found', 'headers' => array('Content-Type' => 'text/plain'));
			return array('status' => 200, 'body' => $body, 'headers' => array(
				'Content-Type' => ($name === 'shell.js' ? 'application/javascript' : 'text/css') . '; charset=utf-8',
				'Cache-Control' => 'no-cache'));
		}
		if ($name === 'themes') {
			$list = array();
			foreach (self::themes() as $n => $t) $list[$n] = (string) ($t['label'] ?? $n);
			return array('status' => 200, 'body' => json_encode($list),
				'headers' => array('Content-Type' => 'application/json', 'Cache-Control' => 'no-cache'));
		}
		if (preg_match('/^theme\/([A-Za-z0-9_-]+)\.json$/', $name, $m)) {
			$themes = self::themes();
			if (!isset($themes[$m[1]])) return array('status' => 404, 'body' => '{}', 'headers' => array('Content-Type' => 'application/json'));
			return array('status' => 200, 'body' => json_encode($themes[$m[1]]),
				'headers' => array('Content-Type' => 'application/json', 'Cache-Control' => 'no-cache'));
		}
		return array('status' => 404, 'body' => 'Not found', 'headers' => array('Content-Type' => 'text/plain'));
	}
}
