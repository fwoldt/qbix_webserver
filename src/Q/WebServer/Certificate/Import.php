<?php
/**
 * @module Q
 */

/**
 * Turns the certificate material people actually have into one usable pair:
 * the certificate that matches the key, followed by its chain in order, and
 * the key unencrypted -- as the TLS listener needs it.
 *
 * Accepted, in any mix:
 *   - PEM certificates, one or several in a file (leaf, chain, or both);
 *   - DER certificates (a .cer/.crt/.der as Windows and some CAs hand out);
 *   - PKCS#7 bundles (.p7b/.p7c), PEM or DER;
 *   - PEM private keys of every kind (PKCS#8, RSA, EC), encrypted or not,
 *     and DER keys;
 *   - a combined PEM holding both the key and the certificates.
 *
 * Nothing is trusted by name: which piece is the key, which certificate is
 * the leaf and which are the chain is worked out from the contents.
 *
 * @class Q_WebServer_Certificate_Import
 * @static
 */
class Q_WebServer_Certificate_Import
{
	/**
	 * Build a pair from pieces of material.
	 * @method fromMaterial
	 * @static
	 * @param {array} $blobs raw file contents, any order
	 * @param {string|null} $keyPassword for an encrypted key
	 * @param {string} $why the reason, when null is returned
	 * @return {Q_WebServer_Certificate|null}
	 */
	static function fromMaterial(array $blobs, $keyPassword = null, &$why = '')
	{
		$certs = array();
		$keys = array();
		foreach ($blobs as $blob) {
			$blob = (string) $blob;
			if ($blob === '') continue;
			foreach (self::certificates($blob) as $pem) $certs[self::fingerprint($pem)] = $pem;
			foreach (self::privateKeys($blob, $keyPassword) as $pem) $keys[] = $pem;
		}
		if (!$certs) { $why = 'no certificate found'; return null; }
		if (!$keys) { $why = $keyPassword === null ? 'no private key found (is it encrypted? give its password)' : 'no private key found, or the password is wrong'; return null; }
		foreach ($keys as $key) {
			foreach ($certs as $fp => $leaf) {
				if (!@openssl_x509_check_private_key($leaf, $key)) continue;
				$rest = $certs;
				unset($rest[$fp]);
				$chain = self::orderChain($leaf, $rest);
				$c = new Q_WebServer_Certificate($leaf . implode('', $chain), $key);
				if (!$c->isUsable()) { $why = 'the certificate matching the key has expired or is not yet valid'; return null; }
				return $c;
			}
		}
		$why = 'none of the certificates belongs to the private key';
		return null;
	}

	/**
	 * Every certificate in a blob, as PEM, in the order found.
	 * @return {array}
	 */
	static function certificates($blob)
	{
		$out = array();
		if (preg_match_all('/-----BEGIN (?:X509 |TRUSTED )?CERTIFICATE-----.+?-----END (?:X509 |TRUSTED )?CERTIFICATE-----/s', $blob, $m)) {
			foreach ($m[0] as $pem) {
				$pem = preg_replace('/(BEGIN|END) (?:X509 |TRUSTED )CERTIFICATE/', '$1 CERTIFICATE', $pem);
				if (@openssl_x509_read($pem)) $out[] = self::normalize($pem);
			}
		}
		if (strpos($blob, '-----BEGIN PKCS7-----') !== false or (!$out and strpos($blob, '-----BEGIN') === false)) {
			foreach (self::pkcs7($blob) as $pem) $out[] = $pem;
		}
		if (!$out and strpos($blob, '-----BEGIN') === false) {
			// DER: one certificate, no armour.
			$pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($blob), 64, "\n") . "-----END CERTIFICATE-----\n";
			if (@openssl_x509_read($pem)) $out[] = $pem;
		}
		return $out;
	}

	/**
	 * Every private key in a blob, as unencrypted PEM.
	 * @return {array}
	 */
	static function privateKeys($blob, $password = null)
	{
		$out = array();
		if (preg_match_all('/-----BEGIN ((?:ENCRYPTED |RSA |EC |DSA )?PRIVATE KEY)-----.+?-----END \1-----/s', $blob, $m)) {
			foreach ($m[0] as $pem) {
				$k = @openssl_pkey_get_private($pem, (string) $password);
				if ($k and @openssl_pkey_export($k, $plain)) $out[] = $plain;
			}
		} elseif (strpos($blob, '-----BEGIN') === false and !self::certificates($blob)) {
			foreach (array('PRIVATE KEY', 'RSA PRIVATE KEY', 'EC PRIVATE KEY') as $label) {
				$pem = "-----BEGIN $label-----\n" . chunk_split(base64_encode($blob), 64, "\n") . "-----END $label-----\n";
				$k = @openssl_pkey_get_private($pem, (string) $password);
				if ($k and @openssl_pkey_export($k, $plain)) { $out[] = $plain; break; }
			}
		}
		return $out;
	}

	/** Certificates from a PKCS#7 bundle, PEM or DER. */
	private static function pkcs7($blob)
	{
		if (!function_exists('openssl_pkcs7_read')) return array();
		$pem = strpos($blob, '-----BEGIN PKCS7-----') !== false
			? $blob
			: "-----BEGIN PKCS7-----\n" . chunk_split(base64_encode($blob), 64, "\n") . "-----END PKCS7-----\n";
		$certs = array();
		if (!@openssl_pkcs7_read($pem, $certs)) return array();
		return array_map(array(__CLASS__, 'normalize'), $certs);
	}

	/** The chain after the leaf, issuer by issuer, then anything unplaced. */
	private static function orderChain($leaf, array $rest)
	{
		$chain = array();
		$current = $leaf;
		while ($rest) {
			$issuer = (openssl_x509_parse($current)['issuer'] ?? null);
			$next = null;
			foreach ($rest as $fp => $pem) {
				if ((openssl_x509_parse($pem)['subject'] ?? null) == $issuer) { $next = $fp; break; }
			}
			if ($next === null) break;
			// A self-signed root ends the path and is not sent: clients have
			// their own, and ignore one that comes with the chain.
			$np = openssl_x509_parse($rest[$next]);
			if ($np and $np['subject'] == $np['issuer']) { unset($rest[$next]); break; }
			$chain[] = $rest[$next];
			$current = $rest[$next];
			unset($rest[$next]);
		}
		// Anything left is not in this path; a self-signed root in it is
		// dropped (clients ignore it), anything else kept at the end.
		foreach ($rest as $pem) {
			$p = openssl_x509_parse($pem);
			if ($p and $p['subject'] != $p['issuer']) $chain[] = $pem;
		}
		return $chain;
	}

	private static function fingerprint($pem)
	{
		return (string) @openssl_x509_fingerprint($pem, 'sha256');
	}

	private static function normalize($pem)
	{
		return trim(str_replace("\r", '', $pem)) . "\n";
	}
}
