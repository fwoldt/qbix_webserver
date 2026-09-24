<?php

/**
 * The server's admin surface answers only whom it should.
 *
 * Found by a security audit: /Q/phpinfo printed the server process's
 * environment to anyone, /Q/stats and /Q/metrics had no check at all, and
 * the dashboard -- with every visitor's paths and session-id prefixes -- was
 * open whenever no panel password had been set, which is every fresh install.
 * Anyone could also POST /Q/cluster/join and become a "peer" the server then
 * contacts and replicates events to.
 *
 * The rule (Q_WebServer::adminAllowed()):
 *   - this machine: always, except /Q/phpinfo once a credential is configured;
 *   - elsewhere: the credential (Q.dashboard.token or a panel session) when one
 *     is configured; without one, only with Q.dashboard.remote, and never
 *     /Q/phpinfo;
 *   - /Q/health answers everyone "ok", and its stats only to an admin.
 *
 * A remote client is simulated from 127.0.0.1 by trusting it as a proxy and
 * sending X-Forwarded-For with a public address.
 *
 *   php tests/unit-admin-surface-access.php
 */

require __DIR__ . '/fixtures/race-harness.php';
list($base, $root) = rh_setup('admin-surface');
file_put_contents($root . DS . 'index.php', '<?php echo "app";');

function req($port, $path, $remote, $headers = array(), $method = 'GET', $body = '')
{
	if ($remote) $headers['X-Forwarded-For'] = '203.0.113.9';
	return rh_get($port, $path, 15, $method, $body, $headers);
}

$proxy = array('proxy' => array('trusted' => array('127.0.0.1', '::1')));
$admin = array('/Q/dashboard', '/Q/stats', '/Q/metrics', '/Q/attestation');

// ── No credential, remote not allowed (the default) ─────────────────────

$port = rh_start('default', array('Q' => array('webserver' => $proxy)), 1);
foreach (array_merge($admin, array('/Q/phpinfo')) as $p) {
	check("default: remote $p is refused", req($port, $p, true)['status'], 403);
}
$h = req($port, '/Q/health', true);
check('default: remote /Q/health says ok', $h['status'] . ' ' . $h['body'], '200 {"status":"ok"}');
$r = req($port, '/Q/dashboard', false);
check('default: this machine still gets the dashboard', $r['status'], 200);
$r = req($port, '/Q/stats', false);
check('default: ...and the stats', $r['status'] === 200 and strpos($r['body'], '"requests"') !== false, true);
$r = req($port, '/Q/health', false);
check('default: ...and the full health', strpos($r['body'], '"uptime"') !== false, true);
$r = req($port, '/Q/phpinfo', false);
check('default: ...and phpinfo, with no credential configured', $r['status'], 200);
$ws = req($port, '/Q/ws', true, array('Upgrade' => 'websocket', 'Connection' => 'Upgrade',
	'Sec-WebSocket-Key' => base64_encode(random_bytes(16)), 'Sec-WebSocket-Version' => '13'));
check('default: a remote dashboard WebSocket is refused', $ws['status'], 403);

// ── Remote allowed explicitly, still no credential ──────────────────────

$port = rh_start('remote', array('Q' => array('webserver' => $proxy,
	'dashboard' => array('remote' => true))), 1);
check('remote on: the dashboard answers remotely', req($port, '/Q/dashboard', true)['status'], 200);
check('remote on: ...and the stats', req($port, '/Q/stats', true)['status'], 200);
check('remote on: phpinfo is still refused without a credential', req($port, '/Q/phpinfo', true)['status'], 403);

// ── A token configured ──────────────────────────────────────────────────

$tok = 'tok-' . bin2hex(random_bytes(8));
$port = rh_start('token', array('Q' => array('webserver' => $proxy,
	'dashboard' => array('token' => $tok, 'remote' => true))), 1);
check('token: remote without it is refused', req($port, '/Q/stats', true)['status'], 403);
check('token: ...even with remote on', req($port, '/Q/dashboard', true)['status'], 403);
check('token: a wrong token is refused', req($port, '/Q/stats?token=nope', true)['status'], 403);
check('token: ?token= is accepted', req($port, "/Q/stats?token=$tok", true)['status'], 200);
check('token: Authorization: Bearer is accepted',
	req($port, '/Q/metrics', true, array('Authorization' => "Bearer $tok"))['status'] !== 403, true);
check('token: remote phpinfo with the token', req($port, "/Q/phpinfo?token=$tok", true)['status'], 200);
check('token: local phpinfo needs it too, once configured', req($port, '/Q/phpinfo', false)['status'], 403);
check('token: local stats do not', req($port, '/Q/stats', false)['status'], 200);

// ── Cluster joins ───────────────────────────────────────────────────────

$peer = 'http://127.0.0.1:9';   // configured, never answers
$port = rh_start('cluster', array('Q' => array('webserver' => $proxy,
	'cluster' => array('peers' => array($peer), 'heartbeat' => 60))), 1);
$j = req($port, '/Q/cluster/join', true, array('Content-Type' => 'application/json'), 'POST',
	json_encode(array('url' => 'http://evil.example:8080')));
check('cluster: an unknown URL cannot join', $j['status'], 403);
$j = req($port, '/Q/cluster/join', true, array('Content-Type' => 'application/json'), 'POST',
	json_encode(array('url' => $peer)));
check('cluster: a configured peer can', $j['status'], 200);

$secret = 's-' . bin2hex(random_bytes(8));
$port = rh_start('cluster-secret', array('Q' => array('webserver' => $proxy,
	'cluster' => array('peers' => array($peer), 'heartbeat' => 60, 'secret' => $secret))), 1);
$body = json_encode(array('url' => 'http://10.0.0.7:8080'));
check('cluster secret: a join without it is refused',
	req($port, '/Q/cluster/join', true, array('Content-Type' => 'application/json'), 'POST', $body)['status'], 403);
check('cluster secret: a wrong one is refused',
	req($port, '/Q/cluster/join', true, array('Content-Type' => 'application/json', 'X-Q-Cluster-Secret' => 'x'), 'POST', $body)['status'], 403);
check('cluster secret: the right one joins a new peer',
	req($port, '/Q/cluster/join', true, array('Content-Type' => 'application/json', 'X-Q-Cluster-Secret' => $secret), 'POST', $body)['status'], 200);

rh_finish();
