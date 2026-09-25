<?php
/**
 * @module Q
 */
/**
 * The domains this server hosts: one record per domain in the control
 * panel's store (acl/panel.json, key "domains"), plus any declared in
 * Q.webserver.domains, and the gate every request passes on its way in.
 *
 * A record, as stored (every field optional except the key):
 *
 *   "example.com": {
 *     "status":  "active" | "suspended" | "disabled",   (default active)
 *     "since":   unix time the status last changed,
 *     "note":    free text shown in the panel,
 *     "root":    document root                (read by the virtual hosts)
 *     "app":     application name,
 *     "tls":     "auto" | "manual" | "none",
 *     "aliases": [ "www.example.com", ... ]    (gated with the domain)
 *   }
 *
 * Reserved for later, so records written now stay valid: "subdomains"
 * (host => root), "redirects" (https, preferredHost, rules), "hsts"
 * (enabled, maxAge), "errorDocs" (status => path), "certificate".
 * Unknown fields are kept as they are when a record is updated.
 *
 * Status, as the gate applies it (the server's own /Q/ and /.well-known/
 * paths are never gated, so the panel and certificate renewals keep
 * working on a suspended host):
 *   active     served normally
 *   suspended  503 with Retry-After: the site is temporarily off
 *   disabled   404: the server does not serve this host at all
 *
 * @class Q_WebServer_Domains
 * @static
 */
class Q_WebServer_Domains
{
	const STATUSES = array('active', 'suspended', 'disabled');

	/** @var array|null host => record, the gate's view, refreshed every TTL seconds */
	protected static $cache = null;
	protected static $cachedAt = 0.0;
	const TTL = 2.0;

	/** @var array host => array(count, last), Host headers seen by the gate */
	protected static $seen = array();
	const SEEN_MAX = 500;

	/** A host as written in a request: lowercase, no port, no trailing dot. */
	static function normalize($host)
	{
		$host = strtolower(trim((string) $host));
		if ($host === '') return '';
		if ($host[0] === '[') {                       // [v6]:port
			$end = strpos($host, ']');
			return $end === false ? '' : substr($host, 0, $end + 1);
		}
		$host = preg_replace('/:\d+$/', '', $host);
		return rtrim($host, '.');
	}

	/** Whether a name can be a domain record key. */
	static function validName($name)
	{
		return is_string($name) and strlen($name) <= 253
			and (bool) preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $name);
	}

	/**
	 * Every record: the panel's own, over those declared in config (which
	 * the panel shows but cannot change), each with its status filled in.
	 * @return {array} name => record (plus 'source' => 'panel'|'config')
	 */
	static function records()
	{
		$out = array();
		$config = class_exists('Q_Config', false) ? Q_Config::get('Q', 'webserver', 'domains', array()) : array();
		foreach ((array) $config as $name => $rec) {
			$name = self::normalize($name);
			if ($name === '') continue;
			$out[$name] = (is_array($rec) ? $rec : array()) + array('source' => 'config');
		}
		$acl = class_exists('Q_WebServer_Panel_Store') ? Q_WebServer_Panel_Store::aclLoad() : array();
		foreach ((array) ($acl['domains'] ?? array()) as $name => $rec) {
			$name = self::normalize($name);
			if ($name === '' or !is_array($rec)) continue;
			$out[$name] = array('source' => 'panel') + $rec + ($out[$name] ?? array());
			$out[$name]['source'] = 'panel';
		}
		foreach ($out as $name => $rec) {
			if (!in_array($rec['status'] ?? 'active', self::STATUSES, true)) $out[$name]['status'] = 'active';
			$out[$name]['status'] = $out[$name]['status'] ?? 'active';
		}
		ksort($out);
		return $out;
	}

	/**
	 * Change one panel record under the store's lock: $fn gets the record
	 * (or an empty array) and returns it, or null to delete it.
	 * @return {bool}
	 */
	static function update($name, callable $fn)
	{
		$name = self::normalize($name);
		if (!self::validName($name) or !class_exists('Q_WebServer_Panel_Store')) return false;
		$ok = Q_WebServer_Panel_Store::aclUpdate(function ($acl) use ($name, $fn) {
			$domains = (array) ($acl['domains'] ?? array());
			$rec = $fn(is_array($domains[$name] ?? null) ? $domains[$name] : array());
			if ($rec === null) unset($domains[$name]);
			else $domains[$name] = $rec;
			$acl['domains'] = $domains;
			return $acl;
		});
		self::forget();
		return (bool) $ok;
	}

	/** Set a domain's status (creating the record when there is none). */
	static function setStatus($name, $status, $note = null)
	{
		if (!in_array($status, self::STATUSES, true)) return false;
		return self::update($name, function ($rec) use ($status, $note) {
			$rec['status'] = $status;
			$rec['since'] = time();
			if ($note !== null) $rec['note'] = substr((string) $note, 0, 500);
			return $rec;
		});
	}

	/** Drop the gate's cached view, after a change. */
	static function forget()
	{
		self::$cache = null;
	}

	/** host => array(name, record) for every name and alias, cached briefly. */
	protected static function index()
	{
		$now = microtime(true);
		if (self::$cache !== null and $now - self::$cachedAt < self::TTL) return self::$cache;
		$index = array();
		foreach (self::records() as $name => $rec) {
			$index[$name] = array($name, $rec);
			foreach ((array) ($rec['aliases'] ?? array()) as $alias) {
				$alias = self::normalize($alias);
				if ($alias !== '' and !isset($index[$alias])) $index[$alias] = array($name, $rec);
			}
		}
		self::$cache = $index;
		self::$cachedAt = $now;
		return $index;
	}

	/** The record a host belongs to, as array(name, record), or null. */
	static function lookup($host)
	{
		$host = self::normalize($host);
		if ($host === '') return null;
		$index = self::index();
		return $index[$host] ?? null;
	}

	/**
	 * The gate: count the Host, then answer for a suspended or disabled
	 * domain. Returns null to let the request through, or a response as
	 * array(status, headers, body) -- the shape the HTTP/2 route returns.
	 * @param {array} $parsed with 'headers' and 'path'
	 * @return {array|null}
	 */
	static function gate(array $parsed)
	{
		$host = self::normalize($parsed['headers']['host'] ?? '');
		if ($host === '') return null;
		self::noteHost($host);
		$path = (string) ($parsed['path'] ?? '/');
		if (strncmp($path, '/Q/', 3) === 0 or strncmp($path, '/.well-known/', 13) === 0) return null;
		$hit = self::lookup($host);
		if ($hit === null) return null;
		$status = $hit[1]['status'] ?? 'active';
		if ($status === 'active') return null;
		if ($status === 'suspended') {
			$body = class_exists('Q_WebServer', false)
				? Q_WebServer::renderErrorPage(503, $path, 'This site is temporarily suspended.')
				: 'This site is temporarily suspended.';
			return array('status' => 503, 'headers' => array(
				'Content-Type' => 'text/html; charset=utf-8',
				'Retry-After' => '3600',
				'Cache-Control' => 'no-store',
			), 'body' => $body);
		}
		$body = class_exists('Q_WebServer', false)
			? Q_WebServer::renderErrorPage(404, $path, 'This site is not served here.')
			: 'This site is not served here.';
		return array('status' => 404, 'headers' => array(
			'Content-Type' => 'text/html; charset=utf-8',
			'Cache-Control' => 'no-store',
		), 'body' => $body);
	}

	/** Count a Host header, keeping the most recently seen when full. */
	static function noteHost($host)
	{
		if (!isset(self::$seen[$host]) and count(self::$seen) >= self::SEEN_MAX) {
			uasort(self::$seen, function ($a, $b) { return $a[1] <=> $b[1]; });
			array_shift(self::$seen);
		}
		$c = self::$seen[$host][0] ?? 0;
		self::$seen[$host] = array($c + 1, time());
	}

	/** host => array('count' => n, 'last' => unix time) */
	static function seen()
	{
		$out = array();
		foreach (self::$seen as $h => $v) $out[$h] = array('count' => $v[0], 'last' => $v[1]);
		return $out;
	}

	/** For tests. */
	static function reset()
	{
		self::$seen = array();
		self::forget();
	}
}
