<?php

/**
 * The HTTPS certificate is looked after with nobody restarting anything.
 *
 * Against a real server:
 *   - HTTPS is bound before plain HTTP;
 *   - a certificate replaced on disk is presented to the next connection,
 *     without a restart (it used to be announced and never used);
 *   - a half-renewed pair (a new certificate, the old key) is not used: the
 *     server keeps presenting the previous one, then picks up the fixed pair;
 *   - a configured certificate that goes missing is replaced by a self-signed
 *     one after one interval to settle, and the configured one comes back as
 *     soon as it is usable again;
 *   - a server with no usable certificate at all comes up on a self-signed one
 *     in its ssl directory, instead of without HTTPS.
 * And without a server: an empty identity left by a failed signing is made
 * again, and a good one is never replaced.
 *
 *   php tests/unit-tls-certificate-lifecycle.php
 */

require __DIR__ . '/fixtures/race-harness.php';
rh_require_pool();
if (!function_exists('openssl_pkey_new')) { printf("  skip  needs openssl\n"); exit(0); }
require_once __DIR__ . '/../src/Q.php';
list($base, $root) = rh_setup('tls-lifecycle');
file_put_contents($root . DS . 'index.php', '<?php echo "ok";');

$make = function ($cn) {
	return (new Q_WebServer_Certificate_Provider_OpensslEcdsa())->create(array($cn, '127.0.0.1'), 30);
};
$put = function ($file, $data) {
	file_put_contents("$file.tmp", $data);
	rename("$file.tmp", $file);
};
$cn = function ($port) {
	$ctx = stream_context_create(array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false, 'capture_peer_cert' => true)));
	$s = @stream_socket_client("tls://127.0.0.1:$port", $en, $es, 5, STREAM_CLIENT_CONNECT, $ctx);
	if (!$s) return null;
	$p = stream_context_get_params($s);
	fclose($s);
	$c = isset($p['options']['ssl']['peer_certificate']) ? openssl_x509_parse($p['options']['ssl']['peer_certificate']) : null;
	return $c ? $c['subject']['CN'] : null;
};
$until = function ($port, $want, $seconds) use ($cn) {
	$got = null;
	for ($t = microtime(true); microtime(true) - $t < $seconds; usleep(250000)) {
		if (($got = $cn($port)) === $want) return $got;
	}
	return $got;
};

// ── A configured certificate, renewed in place ───────────────────────────
$a = $make('lifecycle-a');
$put("$base/cert.pem", $a->certPem); $put("$base/key.pem", $a->keyPem);
$tls = rh_free_port();
$GLOBALS['rh_extra_args'] = array("--https-port=$tls");
rh_start('tls', array('Q' => array('web' => array('https' => array('mode' => 'manual',
	'cert' => "$base/cert.pem", 'key' => "$base/key.pem", 'watchInterval' => 1,
	'selfSigned' => array('dir' => "$base/ssl", 'hosts' => array('lifecycle-self', '127.0.0.1')))))), 1);
check('the configured certificate is presented', $until($tls, 'lifecycle-a', 10), 'lifecycle-a');
$log = rh_log('tls');
$https = strpos($log, '[HTTPS] Listening'); $http = strpos($log, '[HTTP] Listening');
check('HTTPS is bound before HTTP', $https !== false and $http !== false and $https < $http, true);

$b = $make('lifecycle-b');
$put("$base/key.pem", $b->keyPem); $put("$base/cert.pem", $b->certPem);
check('a replaced certificate reaches the next connection, no restart', $until($tls, 'lifecycle-b', 8), 'lifecycle-b');

$c = $make('lifecycle-c');
$put("$base/cert.pem", $c->certPem);            // new certificate, key still b's
usleep(1500000);
check('a half-renewed pair is not used', $cn($tls), 'lifecycle-b');
$put("$base/key.pem", $c->keyPem);
check('...and the completed pair is', $until($tls, 'lifecycle-c', 8), 'lifecycle-c');

rename("$base/cert.pem", "$base/cert.pem.away");
check('a configured certificate gone missing is replaced by a self-signed one', $until($tls, 'lifecycle-self', 10), 'lifecycle-self');
check('...kept in the ssl directory, key private', is_file("$base/ssl/self-signed.pem") && (fileperms("$base/ssl/self-signed.key") & 0777) === 0600, true);
rename("$base/cert.pem.away", "$base/cert.pem");
check('...and the configured one comes back when it is usable', $until($tls, 'lifecycle-c', 10), 'lifecycle-c');
rh_stop($GLOBALS['rh']['servers']['tls']);

// ── No usable certificate at all ─────────────────────────────────────────
$tls2 = rh_free_port();
$GLOBALS['rh_extra_args'] = array("--https-port=$tls2");
rh_start('bare', array('Q' => array('web' => array('https' => array('mode' => 'manual',
	'cert' => "$base/none.pem", 'key' => "$base/none.key",
	'selfSigned' => array('dir' => "$base/ssl2", 'hosts' => array('lifecycle-fallback', '127.0.0.1')))))), 1);
check('with no usable certificate, HTTPS comes up on a self-signed one', $until($tls2, 'lifecycle-fallback', 15), 'lifecycle-fallback');
check('...and the console says so on one line', substr_count(rh_log('bare'), 'tls: certificate ready'), 1);
rh_stop($GLOBALS['rh']['servers']['bare']);

// ── Identity ─────────────────────────────────────────────────────────────
if (!defined('APP_DIR')) define('APP_DIR', "$base/app");
@mkdir("$base/app/local", 0700, true);
file_put_contents("$base/app/local/server.crt", '');
file_put_contents("$base/app/local/server.key", '');
file_put_contents("$base/app/local/server.fingerprint", '');
$id = Q_WebServer_Identity::serverIdentity();
check('an empty identity is made again', $id && $id['fingerprint'] !== '' && Q_WebServer_Certificate::fromFiles($id['cert'], $id['key'])->isUsable(), true);
$again = Q_WebServer_Identity::serverIdentity();
check('...and a good one is kept', $again['fingerprint'] ?? null, $id['fingerprint'] ?? false);

rh_finish();
