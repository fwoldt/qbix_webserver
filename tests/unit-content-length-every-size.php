<?php

/**
 * A request body of any size is accepted, and a malformed Content-Length is
 * still refused.
 *
 * The framing check collected Content-Length values as array keys, and PHP
 * turns a numeric string key into an integer. ctype_digit() reads an integer
 * from -128 to 255 as a character code, so a body of 0-47 or 58-255 bytes was
 * answered "400 Content-Length is not a number" -- while 48-57 (the codes of
 * '0' to '9') and anything from 256 up passed. A login form happened to be
 * 50 bytes; a short AJAX POST was not so lucky.
 *
 *   php tests/unit-content-length-every-size.php
 */

require __DIR__ . '/fixtures/race-harness.php';
rh_require_pool();
list($base, $root) = rh_setup('content-length');
file_put_contents($root . DS . 'index.php', '<?php echo strlen(file_get_contents("php://input"));');

$port = rh_start('cl', array(), 2);

$bad = array();
for ($n = 0; $n <= 300; ++$n) {
	$r = rh_get($port, '/index.php', 10, 'POST', str_repeat('a', $n));
	if ($r['status'] !== 200 or $r['body'] !== (string) $n) $bad[] = "$n: {$r['status']} " . substr($r['body'], 0, 40);
	if (count($bad) > 5) break;
}
check('a POST body of every size from 0 to 300 bytes is accepted whole', $bad, array());

$r = rh_get($port, '/index.php', 10, 'POST', str_repeat('a', 70000));
check('...and a large one', $r['status'] . ' ' . $r['body'], '200 70000');

// The check itself still works.
foreach (array('abc', '-1', '1.5', '0x10', ' ') as $v) {
	$s = stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 5);
	fwrite($s, "POST /index.php HTTP/1.1\r\nHost: x\r\nContent-Length: $v\r\nConnection: close\r\n\r\nabc");
	stream_set_timeout($s, 5);
	$raw = (string) stream_get_contents($s);
	fclose($s);
	check("Content-Length \"$v\" is refused", (bool) preg_match('~^HTTP/1\.[01] 400~', $raw), true);
}
$s = stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 5);
fwrite($s, "POST /index.php HTTP/1.1\r\nHost: x\r\nContent-Length: 3\r\nContent-Length: 4\r\nConnection: close\r\n\r\nabcd");
stream_set_timeout($s, 5);
$raw = (string) stream_get_contents($s);
fclose($s);
check('two different Content-Length values are refused', (bool) preg_match('~^HTTP/1\.[01] 400~', $raw), true);

rh_finish();
