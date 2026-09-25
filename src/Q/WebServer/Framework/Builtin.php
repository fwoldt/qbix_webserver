<?php
/**
 * @module Q
 */
/**
 * The detectors the engine ships: common frameworks and CMSs, then any
 * Composer project, then any PHP site.
 *
 * Each reads the release from the application's own version file with
 * Q_WebServer_Framework_VersionFile (never by including it), and lists the
 * commands its own CLI offers. Those commands are the only ones the panel
 * will run for it.
 *
 * @class Q_WebServer_Framework_Builtin
 */
class Q_WebServer_Framework_Builtin implements Q_WebServer_Framework_Detector
{
	/** @var string */
	private $kind;

	function __construct($kind)
	{
		$this->kind = $kind;
	}

	/**
	 * priority => detectors, for Q_WebServer_Framework.
	 * @method detectors
	 * @static
	 * @return {array}
	 */
	static function detectors()
	{
		$specific = array();
		foreach (array('qbix', 'laravel', 'symfony', 'wordpress', 'drupal', 'joomla', 'magento',
			'typo3', 'craftcms', 'moodle', 'mediawiki', 'nextcloud', 'prestashop', 'laminas', 'fuelphp') as $k) {
			$specific[] = new self($k);
		}
		return array(0 => $specific, -50 => array(new self('composer')), -100 => array(new self('php')));
	}

	function detect($dir)
	{
		$m = 'detect' . ucfirst($this->kind);
		return $this->$m($dir);
	}

	// ── helpers ───────────────────────────────────────────────

	/** A file's parsed literals, or an empty shape. */
	private static function v($file)
	{
		$v = Q_WebServer_Framework_VersionFile::read($file);
		return $v ?: array('consts' => array(), 'defines' => array(), 'vars' => array(), 'funcs' => array(), 'classes' => array());
	}

	/** The version composer.lock records for a package, or ''. */
	private static function lockVersion($dir, $package)
	{
		$lock = $dir . '/composer.lock';
		if (!is_file($lock) or @filesize($lock) > 8388608) return '';
		$j = json_decode((string) @file_get_contents($lock), true);
		foreach (array_merge((array) ($j['packages'] ?? array()), (array) ($j['packages-dev'] ?? array())) as $p) {
			if (($p['name'] ?? '') === $package) return ltrim((string) ($p['version'] ?? ''), 'v');
		}
		return '';
	}

	/** composer.json, decoded, or an empty array. */
	private static function composer($dir)
	{
		$f = $dir . '/composer.json';
		if (!is_file($f) or @filesize($f) > 1048576) return array();
		$j = json_decode((string) @file_get_contents($f), true);
		return is_array($j) ? $j : array();
	}

	/** An executable found on the PATH, or null. */
	private static function which($cmd)
	{
		foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $p) {
			if ($p !== '' and is_file($p . '/' . $cmd) and is_executable($p . '/' . $cmd)) return $p . '/' . $cmd;
		}
		return null;
	}

	/** Commands from a list of array(name, cmd[, disruptive]). */
	private static function cmds(array $list)
	{
		$out = array();
		foreach ($list as $c) {
			$out[] = array('name' => $c[0], 'cmd' => $c[1], 'disruptive' => !empty($c[2]));
		}
		return $out;
	}

	// ── detectors ─────────────────────────────────────────────

	private function detectQbix($dir)
	{
		if (!is_file("$dir/config/app.json") and !is_file("$dir/web/Q.php")) return null;
		$c = json_decode((string) @file_get_contents("$dir/config/app.json"), true);
		return array('kind' => 'qbix', 'name' => 'Qbix app',
			'details' => array('App' => (string) ($c['Q']['app'] ?? basename($dir))),
			'webRoot' => is_dir("$dir/web") ? "$dir/web" : $dir);
	}

	private function detectLaravel($dir)
	{
		if (!is_file("$dir/artisan")) return null;
		$v = self::v("$dir/vendor/laravel/framework/src/Illuminate/Foundation/Application.php");
		$version = (string) ($v['consts']['VERSION'] ?? self::lockVersion($dir, 'laravel/framework'));
		$env = is_file("$dir/.env") ? (@parse_ini_file("$dir/.env", false, INI_SCANNER_RAW) ?: array()) : array();
		return array('kind' => 'laravel', 'name' => 'Laravel', 'version' => $version,
			'webRoot' => "$dir/public",
			'details' => array_filter(array('App' => $env['APP_NAME'] ?? '', 'Environment' => $env['APP_ENV'] ?? '',
				'Debug' => (($env['APP_DEBUG'] ?? '') === 'true') ? 'on' : '')),
			'appName' => $env['APP_NAME'] ?? basename($dir), 'appEnv' => $env['APP_ENV'] ?? 'unknown',
			'debug' => ($env['APP_DEBUG'] ?? 'false') === 'true',
			'cli' => array(Q_WebServer_Framework::php(), 'artisan'),
			'commands' => self::cmds(array(
				array('Clear cache', 'cache:clear', true), array('Clear config', 'config:clear', true),
				array('Clear routes', 'route:clear', true), array('Clear views', 'view:clear', true),
				array('Migrate', 'migrate --force', true), array('Migrate status', 'migrate:status'),
				array('Route list', 'route:list --compact'), array('Queue restart', 'queue:restart', true),
				array('Storage link', 'storage:link', true), array('Optimize', 'optimize', true))));
	}

	private function detectSymfony($dir)
	{
		if (!is_file("$dir/bin/console") or !is_file("$dir/composer.json")) return null;
		$c = self::composer($dir);
		$req = (array) ($c['require'] ?? array());
		if (!isset($req['symfony/framework-bundle']) and !isset($req['symfony/console']) and !is_dir("$dir/config/packages")) return null;
		$v = self::v("$dir/vendor/symfony/http-kernel/Kernel.php");
		return array('kind' => 'symfony', 'name' => 'Symfony',
			'version' => (string) ($v['consts']['VERSION'] ?? self::lockVersion($dir, 'symfony/http-kernel')),
			'webRoot' => "$dir/public",
			'cli' => array(Q_WebServer_Framework::php(), 'bin/console'),
			'commands' => self::cmds(array(
				array('Clear cache', 'cache:clear', true), array('Cache warmup', 'cache:warmup', true),
				array('Route list', 'debug:router --no-interaction'),
				array('Container', 'debug:container --no-interaction'),
				array('Migrate', 'doctrine:migrations:migrate --no-interaction', true),
				array('Migration status', 'doctrine:migrations:status'),
				array('Assets install', 'assets:install', true))));
	}

	private function detectWordpress($dir)
	{
		if (!is_file("$dir/wp-includes/version.php") and !is_file("$dir/wp-config.php")) return null;
		$v = self::v("$dir/wp-includes/version.php");
		$wp = self::which('wp') ?: (is_file("$dir/vendor/bin/wp") ? "$dir/vendor/bin/wp" : null);
		return array('kind' => 'wordpress', 'name' => 'WordPress', 'version' => (string) ($v['vars']['wp_version'] ?? ''),
			'links' => array(array('label' => 'Admin', 'url' => '/wp-admin/')),
			'hasCli' => (bool) $wp, 'cliPath' => $wp,
			'cli' => $wp ? array($wp, '--path=' . $dir) : array(),
			'commands' => $wp ? self::cmds(array(
				array('Plugin list', 'plugin list'), array('Theme list', 'theme list'),
				array('Core version', 'core version --extra'), array('Cache flush', 'cache flush', true),
				array('Rewrite flush', 'rewrite flush', true), array('DB check', 'db check'),
				array('Cron list', 'cron event list'),
				array('User list', 'user list --fields=ID,user_login,user_email,roles'))) : array());
	}

	private function detectDrupal($dir)
	{
		$core = is_file("$dir/core/lib/Drupal.php") ? $dir : (is_file("$dir/web/core/lib/Drupal.php") ? "$dir/web" : null);
		if (!$core) return null;
		$v = self::v("$core/core/lib/Drupal.php");
		$drush = is_file("$dir/vendor/bin/drush") ? "$dir/vendor/bin/drush" : self::which('drush');
		return array('kind' => 'drupal', 'name' => 'Drupal', 'version' => (string) ($v['consts']['VERSION'] ?? ''),
			'webRoot' => $core, 'links' => array(array('label' => 'Sign in', 'url' => '/user/login')),
			'hasCli' => (bool) $drush, 'cli' => $drush ? array($drush) : array(),
			'commands' => $drush ? self::cmds(array(
				array('Cache rebuild', 'cache:rebuild', true), array('Status', 'status'),
				array('Module list', 'pm:list --status=enabled'), array('Update DB', 'updatedb -y', true),
				array('Cron run', 'cron', true))) : array());
	}

	private function detectJoomla($dir)
	{
		if (!is_dir("$dir/administrator") or !is_file("$dir/index.php")) return null;
		if (!is_file("$dir/configuration.php") and !is_file("$dir/libraries/src/Version.php")) return null;
		$v = self::v("$dir/libraries/src/Version.php");
		$c = $v['consts'];
		$version = isset($c['MAJOR_VERSION']) ? $c['MAJOR_VERSION'] . '.' . ($c['MINOR_VERSION'] ?? 0) . '.' . ($c['PATCH_VERSION'] ?? 0) : '';
		return array('kind' => 'joomla', 'name' => 'Joomla', 'version' => $version,
			'links' => array(array('label' => 'Admin', 'url' => '/administrator/')),
			'cli' => is_file("$dir/cli/joomla.php") ? array(Q_WebServer_Framework::php(), 'cli/joomla.php') : array(),
			'commands' => is_file("$dir/cli/joomla.php") ? self::cmds(array(
				array('Clear cache', 'cache:clean', true), array('Extension list', 'extension:list'),
				array('Check updates', 'update:extensions:check'), array('Site info', 'site:info'))) : array());
	}

	private function detectMagento($dir)
	{
		if (!is_file("$dir/bin/magento") and !is_file("$dir/app/etc/env.php")) return null;
		return array('kind' => 'magento', 'name' => 'Magento',
			'version' => self::lockVersion($dir, 'magento/magento2-base') ?: (string) (self::composer($dir)['version'] ?? ''),
			'webRoot' => is_dir("$dir/pub") ? "$dir/pub" : $dir,
			'cli' => is_file("$dir/bin/magento") ? array(Q_WebServer_Framework::php(), 'bin/magento') : array(),
			'commands' => is_file("$dir/bin/magento") ? self::cmds(array(
				array('Cache status', 'cache:status'), array('Cache flush', 'cache:flush', true),
				array('Indexer status', 'indexer:status'))) : array());
	}

	private function detectTypo3($dir)
	{
		$base = is_dir("$dir/typo3") ? $dir : (is_dir("$dir/public/typo3") ? "$dir/public" : null);
		if (!$base or (!is_dir("$base/typo3conf") and !is_file("$dir/composer.json"))) return null;
		if (!is_dir("$base/typo3/sysext") and !is_dir("$dir/vendor/typo3")) return null;
		$v = self::v("$base/typo3/sysext/core/Classes/Information/Typo3Version.php");
		if (!$v['consts']) $v = self::v("$dir/vendor/typo3/cms-core/Classes/Information/Typo3Version.php");
		return array('kind' => 'typo3', 'name' => 'TYPO3', 'version' => (string) ($v['consts']['VERSION'] ?? ''),
			'webRoot' => $base, 'links' => array(array('label' => 'Backend', 'url' => '/typo3/')));
	}

	private function detectCraftcms($dir)
	{
		if (!is_file("$dir/craft") or !is_dir("$dir/config")) return null;
		return array('kind' => 'craftcms', 'name' => 'Craft CMS', 'version' => self::lockVersion($dir, 'craftcms/cms'),
			'webRoot' => is_dir("$dir/web") ? "$dir/web" : $dir,
			'cli' => array(Q_WebServer_Framework::php(), 'craft'),
			'commands' => self::cmds(array(array('Clear caches', 'clear-caches/all', true))));
	}

	private function detectMoodle($dir)
	{
		if (!is_file("$dir/version.php") or !is_dir("$dir/mod")) return null;
		$v = self::v("$dir/version.php");
		return array('kind' => 'moodle', 'name' => 'Moodle', 'version' => (string) ($v['vars']['release'] ?? ''));
	}

	private function detectMediawiki($dir)
	{
		if (!is_file("$dir/LocalSettings.php") and !is_file("$dir/includes/Defines.php")) return null;
		if (!is_dir("$dir/includes")) return null;
		$v = self::v("$dir/includes/Defines.php");
		return array('kind' => 'mediawiki', 'name' => 'MediaWiki', 'version' => (string) ($v['defines']['MW_VERSION'] ?? ''));
	}

	private function detectNextcloud($dir)
	{
		if (!is_file("$dir/status.php") or !is_file("$dir/version.php")) return null;
		$v = self::v("$dir/version.php");
		return array('kind' => 'nextcloud', 'name' => 'Nextcloud', 'version' => (string) ($v['vars']['OC_VersionString'] ?? ''),
			'cli' => is_file("$dir/occ") ? array(Q_WebServer_Framework::php(), 'occ') : array(),
			'commands' => is_file("$dir/occ") ? self::cmds(array(array('Status', 'status'), array('App list', 'app:list'))) : array());
	}

	private function detectPrestashop($dir)
	{
		if (!is_file("$dir/classes/PrestaShopAutoload.php") and !is_file("$dir/config/defines.inc.php")) return null;
		$v = self::v("$dir/app/AppKernel.php");
		return array('kind' => 'prestashop', 'name' => 'PrestaShop', 'version' => (string) ($v['consts']['VERSION'] ?? ''));
	}

	private function detectLaminas($dir)
	{
		if (!is_dir("$dir/module") or !is_file("$dir/config/modules.config.php")) return null;
		return array('kind' => 'laminas', 'name' => 'Laminas', 'version' => self::lockVersion($dir, 'laminas/laminas-mvc'),
			'webRoot' => is_dir("$dir/public") ? "$dir/public" : $dir);
	}

	private function detectFuelphp($dir)
	{
		if (!is_file("$dir/fuel/app/bootstrap.php")) return null;
		return array('kind' => 'fuelphp', 'name' => 'FuelPHP', 'webRoot' => is_dir("$dir/public") ? "$dir/public" : $dir);
	}

	private function detectComposer($dir)
	{
		if (!is_file("$dir/composer.json")) return null;
		// A library checkout, not an application: nothing to serve.
		if (!is_file("$dir/index.php") and !is_file("$dir/public/index.php") and !is_file("$dir/web/index.php")) return null;
		$c = self::composer($dir);
		return array('kind' => 'composer', 'name' => (string) ($c['name'] ?? basename($dir)),
			'version' => (string) ($c['version'] ?? ''),
			'webRoot' => is_file("$dir/public/index.php") ? "$dir/public" : (is_file("$dir/web/index.php") ? "$dir/web" : $dir),
			'details' => array_filter(array('Description' => (string) ($c['description'] ?? ''))));
	}

	private function detectPhp($dir)
	{
		if (!is_file("$dir/index.php")) return null;
		return array('kind' => 'php', 'name' => 'PHP site');
	}
}
