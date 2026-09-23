<?php

/**
 * A malformed HTTP/2 frame is refused, not interpreted.
 *
 * HTTP/2 gives an attacker far more to work with than HTTP/1: every frame
 * carries a length and a stream id it chose, and the connection holds state
 * across all of them. RFC 9113 answers each of these cases with a specific
 * error, and the reason is that a peer which guesses instead can be walked into
 * a state its author never considered.
 *
 * The cases here are the ones the RFC calls MUST:
 *
 *   6.1   DATA on stream 0                     PROTOCOL_ERROR
 *   6.2   HEADERS on stream 0                  PROTOCOL_ERROR
 *   5.1.1 an even stream id from a client      PROTOCOL_ERROR
 *   6.5   SETTINGS length not a multiple of 6  FRAME_SIZE_ERROR
 *   6.5   SETTINGS with ACK and a payload      FRAME_SIZE_ERROR
 *   6.7   PING that is not 8 octets            FRAME_SIZE_ERROR
 *   6.9   WINDOW_UPDATE of 0                   PROTOCOL_ERROR
 *   4.2   a frame larger than SETTINGS_MAX_FRAME_SIZE  FRAME_SIZE_ERROR
 *
 * The connection is driven over a socket pair rather than through a running
 * server, which is how the other frame-level tests here work: it is faster, and
 * it lets the reply be read back byte for byte.
 *
 *   php tests/unit-http2-protocol-errors.php
 */

require __DIR__ . '/../src/Q/WebServer/Http2/Hpack.php';
require __DIR__ . '/../src/Q/WebServer/Http2/Frame.php';
require __DIR__ . '/../src/Q/WebServer/Http2/Connection.php';

$F = 'Q_WebServer_Http2_Frame';

$pass = 0;
$fail = 0;

function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what,
		var_export($got, true), var_export($want, true));
}

// RFC 9113 section 7. Only the ones asserted here are named.
$NO_ERROR = 0;
$PROTOCOL_ERROR = 1;
$FRAME_SIZE_ERROR = 6;

/**
 * Feed one connection the preface and then $raw, and report what came back:
 * the error code of a GOAWAY or RST_STREAM, or 'none'.
 */
function reaction($raw, $settings = '')
{
	$F = 'Q_WebServer_Http2_Frame';

	$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
	if (!$pair) return 'no-socketpair';
	list($server, $peer) = $pair;
	stream_set_blocking($server, false);
	stream_set_blocking($peer, false);

	$conn = new Q_WebServer_Http2_Connection($server, function () {
		return array('status' => 200, 'headers' => array(), 'body' => 'ok');
	});
	$conn->start();
	$conn->feed($F::PREFACE . $F::build($F::SETTINGS, 0, 0, $settings));
	$conn->feed($raw);

	$out = '';
	for ($i = 0; $i < 4; ++$i) {
		$chunk = @fread($peer, 65536);
		if ($chunk === false or $chunk === '') break;
		$out .= $chunk;
	}
	@fclose($server);
	@fclose($peer);

	// Walk the frames we were sent and report the first error carried.
	$off = 0;
	$len = strlen($out);
	while ($off + 9 <= $len) {
		$head = substr($out, $off, 9);
		$plen = (ord($head[0]) << 16) | (ord($head[1]) << 8) | ord($head[2]);
		$type = ord($head[3]);
		$payload = substr($out, $off + 9, $plen);
		if ($type === $F::GOAWAY and strlen($payload) >= 8) {
			$bits = unpack('Nlast/Nerr', substr($payload, 0, 8));
			return (int) $bits['err'];
		}
		if ($type === $F::RST_STREAM and strlen($payload) >= 4) {
			$bits = unpack('Nerr', substr($payload, 0, 4));
			return (int) $bits['err'];
		}
		$off += 9 + $plen;
	}
	return 'none';
}

// ── A sound exchange, so a refusal means something ──────────────

$hpack = new Q_WebServer_Http2_Hpack();
$good = $hpack->encode(array(
	array(':method', 'GET'), array(':scheme', 'https'),
	array(':authority', 'a'), array(':path', '/'),
));
check('a well-formed request draws no error',
	reaction($F::build($F::HEADERS, 0x4 | 0x1, 1, $good)), 'none');

// ── Stream identifiers ──────────────────────────────────────────

// 6.1: DATA is always associated with a stream.
check('DATA on stream 0 is a protocol error',
	reaction($F::build($F::DATA, 0, 0, 'x')), $PROTOCOL_ERROR);

// 6.2: so is HEADERS.
check('HEADERS on stream 0 is a protocol error',
	reaction($F::build($F::HEADERS, 0x4 | 0x1, 0, $good)), $PROTOCOL_ERROR);

// 5.1.1: streams a client opens are odd-numbered. An even one is either a
// confusion about who initiates, or an attempt to collide with a stream the
// server believes it owns.
$hpack2 = new Q_WebServer_Http2_Hpack();
$good2 = $hpack2->encode(array(
	array(':method', 'GET'), array(':scheme', 'https'),
	array(':authority', 'a'), array(':path', '/'),
));
check('an even stream id from a client is a protocol error',
	reaction($F::build($F::HEADERS, 0x4 | 0x1, 2, $good2)), $PROTOCOL_ERROR);

// ── Frame sizes the RFC fixes exactly ───────────────────────────

// 6.5: SETTINGS carries whole 6-octet entries and nothing else.
check('SETTINGS whose length is not a multiple of 6 is a size error',
	reaction($F::build($F::SETTINGS, 0, 0, 'abcdefg')), $FRAME_SIZE_ERROR);

// 6.5: an acknowledgement has nothing to say.
check('SETTINGS with ACK and a payload is a size error',
	reaction($F::build($F::SETTINGS, 0x1, 0, 'abcdef')), $FRAME_SIZE_ERROR);

// 6.7: PING is 8 octets, always.
check('a PING that is not 8 octets is a size error',
	reaction($F::build($F::PING, 0, 0, 'short')), $FRAME_SIZE_ERROR);

// 6.9: an increment of zero advances nothing and is therefore an error rather
// than a no-op -- a peer sending it is not doing what it thinks.
check('a WINDOW_UPDATE of zero is a protocol error',
	reaction($F::build($F::WINDOW_UPDATE, 0, 0, pack('N', 0))), $PROTOCOL_ERROR);

// 4.2: a frame larger than the size we advertised. The default maximum is
// 16384, so this is over it by one.
check('a frame larger than the advertised maximum is a size error',
	reaction($F::build($F::DATA, 0, 1, str_repeat('x', 16385))),
	$FRAME_SIZE_ERROR);

// ── Shapes that must not be fatal ───────────────────────────────

// 4.1: an unknown type must be discarded, not treated as an error. This is how
// the protocol is extended, and a peer that rejects what it does not recognise
// cannot be extended at all.
check('an unknown frame type is ignored rather than refused',
	reaction($F::build(0x63, 0, 0, 'whatever')), 'none');

if ($fail) {
	printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail);
	exit(1);
}
printf("  PASS - %d case(s)\n", $pass);
