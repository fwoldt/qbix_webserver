<?php

/**
 * The headers of an HTTP/1.1 response must say one thing each.
 *
 * sendResponse() wrote Content-Type from its argument and then appended the
 * caller's headers after it. Every cached response carries a Content-Type, so
 * every cached hit went out with two -- observed live on 2026-09-22:
 *
 *   Content-Type: text/html; charset=utf-8
 *   ...
 *   Content-Type: text/html; charset=utf-8
 *
 * RFC 9110 lets a recipient pick either, so two intermediaries may disagree
 * about what a page is. Content-Length duplicated is worse than untidy: the
 * stored length describes the body as it was stored, the body being written
 * may since have been compressed, and a parser given two lengths is being
 * taught to find the next response inside this one.
 *
 *   php tests/unit-http1-response-head.php
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

// Q_WebServer is large and pulls in the world, so the method under test is
// exercised through a copy of its source rather than by loading the class.
// The copy is taken from the file at run time, so it cannot drift from it.
$src = file_get_contents(__DIR__ . '/../src/Q/WebServer.php');
// http1Head() delegates its header serialisation to headerLines(), so both
// have to come across for the copy to behave like the original.
$methods = '';
foreach (array('http1Head', 'headerLines') as $name) {
	if (!preg_match('/\n\tstatic function ' . $name . '\(.*?\n\t\}\n/s', $src, $m)) {
		fwrite(STDERR, "  FAIL - $name() not found in src/Q/WebServer.php\n");
		exit(1);
	}
	$methods .= $m[0];
}
eval('class T { ' . $methods . ' }');

function head($extra, $type = 'text/plain', $len = 3)
{
	return T::http1Head(200, 'OK', $type, $len, 'keep-alive', $extra);
}

function countHeader($head, $name)
{
	$n = 0;
	foreach (explode("\r\n", $head) as $line) {
		if (stripos($line, $name . ':') === 0) ++$n;
	}
	return $n;
}

function valueOf($head, $name)
{
	foreach (explode("\r\n", $head) as $line) {
		if (stripos($line, $name . ':') === 0) {
			return trim(substr($line, strlen($name) + 1));
		}
	}
	return null;
}

// ── The defect ───────────────────────────────────────────────────────────
$cached = array(
	'Content-Type' => 'text/html; charset=utf-8',
	'X-Cache' => 'HIT',
);
$h = head($cached);
check('a caller that supplies Content-Type gets exactly one',
	countHeader($h, 'Content-Type'), 1);
check('and it is the caller\'s, not the fallback',
	valueOf($h, 'Content-Type'), 'text/html; charset=utf-8');
check('unrelated headers survive', valueOf($h, 'X-Cache'), 'HIT');

check('a caller that supplies none gets the fallback',
	valueOf(head(array('X-Cache' => 'MISS')), 'Content-Type'), 'text/plain');

// Case is not significance in a header name.
$h = head(array('content-type' => 'application/json'));
check('a lowercased Content-Type is still Content-Type',
	countHeader($h, 'Content-Type'), 1);
check('and still wins', valueOf($h, 'Content-Type'), 'application/json');

$h = head(array('CONTENT-TYPE' => 'text/css'));
check('as is an upper-cased one', countHeader($h, 'Content-Type'), 1);

// An empty value is not an answer; the fallback stands.
check('an empty Content-Type falls back',
	valueOf(head(array('Content-Type' => '')), 'Content-Type'), 'text/plain');

// ── Length is the body's, and only the body's ────────────────────────────
$h = head(array('Content-Length' => '999999'), 'text/html', 42);
check('a supplied Content-Length is dropped', countHeader($h, 'Content-Length'), 1);
check('and the body decides', valueOf($h, 'Content-Length'), '42');

$h = head(array('content-length' => '999999'), 'text/html', 42);
check('whatever case it came in', valueOf($h, 'Content-Length'), '42');

check('a zero-length body says so',
	valueOf(head(array(), 'text/html', 0), 'Content-Length'), '0');

// ── Connection ───────────────────────────────────────────────────────────
$h = head(array('Connection' => 'close'));
check('Connection is never duplicated either', countHeader($h, 'Connection'), 1);
check('and the argument decides it', valueOf($h, 'Connection'), 'keep-alive');

// ── Splitting ────────────────────────────────────────────────────────────
// Values reach this function from application output and from stored cache
// entries. A CR or LF in one would let it append headers, or a whole second
// response, of its own choosing.
$h = head(array('X-Thing' => "ok\r\nX-Injected: yes"));
check('a value carrying CRLF is refused', strpos($h, 'X-Injected'), false);
check('and does not smuggle a status line',
	substr_count($h, 'HTTP/1.1'), 1);

$h = head(array("X-Bad\r\nX-Injected" => 'yes'));
check('a name carrying CRLF is refused too', strpos($h, 'X-Injected'), false);

$h = head(array('X-Thing' => "ok\nX-Injected: yes"));
check('a bare LF counts', strpos($h, 'X-Injected'), false);

check('a clean value is kept',
	valueOf(head(array('X-Thing' => 'perfectly fine')), 'X-Thing'), 'perfectly fine');

// ── Date ─────────────────────────────────────────────────────────────────
// RFC 9110 requires it, and RFC 9111 computes a response's age against it.
// Without one, a client whose clock runs ahead of ours reads the absolute
// Expires we send beside it as already past, and revalidates every time.
$h = head(array('X-Cache' => 'HIT'));
check('every response carries a Date', countHeader($h, 'Date'), 1);
check('and it parses as a date',
	(bool) strtotime((string) valueOf($h, 'Date')), true);
check('and it is now, not something stored',
	abs(strtotime((string) valueOf($h, 'Date')) - time()) <= 2, true);

$h = head(array('Date' => 'Tue, 01 Jan 1980 00:00:00 GMT'));
check('a stored Date is replaced, not appended', countHeader($h, 'Date'), 1);
check('and the stored value does not survive',
	strtotime((string) valueOf($h, 'Date')) > strtotime('2020-01-01'), true);

// ── Shape ────────────────────────────────────────────────────────────────
$h = head(array('X-Cache' => 'HIT'));
check('it starts with the status line',
	strpos($h, "HTTP/1.1 200 OK\r\n"), 0);
check('it ends with a single CRLF, not the blank line',
	substr($h, -4) === "\r\n\r\n", false);
check('rubbish headers do not crash it',
	countHeader(T::http1Head(200, 'OK', 'text/plain', 0, 'close', null), 'Content-Type'), 1);

echo "\n";
if ($fail === 0) { printf("  PASS - %d case(s)\n\n", $pass); exit(0); }
printf("  FAIL - %d of %d\n\n", $fail, $pass + $fail);
exit(1);
