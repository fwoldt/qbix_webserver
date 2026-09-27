<?php

/**
 * A static file is served whole whatever the client says it can decode,
 * with the on-disk precompress cache turned on.
 *
 * With Q.webserver.precompress.enabled, a compressible static file that was
 * not yet in the in-memory file cache went to Q_WebServer_Precompress::serve().
 * That returns null whenever it does not apply: the client does not take gzip
 * (Accept-Encoding: identity, or br alone, or gzip;q=0), the file is below
 * precompress.minSize, or gzip would not make it smaller. The null was used as
 * the body, so the answer was a 200 with Content-Length: 0 -- an empty
 * stylesheet or script for exactly those clients, while a browser, which asks
 * for gzip, saw nothing wrong. A request without Accept-Encoding went down the
 * uncompressed path and put the file in the memory cache under the same key
 * as identity, which is why the same URL could come back whole on one worker
 * and empty on another.
 *
 * Asserted against a running server, one worker, precompress on:
 *   - identity, br only, gzip;q=0 and no Accept-Encoding get the whole file;
 *   - gzip gets gzip, br gets br when the brotli extension is loaded, and both
 *     decode to the file;
 *   - a file below precompress.minSize and one gzip cannot shrink come back
 *     whole to a gzip client;
 *   - HEAD declares the length and coding of the body a GET would carry,
 *     also for a file over 1 MB, whose GET a child streams as it is on disk;
 *   - If-None-Match with the file's ETag still answers 304;
 *   - an identity request after a gzip one still gets the file uncompressed.
 *
 *   php tests/unit-static-precompress-fallback.php
 */

require __DIR__ . '/fixtures/race-harness.php';

list($base, $root) = rh_setup('static-precompress');

// Compressible text, well above every threshold.
$css = '';
for ($i = 0; $i < 200; ++$i) $css .= ".rule-$i { color: #" . sprintf('%06x', $i * 997) . "; margin: {$i}px; }\n";
file_put_contents("$root/style.css", $css);
// Above the on-the-fly threshold (1024) but below precompress.minSize (set to 4096 below).
file_put_contents("$root/small.css", substr($css, 0, 2000));
// Text by type, but random bytes: gzip cannot make it smaller.
mt_srand(167);
$noise = '';
for ($i = 0; $i < 3000; ++$i) $noise .= chr(mt_rand(0, 255));
file_put_contents("$root/noise.js", $noise);

$port = rh_start('precompress', array('Q' => array('webserver' => array(
	'precompress' => array('enabled' => true, 'minSize' => 4096, 'dir' => "$base/precompress")))), 1);

function decoded($r)
{
	$ce = strtolower($r['headers']['content-encoding'] ?? '');
	if ($ce === '') return $r['body'];
	if ($ce === 'gzip') return (string) @gzdecode($r['body']);
	if ($ce === 'br' and function_exists('brotli_uncompress')) return (string) @brotli_uncompress($r['body']);
	return "undecodable $ce";
}
function get($port, $path, $headers = array(), $method = 'GET')
{
	return rh_get($port, $path, 15.0, $method, '', $headers);
}
function whole($label, $r, $want)
{
	check("$label: status", $r['status'], 200);
	check("$label: body complete", $r['complete'], true);
	check("$label: decoded length", strlen(decoded($r)), strlen($want));
	check("$label: decoded bytes", md5(decoded($r)), md5($want));
}

// Order matters: the identity cases come first, before anything could have put
// the file in the memory cache.
foreach (array(
	'identity' => array('Accept-Encoding' => 'identity'),
	'br only' => array('Accept-Encoding' => 'br'),
	'gzip refused' => array('Accept-Encoding' => 'gzip;q=0, identity'),
	'unknown coding' => array('Accept-Encoding' => 'zstd'),
) as $label => $h) {
	$r = get($port, '/style.css', $h);
	whole("style.css, $label", $r, $css);
	if ($label !== 'br only' or !function_exists('brotli_compress')) {
		check("style.css, $label: not encoded", $r['headers']['content-encoding'] ?? '', '');
	}
}

$r = get($port, '/style.css', array('Accept-Encoding' => 'br'));
if (function_exists('brotli_compress')) {
	check('style.css, br: brotli when the extension is loaded', $r['headers']['content-encoding'] ?? '', 'br');
}

$r = get($port, '/style.css', array('Accept-Encoding' => 'gzip'));
whole('style.css, gzip', $r, $css);
check('style.css, gzip: gzip encoded', $r['headers']['content-encoding'] ?? '', 'gzip');
check('style.css, gzip: smaller than the file', strlen($r['body']) < strlen($css), true);

$r = get($port, '/style.css', array('Accept-Encoding' => 'identity'));
whole('style.css, identity after gzip', $r, $css);
check('style.css, identity after gzip: not encoded', $r['headers']['content-encoding'] ?? '', '');

whole('style.css, no Accept-Encoding', get($port, '/style.css'), $css);

whole('small.css (below precompress.minSize), gzip', get($port, '/small.css', array('Accept-Encoding' => 'gzip')), substr($css, 0, 2000));
whole('small.css, identity', get($port, '/small.css', array('Accept-Encoding' => 'identity')), substr($css, 0, 2000));
$r = get($port, '/noise.js', array('Accept-Encoding' => 'gzip'));
whole('noise.js (gzip cannot shrink it), gzip', $r, $noise);
check('noise.js, gzip: sent as it is', $r['headers']['content-encoding'] ?? '', '');

$h = get($port, '/noise.js', array('Accept-Encoding' => 'gzip, br'), 'HEAD');
check('HEAD noise.js: status', $h['status'], 200);
check('HEAD noise.js: Content-Length is the file', $h['headers']['content-length'] ?? '', (string) strlen($noise));
$h = get($port, '/small.css', array('Accept-Encoding' => 'br'), 'HEAD');
$g = get($port, '/small.css', array('Accept-Encoding' => 'br'));
check('HEAD small.css, br: status', $h['status'], 200);
check('HEAD small.css, br: Content-Length is what a GET sends', $h['headers']['content-length'] ?? '', (string) strlen($g['body']));
check('HEAD small.css, br: never zero', ($h['headers']['content-length'] ?? '0') !== '0', true);

// Over 1 MB on a plain connection a GET is streamed by a child, as it is on
// disk; the HEAD for it announced the gzip length instead.
$big = str_repeat($css, 130);
file_put_contents("$root/big.css", $big);
$g = get($port, '/big.css', array('Accept-Encoding' => 'gzip'));
whole('big.css (over 1 MB), gzip', $g, $big);
$h = get($port, '/big.css', array('Accept-Encoding' => 'gzip'), 'HEAD');
check('HEAD big.css, gzip: status', $h['status'], 200);
check('HEAD big.css, gzip: Content-Length is what a GET sends', $h['headers']['content-length'] ?? '', $g['headers']['content-length'] ?? 'none');
check('HEAD big.css, gzip: coding is what a GET sends', $h['headers']['content-encoding'] ?? '', $g['headers']['content-encoding'] ?? '');

$etag = $r['headers']['etag'] ?? '';
check('noise.js carries an ETag', $etag !== '', true);
$n = get($port, '/noise.js', array('Accept-Encoding' => 'identity', 'If-None-Match' => $etag));
check('If-None-Match with the ETag: 304', $n['status'], 304);
$n = get($port, '/style.css', array('Accept-Encoding' => 'br', 'If-None-Match' => $etag));
check('If-None-Match with another ETag: 200', $n['status'], 200);
whole('style.css, br, other ETag', $n, $css);

rh_finish();
