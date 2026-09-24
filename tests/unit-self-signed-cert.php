<?php

/**
 * The server's own certificate is a certificate, signed with SHA-256.
 *
 * Q_Utils::generateSelfSignedCert() named no digest, so PHP signed with its
 * default, SHA-1. A system crypto policy may refuse that -- RHEL's DEFAULT does,
 * with "invalid digest" -- and then the request and the certificate both failed
 * with warnings, and the method still returned an array: an empty certificate
 * and no fingerprint. Q_WebServer_Identity wrote those to disk and, finding the
 * files on every later start, kept the empty identity for good.
 *
 * Checked here: a real certificate comes back, signed with SHA-256, with a key
 * that belongs to it and a fingerprint of it.
 *
 *   php tests/unit-self-signed-cert.php
 */

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

if (!function_exists('openssl_pkey_new')) { printf("  skip  needs openssl\n"); exit(0); }

require __DIR__ . '/../src/Q/Utils.php';

$warnings = array();
set_error_handler(function ($no, $message) use (&$warnings) {
	$warnings[] = $message;
	return true;
});
$result = Q_Utils::generateSelfSignedCert('qbix-test.local');
restore_error_handler();

check('no warnings while generating', $warnings, array());
check('a result comes back', is_array($result), true);

$cert = is_array($result) ? openssl_x509_read((string) $result['cert']) : false;
check('...holding a certificate that can be read', $cert !== false, true);

$parsed = $cert ? openssl_x509_parse($cert) : array();
check('...for the name asked for', $parsed['subject']['CN'] ?? null, 'qbix-test.local');
check('...signed with SHA-256, not the SHA-1 PHP defaults to',
	$parsed['signatureTypeSN'] ?? null, 'RSA-SHA256');
check('...with a key that belongs to it',
	$cert && openssl_x509_check_private_key($cert, (string) $result['key']), true);
check('...and the fingerprint of that certificate',
	$cert ? $result['fingerprint'] === openssl_x509_fingerprint($cert, 'sha256') : false, true);
check('the fingerprint is a SHA-256 one', (bool) preg_match('/^[0-9a-f]{64}$/', (string) ($result['fingerprint'] ?? '')), true);

if ($fail) {
	printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail);
	exit(1);
}
printf("  PASS - %d case(s)\n", $pass);
