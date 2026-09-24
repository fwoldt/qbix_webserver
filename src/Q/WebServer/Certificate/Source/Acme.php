<?php
/**
 * @module Q
 */

/**
 * Let's Encrypt, or any ACME CA, built in ("acme", alias "letsencrypt"). See
 * Q_WebServer_Acme for the settings. Issuance and renewal run as background
 * jobs; this source only says where the certificate is and starts a job when
 * one is due.
 *
 * @class Q_WebServer_Certificate_Source_Acme
 */
class Q_WebServer_Certificate_Source_Acme extends Q_WebServer_Certificate_Source_Base
{
	function name()
	{
		return 'acme';
	}

	function watched(array $config)
	{
		$f = Q_WebServer_Acme::files($config);
		return $f ? array($f[0], $f[1]) : array();
	}

	function pair(array $config, &$why = '')
	{
		$f = Q_WebServer_Acme::files($config);
		if (!$f) { $why = 'no domains: set https.acme.domains or https.domain'; return null; }
		$this->tick($config);
		$state = Q_WebServer_Certificate_Job::state($f[2]);
		if (!Q_WebServer_Certificate::fromFiles($f[0], $f[1])) {
			$why = 'the first certificate is being issued' . (!empty($state['lastError']) ? ' (last error: ' . $state['lastError'] . ')' : '');
		}
		return array($f[0], $f[1]);
	}

	function tick(array $config)
	{
		$f = Q_WebServer_Acme::files($config);
		if (!$f) return;
		$acme = isset($config['acme']) ? (array) $config['acme'] : array();
		$reason = Q_WebServer_Acme::renewalDue($f[0], $f[1], Q_WebServer_Acme::domains($config),
			isset($acme['renewAt']) ? $acme['renewAt'] : null);
		if ($reason === null) return;
		$started = Q_WebServer_Certificate_Job::start('acme:' . $f[0], $f[2], function () use ($config) {
			return Q_WebServer_Acme::issue($config);
		});
		if ($started) Q_WebServer_Certificate_Events::emit('renewing', array('reason' => "acme: $reason"));
	}

	/**
	 * Issue now, in this process (the console's ssl:issue).
	 * @return {array} Q_WebServer_Acme::issue()'s result
	 */
	function issueNow(array $config)
	{
		$f = Q_WebServer_Acme::files($config);
		$r = Q_WebServer_Acme::issue($config);
		if ($f) {
			$state = Q_WebServer_Certificate_Job::state($f[2]);
			$state['lastAttempt'] = time();
			if ($r['ok']) { $state['lastSuccess'] = time(); $state['failures'] = 0; $state['lastError'] = null; $state['nextAttempt'] = null; }
			else { $state['lastError'] = $r['error']; }
			Q_WebServer_Certificate_Store::writeAtomic($f[2], json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", 0600);
		}
		return $r;
	}
}
