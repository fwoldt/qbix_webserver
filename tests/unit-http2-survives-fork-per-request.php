<?php

/**
 * In fork-per-request mode, a script answered over HTTP/2 does not end the
 * connection it came in on.
 *
 * Each worker there serves one request and is recycled, and recycle() closed
 * whatever client the worker had been given. For HTTP/1.1 that was already
 * closed; for HTTP/2 it was the connection itself -- shared by every other
 * request of the page. fclose() on the TLS stream sent close_notify, and the
 * browser saw the connection end with the rest of the page in flight. In
 * Firefox, signed in to the admin: no header images, stylesheets missing,
 * no sub-items, worst on a hard reload over real latency.
 *
 * Asserted over real TLS with ALPN h2 against a fork-per-request pool:
 *
 *   - a quick script and a slow one requested together on one connection
 *     are both answered, the slow one after the quick one's worker is gone;
 *   - the connection then takes more script requests, and a static file,
 *     without being reopened.
 *
 * Fails on the engine before Pool::onWorkerData() released the client.
 *
 *   php tests/unit-http2-survives-fork-per-request.php
 */

require __DIR__ . '/fixtures/race-harness.php';
require_once __DIR__ . '/../src/Q/WebServer/Http2/Hpack.php';
require_once __DIR__ . '/../src/Q/WebServer/Http2/Frame.php';
rh_require_pool();
if (!function_exists('openssl_pkey_new')) { printf("  skip  needs openssl\n"); exit(0); }
list($base, $root) = rh_setup('h2-fork-per-request');

file_put_contents($root . DS . 'index.php', '<?php
if (isset($_GET["sleep"])) usleep((int) $_GET["sleep"] * 1000);
echo "ok ", $_GET["n"] ?? "";
');
file_put_contents($root . DS . 'style.css', 'body { color: #333 }');

$key = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
// sha256, because PHP's default is SHA-1, which a system crypto policy may
// refuse to sign with (RHEL's DEFAULT does).
$csr = openssl_csr_new(array('commonName' => '127.0.0.1'), $key, array('digest_alg' => 'sha256'));
$crt = openssl_csr_sign($csr, null, $key, 2, array('digest_alg' => 'sha256'));
openssl_x509_export($crt, $crtPem);
openssl_pkey_export($key, $keyPem);
file_put_contents("$base/cert.pem", $crtPem);
file_put_contents("$base/key.pem", $keyPem);

$tlsPort = rh_free_port();
$config = array('Q' => array(
	'web' => array(
		'https' => array('mode' => 'manual', 'cert' => "$base/cert.pem", 'key' => "$base/key.pem"),
		'http2' => array('enabled' => true),
	),
	'webserver' => array('forkPerRequest' => true),
));
$GLOBALS['rh_extra_args'] = array("--https-port=$tlsPort");
rh_start('h2fpr', $config, 4);

$F = 'Q_WebServer_Http2_Frame';

$ctx = stream_context_create(array('ssl' => array(
	'verify_peer' => false, 'verify_peer_name' => false, 'alpn_protocols' => 'h2',
)));
$s = false;
for ($i = 0; $i < 40 and !$s; ++$i) {
	$s = @stream_socket_client("tls://127.0.0.1:$tlsPort", $en, $es, 5, STREAM_CLIENT_CONNECT, $ctx);
	if (!$s) usleep(250000);
}
if (!$s) { check('TLS connection', 'no connection', 'connected'); rh_finish(); }
$meta = stream_get_meta_data($s);
$alpn = $meta['crypto']['alpn_protocol'] ?? '';
if ($alpn !== 'h2') { printf("  skip  server did not negotiate h2 (ALPN %s)\n", json_encode($alpn)); rh_finish(); }

$enc = new Q_WebServer_Http2_Hpack();
$dec = new Q_WebServer_Http2_Hpack();
fwrite($s, $F::PREFACE . $F::build($F::SETTINGS, 0, 0, ''));

function h2Get($s, $enc, $id, $path)
{
	$F = 'Q_WebServer_Http2_Frame';
	fwrite($s, $F::build($F::HEADERS, $F::FLAG_END_HEADERS | $F::FLAG_END_STREAM, $id, $enc->encode(array(
		array(':method', 'GET'), array(':scheme', 'https'),
		array(':authority', '127.0.0.1'), array(':path', $path),
	))));
}

/**
 * Read until every stream in $want has ended, the connection closes, or
 * $timeout passes. Returns array(stream => array(status, body), ..., 'closed' => why).
 */
function h2Collect($s, $dec, $want, $timeout = 20.0)
{
	$F = 'Q_WebServer_Http2_Frame';
	static $buf = '';
	$got = array(); $done = array(); $closed = '';
	$deadline = microtime(true) + $timeout;
	stream_set_blocking($s, false);
	while (count(array_intersect($want, $done)) < count($want) and microtime(true) < $deadline) {
		$r = array($s); $w = $e = null;
		if (!@stream_select($r, $w, $e, 0, 200000)) continue;
		$chunk = @fread($s, 65536);
		if ($chunk === '' or $chunk === false) {
			if (feof($s)) { $closed = 'connection closed by the server'; break; }
			continue;
		}
		$buf .= $chunk;
		while (($f = $F::read($buf)) !== null) {
			$id = $f['stream'];
			if ($f['type'] === $F::SETTINGS and !($f['flags'] & 0x1)) {
				fwrite($s, $F::build($F::SETTINGS, 0x1, 0, ''));
			} elseif ($f['type'] === $F::GOAWAY) {
				$closed = 'GOAWAY';
			} elseif ($f['type'] === $F::HEADERS) {
				foreach ($dec->decode($f['payload']) as $h) {
					if ($h[0] === ':status') $got[$id][0] = (int) $h[1];
				}
				$got[$id][1] = $got[$id][1] ?? '';
			} elseif ($f['type'] === $F::DATA) {
				$got[$id][1] = ($got[$id][1] ?? '') . $f['payload'];
			} elseif ($f['type'] === $F::RST_STREAM) {
				$got[$id] = array(0, 'reset'); $done[] = $id;
			}
			if (in_array($f['type'], array($F::HEADERS, $F::DATA), true) and ($f['flags'] & $F::FLAG_END_STREAM)) {
				$done[] = $id;
			}
		}
		if ($closed === 'GOAWAY') break;
	}
	$got['closed'] = $closed;
	return $got;
}

function h2Result($got, $id)
{
	return isset($got[$id]) ? ($got[$id][0] ?? 0) . ' ' . trim($got[$id][1] ?? '') : 'no answer';
}

// A quick script and a slow one, together. The quick one's worker is done
// and recycled while the slow one is still running on another.
h2Get($s, $enc, 1, '/index.php?n=1');
h2Get($s, $enc, 3, '/index.php?n=3&sleep=600');
$got = h2Collect($s, $dec, array(1, 3));
check('the quick script is answered', h2Result($got, 1), '200 ok 1');
check('the slow one, still in flight when the quick one\'s worker was recycled, is answered too',
	h2Result($got, 3) . ($got['closed'] ? ' (' . $got['closed'] . ')' : ''), '200 ok 3');

// Same connection, more of the page.
h2Get($s, $enc, 5, '/index.php?n=5');
h2Get($s, $enc, 7, '/style.css');
h2Get($s, $enc, 9, '/index.php?n=9');
$got = h2Collect($s, $dec, array(5, 7, 9));
check('the connection then serves more scripts and a file without being reopened',
	h2Result($got, 5) . ' | ' . h2Result($got, 7) . ' | ' . h2Result($got, 9) . ($got['closed'] ? ' (' . $got['closed'] . ')' : ''),
	'200 ok 5 | 200 body { color: #333 } | 200 ok 9');

fclose($s);
rh_finish();
