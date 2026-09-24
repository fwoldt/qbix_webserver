<?php
/**
 * @module Q
 */

/**
 * An installed certbot issues and renews ("certbot"):
 *
 *   "https": {
 *     "mode": "certbot",
 *     "domain": "example.com",
 *     "certbot": {
 *       "email": "admin@example.com",
 *       "webroot": "/var/www/example.com/web",   // where /.well-known/acme-challenge is served
 *       "domains": ["example.com", "www.example.com"],
 *       "binary": "/usr/bin/certbot",           // default: found by absolute path
 *       "live": "/etc/letsencrypt/live"          // default
 *     }
 *   }
 *
 * The certificate is used where certbot keeps it (live/<domain>/), so a
 * renewal by certbot's own timer is picked up as soon as it lands. The server
 * also asks certbot to renew once a day, in the background: certbot itself
 * decides whether anything is due. Run with an argument list, never a shell.
 * (The built-in "acme" mode needs no certbot at all.)
 *
 * @class Q_WebServer_Certificate_Source_Certbot
 */
class Q_WebServer_Certificate_Source_Certbot extends Q_WebServer_Certificate_Source_Base
{
	function name()
	{
		return 'certbot';
	}

	private static function settings(array $config)
	{
		$c = isset($config['certbot']) ? (array) $config['certbot'] : array();
		$domains = !empty($c['domains']) ? (array) $c['domains'] : (!empty($config['domain']) ? array($config['domain']) : array());
		$live = rtrim(isset($c['live']) ? $c['live'] : '/etc/letsencrypt/live', '/');
		return array($c, $domains, $domains ? "$live/{$domains[0]}" : null);
	}

	function watched(array $config)
	{
		list(, , $dir) = self::settings($config);
		return $dir ? array("$dir/fullchain.pem", "$dir/privkey.pem") : array();
	}

	function pair(array $config, &$why = '')
	{
		list(, $domains, $dir) = self::settings($config);
		if (!$dir) { $why = 'no domain: set https.domain or https.certbot.domains'; return null; }
		if (!Q_WebServer_Certificate::fromFiles("$dir/fullchain.pem", "$dir/privkey.pem")) {
			$why = 'certbot has no certificate for ' . $domains[0] . ' yet';
			$this->start($config, 'certonly');
		}
		return array("$dir/fullchain.pem", "$dir/privkey.pem");
	}

	function tick(array $config)
	{
		list(, , $dir) = self::settings($config);
		if (!$dir) return;
		$state = Q_WebServer_Certificate_Job::state(self::stateFile($dir));
		if (!empty($state['lastAttempt']) and time() - $state['lastAttempt'] < 86400) return;
		$this->start($config, Q_WebServer_Certificate::fromFiles("$dir/fullchain.pem", "$dir/privkey.pem") ? 'renew' : 'certonly');
	}

	private static function stateFile($dir)
	{
		return Q_WebServer_Certificate_Store::defaultDir() . DIRECTORY_SEPARATOR . 'certbot-' . basename($dir) . '.json';
	}

	private function start(array $config, $verb)
	{
		list($c, $domains, $dir) = self::settings($config);
		$bin = !empty($c['binary']) ? $c['binary'] : self::tool(array('certbot', 'certbot-auto'));
		if (!$bin) return;
		if ($verb === 'renew') {
			$cmd = array($bin, 'renew', '--cert-name', $domains[0], '--non-interactive', '--quiet');
		} else {
			$cmd = array($bin, 'certonly', '--non-interactive', '--agree-tos', '--keep-until-expiring');
			$cmd = array_merge($cmd, !empty($c['email']) ? array('--email', $c['email']) : array('--register-unsafely-without-email'));
			if (!empty($c['webroot'])) $cmd = array_merge($cmd, array('--webroot', '-w', $c['webroot']));
			foreach ($domains as $d) { $cmd[] = '-d'; $cmd[] = $d; }
		}
		Q_WebServer_Certificate_Job::start('certbot:' . $domains[0], self::stateFile($dir), function () use ($cmd) {
			$code = Q_WebServer_Certificate_Source_Base::run($cmd, $out);
			return array('ok' => $code === 0, 'error' => $code === 0 ? null : trim((string) $out));
		});
	}
}
