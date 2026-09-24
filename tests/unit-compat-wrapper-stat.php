<?php

/**
 * The compat file wrapper answers stat and directory calls as PHP does, and
 * without leaving a resource behind for each one.
 *
 * To reach the real filesystem from inside a file:// wrapper, the wrapper has
 * to unregister itself and register again afterwards, and every registration
 * allocates a resource that PHP frees only when the request ends -- never, in
 * a persistent worker. file_exists() and is_dir() did that on every call, and
 * Exponential makes thousands of them a request: a worker grew ~1.6 MB a
 * request. Now missing paths and directory listings are answered with glob(),
 * which does not touch the wrappers, and the transform sends file_exists(),
 * is_dir() and is_file() to Compat shims that ask the OS directly.
 *
 * Checked here: every answer through the wrapper matches the native one --
 * including the mtime and size PHP's stat cache would otherwise have served
 * from a made-up type-only answer -- across files, directories, dot files, a
 * dangling link and names full of glob pattern characters; the shims agree
 * with native; and thousands of shim calls and missing-path probes allocate
 * nothing.
 *
 *   php tests/unit-compat-wrapper-stat.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q/WebServer/Compat.php';

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

$base = sys_get_temp_dir() . DS . 'qbix-wrapstat-' . getmypid();
@mkdir($base . DS . 'dir' . DS . 'sub', 0700, true);
@mkdir($base . DS . 'we[ird]*?dir', 0700, true);
file_put_contents($base . DS . 'dir' . DS . 'file.txt', 'x');
file_put_contents($base . DS . 'dir' . DS . '.hidden', 'x');
file_put_contents($base . DS . 'we[ird]*?dir' . DS . 'a{b}c.php', 'x');
file_put_contents($base . DS . 'star*.txt', 'x');
$links = DS === '/' and @symlink($base . DS . 'nowhere', $base . DS . 'dangling');
@symlink($base . DS . 'dir', $base . DS . 'dirlink');

$paths = array(
	$base, $base . DS . 'dir', $base . DS . 'dir' . DS . 'file.txt',
	$base . DS . 'dir' . DS . '.hidden', $base . DS . 'dir' . DS . 'sub',
	$base . DS . 'missing', $base . DS . 'dir' . DS . 'missing.txt',
	$base . DS . 'we[ird]*?dir', $base . DS . 'we[ird]*?dir' . DS . 'a{b}c.php',
	$base . DS . 'star*.txt', $base . DS . 'star?.txt', $base . DS . 's*',
	$base . DS . 'dangling', $base . DS . 'dirlink', $base . DS . 'dir' . DS,
);

// Native answers first.
$native = array();
foreach ($paths as $p) {
	clearstatcache();
	$native[$p] = array(file_exists($p), is_dir($p), is_file($p),
		@filemtime($p), @filesize($p), is_link($p));
}
$listing = function ($d) { $e = @scandir($d); if ($e) sort($e); return $e; };
$nativeList = array($listing($base . DS . 'dir'), $listing($base . DS . 'we[ird]*?dir'),
	$listing($base . DS . 'missing'));

// Then through the wrapper.
stream_wrapper_unregister('file');
stream_wrapper_register('file', 'Q_WebServer_CompatFileWrapper');

foreach ($paths as $p) {
	clearstatcache();
	$got = array(file_exists($p), is_dir($p), is_file($p), @filemtime($p), @filesize($p), is_link($p));
	check('stat answers match native for ' . substr($p, strlen($base)), $got, $native[$p]);
}
// The file_exists-then-filemtime pattern, on the same path, back to back.
foreach (array($base . DS . 'dir' . DS . 'file.txt', $base . DS . 'dir') as $p) {
	clearstatcache();
	$seq = array(is_file($p), file_exists($p), filemtime($p), filesize($p));
	check('filemtime straight after a type check is the real one, ' . substr($p, strlen($base)),
		$seq, array($native[$p][2], $native[$p][0], $native[$p][3], $native[$p][4]));
}
// The shims the transform calls instead.
foreach ($paths as $p) {
	$got = array(Q_WebServer_Compat::_file_exists($p), Q_WebServer_Compat::_is_dir($p),
		Q_WebServer_Compat::_is_file($p));
	check('shims match native for ' . substr($p, strlen($base)), $got,
		array_slice($native[$p], 0, 3));
}
// Stats are remembered for the request; a change made through the wrapper
// must still show on the very next call.
$mf = $base . DS . 'dir' . DS . 'file.txt';
clearstatcache(); $m1 = filemtime($mf);
touch($mf, $m1 + 100);
clearstatcache();
check('a touch() is seen by the next filemtime(), not a remembered one', filemtime($mf), $m1 + 100);
file_put_contents($mf, 'longer content');
clearstatcache();
check('a rewrite is seen by the next filesize()', filesize($mf), 14);
Q_WebServer_CompatFileWrapper::forgetStats();
clearstatcache();
check('forgetStats() leaves real answers', filesize($mf), 14);
file_put_contents($mf, 'x');
touch($mf, $native[$mf][3]);
clearstatcache();
$wrapList = array($listing($base . DS . 'dir'), $listing($base . DS . 'we[ird]*?dir'),
	$listing($base . DS . 'missing'));
check('directory listings match native', $wrapList, $nativeList);

// ── Nothing allocated per call ──────────────────────────────────

$factories = function () {
	$n = 0;
	foreach (get_resources() as $r) if (get_resource_type($r) === 'stream factory') ++$n;
	return $n;
};
$before = $factories();
for ($i = 0; $i < 1000; ++$i) {
	Q_WebServer_Compat::_file_exists($base . DS . 'dir' . DS . 'file.txt');
	Q_WebServer_Compat::_file_exists($base . DS . 'missing');
	Q_WebServer_Compat::_is_dir($base . DS . 'dir');
	Q_WebServer_Compat::_is_file($base . DS . 'dir' . DS . 'file.txt');
	Q_WebServer_Compat::_is_dir($base . DS . 'missing');
}
check('5000 existence and type checks allocate no wrapper resources',
	$factories() - $before, 0);
$before = $factories();
for ($i = 0; $i < 1000; ++$i) {
	clearstatcache();
	file_exists($base . DS . 'missing' . $i);
	is_dir($base . DS . 'dir' . DS . 'nope');
}
check('2000 native probes of missing paths allocate no wrapper resources',
	$factories() - $before, 0);
$before = $factories();
for ($i = 0; $i < 200; ++$i) scandir($base . DS . 'dir');
check('200 directory listings allocate no wrapper resources', $factories() - $before, 0);

stream_wrapper_restore('file');

foreach (array('dir/file.txt', 'dir/.hidden', 'we[ird]*?dir/a{b}c.php', 'star*.txt') as $f)
	@unlink($base . DS . $f);
@unlink($base . DS . 'dangling'); @unlink($base . DS . 'dirlink');
@rmdir($base . DS . 'dir' . DS . 'sub'); @rmdir($base . DS . 'dir');
@rmdir($base . DS . 'we[ird]*?dir'); @rmdir($base);

if ($fail) {
	printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail);
	exit(1);
}
printf("  PASS - %d case(s)\n", $pass);
