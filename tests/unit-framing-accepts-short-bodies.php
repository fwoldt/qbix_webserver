<?php

/**
 * An honest Content-Length is accepted, whatever number it is.
 *
 * framingFault() collects the Content-Length values as array keys, so that
 * the same value sent twice counts once. PHP turns a numeric string key into
 * an int, and ctype_digit() given an int from -128 to 255 does not look at
 * the number at all: it reads it as a character code. Content-Length: 100
 * became chr(100), "d", not a digit -- and the request was refused with
 *
 *   400 Bad Request: Content-Length is not a number
 *
 * Every body of 0 to 255 bytes was refused that way, except 48 to 57, whose
 * codes happen to be the digits. That is most form posts: a login form is
 * about a hundred bytes, so nobody could log in. unit-malformed-requests.php
 * only checks what is refused, which is why it passed.
 *
 * Every length up to a kilobyte is tried, so the whole character-code range
 * and both sides of its edge are covered.
 *
 *   php tests/unit-framing-accepts-short-bodies.php
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
if (!preg_match('/\n\tstatic function framingFault\(.*?\n\t\}\n/s', $src, $m)) {
	fwrite(STDERR, "  FAIL - framingFault() not found in src/Q/WebServer.php\n");
	exit(1);
}
eval('class T { ' . $m[0] . ' }');

function head($lengths)
{
	$h = "POST / HTTP/1.1\r\nHost: a";
	foreach ((array) $lengths as $l) $h .= "\r\nContent-Length: $l";
	return $h;
}

// Reported as one check, so a regression names the lengths it refuses
// rather than printing a thousand lines.
$refused = array();
for ($n = 0; $n <= 1024; ++$n) {
	if (T::framingFault(head((string) $n)) !== null) $refused[] = $n;
}
check('every Content-Length from 0 to 1024 is accepted',
	implode(' ', $refused), '');

check('the same length sent twice is accepted',
	T::framingFault(head(array('100', '100'))), null);
check('the same length listed twice in one header is accepted',
	T::framingFault(head('100, 100')), null);

// The fix must not loosen what the check is there to refuse.
check('two different lengths are still refused',
	T::framingFault(head(array('100', '101'))), 'conflicting Content-Length values');
check('a word is still refused',
	T::framingFault(head('abc')), 'Content-Length is not a number');
check('an empty value is still refused',
	T::framingFault(head('')), 'Content-Length is not a number');
check('a negative length is still refused',
	T::framingFault(head('-1')), 'Content-Length is not a number');
check('a leading plus is still refused',
	T::framingFault(head('+5')), 'Content-Length is not a number');

echo "\n";
if ($fail === 0) { printf("  PASS - %d case(s)\n\n", $pass); exit(0); }
printf("  FAIL - %d of %d\n\n", $fail, $pass + $fail);
exit(1);
