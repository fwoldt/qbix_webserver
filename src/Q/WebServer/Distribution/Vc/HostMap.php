<?php
/**
 * @module Q
 */
/**
 * The host names an Exponential installation answers to, for the control
 * panel's Domains view: its siteaccess host matching in settings/site.ini
 * (and the override), read as text -- never included.
 *
 *   [SiteAccessSettings]
 *   HostMatchMapItems[]=www.example.com;site
 *   HostUriMatchMapItems[]=example.com;shop;shop
 *   [SiteSettings]
 *   SiteURL=www.example.com
 *
 * An empty `Name[]` clears the list, as the application merges it.
 *
 * @class Q_WebServer_Distribution_Vc_HostMap
 * @static
 */
class Q_WebServer_Distribution_Vc_HostMap
{
	/**
	 * @param {string|null} $dir the installation (default: the document root)
	 * @return {array} list of array(host, detail)
	 */
	static function hosts($dir = null)
	{
		if ($dir === null) {
			$dir = class_exists('Q_WebServer', false) ? rtrim((string) Q_WebServer::$rootDir, '/\\') : '';
		}
		if ($dir === '' or !is_file("$dir/lib/version.php") or !is_dir("$dir/settings")) return array();
		$host = array();
		$hostUri = array();
		$siteUrl = '';
		foreach (array('settings/site.ini', 'settings/override/site.ini.append', 'settings/override/site.ini.append.php') as $rel) {
			$file = "$dir/$rel";
			if (!is_file($file) or @filesize($file) > 1048576) continue;
			$block = '';
			foreach (preg_split('/\r\n|\r|\n/', (string) @file_get_contents($file)) as $line) {
				$line = trim($line);
				if ($line === '' or $line[0] === '#' or $line[0] === ';') continue;
				if (preg_match('/^\[([^\]]+)\]$/', $line, $m)) { $block = $m[1]; continue; }
				if ($block === 'SiteAccessSettings' and preg_match('/^(HostMatchMapItems|HostUriMatchMapItems)\[\]\s*(=\s*(.*))?$/', $line, $m)) {
					$isUri = $m[1] === 'HostUriMatchMapItems';
					if (!isset($m[3])) { if ($isUri) $hostUri = array(); else $host = array(); continue; }
					$parts = array_map('trim', explode(';', $m[3]));
					if ($parts[0] === '') continue;
					if ($isUri) $hostUri[] = $parts;
					else $host[] = $parts;
				} elseif ($block === 'SiteSettings' and preg_match('/^SiteURL\s*=\s*(.*)$/', $line, $m)) {
					$siteUrl = trim($m[1]);
				}
			}
		}
		$out = array();
		foreach ($host as $p) {
			$out[] = array('host' => $p[0], 'detail' => 'siteaccess ' . ($p[1] ?? '?'));
		}
		foreach ($hostUri as $p) {
			$out[] = array('host' => $p[0], 'detail' => 'siteaccess ' . ($p[2] ?? '?') . ' at /' . ($p[1] ?? ''));
		}
		if ($siteUrl !== '') {
			$h = preg_replace('#^[a-z]+://#i', '', $siteUrl);
			$h = explode('/', $h)[0];
			if ($h !== '') $out[] = array('host' => $h, 'detail' => 'SiteURL');
		}
		return $out;
	}
}
