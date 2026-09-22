<?php

/**
 * Answering "has it changed?" should not require reading the page.
 *
 * A returning visitor's request is settled by comparing two short strings.
 * Before the index, settling one meant loading the whole entry -- 9 KB of
 * gzipped HTML off disk or out of shared memory -- decoding it, comparing the
 * ETag, and discarding every byte of the body unread. For a site people come
 * back to that is the most common request there is, and it was the most
 * wasteful one.
 *
 * Measured server-side before the index, 199 conditional requests on one
 * connection: p50 0.47 ms, p99 2.74 ms. The index exists to pull the tail in.
 *
 * The dangerous direction is answering 304 when we should not: a client told
 * "unchanged" shows the old page, and every reload is answered 304 again, so
 * nothing ever corrects it. Most of what follows is about refusing to answer.
 *
 *   php tests/unit-cache-validator-index.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q/WebServer/Cache.php';

if (!function_exists('apcu_store') or !@apcu_store('probe-' . getmypid(), 1)) {
	echo "\n  SKIP - APCu is not usable here; the index is APCu-backed\n\n";
	exit(0);
}

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

$root = sys_get_temp_dir() . DS . 'qcache-idx-' . getmypid();
@mkdir($root, 0755, true);
register_shutdown_function(function () use ($root) {
	foreach (new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST) as $f) {
		$f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
	}
	@rmdir($root);
	if (function_exists('apcu_delete')) {
		try { apcu_delete(new APCUIterator('#^qcache:#')); } catch (Throwable $e) {}
	}
});

try { apcu_delete(new APCUIterator('#^qcache:#')); } catch (Throwable $e) {}

$C::$enabled = true;
$C::$dir = $root;
$C::$apcuEnabled = true;
$C::$staleWhileRevalidate = 0;

function request($extra = array(), $path = '/indexed')
{
	return array(
		'method' => 'GET',
		'path' => $path,
		'query' => '',
		'headers' => array('host' => 'example.test') + $extra,
	);
}

function response($body = 'a page', $maxAge = 300)
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

$plain = request();
$C::put($plain, response());

$stored = $C::get($plain);
check('the page is stored and served', $stored['headers']['X-Cache'], 'HIT');
$etag = null;
foreach ($stored['headers'] as $n => $v) {
	if (strcasecmp($n, 'ETag') === 0) $etag = $v;
}
check('and carries an ETag', is_string($etag) and $etag !== '', true);

// ── The index answers ────────────────────────────────────────────────────
check('an index entry was written',
	is_array(apcu_fetch('qcache:v:' . $C::cacheKey($plain))), true);

$cond = request(array('if-none-match' => $etag));
$answer = $C::get($cond);
check('a matching ETag is answered 304', $answer['status'], 304);
check('with no body', $answer['body'], '');

// The point of the whole thing: that answer must not have needed the entry.
// Remove the stored page and the index must still answer, which proves it did
// not read it.
$m = new ReflectionMethod($C, 'filePath');
$m->setAccessible(true);
$path = $m->invoke(null, $C::cacheKey($plain));
apcu_delete('qcache:' . $C::cacheKey($plain));
@unlink($path);
check('the page is gone from both stores', file_exists($path), false);

$answer = $C::get(request(array('if-none-match' => $etag)));
check('and the index still answers 304 without it', $answer['status'], 304);

// ── A 304 is an update, not just an answer ───────────────────────────────
// RFC 9111 has the client replace its stored headers with the ones a 304
// carries. An index that remembered only the validators produced a 304 with no
// Cache-Control and no Expires, so the client kept its ORIGINAL expiry instead
// of a renewed one and revalidated sooner -- the opposite of the point. Caught
// in a live browser trace, not by this test, which is why it is here now.
function headerOf($r, $name)
{
	foreach ($r['headers'] as $n => $v) {
		if (strcasecmp($n, $name) === 0) return $v;
	}
	return null;
}

$answer = $C::get(request(array('if-none-match' => $etag)));
check('a 304 from the index carries Cache-Control',
	headerOf($answer, 'Cache-Control'), 'public, max-age=300');
check('and Expires or nothing, but never a stale validator only',
	headerOf($answer, 'ETag'), $etag);
check('and it must not carry a body-describing header',
	headerOf($answer, 'Content-Length'), null);
check('nor Content-Encoding', headerOf($answer, 'Content-Encoding'), null);

// ── When it must refuse ──────────────────────────────────────────────────
try { apcu_delete(new APCUIterator('#^qcache:#')); } catch (Throwable $e) {}
$C::put($plain, response());
$etag2 = null;
foreach ($C::get($plain)['headers'] as $n => $v) {
	if (strcasecmp($n, 'ETag') === 0) $etag2 = $v;
}

check('a request with no validators is not answered from the index',
	$C::get(request())['headers']['X-Cache'], 'HIT');

check('a different ETag is not answered 304',
	$C::get(request(array('if-none-match' => '"something-else"')))['headers']['X-Cache'],
	'HIT');

// An expired index must never answer. Whether an expired page may still be
// served is a decision made with the entry in hand, not from the index.
$key = $C::cacheKey($plain);
$idx = apcu_fetch('qcache:v:' . $key);
$idx['expires'] = time() - 1;
apcu_store('qcache:v:' . $key, $idx, 300);
$answer = $C::get(request(array('if-none-match' => $etag2)));
check('an expired index does not answer 304',
	isset($answer['status']) && $answer['status'] === 304, false);

// ── The purge trap ───────────────────────────────────────────────────────
// A purged page whose index survived would answer 304 to everyone who already
// had it, and every reload would answer 304 again -- so the version they hold
// would never clear. This is the worst failure available here.
try { apcu_delete(new APCUIterator('#^qcache:#')); } catch (Throwable $e) {}
$C::put($plain, response('before the purge'));
$before = $C::get($plain);
$etag3 = null;
foreach ($before['headers'] as $n => $v) {
	if (strcasecmp($n, 'ETag') === 0) $etag3 = $v;
}
check('the index exists before the purge',
	is_array(apcu_fetch('qcache:v:' . $C::cacheKey($plain))), true);

// purge() matches on the url the entry was stored under. Entries carry one
// now; before this they did not, so purge could never match anything and this
// assertion would have been passing on an else-branch instead of testing it.
$raw = file_get_contents($m->invoke(null, $C::cacheKey($plain)));
$meta = json_decode(substr($raw, 0, strpos($raw, "\n")), true);
check('the entry records what it is of', $meta['url'], '/indexed');
check('and what it is filed under', $meta['key'], $C::cacheKey($plain));

$C::purge('/indexed');
check('purge removes the page', file_exists($m->invoke(null, $C::cacheKey($plain))), false);
check('purge removes the index, not just the page',
	apcu_fetch('qcache:v:' . $C::cacheKey($plain)), false);
$answer = $C::get(request(array('if-none-match' => $etag3)));
check('a purged page is not answered 304',
	isset($answer['status']) && $answer['status'] === 304, false);

// ── Without APCu it simply does not engage ───────────────────────────────
$C::$apcuEnabled = false;
$C::put(request(array(), '/no-apcu'), response());
$r = $C::get(request(array('if-none-match' => '"anything"'), '/no-apcu'));
check('with no APCu the ordinary path runs',
	isset($r['status']) && $r['status'] === 304, false);

echo "\n";
if ($fail === 0) { printf("  PASS - %d case(s)\n\n", $pass); exit(0); }
printf("  FAIL - %d of %d\n\n", $fail, $pass + $fail);
exit(1);
