#!/usr/bin/env php
<?php

/**
 * qbixconsole and qbixctl: the console, and apache2ctl-style control.
 *
 * Asserted:
 *   - argument parsing: --a=b, --flag, --no-flag, -abc, `--`, positionals;
 *   - command lookup: exact, alias, unique abbreviation, ambiguous, unknown;
 *   - site/conf/mod enable and disable as a2ensite does (relative symlink,
 *     idempotent, refuses a missing target and a path as a name);
 *   - cache:clear touches the generation marker;
 *   - -t reports each file and fails on one that does not parse;
 *   - ssl:renew and ssl:show manage the self-signed certificate in <tree>/ssl;
 *   - a real server started with `qbixctl start`, seen by `status`, answering,
 *     and gone after `qbixctl stop`.
 *
 *   php tests/unit-console-ctl.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q.php';
require_once __DIR__ . '/../src/Q/Console.php';
require_once __DIR__ . '/../src/Q/WebServer/Layout.php';
require_once __DIR__ . '/../src/Q/WebServer/Ctl.php';
$pass = 0; $fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}

// ── Parsing and lookup ───────────────────────────────────────────────────
check('options and positionals', Q_Console::parse(array('site:enable', 'a', '--conf-dir=/x', '--json', '--no-wait', '-ab', '--', '--not-an-option')),
	array(array('site:enable', 'a', '--not-an-option'), array('conf-dir' => '/x', 'json' => true, 'wait' => false, 'a' => true, 'b' => true)));
Q_WebServer_Ctl::register(dirname(__DIR__));
check('exact name', Q_Console::find('server:status'), 'server:status');
check('alias', Q_Console::find('ensite'), 'site:enable');
check('unique abbreviation', Q_Console::find('ser:stat'), 'server:status');
check('ambiguous abbreviation lists the candidates', Q_Console::find('se:st'), array('server:start', 'server:status', 'server:stop'));
check('unknown', Q_Console::find('nope:nope'), null);

// ── Enable / disable ─────────────────────────────────────────────────────
$base = sys_get_temp_dir() . '/qbix-console-ctl-' . getmypid();
$tree = "$base/qbix";
foreach (array('mods-available', 'mods-enabled', 'sites-available', 'sites-enabled') as $d) mkdir("$tree/$d", 0755, true);
file_put_contents("$tree/mods-available/cache.conf", '{"Q":{"web":{"cache":{"enabled":true,"dir":' . json_encode("$base/cache") . '}}}}');
check('enable creates the relative link', array(Q_WebServer_Ctl::toggle($tree, 'mod', 'cache', true)[0], readlink("$tree/mods-enabled/cache.conf")),
	array(true, '../mods-available/cache.conf'));
check('enable again is fine', Q_WebServer_Ctl::toggle($tree, 'mod', 'cache', true), array(true, 'mod cache already enabled'));
check('a missing target is refused', Q_WebServer_Ctl::toggle($tree, 'mod', 'nope', true)[0], false);
check('a path as a name is refused', Q_WebServer_Ctl::toggle($tree, 'site', '../../etc', true)[0], false);
check('disable removes only the link', array(Q_WebServer_Ctl::toggle($tree, 'mod', 'cache', false)[0], file_exists("$tree/mods-enabled/cache.conf"), is_file("$tree/mods-available/cache.conf")),
	array(true, false, true));
Q_WebServer_Ctl::toggle($tree, 'mod', 'cache', true);

// ── cache:clear and -t through the real entry points ─────────────────────
$ctl = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../qbixctl.php');
$con = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../qbixconsole.php');
$conf = ' --conf-dir=' . escapeshellarg($tree) . ' --distribution=none';
exec("$con cache:clear$conf 2>&1", $o, $code);
check('cache:clear touches the marker in Q.web.cache.dir', array($code, is_file("$base/cache/.generation")), array(0, true));
exec("$ctl -t$conf 2>&1", $o2, $code2);
check('-t passes a tree that parses', array($code2, end($o2)), array(0, 'Syntax OK'));
file_put_contents("$tree/mods-available/cache.conf", '{ not json');
exec("$ctl -t$conf 2>&1", $o3, $code3);
check('-t fails, naming the file, when one does not parse', array($code3, (bool) preg_grep('/BAD .*cache\.conf/', $o3)), array(1, true));
file_put_contents("$tree/mods-available/cache.conf", '{}');

// ── ssl:renew and ssl:show: the self-signed certificate in <tree>/ssl ────
exec("$con ssl:renew localhost 127.0.0.1$conf 2>&1", $ssl1, $sslc1);
check('ssl:renew makes a self-signed certificate in the tree\'s ssl/', array($sslc1, is_file("$tree/ssl/self-signed.pem"), (fileperms("$tree/ssl/self-signed.key") & 0777)), array(0, true, 0600));
exec("$con ssl:show --json$conf 2>&1", $ssl2, $sslc2);
$sslShow = json_decode(implode('', $ssl2), true);
check('ssl:show reports it usable, with its hosts', array($sslc2, $sslShow['certificates']['self-signed']['usable'] ?? null, $sslShow['certificates']['self-signed']['hosts'] ?? null),
	array(0, true, array('127.0.0.1', 'localhost')));
$fp = $sslShow['certificates']['self-signed']['fingerprint'] ?? '';
exec("$con ssl:renew --if-needed$conf 2>&1", $ssl3, $sslc3);
check('ssl:renew --if-needed leaves a good one alone', array($sslc3, end($ssl3)), array(0, "unchanged: $tree/ssl/self-signed.pem"));
check('a repeated letter counts (-vv)', Q_Console::parse(array('ssl:renew', '-vv'))[1], array('v' => 2));

// ── A real start / status / stop ─────────────────────────────────────────
$root = "$base/web"; mkdir($root);
file_put_contents("$root/index.php", '<?php echo "ctl ok";');
$sock = stream_socket_server('tcp://127.0.0.1:0'); $port = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1); fclose($sock);
$pid = "$base/qbixserver.pid";
$srv = " --distribution=none --root=" . escapeshellarg($root) . " --port=$port --workers=1 --pid=" . escapeshellarg($pid) . ' --log=' . escapeshellarg("$base/server.log");
exec("$ctl start$srv 2>&1", $s1, $c1);
check('qbixctl start starts it', $c1, 0);
exec("$ctl status$srv --json 2>&1", $s2, $c2);
$st = json_decode(implode('', $s2), true);
check('qbixctl status sees it running and listening', array($c2, $st['running'] ?? null, $st['listening'] ?? null), array(0, true, array($port)));
check('...and it answers', @file_get_contents("http://127.0.0.1:$port/"), 'ctl ok');
exec("$ctl stop$srv 2>&1", $s3, $c3);
exec("$ctl status$srv 2>&1", $s4, $c4);
check('qbixctl stop stops it; status then says not running (exit 3)', array($c3, $c4), array(0, 3));

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) { ($f->isDir() and !$f->isLink()) ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
rmdir($base);

if ($fail) { printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail); exit(1); }
printf("  PASS - %d case(s)\n", $pass);
