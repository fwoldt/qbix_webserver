#!/usr/bin/env php
<?php
/**
 * The fallback page renders, and is configurable.
 *
 * Worth a test of its own because it is the one page that only appears when
 * something else has already failed -- so it must not be the thing that fails.
 *
 *   php tests/http2-errorpage.php [--write=/path/preview.html]
 */
require __DIR__ . '/../src/Q/WebServer/Http2/ErrorPage.php';

$pass = 0; $fail = 0;
function check($what, $cond) {
	global $pass, $fail;
	if ($cond) { $pass++; return; }
	$fail++; echo "  FAIL  $what\n";
}

$html = Q_WebServer_Http2_ErrorPage::render('a test detail line', 7);

check('renders a whole document', strpos($html, '<!doctype html>') === 0);
check('carries the default heading', strpos($html, 'did not finish loading') !== false);
check('names the stream', strpos($html, 'stream 7') !== false);
check('includes the detail', strpos($html, 'a test detail line') !== false);
check('has an inline image, needing no second request',
	strpos($html, 'data:image/svg+xml;base64,') !== false);
check('image is a monkey, described for a screen reader',
	strpos(Q_WebServer_Http2_ErrorPage::defaultImage(), 'A monkey opening a server with a wrench') !== false);
check('escapes the detail rather than trusting it',
	strpos(Q_WebServer_Http2_ErrorPage::render('<script>x</script>', 1), '<script>x') === false);

$resp = Q_WebServer_Http2_ErrorPage::response('boom', 3);
check('response is 503', $resp['status'] === 503);
check('response declares html', $resp['headers']['content-type'] === 'text/html; charset=utf-8');
check('response length matches the body',
	(int) $resp['headers']['content-length'] === strlen($resp['body']));
check('response must not be cached', $resp['headers']['cache-control'] === 'no-store');

// Configurability, without Q_Config present: the defaults must still stand.
check('works with no configuration at all', strlen($html) > 500);

$opts = getopt('', array('write:'));
if (isset($opts['write'])) {
	file_put_contents($opts['write'], $html);
	echo "  wrote a preview to " . $opts['write'] . "\n";
}

echo "\n";
if ($fail === 0) { echo "  PASS - $pass case(s)\n"; exit(0); }
echo "  FAIL - $fail of " . ($pass + $fail) . "\n"; exit(1);
