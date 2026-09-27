<?php

/**
 * Reading a PHP file through the compat file wrapper gives the file's own
 * bytes. Only include and require get the transformed source.
 *
 * The wrapper transformed every .php file opened for reading, so
 * file_get_contents(), md5_file() and fopen() on application source returned
 * the rewritten code. An application that checks its own files saw them all as
 * changed: Exponential's upgrade check (Setup > System Upgrade > File
 * consistency) listed about 350 kernel and library files as modified under
 * Velocity, and none under Apache.
 *
 *   php tests/unit-compat-read-is-the-file.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q/WebServer/Compat.php';
// Switch the transform on without the server around it.
$en = new ReflectionProperty('Q_WebServer_Compat', 'enabled');
$en->setAccessible(true);
$en->setValue(null, true);

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

$dir = sys_get_temp_dir() . DS . 'qbix-read-' . getmypid();
@mkdir($dir, 0700, true);
$f = $dir . DS . 'sends-a-header.php';
// header() is one of the calls the transform rewrites.
$source = "<?php\nfunction qbix_read_test() { header('X-Read-Test: 1'); return 'ran'; }\nreturn 'included';\n";
file_put_contents($f, $source);
touch($f, time() - 120);
$md5 = md5($source);

check('the transform does change this file', Q_WebServer_Compat::transformSource($source, '') !== $source, true);

stream_wrapper_unregister('file');
stream_wrapper_register('file', 'Q_WebServer_CompatFileWrapper');

check('file_get_contents gives the file, before any include', file_get_contents($f), $source);
check('md5_file hashes the file, before any include', md5_file($f), $md5);

check('include runs it', include $f, 'included');

// After the include the transform is cached; a read must still not get it.
check('file_get_contents gives the file after an include', file_get_contents($f), $source);
check('md5_file hashes the file after an include', md5_file($f), $md5);
$h = fopen($f, 'rb');
check('fopen and fread give the file', stream_get_contents($h), $source);
fclose($h);
check('file() gives its lines', implode('', file($f)), $source);

stream_wrapper_restore('file');
@unlink($f);
@rmdir($dir);

printf("%s  %d passed, %d failed\n", $fail ? 'FAIL' : 'PASS', $pass, $fail);
exit($fail ? 1 : 0);
