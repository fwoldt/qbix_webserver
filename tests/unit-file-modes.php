<?php

/**
 * What permissions does a log or a cache entry get?
 *
 * Until these keys existed the answer was whatever the umask said, which on
 * most machines means 0644 -- a log naming the people who visited, readable by
 * every account on the box. fileMode and dirMode change that, and the whole
 * risk of such a change is that it quietly changes it for installations that
 * never asked. So the first cases here do not assert "0644": they assert that
 * with no keys set, the result is exactly what the umask produces, checked
 * under two different umasks. That is the case that has to keep passing.
 *
 * The rest are the places where a mode is easy to lose: the file a rotation
 * creates to replace the one it moved aside, the .gz an archive run writes,
 * the intermediate levels of a mkdir -p, a virtual host that inherits what it
 * does not say, and a value someone mistyped.
 *
 *   php tests/unit-file-modes.php
 *
 * init() announces itself on STDERR, so a passing run is chatty. The runner
 * only shows a test's output when it fails.
 */

// Cache.php builds paths with DS, the way the other cache tests declare it.
if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);

$pass = 0;
$fail = 0;
$skip = 0;

function ok($cond, $what)
{
	global $pass, $fail;
	if ($cond) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n", $what);
}

function same($expected, $got, $what)
{
	global $pass, $fail;
	if ($expected === $got) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        expected %s\n        got      %s\n",
		$what, var_export($expected, true), var_export($got, true));
}

/** Compare permissions, reporting them in octal — 0644 vs 420 is unreadable. */
function mode($expected, $path, $what)
{
	global $pass, $fail;
	clearstatcache(true, $path);
	$got = @fileperms($path);
	if ($got !== false and ($got & 07777) === $expected) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        expected %s\n        got      %s\n", $what,
		decoct($expected), $got === false ? 'missing' : decoct($got & 07777));
}

function skipped($why)
{
	global $skip;
	++$skip;
	printf("  skip  %s\n", $why);
}

// ── Stubs ────────────────────────────────────────────────────────────────
// The whole of what Log.php needs from the rest of Q.

class Q_Config
{
	static $data = array();

	static function get()
	{
		$args = func_get_args();
		$default = array_pop($args);
		$node = self::$data;
		foreach ($args as $key) {
			if (!is_array($node) or !array_key_exists($key, $node)) return $default;
			$node = $node[$key];
		}
		return $node;
	}

	static function set($config)
	{
		self::$data = array('Q' => array('webserver' => $config));
	}
}

class Q
{
	static function ifset($array, $key, $default = null)
	{
		return isset($array[$key]) ? $array[$key] : $default;
	}
}

class Q_Evented
{
	static function repeat($interval, $callback) {}
}

require_once __DIR__ . '/../src/Q/WebServer/Log.php';
require_once __DIR__ . '/../src/Q/WebServer/Cache.php';

// ── Harness ──────────────────────────────────────────────────────────────

$tmp = sys_get_temp_dir() . '/qbix-file-modes-' . getmypid();
$startUmask = umask();

function rrmdir($dir)
{
	if (!is_dir($dir)) return;
	foreach (scandir($dir) as $f) {
		if ($f === '.' or $f === '..') continue;
		$path = "$dir/$f";
		is_dir($path) ? rrmdir($path) : @unlink($path);
	}
	@rmdir($dir);
}

function reset_logs($logConfig, $hosts = array(), $dir = null)
{
	global $tmp;
	rrmdir($tmp);
	mkdir($tmp, 0755, true);
	Q_WebServer_Log::shutdown();
	Q_WebServer_Log::$hosts = array();
	Q_WebServer_Log::$enabled = false;
	Q_WebServer_Log::$accessFp = null;
	Q_WebServer_Log::$errorFp = null;
	Q_WebServer_Log::$accessBuf = '';
	Q_WebServer_Log::$fileMode = null;
	Q_WebServer_Log::$dirMode = null;
	Q_WebServer_Log::$dirModeExplicit = false;
	$logConfig['dir'] = $dir ?: (isset($logConfig['dir']) ? $logConfig['dir'] : $tmp);
	Q_Config::set(array('log' => $logConfig, 'hosts' => $hosts));
	Q_WebServer_Log::init();
}

function hit($host = null, $uri = '/')
{
	Q_WebServer_Log::access('127.0.0.1', 'GET', $uri, 200, 12, '', 'curl', 1.5,
		array('headers' => $host === null ? array() : array('host' => $host)));
	Q_WebServer_Log::flush();
}

echo "\n";

// ── Can this filesystem hold a mode at all? ──────────────────────────────
// FAT and some network mounts take chmod and do nothing. Without this the
// test would fail there and say nothing useful about the code.

$probeDir = $tmp . '-probe';
@mkdir($probeDir, 0777, true);
$probe = $probeDir . '/probe';
@file_put_contents($probe, 'x');
@chmod($probe, 0640);
clearstatcache(true, $probe);
$modesWork = (@fileperms($probe) & 07777) === 0640;
rrmdir($probeDir);

if (!$modesWork) {
	skipped('this filesystem does not keep permissions — nothing to assert');
	printf("\n  PASS - %d case(s), %d skipped\n\n", $pass, $skip);
	exit(0);
}

// ── 1. No keys: exactly what the umask gives, under two umasks ───────────
// The regression guard. If this ever starts asserting a fixed 0644, the
// patch has begun overriding installations that never configured anything.

foreach (array(0022, 0002) as $um) {
	umask($um);
	reset_logs(array('bufferSize' => 0));
	hit();
	mode(0666 & ~$um, "$tmp/access.log",
		'no fileMode: the file is 0666 & ~umask ' . decoct($um));
	mode(0755 & ~$um, $tmp,
		'no dirMode: the directory is 0755 & ~umask ' . decoct($um));
}

// ── 2. fileMode overrides the umask, in both directions ──────────────────

umask(0077);
reset_logs(array('fileMode' => '0660', 'bufferSize' => 0));
hit();
mode(0660, "$tmp/access.log", 'fileMode widens past a strict umask');
mode(0660, "$tmp/error.log", 'and applies to the error log too');

umask(0000);
reset_logs(array('fileMode' => '0600', 'bufferSize' => 0));
hit();
mode(0600, "$tmp/access.log", 'fileMode narrows past a permissive umask');

// ── 3. dirMode, including setgid ─────────────────────────────────────────

umask(0022);
reset_logs(array('dirMode' => '0770'));
mode(0770, $tmp, 'dirMode overrides the umask on the directory');

reset_logs(array('dirMode' => '2770'));
clearstatcache(true, $tmp);
if ((@fileperms($tmp) & 02000) === 0) {
	skipped('setgid does not stick here (egid differs from the group?)');
} else {
	mode(02770, $tmp, 'dirMode can set the setgid bit');
}

// ── 4. mkdir -p: every level this call creates, not just the last ────────
// The one case that catches it. A chmod after mkdir(..., true) fixes the
// leaf and leaves the levels above it at the umask, without setgid — which
// is the inheritance the bit was for.

$deep = $tmp . '/one/two/three';
reset_logs(array('dirMode' => '0770'), array(), $deep);
mode(0770, $deep, 'the deepest level gets the mode');
mode(0770, $tmp . '/one/two', 'and so does the level above it');
mode(0770, $tmp . '/one', 'and the one above that');

// ── 5. Rotation: the rotated file and the fresh one that replaces it ─────

reset_logs(array('fileMode' => '0640', 'maxSize' => 200, 'bufferSize' => 0));
for ($i = 0; $i < 6; ++$i) hit();
Q_WebServer_Log::checkRotation();
$rotated = glob("$tmp/access.*.log");
same(1, count($rotated), 'the log rotated');
if ($rotated) {
	mode(0640, $rotated[0], 'the rotated file keeps the mode');
}
mode(0640, "$tmp/access.log", 'and the fresh live file is created with it');

// ── 6. The .gz an archive run writes ─────────────────────────────────────
// A gzipped log is the same log. An archive directory full of 0644 copies
// of files that were 0640 would undo the whole point.

reset_logs(array('fileMode' => '0640', 'archiveAfterDays' => 1,
	'deleteAfterDays' => 30, 'bufferSize' => 0));
hit();
$old = time() - (5 * 86400);
file_put_contents("$tmp/access.2000-01-01.log", "old\n");
touch("$tmp/access.2000-01-01.log", $old);
Q_WebServer_Log::archiveAndPrune();
if (!function_exists('gzopen')) {
	skipped('no zlib, so no archive to check');
} else {
	ok(file_exists("$tmp/access.2000-01-01.log.gz"), 'the old log was archived');
	mode(0640, "$tmp/access.2000-01-01.log.gz",
		'the archive has the mode of the log it came from');
}

// ── 7. Per host: its own mode, or the server's ───────────────────────────

$other = sys_get_temp_dir() . '/qbix-file-modes-other-' . getmypid();
rrmdir($other);
reset_logs(array('fileMode' => '0640', 'dirMode' => '0770', 'bufferSize' => 0),
	array(
		'a.example.com' => array('log' => array('fileMode' => '0600')),
		'b.example.com' => array('log' => true),
		'c.example.com' => array('log' => array('dir' => $other)),
	));
hit('a.example.com');
hit('b.example.com');
hit('c.example.com');
mode(0600, "$tmp/a.example.com-access.log", 'a host can be stricter than the server');
mode(0640, "$tmp/b.example.com-access.log", 'and inherits when it says nothing');
mode(0640, "$tmp/access.log", "the server's own log is unaffected");
mode(0770, $other, "a host's own directory gets the server's dirMode");
mode(0640, "$other/c.example.com-access.log", 'and its file the server fileMode');

// ── 8. A value that is not a mode behaves as if unset ────────────────────

umask(0022);
foreach (array('abc', '0999', '0o660', '', '   ', 'rwxrwx---', '123456',
		true, array(), '-0660') as $bad) {
	reset_logs(array('fileMode' => $bad, 'bufferSize' => 0));
	hit();
	mode(0666 & ~0022, "$tmp/access.log",
		'refuses ' . (is_array($bad) ? 'array()' : var_export($bad, true))
		. ' and leaves the umask in charge');
}

// parse() directly, where the cases are cheapest.
same(null, Q_WebServer_Modes::parse('abc'), 'parse refuses a word');
same(0660, Q_WebServer_Modes::parse('0660'), 'parse takes 0660');
same(0660, Q_WebServer_Modes::parse('660'), 'parse takes 660 without the zero');
same(02770, Q_WebServer_Modes::parse('02770'), 'parse takes 02770');
same(02770, Q_WebServer_Modes::parse('2770'), 'parse takes 2770');
same(0660, Q_WebServer_Modes::parse(660), 'parse reads an integer as written');
same(0755, Q_WebServer_Modes::parse('nonsense', 0755), 'parse falls back');

// ── 9. Mode 0 is a mode ──────────────────────────────────────────────────
// The guard against `if ($mode)`, which would treat it as unset.

same(0, Q_WebServer_Modes::parse('0000'), 'parse keeps 0000 as 0, not null');
reset_logs(array('fileMode' => '0000', 'bufferSize' => 0));
hit();
mode(0, "$tmp/access.log", 'fileMode 0000 creates an unreadable file');

// ── 10. The cache ────────────────────────────────────────────────────────
// Driven through put() rather than through the helper it calls, so this
// covers the mode reaching the entry the server actually writes -- and
// that setting a mode on the revalidation claim did not change what the
// claim returns, which is the thing making it atomic.

reset_logs(array());
$cacheDir = $tmp . '/cache';
Q_WebServer_Cache::$enabled = true;
Q_WebServer_Cache::$dir = $cacheDir;
Q_WebServer_Cache::$apcuEnabled = false;
Q_WebServer_Cache::$skipCookies = array();
Q_WebServer_Cache::$defaultTtl = 300;
Q_WebServer_Cache::$fileMode = 0660;
Q_WebServer_Cache::$dirMode = 02770;
Q_WebServer_Cache::$dirModeExplicit = true;
Q_WebServer_Modes::dir($cacheDir, 02770, true);

$request = array(
	'method' => 'GET',
	'path' => '/a-page',
	'query' => '',
	'headers' => array('host' => 'example.com'),
);
$response = array(
	'status' => 200,
	'headers' => array('Content-Type' => 'text/html'),
	'body' => 'hello',
);

Q_WebServer_Cache::put($request, $response);

$entries = array();
$dirs = array();
$walk = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator($cacheDir, RecursiveDirectoryIterator::SKIP_DOTS)
);
foreach ($walk as $f) {
	if ($f->isFile()) $entries[] = $f->getPathname();
}
foreach (glob($cacheDir . '/*', GLOB_ONLYDIR) as $d) $dirs[] = $d;

ok(count($entries) > 0, 'put() wrote an entry');
if ($entries) {
	mode(0660, $entries[0], 'the entry has the configured mode');
}
ok(count($dirs) > 0, 'and put() created a shard directory');
if ($dirs) {
	mode(02770, $dirs[0], 'which has the configured dirMode');
}
ok(is_array(Q_WebServer_Cache::get($request)), 'and the entry reads back');

// The claim is a mkdir, and its return value is the lock.
$key = md5('example.com/a-page?');
same(true, Q_WebServer_Cache::claimRevalidation($key),
	'the first caller claims revalidation');
same(false, Q_WebServer_Cache::claimRevalidation($key),
	'and the second does not');
$lock = Q_WebServer_Cache::filePath($key) . '.refresh';
if (is_dir($lock)) {
	mode(02770, $lock, 'the claim directory gets the mode too');
} else {
	skipped('the claim went somewhere this test cannot see');
}

// ── 11. A mode set after the fact converges ──────────────────────────────
// What umask alone cannot do: it does not touch a file that already exists.

umask(0022);
reset_logs(array('bufferSize' => 0));
hit();
mode(0644, "$tmp/access.log", 'starts at the umask');
Q_WebServer_Log::shutdown();
Q_Config::set(array('log' => array('dir' => $tmp, 'fileMode' => '0660',
	'bufferSize' => 0)));
Q_WebServer_Log::$enabled = false;
Q_WebServer_Log::init();
hit();
mode(0660, "$tmp/access.log", 'and is relabelled once a mode is configured');

// ── Done ─────────────────────────────────────────────────────────────────

Q_WebServer_Log::shutdown();
umask($startUmask);
rrmdir($tmp);
rrmdir($other);

echo "\n";
if ($fail === 0) {
	printf("  PASS - %d case(s)%s\n\n", $pass, $skip ? ", $skip skipped" : '');
	exit(0);
}
printf("  FAIL - %d of %d\n\n", $fail, $pass + $fail);
exit(1);
