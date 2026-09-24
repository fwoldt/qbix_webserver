<?php
/**
 * @module Q
 */

/**
 * Certificate and key files you provide ("files", and "manual" as before):
 *
 *   "https": {
 *     "mode": "files",
 *     "cert": "/etc/ssl/example.com/fullchain.pem",   // leaf, or leaf + chain; PEM or DER
 *     "key": "/etc/ssl/example.com/privkey.pem",      // may be the same file as cert
 *     "chain": "/etc/ssl/example.com/chain.pem",      // optional, when cert holds only the leaf
 *     "keyPassword": "...", or "keyPasswordFile": "/path", or "keyPasswordEnv": "VAR"
 *   }
 *
 * Plain PEM files with an unencrypted key are used where they are, so a
 * renewal by whatever wrote them is picked up as soon as it is complete. Any
 * other form -- DER, a separate chain, an encrypted key, a combined file --
 * is imported into <ssl dir>/imported/files.* and imported again when one of
 * your files changes.
 *
 * @class Q_WebServer_Certificate_Source_Files
 */
class Q_WebServer_Certificate_Source_Files extends Q_WebServer_Certificate_Source_Base
{
	function name()
	{
		return 'files';
	}

	function watched(array $config)
	{
		return array_values(array_filter(array(self::path($config, 'cert'), self::path($config, 'key'),
			isset($config['chain']) ? $config['chain'] : null)));
	}

	function pair(array $config, &$why = '')
	{
		$cert = self::path($config, 'cert');
		$key = self::path($config, 'key');
		$chain = isset($config['chain']) ? $config['chain'] : null;
		$password = self::secret($config, 'keyPassword');
		if (!is_file($cert) or !is_file($key)) {
			// Nothing yet: point at where it will be, so it is used as soon
			// as it appears.
			$why = !is_file($cert) ? "no certificate at $cert" : "no key at $key";
			return array($cert, $key);
		}
		if ($cert !== $key and !$chain and $password === null) {
			$c = Q_WebServer_Certificate::fromFiles($cert, $key);
			if ($c and $c->isUsable() and strpos($c->certPem, '-----BEGIN CERTIFICATE-----') === 0) {
				return array($cert, $key);
			}
		}
		$blobs = array((string) @file_get_contents($cert));
		if ($key !== $cert) $blobs[] = (string) @file_get_contents($key);
		if ($chain and is_file($chain)) $blobs[] = (string) @file_get_contents($chain);
		$c = Q_WebServer_Certificate_Import::fromMaterial($blobs, $password, $why);
		if (!$c) return array($cert, $key);
		return $this->keep($c, $why);
	}

	/** cert or key from the configuration, or the certs directory's default. */
	static function path(array $config, $which)
	{
		if (!empty($config[$which])) return $config[$which];
		return Q_WebServer_Certs::certsDir() . DIRECTORY_SEPARATOR . ($which === 'cert' ? 'fullchain.pem' : 'privkey.pem');
	}
}
