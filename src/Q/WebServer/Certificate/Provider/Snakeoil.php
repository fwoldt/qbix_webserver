<?php
/**
 * @module Q
 */

/**
 * The system's own "snakeoil" certificate, as Debian and Ubuntu install with
 * ssl-cert: the last resort, when nothing here can make a certificate at all.
 * It is not made for the configured host names, so it is used only when it
 * is still valid and readable, and copied, never linked.
 *
 * @class Q_WebServer_Certificate_Provider_Snakeoil
 */
class Q_WebServer_Certificate_Provider_Snakeoil implements Q_WebServer_Certificate_Provider
{
	private $certFile;
	private $keyFile;

	function __construct($certFile = '/etc/ssl/certs/ssl-cert-snakeoil.pem',
		$keyFile = '/etc/ssl/private/ssl-cert-snakeoil.key')
	{
		$this->certFile = $certFile;
		$this->keyFile = $keyFile;
	}

	function name()
	{
		return 'snakeoil';
	}

	function available(&$why = '')
	{
		if (!@is_readable($this->certFile) or !@is_readable($this->keyFile)) {
			$why = 'no readable ' . $this->certFile . ' and ' . $this->keyFile;
			return false;
		}
		return true;
	}

	function create(array $hosts, $days, &$why = '')
	{
		$c = Q_WebServer_Certificate::fromFiles($this->certFile, $this->keyFile, $this->name());
		if (!$c) { $why = 'could not read the snakeoil pair'; return null; }
		if (!$c->isUsable()) { $why = 'the snakeoil certificate has expired or does not match its key'; return null; }
		return $c;
	}
}
