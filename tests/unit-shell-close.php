#!/usr/bin/env php
<?php
/**
 * The console's close control ends a session, and only its owner's.
 *
 * Q_WebServer_Shell_Server::closeClient() is what DELETE shell/session and
 * the WebSocket's "close" message call. Asserted:
 *
 *   - the owner closes their own session, which is then gone;
 *   - another panel session naming the same client id closes nothing, and
 *     the owner's session survives;
 *   - closing a session that does not exist returns false and does not
 *     create it on the way;
 *   - a malformed client id is refused.
 *
 *   php tests/unit-shell-close.php
 */
if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
$pass = 0; $fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}
require __DIR__ . '/../src/Q.php';
require_once __DIR__ . '/../src/Q/WebServer/Shell.php';
require_once __DIR__ . '/../src/Q/WebServer/Shell/Server.php';

$sessions = new ReflectionProperty('Q_WebServer_Shell_Server', 'sessions');
$sessions->setAccessible(true);
$count = function () use ($sessions) { return count($sessions->getValue()); };

$a = str_repeat('a', 64);
$b = str_repeat('b', 64);

$keyA = Q_WebServer_Shell_Server::session($a, 'console-p1');
check('a session exists after it is named', isset($sessions->getValue()[$keyA]), true);

check('another panel session cannot close it', Q_WebServer_Shell_Server::closeClient($b, 'console-p1'), false);
check('...and the owner\'s session survives', isset($sessions->getValue()[$keyA]), true);

$before = $count();
check('closing a session that does not exist says so', Q_WebServer_Shell_Server::closeClient($a, 'never-opened'), false);
check('...and does not create it', $count(), $before);

check('a malformed client id is refused', Q_WebServer_Shell_Server::closeClient($a, '../../etc'), false);
check('an empty client id is refused', Q_WebServer_Shell_Server::closeClient($a, ''), false);

check('the owner closes their session', Q_WebServer_Shell_Server::closeClient($a, 'console-p1'), true);
check('...which is then gone', isset($sessions->getValue()[$keyA]), false);
check('closing it twice finds nothing', Q_WebServer_Shell_Server::closeClient($a, 'console-p1'), false);

printf("  %s - %d case(s)%s\n", $fail ? 'FAIL' : 'PASS', $pass + $fail, $fail ? " ($fail failed)" : '');
exit($fail ? 1 : 0);
