<?php
/**
 * @module Q
 */

/**
 * Self-signed certificates through PHP's openssl extension. The key type is
 * the subclass's; everything else is shared:
 *
 *   - the digest is named (SHA-256). PHP's default is SHA-1, which a system
 *     crypto policy may refuse to sign with -- RHEL's DEFAULT does;
 *   - the host names and addresses go in subjectAltName, which is what
 *     clients check -- a certificate that names its host only in the common
 *     name is refused by every current browser;
 *   - the serial number is random, so a renewed certificate is never mistaken
 *     for the one it replaces.
 *
 * @class Q_WebServer_Certificate_Provider_Openssl
 */
abstract class Q_WebServer_Certificate_Provider_Openssl implements Q_WebServer_Certificate_Provider
{
	/** @return {array} openssl_pkey_new() options for the key type */
	abstract protected function keyOptions();

	function available(&$why = '')
	{
		foreach (array('openssl_pkey_new', 'openssl_csr_new', 'openssl_csr_sign') as $f) {
			if (!function_exists($f)) { $why = 'the openssl extension is not loaded'; return false; }
		}
		return true;
	}

	function create(array $hosts, $days, &$why = '')
	{
		$hosts = array_values(array_filter(array_map('strval', $hosts), 'strlen'));
		if (!$hosts) $hosts = array('localhost');
		$conf = self::writeConfig($hosts);
		if ($conf === null) { $why = 'could not write a temporary openssl configuration'; return null; }
		$options = array('config' => $conf, 'digest_alg' => 'sha256', 'x509_extensions' => 'qbix_ext',
			'req_extensions' => 'qbix_ext') + $this->keyOptions();
		try {
			while (openssl_error_string() !== false) {}
			$key = @openssl_pkey_new($options);
			$csr = $key ? @openssl_csr_new(array('commonName' => $hosts[0]), $key, $options) : false;
			$cert = $csr ? @openssl_csr_sign($csr, null, $key, (int) $days, $options, random_int(1, PHP_INT_MAX)) : false;
			if (!$cert or !@openssl_x509_export($cert, $certPem) or !@openssl_pkey_export($key, $keyPem, null, $options)) {
				$why = self::lastError('signing failed');
				return null;
			}
		} finally {
			@unlink($conf);
		}
		$c = new Q_WebServer_Certificate($certPem, $keyPem, $this->name());
		if (!$c->isUsable()) { $why = 'the certificate made does not verify against its key'; return null; }
		return $c;
	}

	/**
	 * An openssl configuration with the extensions a server certificate needs.
	 * Written to a private temporary file; the caller removes it.
	 * @return {string|null} its path
	 */
	static function writeConfig(array $hosts)
	{
		$san = array();
		foreach ($hosts as $h) $san[] = (filter_var($h, FILTER_VALIDATE_IP) ? 'IP:' : 'DNS:') . $h;
		$text = "[req]\ndistinguished_name = qbix_dn\nprompt = no\n[qbix_dn]\nCN = " . $hosts[0] . "\n"
			. "[qbix_ext]\nsubjectAltName = " . implode(',', $san) . "\n"
			. "basicConstraints = critical,CA:FALSE\n"
			. "keyUsage = critical,digitalSignature,keyEncipherment\n"
			. "extendedKeyUsage = serverAuth\n";
		$file = @tempnam(sys_get_temp_dir(), 'qbix-ssl-');
		if ($file === false) return null;
		@chmod($file, 0600);
		return @file_put_contents($file, $text) === false ? null : $file;
	}

	/** The last openssl error, or $fallback. */
	static function lastError($fallback)
	{
		$last = null;
		while (($e = openssl_error_string()) !== false) $last = $e;
		return $last ? "$fallback: $last" : $fallback;
	}
}
