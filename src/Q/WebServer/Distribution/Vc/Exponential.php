<?php
/**
 * @module Q
 */
/**
 * Recognises an Exponential installation, of any release.
 *
 * Every Exponential tree -- and the legacy releases it grew from -- records
 * its release in lib/version.php: class eZPublishSDK with the constants
 * VERSION_MAJOR, VERSION_MINOR, VERSION_RELEASE, VERSION_STATE,
 * VERSION_DEVELOPMENT, VERSION_ALIAS and EDITION on current releases, and
 * only functions returning those numbers (majorVersion(), minorVersion(),
 * release(), state(), alias()) on older ones. The file is parsed, never
 * included: including it would declare eZPublishSDK inside the server, and a
 * second installation served by the same process would then collide.
 *
 * The siteaccess list comes from settings/site.ini and its override, read as
 * text for [SiteAccessSettings] and [SiteSettings] only -- nothing else in
 * those files (database settings among them) is read or shown.
 *
 * Commands run the installation's own scripts from its directory. Composer
 * is never run from the panel for this kind (composerWrite false): an
 * installation's extensions are often live git checkouts that an install or
 * update would replace.
 *
 * Registered by Q_WebServer_Distribution_Vc.
 *
 * @class Q_WebServer_Distribution_Vc_Exponential
 */
class Q_WebServer_Distribution_Vc_Exponential implements Q_WebServer_Framework_Detector
{
	function detect($dir)
	{
		if (!is_file("$dir/lib/version.php") or !is_dir("$dir/kernel")) return null;
		if (!is_file("$dir/settings/site.ini") and !is_dir("$dir/settings")) return null;
		$ver = self::version("$dir/lib/version.php");
		if ($ver === null) return null;

		$sa = self::siteaccesses($dir);
		$php = Q_WebServer_Framework::php();
		$ezcache = array($php, 'bin/php/ezcache.php');
		$root = array('--allow-root-user');
		$commands = array(
			array('name' => 'List cache tags', 'cmd' => 'cache:list', 'argv' => array_merge($ezcache, array('--list-tags'), $root)),
			array('name' => 'Clear all caches', 'cmd' => 'cache:clear-all', 'disruptive' => true,
				'argv' => array_merge($ezcache, array('--clear-all'), $root)),
			array('name' => 'Clear template cache', 'cmd' => 'cache:clear-template', 'disruptive' => true,
				'argv' => array_merge($ezcache, array('--clear-tag=template'), $root)),
			array('name' => 'Clear content cache', 'cmd' => 'cache:clear-content', 'disruptive' => true,
				'argv' => array_merge($ezcache, array('--clear-tag=content'), $root)),
			array('name' => 'Clear INI cache', 'cmd' => 'cache:clear-ini', 'disruptive' => true,
				'argv' => array_merge($ezcache, array('--clear-tag=ini'), $root)),
			array('name' => 'Regenerate autoloads', 'cmd' => 'autoloads', 'disruptive' => true,
				'argv' => array($php, 'bin/php/ezpgenerateautoloads.php', '-e')),
			array('name' => 'List siteaccesses', 'cmd' => 'siteaccess:list',
				'output' => $sa['list'] ? implode("\n", array_map(function ($s) use ($sa) {
					return $s . ($s === $sa['default'] ? '  (default)' : '');
				}, $sa['list'])) . "\n" : "(no siteaccesses listed in settings)\n"),
		);
		if (is_file("$dir/console") or is_file("$dir/bin/php/console")) {
			$console = is_file("$dir/console") ? 'console' : 'bin/php/console';
			$commands[] = array('name' => 'Console commands', 'cmd' => 'console:list',
				'argv' => array($php, $console, 'list'));
		}
		if (is_file("$dir/runcronjobs.php")) {
			$commands[] = array('name' => 'Run frequent cronjobs', 'cmd' => 'cron:frequent', 'disruptive' => true,
				'argv' => array($php, 'runcronjobs.php', 'frequent', '--allow-root-user'));
			$commands[] = array('name' => 'Run cronjobs', 'cmd' => 'cron:default', 'disruptive' => true,
				'argv' => array($php, 'runcronjobs.php', '--allow-root-user'));
		}

		$expInfo = __DIR__ . DIRECTORY_SEPARATOR . 'expinfo.php';
		if (is_file($expInfo)) {
			$commands[] = array('name' => 'Site installation info', 'cmd' => 'expinfo',
				'argv' => array($php, $expInfo));
		}

		$links = array(array('label' => 'Site', 'url' => '/'));
		foreach ($sa['list'] as $s) {
			if (stripos($s, 'admin') !== false) { $links[] = array('label' => 'Admin', 'url' => '/' . $s . '/'); break; }
		}
		$details = array();
		if ($ver['alias'] !== '') $details['Release line'] = $ver['alias'];
		if ($sa['list']) $details['Siteaccesses'] = implode(', ', $sa['list']);
		if ($sa['default'] !== '') $details['Default siteaccess'] = $sa['default'];
		if ($sa['siteName'] !== '') $details['Site name'] = $sa['siteName'];
		$composer = is_file("$dir/composer.json") ? json_decode((string) @file_get_contents("$dir/composer.json"), true) : null;
		if (is_array($composer) and !empty($composer['name'])) {
			$details['Package'] = $composer['name'] . (!empty($composer['version']) ? ' ' . $composer['version'] : '');
		}
		if ($ver['development'] !== '' and $ver['development'] !== false) $details['Development build'] = (string) $ver['development'];
		if (class_exists('Q_Config', false) and ($preset = Q_Config::get('Q', 'webserver', 'preset', null))) {
			$details['Server preset'] = (string) $preset;
		}

		return array(
			'kind' => 'exponential',
			'name' => $ver['edition'],
			'edition' => $ver['edition'],
			'version' => $ver['version'],
			'state' => $ver['state'],
			'webRoot' => $dir,
			'siteaccesses' => $sa['list'],
			'details' => $details,
			'links' => $links,
			'commands' => $commands,
			'composerWrite' => false,
		);
	}

	/**
	 * The release lib/version.php records, in either form.
	 * @method version
	 * @static
	 * @param {string} $file
	 * @return {array|null} version, major, minor, release, state, alias, edition, development
	 */
	static function version($file)
	{
		$v = Q_WebServer_Framework_VersionFile::read($file);
		if (!$v) return null;
		$c = $v['consts'];
		$f = $v['funcs'];
		$pick = function ($const, $func) use ($c, $f) {
			if (array_key_exists($const, $c)) return $c[$const];
			if (array_key_exists($func, $f)) return $f[$func];
			return null;
		};
		$major = $pick('VERSION_MAJOR', 'majorVersion');
		$minor = $pick('VERSION_MINOR', 'minorVersion');
		if ($major === null or $minor === null) return null;
		$release = $pick('VERSION_RELEASE', 'release');
		$state = $pick('VERSION_STATE', 'state');
		$alias = $pick('VERSION_ALIAS', 'alias');
		$dev = $pick('VERSION_DEVELOPMENT', 'developmentVersion');
		$edition = isset($c['EDITION']) && is_string($c['EDITION']) && $c['EDITION'] !== '' ? $c['EDITION'] : 'Exponential';
		return array(
			'version' => $major . '.' . $minor . ($release !== null ? '.' . $release : ''),
			'major' => $major, 'minor' => $minor, 'release' => $release,
			'state' => is_string($state) ? $state : '',
			'alias' => is_scalar($alias) ? (string) $alias : '',
			'edition' => $edition,
			'development' => $dev === null ? '' : $dev,
		);
	}

	/**
	 * The siteaccesses the installation lists, its default, and its name --
	 * from settings/site.ini then its override, INI arrays merged the way the
	 * application merges them (an empty `Name[]` clears the list).
	 * @method siteaccesses
	 * @static
	 * @param {string} $dir
	 * @return {array} list, default, siteName
	 */
	static function siteaccesses($dir)
	{
		$list = array();
		$default = '';
		$siteName = '';
		foreach (array('settings/site.ini', 'settings/override/site.ini.append', 'settings/override/site.ini.append.php') as $rel) {
			$file = "$dir/$rel";
			if (!is_file($file) or @filesize($file) > 1048576) continue;
			$block = '';
			foreach (preg_split('/\r\n|\r|\n/', (string) @file_get_contents($file)) as $line) {
				$line = trim($line);
				if ($line === '' or $line[0] === '#' or $line[0] === ';') continue;
				if (preg_match('/^\[([^\]]+)\]$/', $line, $m)) { $block = $m[1]; continue; }
				if ($block === 'SiteAccessSettings' and preg_match('/^AvailableSiteAccessList\[\]\s*(=\s*(.*))?$/', $line, $m)) {
					if (!isset($m[2])) { $list = array(); continue; }
					$name = trim($m[2]);
					if ($name !== '' and preg_match('/^[A-Za-z0-9_.-]+$/', $name) and !in_array($name, $list, true)) $list[] = $name;
				} elseif ($block === 'SiteSettings' and preg_match('/^(DefaultAccess|SiteName)\s*=\s*(.*)$/', $line, $m)) {
					if ($m[1] === 'DefaultAccess') $default = trim($m[2]);
					else $siteName = trim($m[2]);
				}
			}
		}
		return array('list' => $list, 'default' => $default, 'siteName' => $siteName);
	}
}
