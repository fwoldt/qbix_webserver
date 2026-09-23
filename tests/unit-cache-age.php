<?php

/**
 * A reused response has to say how old it is.
 *
 * RFC 9111 section 5.1: "A cache MUST generate this field when using a stored
 * response." The requirement is not a formality. Age is how a client, and every
 * cache between here and it, works out how much of a response's freshness
 * lifetime is left.
 *
 * Without it, a response already 290 seconds into a 300 second lifetime looks
 * newly generated, so the next cache downstream holds it for a further 300 --
 * and the lifetime multiplies at every hop instead of running out. A page
 * configured to be at most five minutes stale can be a quarter of an hour old
 * by the time it reaches somebody through a CDN.
 *
 * This cache emitted Age only on a stale response, and not on an ordinary hit,
 * which is the common case by a long way.
 *
 *   php tests/unit-cache-age.php
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

function header_of($r, $name)
{
	foreach (($r['headers'] ?? array()) as $k => $v) {
		if (strcasecmp($k, $name) === 0) return $v;
	}
	return null;
}

$root = sys_get_temp_dir() . DS . 'qcache-age-' . getmypid();
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
$C::$middleOut = false;

function req($path = '/aged')
{
	return array('method' => 'GET', 'path' => $path, 'query' => '',
		'headers' => array('host' => 'example.test'));
}

function resp($maxAge = 300)
{
	return array('status' => 200,
		'headers' => array('Content-Type' => 'text/html',
			'Cache-Control' => 'public, max-age=' . $maxAge),
		'body' => 'a page');
}

// ── The computation ──────────────────────────────────────────────────────
check('an entry stored now is zero seconds old',
	$C::age(array('stored' => time())), '0');
check('one stored a minute ago is sixty',
	$C::age(array('stored' => time() - 60)), '60');
check('an entry with no stored time reports zero rather than nonsense',
	$C::age(array()), '0');
check('and a clock that has gone backwards never yields a negative age',
	$C::age(array('stored' => time() + 30)), '0');

// ── On an ordinary hit, which is the case that was missing ───────────────
$C::put(req(), resp());
$hit = $C::get(req());
check('a hit reports X-Cache', header_of($hit, 'X-Cache'), 'HIT');
check('and carries an Age', header_of($hit, 'Age') !== null, true);
check('which is a number', ctype_digit((string) header_of($hit, 'Age')), true);
check('and is small for an entry just stored',
	(int) header_of($hit, 'Age') <= 1, true);

// Age must track the entry, not the request.
$m = new ReflectionMethod($C, 'filePath');
$m->setAccessible(true);
$path = $m->invoke(null, $C::cacheKey(req()));
$raw = file_get_contents($path);
$nl = strpos($raw, "\n");
$meta = json_decode(substr($raw, 0, $nl), true);
$meta['stored'] = time() - 120;
file_put_contents($path, json_encode($meta) . "\n" . substr($raw, $nl + 1));

$older = $C::get(req());
check('an entry stored two minutes ago says so',
	(int) header_of($older, 'Age') >= 119, true);
check('and is still a hit', header_of($older, 'X-Cache'), 'HIT');

// ── On a stale response ──────────────────────────────────────────────────
$C::$staleWhileRevalidate = 60;
$meta['expires'] = time() - 5;
file_put_contents($path, json_encode($meta) . "\n" . substr($raw, $nl + 1));

$C::claimRevalidation($C::cacheKey(req()));   // somebody else is rendering
$stale = $C::get(req());
check('a stale response is labelled stale',
	header_of($stale, 'X-Cache'), 'STALE');
check('and reports its real age, not a reset one',
	(int) header_of($stale, 'Age') >= 119, true);
$C::releaseRevalidation($C::cacheKey(req()));

// ── On a 304 ─────────────────────────────────────────────────────────────
// A 304 updates the client's stored headers just as a 200 does, so omitting
// Age there leaves the client believing its copy was revalidated against a
// freshly generated response.
$entry = array('status' => 200, 'headers' => array(
	'ETag' => '"abc"', 'Cache-Control' => 'public, max-age=300',
	'Age' => '240', 'Content-Length' => '900',
), 'body' => 'x');
$notModified = $C::notModified($entry, array('if-none-match' => '"abc"'));
check('a 304 is produced', $notModified['status'], 304);
check('and carries the Age through', header_of($notModified, 'Age'), '240');
check('while still dropping body-describing headers',
	header_of($notModified, 'Content-Length'), null);

// ── The consequence, stated as a test ────────────────────────────────────
// This is what the header is for: remaining lifetime is max-age minus Age.
$maxAge = 300;
$age = (int) header_of($older, 'Age');
check('a downstream cache can compute the remaining lifetime',
	$maxAge - $age < $maxAge, true);
printf("  (a downstream cache sees %ds of a %ds lifetime left, not %ds)\n",
	$maxAge - $age, $maxAge, $maxAge);

echo "\n";
if ($fail === 0) { printf("  PASS - %d case(s)\n\n", $pass); exit(0); }
printf("  FAIL - %d of %d\n\n", $fail, $pass + $fail);
exit(1);
