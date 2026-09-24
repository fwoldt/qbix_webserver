<?php

/**
 * The source transform gives the same answer every time, under the JIT too.
 *
 * PHP 8.2 and 8.3's function JIT (opcache.jit=1235) compiled
 * Q_WebServer_Compat::transformSource() in the middle of a call once its token
 * loop turned hot, and the compiled loop started again from the first token
 * while keeping what had been written: about the fourth call returned every
 * token so far twice -- a parse error in the script being loaded. The loop is
 * written so that JIT compiles it correctly; this runs it hot, under that
 * exact mode, and compares every result with the first.
 *
 * Re-runs itself with opcache and the JIT on; skipped where the JIT is not
 * available (no opcache, or a build without it).
 *
 *   php tests/unit-compat-transform-under-jit.php
 */

if (getenv('QBIX_TRANSFORM_JIT_CHILD') === false) {
	$cmd = escapeshellarg(PHP_BINARY) . ' -d opcache.enable=1 -d opcache.enable_cli=1'
		. ' -d opcache.jit=1235 -d opcache.jit_buffer_size=64M ' . escapeshellarg(__FILE__);
	passthru('QBIX_TRANSFORM_JIT_CHILD=1 ' . $cmd, $code);
	exit($code);
}

if (!function_exists('opcache_get_status') or !($s = @opcache_get_status(false)) or empty($s['jit']['enabled'])) {
	printf("  skip  the opcache JIT is not available here\n");
	exit(0);
}

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q/WebServer/Compat.php';

$pass = 0; $fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}

$scripts = array(
	'<?php putenv("QBIX_REGEN=2"); return "t2";',
	'<?php header("X-A: 1"); setcookie("a", "b"); if ($x) exit; return 1;',
	"<?php\nfunction f() { return headers_sent(); }\n\$o?->header('x');\ndie('done');\n",
);
foreach ($scripts as $n => $code) {
	$first = Q_WebServer_Compat::transformSource($code, '');
	$differ = 0;
	$example = null;
	for ($i = 0; $i < 500; $i++) {
		$got = Q_WebServer_Compat::transformSource($code, '');
		if ($got !== $first) { $differ++; if ($example === null) $example = $got; }
	}
	check("script $n: 500 hot calls under the JIT all transform alike", $differ ? "$differ differ, e.g. " . var_export($example, true) : 0, 0);
	check("script $n: and the result parses", (function () use ($first) {
		try { token_get_all($first, TOKEN_PARSE); return true; } catch (\ParseError $e) { return $e->getMessage(); }
	})(), true);
}

if ($fail) { printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail); exit(1); }
printf("  PASS - %d case(s) (JIT %s)\n", $pass, ini_get('opcache.jit'));
