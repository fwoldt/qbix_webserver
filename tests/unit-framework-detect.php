#!/usr/bin/env php
<?php
/**
 * One registry recognises the PHP applications the server can see: the
 * panel's Apps and Frameworks tabs and the autohost all ask it.
 *
 * Asserted, on fixture trees built here:
 *   - version files are read by parsing, never by including them (class
 *     constants, define(), variables, functions returning a literal or a
 *     constant), so a second installation can never collide with the first;
 *   - a legacy-CMS installation of any release is recognised by its
 *     lib/version.php -- the current constant form, an older constants form
 *     without an edition, and the oldest function-only form -- with its
 *     siteaccesses read from site.ini and its override (an empty `Name[]`
 *     clears the list), and composer writes refused for it;
 *   - Laravel, Symfony, WordPress, Drupal, Joomla, Qbix, a Composer app and a
 *     plain PHP site are each recognised, with the release their own version
 *     file states;
 *   - the autohost's detectFramework() agrees with the registry;
 *   - scanning is bounded (MAX_DIRS) however many directories a root holds;
 *   - run() runs only listed commands, asks for confirmation on disruptive
 *     ones, runs argv arrays in the application's directory, and serves
 *     built-in output without a subprocess;
 *   - the panel's Apps and Frameworks APIs list the served installation first
 *     and refuse composer writes where the detector says so.
 *
 *   php tests/unit-framework-detect.php
 */
if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
$base = sys_get_temp_dir() . DS . 'qbix-fwdetect-' . getmypid();
@mkdir($base, 0700, true);
define('APP_DIR', $base . DS . 'appdir');
@mkdir(APP_DIR, 0700, true);
require __DIR__ . '/../src/Q.php';
require_once __DIR__ . '/../src/Q/WebServer/Framework.php';
require_once __DIR__ . '/../src/Q/WebServer/Framework/Detector.php';
require_once __DIR__ . '/../src/Q/WebServer/Distribution/Vc/Exponential.php';

$pass = 0;
$fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what,
		var_export($got, true), var_export($want, true));
}

/** Write $files (relative path => contents) under $dir. */
function tree($dir, array $files)
{
	foreach ($files as $rel => $body) {
		$p = $dir . DS . $rel;
		@mkdir(dirname($p), 0755, true);
		file_put_contents($p, $body);
	}
	return $dir;
}

$F = 'Q_WebServer_Framework';
$F::reset();
$F::register(new Q_WebServer_Distribution_Vc_Exponential(), 50);

// ── Version files are parsed, not run ────────────────────────────────
$v = Q_WebServer_Framework_VersionFile::parse('<?php
define("APP_VERSION", "1.2.3");
$wp_version = \'6.4.2\';
class Info {
	const MAJOR = 6, MINOR = 0;
	const string LABEL = "x";
	const NEG = -3;
	const DEV = false;
	static function major() { return self::MAJOR; }
	static function patch() { return 15; }
	static function name() { return "a" . "b"; }
}');
check('parse: define()', $v['defines']['APP_VERSION'] ?? null, '1.2.3');
check('parse: top-level variable', $v['vars']['wp_version'] ?? null, '6.4.2');
check('parse: several constants in one statement', array($v['consts']['MAJOR'] ?? null, $v['consts']['MINOR'] ?? null), array(6, 0));
check('parse: a typed constant', $v['consts']['LABEL'] ?? null, 'x');
check('parse: a negative number and false', array($v['consts']['NEG'] ?? null, $v['consts']['DEV'] ?? 'unset'), array(-3, false));
check('parse: a function returning a literal', $v['funcs']['patch'] ?? null, 15);
check('parse: a function returning self::CONST resolves it', $v['funcs']['major'] ?? null, 6);
check('parse: a function returning an expression is left out', array_key_exists('name', $v['funcs']), false);
check('parse: the class is recorded, not declared', array($v['classes'], class_exists('Info', false)), array(array('Info'), false));

// ── The legacy CMS, every release form ───────────────────────────────
$siteIni = "[SiteSettings]\nDefaultAccess=site\nSiteName=Demo\n\n[SiteAccessSettings]\nAvailableSiteAccessList[]\nAvailableSiteAccessList[]=site\nAvailableSiteAccessList[]=admin\n";
$override = "<?php /* #?ini charset=\"utf-8\"?\n\n[DatabaseSettings]\nPassword=secret\n\n[SiteAccessSettings]\nAvailableSiteAccessList[]\nAvailableSiteAccessList[]=shop\nAvailableSiteAccessList[]=admin\nAvailableSiteAccessList[]=shop_ger\n*/ ?>";
$cms6 = tree($base . DS . 'cms6', array(
	'lib/version.php' => "<?php\nclass eZPublishSDK\n{\n    const VERSION_MAJOR = 6;\n    const VERSION_MINOR = 0;\n    const VERSION_RELEASE = 15;\n    const VERSION_STATE = 'stable';\n    const VERSION_DEVELOPMENT = false;\n    const VERSION_ALIAS = '6.0';\n    const EDITION = 'Exponential';\n    static function majorVersion() { return eZPublishSDK::VERSION_MAJOR; }\n}\n",
	'kernel/content/view.php' => '<?php',
	'settings/site.ini' => $siteIni,
	'settings/override/site.ini.append.php' => $override,
	'index.php' => '<?php',
	'composer.json' => '{"name":"acme/site","version":"6.0.15"}',
	'bin/php/ezcache.php' => "<?php echo 'ezcache ', implode(' ', array_slice(\$argv, 1)), ' in ', getcwd(), \"\\n\";",
));
$d = $F::detect($cms6);
check('cms 6.x: recognised', $d['kind'] ?? null, 'exponential');
check('cms 6.x: title from lib/version.php', $d['title'] ?? null, 'Exponential 6.0.15 (stable)');
check('cms 6.x: the override replaces the siteaccess list', $d['siteaccesses'] ?? null, array('shop', 'admin', 'shop_ger'));
check('cms 6.x: default siteaccess and release line shown', array($d['details']['Default siteaccess'] ?? null, $d['details']['Release line'] ?? null), array('site', '6.0'));
check('cms 6.x: nothing from other blocks (the database password) is read',
	strpos(json_encode($d), 'secret'), false);
check('cms 6.x: composer writes refused', $d['composerWrite'] ?? null, false);
check('cms 6.x: lib/version.php was never included', class_exists('eZPublishSDK', false), false);
$byCmd = array();
foreach ($d['commands'] as $c) $byCmd[$c['cmd']] = $c;
check('cms 6.x: clear-all is listed and disruptive', $byCmd['cache:clear-all']['disruptive'] ?? null, true);
check('cms 6.x: expinfo command is listed', isset($byCmd['expinfo']) ? $byCmd['expinfo']['name'] : null, 'Site installation info');
check('cms 6.x: the admin link points at the admin siteaccess', $d['links'][1]['url'] ?? null, '/admin/');

$cms5 = tree($base . DS . 'cms5', array(
	'lib/version.php' => "<?php\nclass eZPublishSDK {\n const VERSION_MAJOR = 5;\n const VERSION_MINOR = 4;\n const VERSION_RELEASE = 0;\n const VERSION_STATE = 'alpha1';\n const VERSION_ALIAS = '5.4';\n}\n",
	'kernel/x.php' => '<?php', 'settings/site.ini' => $siteIni,
));
check('cms 5.x (no EDITION constant): recognised, edition defaulted', $F::detect($cms5)['title'] ?? null, 'Exponential 5.4.0 (alpha1)');

$cms4 = tree($base . DS . 'cms4', array(
	'lib/version.php' => "<?php\nclass eZPublishSDK {\n  function majorVersion() { return 4; }\n  function minorVersion() { return 7; }\n  function release() { return 0; }\n  function state() { return 'stable'; }\n  function alias() { return '4.7'; }\n}\n",
	'kernel/x.php' => '<?php', 'settings/site.ini' => $siteIni,
));
$d4 = $F::detect($cms4);
check('cms 4.x (functions only): recognised with its release', $d4['title'] ?? null, 'Exponential 4.7.0 (stable)');
check('cms 4.x: siteaccesses from site.ini alone', $d4['siteaccesses'] ?? null, array('site', 'admin'));

$notCms = tree($base . DS . 'notcms', array('lib/version.php' => '<?php $x = 1;', 'kernel/x.php' => '<?php', 'index.php' => '<?php'));
check('a lib/version.php without release numbers is not the CMS', $F::detect($notCms)['kind'] ?? null, 'php');

// ── Other frameworks ─────────────────────────────────────────────────
$cases = array(
	'laravel' => array(array('artisan' => '', 'vendor/laravel/framework/src/Illuminate/Foundation/Application.php' => "<?php class Application { const VERSION = '11.9.2'; }"), 'Laravel 11.9.2'),
	'symfony' => array(array('bin/console' => '', 'composer.json' => '{"require":{"symfony/framework-bundle":"^7"}}', 'vendor/symfony/http-kernel/Kernel.php' => "<?php abstract class Kernel { public const VERSION = '7.4.8'; }"), 'Symfony 7.4.8'),
	'wordpress' => array(array('wp-config.php' => '', 'wp-includes/version.php' => "<?php\n\$wp_version = '6.6.1';\n\$wp_db_version = 57155;"), 'WordPress 6.6.1'),
	'drupal' => array(array('composer.json' => '{}', 'web/core/lib/Drupal.php' => "<?php class Drupal { const VERSION = '10.3.1'; }", 'web/index.php' => '<?php'), 'Drupal 10.3.1'),
	'joomla' => array(array('administrator/index.php' => '', 'index.php' => '', 'configuration.php' => '', 'libraries/src/Version.php' => "<?php final class Version { public const MAJOR_VERSION = 5; public const MINOR_VERSION = 1; public const PATCH_VERSION = 2; }"), 'Joomla 5.1.2'),
	'qbix' => array(array('config/app.json' => '{"Q":{"app":"Chess"}}', 'web/index.php' => ''), 'Qbix app'),
	'composer' => array(array('composer.json' => '{"name":"acme/tool","version":"2.0.1"}', 'public/index.php' => ''), 'acme/tool 2.0.1'),
	'php' => array(array('index.php' => '<?php echo 1;'), 'PHP site'),
);
foreach ($cases as $kind => $case) {
	$dir = tree($base . DS . 'fw-' . $kind, $case[0]);
	$d = $F::detect($dir);
	check("$kind: recognised", $d['kind'] ?? null, $kind);
	check("$kind: title", $d['title'] ?? null, $case[1]);
	check("$kind: the autohost agrees", Q_WebServer_Autohost::detectFramework($dir),
		in_array($kind, array('php', 'composer'), true) ? null : $kind);
}
check('an empty directory is nothing', $F::detect(tree($base . DS . 'empty', array('README' => 'x'))), null);

// ── Commands ─────────────────────────────────────────────────────────
$r = $F::run('exponential', 'rm -rf /', $cms6);
check('run: an unlisted command is refused', $r['status'] ?? null, 403);
$r = $F::run('exponential', 'cache:clear-all', $cms6);
check('run: a disruptive command needs confirmation', array($r['status'] ?? null, $r['confirm'] ?? null), array(409, true));
$r = $F::run('exponential', 'cache:list', $cms6);
check('run: a listed command runs as argv in the application directory',
	trim($r['output'] ?? '') === 'ezcache --list-tags --allow-root-user in ' . realpath($cms6) ? true : ($r['output'] ?? $r), true);
check('run: its exit code is reported', $r['exit'] ?? null, 0);
$r = $F::run('exponential', 'siteaccess:list', $cms6);
check('run: built-in output needs no subprocess', $r['output'] ?? null, "shop\nadmin\nshop_ger\n");
$r = $F::run('laravel', 'cache:clear', $cms6);
check('run: a kind that is not in that directory is refused', $r['status'] ?? null, 404);

// ── Scanning is bounded ──────────────────────────────────────────────
$wide = $base . DS . 'wide';
for ($i = 0; $i < 600; ++$i) @mkdir($wide . DS . sprintf('d%03d', $i), 0755, true);
tree($wide . DS . 'd005', array('index.php' => '<?php'));
Q_Config::set('Q', 'panel', 'appRoots', array($wide));
Q_WebServer::$rootDir = $cms6 . DS;
$t = microtime(true);
$all = $F::scan();
check('scan: bounded time over a root with 600 directories', microtime(true) - $t < 3.0, true);
check('scan: the served installation comes first, marked serving',
	array($all[0]['kind'] ?? null, $all[0]['serving'] ?? null), array('exponential', true));
$kinds = array_map(function ($d) { return $d['kind']; }, $all);
check('scan: applications in Q.panel.appRoots are found', in_array('php', $kinds, true), true);
check('scan: each directory listed once', count($all), count(array_unique(array_map(function ($d) { return $d['dir']; }, $all))));

// ── The panel's APIs ─────────────────────────────────────────────────
$apps = Q_WebServer_Panel::apiListApps();
check('panel apps: installations listed, served first', $apps['installations'][0]['title'] ?? null, 'Exponential 6.0.15 (stable)');
$fw = Q_WebServer_Panel::apiFrameworks();
check('panel frameworks: the served CMS is listed', $fw['frameworks'][0]['kind'] ?? null, 'exponential');
check('panel frameworks: plain PHP sites stay on the Apps tab',
	in_array('php', array_map(function ($d) { return $d['kind']; }, $fw['frameworks']), true), false);
$r = Q_WebServer_Panel::apiFrameworkRun(array('body' => json_encode(array('framework' => 'exponential', 'cmd' => 'cache:list', 'dir' => '/etc'))));
check('panel run: a directory the scan did not find is refused', $r['status'] ?? null, 404);
$r = Q_WebServer_Panel::apiFrameworkRun(array('body' => json_encode(array('framework' => 'exponential', 'cmd' => 'cache:clear-all'))));
check('panel run: disruptive without confirm is refused', $r['status'] ?? null, 409);
check('panel composer: writes refused for the CMS', (Q_WebServer_Panel::composerWriteRefused($cms6)['status'] ?? null), 403);
Q_Config::set('Q', 'panel', 'allowComposerWrite', true);
check('panel composer: allowed when Q.panel.allowComposerWrite is set', Q_WebServer_Panel::composerWriteRefused($cms6), null);

// Tidy up the fixtures.
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
@rmdir($base);

if ($fail) { printf("  FAIL - %d of %d case(s)\n", $fail, $pass + $fail); exit(1); }
printf("  PASS - %d case(s)\n", $pass);
