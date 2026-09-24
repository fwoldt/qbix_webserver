<?php
/**
 * A dns-01 hook, as a user would write one, for Pebble's challenge test
 * server: HOOK add|remove NAME VALUE. A real hook calls a DNS provider's API
 * the same way, then returns 0 once the record is set.
 *
 *   php acme-dns-hook-challtestsrv.php add _acme-challenge.example.com VALUE
 */
list(, $action, $name, $value) = $argv + array(null, '', '', '');
$api = getenv('CHALLTESTSRV') ?: 'http://127.0.0.1:8055';
$path = $action === 'add' ? '/set-txt' : '/clear-txt';
$body = json_encode($action === 'add' ? array('host' => rtrim($name, '.') . '.', 'value' => $value) : array('host' => rtrim($name, '.') . '.'));
$ctx = stream_context_create(array('http' => array('method' => 'POST', 'header' => 'Content-Type: application/json',
	'content' => $body, 'ignore_errors' => true, 'timeout' => 10)));
$r = @file_get_contents($api . $path, false, $ctx);
$ok = isset($http_response_header[0]) && preg_match('~ 200 ~', $http_response_header[0]);
fwrite(STDERR, $ok ? '' : "challtestsrv answered: " . ($http_response_header[0] ?? 'nothing') . "\n");
exit($ok ? 0 : 1);
