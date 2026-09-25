#!/usr/bin/env php
<?php

/**
 * The qbix distribution registers a Qbix app detector with a site-info
 * command. It stacks no overlay: /etc/qbix is already the base tree.
 *
 * Asserted:
 *   - registering the distribution adds no overlay;
 *   - the Qbix app detector recognises a Qbix app and lists the appinfo command;
 *   - the appinfo command prints the app name and config.
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q.php';
require_once __DIR__ . '/../src/Q/WebServer/Layout.php';
require_once __DIR__ . '/../src/Q/WebServer/Framework.php';
require_once __DIR__ . '/../src/Q/WebServer/Framework/Detector.php';
require_once __DIR__ . '/../src/Q/WebServer/Distribution/Qbix.php';

$pass = 0; $fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}

Q_WebServer_Distribution_Qbix::register();
check('registering qbix adds no overlay (/etc/qbix is the base tree already)',
	Q_WebServer_Layout::$overlays, array());

$base = sys_get_temp_dir() . '/qbix-distribution-qbix-' . getmypid();
$appDir = $base . DS . 'myapp';
@mkdir($appDir . DS . 'web', 0755, true);
@mkdir($appDir . DS . 'config', 0755, true);
file_put_contents($appDir . DS . 'config' . DS . 'app.json', json_encode(array('Q' => array('app' => 'MyApp', 'web' => array('appRootUrl' => 'https://myapp.local/')))));
file_put_contents($appDir . DS . 'composer.json', json_encode(array('name' => 'acme/myapp', 'version' => '2.1.0', 'description' => 'A test app')));

$app = new Q_WebServer_Distribution_Qbix_App();
$d = $app->detect($appDir);
check('qbix app: recognised', $d['kind'] ?? null, 'qbix');
check('qbix app: name from config', $d['details']['App'] ?? null, 'MyApp');
check('qbix app: package shown', $d['details']['Package'] ?? null, 'acme/myapp 2.1.0');

$byCmd = array();
foreach ($d['commands'] ?? array() as $c) $byCmd[$c['cmd']] = $c;
check('qbix app: appinfo command listed', $byCmd['appinfo']['name'] ?? null, 'Site installation info');

$r = Q_WebServer_Framework::run('qbix', 'appinfo', $appDir);
check('qbix app: appinfo command runs and finds the app name',
	strpos($r['output'] ?? '', 'App: MyApp') !== false, true);

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) { ($f->isDir() and !$f->isLink()) ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
@rmdir($base);

if ($fail) { printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail); exit(1); }
printf("  PASS - %d case(s)\n", $pass);
