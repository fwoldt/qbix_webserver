<?php

/**
 * A script regenerated on disk is run with its new contents when included
 * through the compat file wrapper with the opcode cache on.
 *
 * Template engines rewrite their compiled templates in place and include them
 * by relative path, often under a symlinked document root. An attempt to save
 * an unwrap per include answered includes of scripts the opcode cache already
 * held with an empty stream. On a live Exponential install, pages then lost
 * whole templates (/healthy-eating rendered 1 article of 22) on some workers.
 *
 * That failure was not reproduced in isolation -- this test passes with the
 * shortcut put back -- so it is not a regression test for that bug; the
 * guard for it is a live comparison of the application's pages against a
 * reference server. What this holds is the invariant any such shortcut must
 * keep: a regenerated script runs its new contents, and relative, absolute
 * and symlinked includes all run the real script.
 *
 * It re-runs itself with opcache.enable_cli=1, so the cache is involved.
 *
 *   php tests/unit-compat-include-regenerated.php
 */

if (getenv('QBIX_INCLUDE_REGEN_CHILD') !== '1') {
	$cmd = escapeshellarg(PHP_BINARY) . ' -d opcache.enable=1 -d opcache.enable_cli=1'
		. ' -d opcache.revalidate_freq=0 ' . escapeshellarg(__FILE__);
	$out = array();
	exec('QBIX_INCLUDE_REGEN_CHILD=1 ' . $cmd . ' 2>&1', $out, $code);
	echo implode("\n", $out), "\n";
	exit($code);
}

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

$opcache = function_exists('opcache_get_status') and ($s = @opcache_get_status(false))
	and !empty($s['opcache_enabled']);
if (!$opcache) { printf("  skip  the opcode cache is not available\n"); exit(0); }

$dir = sys_get_temp_dir() . DS . 'qbix-regen-' . getmypid();
@mkdir($dir, 0700, true);
$f = $dir . DS . 'compiled.php';
file_put_contents($f, '<?php return "first";');
touch($f, time() - 120);

stream_wrapper_unregister('file');
stream_wrapper_register('file', 'Q_WebServer_CompatFileWrapper');

check('the script runs through the wrapper', include $f, 'first');
check('...and again, from the cache', include $f, 'first');
check('the opcode cache holds it', opcache_is_script_cached($f), true);

// Regenerated in place, as a template compiler does.
file_put_contents($f, '<?php return "second, regenerated";');
touch($f, time() - 60);
clearstatcache();
check('after regeneration the new contents run, not nothing', include $f, 'second, regenerated');

// Relative includes, as Exponential includes its compiled templates: the
// shortcut this guards against set the opened path to the relative string,
// the cache's lookup by resolved path missed, and it compiled -- and kept --
// the empty stream.
$g = $dir . DS . 'relative.php';
file_put_contents($g, '<?php return "relative";');
touch($g, time() - 120);
$cwd = getcwd();
chdir($dir);
check('a relative include runs the script', include 'relative.php', 'relative');
check('...again', include 'relative.php', 'relative');
check('...and by absolute path', include $g, 'relative');
check('...and relatively once more, after the absolute one', include './relative.php', 'relative');
chdir($cwd);

// Through a symbolic link to the directory, as a document root often is:
// the cache keys scripts by resolved path.
$link = $dir . '-link';
stream_wrapper_restore('file');
@symlink($dir, $link);
stream_wrapper_unregister('file');
stream_wrapper_register('file', 'Q_WebServer_CompatFileWrapper');
if (is_link($link)) {
	$h = $dir . DS . 'linked.php';
	stream_wrapper_restore('file');
	file_put_contents($h, '<?php return "linked";');
	touch($h, time() - 120);
	stream_wrapper_unregister('file');
	stream_wrapper_register('file', 'Q_WebServer_CompatFileWrapper');
	check('included by its real path', include $h, 'linked');
	check('...and through the link', include $link . DS . 'linked.php', 'linked');
	chdir($link);
	check('...and relatively inside the linked directory', include 'linked.php', 'linked');
	check('...twice', include 'linked.php', 'linked');
	chdir($cwd);
}

stream_wrapper_restore('file');
@unlink($dir . DS . 'linked.php'); @unlink($link);
@unlink($f); @unlink($g); @rmdir($dir);

if ($fail) {
	printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail);
	exit(1);
}
printf("  PASS - %d case(s)\n", $pass);
