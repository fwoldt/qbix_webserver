#!/usr/bin/env php
<?php
/**
 * The panel's domains: the record in the store, the status gate, and the
 * usage view that says which names are actually in use.
 *
 *   - records: panel records over config ones, status defaulted, unknown
 *     fields kept on update, invalid names refused;
 *   - the gate: active passes, suspended 503 + Retry-After, disabled 404,
 *     aliases gated too, /Q/ and /.well-known/ never gated, Host normalised;
 *   - usage: listeners, certificate names and expiry, Host headers seen,
 *     site files, records and a provider, with the four states;
 *   - the app host map reader (text only, an empty Name[] clears);
 *   - the panel API: status needs confirm (409), remove needs confirm;
 *   - a real server answering a suspended host with 503 and an active one.
 *
 *   php tests/unit-domains.php
 */
if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q.php';
require_once __DIR__ . '/../src/Q/WebServer/Layout.php';
require_once __DIR__ . '/../src/Q/WebServer/Panel.php';
require_once __DIR__ . '/../src/Q/WebServer/Panel/Store.php';
require_once __DIR__ . '/../src/Q/WebServer/Domains.php';
require_once __DIR__ . '/../src/Q/WebServer/DomainUsage.php';
require_once __DIR__ . '/../src/Q/WebServer/Distribution/Vc/HostMap.php';

$pass = 0; $fail = 0;
function ck($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}
if (!function_exists('posix_geteuid') or posix_geteuid() !== 0) { echo "  skip  needs root for the panel store's ownership rule\n"; exit(0); }

$D = 'Q_WebServer_Domains';
$base = sys_get_temp_dir() . DS . 'qbix-domains-' . getmypid();
@mkdir($base, 0755, true);
chmod($base, 0755);
$acl = "$base/acl"; $sessions = "$base/sessions";
Q_Config::set('Q', 'panel', 'aclDir', $acl);
Q_Config::set('Q', 'panel', 'sessionsDir', $sessions);
Q_Config::set('Q', 'webserver', 'domains', array('config.test' => array('root' => '/srv/x')));
Q_WebServer_Panel_Store::reset();
Q_WebServer_Panel_Store::dirTrusted($acl, true, $why);
Q_WebServer_Panel_Store::dirTrusted($sessions, true, $why);

// ── Records ─────────────────────────────────────────────────────────────
ck('an invalid name is refused', $D::update('bad name', function ($r) { return $r; }), false);
ck('a record is created with a status', $D::setStatus('Shop.Example.TEST', 'suspended', 'maintenance'), true);
Q_WebServer_Panel_Store::aclUpdate(function ($a) { $a['domains']['shop.example.test']['future'] = 'kept'; return $a; });
$D::update('shop.example.test', function ($r) { $r['aliases'] = array('www.shop.example.test'); return $r; });
$recs = $D::records();
ck('the name is stored lowercased', isset($recs['shop.example.test']), true);
ck('status and note are kept', array($recs['shop.example.test']['status'], $recs['shop.example.test']['note']), array('suspended', 'maintenance'));
ck('an unknown field survives an update', $recs['shop.example.test']['future'] ?? null, 'kept');
ck('config records are listed, read-only, active', array($recs['config.test']['source'], $recs['config.test']['status']), array('config', 'active'));
ck('an unknown status reads as active', (function () use ($D) {
	Q_WebServer_Panel_Store::aclUpdate(function ($a) { $a['domains']['odd.test'] = array('status' => 'weird'); return $a; });
	$D::forget();
	return $D::records()['odd.test']['status'];
})(), 'active');
ck('setStatus refuses an unknown status', $D::setStatus('odd.test', 'gone'), false);
$D::setStatus('off.test', 'disabled');
$D::setStatus('live.test', 'active');

// ── The gate ────────────────────────────────────────────────────────────
$req = function ($host, $path = '/') { return array('headers' => array('host' => $host), 'path' => $path); };
ck('an active host passes', $D::gate($req('live.test')), null);
ck('an unknown host passes', $D::gate($req('nobody.test')), null);
$g = $D::gate($req('SHOP.example.test:8443', '/cart'));
ck('a suspended host answers 503 (case and port ignored)', $g['status'] ?? null, 503);
ck('...with Retry-After and no-store', array($g['headers']['Retry-After'] ?? null, $g['headers']['Cache-Control'] ?? null), array('3600', 'no-store'));
ck('...and says so', strpos($g['body'] ?? '', 'temporarily suspended') !== false, true);
ck('an alias of a suspended domain is gated too', $D::gate($req('www.shop.example.test'))['status'] ?? null, 503);
ck('a disabled host answers 404', $D::gate($req('off.test'))['status'] ?? null, 404);
ck('/Q/ is never gated (the panel stays reachable)', $D::gate($req('shop.example.test', '/Q/panel')), null);
ck('/.well-known/ is never gated (renewals keep working)', $D::gate($req('shop.example.test', '/.well-known/acme-challenge/x')), null);
ck('no Host, no gate', $D::gate(array('headers' => array(), 'path' => '/')), null);

// ── Hosts seen ──────────────────────────────────────────────────────────
$D::reset();
$D::gate($req('live.test')); $D::gate($req('LIVE.test:80')); $D::gate($req('stray.test'));
$seen = $D::seen();
ck('Host headers are counted, normalised', array($seen['live.test']['count'] ?? 0, $seen['stray.test']['count'] ?? 0), array(2, 1));

// ── The app host map ───────────────────────────────────────────────────
$app = "$base/app";
mkdir("$app/lib", 0755, true); mkdir("$app/settings/override", 0755, true);
file_put_contents("$app/lib/version.php", "<?php\n");
file_put_contents("$app/settings/site.ini", "[SiteSettings]\nSiteURL=http://www.cms.test/\n[SiteAccessSettings]\nHostMatchMapItems[]=old.test;site\nHostMatchMapItems[]\nHostMatchMapItems[]=www.cms.test;site\nHostUriMatchMapItems[]=cms.test;shop;shop\n");
file_put_contents("$app/settings/override/site.ini.append.php", "<?php /* #?ini charset=\"utf-8\"?\n[SiteAccessSettings]\nHostMatchMapItems[]=admin.cms.test;admin\n*/ ?>\n");
$hm = Q_WebServer_Distribution_Vc_HostMap::hosts($app);
$names = array_column($hm, 'host');
ck('the host map reads host matches, uri matches and SiteURL', $names, array('www.cms.test', 'admin.cms.test', 'cms.test', 'www.cms.test'));
ck('an empty Name[] clears what came before', in_array('old.test', $names, true), false);
ck('a directory that is not the application gives nothing', Q_WebServer_Distribution_Vc_HostMap::hosts($base), array());

// ── Usage ──────────────────────────────────────────────────────────────
$cfg = "$base/openssl.cnf";
file_put_contents($cfg, "[req]\ndistinguished_name=dn\n[dn]\n[san]\nsubjectAltName=DNS:live.test,DNS:*.cms.test,DNS:unused.test\n");
$key = openssl_pkey_new(array('private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'config' => $cfg));
$csr = openssl_csr_new(array('commonName' => 'live.test'), $key, array('config' => $cfg, 'req_extensions' => 'san', 'digest_alg' => 'sha256'));
$crt = openssl_csr_sign($csr, null, $key, 30, array('config' => $cfg, 'x509_extensions' => 'san', 'digest_alg' => 'sha256'));
openssl_x509_export($crt, $pem);
file_put_contents("$base/cert.pem", $pem);
mkdir("$base/conf/sites-enabled", 0755, true);
file_put_contents("$base/conf/sites-enabled/site.example.test.conf", "{}");
Q_WebServer_DomainUsage::addProvider('app host map', function () use ($app) { return Q_WebServer_Distribution_Vc_HostMap::hosts($app); });
Q_WebServer_DomainUsage::addProvider('broken', function () { throw new \RuntimeException('no'); });
$u = Q_WebServer_DomainUsage::collect(array('certFile' => "$base/cert.pem", 'confDir' => "$base/conf"));
$by = array();
foreach ($u['hosts'] as $h) $by[$h['host']] = $h;
ck('the certificate names are read', $u['certificate']['names'], array('live.test', '*.cms.test', 'unused.test'));
ck('...and its days left', in_array($u['certificate']['daysLeft'], array(29, 30), true), true);
ck('a wildcard name is not listed as a host', isset($by['*.cms.test']), false);
ck('live.test: serving and covered', $by['live.test']['states'], array('serving', 'covered'));
ck('www.cms.test: covered by the wildcard, configured by the host map, unseen', $by['www.cms.test']['states'], array('covered', 'unseen'));
ck('stray.test: seen but configured by nothing', $by['stray.test']['states'], array('serving', 'unconfigured'));
ck('unused.test: only on the certificate, never seen', $by['unused.test']['states'], array('covered'));
ck('a site file names a host', in_array('site file', array_column($by['site.example.test']['sources'], 'source'), true), true);
ck('records carry their status into the view', array($by['shop.example.test']['status'], $by['www.shop.example.test']['record']), array('suspended', 'shop.example.test'));
ck('a broken provider costs only its own rows', isset($by['admin.cms.test']), true);
ck('Host counts reach the view', $by['live.test']['seen']['count'], 2);

// ── The panel API ──────────────────────────────────────────────────────
$post = function ($body) { return array('body' => json_encode($body)); };
ck('status without confirm is refused with 409', Q_WebServer_Panel::apiDomainStatus($post(array('domain' => 'live.test', 'status' => 'suspended')))['status'] ?? null, 409);
ck('an unknown status is refused with 400', Q_WebServer_Panel::apiDomainStatus($post(array('domain' => 'live.test', 'status' => 'gone', 'confirm' => true)))['status'] ?? null, 400);
ck('an invalid name is refused with 400', Q_WebServer_Panel::apiDomainStatus($post(array('domain' => 'x y', 'status' => 'active')))['status'] ?? null, 400);
ck('back to active needs no confirm', Q_WebServer_Panel::apiDomainStatus($post(array('domain' => 'off.test', 'status' => 'active'))), array('domain' => 'off.test', 'status' => 'active'));
ck('suspend with confirm', Q_WebServer_Panel::apiDomainStatus($post(array('domain' => 'live.test', 'status' => 'suspended', 'confirm' => true))), array('domain' => 'live.test', 'status' => 'suspended'));
ck('remove without confirm is refused with 409', Q_WebServer_Panel::apiRemoveDomain($post(array('domain' => 'odd.test')))['status'] ?? null, 409);
ck('remove with confirm', Q_WebServer_Panel::apiRemoveDomain($post(array('domain' => 'odd.test', 'confirm' => true))), array('removed' => 'odd.test'));
$D::forget();
ck('...and it is gone', isset($D::records()['odd.test']), false);
ck('the list shows status and source', (function () {
	foreach (Q_WebServer_Panel::apiListDomains()['domains'] as $d) if ($d['domain'] === 'live.test') return array($d['status'], $d['source']);
	return null;
})(), array('suspended', 'panel'));

// ── A real server ──────────────────────────────────────────────────────
/** GET $path from $port with this Host, over a raw socket: array(status, body). */
function rawGet($port, $host, $path)
{
	$s = @stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 5);
	if (!$s) return array(0, '');
	stream_set_timeout($s, 10);
	fwrite($s, "GET $path HTTP/1.1\r\nHost: $host\r\nConnection: close\r\n\r\n");
	$raw = stream_get_contents($s);
	fclose($s);
	$status = preg_match('#^HTTP/1\.[01] (\d{3})#', (string) $raw, $m) ? (int) $m[1] : 0;
	$parts = explode("\r\n\r\n", (string) $raw, 2);
	$body = $parts[1] ?? '';
	if (stripos($parts[0], 'transfer-encoding: chunked') !== false) {
		$out = ''; $rest = $body;
		while (preg_match('/^([0-9a-f]+)\r\n/i', $rest, $cm)) {
			$len = hexdec($cm[1]); if ($len === 0) break;
			$out .= substr($rest, strlen($cm[0]), $len);
			$rest = substr($rest, strlen($cm[0]) + $len + 2);
		}
		$body = $out;
	}
	return array($status, $body);
}
if (function_exists('pcntl_fork')) {
	require_once __DIR__ . '/fixtures/race-harness.php';
	list($rbase, $root) = rh_setup('domains');
	file_put_contents($root . DS . 'index.php', '<?php echo "SITE-OK";');
	$port = rh_start('dom', array('Q' => array('panel' => array('aclDir' => $acl, 'sessionsDir' => $sessions))), 2);
	list($st) = rawGet($port, 'live.test', '/');
	ck('the server answers a suspended host with 503', $st, 503);
	list($st, $body) = rawGet($port, 'off.test', '/');
	ck('...and an active one with the site', array($st, trim($body)), array(200, 'SITE-OK'));
	list($st) = rawGet($port, 'live.test', '/Q/health');
	ck('...while its /Q/ paths still answer', $st !== 503 && $st !== 0, true);
	rh_stop($GLOBALS['rh']['servers']['dom']);
}

printf("%s - %d case(s)%s\n", $fail ? '  FAIL' : '  PASS', $pass + $fail, $fail ? " ($fail failed)" : '');
exit($fail ? 1 : 0);
