<?php

/**
 * How a cached response is written and read back.
 *
 * The body used to be stored inside the JSON, so every hit ran json_decode over
 * a document containing a whole page -- unescaping every byte to hand back a
 * string that had been a string all along. On a 250KB page that was 1.108ms
 * against 0.049ms to read the file: 96% of the cost of a hit was undoing an
 * encoding that need never have happened.
 *
 * It is now a line of JSON, a newline, then the bytes. That only works if the
 * metadata can never contain a newline, and if entries written by the older
 * version still read -- an installation upgrading in place has a cache full of
 * them, and discarding it would mean every page rendering once more for nothing.
 *
 *   php tests/unit-cache-entry-format.php
 */

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
		var_export(is_string($got) ? substr($got, 0, 120) : $got, true),
		var_export(is_string($want) ? substr($want, 0, 120) : $want, true));
}

function entry($body, array $headers = array())
{
	return array(
		'status' => 200,
		'headers' => $headers + array('content-type' => 'text/html; charset=utf-8'),
		'body' => $body,
		'expires' => 1790000000,
		'stored' => 1789999700,
	);
}

// ── Round trip ───────────────────────────────────────────────────────────
$e = entry('<html>hello</html>');
$back = $C::decodeEntry($C::encodeEntry($e));
check('the body survives', $back['body'], $e['body']);
check('the status survives', $back['status'], 200);
check('the headers survive', $back['headers'], $e['headers']);
check('the expiry survives', $back['expires'], $e['expires']);

// ── Bodies that would break a line-based format ──────────────────────────
$awkward = array(
	'newlines' => "line one\nline two\nline three\n",
	'crlf' => "a\r\nb\r\n",
	'a leading newline' => "\nstarts with one",
	'nothing at all' => '',
	'a single newline' => "\n",
	'null bytes' => "before\x00after",
	'utf-8' => "héllo wörld — ünïcödé ✓",
	'a json document as the body' => '{"v":2,"body":"not really"}',
	'the separator repeated' => str_repeat("x\n", 1000),
	'binary' => random_bytes(4096),
	'large' => str_repeat('abcdefgh', 40000),
);
foreach ($awkward as $what => $body) {
	$back = $C::decodeEntry($C::encodeEntry(entry($body)));
	check("a body of $what survives exactly", $back['body'], $body);
}

// ── Metadata that must not break the separator ───────────────────────────
// json_encode escapes newlines, so a header containing one cannot split the
// line. If it could, the body would start in the wrong place.
$e = entry('body', array('x-odd' => "has\na newline", 'x-quote' => 'say "hi"'));
$encoded = $C::encodeEntry($e);
check('the metadata occupies exactly one line',
	substr_count(substr($encoded, 0, strpos($encoded, "\n")), "\n"), 0);
$back = $C::decodeEntry($encoded);
check('a header containing a newline survives', $back['headers']['x-odd'], "has\na newline");
check('and the body is still correct', $back['body'], 'body');

// ── Entries written by the older version ─────────────────────────────────
// A cache full of these exists on any installation upgrading in place.
$old = json_encode(entry('<html>old format</html>'));
$back = $C::decodeEntry($old);
check('an old entry still reads', $back['body'], '<html>old format</html>');
check('with its status', $back['status'], 200);
check('and its expiry', $back['expires'], 1790000000);

// ── Rubbish ──────────────────────────────────────────────────────────────
check('an empty file decodes to nothing', $C::decodeEntry(''), null);
check('a truncated entry decodes to nothing', $C::decodeEntry('{"v":2,"stat'), null);
check('random bytes decode to nothing', $C::decodeEntry("\x00\x01\x02"), null);
check('a bare newline decodes to nothing', $C::decodeEntry("\n"), null);

// ── The point of the exercise ────────────────────────────────────────────
// Decoding must not depend on the size of the body.
$small = $C::encodeEntry(entry(str_repeat('x', 100)));
$big = $C::encodeEntry(entry(str_repeat('x', 500000)));

$t = microtime(true);
for ($i = 0; $i < 200; $i++) $C::decodeEntry($small);
$tSmall = microtime(true) - $t;

$t = microtime(true);
for ($i = 0; $i < 200; $i++) $C::decodeEntry($big);
$tBig = microtime(true) - $t;

// A 5000x larger body should not cost 5000x to decode. Allow a wide margin --
// substr still copies -- but the old shape was linear in the body's length
// with a large constant, and this must not be.
$ratio = $tSmall > 0 ? $tBig / $tSmall : 0;
check('decoding a 5000x larger body costs well under 100x', $ratio < 100, true);
printf("  (500KB body decodes %.1fx slower than a 100 byte one)\n", $ratio);

echo "\n";
if ($fail === 0) { printf("  PASS - %d case(s)\n\n", $pass); exit(0); }
printf("  FAIL - %d of %d\n\n", $fail, $pass + $fail);
exit(1);
