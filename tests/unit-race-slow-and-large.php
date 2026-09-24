<?php

/**
 * Slow clients, big requests and big responses do not stall anyone else, and
 * nothing gets lost moving bytes that do not fit a socket buffer.
 *
 * The parent is a single event loop: it reads every client, writes every
 * response, and shuttles requests and responses over each worker's socket
 * pair (Pool::sendTo writes the length-prefixed request with writeFully();
 * Pool::onWorkerData reassembles the response 64 KB at a time). A blocking
 * write or read anywhere in that path turns one slow peer into a stalled
 * server. The races, and how each would fail:
 *
 *   A. Request bodies larger than the socket buffers (4 MB, binary, so the
 *      request travels base64 inside the JSON frame) sent concurrently with
 *      small requests: every body must arrive whole (md5 compared), and the
 *      small requests must not be answered with another request's data.
 *      A short write to a worker counted as complete desynchronises that
 *      worker's stream for every request after it.
 *   B. A client reading a 3 MB response slowly (4 KB every 5 ms) while 60
 *      fast requests run: all fast requests finish long before the slow one
 *      does, and the slow one arrives intact. A parent that blocks writing to
 *      a slow reader stalls every other client.
 *   C. A client sending its request head one byte every 25 ms (slowloris):
 *      60 fast requests meanwhile finish quickly; the slow request is
 *      answered (or refused with a 4xx) -- never a hang.
 *   D. Keep-alive and pipelining with a worker replaced after every request
 *      (ceiling = 1 MB): two requests pipelined on one connection must get
 *      their own responses, in order, or the connection closed after the
 *      first; never the first's body twice or the second's first. (The
 *      server answers the first and closes; the close itself does not reach
 *      the client while the replacement worker lives -- the inherited-socket
 *      defect of unit-race-worker-replacement.php -- so each connection here
 *      is read for at most ~1 s.)
 *
 *   php tests/unit-race-slow-and-large.php
 */

require __DIR__ . '/fixtures/race-harness.php';
rh_require_pool();
list($base, $root) = rh_setup('race-slow');

file_put_contents($root . DS . 'index.php', '<?php
$a = $_GET["a"] ?? "";
$t = preg_replace("/[^a-z0-9]/", "", $_GET["t"] ?? "");
if ($a === "body") { $in = file_get_contents("php://input"); echo $t, " ", strlen($in), " ", md5($in); return; }
if ($a === "big")  { mt_srand(42); $s = ""; for ($i = 0; $i < 3 * 1024; ++$i) $s .= md5((string) mt_rand()) . str_repeat("=", 992); echo $s; return; }
// hold=1 keeps 2 MB for the life of the worker, so D is over its 1 MB ceiling
// whatever the server itself weighs (a fresh worker can be under 1 MB).
if (isset($_GET["hold"])) { if (!class_exists("Held", false)) { class Held { static $d; } } Held::$d = str_repeat("h", 2097152); }
echo "OK ", $t;
');

$port = rh_start('slow', array(), 4);

// ── A. Big bodies, concurrently with small requests ─────────────

$reqs = array(); $want = array();
for ($i = 0; $i < 30; ++$i) {
	$t = 'b' . $i . 'x' . bin2hex(random_bytes(3));
	if ($i % 6 === 0) {
		$body = random_bytes(4 * 1024 * 1024 + $i);
		$reqs[$i] = array('path' => "/index.php?a=body&t=$t", 'method' => 'POST', 'body' => $body);
		$want[$i] = "$t " . strlen($body) . ' ' . md5($body);
	} else {
		$reqs[$i] = array('path' => "/index.php?t=$t");
		$want[$i] = "OK $t";
	}
}
$res = rh_many($port, $reqs, 10, 40.0, null, 2.0);
$bad = array();
foreach ($res as $i => $r) {
	if ($r['status'] !== 200 or $r['body'] !== $want[$i] or rh_transport_error($r)) {
		$bad[] = "$i: {$r['status']} " . rh_short($r['body'], 60) . " {$r['error']}";
	}
}
check('A: 5 x 4 MB binary bodies and 25 small requests, concurrently, each answered with its own data', $bad, array());
rh_note_no_eof('A', $res);
unset($reqs);

// ── B. A slow reader of a big response ──────────────────────────

mt_srand(42); $bigWant = '';
for ($i = 0; $i < 3 * 1024; ++$i) $bigWant .= md5((string) mt_rand()) . str_repeat('=', 992);
$reqs = array(array('path' => '/index.php?a=big&t=slowreader', 'readDelay' => 5000));
for ($i = 1; $i <= 60; ++$i) $reqs[$i] = array('path' => "/index.php?t=fast$i");
$res = rh_many($port, $reqs, 61, 40.0, null, 3.0);
$fastBad = 0; $fastMax = 0;
for ($i = 1; $i <= 60; ++$i) {
	if ($res[$i]['status'] !== 200 or $res[$i]['body'] !== "OK fast$i") ++$fastBad;
	$fastMax = max($fastMax, $res[$i]['ms']);
}
check('B: the slow reader gets the whole 3 MB response, intact',
	array($res[0]['status'], strlen($res[0]['body']), md5($res[0]['body']) === md5($bigWant)),
	array(200, strlen($bigWant), true));
check('B: all 60 fast requests are answered correctly meanwhile', $fastBad, 0);
check(sprintf('B: ...and none waited on the slow reader (slowest fast %.0f ms, slow reader %.0f ms)',
	$fastMax, $res[0]['ms']), $fastMax < 1500 and $fastMax < $res[0]['ms'] / 2, true);
rh_note_no_eof('B', $res);

// ── C. A client trickling its request head ──────────────────────

$reqs = array(array('path' => '/index.php?t=trickle', 'writeDelay' => 25000));
for ($i = 1; $i <= 60; ++$i) $reqs[$i] = array('path' => "/index.php?t=quick$i");
$res = rh_many($port, $reqs, 61, 40.0, null, 3.0);
$quickBad = 0; $quickMax = 0;
for ($i = 1; $i <= 60; ++$i) {
	if ($res[$i]['status'] !== 200 or $res[$i]['body'] !== "OK quick$i") ++$quickBad;
	$quickMax = max($quickMax, $res[$i]['ms']);
}
check('C: all 60 quick requests are answered while a client trickles its head', $quickBad, 0);
check(sprintf('C: ...without waiting for it (slowest quick %.0f ms)', $quickMax), $quickMax < 1500, true);
$slow = $res[0];
check(sprintf('C: the trickling client is answered, not left hanging (%s after %.0f ms)',
	$slow['status'] ?: $slow['error'], $slow['ms']),
	$slow['status'] === 200 and $slow['body'] === 'OK trickle'
	or ($slow['status'] >= 400 and $slow['status'] < 500), true);

// ── D. Pipelining / keep-alive with a worker replaced each time ─

$cport = rh_start('ceil', array('Q' => array('webserver' => array('workerMemoryCeiling' => 1))), 2);
$ok = 0; $wrong = array();
for ($n = 0; $n < 10; ++$n) {
	$s = stream_socket_client("tcp://127.0.0.1:$cport", $en, $es, 5);
	stream_set_timeout($s, 1);
	fwrite($s, "GET /index.php?hold=1&t=pa$n HTTP/1.1\r\nHost: x\r\nConnection: keep-alive\r\n\r\n"
		. "GET /index.php?hold=1&t=pb$n HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");
	$raw = ''; $until = microtime(true) + 4;
	while (!feof($s) and microtime(true) < $until) {
		$c = fread($s, 65536);
		if ($c === '' or $c === false) { $m = stream_get_meta_data($s); if ($m['timed_out']) break; usleep(5000); continue; }
		$raw .= $c;
		// Stop once both responses (or one plus close) are in.
		if (substr_count($raw, "OK p") >= 2) break;
	}
	fclose($s);
	preg_match_all('/OK (p[ab]\d+)/', $raw, $m);
	$got = $m[1];
	if ($got === array("pa$n", "pb$n") or $got === array("pa$n")) ++$ok;
	else $wrong[] = implode(',', $got) ?: rh_short($raw, 80);
}
check('D: pipelined requests over a replaced worker get their own responses, in order (or a close after the first)',
	$wrong, array());

rh_finish();
