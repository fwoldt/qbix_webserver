<?php

/**
 * A visitor who already holds the page should be told so, not sent it again.
 *
 * Static files have always answered a conditional request with 304. Cached
 * pages never did, so a reload of a page the browser already had still carried
 * the whole document -- observed on a live site at 9.73 kB transferred against
 * a Last-Modified identical to the one just sent. The browser said it had the
 * page, in the request, and was sent it anyway.
 *
 * This is what keeps a reload fast rather than merely making the first view
 * fast. The body is the part that scales with the page; once it is gone, what
 * remains is a round trip and a couple of hundred bytes, whatever the page
 * weighs.
 *
 * Getting it wrong in the other direction is worse than not doing it: a 304
 * sent to someone holding something stale shows them the stale thing, and no
 * amount of reloading will fix it, because every reload is answered 304 again.
 * Most of the cases below are about not doing that.
 *
 *   php tests/unit-cache-revalidation.php
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

$modified = 'Tue, 22 Sep 2026 20:33:20 GMT';
$older    = 'Tue, 22 Sep 2026 19:00:00 GMT';
$newer    = 'Tue, 22 Sep 2026 21:00:00 GMT';

function entry($extra = array())
{
	global $modified;
	return array(
		'status' => 200,
		'headers' => array(
			'Content-Type' => 'text/html; charset=utf-8',
			'Cache-Control' => 'public, max-age=300',
			'Last-Modified' => $modified,
			'ETag' => '"abc123"',
			'Content-Length' => '9367',
			'Content-Encoding' => 'gzip',
		) + $extra,
		'body' => 'the whole page, nine kilobytes of it',
	);
}

function status($result) { return $result === null ? 200 : $result['status']; }
function header_of($r, $n)
{
	foreach (($r['headers'] ?? array()) as $k => $v) {
		if (strcasecmp($k, $n) === 0) return $v;
	}
	return null;
}

// ── When it must answer 304 ──────────────────────────────────────────────
$r = $C::notModified(entry(), array('if-modified-since' => $modified));
check('an identical If-Modified-Since is not modified', status($r), 304);
check('and carries no body', $r['body'], '');

$r = $C::notModified(entry(), array('if-modified-since' => $newer));
check('a client holding something newer is not modified', status($r), 304);

$r = $C::notModified(entry(), array('if-none-match' => '"abc123"'));
check('a matching ETag is not modified', status($r), 304);

$r = $C::notModified(entry(), array('if-none-match' => 'W/"abc123"'));
check('a weak ETag matches the same representation', status($r), 304);

$r = $C::notModified(entry(), array('if-none-match' => '"x", "abc123", "y"'));
check('one of several ETags matching is enough', status($r), 304);

$r = $C::notModified(entry(), array('if-none-match' => '*'));
check('a wildcard matches anything we hold', status($r), 304);

// ── When it must not ─────────────────────────────────────────────────────
check('no conditional headers means send the page',
	$C::notModified(entry(), array()), null);

check('a client holding something older must get the page',
	$C::notModified(entry(), array('if-modified-since' => $older)), null);

check('a different ETag must get the page',
	$C::notModified(entry(), array('if-none-match' => '"different"')), null);

// If-None-Match is the stronger validator and decides alone. A client with a
// stale ETag and a coincidentally recent date must not be told 304.
check('a failed ETag wins over a passing date',
	$C::notModified(entry(), array(
		'if-none-match' => '"stale"',
		'if-modified-since' => $newer,
	)), null);

check('an unparseable date is not a match',
	$C::notModified(entry(), array('if-modified-since' => 'not a date')), null);

check('an empty conditional header is not a match',
	$C::notModified(entry(), array('if-none-match' => '')), null);

// An entry with no validators cannot answer a conditional request at all.
$noValidators = entry();
unset($noValidators['headers']['Last-Modified'], $noValidators['headers']['ETag']);
check('an entry with no validators always sends the page',
	$C::notModified($noValidators, array('if-modified-since' => $modified)), null);

// ── What a 304 may and may not carry ─────────────────────────────────────
// Sending Content-Length or Content-Encoding with an empty body tells the
// client to expect bytes that never come.
$r = $C::notModified(entry(), array('if-none-match' => '"abc123"'));
check('a 304 keeps the validator', header_of($r, 'ETag'), '"abc123"');
check('and Last-Modified', header_of($r, 'Last-Modified'), $modified);
check('and the caching policy', header_of($r, 'Cache-Control'), 'public, max-age=300');
check('but not Content-Length', header_of($r, 'Content-Length'), null);
check('nor Content-Encoding', header_of($r, 'Content-Encoding'), null);
check('nor Content-Type', header_of($r, 'Content-Type'), null);

// ── Rubbish ──────────────────────────────────────────────────────────────
check('a non-entry is never fresh', $C::notModified(null, array('if-none-match' => '*')), null);
check('an entry without headers is never fresh',
	$C::notModified(array('status' => 200, 'body' => 'x'), array('if-none-match' => '*')), null);

// ── The validator itself ─────────────────────────────────────────────────
// Last-Modified is the moment the entry was stored, not the moment the page
// changed. A cache rebuilt on a timer moves it every lifetime, so without a
// content-derived tag an unchanged page costs full price once per lifetime.
$page = '<html>a page that nobody has edited</html>';

$h1 = $C::withEntityTag(array('Content-Type' => 'text/html'), $page);
$h2 = $C::withEntityTag(array('Content-Type' => 'text/html'), $page);
check('the same body yields the same tag', $h1['ETag'], $h2['ETag']);
check('and it is a quoted strong tag',
	(bool) preg_match('/^"[A-Za-z0-9-]+"$/', $h1['ETag']), true);

$h3 = $C::withEntityTag(array('Content-Type' => 'text/html'), $page . ' edited');
check('a changed body yields a different tag', $h1['ETag'] === $h3['ETag'], false);

// This is the whole point: rebuild the entry, keep the content, still 304.
$rebuilt = array(
	'status' => 200,
	'headers' => array(
		'Last-Modified' => 'Tue, 22 Sep 2026 21:15:00 GMT', // moved on
	) + $h1,
	'body' => $page,
);
$r = $C::notModified($rebuilt, array(
	'if-none-match' => $h1['ETag'],
	'if-modified-since' => $modified,      // older than the rebuild
));
check('a rebuilt but unchanged page is still not modified', status($r), 304);

// The same bytes gzipped are a different representation and must not be
// validated against the identity tag, or a client gets gzip it cannot read.
$gz = $C::withEntityTag(
	array('Content-Type' => 'text/html', 'Content-Encoding' => 'gzip'), $page);
check('a coded representation gets its own tag', $gz['ETag'] === $h1['ETag'], false);
check('an identity tag does not validate the gzip form',
	$C::notModified(
		array('status' => 200, 'headers' => $gz, 'body' => 'z'),
		array('if-none-match' => $h1['ETag'])
	), null);

// An application that set its own tag knows something we do not.
$own = $C::withEntityTag(array('ETag' => '"chosen-by-the-app"'), $page);
check('an existing tag is left alone', $own['ETag'], '"chosen-by-the-app"');

$empty = $C::withEntityTag(array('ETag' => ''), $page);
check('but an empty one is replaced', $empty['ETag'] === '', false);

check('rubbish headers do not crash it', $C::withEntityTag(null, $page), array());

// ── What it saves ────────────────────────────────────────────────────────
$full = entry();
$r = $C::notModified($full, array('if-none-match' => '"abc123"'));
$saved = strlen($full['body']);
check('a reload sends no body at all', strlen($r['body']), 0);
printf("  (a reload of this entry sends %d bytes of body instead of %d)\n", 0, $saved);

echo "\n";
if ($fail === 0) { printf("  PASS - %d case(s)\n\n", $pass); exit(0); }
printf("  FAIL - %d of %d\n\n", $fail, $pass + $fail);
exit(1);
