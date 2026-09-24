<?php

/**
 * An unknown /Q/ path over HTTP/2 is the application's, answered by a worker
 * -- never run inside the server process, and never able to hang it.
 *
 * http2Route() hands /Q/ and /.well-known/ paths to route() for the server's
 * own pages, and route() fell through for anything it did not know: to
 * index.php, run right there in the server process. /Q/panel took 838ms of
 * the event loop on alpha, and the next such request spun forever clearing
 * output buffers -- `while (ob_get_level()) ob_end_clean();` against the
 * capture buffer, which cannot be removed -- so the whole server stopped
 * answering, with 37 million notices in its log.
 *
 * Asserted over real TLS + HTTP/2, persistent and fork-per-request:
 *   - several unknown /Q/ paths in a row are each answered, by the application
 *     (its index.php), not by the server process;
 *   - the server's own /Q/health still answers, and so does an ordinary page.
 *
 * Fails on the engine before route() declined for http2Route() and
 * Q_WebServer::dropOutputBuffers() stopped at the capture buffer.
 *
 *   php tests/unit-in-process-requests-never-hang.php
 */

require __DIR__ . '/fixtures/race-harness.php';
require_once __DIR__ . '/../src/Q/WebServer/Http2/Hpack.php';
require_once __DIR__ . '/../src/Q/WebServer/Http2/Frame.php';
rh_require_pool();
if (!function_exists('openssl_pkey_new')) { printf("  skip  needs openssl\n"); exit(0); }
list($base, $root) = rh_setup('in-process-hang');
file_put_contents($root . DS . 'index.php', '<?php echo "app ", getmypid() === (int) ($_GET["parent"] ?? 0) ? "IN PARENT" : "in worker", " ", parse_url($_SERVER["REQUEST_URI"] ?? "", PHP_URL_PATH);');

$key = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
$crt = openssl_csr_sign(openssl_csr_new(array('commonName' => '127.0.0.1'), $key), null, $key, 2);
openssl_x509_export($crt, $crtPem); openssl_pkey_export($key, $keyPem);
file_put_contents("$base/cert.pem", $crtPem); file_put_contents("$base/key.pem", $keyPem);

$F = 'Q_WebServer_Http2_Frame';
function h2Fetch($port, $path, $timeout = 8.0)
{
	$F = 'Q_WebServer_Http2_Frame';
	$ctx = stream_context_create(array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false, 'alpn_protocols' => 'h2')));
	$s = @stream_socket_client("tls://127.0.0.1:$port", $en, $es, 5, STREAM_CLIENT_CONNECT, $ctx);
	if (!$s) return 'no connection';
	if ((stream_get_meta_data($s)['crypto']['alpn_protocol'] ?? '') !== 'h2') return 'no h2';
	$enc = new Q_WebServer_Http2_Hpack(); $dec = new Q_WebServer_Http2_Hpack();
	fwrite($s, $F::PREFACE . $F::build($F::SETTINGS, 0, 0, ''));
	fwrite($s, $F::build($F::HEADERS, $F::FLAG_END_HEADERS | $F::FLAG_END_STREAM, 1, $enc->encode(array(
		array(':method', 'GET'), array(':scheme', 'https'), array(':authority', '127.0.0.1'), array(':path', $path)))));
	stream_set_blocking($s, false);
	$buf = ''; $status = 0; $body = ''; $deadline = microtime(true) + $timeout;
	while (microtime(true) < $deadline) {
		$r = array($s); $w = $e = null;
		if (!@stream_select($r, $w, $e, 0, 200000)) continue;
		$c = @fread($s, 65536);
		if ($c === '' or $c === false) { if (feof($s)) break; continue; }
		$buf .= $c;
		while (($f = $F::read($buf)) !== null) {
			if ($f['type'] === $F::SETTINGS and !($f['flags'] & 1)) fwrite($s, $F::build($F::SETTINGS, 1, 0, ''));
			if ($f['stream'] !== 1) continue;
			if ($f['type'] === $F::HEADERS) foreach ($dec->decode($f['payload']) as $h) if ($h[0] === ':status') $status = (int) $h[1];
			if ($f['type'] === $F::DATA) $body .= $f['payload'];
			if (in_array($f['type'], array($F::HEADERS, $F::DATA), true) and ($f['flags'] & $F::FLAG_END_STREAM)) { fclose($s); return "$status " . trim($body); }
		}
	}
	@fclose($s);
	return 'NO ANSWER';
}

foreach (array('persistent' => array(), 'fork-per-request' => array('forkPerRequest' => true)) as $mode => $ws) {
	$tls = rh_free_port();
	$GLOBALS['rh_extra_args'] = array("--https-port=$tls");
	$name = $mode === 'persistent' ? 'hp' : 'hf';
	rh_start($name, array('Q' => array(
		'web' => array('https' => array('mode' => 'manual', 'cert' => "$base/cert.pem", 'key' => "$base/key.pem"), 'http2' => array('enabled' => true)),
		'webserver' => $ws)), 2);
	$parent = rh_server_pid($name);
	$got = array();
	// /Q/panel itself used to be one of these; the server answers it now, on
	// HTTP/2 as on HTTP/1.1 (see unit-panel-access.php).
	foreach (array('/Q/no-such-0', '/Q/no-such-1', '/Q/no-such-2', '/Q/no-such-3') as $p) {
		$got[] = h2Fetch($tls, "$p?parent=$parent");
	}
	check("$mode: unknown /Q/ paths over HTTP/2 are each answered by the application in a worker",
		implode(' | ', $got), '200 app in worker /Q/no-such-0 | 200 app in worker /Q/no-such-1 | 200 app in worker /Q/no-such-2 | 200 app in worker /Q/no-such-3');
	check("$mode: ...and the server's own /Q/health still answers", substr(h2Fetch($tls, '/Q/health'), 0, 3), '200');
	check("$mode: ...and an ordinary page too", h2Fetch($tls, "/page?parent=$parent"), '200 app in worker /page');
	rh_stop($GLOBALS['rh']['servers'][$name]); unset($GLOBALS['rh']['servers'][$name]);
}

rh_finish();
