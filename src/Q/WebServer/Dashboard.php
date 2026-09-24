<?php
/**
 * @module Q
 */
/**
 * Server dashboard: comprehensive stats, live HTML display at /Q/dashboard,
 * real-time updates via Q_WebSocket on the 'dashboard' channel.
 * @class Q_WebServer_Dashboard
 */
class Q_WebServer_Dashboard
{
	static $stats = array(
		'startTime' => 0, 'requests' => 0,
		'status2xx' => 0, 'status3xx' => 0, 'status4xx' => 0, 'status5xx' => 0,
		'phpRequests' => 0, 'staticRequests' => 0,
		'bytesOut' => 0, 'totalMs' => 0,
		'slowest' => 0, 'slowestUri' => '',
	);
	static $recentRequests = array();
	static $topPaths = array(); // path => [count, totalMs]
	static $statusCodes = array(); // code => count
	static $rpsHistory = array(); // [timestamp => count] for sparkline

	static function init()
	{
		self::$stats['startTime'] = time();
		self::$maxSessions = (int) Q_Config::get('Q', 'dashboard', 'maxSessions', 20);

		// Push stats every 2 seconds so the dashboard stays live
		// even when no requests are coming in
		Q_Evented::repeat(2.0, function () {
			if (empty(Q_WebSocket::$channels['dashboard'])) return;
			Q_WebSocket::broadcastTo('dashboard', array(
				'type' => 'heartbeat', 'stats' => Q_WebServer_Dashboard::getStats()
			));
		});
	}

	static $sessions = array();  // sessionId => lastSeen timestamp (MRU order)
	static $maxSessions = 20;    // configurable cap

	static function recordRequest($method, $uri, $status, $ms, $bytes = 0,
		$isPhp = false, $contentType = '', $memUsed = 0, $cookies = array())
	{
		self::$stats['requests']++;
		self::$stats['totalMs'] += $ms;
		self::$stats['bytesOut'] += $bytes;
		if ($isPhp) self::$stats['phpRequests']++;
		else self::$stats['staticRequests']++;

		if ($ms > self::$stats['slowest']) {
			self::$stats['slowest'] = $ms;
			self::$stats['slowestUri'] = $uri;
		}

		if ($status < 300) self::$stats['status2xx']++;
		elseif ($status < 400) self::$stats['status3xx']++;
		elseif ($status < 500) self::$stats['status4xx']++;
		else self::$stats['status5xx']++;

		if (!isset(self::$statusCodes[$status])) self::$statusCodes[$status] = 0;
		self::$statusCodes[$status]++;

		$pathKey = $method . ' ' . strtok($uri, '?');
		if (!isset(self::$topPaths[$pathKey])) self::$topPaths[$pathKey] = array(0, 0);
		self::$topPaths[$pathKey][0]++;
		self::$topPaths[$pathKey][1] += $ms;

		$sec = time();
		if (!isset(self::$rpsHistory[$sec])) self::$rpsHistory[$sec] = 0;
		self::$rpsHistory[$sec]++;
		$cutoff = $sec - 60;
		foreach (self::$rpsHistory as $t => $c) {
			if ($t < $cutoff) unset(self::$rpsHistory[$t]);
			else break;
		}

		$kind = $isPhp ? 'php' : self::mimeKind($uri, $contentType);

		// Detect session ID from cookies
		$sessionId = '';
		foreach ($cookies as $name => $val) {
			if ($name === 'PHPSESSID' || strpos($name, 'sessionId') === 0
				|| strpos($name, 'Q_sessionId') === 0
			) {
				$sessionId = substr($val, 0, 12); // short prefix for display
				break;
			}
		}
		if ($sessionId !== '') {
			self::$sessions[$sessionId] = time();
			// Evict beyond cap
			if (count(self::$sessions) > self::$maxSessions) {
				asort(self::$sessions);
				self::$sessions = array_slice(self::$sessions,
					-self::$maxSessions, null, true);
			}
		}

		$entry = array('time' => date('H:i:s'), 'method' => $method,
			'uri' => $uri, 'status' => $status, 'ms' => round($ms, 1), 'kind' => $kind,
			'mem' => $memUsed, 'sid' => $sessionId);
		self::$recentRequests[] = $entry;
		if (count(self::$recentRequests) > 200) array_shift(self::$recentRequests);

		// Only build stats + broadcast if a dashboard client is connected
		if (!empty(Q_WebSocket::$channels['dashboard'])) {
			Q_WebSocket::broadcastTo('dashboard', array(
				'type' => 'request', 'entry' => $entry, 'stats' => self::getStats()
			));
		}
	}

	/**
	 * Map URI extension or content-type to a kind for dashboard icons.
	 * @return {string} php, html, css, js, img, font, json, xml, doc, media, file
	 */
	static function mimeKind($uri, $contentType = '')
	{
		$ext = strtolower(pathinfo(strtok($uri, '?') ?: '', PATHINFO_EXTENSION));
		static $map = array(
			'html' => 'html', 'htm' => 'html',
			'css' => 'css', 'less' => 'css', 'scss' => 'css',
			'js' => 'js', 'mjs' => 'js', 'ts' => 'js',
			'png' => 'img', 'jpg' => 'img', 'jpeg' => 'img', 'gif' => 'img',
			'svg' => 'img', 'webp' => 'img', 'ico' => 'img', 'avif' => 'img',
			'woff' => 'font', 'woff2' => 'font', 'ttf' => 'font', 'otf' => 'font', 'eot' => 'font',
			'json' => 'json', 'xml' => 'xml', 'rss' => 'xml',
			'pdf' => 'doc', 'doc' => 'doc', 'docx' => 'doc', 'txt' => 'doc', 'md' => 'doc',
			'mp4' => 'media', 'webm' => 'media', 'mp3' => 'media', 'ogg' => 'media',
			'zip' => 'file', 'gz' => 'file', 'tar' => 'file',
		);
		if (isset($map[$ext])) return $map[$ext];
		if ($contentType) {
			if (strpos($contentType, 'html') !== false) return 'html';
			if (strpos($contentType, 'css') !== false) return 'css';
			if (strpos($contentType, 'javascript') !== false) return 'js';
			if (strpos($contentType, 'image/') !== false) return 'img';
			if (strpos($contentType, 'json') !== false) return 'json';
		}
		return 'file';
	}


	static function getStats()
	{
		$up = time() - self::$stats['startTime'];
		$pool = Q_WebServer::$pool;
		$reqs = self::$stats['requests'];
		$avgMs = $reqs > 0 ? round(self::$stats['totalMs'] / $reqs, 1) : 0;
		$rps = $up > 0 ? round($reqs / $up, 1) : 0;

		// Current RPS (last 5 seconds)
		$now = time();
		$recent5 = 0;
		for ($i = 1; $i <= 5; $i++) {
			$recent5 += self::$rpsHistory[$now - $i] ?? 0;
		}
		$currentRps = round($recent5 / 5, 1);

		// Top 10 paths by count
		$topPaths = self::$topPaths;
		uasort($topPaths, function($a, $b) { return $b[0] - $a[0]; });
		$topPaths = array_slice($topPaths, 0, 10, true);
		$topFormatted = array();
		foreach ($topPaths as $path => $data) {
			$topFormatted[] = array(
				'path' => $path,
				'count' => $data[0],
				'avgMs' => $data[0] > 0 ? round($data[1] / $data[0], 1) : 0,
			);
		}

		// RPS sparkline data (last 60 seconds)
		$sparkline = array();
		for ($i = 59; $i >= 0; $i--) {
			$sparkline[] = self::$rpsHistory[$now - $i] ?? 0;
		}

		// Connection counts
		$keepAlive = count(Q_WebServer::$keepAliveCount);
		$wsConnections = count(Q_WebSocket::$workers);
		$wsRooms = count(Q_WebSocket::$roomWorkers);
		$activeRooms = array();
		foreach (Q_WebSocket::$roomWorkers as $name => $rw) {
			$activeRooms[] = array(
				'name' => $name,
				'members' => count($rw['members'] ?? array()),
			);
		}

		return array(
			'uptime' => self::fmtUp($up), 'uptimeSec' => $up,
			'requests' => $reqs,
			'rps' => $rps, 'currentRps' => $currentRps,
			'avgMs' => $avgMs,
			// Rounded here rather than where it is displayed. microtime()
			// subtraction gives thirteen decimal places, and the dashboard
			// printed all of them -- "slowest: 1541.8879985809326ms" --
			// which overflowed the card it sits in. avgMs a few lines up was
			// already rounded, so the two numbers beside each other disagreed
			// about how precise this server's timings are. Rounding at the
			// source fixes /Q/health and anything else reading this too.
			'slowest' => round(self::$stats['slowest'], 1),
			'slowestUri' => self::$stats['slowestUri'],
			'status2xx' => self::$stats['status2xx'],
			'status3xx' => self::$stats['status3xx'],
			'status4xx' => self::$stats['status4xx'],
			'status5xx' => self::$stats['status5xx'],
			'statusCodes' => self::$statusCodes,
			'phpRequests' => self::$stats['phpRequests'],
			'staticRequests' => self::$stats['staticRequests'],
			'bytesOut' => self::$stats['bytesOut'],
			'bytesFormatted' => self::fmtBytes(self::$stats['bytesOut']),
			'memory' => round(memory_get_usage(true)/1048576, 1),
			'memoryPeak' => round(memory_get_peak_usage(true)/1048576, 1),
			// "idle/live". A dynamic pool runs fewer workers than its maximum while
			// quiet, so the maximum is sent on its own.
			'workers' => $pool ? $pool->idleCount().'/'.$pool->liveCount() : 'fork',
			'workersMax' => $pool ? $pool->targetSize : 0,
			'workersSpare' => $pool ? $pool->spareWorkers : 0,
			'workerStats' => $pool ? self::cachedWorkerStats($pool) : null,
			'systemRam' => self::cachedSystemRam(),
			'parentPid' => getmypid(),
			'forkMode' => !$pool,
			'wsClients' => Q_WebSocket::clientCount(),
			'wsConnections' => $wsConnections,
			'wsRooms' => $wsRooms,
			'activeRooms' => $activeRooms,
			'connections' => count(Q_WebServer::$clients),
			'keepAlive' => $keepAlive,
			'topPaths' => $topFormatted,
			'sparkline' => $sparkline,
			'cache' => Q_WebServer_Cache::stats(),
			'log' => Q_WebServer_Log::stats(),
			'components' => Q_WebServer_Cache_Components::enabled()
				? Q_WebServer_Cache_Components::stats() : null,
			'php' => PHP_VERSION,
			'os' => PHP_OS,
			'sessions' => self::getSessionList(),
		);
	}

	/**
	 * Return sessions sorted by most recently used (newest first).
	 */
	static function getSessionList()
	{
		if (empty(self::$sessions)) return array();
		arsort(self::$sessions); // highest timestamp first = most recent
		$list = array();
		foreach (self::$sessions as $sid => $ts) {
			$list[] = array('id' => $sid, 'last' => date('H:i:s', $ts));
		}
		return $list;
	}

	static function handle($client, $parsed)
	{
		$p = $parsed['path'];
		if ($p === '/Q/dashboard' || $p === '/Q/dashboard/') {
			// If panel password is set, require auth (cookie or query token)
			if (Q_WebServer_Panel::hasPassword()) {
				$cookie = $parsed['cookies']['Q_panel_token'] ?? '';
				$qp = array();
				if (!empty($parsed['query'])) parse_str($parsed['query'], $qp);
				$qToken = $qp['token'] ?? '';
				if (!Q_WebServer_Panel::validateToken($cookie)
					&& !Q_WebServer_Panel::validateToken($qToken)
				) {
					Q_WebServer::sendRedirect($client,
						'/Q/panel?next=' . urlencode('/Q/dashboard'));
					return true;
				}
			}
			Q_WebServer::sendResponse($client, 200, self::renderHtml($parsed), 'text/html; charset=utf-8');
			return true;
		}
		if ($p === '/Q/stats') {
			Q_WebServer::sendResponse($client, 200, json_encode(self::getStats()), 'application/json');
			return true;
		}
		return false;
	}

	// ── Cached stats (avoid shell calls every heartbeat) ──

	private static $cachedRam = null;
	private static $cachedRamTime = 0;
	private static $cachedWs = null;
	private static $cachedWsTime = 0;

	static function cachedSystemRam()
	{
		$now = time();
		if (self::$cachedRam && ($now - self::$cachedRamTime) < 5) {
			return self::$cachedRam;
		}
		self::$cachedRam = self::getSystemRam();
		self::$cachedRamTime = $now;
		return self::$cachedRam;
	}

	static function cachedWorkerStats($pool)
	{
		$now = time();
		if (self::$cachedWs && ($now - self::$cachedWsTime) < 15) {
			// Update idle count (cheap) even on cache hit
			self::$cachedWs['idle'] = $pool->idleCount();
			return self::$cachedWs;
		}
		self::$cachedWs = $pool->getWorkerStats();
		self::$cachedWsTime = $now;
		return self::$cachedWs;
	}

	static function getSystemRam()
	{
		// Linux: read /proc/meminfo
		$meminfo = @file_get_contents('/proc/meminfo');
		if ($meminfo) {
			$info = array();
			foreach (explode("\n", $meminfo) as $line) {
				if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) {
					$info[$m[1]] = (int) $m[2]; // kB
				}
			}
			$total = ($info['MemTotal'] ?? 0) / 1024;
			$available = ($info['MemAvailable'] ?? 0) / 1024;
			$used = $total - $available;
			// Swap in use is the signal a RAM percentage hides: a box can read
			// a comfortable 42% while it has pushed gigabytes to disk under
			// earlier pressure, which is the opposite of comfortable.
			$swapTotal = ($info['SwapTotal'] ?? 0) / 1024;
			$swapFree = ($info['SwapFree'] ?? 0) / 1024;
			$swapUsed = max(0, $swapTotal - $swapFree);
			return array(
				'totalMb' => round($total),
				'usedMb' => round($used),
				'availableMb' => round($available),
				'percent' => $total > 0 ? round($used / $total * 100) : 0,
				'swapTotalMb' => round($swapTotal),
				'swapUsedMb' => round($swapUsed),
				'swapPercent' => $swapTotal > 0 ? round($swapUsed / $swapTotal * 100) : 0,
			);
		}
		// macOS: sysctl + vm_stat
		if (PHP_OS_FAMILY === 'Darwin') {
			$totalBytes = (int) trim(shell_exec('sysctl -n hw.memsize 2>/dev/null') ?? '0');
			$vmstat = @shell_exec('vm_stat 2>/dev/null');
			if ($totalBytes && $vmstat) {
				$pageSize = 4096;
				if (preg_match('/page size of (\d+)/', $vmstat, $ps)) $pageSize = (int) $ps[1];
				preg_match('/Pages free:\s+(\d+)/', $vmstat, $mf);
				preg_match('/Pages active:\s+(\d+)/', $vmstat, $ma);
				preg_match('/Pages inactive:\s+(\d+)/', $vmstat, $mi);
				preg_match('/Pages speculative:\s+(\d+)/', $vmstat, $ms);
				preg_match('/Pages wired down:\s+(\d+)/', $vmstat, $mw);
				preg_match('/Pages occupied by compressor:\s+(\d+)/', $vmstat, $mc);
				$total = $totalBytes / 1048576;
				$used = ((int)($ma[1] ?? 0) + (int)($mw[1] ?? 0) + (int)($mc[1] ?? 0)) * $pageSize / 1048576;
				$available = $total - $used;
				return array(
					'totalMb' => round($total),
					'usedMb' => round($used),
					'availableMb' => round($available),
					'percent' => $total > 0 ? round($used / $total * 100) : 0,
				);
			}
		}
		// Windows: wmic or powershell
		if (PHP_OS_FAMILY === 'Windows') {
			$out = @shell_exec('wmic OS get TotalVisibleMemorySize,FreePhysicalMemory /VALUE 2>NUL');
			if ($out) {
				$total = 0;
				$free = 0;
				if (preg_match('/TotalVisibleMemorySize=(\d+)/', $out, $m)) $total = (int) $m[1] / 1024;
				if (preg_match('/FreePhysicalMemory=(\d+)/', $out, $m)) $free = (int) $m[1] / 1024;
				$used = $total - $free;
				return array(
					'totalMb' => round($total),
					'usedMb' => round($used),
					'availableMb' => round($free),
					'percent' => $total > 0 ? round($used / $total * 100) : 0,
				);
			}
			// Fallback: PowerShell
			$out = @shell_exec('powershell -Command "Get-CimInstance Win32_OperatingSystem | Select TotalVisibleMemorySize,FreePhysicalMemory | ConvertTo-Json" 2>NUL');
			if ($out) {
				$d = json_decode($out, true);
				if ($d) {
					$total = ($d['TotalVisibleMemorySize'] ?? 0) / 1024;
					$free = ($d['FreePhysicalMemory'] ?? 0) / 1024;
					return array(
						'totalMb' => round($total),
						'usedMb' => round($total - $free),
						'availableMb' => round($free),
						'percent' => $total > 0 ? round(($total - $free) / $total * 100) : 0,
					);
				}
			}
		}
		return null;
	}

	static function fmtUp($s) {
		if ($s < 60) return "{$s}s";
		$d = floor($s/86400); $h = floor(($s%86400)/3600);
		$m = floor(($s%3600)/60);
		if ($d > 0) return "{$d}d {$h}h {$m}m";
		if ($h > 0) return "{$h}h {$m}m";
		return "{$m}m ".($s%60).'s';
	}

	/**
	 * A duration as a person reads it: "10.6 ms" under a second, "3.64 s"
	 * from one. The script's fmtMs() is the same rule, so the rows served
	 * first and the rows the live refresh writes cannot disagree.
	 */
	static function fmtMs($ms) {
		$ms = (float) $ms;
		$r = round($ms, 1);
		if ($r < 1000) return $r . ' ms';
		return number_format($ms / 1000, 2, '.', '') . ' s';
	}

	/**
	 * One "Top paths" row: the path, truncated by CSS, then the request
	 * count and the average time as separate labelled values. They used to
	 * sit in bare adjacent spans and read as one string ("GET /643636.2ms").
	 * The path is request data, so it is escaped for the text and the title
	 * attribute. Mirrors tpRow() in the page script character for character:
	 * ENT_COMPAT because esc() there leaves ' alone, which is safe inside a
	 * double-quoted attribute.
	 */
	static function topPathRow($p) {
		$path = htmlspecialchars((string) $p['path'], ENT_COMPAT | ENT_SUBSTITUTE, 'UTF-8');
		return '<div class="tp"><span class="p" title="' . $path . '">' . $path
			. '</span><span class="c">' . (int) $p['count'] . ' req</span>'
			. '<span class="a">' . self::fmtMs($p['avgMs']) . ' avg</span></div>';
	}

	static function topPathsHtml($topPaths) {
		$out = '';
		foreach ((array) $topPaths as $p) $out .= self::topPathRow($p);
		return $out;
	}

	/**
	 * Severity of system RAM use: 'ok' under 70%, 'warn' from 70% to under
	 * 90%, 'crit' from 90%. ramLevel() in the page script is the same rule.
	 */
	static function ramLevel($percent) {
		$percent = (float) $percent;
		if ($percent >= 90) return 'crit';
		if ($percent >= 70) return 'warn';
		return 'ok';
	}

	/**
	 * Severity of swap: 'none' when nothing is swapped, 'warn' when any is,
	 * 'crit' when more than half of a known swap total is in use.
	 */
	static function swapLevel($usedMb, $totalMb = 0) {
		$usedMb = (float) $usedMb; $totalMb = (float) $totalMb;
		if ($usedMb <= 0) return 'none';
		if ($totalMb > 0 and $usedMb > $totalMb / 2) return 'crit';
		return 'warn';
	}

	/** One decimal, trailing ".0" dropped, as JavaScript prints a number. */
	private static function num1($v) {
		$r = round((float) $v, 1);
		return ($r == floor($r)) ? (string) (int) $r : (string) $r;
	}

	static function ramPercentHtml($ram) {
		return '<span class="sev-' . self::ramLevel($ram['percent'] ?? 0) . '">'
			. (int) ($ram['percent'] ?? 0) . '%</span>';
	}

	/**
	 * "18.5 / 46.8 GB · 4.7 GB swap", with the swap part in its own span so
	 * it takes its severity colour. Swap is shown whenever the host reports
	 * any (Linux); a platform that sends no swap figures gets no swap part.
	 * Mirrors ramDetail() in the page script.
	 */
	static function ramDetailHtml($ram) {
		$out = self::num1(($ram['usedMb'] ?? 0) / 1024) . ' / '
			. self::num1(($ram['totalMb'] ?? 0) / 1024) . ' GB';
		$swTotal = (float) ($ram['swapTotalMb'] ?? 0);
		$sw = (float) ($ram['swapUsedMb'] ?? 0);
		if ($swTotal > 0 or $sw > 0) {
			$amt = ($sw > 0 and $sw < 1024) ? ((int) round($sw)) . ' MB' : self::num1($sw / 1024) . ' GB';
			$out .= ' &#183; <span class="sev-' . self::swapLevel($sw, $swTotal) . '">' . $amt . ' swap</span>';
		}
		return $out;
	}

	static function fmtBytes($b) {
		if ($b < 1024) return $b . ' B';
		if ($b < 1048576) return round($b/1024, 1) . ' KB';
		if ($b < 1073741824) return round($b/1048576, 1) . ' MB';
		return round($b/1073741824, 2) . ' GB';
	}

	static function renderHtml($parsed)
	{
		// Product name, configurable, default "Qbix Server". The heredoc
		// below interpolates $brand for the chrome a person sees; $brandJs
		// is the same value JSON-encoded for the one place the script
		// rebuilds the subtitle. htmlspecialchars because it is operator
		// input reaching HTML, trusted or not.
		$brand = class_exists('Q_WebServer', false)
			? Q_WebServer::brand() : 'Qbix Server';
		$brand = htmlspecialchars($brand, ENT_QUOTES, 'UTF-8');
		$brandJs = json_encode($brand);
		$verLabel = function_exists('qbix_version_label')
			? qbix_version_label(true)
			: (defined('QBIX_SERVER_VERSION') ? QBIX_SERVER_VERSION : '');
		$verLabel = htmlspecialchars($verLabel, ENT_QUOTES, 'UTF-8');
		$brandUrl = class_exists('Q_WebServer', false) ? Q_WebServer::brandLink('brandUrl') : '';
		$maintainer = class_exists('Q_WebServer', false) ? Q_WebServer::brandLink('maintainer') : '';
		$maintainerUrl = class_exists('Q_WebServer', false) ? Q_WebServer::brandLink('maintainerUrl') : '';
		$brandUrl = htmlspecialchars($brandUrl, ENT_QUOTES, 'UTF-8');
		$maintainer = htmlspecialchars($maintainer, ENT_QUOTES, 'UTF-8');
		$maintainerUrl = htmlspecialchars($maintainerUrl, ENT_QUOTES, 'UTF-8');
		// The brand name, linked to the product home when one is configured.
		// The header brand links to the dashboard itself; the footer links to
		// the product. Different destinations, same name.
		$brandHeader = '<a href="/Q/dashboard" style="color:inherit;text-decoration:none">' . $brand . '</a>';
		$brandName = ($brandUrl !== '')
			? '<a href="' . $brandUrl . '" target="_blank" rel="noopener" style="color:inherit;text-decoration:none">' . $brand . '</a>'
			: $brand;
		// The maintainer line, shown only when a maintainer is named.
		$maintainedBy = '';
		if ($maintainer !== '') {
			$inner = '<span>Maintained by ' . $maintainer . '</span>';
			if ($maintainerUrl !== '') {
				$inner = '<a href="' . $maintainerUrl . '" target="_blank" rel="noopener">' . $inner . '</a>';
			}
			$maintainedBy = '<div class="foot-by">' . $inner . '</div>';
		}
		$statsArr = self::getStats();
		$stats = json_encode($statsArr);
		// Cards rendered here as well as by U(), from the same helpers the
		// script mirrors, so the first paint already reads correctly.
		$topPathsHtml = self::topPathsHtml($statsArr['topPaths'] ?? array());
		$ramSrv = $statsArr['systemRam'] ?? null;
		$sysramHtml = $ramSrv ? self::ramPercentHtml($ramSrv) : '&#8212;';
		$sysramDetailHtml = $ramSrv ? self::ramDetailHtml($ramSrv) : '&#8212;';
		$recent = json_encode(array_reverse(array_slice(self::$recentRequests, -50)));
		$host = $parsed['headers']['host'] ?? 'localhost';

		// Get auth token for WebSocket connection
		$wsToken = '';
		$cookie = $parsed['cookies']['Q_panel_token'] ?? '';
		if ($cookie && Q_WebServer_Panel::validateToken($cookie)) {
			$wsToken = $cookie;
		}
		if (!$wsToken) {
			$qp = array();
			if (!empty($parsed['query'])) parse_str($parsed['query'], $qp);
			$wsToken = $qp['token'] ?? '';
		}
		if (!$wsToken) {
			$wsToken = Q_Config::get('Q', 'dashboard', 'token', '');
		}

		$tokenParam = $wsToken ? "?token=$wsToken" : '';
		// Scheme and host are chosen in the browser from location, below --
		// a hardcoded ws:// is blocked as mixed content on an https page.
		$baseUrl = "http://$host";
		// Icons, manifest and link-preview tags (Q_WebServer_Brand).
		$brandHead = Q_WebServer_Brand::headTags(Q_WebServer::brand() . ' Dashboard', '/Q/dashboard');
		return <<<HTML
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark">
<title>$brand Dashboard</title>
$brandHead<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#0f1117;--sfc:#1a1d27;--sfc2:#222533;--bdr:#2a2d3a;--txt:#e1e4ed;--dim:#6b7089;
--ac:#7c8aff;--grn:#4ade80;--yel:#fbbf24;--red:#f87171;--cyn:#22d3ee;--pur:#a78bfa}
@media(prefers-color-scheme:light){:root{--bg:#f4f5f7;--sfc:#fff;--sfc2:#f0f1f3;--bdr:rgba(0,0,0,.1);--txt:#1a1a2e;--dim:#6b7089}}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
background:var(--bg);color:var(--txt);padding:24px;font-size:13px;max-width:1200px;margin:0 auto}
h1{font-size:20px;font-weight:600;margin-bottom:4px;color:var(--ac);display:flex;align-items:center;gap:10px}
h1 .dot{width:8px;height:8px;border-radius:50%;background:var(--grn);animation:pulse 2s ease-in-out infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
.sub{font-size:12px;color:var(--dim);margin-bottom:20px}.foot{font-size:11px;color:var(--dim);text-align:center;margin-top:28px;padding-top:16px;border-top:1px solid rgba(128,128,128,.18)}.foot a{color:var(--dim);text-decoration:none}.foot a:hover{color:var(--txt)}.foot-by{text-align:center;margin-top:12px}.foot-by a{text-decoration:none}.foot-by span{display:inline-block;color:#ff7a1a;border:1px solid #fff;border-radius:6px;padding:4px 14px;font-size:11px;letter-spacing:.02em}
.foot-docs{font-size:11px;color:var(--dim);text-align:center;margin-top:12px;overflow-wrap:anywhere}.foot-docs a{color:var(--dim);text-decoration:none}.foot-docs a:hover{color:var(--txt)}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-bottom:20px}
.card{background:var(--sfc);border:1px solid var(--bdr);border-radius:8px;padding:14px}
.card .l{font-size:10px;color:var(--dim);text-transform:uppercase;letter-spacing:.8px;margin-bottom:6px}
.card .v{font-size:22px;font-weight:700;line-height:1.2}
.card .s{font-size:11px;color:var(--dim);margin-top:4px}
.vl{font-size:12px;line-height:1.7}.vl div{display:flex;justify-content:space-between;gap:8px}.vl b{font-weight:700}.card .s.vl{font-size:11px;line-height:1.6}
.row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px}
@media(max-width:700px){.row{grid-template-columns:1fr}.grid{grid-template-columns:repeat(auto-fit,minmax(100px,1fr))}}
.panel{background:var(--sfc);border:1px solid var(--bdr);border-radius:8px;overflow:hidden}
.ph{padding:10px 14px;border-bottom:1px solid var(--bdr);font-weight:600;font-size:12px;
display:flex;justify-content:space-between;align-items:center}
.ph-btns{display:flex;gap:6px;align-items:center}
.ph-btn{background:none;border:1px solid var(--bdr);color:var(--dim);border-radius:4px;
padding:2px 8px;font-size:10px;cursor:pointer;font-family:inherit;transition:all .15s}
.ph-btn:hover{color:var(--txt);border-color:var(--txt)}
.ph-btn.active{color:var(--ac);border-color:var(--ac)}
.ph-sel{background:var(--sfc2);border:1px solid var(--bdr);color:var(--txt);border-radius:4px;
padding:2px 6px;font-size:10px;font-family:inherit;cursor:pointer;max-width:140px}
.pb{padding:8px 14px;max-height:260px;overflow-y:auto}
.spark{height:40px;display:flex;align-items:flex-end;gap:1px;margin:8px 14px}
.spark div{flex:1;background:var(--ac);border-radius:1px 1px 0 0;min-height:1px;opacity:.7;transition:height .3s}
.le{padding:4px 14px;font-size:12px;display:flex;gap:8px;align-items:center;
border-bottom:1px solid rgba(255,255,255,.03);font-family:'SF Mono','Fira Code',Consolas,monospace;
transition:background .1s}
.le:hover{background:rgba(255,255,255,.04)}
.le .lk{min-width:18px;text-align:center;font-size:13px;flex-shrink:0}
.le .lt{color:var(--dim);min-width:58px;flex-shrink:0}
.le .ls{min-width:28px;font-weight:700;text-align:right;flex-shrink:0}
.le .lm{min-width:36px;color:var(--cyn);flex-shrink:0}
.le .lu{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0}
.le .lu a{color:inherit;text-decoration:none}
.le .lu a:hover{text-decoration:underline}
.le .ld{color:var(--dim);min-width:48px;text-align:right;flex-shrink:0}
.le .lmem{color:var(--pur);min-width:56px;text-align:right;flex-shrink:0;font-size:11px}
.s2{color:var(--grn)}.s3{color:var(--yel)}.s4,.s5{color:var(--red)}
.sev-ok{color:var(--grn)}.sev-warn{color:var(--yel)}.sev-crit{color:var(--red)}.sev-none{color:var(--dim)}
.tp{display:flex;justify-content:space-between;align-items:baseline;gap:12px;padding:4px 0;font-size:12px;border-bottom:1px solid rgba(255,255,255,.03)}
.tp .p{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-family:'SF Mono',monospace}
.tp .c{min-width:50px;text-align:right;color:var(--ac);white-space:nowrap;flex-shrink:0}.tp .a{min-width:72px;text-align:right;color:var(--dim);white-space:nowrap;flex-shrink:0}
.ws{display:inline-flex;align-items:center;gap:6px;font-size:11px}
.wd{width:6px;height:6px;border-radius:50%;background:var(--red)}.wd.on{background:var(--grn)}
.room{display:flex;justify-content:space-between;padding:4px 0;font-size:12px}
.room .n{font-family:'SF Mono',monospace;color:var(--pur)}
.log-wrap{max-height:50vh;overflow-y:auto;display:flex;flex-direction:column-reverse}.le.lh{font-weight:700;color:var(--dim);text-transform:uppercase;letter-spacing:.04em;font-size:10px;border-bottom:1px solid rgba(128,128,128,.22);position:sticky;top:0;background:var(--bg,#111);z-index:1}.le.lh span{color:inherit}
</style></head><body>
<h1><span class="dot"></span>$brandHeader</h1>
<div class="sub" id="sub"></div>

<div class="grid">
<div class="card"><div class="l">Total requests</div><div class="v" id="sr">0</div><div class="s" id="srps">0 avg req/s</div></div>
<div class="card"><div class="l">Current RPS</div><div class="v" id="crps" style="color:var(--cyn)">0</div><div class="s">last 5 sec</div></div>
<div class="card"><div class="l">Avg response</div><div class="v" id="avg">0<span style="font-size:12px;font-weight:400">ms</span></div><div class="s">slowest: <span id="slow">0ms</span></div></div>
<div class="card"><div class="l">Parent Memory</div><div class="v" id="sm">&#8212;</div><div class="s">peak <span id="smp">&#8212;</span></div></div>
<div class="card"><div class="l">Workers</div><div class="v" id="sw">&#8212;</div><div class="s vl">
<div><span>idle</span><b id="swi">&#8212;</b></div>
<div><span>busy</span><b id="swb">&#8212;</b></div>
<div><span>PHP requests served</span><b id="phpn">0</b></div>
<div><span>static files served</span><b id="stn">0</b></div></div></div>
<div class="card"><div class="l">System RAM</div><div class="v" id="sysram">$sysramHtml</div><div class="s" id="sysram-detail">$sysramDetailHtml</div></div>
<div class="card"><div class="l">Worker Memory (COW)</div><div class="v" id="cow-total">&#8212;</div><div class="s vl" id="cow-detail">&#8212;</div></div>
<div class="card"><div class="l">WebSocket</div><div class="v" id="wsc" style="color:var(--pur)">0</div><div class="s"><span id="wsr">0</span> rooms</div></div>
<div class="card"><div class="l">Data out</div><div class="v" id="bout">0</div><div class="s"><span id="conn">0</span> conn &#183; <span id="ka">0</span> keep-alive</div></div>
<div class="card"><div class="l">Status codes</div><div class="vl">
<div><span>ok</span><b class="s2" id="s2">0</b></div>
<div><span>redir</span><b class="s3" id="s3">0</b></div>
<div><span>4xx</span><b class="s4" id="s4">0</b></div>
<div><span>5xx</span><b class="s5" id="s5">0</b></div></div></div>
</div>

<div class="panel" style="margin-bottom:16px"><div class="ph">Throughput <span style="font-size:11px;color:var(--dim)">last 60s</span></div>
<div class="spark" id="spark"></div></div>

<div class="row">
<div class="panel"><div class="ph">Top paths</div><div class="pb" id="paths">$topPathsHtml</div></div>
<div class="panel"><div class="ph">Active rooms</div><div class="pb" id="rooms"><div style="color:var(--dim);padding:8px;font-size:12px">No active rooms</div></div></div>
</div>

<div class="panel"><div class="ph"><span>Live requests <span style="font-size:11px;color:var(--dim)" id="reqc">0 total</span></span>
<div class="ph-btns">
<select id="sc-filter" onchange="filterStatus()" title="Filter by status code" class="ph-sel">
<option value="">All status codes</option>
</select>
<select id="sid-filter" onchange="filterSession()" title="Filter by session" class="ph-sel">
<option value="">All sessions</option>
</select>
<button class="ph-btn" id="btn-pause" onclick="togglePause()" title="Pause/resume">&#9208;</button>
<button class="ph-btn" onclick="clearLog()" title="Clear log">&#10005;</button>
</div></div>
<div class="le lh"><span class="lk"></span><span class="lt">Time</span><span class="ls">Sts</span><span class="lm">Verb</span><span class="lu">Path</span><span class="ld">ms</span><span class="lmem">Mem</span></div><div class="log-wrap" id="log-wrap"><div id="log"></div></div></div>

<script>
var S=$stats,R=$recent,BASE='$baseUrl',
    L=document.getElementById('log'),SP=document.getElementById('spark'),
    LW=document.getElementById('log-wrap'),paused=false,MAX_LOG=300,
    sidFilter='',scFilter='',knownSids={},knownCodes={};

// Status code descriptions
var SC={'200':'OK','201':'Created','204':'No Content','206':'Partial',
'301':'Moved','302':'Found','304':'Not Modified','307':'Redirect','308':'Permanent',
'400':'Bad Request','401':'Unauthorized','403':'Forbidden','404':'Not Found',
'405':'Method Not Allowed','408':'Timeout','413':'Too Large','414':'URI Too Long',
'429':'Too Many Requests','500':'Internal Error','502':'Bad Gateway',
'503':'Unavailable','504':'Gateway Timeout'};

// Uptime ticker — animate every second client-side
var upSec=0,upTimer=null;
function fmtUp(s){
if(s<60)return s+'s';
var d=Math.floor(s/86400),h=Math.floor((s%86400)/3600),m=Math.floor((s%3600)/60),ss=s%60;
if(d>0)return d+'d '+h+'h '+m+'m';
if(h>0)return h+'h '+m+'m '+ss+'s';
return m+'m '+ss+'s';
}
// OS, then PHP, then the Live/Connecting status, uptime last.
function tickUp(){upSec++;el('sub',(S.os||'')+' \u00B7 PHP '+(S.php||'')+' \u00B7 <span class="ws"><span class="wd'+(wsLive?' on':'')+'" id="wd"></span><span id="wl">'+(wsLive?'Live':'Connecting')+'</span></span> \u00B7 Up '+fmtUp(upSec))}
var wsLive=false;

// Sparkline ticker — shift left every second even when idle
var spData=new Array(60).fill(0),spDirty=false;
function tickSpark(){
spData.push(0);if(spData.length>60)spData.shift();
renderSpark();
}
function renderSpark(){
var mx=Math.max.apply(null,spData)||1;
SP.innerHTML=spData.map(function(v){return'<div style="height:'+Math.max(1,v/mx*36)+'px" title="'+v+' req/s"></div>'}).join('');
}

function U(s){S=s;
el('sr',s.requests.toLocaleString());
el('crps',s.currentRps);
el('avg',s.avgMs+'<span style="font-size:12px;font-weight:400">ms</span>');
el('slow',s.slowest+'ms');
el('sm',s.memory+' MB');el('smp',s.memoryPeak+' MB');
// s.workers is "idle/live". Shown as the live count, with idle and busy on their
// own labelled rows: "590/590" read as a count of something unexplained. A
// dynamic pool (workersSpare > 0) also shows the most it will grow to.
(function(){var w=String(s.workers).split('/');
if(w.length===2){var idle=+w[0],total=+w[1];el('sw',total+(s.workersSpare>0&&s.workersMax>total?' <span style="font-size:10px;color:var(--dim)">of '+s.workersMax+'</span>':''));el('swi',idle+'');el('swb',(total-idle)+'')}
else{el('sw',s.workers+(s.forkMode?' <span style="font-size:10px;color:var(--yel)">(fork mode)</span>':''));el('swi','\u2014');el('swb','\u2014')}})();el('wsc',s.wsConnections);el('wsr',s.wsRooms);
// System RAM
if(s.systemRam){
  el('sysram',ramPct(s.systemRam));
  el('sysram-detail',ramDetail(s.systemRam));
}
// Worker COW stats
if(s.workerStats){
  var ws=s.workerStats;
  // Real footprint (PSS) when available, not summed RSS: RSS counts each
  // shared copy-on-write page once per worker, over-reporting the warmed
  // baseline several-fold.
  var real=ws.totalPssKb>0;
  var totalKb=real?ws.totalPssKb:ws.totalRssKb;
  var avgKb=ws.count>0?Math.round(totalKb/ws.count):0;
  var fpmEquiv=ws.count*50;
  el('cow-total',fmtMem(totalKb*1024));
  // One item per line, so the figures read at a glance.
  el('cow-detail','<div>'+ws.idle+'/'+ws.count+' idle</div><div>'+(real?'real ':'rss ')+fmtMem(avgKb*1024)+'/worker</div><div>fpm would use ~'+fpmEquiv+'MB</div>');
  var ce=document.getElementById('cow-total');
  if(ce)ce.style.color='var(--grn)';
}else if(s.forkMode){
  el('cow-total','fork');
  el('cow-detail','<div>each request forks a fresh process (~120KB COW)</div>');
}
el('s2',s.status2xx);el('s3',s.status3xx);el('s4',s.status4xx);el('s5',s.status5xx);
el('bout',s.bytesFormatted);el('conn',s.connections);el('ka',s.keepAlive||0);
el('srps',(s.rps)+' avg req/s');
// Requests served, not workers: "5 PHP / 7 static" was read as a worker count.
el('phpn',s.phpRequests.toLocaleString());el('stn',s.staticRequests.toLocaleString());
// Offer every status code the server has recorded, not just the live ones.
if(s.statusCodes){for(var _sc in s.statusCodes){if(!knownCodes[_sc]){knownCodes[_sc]=1;addScOption(_sc)}}}
el('reqc',s.requests.toLocaleString()+' total');
// Sync uptime from server
upSec=s.uptimeSec||0;
// Sync sparkline from server
if(s.sparkline){spData=s.sparkline.slice();renderSpark()}
// Top paths
var pp=document.getElementById('paths');
if(s.topPaths&&s.topPaths.length){pp.innerHTML=s.topPaths.map(tpRow).join('')}
// Rooms
var rm=document.getElementById('rooms');
if(s.activeRooms&&s.activeRooms.length){rm.innerHTML=s.activeRooms.map(function(r){
return'<div class="room"><span class="n">'+esc(r.name)+'</span><span>'+r.members+' members</span></div>'}).join('')}
else{rm.innerHTML='<div style="color:var(--dim);padding:8px;font-size:12px">No active rooms</div>'}
if(s.sessions&&s.sessions.length){updateSidDropdown(s.sessions)}}

function el(id,v){var e=document.getElementById(id);if(e)e.innerHTML=v}
function esc(s){return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;')}
// Top paths and System RAM helpers. Each mirrors a PHP method of the same
// name in Q_WebServer_Dashboard, which renders the first paint, so the two
// must stay in step: fmtMs, tpRow (topPathRow), ramLevel, swapLevel,
// ramPct (ramPercentHtml), ramDetail (ramDetailHtml).
function fmtMs(ms){ms=+ms||0;var r=Math.round(ms*10)/10;if(r<1000)return r+' ms';return(ms/1000).toFixed(2)+' s'}
function tpRow(p){var h=esc(String(p.path));return'<div class="tp"><span class="p" title="'+h+'">'+h+
'</span><span class="c">'+(parseInt(p.count,10)||0)+' req</span><span class="a">'+fmtMs(p.avgMs)+' avg</span></div>'}
function ramLevel(pc){pc=+pc||0;return pc>=90?'crit':(pc>=70?'warn':'ok')}
function swapLevel(u,t){u=+u||0;t=+t||0;if(u<=0)return'none';return(t>0&&u>t/2)?'crit':'warn'}
function ramPct(r){return'<span class="sev-'+ramLevel(r.percent)+'">'+(parseInt(r.percent,10)||0)+'%</span>'}
function ramDetail(r){
var o=Math.round((r.usedMb||0)/1024*10)/10+' / '+Math.round((r.totalMb||0)/1024*10)/10+' GB';
var st=+r.swapTotalMb||0,sw=+r.swapUsedMb||0;
if(st>0||sw>0){o+=' &#183; <span class="sev-'+swapLevel(sw,st)+'">'+((sw>0&&sw<1024)?Math.round(sw)+' MB':Math.round(sw/1024*10)/10+' GB')+' swap</span>'}
return o}

function fmtMem(b){
if(b<=0)return'\u2014';
if(b<1024)return b+' B';
if(b<1048576)return(b/1024).toFixed(1)+' KB';
return(b/1048576).toFixed(1)+' MB';
}

var K={php:'\u{1F418}',html:'\u{1F310}',css:'\u{1F3A8}',js:'\u26A1',img:'\u{1F5BC}',
font:'\u{1F524}',json:'\u{1F4CB}',xml:'\u{1F4C4}',doc:'\u{1F4D1}',media:'\u{1F3AC}',file:'\u{1F4E6}'};

function shouldShow(e){
if(sidFilter&&(e.sid||'')!==sidFilter)return false;
if(scFilter&&String(e.status)!==scFilter)return false;
return true;
}

function A(e){
if(paused)return;
var vis=shouldShow(e);
var d=document.createElement('div');d.className='le';
if(!vis)d.style.display='none';
d.setAttribute('data-sid',e.sid||'');
d.setAttribute('data-sc',e.status);
d.innerHTML=mkRow(e);
L.insertBefore(d,L.firstChild);
while(L.children.length>MAX_LOG)L.removeChild(L.lastChild);
// Track session
if(e.sid&&!knownSids[e.sid]){knownSids[e.sid]=1;addSidOption(e.sid)}
// Track status code
var sc=String(e.status);
if(!knownCodes[sc]){knownCodes[sc]=1;addScOption(sc)}
}

function mkRow(e){
var c=e.status<300?'s2':e.status<400?'s3':e.status<500?'s4':'s5';
var k=K[e.kind]||'\u{1F4C2}';
var uri=esc(e.uri);
if(e.method==='GET'){uri='<a href="'+BASE+esc(e.uri)+'" target="_blank">'+uri+'</a>'}
return '<span class="lk">'+k+'</span><span class="lt">'+e.time+'</span><span class="ls '+c+'">'+e.status+
'</span><span class="lm">'+e.method+'</span><span class="lu">'+uri+
'</span><span class="ld">'+(e.ms==null?'':(+e.ms).toFixed(1))+'ms</span><span class="lmem">'+fmtMem(e.mem)+'</span>';
}

function togglePause(){
paused=!paused;
var btn=document.getElementById('btn-pause');
btn.textContent=paused?'\u25B6':'\u23F8';
btn.classList.toggle('active',paused);
btn.title=paused?'Resume':'Pause';
}
function clearLog(){L.innerHTML=''}

// Session filter
function updateSidDropdown(sessions){
sessions.forEach(function(s){
if(!knownSids[s.id]){knownSids[s.id]=1;addSidOption(s.id)}
});
}
function addSidOption(sid){
var sel=document.getElementById('sid-filter');
var o=document.createElement('option');
o.value=sid;o.textContent=sid;
sel.appendChild(o);
}
function filterSession(){
sidFilter=document.getElementById('sid-filter').value;
refilterRows();
}

// Status code filter
function addScOption(sc){
var sel=document.getElementById('sc-filter');
var o=document.createElement('option');
o.value=sc;o.textContent=sc+(SC[sc]?' \u2014 '+SC[sc]:'');
// Insert sorted
var opts=sel.options;
for(var i=1;i<opts.length;i++){
if(parseInt(opts[i].value)>parseInt(sc)){sel.insertBefore(o,opts[i]);return}
}
sel.appendChild(o);
}
function filterStatus(){
scFilter=document.getElementById('sc-filter').value;
refilterRows();
}

function refilterRows(){
var rows=L.children;
for(var i=0;i<rows.length;i++){
var r=rows[i],sid=r.getAttribute('data-sid')||'',sc=r.getAttribute('data-sc')||'';
var vis=(!sidFilter||sid===sidFilter)&&(!scFilter||sc===scFilter);
r.style.display=vis?'':'none';
}
}

U(S);R.forEach(A);

// 1-second tickers for uptime + sparkline
setInterval(function(){tickUp();tickSpark()},1000);
tickUp();

var wsUrl=(location.protocol==='https:'?'wss://':'ws://')+location.host+'/Q/ws$tokenParam';
var ws;function C(){ws=new WebSocket(wsUrl);
ws.onopen=function(){wsLive=true;tickUp()};
ws.onmessage=function(e){var m=JSON.parse(e.data);if(m.type==='request'){A(m.entry);U(m.stats)}else if(m.type==='heartbeat'){U(m.stats)}};
ws.onclose=function(){wsLive=false;tickUp();setTimeout(C,2000)}}
C();
</script>
<div class="foot">$brandName <span style="opacity:.6">$verLabel</span></div>
$maintainedBy
<div class="foot-docs"><a href="/Q/docs">Documentation</a> &#183; Powered by the Qbix engine</div>
</body></html>
HTML;
	}
}
