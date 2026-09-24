<?php

/**
 * The product name is a parameter; the protocol identifiers are not.
 *
 * A fork wants its own name on the dashboard, the docs and the manifest without
 * editing a dozen views or diverging from upstream at every occurrence. So the
 * display name comes from Q_WebServer::brand(), default "Qbix Server",
 * overridable by Q.webserver.brand.
 *
 * The line this test holds is the one that is easy to cross by accident: the
 * wire identifiers -- the .well-known/qbix path, qbix.json, the "qbix"
 * framework key, isQbixApp, the provider name -- are protocol, not brand.
 * Renaming those to match a fork's name breaks federation and app detection for
 * a cosmetic gain. This asserts the display strings moved and the identifiers
 * did not.
 *
 *   php tests/unit-brand-string.php
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

$web = file_get_contents(__DIR__ . '/../src/Q/WebServer.php');
// The dashboard's PHP and its page, which is a design on disk now
// (designs/default/dashboard: page.html, style.css, script.js).
$dash = file_get_contents(__DIR__ . '/../src/Q/WebServer/Dashboard.php');
foreach (array('page.html', 'style.css', 'script.js') as $__f) $dash .= "\n" . file_get_contents(__DIR__ . '/../designs/default/dashboard/' . $__f);
$panel = file_get_contents(__DIR__ . '/../src/Q/WebServer/Panel.php');

// ── The accessor exists and defaults to the upstream name ───────

check('brand() is defined', (bool) preg_match('/static function brand\(\)/', $web), true);
check('...and its default is the upstream name, not a fork\'s',
	(bool) preg_match("/'brand',\s*'Qbix Server'/", $web), true);

// ── The display views ask for it rather than hardcoding ─────────

check('the dashboard heading is not a literal any more',
	strpos($dash, '>Qbix Server</h1>'), false);
// The heading links to the dashboard itself, so it carries $brandHeader --
// the brand wrapped in that link -- rather than the bare name.
check('the dashboard heading interpolates the brand',
	strpos($dash, '{{brandHeader}}</h1>') !== false
	and (bool) preg_match('/\$brandHeader = .*\$brand\b/', $dash), true);
check('the dashboard <title> is not a literal',
	strpos($dash, '<title>Qbix Server Dashboard'), false);
check('the docs viewer calls brand()',
	substr_count($web, 'self::brand()') >= 6, true);
check('the panel title is substituted, not literal',
	strpos($panel, '<title>Qbix Control Panel</title>'), false);

// ── The wire identifiers are untouched ──────────────────────────
// Each of these is matched by a peer or the framework and must read "qbix".

check('the .well-known path is still qbix',
	substr_count($web, "'qbix'") >= 1 || strpos($web, "=== 'qbix'") !== false, true);
check('the manifest machine name is still qbix-server',
	strpos($web, "'name' => 'qbix-server'") !== false, true);
check('the provider name is still Qbix',
	strpos($web, "'name' => 'Qbix'") !== false, true);
check('qbix.json is still qbix.json',
	strpos($web, 'qbix.json') !== false, true);
check('the app-detection flag is still isQbixApp',
	strpos($panel, 'isQbixApp') !== false, true);

// ── Brand and maintainer links are configurable and validated ───

check('brandLink() is defined', (bool) preg_match('/static function brandLink/', $web), true);
// A non-URL for a url key must be dropped, so a setting cannot inject a
// javascript: or data: link into the page.
check('a url key rejects a non-http value',
	(bool) preg_match('#https\\?://#', $web) && strpos($web, "!preg_match('#^https?://#i'") !== false, true);
check('the maintainer line is conditional, not hardcoded',
	strpos($dash, 'Maintained by 7x'), false);
check('the dashboard builds the maintainer line from config',
	strpos($dash, '$maintainedBy') !== false, true);

// ── The WebSocket scheme is chosen by the browser ───────────────
// A hardcoded ws:// is blocked as mixed content on an https page, which is the
// "connecting" that never resolves. location.protocol is authoritative.

check('the dashboard no longer hardcodes ws://',
	strpos($dash, '"ws://$host/Q/ws'), false);
check('...and picks the scheme from the page',
	strpos($dash, "location.protocol==='https:'?'wss://':'ws://'") !== false, true);

if ($fail) {
	printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail);
	exit(1);
}
printf("  PASS - %d case(s)\n", $pass);
