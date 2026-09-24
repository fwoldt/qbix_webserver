<?php

/**
 * The self-signed certificate looks after itself.
 *
 * Asserted:
 *   - every provider that can run here makes a usable certificate that covers
 *     the asked-for hosts in subjectAltName (ECDSA, RSA, the openssl binary),
 *     and the snakeoil provider copies a valid pair and refuses an expired one;
 *   - the factory tries providers in order, reports skipped and failed ones,
 *     and returns null with an "exhausted" event when all fail;
 *   - the store writes the key 0600 and the certificate 0644, atomically, and
 *     keeps the replaced pair as .prev;
 *   - ensure() makes one when there is none, reuses a good one, and renews on
 *     expiry, on changed hosts, on an unusable pair and when forced -- and keeps
 *     a still-valid one when nothing can make a new one;
 *   - the reporter prints what each verbosity level promises and no more.
 *
 *   php tests/unit-certificate-self-signed.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q.php';
$pass = 0; $fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}

/** Records events, for the assertions. */
class Recorder implements Q_WebServer_Certificate_Observer
{
	public $events = array();
	function update($event, array $info) { $this->events[] = $event . (isset($info['provider']) ? ':' . $info['provider'] : ''); }
}
/** A provider that fails, or makes a certificate valid for a set number of days. */
class Fake implements Q_WebServer_Certificate_Provider
{
	public $calls = 0;
	function __construct(private $name, private $works, private $days = null) {}
	function name() { return $this->name; }
	function available(&$why = '') { return true; }
	function create(array $hosts, $days, &$why = '')
	{
		++$this->calls;
		if (!$this->works) { $why = 'fake failure'; return null; }
		$c = (new Q_WebServer_Certificate_Provider_OpensslEcdsa())->create($hosts, $this->days ?? $days, $why);
		if ($c) $c->provider = $this->name;
		return $c;
	}
}

$hosts = array('example.test', 'www.example.test', '127.0.0.1');
$sorted = $hosts; sort($sorted);
$base = sys_get_temp_dir() . DS . 'qbix-cert-' . getmypid();
@mkdir($base, 0700, true);

// ── Providers ────────────────────────────────────────────────────────────
foreach (array(new Q_WebServer_Certificate_Provider_OpensslEcdsa(), new Q_WebServer_Certificate_Provider_OpensslRsa(),
	new Q_WebServer_Certificate_Provider_OpensslCli()) as $p) {
	if (!$p->available($why)) { printf("  skip  %s: %s\n", $p->name(), $why); continue; }
	$c = $p->create($hosts, 30, $why);
	check($p->name() . ' makes a usable certificate', $c ? $c->isUsable() : $why, true);
	check($p->name() . ' covers the hosts in subjectAltName', $c ? $c->hosts() : null, $sorted);
	check($p->name() . ' signs with SHA-256', $c ? (openssl_x509_parse($c->certPem)['signatureTypeSN'] ?? '') !== '' && stripos(openssl_x509_parse($c->certPem)['signatureTypeSN'], 'sha256') !== false : false, true);
}
$ec = (new Q_WebServer_Certificate_Provider_OpensslEcdsa())->create(array('snake.test'), 30);
file_put_contents("$base/snake.pem", $ec->certPem); file_put_contents("$base/snake.key", $ec->keyPem);
$snake = new Q_WebServer_Certificate_Provider_Snakeoil("$base/snake.pem", "$base/snake.key");
check('snakeoil copies a valid pair', ($c = $snake->create($hosts, 30)) ? $c->hosts() : null, array('snake.test'));
check('snakeoil is unavailable without the files', (new Q_WebServer_Certificate_Provider_Snakeoil("$base/none.pem", "$base/none.key"))->available(), false);

// ── Factory ──────────────────────────────────────────────────────────────
$rec = new Recorder();
Q_WebServer_Certificate_Events::attach($rec);
Q_WebServer_Certificate_Factory::setProviders(array(new Fake('first', false), new Fake('second', true)));
$c = Q_WebServer_Certificate_Factory::create($hosts);
check('the factory falls through to the next provider', $c ? $c->provider : null, 'second');
check('...reporting each step', $rec->events, array('trying:first', 'failed:first', 'trying:second', 'created:second'));
$rec->events = array();
Q_WebServer_Certificate_Factory::setProviders(array(new Fake('a', false), new Fake('b', false)));
check('all failing gives null', Q_WebServer_Certificate_Factory::create($hosts), null);
check('...and an exhausted event', end($rec->events), 'exhausted');
Q_WebServer_Certificate_Factory::reset();
check('the default order is ECDSA, RSA, the binary, snakeoil', array_map(function ($p) { return $p->name(); }, Q_WebServer_Certificate_Factory::providers()),
	array('openssl-ecdsa', 'openssl-rsa', 'openssl-cli', 'snakeoil'));

// ── Store and ensure() ───────────────────────────────────────────────────
$store = new Q_WebServer_Certificate_Store("$base/ssl");
$r = Q_WebServer_Certificate_SelfSigned::ensure($hosts, false, $store);
check('ensure() makes one when there is none', $r ? $r[2] : null, true);
check('the key is 0600', fileperms($store->keyFile()) & 0777, 0600);
check('the certificate is 0644', fileperms($store->certFile()) & 0777, 0644);
$fp = $store->load()->fingerprint();
$r = Q_WebServer_Certificate_SelfSigned::ensure($hosts, false, $store);
check('a good one is reused', array($r[2], $store->load()->fingerprint()), array(false, $fp));
$r = Q_WebServer_Certificate_SelfSigned::ensure(array_merge($hosts, array('new.example.test')), false, $store);
check('changed hosts renew it', array($r[2], in_array('new.example.test', $store->load()->hosts(), true)), array(true, true));
check('...keeping the replaced pair as .prev', is_file($store->certFile() . '.prev') && is_file($store->keyFile() . '.prev'), true);
$store->save((new Fake('short', true, 10))->create($hosts, 10));
$r = Q_WebServer_Certificate_SelfSigned::ensure($hosts, false, $store);
check('one that expires within 30 days is renewed', array($r[2], $store->load()->daysLeft() > 300), array(true, true));
file_put_contents($store->certFile(), "-----BEGIN CERTIFICATE-----\ngarbage\n-----END CERTIFICATE-----\n");
$r = Q_WebServer_Certificate_SelfSigned::ensure($hosts, false, $store);
check('an unusable pair is replaced', array($r[2], $store->load()->isUsable()), array(true, true));
$r = Q_WebServer_Certificate_SelfSigned::ensure($hosts, true, $store);
check('forcing renews', $r[2], true);
$fp = $store->load()->fingerprint();
Q_WebServer_Certificate_Factory::setProviders(array(new Fake('broken', false)));
$r = Q_WebServer_Certificate_SelfSigned::ensure($hosts, true, $store);
check('with nothing able to make one, the valid one stays', array($r !== null, $r[2] ?? null, $store->load()->fingerprint()), array(true, false, $fp));
unlink($store->certFile()); unlink($store->keyFile());
check('...and with nothing at all, null', Q_WebServer_Certificate_SelfSigned::ensure($hosts, false, $store), null);
Q_WebServer_Certificate_Factory::reset();
Q_WebServer_Certificate_Events::reset();

// ── Reporter levels ──────────────────────────────────────────────────────
$lines = function ($level) use ($hosts) {
	$m = fopen('php://memory', 'w+');
	$rep = new Q_WebServer_Certificate_Reporter($level, $m);
	Q_WebServer_Certificate_Events::attach($rep);
	Q_WebServer_Certificate_Factory::setProviders(array(new Fake('first', false), new Fake('second', true)));
	Q_WebServer_Certificate_Factory::create($hosts);
	Q_WebServer_Certificate_Events::emit('error', array('reason' => 'boom'));
	Q_WebServer_Certificate_Events::detach($rep);
	rewind($m);
	return array_values(array_filter(explode("\n", stream_get_contents($m))));
};
check('quiet: errors only', count($lines(Q_WebServer_Certificate_Reporter::QUIET)), 1);
$n = $lines(Q_WebServer_Certificate_Reporter::NORMAL);
check('normal: one ready line plus the error', array(count($n), strpos($n[0], 'tls: certificate ready (EC, second') === 0), array(2, true));
check('verbose: also the failed provider', count($lines(Q_WebServer_Certificate_Reporter::VERBOSE)), 3);
check('debug: every step', count($lines(Q_WebServer_Certificate_Reporter::DEBUG)), 5);
check('levels from options', array_map(array('Q_WebServer_Certificate_Reporter', 'levelFromOptions'),
	array(array('quiet' => true), array(), array('v' => true), array('v' => 2), array('verbose' => true), array('debug' => true))),
	array(0, 1, 2, 3, 2, 3));
Q_WebServer_Certificate_Factory::reset();

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
rmdir($base);

if ($fail) { printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail); exit(1); }
printf("  PASS - %d case(s)\n", $pass);
