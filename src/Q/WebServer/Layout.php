<?php
/**
 * @module Q
 */
/**
 * Where the server's configuration lives on disk: the Debian Apache layout.
 *
 * A configuration directory -- /etc/vc for Velocity, /etc/qbix upstream,
 * each accepted wherever the other is -- is organised exactly like
 * /etc/apache2, because that is the structure organisations already run:
 *
 *   /etc/vc/vc.conf                base settings        (apache2.conf)
 *   /etc/vc/ports.conf             listen ports         (ports.conf)
 *   /etc/vc/envvars                process environment  (envvars)
 *   /etc/vc/conf-available/*.conf  shared snippets, enabled by a symlink
 *   /etc/vc/conf-enabled/          in conf-enabled
 *   /etc/vc/mods-available/*.conf  engine modules (http2, cache, compat...),
 *   /etc/vc/mods-enabled/          enabled by a symlink in mods-enabled
 *   /etc/vc/sites-available/*.conf one file per installation, enabled by
 *   /etc/vc/sites-enabled/         a symlink in sites-enabled
 *   /etc/vc/designs/               the server's own page designs
 *
 * Files hold JSON objects -- the engine's configuration format -- under
 * Apache's names; envvars holds "export NAME=value" lines, as Apache's does.
 * They are merged in the order apache2.conf includes them, later winning:
 * the base file, ports.conf, mods-enabled, conf-enabled, and then the
 * --config file, which is normally a sites-enabled file.
 *
 * The directory is used only when asked for: --conf-dir, QBIX_CONF_DIR or
 * VC_CONF_DIR, or a --config file inside a sites-* directory of one. It is
 * never picked up just because it exists -- a machine's /etc/vc must not
 * change how an unrelated server, or a test suite, behaves. --conf-dir=auto
 * searches the standard places.
 *
 * @class Q_WebServer_Layout
 * @static
 */
class Q_WebServer_Layout
{
	/**
	 * Standard places, in search order. Velocity's own first: this fork
	 * installs to /etc/vc, and still reads a tree left at /etc/qbix.
	 * @property $standardDirs
	 */
	static $standardDirs = array('/etc/vc', '/etc/qbix');

	/** The available/enabled pairs, in load order (sites are loaded as --config). */
	static $pairs = array('mods', 'conf', 'sites');

	/**
	 * The configuration directory to use, or null for none.
	 *
	 * @method resolve
	 * @static
	 * @param {string|null} $explicit --conf-dir: a path, 'auto', 'none', or null
	 * @param {string|null} $configFile the --config file
	 * @return {string|null}
	 */
	static function resolve($explicit = null, $configFile = null)
	{
		if ($explicit === 'none' or $explicit === 'disabled') return null;
		if ($explicit === 'auto') return self::search();
		if ($explicit !== null and $explicit !== '') {
			return is_dir($explicit) ? rtrim($explicit, '/') : null;
		}
		foreach (array('QBIX_CONF_DIR', 'VC_CONF_DIR') as $env) {
			$dir = getenv($env);
			if (is_string($dir) and $dir !== '') {
				return $dir === 'auto' ? self::search() : (is_dir($dir) ? rtrim($dir, '/') : null);
			}
		}
		if ($configFile) return self::fromSiteFile($configFile);
		return null;
	}

	/**
	 * The first standard directory that holds a configuration.
	 * @method search
	 * @static
	 * @return {string|null}
	 */
	static function search()
	{
		foreach (self::$standardDirs as $dir) {
			if (self::isConfDir($dir)) return $dir;
		}
		return null;
	}

	/**
	 * The directory a site file belongs to: X for X/sites-enabled/a.conf.
	 * @method fromSiteFile
	 * @static
	 * @param {string} $configFile
	 * @return {string|null}
	 */
	static function fromSiteFile($configFile)
	{
		// The path as given, not realpath(): sites-enabled holds symlinks into
		// sites-available, and both belong to the same directory.
		$parent = dirname($configFile);
		if (!in_array(basename($parent), array('sites-enabled', 'sites-available'), true)) return null;
		$dir = dirname($parent);
		return self::isConfDir($dir) ? $dir : null;
	}

	/**
	 * Whether a directory is laid out as a configuration directory.
	 * @method isConfDir
	 * @static
	 * @param {string} $dir
	 * @return {boolean}
	 */
	static function isConfDir($dir)
	{
		if (!is_dir($dir)) return false;
		if (self::mainFile($dir) !== null) return true;
		foreach (self::$pairs as $p) {
			if (is_dir("$dir/$p-available") or is_dir("$dir/$p-enabled")) return true;
		}
		return false;
	}

	/**
	 * The base file: vc.conf in /etc/vc, qbix.conf in /etc/qbix, and either
	 * name in either, so a tree moved from one to the other keeps working.
	 * @method mainFile
	 * @static
	 * @param {string} $dir
	 * @return {string|null}
	 */
	static function mainFile($dir)
	{
		foreach (array_unique(array(basename($dir) . '.conf', 'vc.conf', 'qbix.conf')) as $name) {
			if (is_file("$dir/$name")) return "$dir/$name";
		}
		return null;
	}

	/**
	 * The enabled files of one pair, in name order. Only *.conf and *.json,
	 * as Apache includes only *.conf -- an editor's backup is not a setting.
	 * @method enabled
	 * @static
	 * @param {string} $dir
	 * @param {string} $pair mods, conf or sites
	 * @return {array}
	 */
	static function enabled($dir, $pair)
	{
		$files = array_merge(
			glob("$dir/$pair-enabled/*.conf") ?: array(),
			glob("$dir/$pair-enabled/*.json") ?: array()
		);
		sort($files, SORT_STRING);
		// A dangling symlink is a disabled target, not an error.
		return array_values(array_filter($files, 'is_file'));
	}

	/**
	 * Every file to load from a configuration directory, in apache2.conf
	 * order: base, ports.conf, mods-enabled, conf-enabled. The site comes
	 * last, as --config.
	 * @method files
	 * @static
	 * @param {string} $dir
	 * @return {array}
	 */
	static function files($dir)
	{
		$files = array();
		$main = self::mainFile($dir);
		if ($main !== null) $files[] = $main;
		if (is_file("$dir/ports.conf")) $files[] = "$dir/ports.conf";
		foreach (array('mods', 'conf') as $pair) {
			$files = array_merge($files, self::enabled($dir, $pair));
		}
		return $files;
	}

	/**
	 * Load a configuration directory into Q_Config, below whatever the
	 * caller loads next. Returns the files loaded.
	 * @method load
	 * @static
	 * @param {string|null} $dir
	 * @return {array}
	 */
	static function load($dir)
	{
		if ($dir === null) return array();
		$loaded = array();
		foreach (self::files($dir) as $file) {
			$json = json_decode((string) @file_get_contents($file), true);
			if (!is_array($json)) {
				fwrite(STDERR, "  config: $file is not a JSON object, skipped\n");
				continue;
			}
			Q_Config::load($file);
			$loaded[] = $file;
		}
		return $loaded;
	}

	/**
	 * The environment envvars sets: "export NAME=value" or "NAME=value" per
	 * line, # comments, optional single or double quotes. No expansion --
	 * the file is read, not executed.
	 * @method envvars
	 * @static
	 * @param {string|null} $dir
	 * @return {array} name => value
	 */
	static function envvars($dir)
	{
		$vars = array();
		if ($dir === null or !is_file("$dir/envvars")) return $vars;
		foreach (file("$dir/envvars", FILE_IGNORE_NEW_LINES) ?: array() as $line) {
			$line = trim($line);
			if ($line === '' or $line[0] === '#') continue;
			if (!preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $m)) continue;
			$value = trim($m[2]);
			if (strlen($value) >= 2 and ($value[0] === '"' or $value[0] === "'") and substr($value, -1) === $value[0]) {
				$value = substr($value, 1, -1);
			}
			$vars[$m[1]] = $value;
		}
		return $vars;
	}

	/**
	 * What would be used, for --layout and for people.
	 * @method describe
	 * @static
	 * @param {string|null} $dir
	 * @param {string|null} $configFile
	 * @return {array}
	 */
	static function describe($dir, $configFile = null)
	{
		$pairs = array();
		if ($dir !== null) {
			foreach (self::$pairs as $p) {
				$pairs[$p] = array(
					'available' => array_map(function ($f) { return basename($f); },
						array_merge(glob("$dir/$p-available/*.conf") ?: array(), glob("$dir/$p-available/*.json") ?: array())),
					'enabled' => array_map('basename', self::enabled($dir, $p)),
				);
			}
		}
		return array(
			'confDir'  => $dir,
			'searched' => self::$standardDirs,
			'files'    => $dir !== null ? self::files($dir) : array(),
			'site'     => $configFile,
			'pairs'    => $pairs,
			'envvars'  => array_keys(self::envvars($dir)),
			'designs'  => ($dir !== null and is_dir("$dir/designs")) ? "$dir/designs" : null,
		);
	}
}
