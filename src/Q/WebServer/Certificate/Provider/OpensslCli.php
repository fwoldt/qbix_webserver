<?php
/**
 * @module Q
 */

/**
 * The system's openssl command-line tool: for when the extension is missing
 * or refuses. Found by absolute path, so it works from a service manager that
 * starts the server with no PATH, and run without a shell.
 *
 * @class Q_WebServer_Certificate_Provider_OpensslCli
 */
class Q_WebServer_Certificate_Provider_OpensslCli implements Q_WebServer_Certificate_Provider
{
	/** @var array where to look for the binary, in order */
	static $candidates = array('/usr/bin/openssl', '/usr/local/bin/openssl', '/bin/openssl',
		'/opt/homebrew/bin/openssl', '/usr/local/opt/openssl/bin/openssl', '/opt/local/bin/openssl');

	/** @var string|null an explicit binary, e.g. from Q.web.https.selfSigned.openssl */
	private $binary;

	function __construct($binary = null)
	{
		$this->binary = $binary;
	}

	function name()
	{
		return 'openssl-cli';
	}

	/** @return {string|null} the binary this provider would run */
	function binary()
	{
		if ($this->binary !== null) return is_executable($this->binary) ? $this->binary : null;
		foreach (self::$candidates as $c) if (@is_executable($c)) return $c;
		return null;
	}

	function available(&$why = '')
	{
		if (!function_exists('proc_open')) { $why = 'proc_open() is disabled'; return false; }
		if ($this->binary() === null) { $why = 'no openssl binary found'; return false; }
		return true;
	}

	function create(array $hosts, $days, &$why = '')
	{
		$hosts = array_values(array_filter(array_map('strval', $hosts), 'strlen'));
		if (!$hosts) $hosts = array('localhost');
		$conf = Q_WebServer_Certificate_Provider_Openssl::writeConfig($hosts);
		$keyFile = @tempnam(sys_get_temp_dir(), 'qbix-key-');
		$certFile = @tempnam(sys_get_temp_dir(), 'qbix-crt-');
		if ($conf === null or $keyFile === false or $certFile === false) {
			$why = 'could not create temporary files';
			foreach (array($conf, $keyFile, $certFile) as $f) if ($f) @unlink($f);
			return null;
		}
		@chmod($keyFile, 0600);
		try {
			$cmd = array($this->binary(), 'req', '-x509', '-new', '-nodes',
				'-newkey', 'ec', '-pkeyopt', 'ec_paramgen_curve:prime256v1', '-sha256',
				'-days', (string) (int) $days, '-set_serial', (string) random_int(1, PHP_INT_MAX),
				'-config', $conf, '-extensions', 'qbix_ext',
				'-keyout', $keyFile, '-out', $certFile);
			$p = @proc_open($cmd, array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
			if (!is_resource($p)) { $why = 'could not run ' . $this->binary(); return null; }
			$out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
			fclose($pipes[1]); fclose($pipes[2]);
			$code = proc_close($p);
			if ($code !== 0) {
				$lines = array_filter(array_map('trim', explode("\n", $out)));
				$why = 'openssl exited with ' . $code . ($lines ? ': ' . end($lines) : '');
				return null;
			}
			$c = Q_WebServer_Certificate::fromFiles($certFile, $keyFile, $this->name());
		} finally {
			foreach (array($conf, $keyFile, $certFile) as $f) @unlink($f);
		}
		if (!$c or !$c->isUsable()) { $why = 'the certificate made does not verify against its key'; return null; }
		return $c;
	}
}
