<?php

/**
 * The HTTP/2 route refuses what HTTP/1.1 refuses, and in that order.
 *
 * A browser negotiates HTTP/2, so http2Route() is the ordinary path and the
 * HTTP/1.1 one is the exception. It nevertheless grew up separately, and the
 * refusals were on the other side: /settings/site.ini answered 403 to
 * curl --http1.1 and 200 with the file -- database credentials included -- to
 * anything modern. So did /.git/config.
 *
 * Wiring the server's own routes (/Q/dashboard and friends) into this path
 * introduced a second, subtler ordering question. route() can fall through to
 * resolveStatic(), so a delegation placed in front of the refusals would serve
 * files around them for any document root that happened to contain a Q
 * directory. Behind them it cannot.
 *
 * None of that is visible in a passing request, which is why it is asserted on
 * the source: the guards are cheap to move by accident and nothing fails when
 * they are.
 *
 *   php tests/unit-http2-route-order.php
 */

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

$src = file_get_contents(__DIR__ . '/../src/Q/WebServer.php');
if ($src === false) { fwrite(STDERR, "  FAIL - cannot read source\n"); exit(1); }

// The needles are call forms, not bare names: a guard that survives only as a
// mention in a comment is not a guard, and a test satisfied by one would pass
// over its own removal.
//
// Isolate http2Route(). Asserting positions against the whole file would pass
// on matches found in handleRequest(), which is the function this one was
// missing the checks of.
$start = strpos($src, 'function http2Route');
check('http2Route() is present', $start !== false, true);
if ($start === false) { printf("\n  FAIL\n"); exit(1); }

// To the next method declaration at class-body indentation.
$rest = substr($src, $start);
$end = preg_match('/\n\t(?:public |private |protected |static )*function /', $rest,
	$m, PREG_OFFSET_CAPTURE) ? $m[0][1] : strlen($rest);
$body = substr($rest, 0, $end);

printf("  http2Route() body: %d lines\n\n", substr_count($body, "\n"));

/** Offset of a needle within http2Route(), or null. */
function at($needle, $isRegex = false)
{
	global $body;
	if ($isRegex) {
		return preg_match($needle, $body, $m, PREG_OFFSET_CAPTURE)
			? $m[0][1] : null;
	}
	$p = strpos($body, $needle);
	return $p === false ? null : $p;
}

$escapes = at('self::pathEscapesRoot(');
$inside = at('self::insideRoot(');
$blocked = at('self::isBlocked(');
$allowed = at('self::$allowedExtensions');
$builtin = at('self::route(');
$resolve = at('self::resolveScript(');

// ── Every refusal is made at all ────────────────────────────────

check('the request string is judged before anything is opened',
	$escapes !== null, true);
check('the resolved path is contained to the document root',
	$inside !== null, true);
check('the blocked list is consulted -- it was not, and site.ini was served',
	$blocked !== null, true);
check('the extension allow-list is consulted', $allowed !== null, true);

// ── And in an order where each one can do its job ───────────────

check('the request check comes before the filesystem is touched',
	$escapes !== null and $inside !== null and $escapes < $inside, true);
check('containment comes before the blocked list',
	$inside !== null and $blocked !== null and $inside < $blocked, true);
check('the blocked list comes before the allow-list',
	$blocked !== null and $allowed !== null and $blocked < $allowed, true);

// ── The delegation sits behind all of them ──────────────────────

check('the server\'s own routes are reachable over HTTP/2 at all',
	$builtin !== null, true);
check('...gated on the prefixes route() owns, not on every path',
	(bool) preg_match('#/Q/.*?/\.well-known/#s', $body), true);

// The one this file exists for.
check('...and delegated only after the blocked list',
	$builtin !== null and $blocked !== null and $blocked < $builtin, true);
check('...and only after the extension allow-list',
	$builtin !== null and $allowed !== null and $allowed < $builtin, true);
check('...and only after containment',
	$builtin !== null and $inside !== null and $inside < $builtin, true);

// And still in front of handing the path to a worker, or it would never run.
check('but before the application gets the path',
	$builtin !== null and $resolve !== null and $builtin < $resolve, true);

// ── And what it answers is counted ──────────────────────────────

// http2Request() is the one place every directly-returned HTTP/2 response
// passes through, and until it recorded them the dashboard could not see most
// of HTTP/2 at all. A script goes to the worker pool, which records it on its
// own event, so PHP looked fine on both protocols; static files and every
// refusal returned from http2Route() and were counted nowhere.
//
// Measured before the fix: five requests for /.git/config over HTTP/1.1 moved
// the 4xx counter from 2 to 7, and five identical requests over HTTP/2 moved
// it from 7 to 7. A browser negotiates HTTP/2, so a scan showed as nothing.
$wrapper = strpos($src, 'function http2Request');
check('http2Request() is present', $wrapper !== false, true);
if ($wrapper !== false) {
	$w = substr($src, $wrapper, 4000);
	$w = substr($w, 0, ($n = strpos($w, "\n\t/**")) === false ? strlen($w) : $n);
	check('an HTTP/2 response it answers itself is recorded',
		strpos($w, 'recordCompleted') !== false, true);
	// The null case belongs to the pool. Recording it here as well would count
	// every PHP request over HTTP/2 twice, which is the opposite mistake and
	// just as invisible.
	check('...and only when there is a response, so the pool does not double-count',
		(bool) preg_match('/is_array\(\$response\)/', $w), true);
}

if ($fail) {
	printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail);
	exit(1);
}
printf("  PASS - %d case(s)\n", $pass);
