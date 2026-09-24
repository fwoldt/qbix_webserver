<?php

/**
 * A visitor over TLS is known by their real address.
 *
 * TLS connections are accepted by a path that never recorded the client's
 * address, so every HTTPS visitor was 0.0.0.0: in the access log, in the
 * application's REMOTE_ADDR, and to the admin surface's this-machine check.
 * The address is now read from the socket when nothing was recorded.
 *
 *   php tests/unit-tls-client-address.php
 */

require __DIR__ . '/fixtures/race-harness.php';
rh_require_pool();
if (!function_exists('openssl_pkey_new')) { printf("  skip  needs openssl\n"); exit(0); }
list($base, $root) = rh_setup('tls-address');
file_put_contents($root . DS . 'index.php', '<?php echo "addr=", $_SERVER["REMOTE_ADDR"] ?? "none";');

$key = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
$crt = openssl_csr_sign(openssl_csr_new(array('commonName' => '127.0.0.1'), $key), null, $key, 2);
openssl_x509_export($crt, $crtPem); openssl_pkey_export($key, $keyPem);
file_put_contents("$base/cert.pem", $crtPem); file_put_contents("$base/key.pem", $keyPem);

$tlsPort = rh_free_port();
$GLOBALS['rh_extra_args'] = array("--https-port=$tlsPort");
$port = rh_start('tls', array('Q' => array(
	'web' => array('https' => array('mode' => 'manual', 'cert' => "$base/cert.pem", 'key' => "$base/key.pem")),
	'webserver' => array('log' => array('dir' => "$base/logs", 'accessName' => 'access.log', 'errorName' => 'error.log')),
)), 1);

$ctx = stream_context_create(array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false)));
$raw = '';
for ($i = 0; $i < 40 and $raw === ''; ++$i) {
	$s = @stream_socket_client("tls://127.0.0.1:$tlsPort", $en, $es, 5, STREAM_CLIENT_CONNECT, $ctx);
	if (!$s) { usleep(250000); continue; }
	fwrite($s, "GET /index.php HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
	stream_set_timeout($s, 10);
	$raw = (string) stream_get_contents($s);
	fclose($s);
}
check('a TLS request is answered', (bool) preg_match('~^HTTP/1\.[01] 200~', $raw), true);
check('...and the application sees the real client address', strpos($raw, 'addr=127.0.0.1') !== false, true);

$log = '';
for ($i = 0; $i < 30 and strpos($log, 'index.php') === false; ++$i) {
	usleep(200000);
	$log = (string) @file_get_contents("$base/logs/access.log");
}
check('the access log records the real client address, not 0.0.0.0',
	(bool) preg_match('~^127\.0\.0\.1 .*index\.php~m', $log) and strpos($log, '0.0.0.0') === false, true);

rh_finish();
