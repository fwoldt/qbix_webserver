<?php

/**
 * A forked worker must not inherit the parent's descriptor table.
 *
 * fork() gives the child everything the parent had open. The worker needs one
 * descriptor -- its half of the socket pair the pool made for it -- and the
 * child branch used to close exactly one: the other half. Everything else came
 * with it:
 *
 *   - the listening socket, the TLS listener, the Unix socket
 *   - every client connection the parent had open at that instant
 *   - the parent's end of every other worker's socket pair
 *
 * So a worker serving one visitor held the socket of another visitor's request
 * and could read from it. No multi-tenancy required; this is true of a single
 * site with one tenant. It is also why a listening socket stayed bound after
 * the parent released it: sixteen other processes were still holding it.
 *
 * Workers never accept -- stream_socket_accept() appears only in Q_WebServer,
 * and the pool dispatches over its socket pair -- so closing the listeners in
 * the child is both safe and required.
 *
 *   php tests/unit-fork-descriptors.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);

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

// Q_WebServer pulls in the world, so the method under test is exercised through
// a copy of its source taken from the file at run time -- it cannot drift.
$src = file_get_contents(__DIR__ . '/../src/Q/WebServer.php');
if (!preg_match('/\n\tstatic function closeInheritedDescriptors\(\).*?\n\t\}\n/s', $src, $m)) {
	fwrite(STDERR, "  FAIL - closeInheritedDescriptors() not found in src/Q/WebServer.php\n");
	exit(1);
}
// ...and the helper it releases each stream through (which keeps TLS streams
// open rather than sending close_notify on the parent's connection).
if (preg_match('/\n\tstatic function releaseInherited\(.*?\n\t\}\n/s', $src, $r)) {
	$m[0] .= $r[0];
}

eval('class T {
	private static $socket = null;
	private static $tlsSocket = null;
	private static $udsSocket = null;
	static $clients = array();
	static $buffers = array();
	static $http2 = array();
	static function seed($s, $t, $u, $c, $h) {
		self::$socket = $s; self::$tlsSocket = $t; self::$udsSocket = $u;
		self::$clients = $c; self::$http2 = $h;
		self::$buffers = array("a" => "leftover bytes");
	}
	static function peek() {
		return array(self::$socket, self::$tlsSocket, self::$udsSocket);
	}
' . $m[0] . ' }');

/** A throwaway pair; returns one end, keeping the other alive in $keep. */
function apair(&$keep)
{
	$p = stream_socket_pair(
		defined('STREAM_PF_UNIX') ? STREAM_PF_UNIX : STREAM_PF_INET,
		STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
	$keep[] = $p[1];
	return $p[0];
}

$keep = array();

// ── Everything a worker must not keep ────────────────────────────────────
$listen = apair($keep);
$tls    = apair($keep);
$uds    = apair($keep);
$c1     = apair($keep);
$c2     = apair($keep);
$h2sock = apair($keep);

$h2 = new stdClass();
$h2->socket = $h2sock;

T::seed($listen, $tls, $uds, array(5 => $c1, 9 => $c2), array(3 => $h2));

check('all six start open', array(
	is_resource($listen), is_resource($tls), is_resource($uds),
	is_resource($c1), is_resource($c2), is_resource($h2sock),
), array(true, true, true, true, true, true));

$closed = T::closeInheritedDescriptors();

check('it reports how many it closed', $closed, 6);
check('the listening socket is closed', is_resource($listen), false);
check('the TLS listener is closed', is_resource($tls), false);
check('the Unix socket is closed', is_resource($uds), false);
check('the first client connection is closed', is_resource($c1), false);
check('the second client connection is closed', is_resource($c2), false);
check('an HTTP/2 connection socket is closed', is_resource($h2sock), false);

// ── And the bookkeeping that would otherwise point at dead handles ───────
check('the listener slots are nulled', T::peek(), array(null, null, null));
check('the client list is emptied', T::$clients, array());
check('read buffers are dropped', T::$buffers, array());
check('the HTTP/2 table is emptied', T::$http2, array());

// ── Idempotent, and safe on a server that never opened anything ──────────
check('calling it twice closes nothing more', T::closeInheritedDescriptors(), 0);

T::seed(null, null, null, array(), array());
check('a server with nothing open closes nothing',
	T::closeInheritedDescriptors(), 0);

// ── It must cope with junk rather than fatal in a freshly forked child ───
T::seed(null, null, null, array(1 => 'not a resource', 2 => null), array(7 => null));
check('non-resources are skipped, not fataled on',
	T::closeInheritedDescriptors(), 0);

$stale = apair($keep);
fclose($stale);
T::seed(null, null, null, array(1 => $stale), array());
check('an already-closed handle is not double-closed',
	T::closeInheritedDescriptors(), 0);

foreach ($keep as $k) { if (is_resource($k)) fclose($k); }

// ── The child branch of forkWorker must actually call it ─────────────────
// A method nothing calls fixes nothing.
$pool = file_get_contents(__DIR__ . '/../src/Q/WebServer/Pool.php');
if (!preg_match('/if \(\$pid === 0\) \{.*?self::childRun/s', $pool, $child)) {
	fwrite(STDERR, "  FAIL - could not find the child branch of forkWorker()\n");
	exit(1);
}
$branch = $child[0];

check('the child closes its own half of the pair',
	strpos($branch, 'fclose($pair[0])') !== false, true);
check('the child closes the other workers\' sockets',
	strpos($branch, "\$this->workers") !== false
	and strpos($branch, 'fclose($other[\'socket\'])') !== false, true);
check('the child closes the listeners and client connections',
	strpos($branch, 'closeInheritedDescriptors') !== false, true);
check('and it does so before running any application code',
	strpos($branch, 'closeInheritedDescriptors') < strpos($branch, 'childRun'), true);

echo "\n";
if ($fail === 0) { printf("  PASS - %d case(s)\n\n", $pass); exit(0); }
printf("  FAIL - %d of %d\n\n", $fail, $pass + $fail);
exit(1);
