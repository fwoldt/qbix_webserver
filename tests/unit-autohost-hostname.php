<?php

/**
 * What a hostname is allowed to be, before we act on it.
 *
 * Q_WebServer_Autohost accepts a Host header from an unknown caller and, if it
 * validates, resolves it, provisions for it, asks certbot for a certificate in
 * its name and writes it to a log. validateHostname() is the only thing
 * standing between a stranger's header and all of that, so it is the place
 * where a wrong answer is expensive, and it had no test at all.
 *
 *   php tests/unit-autohost-hostname.php
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

// Q_WebServer_Autohost reaches for Q_Config and the event loop, so the method
// under test is exercised through a copy of its source taken at run time.
$src = file_get_contents(__DIR__ . '/../src/Q/WebServer/Autohost.php');
if (!preg_match('/\n\tstatic function validateHostname\(.*?\n\t\}\n/s', $src, $m)) {
	fwrite(STDERR, "  FAIL - validateHostname() not found\n");
	exit(1);
}
eval('class T { ' . $m[0] . ' }');

function ok($h) { return T::validateHostname($h); }

// ── Ordinary names are accepted ─────────────────────────────────

check('a two-label name', ok('example.com'), true);
check('a subdomain', ok('www.example.com'), true);
check('a deep subdomain', ok('a.b.c.d.example.com'), true);
check('digits in a label', ok('web01.example.com'), true);
check('an all-digit label', ok('123.example.com'), true);
check('a hyphen inside a label', ok('my-site.example.com'), true);
check('uppercase is normalised, not rejected', ok('EXAMPLE.COM'), true);
check('mixed case is normalised', ok('WwW.Example.Com'), true);
check('a port is stripped before validating', ok('example.com:8080'), true);
check('a 63-character label is the limit and allowed',
	ok(str_repeat('a', 63) . '.com'), true);

// ── Malformed names are refused ─────────────────────────────────

check('the empty string', ok(''), false);
check('a bare hostname with no dot', ok('localhost'), false);
check('a label longer than 63 characters',
	ok(str_repeat('a', 64) . '.com'), false);
check('a name longer than 253 characters',
	ok(str_repeat('a.', 200) . 'com'), false);
check('a leading dot', ok('.example.com'), false);
check('a trailing dot', ok('example.com.'), false);
check('a doubled dot', ok('example..com'), false);
check('a leading hyphen', ok('-example.com'), false);
check('a trailing hyphen', ok('example-.com'), false);
check('an underscore', ok('my_site.example.com'), false);
check('a space', ok('example .com'), false);

// ── The reason this matters ─────────────────────────────────────

// The validated name is interpolated into a certbot invocation, passed to a
// resolver and written to a log. Anything that can carry a shell character, a
// path or a line ending past this point is a real problem, not a tidiness one.

check('a shell metacharacter', ok('example.com;id'), false);
check('a backtick', ok('example.com`id`'), false);
check('a dollar expansion', ok('example.com$(id)'), false);
check('a pipe', ok('example.com|id'), false);
check('an ampersand', ok('example.com&id'), false);
check('a quote', ok("example.com'"), false);
check('a double quote', ok('example.com"'), false);
check('a slash', ok('example.com/../etc'), false);
check('a backslash', ok('example.com\\x'), false);
check('a null byte', ok("example.com\0"), false);
check('a tab', ok("example.com\t"), false);
check('an IPv6 literal in brackets', ok('[::1]'), false);
check('a wildcard', ok('*.example.com'), false);
check('an at sign', ok('user@example.com'), false);

// A trailing newline is the one that does not look dangerous and is. PCRE's $
// matches before a final newline unless the D modifier says otherwise, so a
// pattern anchored with ^...$ accepts a string with one on the end. The name
// then reaches a log as two lines, and a command line as an argument the
// author did not write.
check('a trailing newline', ok("example.com\n"), false);
check('a trailing carriage return', ok("example.com\r"), false);
check('a newline after a stripped port', ok("example.com:80\n"), false);
check('a name that is only a newline', ok("\n"), false);
check('a newline in the middle', ok("exa\nmple.com"), false);
check('a smuggled second line', ok("example.com\nevil.com"), false);

// ── The bare-label option ───────────────────────────────────────

// An entry in a hosts file may legitimately be a single label, so the panel
// asks for the same shape without the dot rather than keeping a second
// validator of its own. Everything else must still apply.
check('a bare label is allowed when the dot is not required',
	T::validateHostname('myapp', false), true);
check('a dotted name is still fine without the requirement',
	T::validateHostname('example.com', false), true);
check('the dot is required by default',
	T::validateHostname('myapp'), false);
check('a bare label still cannot end in a hyphen',
	T::validateHostname('myapp-', false), false);
check('a bare label still cannot carry a newline',
	T::validateHostname("myapp\n", false), false);
check('a bare label still cannot carry a shell character',
	T::validateHostname('myapp;id', false), false);
check('an over-long bare label is still refused',
	T::validateHostname(str_repeat('a', 64), false), false);

// The panel must actually be using it, rather than keeping the copy that
// accepted a trailing hyphen and a trailing newline.
$panelSrc = file_get_contents(__DIR__ . '/../src/Q/WebServer/Panel.php');
check('the panel has no hostname pattern of its own',
	preg_match_all('/\^\[a-z0-9\]\(\[a-z0-9\\\\\-\\\\\.\]\*\[a-z0-9\]\)\?\$/i', $panelSrc),
	0);
check('the panel validates a new domain through this validator',
	(bool) preg_match('/apiAddDomain.*?Q_WebServer_Autohost::validateHostname\(\$domain\)/s', $panelSrc),
	true);
check('the panel validates a new host through this validator',
	(bool) preg_match('/apiHostsAdd.*?Q_WebServer_Autohost::validateHostname\(\$hostname, false\)/s', $panelSrc),
	true);

if ($fail) {
	printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail);
	exit(1);
}
printf("  PASS - %d case(s)\n", $pass);
