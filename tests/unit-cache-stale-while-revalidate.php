<?php

/**
 * An expiry must not stop the page for everyone at once.
 *
 * A cached entry expires at a moment, not gradually. Every request in flight
 * for that page misses simultaneously, and before this each of them rendered
 * it. Measured on a live installation on 2026-09-22:
 *
 *   cache hit   0.6 ms
 *   render      1382 ms
 *
 * With max-age=300 that is a 1.4 second stall every five minutes, paid by
 * everyone who arrives during it, all of them doing identical work to produce
 * identical bytes. It showed up in the load test as p99 111 ms at concurrency
 * 8 and 182 ms at 16 -- a tail that grows with the number of people present,
 * which is the signature of a stampede rather than of load.
 *
 * So exactly one request renders, and the others are handed the copy that
 * already exists. What has to hold:
 *
 *   - only one request is ever told to render
 *   - the others get the old page immediately, not an error and not a wait
 *   - the old page is not served past the grace window
 *   - a render that dies does not freeze the page at its last version
 *   - none of this happens at all unless it is configured
 *
 *   php tests/unit-cache-stale-while-revalidate.php
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

$root = sys_get_temp_dir() . DS . 'qcache-swr-' . getmypid();
@mkdir($root, 0755, true);
register_shutdown_function(function () use ($root) {
	foreach (glob($root . '/*') ?: array() as $f) {
		is_dir($f) ? @rmdir($f) : @unlink($f);
	}
	@rmdir($root);
});

$C::$enabled = true;
$C::$dir = $root;
$C::$apcuEnabled = false;
$C::$staleWhileRevalidate = 60;
$C::$revalidateLockSeconds = 30;

$key = 'a-page';

// ── Only one renders ─────────────────────────────────────────────────────
check('the first request to arrive renders', $C::claimRevalidation($key), true);
check('the second is told not to', $C::claimRevalidation($key), false);
check('nor the third', $C::claimRevalidation($key), false);
check('nor the tenth', $C::claimRevalidation($key), false);

// ── And hands it back ────────────────────────────────────────────────────
$C::releaseRevalidation($key);
check('once released, the next request may render',
	$C::claimRevalidation($key), true);
check('and it is again the only one', $C::claimRevalidation($key), false);

// Releasing something nobody holds is not an error.
$C::releaseRevalidation('never-claimed');
check('releasing an unheld key is harmless', true, true);

// Two keys do not interfere.
check('a different page is claimed independently',
	$C::claimRevalidation('another-page'), true);
$C::releaseRevalidation('another-page');
$C::releaseRevalidation($key);

// ── A render that died ───────────────────────────────────────────────────
// The page must not stay frozen because a worker was killed mid-render.
check('a claim is taken', $C::claimRevalidation($key), true);
$lock = null;
foreach (glob($root . '/*') ?: array() as $f) {
	if (is_dir($f) and substr($f, -8) === '.refresh') $lock = $f;
}
if ($lock === null) {
	// filePath() may nest; search harder.
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
	foreach ($it as $f) { /* files only; the lock is a dir */ }
	foreach (glob($root . '/*/*') ?: array() as $f) {
		if (is_dir($f) and substr($f, -8) === '.refresh') $lock = $f;
	}
}
check('the claim exists on disk', is_dir((string) $lock), true);

touch($lock, time() - 31);            // as if the renderer died 31s ago
check('a claim older than the timeout may be taken',
	$C::claimRevalidation($key), true);
check('and taking it re-dates it, so the next one waits',
	$C::claimRevalidation($key), false);
$C::releaseRevalidation($key);

// A recent claim is left alone. Re-made through the real path, not by
// touching the file back into existence -- the claim is a directory, and a
// file of the same name would wedge every later claim until the timeout.
check('a claim can be re-made after release', $C::claimRevalidation($key), true);
check('a recent claim is not taken', $C::claimRevalidation($key), false);
$C::releaseRevalidation($key);
check('and the path is clear afterwards', file_exists((string) $lock), false);

// ── Through the real path ────────────────────────────────────────────────
// These drive get() and put(), not the decision helper underneath them. The
// first version of this test exercised the helper and passed while the live
// server still stampeded 8 requests out of 8: the helper was right and get()
// threw the stale copy away immediately after consulting it. A test that
// cannot see that is not testing the behaviour anyone cares about.

function request($path = '/a-page')
{
	return array(
		'method' => 'GET',
		'path' => $path,
		'query' => '',
		'headers' => array('host' => 'example.test'),
	);
}

function response($body, $maxAge = 300)
{
	return array(
		'status' => 200,
		'headers' => array(
			'Content-Type' => 'text/html',
			'Cache-Control' => 'public, max-age=' . $maxAge,
		),
		'body' => $body,
	);
}

function ageStored($C, $parsed, $secondsPast)
{
	// Reach into the store and set the entry's expiry into the past, which is
	// exactly the state a natural expiry leaves behind.
	$m = new ReflectionMethod($C, 'filePath');
	$m->setAccessible(true);
	$path = $m->invoke(null, $C::cacheKey($parsed));
	$raw = file_get_contents($path);
	$nl = strpos($raw, "\n");
	$meta = json_decode(substr($raw, 0, $nl), true);
	$meta['expires'] = time() - $secondsPast;
	file_put_contents($path, json_encode($meta) . "\n" . substr($raw, $nl + 1));
}

$C::$staleWhileRevalidate = 60;
$req = request();

$C::put($req, response('version one'));
$hit = $C::get($req);
check('a fresh entry is a hit', $hit['headers']['X-Cache'], 'HIT');
check('and carries what was stored', $hit['body'], 'version one');

ageStored($C, $req, 5);

// The first request through is the one that renders: it gets a miss, so the
// server goes and produces a new page.
check('the first request after expiry is told to render', $C::get($req), null);

// Everyone arriving behind it gets the page that already exists, immediately.
$second = $C::get($req);
check('the second is served the existing copy', is_array($second), true);
check('marked stale rather than passed off as fresh',
	$second['headers']['X-Cache'], 'STALE');
check('with the body intact', $second['body'], 'version one');
check('and an Age saying how old it really is',
	isset($second['headers']['Age']), true);

$third = $C::get($req);
check('and so is the third', $third['headers']['X-Cache'], 'STALE');

// This is the regression. The renderer must not delete the copy the others
// are being served.
$m = new ReflectionMethod($C, 'filePath');
$m->setAccessible(true);
check('the stale copy still exists on disk',
	file_exists($m->invoke(null, $C::cacheKey($req))), true);

// When the render finishes, everyone is back on the fresh page.
$C::put($req, response('version two'));
$after = $C::get($req);
check('once rendered, the new page is served', $after['body'], 'version two');
check('as a hit', $after['headers']['X-Cache'], 'HIT');

// ── Past the window ──────────────────────────────────────────────────────
ageStored($C, $req, 61);
check('past the grace window it is a plain miss', $C::get($req), null);
check('and the worthless copy is removed',
	file_exists($m->invoke(null, $C::cacheKey($req))), false);

// ── Off by default ───────────────────────────────────────────────────────
// An installation that configures nothing must behave exactly as before.
$C::$staleWhileRevalidate = 0;
$req2 = request('/another-page');
$C::put($req2, response('only version'));
ageStored($C, $req2, 5);
check('with no grace configured, an expired entry is a miss',
	$C::get($req2), null);
check('and is removed as before',
	file_exists($m->invoke(null, $C::cacheKey($req2))), false);

// ── An uncacheable render gives the claim back ───────────────────────────
// Otherwise the page it was refreshing stays stale until the claim ages out.
$C::$staleWhileRevalidate = 60;
$req3 = request('/third-page');
$C::put($req3, response('cacheable'));
ageStored($C, $req3, 5);
check('the renderer is chosen', $C::get($req3), null);
$C::put($req3, array('status' => 500, 'headers' => array(), 'body' => 'boom'));
check('after an uncacheable render, the next request may render again',
	$C::claimRevalidation($C::cacheKey($req3)), true);
$C::releaseRevalidation($C::cacheKey($req3));

echo "\n";
if ($fail === 0) { printf("  PASS - %d case(s)\n\n", $pass); exit(0); }
printf("  FAIL - %d of %d\n\n", $fail, $pass + $fail);
exit(1);
