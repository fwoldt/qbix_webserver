<?php
/**
 * @module Q
 */

/**
 * A PKCS#12 bundle, as Windows, IIS, Java keystores and many CAs export it:
 *
 *   "https": {
 *     "mode": "pkcs12",
 *     "bundle": "/etc/ssl/example.com.pfx",
 *     "password": "...", or "passwordFile": "/path", or "passwordEnv": "VAR"
 *   }
 *
 * Read with the openssl extension; a bundle in the older RC2/3DES format that
 * OpenSSL 3 no longer reads by default is read with the openssl binary's
 * -legacy option instead. Imported into <ssl dir>/imported/pkcs12.*, and
 * again whenever the bundle changes.
 *
 * @class Q_WebServer_Certificate_Source_Pkcs12
 */
class Q_WebServer_Certificate_Source_Pkcs12 extends Q_WebServer_Certificate_Source_Base
{
	function name()
	{
		return 'pkcs12';
	}

	function watched(array $config)
	{
		return empty($config['bundle']) ? array() : array($config['bundle']);
	}

	function pair(array $config, &$why = '')
	{
		$bundle = isset($config['bundle']) ? $config['bundle'] : '';
		if ($bundle === '' or !is_file($bundle)) { $why = "no bundle at $bundle"; return null; }
		$c = self::read((string) file_get_contents($bundle), (string) self::secret($config, 'password'), $why);
		return $c ? $this->keep($c, $why) : null;
	}

	/**
	 * A pair from PKCS#12 bytes.
	 * @method read
	 * @static
	 * @return {Q_WebServer_Certificate|null}
	 */
	static function read($bytes, $password, &$why = '')
	{
		$parts = array();
		if (function_exists('openssl_pkcs12_read') and @openssl_pkcs12_read($bytes, $parts, $password)) {
			$blobs = array($parts['cert'] ?? '', $parts['pkey'] ?? '');
			foreach ((array) ($parts['extracerts'] ?? array()) as $x) $blobs[] = $x;
			return Q_WebServer_Certificate_Import::fromMaterial($blobs, null, $why);
		}
		// OpenSSL 3 refuses the older ciphers unless asked for the legacy provider.
		$openssl = (new Q_WebServer_Certificate_Provider_OpensslCli())->binary();
		if ($openssl) {
			$tmp = @tempnam(sys_get_temp_dir(), 'qbix-p12-');
			if ($tmp !== false) {
				@chmod($tmp, 0600);
				file_put_contents($tmp, $bytes);
				try {
					$code = self::run(array($openssl, 'pkcs12', '-in', $tmp, '-nodes', '-legacy', '-passin', 'env:QBIX_P12_PASS'),
						$out, array('QBIX_P12_PASS' => $password));
				} finally {
					@unlink($tmp);
				}
				if ($code === 0) return Q_WebServer_Certificate_Import::fromMaterial(array($out), null, $why);
			}
		}
		$why = 'the bundle could not be read: a wrong password, or not a PKCS#12 file';
		return null;
	}
}
