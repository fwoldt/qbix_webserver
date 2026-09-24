<?php

/**
 * Every way of handing the server a certificate works, and the wrong ones are
 * refused with a reason.
 *
 * With a real hierarchy (root, intermediate, leaf) made here:
 *   - files: plain PEM is used in place; DER, a separate chain (in the wrong
 *     order), an encrypted key (password, password file, environment) and a
 *     combined key+certificate file are imported, the chain put in order and
 *     the root dropped; a wrong password and a key that fits no certificate
 *     are refused;
 *   - archive: zip, tar.gz and tar.xz, with the pieces under any names and in
 *     sub-directories, a .p12 inside an archive, and member names that try to
 *     climb out of the extraction directory;
 *   - pkcs12: read with its password, refused without it;
 *   - acme: renewal is due at a third of the lifetime left, and when the
 *     domains change.
 *
 *   php tests/unit-certificate-sources.php
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

$base = sys_get_temp_dir() . DS . 'qbix-sources-' . getmypid();
@mkdir("$base/ssl", 0700, true);
Q_Config::set('Q', 'web', 'https', 'selfSigned', 'dir', "$base/ssl");

// ── A root, an intermediate and a leaf ───────────────────────────────────
$sign = function ($cn, $issuerCert, $issuerKey, $ca, $days = 90) {
	$conf = tempnam(sys_get_temp_dir(), 'qbix-t-');
	file_put_contents($conf, "[req]\ndefault_bits=2048\ndistinguished_name=dn\n[dn]\n[ext]\nbasicConstraints=critical,CA:" . ($ca ? 'TRUE' : 'FALSE') . "\n"
		. ($ca ? '' : "subjectAltName=DNS:$cn\n"));
	$opt = array('config' => $conf, 'digest_alg' => 'sha256', 'x509_extensions' => 'ext',
		'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'private_key_bits' => 2048);
	$key = openssl_pkey_new($opt);
	$csr = openssl_csr_new(array('commonName' => $cn), $key, $opt);
	$crt = openssl_csr_sign($csr, $issuerCert, $issuerKey ?: $key, $days, $opt, random_int(1, PHP_INT_MAX));
	openssl_x509_export($crt, $pem); openssl_pkey_export($key, $kp);
	unlink($conf);
	return array($pem, $kp);
};
list($rootPem, $rootKey) = $sign('Test Root', null, null, true, 3650);
list($intPem, $intKey) = $sign('Test Intermediate', $rootPem, $rootKey, true, 1825);
list($leafPem, $leafKey) = $sign('shop.example.test', $intPem, $intKey, false, 90);
$want = array('shop.example.test');
$chainCount = function ($c) { return $c ? count(Q_WebServer_Certificate_Import::certificates($c->certPem)) : null; };

// ── Files ────────────────────────────────────────────────────────────────
$files = new Q_WebServer_Certificate_Source_Files();
file_put_contents("$base/full.pem", $leafPem . $intPem); file_put_contents("$base/key.pem", $leafKey);
check('plain PEM is used where it is', $files->pair(array('cert' => "$base/full.pem", 'key' => "$base/key.pem")), array("$base/full.pem", "$base/key.pem"));

file_put_contents("$base/leaf.der", base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $leafPem)));
file_put_contents("$base/chain.pem", $rootPem . $intPem);   // wrong order, with the root
$p = $files->pair(array('cert' => "$base/leaf.der", 'key' => "$base/key.pem", 'chain' => "$base/chain.pem"), $why);
$c = $p ? Q_WebServer_Certificate::fromFiles($p[0], $p[1]) : null;
check('a DER leaf with a separate, misordered chain is imported', $c ? $c->hosts() : $why, $want);
check('...leaf, then intermediate, the root dropped', $c ? array_map(function ($pem) { return openssl_x509_parse($pem)['subject']['CN']; }, Q_WebServer_Certificate_Import::certificates($c->certPem)) : null,
	array('shop.example.test', 'Test Intermediate'));
check('...into imported/, the key 0600', $p ? array(dirname($p[0]), fileperms($p[1]) & 0777) : null, array("$base/ssl/imported", 0600));

openssl_pkey_export(openssl_pkey_get_private($leafKey), $encrypted, 'sesame');
file_put_contents("$base/enc.key", $encrypted);
check('an encrypted key without its password is refused', ($files->pair(array('cert' => "$base/full.pem", 'key' => "$base/enc.key"), $why) and Q_WebServer_Certs::pairUsable("$base/full.pem", "$base/enc.key")), false);
$p = $files->pair(array('cert' => "$base/full.pem", 'key' => "$base/enc.key", 'keyPassword' => 'sesame'), $why);
check('...and imported with it', $p ? Q_WebServer_Certs::pairUsable($p[0], $p[1]) : $why, true);
file_put_contents("$base/pw.txt", "sesame\n");
$p = $files->pair(array('cert' => "$base/full.pem", 'key' => "$base/enc.key", 'keyPasswordFile' => "$base/pw.txt"), $why);
check('...or with it read from a file', $p ? Q_WebServer_Certs::pairUsable($p[0], $p[1]) : $why, true);
putenv('QBIX_TEST_KEYPW=sesame');
$p = $files->pair(array('cert' => "$base/full.pem", 'key' => "$base/enc.key", 'keyPasswordEnv' => 'QBIX_TEST_KEYPW'), $why);
check('...or from the environment', $p ? Q_WebServer_Certs::pairUsable($p[0], $p[1]) : $why, true);
$why = '';
$files->pair(array('cert' => "$base/full.pem", 'key' => "$base/enc.key", 'keyPassword' => 'wrong'), $why);
check('a wrong password says so', strpos($why, 'password') !== false, true);
file_put_contents("$base/combined.pem", $leafKey . $intPem . $leafPem);
$p = $files->pair(array('cert' => "$base/combined.pem", 'key' => "$base/combined.pem"), $why);
check('one file with the key and the certificates', $p ? $chainCount(Q_WebServer_Certificate::fromFiles($p[0], $p[1])) : $why, 2);
file_put_contents("$base/other.key", $intKey);
$why = '';
Q_WebServer_Certificate_Import::fromMaterial(array($leafPem, $intKey . "\n"), null, $why);
check('a key that belongs to no leaf is refused', $why !== '', true);

// ── Archives ─────────────────────────────────────────────────────────────
$archive = new Q_WebServer_Certificate_Source_Archive();
if (class_exists('ZipArchive')) {
	$z = new ZipArchive(); $z->open("$base/bundle.zip", ZipArchive::CREATE);
	$z->addFromString('certs/www_shop.crt', $leafPem); $z->addFromString('certs/ca-bundle.crt', $intPem . $rootPem);
	$z->addFromString('private/server.key', $leafKey); $z->addFromString('../../escape.txt', 'no');
	$z->close();
	$p = $archive->pair(array('archive' => "$base/bundle.zip"), $why);
	check('a zip with the pieces under any names', $p ? Q_WebServer_Certificate::fromFiles($p[0], $p[1])->hosts() : $why, $want);
	check('...and nothing written outside', is_file(dirname($base) . DS . 'escape.txt'), false);
}
if (class_exists('PharData')) {
	@unlink("$base/bundle.tar"); @unlink("$base/bundle.tar.gz");
	$t = new PharData("$base/bundle.tar");
	$t->addFromString('site/leaf.pem', $leafPem); $t->addFromString('site/int.pem', $intPem); $t->addFromString('site/key', $leafKey);
	$t->compress(Phar::GZ);
	unset($t);
	$p = $archive->pair(array('archive' => "$base/bundle.tar.gz"), $why);
	check('a tar.gz', $p ? Q_WebServer_Certificate::fromFiles($p[0], $p[1])->hosts() : $why, $want);
}
$tar = Q_WebServer_Certificate_Source_Base::tool(array('tar'));
if ($tar) {
	@mkdir("$base/xz/inner", 0700, true);
	file_put_contents("$base/xz/inner/a.crt", $leafPem . $intPem); file_put_contents("$base/xz/inner/a.key", $leafKey);
	Q_WebServer_Certificate_Source_Base::run(array($tar, '-cJf', "$base/bundle.tar.xz", '-C', "$base/xz", 'inner'), $out);
	if (is_file("$base/bundle.tar.xz") and filesize("$base/bundle.tar.xz") > 0) {
		$p = $archive->pair(array('archive' => "$base/bundle.tar.xz"), $why);
		check('a tar.xz, through the tar binary', $p ? Q_WebServer_Certificate::fromFiles($p[0], $p[1])->hosts() : $why, $want);
	} else {
		printf("  skip  tar.xz: %s\n", trim($out));
	}
}

// ── PKCS#12 ──────────────────────────────────────────────────────────────
openssl_pkcs12_export($leafPem, $p12, $leafKey, 'bundlepw', array('extracerts' => array($intPem)));
file_put_contents("$base/site.pfx", $p12);
$pk = new Q_WebServer_Certificate_Source_Pkcs12();
$p = $pk->pair(array('bundle' => "$base/site.pfx", 'password' => 'bundlepw'), $why);
check('a .pfx with its password, chain included', $p ? $chainCount(Q_WebServer_Certificate::fromFiles($p[0], $p[1])) : $why, 2);
check('...refused without it', $pk->pair(array('bundle' => "$base/site.pfx"), $why), null);
if (class_exists('ZipArchive')) {
	$z = new ZipArchive(); $z->open("$base/pfx.zip", ZipArchive::CREATE); $z->addFromString('export/site.pfx', $p12); $z->close();
	$p = $archive->pair(array('archive' => "$base/pfx.zip", 'keyPassword' => 'bundlepw'), $why);
	check('a .pfx inside a zip', $p ? Q_WebServer_Certificate::fromFiles($p[0], $p[1])->hosts() : $why, $want);
}

// ── ACME renewal rule ────────────────────────────────────────────────────
file_put_contents("$base/leaf.pem", $leafPem);
check('90-day certificate, fresh: not due', Q_WebServer_Acme::renewalDue("$base/leaf.pem", "$base/key.pem", $want), null);
list($short) = $sign('shop.example.test', $intPem, $intKey, false, 20);
file_put_contents("$base/short.pem", $short);
check('20 days left of a longer lifetime would be due; of its own 20, not yet', Q_WebServer_Acme::renewalDue("$base/short.pem", "$base/key.pem", $want) !== null, true);
check('different domains: due', Q_WebServer_Acme::renewalDue("$base/leaf.pem", "$base/key.pem", array('shop.example.test', 'new.example.test')), 'the domains changed');
check('the directory shortcuts', array(Q_WebServer_Acme::directoryUrl(null), Q_WebServer_Acme::directoryUrl('letsencrypt-staging')),
	array(Q_WebServer_Acme::LE_DIRECTORY, Q_WebServer_Acme::LE_STAGING));
check('a challenge token is read only from its directory, by a plain name', Q_WebServer_Acme::getChallengeResponse('../../etc/passwd'), null);

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) { ($f->isDir() and !$f->isLink()) ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
rmdir($base);

if ($fail) { printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail); exit(1); }
printf("  PASS - %d case(s)\n", $pass);
