<?php

/**
 * A signed-in visitor's page must not be stored and handed to anybody else.
 *
 * The response cache keys on host, path, query and the encoding variant. It
 * does not key on identity, and it should not have to: the rule is that a
 * request carrying a session cookie is never cached in the first place.
 *
 * That rule is enforced by hasSkipCookie(), which looked for the configured
 * name followed immediately by "=". Exponential sets SessionNamePrefix=eZSESSID
 * with SessionNamePerSiteAccess enabled, so its cookie is eZSESSID<digest> and
 * never plain eZSESSID. The configured skip therefore never matched, and a
 * signed-in visitor's pages were stored under a key with no session in it.
 *
 * The same shape catches PHP's own session cookie wherever an installation has
 * given it a prefix, and it is not unusual to do so.
 *
 * Over-matching is the safe direction and the test says so explicitly: a name
 * that catches a cookie it did not mean to costs cache hits, while a name that
 * misses the session cookie costs one visitor's page to another.
 *
 *   php tests/unit-cache-skip-cookies.php
 */

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
		var_export($got, true), var_export($want, true));
}

function skipped($cookie)
{
	return Q_WebServer_Cache::hasSkipCookie(array('cookie' => $cookie));
}

// ── Nothing configured ──────────────────────────────────────────

Q_WebServer_Cache::$skipCookies = array();
check('with nothing configured, nothing is skipped',
	skipped('eZSESSID6f8a=1'), false);

// ── The case this exists for ────────────────────────────────────

Q_WebServer_Cache::$skipCookies = array('eZSESSID');

check('the exact name is skipped', skipped('eZSESSID=abc'), true);

// The one that was missed, and the reason this test exists.
check('a name carrying a per-siteaccess digest is skipped',
	skipped('eZSESSID6f8a9b2c3d=abc'), true);

check('...also when other cookies come first',
	skipped('lang=en; eZSESSID6f8a9b2c3d=abc'), true);

check('...and when it is neither first nor last',
	skipped('a=1; eZSESSID6f8a9b2c3d=abc; b=2'), true);

check('...with no space after the semicolon',
	skipped('a=1;eZSESSID6f8a=abc'), true);

// ── What must still be cached ───────────────────────────────────

check('an unrelated cookie does not stop caching',
	skipped('lang=en'), false);

check('...nor several of them',
	skipped('lang=en; theme=dark; consent=1'), false);

check('an empty cookie header does not stop caching', skipped(''), false);

// A cookie whose *value* mentions the name is not that cookie.
check('the name appearing in a value is not a match',
	skipped('referrer=eZSESSID'), false);

// ── More than one name ──────────────────────────────────────────

Q_WebServer_Cache::$skipCookies = array('Q_sid', 'PHPSESSID');
check('the packaged default catches PHPSESSID',
	skipped('PHPSESSID=abc'), true);
check('...and a prefixed variant of it',
	skipped('PHPSESSID_admin=abc'), true);
check('...and Q_sid', skipped('x=1; Q_sid=abc'), true);
check('...while leaving an unrelated cookie alone',
	skipped('other=abc'), false);

// ── The direction of the trade ──────────────────────────────────

// Recorded deliberately: a configured name that prefixes an unrelated cookie
// will skip it, which costs a cache hit. That is the safe way to be wrong, and
// the alternative -- matching too little -- is how this was broken.
Q_WebServer_Cache::$skipCookies = array('sid');
check('a short name over-matches, which loses a hit rather than a secret',
	skipped('sidebar=open'), true);

if ($fail) {
	printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail);
	exit(1);
}
printf("  PASS - %d case(s)\n", $pass);
