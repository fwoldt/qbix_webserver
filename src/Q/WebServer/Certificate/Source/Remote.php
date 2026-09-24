<?php
/**
 * @module Q
 */

/**
 * Downloaded from a URL ("remote") -- certificates published centrally, for
 * development domains or a fleet:
 *
 *   "https": {
 *     "mode": "remote",
 *     "remote": {
 *       "url": "https://certs.example.com/dev.example.com.zip",   // any archive, a .p12, or a PEM
 *       "checkInterval": 86400,
 *       "headers": ["Authorization: Bearer ..."],   // optional
 *       "password": "...", "keyPassword": "..."     // for an encrypted archive or key
 *     }
 *   }
 *
 * Downloaded in the background, read like the "archive" source (so any of its
 * formats works), imported into <ssl dir>/imported/remote.*, and downloaded
 * again every checkInterval.
 *
 * @class Q_WebServer_Certificate_Source_Remote
 */
class Q_WebServer_Certificate_Source_Remote extends Q_WebServer_Certificate_Source_Base
{
	function name()
	{
		return 'remote';
	}

	private static function download(array $config)
	{
		$r = isset($config['remote']) ? (array) $config['remote'] : array();
		return Q_WebServer_Certificate_Store::defaultDir() . DIRECTORY_SEPARATOR . 'imported'
			. DIRECTORY_SEPARATOR . 'remote-download' . (preg_match('/(\.tar\.[a-z0-9]+|\.[a-z0-9]+)(?:\?|$)/i', (string) ($r['url'] ?? ''), $m) ? strtolower($m[1]) : '.zip');
	}

	function watched(array $config)
	{
		return array(self::download($config));
	}

	function pair(array $config, &$why = '')
	{
		$file = self::download($config);
		if (!is_file($file)) {
			$this->tick($config);
			$why = 'the certificate is being downloaded';
			$d = Q_WebServer_Certificate_Store::defaultDir() . DIRECTORY_SEPARATOR . 'imported';
			return array("$d/remote.pem", "$d/remote.key");
		}
		$r = isset($config['remote']) ? (array) $config['remote'] : array();
		$members = preg_match('/\.(pem|crt|cer)$/i', $file)
			? array(basename($file) => (string) file_get_contents($file))
			: Q_WebServer_Certificate_Source_Archive::members($file, self::secret($r, 'password'), $why);
		$c = $members ? Q_WebServer_Certificate_Source_Archive::fromMembers($members, self::secret($r, 'keyPassword'), self::secret($r, 'password'), $why) : null;
		return $c ? $this->keep($c, $why) : null;
	}

	function tick(array $config)
	{
		$r = isset($config['remote']) ? (array) $config['remote'] : array();
		if (empty($r['url'])) return;
		$file = self::download($config);
		$stateFile = $file . '.json';
		$state = Q_WebServer_Certificate_Job::state($stateFile);
		$interval = (int) ($r['checkInterval'] ?? 86400);
		if (is_file($file) and !empty($state['lastSuccess']) and time() - $state['lastSuccess'] < $interval) return;
		Q_WebServer_Certificate_Job::start('remote:' . $r['url'], $stateFile, function () use ($r, $file) {
			$ctx = stream_context_create(array('http' => array('timeout' => 60, 'ignore_errors' => true,
				'header' => implode("\r\n", (array) ($r['headers'] ?? array())))));
			$bytes = @file_get_contents($r['url'], false, $ctx);
			$status = 0;
			foreach ((array) ($http_response_header ?? array()) as $h) if (preg_match('~^HTTP/\S+\s+(\d+)~', $h, $m)) $status = (int) $m[1];
			if ($bytes === false or $bytes === '' or $status >= 400) return array('ok' => false, 'error' => "download failed (HTTP $status)");
			if (!is_dir(dirname($file))) @mkdir(dirname($file), 0700, true);
			$ok = Q_WebServer_Certificate_Store::writeAtomic($file, $bytes, 0600);
			return array('ok' => $ok, 'error' => $ok ? null : 'could not write ' . $file);
		});
	}
}
