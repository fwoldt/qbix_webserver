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
 *     "root":    document root for the domain and its aliases
 *     "app":     application name,
 *     "tls":     "auto" | "manual" | "none",
 *     "aliases": [ "www.example.com", ... ]    (served and gated as the domain)
 *     "subdomains": { "blog": "blog", "shop.example.com": "/srv/shop" }
 *                label or full host => document root; a relative root is
 *                taken under the domain's root, and must stay inside it
 *   }
 *
 * Routing: a request's Host (port and case ignored) is looked up as the
 * domain, an alias, or a subdomain, and its document root replaces the
 * server's default root for that request. A root that does not resolve to
 * an existing directory -- or, for a subdomain, resolves outside the
 * domain's root, a symlink included -- is not used; the request is then
 * served from the default root, as an unknown host always is. The server's
 * own /Q/ and /.well-known/ paths are never rerouted.
 *
 * Reserved for later, so records written now stay valid: "redirects"
 * (https, preferredHost, rules), "hsts" (enabled, maxAge), "errorDocs"
 * (status => path), "certificate". Unknown fields are kept as they are
 * when a record is updated.
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

	/**
	 * host => array(name, record, root) for every name, alias and subdomain,
	 * cached briefly; root is the resolved document root, or null.
	 */
	protected static function index()
	{
		$now = microtime(true);
		if (self::$cache !== null and $now - self::$cachedAt < self::TTL) return self::$cache;
		$index = array();
		foreach (self::records() as $name => $rec) {
			$root = self::realDir($rec['root'] ?? null);
			$index[$name] = array($name, $rec, $root);
			foreach ((array) ($rec['aliases'] ?? array()) as $alias) {
				$alias = self::normalize($alias);
				if ($alias !== '' and !isset($index[$alias])) $index[$alias] = array($name, $rec, $root);
			}
			foreach ((array) ($rec['subdomains'] ?? array()) as $sub => $subRoot) {
				$host = self::subdomainHost($sub, $name);
				if ($host === null or isset($index[$host])) continue;
				$index[$host] = array($name, $rec, self::subdomainRoot($subRoot, $root, $why, self::fence($root)));
			}
		}
		self::$cache = $index;
		self::$cachedAt = $now;
		return $index;
	}

	/** A subdomain key ("blog" or "blog.example.com") as a full host under $domain, or null. */
	static function subdomainHost($sub, $domain)
	{
		$sub = self::normalize($sub);
		if ($sub === '') return null;
		$host = (substr($sub, -strlen('.' . $domain)) === '.' . $domain) ? $sub : $sub . '.' . $domain;
		return self::validName($host) ? $host : null;
	}

	/** An existing directory, resolved (symlinks followed), or null. */
	static function realDir($path)
	{
		if (!is_string($path) or $path === '' or strpos($path, "\0") !== false) return null;
		$real = realpath($path);
		return ($real !== false and is_dir($real)) ? rtrim($real, DIRECTORY_SEPARATOR) : null;
	}

	/**
	 * A subdomain's root, resolved: relative to the domain's root when it
	 * is not absolute, and required to stay inside it (after symlinks) when
	 * the domain has a root. Null, with the reason in $why, otherwise.
	 */
	static function subdomainRoot($subRoot, $domainRoot, &$why = null, $fence = null)
	{
		$why = null;
		if ($fence === null) $fence = $domainRoot;
		if (!is_string($subRoot) or trim($subRoot) === '') { $why = 'no document root given'; return null; }
		$path = $subRoot;
		if ($path[0] !== '/' and !preg_match('#^[A-Za-z]:[\\\\/]#', $path)) {
			if ($domainRoot === null) { $why = 'a relative root needs the domain to have a root'; return null; }
			$path = $domainRoot . DIRECTORY_SEPARATOR . $path;
		}
		$real = self::realDir($path);
		if ($real === null) { $why = 'not an existing directory'; return null; }
		if ($fence !== null and $real !== $fence
			and strncmp($real, $fence . DIRECTORY_SEPARATOR, strlen($fence) + 1) !== 0) {
			$why = 'outside the domain\'s document root';
			return null;
		}
		return $real;
	}

	/**
	 * What a subdomain's root must stay inside: the domain's folder when its
	 * root follows the standard layout (<base>/<domain>/<docDirName>), so
	 * <base>/<domain>/<sub>/<docDirName> qualifies; the root itself otherwise.
	 */
	static function fence($domainRoot)
	{
		if ($domainRoot === null) return null;
		return basename($domainRoot) === self::docDirName() ? dirname($domainRoot) : $domainRoot;
	}

	/**
	 * The name of a domain's document root directory in the standard
	 * layout: Q.domains.docDirName, "doc" by default. A value that is not a
	 * single plain directory name is ignored.
	 */
	static function docDirName()
	{
		$name = class_exists('Q_Config', false) ? Q_Config::get('Q', 'domains', 'docDirName', 'doc') : 'doc';
		return (is_string($name) and preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name) and $name !== '..') ? $name : 'doc';
	}

	/**
	 * Where new domains live: Q.domains.baseDir, else the folder above the
	 * server's own domain folder when its document root follows the
	 * <base>/<domain>/... pattern, else /var/www/vhosts.
	 */
	static function baseDir()
	{
		$set = class_exists('Q_Config', false) ? Q_Config::get('Q', 'domains', 'baseDir', null) : null;
		if (is_string($set) and $set !== '' and $set[0] === '/') return rtrim($set, '/');
		$own = class_exists('Q_WebServer', false) ? rtrim((string) Q_WebServer::$rootDir, '/') : '';
		$parts = $own === '' ? array() : explode('/', ltrim($own, '/'));
		foreach ($parts as $i => $seg) {
			if ($i > 0 and strpos($seg, '.') !== false and self::validName(strtolower($seg))) {
				return '/' . implode('/', array_slice($parts, 0, $i));
			}
		}
		return '/var/www/vhosts';
	}

	/** The standard root for a new domain: <base>/<domain>/<docDirName>. */
	static function defaultRoot($domain)
	{
		return self::baseDir() . '/' . self::normalize($domain) . '/' . self::docDirName();
	}

	/** The standard root for a new subdomain: <base>/<domain>/<label>/<docDirName>. */
	static function defaultSubdomainRoot($domain, $sub)
	{
		$domain = self::normalize($domain);
		$label = self::normalize($sub);
		if (substr($label, -strlen('.' . $domain)) === '.' . $domain) $label = substr($label, 0, -strlen('.' . $domain));
		return self::baseDir() . '/' . $domain . '/' . $label . '/' . self::docDirName();
	}

	/**
	 * Create a missing directory (and missing parents), 0755, each owned like
	 * the nearest existing parent. Never touches anything that exists.
	 * @return {bool} true when it now exists as a directory made here
	 */
	static function createDir($path, &$why = null)
	{
		$why = null;
		if (!is_string($path) or $path === '' or $path[0] !== '/' or strpos($path, "\0") !== false or strpos('/' . $path . '/', '/../') !== false) {
			$why = 'not a plain absolute path';
			return false;
		}
		if (file_exists($path) or is_link($path)) { $why = 'already exists'; return false; }
		$missing = array();
		$p = $path;
		while ($p !== '/' and !file_exists($p) and !is_link($p)) { array_unshift($missing, $p); $p = dirname($p); }
		if (!is_dir($p)) { $why = "$p is not a directory"; return false; }
		$uid = @fileowner($p); $gid = @filegroup($p);
		foreach ($missing as $dir) {
			if (!@mkdir($dir, 0755)) { $why = "could not create $dir"; return false; }
			@chmod($dir, 0755);
			if ($uid !== false) @chown($dir, $uid);
			if ($gid !== false) @chgrp($dir, $gid);
		}
		return is_dir($path);
	}

	/**
	 * The document root a request's Host is served from, or null for the
	 * server's default root.
	 * @param {string} $host
	 * @return {string|null} without a trailing separator
	 */
	static function resolveRoot($host)
	{
		$host = self::normalize($host);
		if ($host === '') return null;
		$index = self::index();
		return isset($index[$host]) ? $index[$host][2] : null;
	}

	/** Set a domain's document root (null clears it). */
	static function setRoot($name, $root)
	{
		return self::update($name, function ($rec) use ($root) {
			if ($root === null) unset($rec['root']);
			else $rec['root'] = $root;
			$rec['status'] = $rec['status'] ?? 'active';
			return $rec;
		});
	}

	/** Add or remove an alias. */
	static function setAlias($name, $alias, $add = true)
	{
		$alias = self::normalize($alias);
		return self::update($name, function ($rec) use ($alias, $add) {
			$list = array_values(array_filter(array_map(array(__CLASS__, 'normalize'), (array) ($rec['aliases'] ?? array())), 'strlen'));
			$list = array_values(array_diff($list, array($alias)));
			if ($add) $list[] = $alias;
			$rec['aliases'] = $list;
			$rec['status'] = $rec['status'] ?? 'active';
			return $rec;
		});
	}

	/** Set a subdomain's root, or remove the subdomain with a null root. */
	static function setSubdomain($name, $sub, $root)
	{
		return self::update($name, function ($rec) use ($sub, $root) {
			$subs = is_array($rec['subdomains'] ?? null) ? $rec['subdomains'] : array();
			if ($root === null) unset($subs[$sub]);
			else $subs[$sub] = $root;
			if ($subs) $rec['subdomains'] = $subs;
			else unset($rec['subdomains']);
			$rec['status'] = $rec['status'] ?? 'active';
			return $rec;
		});
	}

	/** Which domain already answers to $host (as itself, an alias or a subdomain), or null. */
	static function owner($host)
	{
		$hit = self::lookup($host);
		return $hit ? $hit[0] : null;
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
