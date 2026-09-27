<?php
/**
 * The response cache reads its generation marker's mtime in the server
 * process, where the compat file wrapper is registered and remembers file
 * stats. A purge rewrites the marker; the cache must see the new mtime within
 * the second it checks, not whenever the wrapper next forgets.
 *
 * It once called the method on the wrong class (Q_WebServer_Compat, which has
 * no forgetPath()), behind a guard that made the mistake silent: purged pages
 * were served for up to eight seconds more. So this checks the call's target
 * exists, and that forgetting one path really drops its remembered stat.
 *
 *   php tests/unit-cache-generation-sees-wrapper-stat.php
 */

require_once __DIR__ . '/../src/Q/WebServer/Compat.php';

$pass = 0;
$fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}

$cache = file_get_contents(__DIR__ . '/../src/Q/WebServer/Cache.php');
check('Cache::generation() forgets the marker through a class that has forgetPath()',
	preg_match('/(Q_WebServer_\w+)::forgetPath\(self::\$generationFile\)/', $cache, $m) === 1 && method_exists($m[1], 'forgetPath'), true);

$dir = sys_get_temp_dir() . '/qbix-gen-stat-' . getmypid();
@mkdir($dir, 0700, true);
$file = $dir . '/marker';
file_put_contents($file, 'a');
touch($file, 1000000000);

$memo = new ReflectionProperty('Q_WebServer_CompatFileWrapper', 'statMemo');
$memo->setAccessible(true);

$first = @Q_WebServer_CompatFileWrapper::realStat($file);
check('the wrapper answers the stat', (int) $first['mtime'], 1000000000);
check('...and remembers it', array_key_exists($file, $memo->getValue()), true);

$memo->setValue(null, $memo->getValue() + array($dir . '/other' => array('mtime' => 1)));
Q_WebServer_CompatFileWrapper::forgetPath($file);
check('forgetPath() forgets that file', array_key_exists($file, $memo->getValue()), false);
check('...and only that file', array_key_exists($dir . '/other', $memo->getValue()), true);

// What a purge does: the marker is rewritten under a new time.
touch($file, 1000000100);
clearstatcache(true, $file);
check('after forgetting, the new mtime is what it answers', (int) @Q_WebServer_CompatFileWrapper::realStat($file)['mtime'], 1000000100);

@unlink($file);
@rmdir($dir);

echo "\n";
if ($fail === 0) { printf("  PASS - %d case(s)\n\n", $pass); exit(0); }
printf("  FAIL - %d of %d\n\n", $fail, $pass + $fail);
exit(1);
