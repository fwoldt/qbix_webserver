<?php
/**
 * @module Q
 */

/**
 * Makes a self-signed certificate by trying providers in order until one
 * returns a usable one. The default order:
 *
 *   1. openssl-ecdsa   the openssl extension, ECDSA P-256
 *   2. openssl-rsa     the openssl extension, RSA 2048 signed with SHA-256
 *   3. openssl-cli     the system openssl binary
 *   4. snakeoil        the system snakeoil certificate (Debian ssl-cert)
 *
 * Every step is reported through Q_WebServer_Certificate_Events, so the
 * console shows it at the verbosity asked for and the server can react.
 *
 * @class Q_WebServer_Certificate_Factory
 * @static
 */
class Q_WebServer_Certificate_Factory
{
	/** @var Q_WebServer_Certificate_Provider[]|null null until first used */
	private static $providers = null;

	/** Days a self-signed certificate is valid: under the 825 some clients cap it at. */
	const DAYS = 397;

	/** @return {Q_WebServer_Certificate_Provider[]} the chain, in order */
	static function providers()
	{
		if (self::$providers === null) {
			self::$providers = array(
				new Q_WebServer_Certificate_Provider_OpensslEcdsa(),
				new Q_WebServer_Certificate_Provider_OpensslRsa(),
				new Q_WebServer_Certificate_Provider_OpensslCli(),
				new Q_WebServer_Certificate_Provider_Snakeoil(),
			);
		}
		return self::$providers;
	}

	/** Replace the whole chain (tests, or a distribution that wants its own). */
	static function setProviders(array $providers)
	{
		self::$providers = array_values($providers);
	}

	/** Add a provider at the end. */
	static function add(Q_WebServer_Certificate_Provider $p)
	{
		self::providers();
		self::$providers[] = $p;
	}

	/** Add a provider at the front. */
	static function prepend(Q_WebServer_Certificate_Provider $p)
	{
		self::providers();
		array_unshift(self::$providers, $p);
	}

	/** Back to the default chain. */
	static function reset()
	{
		self::$providers = null;
	}

	/**
	 * Make a certificate for these hosts.
	 * @method create
	 * @static
	 * @param {array} $hosts the first is the common name
	 * @param {integer} $days
	 * @return {Q_WebServer_Certificate|null} null when every provider failed
	 */
	static function create(array $hosts, $days = self::DAYS)
	{
		$reasons = array();
		foreach (self::providers() as $p) {
			$name = $p->name();
			$why = '';
			if (!$p->available($why)) {
				Q_WebServer_Certificate_Events::emit('skipped', array('provider' => $name, 'reason' => $why));
				$reasons[] = "$name: $why";
				continue;
			}
			Q_WebServer_Certificate_Events::emit('trying', array('provider' => $name));
			$t = microtime(true);
			try {
				$c = $p->create($hosts, $days, $why);
			} catch (\Throwable $e) {
				$c = null;
				$why = get_class($e) . ': ' . $e->getMessage();
			}
			$secs = microtime(true) - $t;
			if ($c instanceof Q_WebServer_Certificate and $c->isUsable()) {
				Q_WebServer_Certificate_Events::emit('created', array('provider' => $name, 'seconds' => $secs, 'certificate' => $c));
				return $c;
			}
			$why = $why !== '' ? $why : 'made nothing usable';
			Q_WebServer_Certificate_Events::emit('failed', array('provider' => $name, 'reason' => $why, 'seconds' => $secs));
			$reasons[] = "$name: $why";
		}
		Q_WebServer_Certificate_Events::emit('exhausted', array('reasons' => $reasons));
		return null;
	}
}
