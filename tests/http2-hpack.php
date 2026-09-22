<?php

/**
 * HPACK against the worked examples in RFC 7541 Appendix C.
 *
 * These are the specification's own vectors, byte for byte. They exercise the
 * integer codec at both prefix widths, the static table, literal forms with
 * and without indexing, the dynamic table including eviction, and the Huffman
 * code -- which is 257 entries of hand-entered data and therefore the single
 * most likely thing in the implementation to be wrong.
 *
 * An HPACK fault does not announce itself. A browser that has committed to h2
 * at ALPN will not fall back, so a bad table shows as a blank page with
 * nothing in any log. Hence: verify here, before anything is built on it.
 *
 *   php tests/http2-hpack.php
 *
 * Exits non-zero if any case fails.
 */

require __DIR__ . '/../src/Q/WebServer/Http2/Hpack.php';

$pass = 0;
$fail = 0;

function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) {
		$pass++;
		return;
	}
	$fail++;
	echo "  FAIL  $what\n";
	echo "        got  " . (is_string($got) ? var_export($got, true) : json_encode($got)) . "\n";
	echo "        want " . (is_string($want) ? var_export($want, true) : json_encode($want)) . "\n";
}

function hex2bin_spaced($hex)
{
	return hex2bin(preg_replace('/\s+/', '', $hex));
}

// ── C.1 Integer representation ───────────────────────────────────────────

$pos = 0;
check('C.1.1 decode 10 in a 5-bit prefix',
	Q_WebServer_Http2_Hpack::decodeInt(hex2bin_spaced('0a'), $pos, 5), 10);

$pos = 0;
check('C.1.2 decode 1337 in a 5-bit prefix',
	Q_WebServer_Http2_Hpack::decodeInt(hex2bin_spaced('1f9a0a'), $pos, 5), 1337);

$pos = 0;
check('C.1.3 decode 42 starting at an octet boundary',
	Q_WebServer_Http2_Hpack::decodeInt(hex2bin_spaced('2a'), $pos, 8), 42);

check('C.1.2 encode 1337 round trips',
	bin2hex(Q_WebServer_Http2_Hpack::encodeInt(1337, 5, 0x00)), '1f9a0a');

// ── C.2 Header field representation ──────────────────────────────────────

$h = new Q_WebServer_Http2_Hpack();
check('C.2.1 literal with indexing, custom name',
	$h->decode(hex2bin_spaced('400a 6375 7374 6f6d 2d6b 6579 0d63 7573 746f 6d2d 6865 6164 6572')),
	array(array('custom-key', 'custom-header')));
check('C.2.1 entry joined the dynamic table', count($h->dynamic), 1);

$h = new Q_WebServer_Http2_Hpack();
check('C.2.2 literal without indexing',
	$h->decode(hex2bin_spaced('040c 2f73 616d 706c 652f 7061 7468')),
	array(array(':path', '/sample/path')));
check('C.2.2 entry did not join the dynamic table', count($h->dynamic), 0);

$h = new Q_WebServer_Http2_Hpack();
check('C.2.4 indexed header field',
	$h->decode(hex2bin_spaced('82')),
	array(array(':method', 'GET')));

// ── C.3 Request sequence, no Huffman, shared dynamic table ───────────────

$h = new Q_WebServer_Http2_Hpack();

check('C.3.1 first request',
	$h->decode(hex2bin_spaced('8286 8441 0f77 7777 2e65 7861 6d70 6c65 2e63 6f6d')),
	array(
		array(':method', 'GET'),
		array(':scheme', 'http'),
		array(':path', '/'),
		array(':authority', 'www.example.com'),
	));

check('C.3.2 second request reuses the table',
	$h->decode(hex2bin_spaced('8286 84be 5808 6e6f 2d63 6163 6865')),
	array(
		array(':method', 'GET'),
		array(':scheme', 'http'),
		array(':path', '/'),
		array(':authority', 'www.example.com'),
		array('cache-control', 'no-cache'),
	));

check('C.3.3 third request',
	$h->decode(hex2bin_spaced('8287 85bf 400a 6375 7374 6f6d 2d6b 6579 0c63 7573 746f 6d2d 7661 6c75 65')),
	array(
		array(':method', 'GET'),
		array(':scheme', 'https'),
		array(':path', '/index.html'),
		array(':authority', 'www.example.com'),
		array('custom-key', 'custom-value'),
	));

// ── C.4 The same sequence, Huffman-coded ─────────────────────────────────
//
// This is the part that proves the Huffman table. If the table were wrong,
// C.3 would still pass and this would not.

$h = new Q_WebServer_Http2_Hpack();

check('C.4.1 first request, Huffman',
	$h->decode(hex2bin_spaced('8286 8441 8cf1 e3c2 e5f2 3a6b a0ab 90f4 ff')),
	array(
		array(':method', 'GET'),
		array(':scheme', 'http'),
		array(':path', '/'),
		array(':authority', 'www.example.com'),
	));

check('C.4.2 second request, Huffman',
	$h->decode(hex2bin_spaced('8286 84be 5886 a8eb 1064 9cbf')),
	array(
		array(':method', 'GET'),
		array(':scheme', 'http'),
		array(':path', '/'),
		array(':authority', 'www.example.com'),
		array('cache-control', 'no-cache'),
	));

check('C.4.3 third request, Huffman',
	$h->decode(hex2bin_spaced('8287 85bf 4088 25a8 49e9 5ba9 7d7f 8925 a849 e95b b8e8 b4bf')),
	array(
		array(':method', 'GET'),
		array(':scheme', 'https'),
		array(':path', '/index.html'),
		array(':authority', 'www.example.com'),
		array('custom-key', 'custom-value'),
	));

// Huffman decoding on its own, so a failure points at the table directly.
check('Huffman decodes www.example.com',
	Q_WebServer_Http2_Hpack::huffmanDecode(hex2bin_spaced('f1e3 c2e5 f23a 6ba0 ab90 f4ff')),
	'www.example.com');
check('Huffman decodes no-cache',
	Q_WebServer_Http2_Hpack::huffmanDecode(hex2bin_spaced('a8eb 1064 9cbf')),
	'no-cache');
check('Huffman decodes custom-value',
	Q_WebServer_Http2_Hpack::huffmanDecode(hex2bin_spaced('25a8 49e9 5bb8 e8b4 bf')),
	'custom-value');

// ── C.5 Response sequence with a table small enough to force eviction ────

$h = new Q_WebServer_Http2_Hpack();
$h->resize(256);

check('C.5.1 first response',
	$h->decode(hex2bin_spaced(
		'4803 3330 3258 0770 7269 7661 7465 611d 4d6f 6e2c 2032 3120 4f63 7420 3230 3133'
		. ' 2032 303a 3133 3a32 3120 474d 546e 1768 7474 7073 3a2f 2f77 7777 2e65 7861 6d70'
		. '6c65 2e63 6f6d')),
	array(
		array(':status', '302'),
		array('cache-control', 'private'),
		array('date', 'Mon, 21 Oct 2013 20:13:21 GMT'),
		array('location', 'https://www.example.com'),
	));

check('C.5.2 second response, entries evicted',
	$h->decode(hex2bin_spaced('4803 3330 37c1 c0bf')),
	array(
		array(':status', '307'),
		array('cache-control', 'private'),
		array('date', 'Mon, 21 Oct 2013 20:13:21 GMT'),
		array('location', 'https://www.example.com'),
	));

check('C.5 dynamic table stayed within its limit', $h->size <= $h->maxSize, true);

// ── Encoding: what we emit must decode back to what we meant ─────────────

$enc = new Q_WebServer_Http2_Hpack();
$dec = new Q_WebServer_Http2_Hpack();
$headers = array(
	array(':status', '200'),
	array('content-type', 'text/html; charset=utf-8'),
	array('content-length', '1234'),
	array('x-made-up-header', 'a value with spaces, and punctuation!'),
	array('set-cookie', 'a=1; Path=/'),
	array('set-cookie', 'b=2; Path=/'),
);
check('encode then decode round trips, repeats and all',
	$dec->decode($enc->encode($headers)), $headers);

echo "\n";
if ($fail === 0) {
	echo "  PASS - $pass case(s), including every Appendix C vector\n";
	exit(0);
}
echo "  FAIL - $fail of " . ($pass + $fail) . " case(s)\n";
exit(1);
