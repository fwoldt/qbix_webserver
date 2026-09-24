<?php

/**
 * A script's session_name() and session_set_cookie_params() take effect.
 *
 * The compat layer runs sessions itself, because PHP's own session functions
 * refuse once output has begun -- which in a worker it always has. It did
 * that for session_start() and session_id(), but not for session_name() or
 * session_set_cookie_params(): those still went to PHP, which refused them
 * with a warning. Every session went out as PHPSESSID with a browser-session
 * lifetime, whatever the application asked for. Exponential names its cookie
 * eZSESSID and only starts a session for a visitor who sends that cookie, so
 * on the public site a sign-in was accepted and forgotten on the next page.
 *
 * Asserted in both worker modes:
 *   - the session cookie carries the name and lifetime the script set;
 *   - the script reads back what it set, and the next request that sends the
 *     cookie finds its session, without a new cookie;
 *   - a request whose script sets nothing still gets PHP's defaults;
 *   - neither setting leaks from one request into the next.
 *
 * Fails on the engine before Q_WebServer_Compat::_session_name() existed.
 *
 *   php tests/unit-session-name-and-cookie-params.php
 */

require __DIR__ . '/fixtures/race-harness.php';
rh_require_pool();
list($base, $root) = rh_setup('session-name');

file_put_contents($root . DS . 'named.php', '<?php
session_name("appSESSID");
session_set_cookie_params(3600, "/");
session_start();
$_SESSION["n"] = ($_SESSION["n"] ?? 0) + 1;
$p = session_get_cookie_params();
echo session_name(), " ", $p["lifetime"], " n=", $_SESSION["n"];
');
file_put_contents($root . DS . 'plain.php', '<?php
session_start();
echo session_name(), " ", session_get_cookie_params()["lifetime"];
');

/** Every Set-Cookie line of a raw response. */
function setCookies($raw)
{
	preg_match_all('/^set-cookie:\s*(.+)$/mi', substr($raw, 0, (int) strpos($raw, "\r\n\r\n")), $m);
	return array_map('trim', $m[1]);
}

foreach (array('persistent' => array(), 'fork-per-request' => array('forkPerRequest' => true)) as $mode => $ws) {
	$port = rh_start($mode === 'persistent' ? 'sp' : 'sf', array('Q' => array('webserver' => $ws)), 2);

	$r = rh_get($port, '/named.php');
	$cookies = setCookies($r['raw']);
	$named = array_values(array_filter($cookies, function ($c) { return stripos($c, 'appSESSID=') === 0; }));
	check("$mode: the session cookie has the name the script set", count($named) . ' ' . json_encode(array_map(function ($c) { return strtok($c, '='); }, $cookies)), '1 ["appSESSID"]');
	check("$mode: ...and the lifetime it set (an Expires about an hour out)",
		$named && preg_match('/expires=([^;]+)/i', $named[0], $e) ? (abs(strtotime($e[1]) - time() - 3600) < 120) : false, true);
	check("$mode: the script reads back its name and lifetime", $r['body'], 'appSESSID 3600 n=1');

	$id = $named ? substr(strtok($named[0], ';'), strlen('appSESSID=')) : '';
	$r2 = rh_get($port, '/named.php', 15.0, 'GET', '', array('Cookie' => "appSESSID=$id"));
	check("$mode: the next request with that cookie finds its session", $r2['body'], 'appSESSID 3600 n=2');
	check("$mode: ...and is not handed a new cookie", count(array_filter(setCookies($r2['raw']), function ($c) { return stripos($c, 'appSESSID=') === 0; })), 0);

	$r3 = rh_get($port, '/plain.php');
	check("$mode: a script that sets nothing gets PHP's defaults, not the last request's",
		$r3['body'] . ' ' . json_encode(array_map(function ($c) { return strtok($c, '='); }, setCookies($r3['raw']))),
		(ini_get('session.name') ?: 'PHPSESSID') . ' ' . (int) ini_get('session.cookie_lifetime') . ' ["' . (ini_get('session.name') ?: 'PHPSESSID') . '"]');
}

rh_finish();
