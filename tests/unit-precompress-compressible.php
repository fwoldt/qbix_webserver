<?php

/**
 * What is worth compressing, and what compression would only make bigger.
 *
 * Q_WebServer_Precompress decides this per response and then caches the result
 * on disk, so a wrong answer is not a one-off cost: a JPEG judged compressible
 * is gzipped on the way out, stored gzipped, and served that way to everybody
 * afterwards, spending CPU on both ends to make the file slightly larger. The
 * class had no test.
 *
 *   php tests/unit-precompress-compressible.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);

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

$src = file_get_contents(__DIR__ . '/../src/Q/WebServer/Precompress.php');
if (!preg_match('/\n\tstatic function compressible\(.*?\n\t\}\n/s', $src, $m)) {
	fwrite(STDERR, "  FAIL - compressible() not found\n");
	exit(1);
}
eval('class T { ' . $m[0] . ' }');

function c($ct) { return T::compressible($ct); }

// ── Text ────────────────────────────────────────────────────────

check('html', c('text/html'), true);
check('html with a charset', c('text/html; charset=utf-8'), true);
check('css', c('text/css'), true);
check('plain text', c('text/plain'), true);
check('csv', c('text/csv'), true);
check('any text/* subtype we have not heard of', c('text/vnd.whatever'), true);

// ── Structured formats ──────────────────────────────────────────

check('json', c('application/json'), true);
check('javascript', c('application/javascript'), true);
check('ecmascript', c('application/ecmascript'), true);
check('xml', c('application/xml'), true);
check('svg', c('image/svg+xml'), true);
check('a +json suffix', c('application/ld+json'), true);
check('a +xml suffix', c('application/atom+xml'), true);
check('wasm', c('application/wasm'), true);

// ── Already compressed ──────────────────────────────────────────

// These are the ones that cost CPU on both ends to grow the file.
check('jpeg', c('image/jpeg'), false);
check('png', c('image/png'), false);
check('gif', c('image/gif'), false);
check('webp', c('image/webp'), false);
check('mp4', c('video/mp4'), false);
check('mpeg audio', c('audio/mpeg'), false);
check('a zip', c('application/zip'), false);
check('a gzip', c('application/gzip'), false);
check('a pdf', c('application/pdf'), false);
check('an octet stream', c('application/octet-stream'), false);
check('woff2, which is already compressed', c('font/woff2'), false);

// ── Shape ───────────────────────────────────────────────────────

check('an uppercase type is matched', c('TEXT/HTML'), true);
check('a mixed-case type is matched', c('Application/JSON'), true);
check('an empty type compresses nothing', c(''), false);

if ($fail) {
	printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail);
	exit(1);
}
printf("  PASS - %d case(s)\n", $pass);
