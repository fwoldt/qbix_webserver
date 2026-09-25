<?php
/**
 * @module Q
 */
/**
 * Which domains are actually in use, and where each name comes from: the
 * listeners, the names on the certificate the HTTPS listener presents,
 * the Host headers requests arrived with, the enabled site files, the
 * domain records, and whatever a provider adds (an application's own host
 * map, registered by a distribution).
 *
 * A provider is a callable returning a list of
 *   array('host' => 'example.com', 'detail' => 'shown in the panel')
 * and is registered with Q_WebServer_DomainUsage::addProvider($label, $fn).
 *
 * State, per host:
 *   serving       requests for it were answered since the server started
 *   covered       the presented certificate names it (wildcards count)
 *   unconfigured  requests arrived for it, but nothing configures it
 *   unseen        configured, but no request for it since start
 *
 * @class Q_WebServer_DomainUsage
 * @static
 */
class Q_WebServer_DomainUsage
{
	/** @var array label => callable */
	protected static $providers = array();

	static function addProvider($label, callable $fn)
	{
		self::$providers[(string) $label] = $fn;
	}

	static function providers()
	{
		return self::$providers;
	}

	/** Whether a certificate name (maybe *.example.com) covers a host. */
	static function covers($certName, $host)
	{
		$certName = strtolower($certName);
		if ($certName === $host) return true;
		if (strncmp($certName, '*.', 2) === 0) {
			$suffix = substr($certName, 1);                 // .example.com
			$pos = strpos($host, '.');
			return $pos !== false and substr($host, $pos) === $suffix;
		}
		return false;
	}

	/**
	 * The certificate the HTTPS listener presents, or null.
	 * @return {array|null} file, names, expires (unix), daysLeft
	 */
	static function certificate($file = null)
	{
		if ($file === null and class_exists('Q_WebServer_Certs', false)) {
			$file = Q_WebServer_Certs::$activeCert ?: Q_WebServer_Certs::$certPath;
		}
		if (!is_string($file) or $file === '' or !is_readable($file)) return null;
		$pem = (string) @file_get_contents($file);
		$x = $pem !== '' ? @openssl_x509_parse($pem) : false;
		if (!$x) return null;
		$names = array();
		if (!empty($x['subject']['CN'])) $names[] = strtolower($x['subject']['CN']);
		foreach (explode(',', (string) ($x['extensions']['subjectAltName'] ?? '')) as $san) {
			$san = trim($san);
			if (strncmp($san, 'DNS:', 4) === 0) {
				$n = strtolower(substr($san, 4));
				if (!in_array($n, $names, true)) $names[] = $n;
			}
		}
		$exp = (int) ($x['validTo_time_t'] ?? 0);
		return array('file' => $file, 'names' => $names, 'expires' => $exp ?: null,
			'daysLeft' => $exp ? (int) floor(($exp - time()) / 86400) : null);
	}

	/**
	 * Everything, per host.
	 * @param {array} $opts for tests: certFile, confDir, seen, records
	 * @return {array} listeners, certificate, hosts (list, sorted)
	 */
	static function collect(array $opts = array())
	{
		$hosts = array();
		$add = function ($host, $source, $detail = '') use (&$hosts) {
			$h = Q_WebServer_Domains::normalize($host);
			if ($h === '' or strpos($h, '*') !== false) return;
			if (!isset($hosts[$h])) $hosts[$h] = array('host' => $h, 'sources' => array(), 'seen' => null);
			$hosts[$h]['sources'][] = array('source' => $source, 'detail' => (string) $detail);
		};

		// Listeners.
		$listeners = array();
		if (class_exists('Q_WebServer', false)) {
			$bind = (string) Q_WebServer::$host;
			if (Q_WebServer::$port) $listeners[] = array('scheme' => 'http', 'host' => $bind, 'port' => (int) Q_WebServer::$port);
			$tls = method_exists('Q_WebServer', 'httpsPort') ? Q_WebServer::httpsPort() : 0;
			if ($tls) $listeners[] = array('scheme' => 'https', 'host' => $bind, 'port' => (int) $tls);
		}

		// The certificate.
		$cert = self::certificate($opts['certFile'] ?? null);
		if ($cert) {
			foreach ($cert['names'] as $n) $add($n, 'certificate', $cert['daysLeft'] !== null ? $cert['daysLeft'] . ' days left' : '');
		}

		// Enabled site files.
		$confDir = $opts['confDir'] ?? (class_exists('Q_Config', false) ? Q_Config::get('Q', 'webserver', 'confDir', null) : null);
		$siteFiles = array();
		if (is_string($confDir) and $confDir !== '' and class_exists('Q_WebServer_Layout')) {
			foreach (Q_WebServer_Layout::enabled($confDir, 'sites') as $f) {
				$name = preg_replace('/\.(conf|json)$/', '', basename($f));
				$siteFiles[] = $name;
				if (strpos($name, '.') !== false) $add($name, 'site file', basename($f));
			}
		}

		// Domain records.
		$records = $opts['records'] ?? Q_WebServer_Domains::records();
		foreach ($records as $name => $rec) {
			$add($name, 'record', ($rec['status'] ?? 'active') . ' (' . ($rec['source'] ?? 'panel') . ')');
			foreach ((array) ($rec['aliases'] ?? array()) as $alias) $add($alias, 'record alias', $name);
		}

		// Providers (an application's host map).
		foreach (self::$providers as $label => $fn) {
			try {
				foreach ((array) call_user_func($fn) as $item) {
					if (is_array($item) and isset($item['host'])) $add($item['host'], (string) $label, $item['detail'] ?? '');
				}
			} catch (\Throwable $e) {
				// A broken provider costs its own rows, never the view.
			}
		}

		// Host headers seen.
		$seen = $opts['seen'] ?? Q_WebServer_Domains::seen();
		foreach ($seen as $h => $s) {
			$h = Q_WebServer_Domains::normalize($h);
			if ($h === '') continue;
			if (!isset($hosts[$h])) $hosts[$h] = array('host' => $h, 'sources' => array(), 'seen' => null);
			$hosts[$h]['seen'] = $s;
		}

		// States.
		foreach ($hosts as $h => &$row) {
			$configured = false;
			foreach ($row['sources'] as $s) {
				if ($s['source'] !== 'certificate') { $configured = true; break; }
			}
			$states = array();
			if ($row['seen']) $states[] = 'serving';
			if ($cert) {
				foreach ($cert['names'] as $n) {
					if (self::covers($n, $h)) { $states[] = 'covered'; break; }
				}
			}
			if ($row['seen'] and !$configured) $states[] = 'unconfigured';
			if (!$row['seen'] and $configured) $states[] = 'unseen';
			$hit = isset($records[$h]) ? array($h, $records[$h]) : null;
			if ($hit === null) {
				foreach ($records as $name => $rec) {
					if (in_array($h, array_map(array('Q_WebServer_Domains', 'normalize'), (array) ($rec['aliases'] ?? array())), true)) {
						$hit = array($name, $rec);
						break;
					}
				}
			}
			$row['record'] = $hit ? $hit[0] : null;
			$row['status'] = $hit ? ($hit[1]['status'] ?? 'active') : null;
			$row['states'] = $states;
		}
		unset($row);
		ksort($hosts);
		return array('listeners' => $listeners, 'certificate' => $cert,
			'siteFiles' => $siteFiles, 'hosts' => array_values($hosts));
	}
}
