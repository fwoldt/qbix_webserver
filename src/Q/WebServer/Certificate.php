<?php
/**
 * @module Q
 */

/**
 * A certificate and its private key, as PEM text, with what the server needs
 * to know about them: whether they are usable together, when the certificate
 * expires, which host names and addresses it covers, and its fingerprint.
 *
 * Produced by a Q_WebServer_Certificate_Provider, kept by
 * Q_WebServer_Certificate_Store. Nothing here touches the Platform's Q_Utils,
 * so it works the same in standalone and --app mode.
 *
 * @class Q_WebServer_Certificate
 */
class Q_WebServer_Certificate
{
	/** @var string the certificate, PEM */
	public $certPem;

	/** @var string the private key, PEM */
	public $keyPem;

	/** @var string the provider that made it, e.g. "openssl-ecdsa" */
	public $provider;

	/** @var array|false|null openssl_x509_parse() of the certificate, once read */
	private $parsed = null;

	function __construct($certPem, $keyPem, $provider = '')
	{
		$this->certPem = (string) $certPem;
		$this->keyPem = (string) $keyPem;
		$this->provider = (string) $provider;
	}

	/**
	 * Read a certificate and key from files. Null when either is missing or
	 * unreadable.
	 * @method fromFiles
	 * @static
	 * @param {string} $certFile
	 * @param {string} $keyFile
	 * @return {Q_WebServer_Certificate|null}
	 */
	static function fromFiles($certFile, $keyFile, $provider = '')
	{
		$cert = is_file($certFile) ? @file_get_contents($certFile) : false;
		$key = is_file($keyFile) ? @file_get_contents($keyFile) : false;
		if ($cert === false or $key === false or $cert === '' or $key === '') return null;
		return new self($cert, $key, $provider);
	}

	/**
	 * Whether the certificate parses, has not expired, and the key belongs to
	 * it. An empty or truncated pair -- what a failed signing used to leave on
	 * disk -- is not usable.
	 * @method isUsable
	 * @return {boolean}
	 */
	function isUsable()
	{
		if (!function_exists('openssl_x509_parse')) return false;
		$p = $this->parsed();
		if (!$p or empty($p['validTo_time_t'])) return false;
		if ((int) $p['validTo_time_t'] <= time()) return false;
		return (bool) @openssl_x509_check_private_key($this->certPem, $this->keyPem);
	}

	/** @return {integer|null} the expiry as a Unix time */
	function expires()
	{
		$p = $this->parsed();
		return ($p and isset($p['validTo_time_t'])) ? (int) $p['validTo_time_t'] : null;
	}

	/** @return {integer|null} whole days left, 0 once expired */
	function daysLeft()
	{
		$e = $this->expires();
		return $e === null ? null : max(0, (int) floor(($e - time()) / 86400));
	}

	/**
	 * The names and addresses it covers: its subjectAltName entries, or its
	 * common name when it has none. Lower-cased and sorted, so two lists can be
	 * compared.
	 * @method hosts
	 * @return {array}
	 */
	function hosts()
	{
		$p = $this->parsed();
		if (!$p) return array();
		$hosts = array();
		$san = isset($p['extensions']['subjectAltName']) ? $p['extensions']['subjectAltName'] : '';
		foreach (preg_split('/\s*,\s*/', (string) $san, -1, PREG_SPLIT_NO_EMPTY) as $entry) {
			if (preg_match('/^(?:DNS|IP Address):(.+)$/i', $entry, $m)) $hosts[] = strtolower(trim($m[1]));
		}
		if (!$hosts and !empty($p['subject']['CN'])) $hosts[] = strtolower($p['subject']['CN']);
		$hosts = array_values(array_unique($hosts));
		sort($hosts);
		return $hosts;
	}

	/** @return {string} the subject's common name */
	function commonName()
	{
		$p = $this->parsed();
		return ($p and !empty($p['subject']['CN'])) ? strtolower((string) $p['subject']['CN']) : '';
	}

	/** @return {array} hosts(), with the common name first, as it was asked for */
	function hostsInOrder()
	{
		$cn = $this->commonName();
		$rest = array_values(array_diff($this->hosts(), array($cn)));
		return $cn !== '' ? array_merge(array($cn), $rest) : $rest;
	}

	/** @return {string} the SHA-256 fingerprint, hex */
	function fingerprint()
	{
		$fp = function_exists('openssl_x509_fingerprint') ? @openssl_x509_fingerprint($this->certPem, 'sha256') : false;
		return $fp ? (string) $fp : '';
	}

	/** @return {string} "EC" or "RSA", from the key */
	function keyType()
	{
		$k = @openssl_pkey_get_private($this->keyPem);
		$d = $k ? @openssl_pkey_get_details($k) : false;
		if (!$d) return '';
		return $d['type'] === OPENSSL_KEYTYPE_EC ? 'EC' : ($d['type'] === OPENSSL_KEYTYPE_RSA ? 'RSA' : 'other');
	}

	private function parsed()
	{
		if ($this->parsed === null) {
			$this->parsed = function_exists('openssl_x509_parse') ? @openssl_x509_parse($this->certPem) : false;
		}
		return $this->parsed;
	}
}
