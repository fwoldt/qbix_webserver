<?php

/**
 * The pre-warm result has to survive a restart.
 *
 * Q_WebServer_Compat::prewarm() walks the document root and tokenises every
 * PHP file so that workers inherit the answers copy-on-write. On this
 * installation that walk cost 4.7 seconds and the overwhelming majority of it
 * established that a file needs no transform at all -- an answer that is the
 * same on every start until the file changes, and was thrown away at exit.
 *
 * Persisting it takes the walk to 0.6 seconds. The risk that buys is worse
 * than slowness, though: a cache trusted when it should not be feeds a worker
 * the wrong source for a file that has changed. So the rules are strict, and
 * this covers them -- an entry is reused only when size and mtime both still
 * match, and the whole file is discarded if it was written by a different
 * transformer, a different PHP, or for a different root.
 *
 *   php tests/unit-compat-prewarm-cache.php
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

// The cache directory comes from configuration. A stub puts it under this
// test's own scratch directory so the run touches nothing it does not own.
$scratch = sys_get_temp_dir() . DS . 'qbix-prewarm-test-' . getmypid();
@mkdir($scratch . DS . 'cache', 0700, true);
@mkdir($scratch . DS . 'app', 0700, true);

if (!class_exists('Q_Config')) {
	class Q_Config
	{
		static $dir;
		static function get()
		{
			$args = func_get_args();
			$default = array_pop($args);
			if (implode('/', $args) === 'Q/webserver/compat/dir') return self::$dir;
			return $default;
		}
	}
}
Q_Config::$dir = $scratch . DS . 'cache';

require __DIR__ . '/../src/Q/WebServer/Compat.php';

$C = 'Q_WebServer_Compat';

if (!method_exists($C, 'persistPath')) {
	fwrite(STDERR, "  FAIL - persistence is not present in this build\n");
	exit(1);
}

function cleanup($dir)
{
	if (!is_dir($dir)) return;
	foreach (scandir($dir) as $f) {
		if ($f === '.' || $f === '..') continue;
		$p = $dir . DIRECTORY_SEPARATOR . $f;
		is_dir($p) ? cleanup($p) : @unlink($p);
	}
	@rmdir($dir);
}
register_shutdown_function(function () use ($scratch) { cleanup($scratch); });

$app = $scratch . DS . 'app';

// A file that needs no transform, and one that does. Which construct triggers
// a transform is the other test's business; here it only matters that the two
// files differ in whether they produce a sentinel, so both paths are covered.
file_put_contents($app . DS . 'plain.php', "<?php\necho 'hello';\n");
file_put_contents($app . DS . 'other.php', "<?php\n\$x = 1;\necho \$x;\n");

// ── Round trip ──────────────────────────────────────────────────

$root = realpath($app);
$path = $C::persistPath($root);
check('a cache path is resolved', is_string($path) && $path !== '', true);
check('the path is inside the configured directory',
	strpos($path, $scratch) === 0, true);

check('nothing is loaded before anything is saved',
	$C::loadPersisted($root), null);

$entries = array(
	$app . DS . 'plain.php' => array('source' => false, 'mtime' => 111, 'size' => 22),
	$app . DS . 'other.php' => array('source' => '<?php x', 'mtime' => 222, 'size' => 33),
);
check('saving reports success', $C::savePersisted($root, $entries), true);
check('the file is on disk', is_file($path), true);
check('what comes back is what went in', $C::loadPersisted($root), $entries);

// The file holds transformed copies of the application's own source.
check('the file is private to its owner',
	substr(sprintf('%o', fileperms($path)), -3), '600');

check('no temporary file is left behind',
	count(glob(dirname($path) . DS . '*.tmp')), 0);

// ── What must be refused ────────────────────────────────────────

check('a cache written for another root is not used',
	$C::loadPersisted($root . '/elsewhere'), null);

$raw = unserialize(file_get_contents($path));
$raw['php'] = PHP_VERSION_ID + 1;
file_put_contents($path, serialize($raw));
check('a cache written by another PHP is discarded',
	$C::loadPersisted($root), null);

$raw['php'] = PHP_VERSION_ID;
$raw['v'] = $C::PREWARM_FORMAT + 1;
file_put_contents($path, serialize($raw));
check('a cache written by another transformer is discarded',
	$C::loadPersisted($root), null);

file_put_contents($path, 'this is not serialized data at all');
check('a corrupt file is ignored rather than fatal',
	$C::loadPersisted($root), null);

file_put_contents($path, '');
check('an empty file is ignored', $C::loadPersisted($root), null);

file_put_contents($path, serialize(array('v' => $C::PREWARM_FORMAT,
	'php' => PHP_VERSION_ID, 'root' => $root)));
check('a file with no entries is ignored', $C::loadPersisted($root), null);

@unlink($path);

// ── The behaviour that matters ──────────────────────────────────

$n = $C::prewarm($app, 100);
check('the first walk sees both files', $n, 2);
check('the first walk reuses nothing', $C::prewarmReused(), 0);
check('the first walk writes a cache', is_file($path), true);

$n = $C::prewarm($app, 100);
check('the second walk sees both files', $n, 2);
check('the second walk reuses both', $C::prewarmReused(), 2);

// A changed file must be re-read, or the worker is handed source that no
// longer matches what is on disk. Size differs here, which is the ordinary
// case; mtime is covered separately below because an edit inside the same
// second leaves it untouched.
file_put_contents($app . DS . 'plain.php', "<?php\necho 'hello, world, again';\n");
$C::prewarm($app, 100);
check('a file whose size changed is re-read', $C::prewarmReused(), 1);

$C::prewarm($app, 100);
check('and is reused once recorded', $C::prewarmReused(), 2);

// Same length, different bytes, and mtime moved: the stat check has to catch
// it on the mtime alone.
$before = filemtime($app . DS . 'plain.php');
file_put_contents($app . DS . 'plain.php', "<?php\necho 'HELLO, WORLD, AGAIN';\n");
touch($app . DS . 'plain.php', $before + 10);
clearstatcache();
$C::prewarm($app, 100);
check('a file whose mtime moved is re-read', $C::prewarmReused(), 1);

// A file that disappears must leave the map, not linger with stale source.
unlink($app . DS . 'other.php');
clearstatcache();
$n = $C::prewarm($app, 100);
check('a deleted file is dropped from the walk', $n, 1);
$stored = $C::loadPersisted(realpath($app));
check('and from the cache written for the next start', count($stored), 1);
check('the survivor is the one still on disk',
	isset($stored[$app . DS . 'plain.php']), true);

// A new file must be picked up rather than ignored because a cache exists.
file_put_contents($app . DS . 'third.php', "<?php\necho 3;\n");
clearstatcache();
$n = $C::prewarm($app, 100);
check('a new file is walked', $n, 2);
check('and only it is re-read', $C::prewarmReused(), 1);

if ($fail) {
	printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail);
	exit(1);
}
printf("  PASS - %d case(s)\n", $pass);
