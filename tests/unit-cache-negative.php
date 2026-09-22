<?php

/**
 * A not-found is an answer too, and an expensive one.
 *
 * Measured across 278 public URLs on a live installation, a 404 cost 220-340ms
 * -- it runs the whole routing and rendering path before concluding there is
 * nothing there -- and none of them were ever cached, because put() refused any
 * status but 200. So a crawler walking a list of dead links paid full price on
 * every request, and so did the server, with nothing bounding it.
 *
 * The risk runs the other way too, which is why this is off unless asked for
 * and why most of the cases below are about refusing:
 *
 *   - a 500 must never be remembered; the next request is exactly when it
 *     might work
 *   - a 302 must never be remembered as a 404
 *   - publishing a page must not be shadowed for long by its own absence
 *
 *   php tests/unit-cache-negative.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q/WebServer/Cache.php';

$C = 'Q_WebServer_Cache';
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

$root = sys_get_temp_dir() . DS . 'qcache-neg-' . getmypid();
@mkdir($root, 0755, true);
register_shutdown_function(function () use ($root) {
	foreach (new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST) as $f) {
		$f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
	}
	@rmdir($root);
});

$C::$enabled = true;
$C::$dir = $root;
$C::$apcuEnabled = false;
$C::$staleWhileRevalidate = 0;

function req($path)
{
	return array('method' => 'GET', 'path' => $path, 'query' => '',
		'headers' => array('host' => 'example.test'));
}

function resp($status, $body = 'gone', $headers = array())
{
	return array('status' => $status, 'headers' => $headers, 'body' => $body);
}

// ── Off by default ───────────────────────────────────────────────────────
// This is the guarantee an existing installation depends on. It was briefly
// broken by `and` binding looser than `=`, which assigned only the status test
// and left the ttl check as a discarded expression -- so the feature switched
// itself on regardless of configuration. The two cases below are that bug.
$C::$negativeTtl = 0;
$C::put(req('/nowhere'), resp(404));
check('with negativeTtl 0 a 404 is not stored', $C::get(req('/nowhere')), null);

$C::put(req('/gone'), resp(410));
check('nor a 410', $C::get(req('/gone')), null);

// ── On, when asked ───────────────────────────────────────────────────────
$C::$negativeTtl = 60;
$C::put(req('/missing'), resp(404, 'not found here'));
$hit = $C::get(req('/missing'));
check('a 404 is remembered', is_array($hit), true);
check('as a 404, not as something else', $hit['status'], 404);
check('with its body intact', $hit['body'], 'not found here');
check('and marked as a hit', $hit['headers']['X-Cache'], 'HIT');

$C::put(req('/departed'), resp(410, 'gone for good'));
check('a 410 is remembered too', $C::get(req('/departed'))['status'], 410);

// ── What must never be remembered ────────────────────────────────────────
foreach (array(500, 502, 503, 400, 403, 429) as $status) {
	$C::put(req('/status-' . $status), resp($status));
	check("a $status is never remembered", $C::get(req('/status-' . $status)), null);
}

$C::put(req('/redirect'), resp(302, '', array('Location' => '/elsewhere')));
check('a redirect is not remembered as a negative',
	$C::get(req('/redirect')), null);

// ── Our lifetime, not the application's ──────────────────────────────────
// A not-found carries whatever Cache-Control the application happened to set,
// which is usually none and occasionally something meant for another purpose.
$C::put(req('/no-directives'), resp(404));
check('a 404 with no Cache-Control at all is still remembered',
	$C::get(req('/no-directives'))['status'], 404);

$C::put(req('/long-lived'),
	resp(404, 'x', array('Cache-Control' => 'public, max-age=86400')));
$m = new ReflectionMethod($C, 'filePath');
$m->setAccessible(true);
$raw = file_get_contents($m->invoke(null, $C::cacheKey(req('/long-lived'))));
$meta = json_decode(substr($raw, 0, strpos($raw, "\n")), true);
$life = $meta['expires'] - $meta['stored'];
check('a 404 claiming a day lives only as long as we allow', $life <= 60, true);

// A 200 still takes its lifetime from its own directives.
$C::put(req('/normal'), resp(200, 'a page',
	array('Cache-Control' => 'public, max-age=300')));
$raw = file_get_contents($m->invoke(null, $C::cacheKey(req('/normal'))));
$meta = json_decode(substr($raw, 0, strpos($raw, "\n")), true);
check('a 200 keeps its own lifetime', $meta['expires'] - $meta['stored'], 300);

// ── It expires ───────────────────────────────────────────────────────────
// Publishing a page must not be shadowed by its own absence for long.
$path = $m->invoke(null, $C::cacheKey(req('/missing')));
$raw = file_get_contents($path);
$nl = strpos($raw, "\n");
$meta = json_decode(substr($raw, 0, $nl), true);
$meta['expires'] = time() - 1;
file_put_contents($path, json_encode($meta) . "\n" . substr($raw, $nl + 1));
check('an expired negative entry is gone', $C::get(req('/missing')), null);

echo "\n";
if ($fail === 0) { printf("  PASS - %d case(s)\n\n", $pass); exit(0); }
printf("  FAIL - %d of %d\n\n", $fail, $pass + $fail);
exit(1);
