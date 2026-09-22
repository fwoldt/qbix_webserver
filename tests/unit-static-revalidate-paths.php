<?php

/**
 * Some files must never be held, however long other static files may be.
 *
 * A service worker is installed by the browser and then outlives the page that
 * installed it, deciding what every later navigation is answered with. Served
 * with the year-long max-age this installation gives static files, a bug in one
 * cannot be withdrawn: the fix sits on the server while browsers keep running
 * the old copy out of their own cache. That is the difference between a bad
 * deploy and a bad deploy nobody can take back.
 *
 *   php tests/unit-static-revalidate-paths.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);

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

// Q_WebServer pulls in the world, so the two methods under test are exercised
// through a copy of their source, taken from the file at run time so it cannot
// drift from it.
$src = file_get_contents(__DIR__ . '/../src/Q/WebServer.php');
$out = '';
foreach (array('staticCacheControl', 'revalidateAlways') as $name) {
	if (!preg_match('/\n\tstatic function ' . $name . '\(.*?\n\t\}\n/s', $src, $m)) {
		fwrite(STDERR, "  FAIL - $name() not found in src/Q/WebServer.php\n");
		exit(1);
	}
	$out .= $m[0];
}

// A stand-in for the config the real method reads.
class Q_Config
{
	static $values = array();
	static function get()
	{
		$args = func_get_args();
		$default = array_pop($args);
		$key = implode('.', $args);
		return array_key_exists($key, self::$values) ? self::$values[$key] : $default;
	}
}

eval('class T { ' . $out . ' }');

Q_Config::$values['Q.web.static.maxAge'] = 31536000;

// ── The files that decide how a client behaves afterwards ────────────────
foreach (array(
	'/var/www/site/sw.js',
	'sw.js',
	'/deep/nested/path/service-worker.js',
	'/app.webmanifest',
	'/manifest.json',
) as $path) {
	check("$path is never held", T::staticCacheControl($path), 'no-cache, must-revalidate');
}

// ── Everything else still gets the long lifetime ─────────────────────────
foreach (array(
	'/var/site/cache/public/stylesheets/abc_all.css',
	'/images/logo.svg',
	'/fonts/inter.woff2',
	'/js/index.js',
) as $path) {
	check("$path keeps the static lifetime",
		T::staticCacheControl($path), 'public, max-age=31536000');
}

// A name that merely contains "sw.js" is not a service worker.
check('a file that only looks similar is not matched',
	T::staticCacheControl('/js/answers.js'), 'public, max-age=31536000');
check('nor one with the name inside it',
	T::staticCacheControl('/js/view-sw.json'), 'public, max-age=31536000');

// ── Called with no path at all, as the 304 path does ─────────────────────
check('with no path it falls back to the static lifetime',
	T::staticCacheControl(), 'public, max-age=31536000');
check('and to must-revalidate when no lifetime is configured', (function () {
	Q_Config::$values['Q.web.static.maxAge'] = 0;
	// staticCacheControl memoises, so this asserts on the path-aware branch,
	// which is the one that must not be memoised.
	return T::staticCacheControl('/sw.js');
})(), 'no-cache, must-revalidate');

echo "\n";
if ($fail === 0) { printf("  PASS - %d case(s)\n\n", $pass); exit(0); }
printf("  FAIL - %d of %d\n\n", $fail, $pass + $fail);
exit(1);
