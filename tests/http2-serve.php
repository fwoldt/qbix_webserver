<?php

/**
 * A real HTTP/2 server, small enough to read in one sitting.
 *
 * The point of this file is that the frame layer and the connection state
 * machine are exercised by an actual client speaking actual HTTP/2 over TLS,
 * rather than by a test that feeds them bytes someone wrote down. curl and a
 * browser disagree with a specification in different places, and only a client
 * finds that out.
 *
 *   php tests/http2-serve.php --port=8099 --cert=/path/fullchain.pem --key=/path/privkey.pem
 *   curl --http2 -k https://127.0.0.1:8099/hello
 *
 * It answers every request with a short page describing what it received, so a
 * failure in header decoding is visible in the response rather than silent.
 *
 * Single connection at a time, blocking reads: this is a proving ground, not
 * the server. The integration into the event loop is the next wave.
 */

require __DIR__ . '/../src/Q/WebServer/Http2/Hpack.php';
require __DIR__ . '/../src/Q/WebServer/Http2/Frame.php';
require __DIR__ . '/../src/Q/WebServer/Http2/Connection.php';

$opts = getopt('', array('port:', 'cert:', 'key:', 'host:'));
$port = isset($opts['port']) ? (int) $opts['port'] : 8099;
$host = isset($opts['host']) ? $opts['host'] : '127.0.0.1';
$cert = isset($opts['cert']) ? $opts['cert'] : '';
$key  = isset($opts['key']) ? $opts['key'] : '';

if (!$cert or !is_file($cert)) {
	fwrite(STDERR, "  need --cert=<fullchain.pem> (HTTP/2 to a browser is TLS only,\n");
	fwrite(STDERR, "  because the protocol is chosen by ALPN during the handshake)\n");
	exit(2);
}

$ssl = array(
	'local_cert' => $cert,
	'verify_peer' => false,
	'verify_peer_name' => false,
	// This single line is what makes h2 possible at all: without it a browser
	// negotiates http/1.1 and never offers HTTP/2, whatever the server can do.
	'alpn_protocols' => 'h2,http/1.1',
);
if ($key) $ssl['local_pk'] = $key;

$ctx = stream_context_create(array('ssl' => $ssl));
$server = @stream_socket_server(
	"tcp://$host:$port", $errno, $errstr,
	STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx
);
if (!$server) {
	fwrite(STDERR, "  cannot listen on $host:$port -- $errstr ($errno)\n");
	exit(1);
}

fwrite(STDERR, "  listening on https://$host:$port  (ALPN offers h2, http/1.1)\n");

while (true) {
	$client = @stream_socket_accept($server, -1);
	if (!$client) continue;

	stream_set_blocking($client, true);
	$ok = @stream_socket_enable_crypto(
		$client, true, STREAM_CRYPTO_METHOD_TLS_SERVER
	);
	if ($ok !== true) { @fclose($client); continue; }

	$meta = stream_get_meta_data($client);
	$alpn = isset($meta['crypto']['alpn_protocol']) ? $meta['crypto']['alpn_protocol'] : '';
	fwrite(STDERR, "  connection: ALPN negotiated '" . ($alpn ?: '(none)') . "'\n");

	if ($alpn !== 'h2') {
		// The client did not want HTTP/2. Say something in the protocol it
		// does speak rather than leaving it waiting.
		$body = "ALPN negotiated '" . ($alpn ?: 'none') . "', not h2\n";
		@fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\n"
			. "Content-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
		@fclose($client);
		continue;
	}

	$requests = 0;
	$conn = new Q_WebServer_Http2_Connection($client, function ($request) use (&$requests) {
		$requests++;

		$lines = array(
			'HTTP/2 request received by the qbix frame layer',
			'',
			'  stream    ' . $request['stream'],
			'  method    ' . $request['method'],
			'  path      ' . $request['path'],
			'  scheme    ' . $request['scheme'],
			'  authority ' . $request['authority'],
			'  body      ' . strlen($request['body']) . ' byte(s)',
			'',
			'  headers, as decoded by HPACK:',
		);
		foreach ($request['headers'] as $k => $v) {
			$lines[] = sprintf('    %-20s %s', $k, $v);
		}
		$lines[] = '';
		$lines[] = '  requests on this connection so far: ' . $requests;

		$body = implode("\n", $lines) . "\n";

		return array(
			'status' => 200,
			'headers' => array(
				'content-type' => 'text/plain; charset=utf-8',
				'content-length' => (string) strlen($body),
				'x-served-by' => 'qbix-http2',
			),
			'body' => $body,
		);
	});

	$conn->start();

	while (is_resource($client) and !feof($client)) {
		$data = @fread($client, 65536);
		if ($data === false or $data === '') break;
		if (!$conn->feed($data)) break;
	}

	fwrite(STDERR, "  connection closed after $requests request(s)\n");
	@fclose($client);
}
