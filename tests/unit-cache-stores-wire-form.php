<?php

/**
 * What the cache stores must be exactly what goes on the wire.
 *
 * The key already distinguishes encoding variants, so the gzip entry and the
 * plain entry are separate rows. They were both holding uncompressed bytes, so
 * every hit on the gzip row compressed the page again to produce output
 * identical to the time before -- 4.19ms on a 250KB page, against 1.89ms of
 * measured CPU for an average request.
 *
 * Storing the compressed form is worth doing and easy to do wrong. The first
 * attempt compressed the body after put() had already taken a copy of the
 * headers, so an entry was written with the new body and the old headers: a
 * gzip payload with no Content-Encoding, which a browser renders as binary
 * rubbish. Every case below exists because of a way that went wrong.
 *
 *   php tests/unit-cache-stores-wire-form.php
 */

// The cache builds paths with DS, which the server defines at boot.
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
		var_export(is_string($got) ? substr($got, 0, 80) : $got, true),
		var_export(is_string($want) ? substr($want, 0, 80) : $want, true));
}

function headerOf($response, $name)
{
	foreach (($response['headers'] ?? array()) as $k => $v) {
		if (strcasecmp($k, $name) === 0) return $v;
	}
	return null;
}

$html = str_repeat("<p>a paragraph of text</p>\n", 400);
function html_response($body, $type = 'text/html; charset=utf-8')
{
	return array(
		'status' => 200,
		'headers' => array('Content-Type' => $type, 'Cache-Control' => 'public, max-age=300'),
		'body' => $body,
	);
}

// ── The body and the headers must describe each other ────────────────────
$r = $C::encodeBody(html_response($html), 'gzip, deflate, br');
check('the body is compressed', substr($r['body'], 0, 2), "\x1f\x8b");
check('and it says so', headerOf($r, 'Content-Encoding'), 'gzip');
check('and varies on it', headerOf($r, 'Vary'), 'Accept-Encoding');
check('and the length matches the body it describes',
	(int) headerOf($r, 'Content-Length'), strlen($r['body']));
check('the body still decodes to the original', gzdecode($r['body']), $html);

// The failure that shipped: a compressed body with the plain body's headers.
// If these two ever disagree, a browser gets binary.
$encoded = $C::encodeBody(html_response($html), 'gzip');
$declares = headerOf($encoded, 'Content-Encoding') === 'gzip';
$isGzip = substr($encoded['body'], 0, 2) === "\x1f\x8b";
check('body and header agree, both ways', $declares === $isGzip, true);

// ── When it must not compress ────────────────────────────────────────────
$r = $C::encodeBody(html_response($html), '');
check('no Accept-Encoding means no compression', substr($r['body'], 0, 2) === "\x1f\x8b", false);
check('and no header claiming otherwise', headerOf($r, 'Content-Encoding'), null);

$r = $C::encodeBody(html_response($html), 'deflate, br');
check('gzip not offered means no gzip', headerOf($r, 'Content-Encoding'), null);

$r = $C::encodeBody(html_response('tiny'), 'gzip');
check('a body below the threshold is left alone', $r['body'], 'tiny');

$r = $C::encodeBody(html_response(random_bytes(20000), 'image/png'), 'gzip');
check('an incompressible type is left alone', headerOf($r, 'Content-Encoding'), null);

// ── Already encoded ──────────────────────────────────────────────────────
$already = html_response(gzencode($html, 6));
$already['headers']['Content-Encoding'] = 'gzip';
$r = $C::encodeBody($already, 'gzip');
check('an already-encoded body is not compressed twice', $r['body'], $already['body']);
check('a lowercase content-encoding is also respected',
	$C::encodeBody(array(
		'status' => 200,
		'headers' => array('content-type' => 'text/html', 'content-encoding' => 'br'),
		'body' => $html,
	), 'gzip')['body'], $html);

// ── Idempotence ──────────────────────────────────────────────────────────
$once = $C::encodeBody(html_response($html), 'gzip');
$twice = $C::encodeBody($once, 'gzip');
check('encoding twice is the same as encoding once', $twice['body'], $once['body']);

// ── It survives being stored and read back ───────────────────────────────
$entry = $once + array('expires' => time() + 300, 'stored' => time());
$round = $C::decodeEntry($C::encodeEntry($entry));
check('the compressed body survives the entry format', $round['body'], $once['body']);
check('and so does the encoding header', headerOf($round, 'Content-Encoding'), 'gzip');
check('and it still decodes', gzdecode($round['body']), $html);

// ── Through put() and get(), which is where it actually broke ───────────
//
// The failure that shipped was not in encodeBody. put() took a copy of the
// headers, then the body was compressed, and the entry was written from the
// old headers and the new body. Only an end-to-end case catches that, so the
// cases above would have passed while the site served binary.

$dir = sys_get_temp_dir() . '/qbix-cache-test-' . getmypid();
@mkdir($dir, 0775, true);

$C::$enabled = true;
$C::$dir = $dir;
$C::$apcuEnabled = false;
$C::$skipCookies = array();

$request = array(
	'method' => 'GET',
	'path' => '/a-page',
	'query' => '',
	'headers' => array('host' => 'example.com', 'accept-encoding' => 'gzip, deflate, br'),
);

$C::put($request, html_response($html));
$got = $C::get($request);

if (!is_array($got)) {
	++$fail;
	echo "  FAIL  the entry did not come back from the cache at all\n";
} else {
	$enc = headerOf($got, 'Content-Encoding');
	$isGzip = substr($got['body'], 0, 2) === "\x1f\x8b";

	check('a stored entry comes back compressed', $isGzip, true);
	check('and declares the encoding it used', $enc, 'gzip');
	check('body and header agree end to end', $isGzip === ($enc === 'gzip'), true);
	check('the declared length matches the stored body',
		(int) headerOf($got, 'Content-Length'), strlen($got['body']));
	check('and it decodes to what was put in', gzdecode($got['body']), $html);
}

// A client that cannot take gzip must get an entry it can read. The key
// separates the variants, so this is a different row.
$plainRequest = $request;
$plainRequest['headers']['accept-encoding'] = '';
$C::put($plainRequest, html_response($html));
$plain = $C::get($plainRequest);
if (is_array($plain)) {
	check('the plain variant is stored uncompressed', $plain['body'], $html);
	check('and claims no encoding', headerOf($plain, 'Content-Encoding'), null);
}

foreach (glob($dir . '/*/*') as $f) @unlink($f);
foreach (glob($dir . '/*') as $d) @rmdir($d);
@rmdir($dir);

// ── It is actually cheaper ───────────────────────────────────────────────
$big = str_repeat("<div class='row'>text content here</div>\n", 6000);
$t = microtime(true);
for ($i = 0; $i < 20; $i++) gzencode($big, 6);
$compressEach = (microtime(true) - $t) / 20 * 1000;
printf("  (compressing this body costs %.2f ms, which a hit no longer pays)\n", $compressEach);
check('and that cost is worth removing', $compressEach > 0.1, true);

echo "\n";
if ($fail === 0) { printf("  PASS - %d case(s)\n\n", $pass); exit(0); }
printf("  FAIL - %d of %d\n\n", $fail, $pass + $fail);
exit(1);
