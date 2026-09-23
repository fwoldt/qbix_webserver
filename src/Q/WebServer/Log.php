<?php
/**
 * @module Q
 */

/**
 * Access and error logging with buffered writes, daily rotation,
 * and automatic gzip archiving.
 *
 * Writes access.log (combined format + response time) and error.log,
 * or whatever accessName/errorName call them.
 * Buffered: log lines accumulate in memory and flush on a timer or
 * when the buffer is full — one write() syscall per flush instead of
 * one per request. Daily rotation at midnight. Logs older than
 * `archiveAfterDays` (default 2) are compressed to .gz. Logs older
 * than `deleteAfterDays` (default 30) are deleted.
 *
 * Config:
 *   "Q": { "webserver": { "log": {
 *     "dir":              "logs",
 *     "access":           true,
 *     "error":            true,
 *     "accessName":       "access.log",
 *     "errorName":        "error.log",
 *     "format":           "qbix",
 *     "bufferSize":       65536,
 *     "flushInterval":    1,
 *     "maxSize":          52428800,
 *     "archiveAfterDays": 2,
 *     "deleteAfterDays":  30
 *   }}}
 *
 *   bufferSize: 0 = unbuffered (flush every line), default 64KB.
 *   flushInterval: seconds between timer flushes, default 1.
 *   Set access/error to false to disable that log entirely.
 *   accessName/errorName rename the two files -- for one directory
 *   collecting the logs of several servers, or to match what an
 *   existing log shipper already watches. A bare filename, no
 *   directory part: anything else falls back to the default, so a
 *   name out of config cannot write outside `dir`. Rotation keeps
 *   the name and inserts the date before the .log, so "site.log"
 *   rotates to "site.2000-10-10.log".
 *   format: "qbix" (default), "combined", "common", or a format string.
 *
 * Format strings take the Apache tokens that make sense here:
 *
 *   %h  client address          %l  identd, always -
 *   %u  remote user, always -   %t  time, [10/Oct/2000:13:55:36 -0700]
 *   %r  request line            %m  method
 *   %U  path without the query  %q  query, with its ? when not empty
 *   %H  protocol                %s, %>s  status
 *   %b  size, - when zero       %B  size, 0 when zero
 *   %D  microseconds            %T  seconds
 *   %{ms}T  milliseconds        %{Name}i  a request header
 *   %%  a literal percent
 *
 * The named formats:
 *
 *   common    %h %l %u %t "%r" %>s %b
 *   combined  common + "%{Referer}i" "%{User-Agent}i"
 *   qbix      combined + " %{ms}T" -- what this server has always written
 *
 * @class Q_WebServer_Log
 */
class Q_WebServer_Log
{
	static $accessFp = null;
	static $errorFp = null;
	static $accessPath = null;
	static $errorPath = null;
	static $accessName = 'access.log';
	static $errorName = 'error.log';
	static $dir = null;
	static $maxSize = 52428800;       // 50MB
	static $archiveAfterDays = 2;
	static $deleteAfterDays = 30;
	static $currentDate = null;
	static $enabled = false;

	// ── Buffer ──────────────────────────────────────────
	static $accessBuf = '';
	static $errorBuf = '';
	static $format = null;            // resolved format string
	static $bufferSize = 65536;       // 64KB default
	static $flushInterval = 1.0;      // seconds

	/**
	 * Initialize from config.
	 * @method init
	 * @static
	 */
	static function init()
	{
		$config = Q_Config::get('Q', 'webserver', 'log', array());
		if (empty($config)) return;

		$dir = Q::ifset($config, 'dir', null);
		if (!$dir) {
			$dir = defined('APP_DIR') ? APP_DIR . '/logs' : 'logs';
		}
		if ($dir[0] !== '/') {
			$base = defined('APP_DIR') ? APP_DIR : getcwd();
			$dir = $base . '/' . $dir;
		}
		if (!is_dir($dir)) @mkdir($dir, 0755, true);
		self::$dir = $dir;

		self::$maxSize = (int) Q::ifset($config, 'maxSize', 52428800);
		self::$archiveAfterDays = (int) Q::ifset($config, 'archiveAfterDays', 2);
		self::$deleteAfterDays = (int) Q::ifset($config, 'deleteAfterDays', 30);
		self::$format = self::resolveFormat(Q::ifset($config, 'format', 'qbix'));
		self::$bufferSize = (int) Q::ifset($config, 'bufferSize', 65536);
		self::$flushInterval = (float) Q::ifset($config, 'flushInterval', 1.0);

		$accessEnabled = Q::ifset($config, 'access', true);
		$errorEnabled = Q::ifset($config, 'error', true);

		self::$currentDate = date('Y-m-d');

		self::$accessName = self::sanitizeName(
			Q::ifset($config, 'accessName', null), 'access.log'
		);
		self::$errorName = self::sanitizeName(
			Q::ifset($config, 'errorName', null), 'error.log'
		);

		if ($accessEnabled) {
			self::$accessPath = $dir . '/' . self::$accessName;
			self::$accessFp = fopen(self::$accessPath, 'a');
		}
		if ($errorEnabled) {
			self::$errorPath = $dir . '/' . self::$errorName;
			self::$errorFp = fopen(self::$errorPath, 'a');
		}

		self::$enabled = true;

		// Flush timer — write accumulated buffer to disk
		if (self::$flushInterval > 0) {
			Q_Evented::repeat(self::$flushInterval, function () {
				Q_WebServer_Log::flush();
			});
		}

		// Check rotation every 60 seconds
		Q_Evented::repeat(60.0, function () {
			Q_WebServer_Log::checkRotation();
		});

		// Archive/prune on startup and daily
		self::archiveAndPrune();
		Q_Evented::repeat(86400.0, function () {
			Q_WebServer_Log::archiveAndPrune();
		});

		$mode = self::$bufferSize > 0
			? 'buffered (' . round(self::$bufferSize / 1024) . 'KB, '
			  . self::$flushInterval . 's)'
			: 'unbuffered';
		fwrite(STDERR, "  Logging to $dir/ ($mode)\n");
	}

	/**
	 * Log a request. Line goes into the buffer (or directly to disk
	 * if bufferSize=0).
	 * @method access
	 * @static
	 */
	/**
	 * Turn a named format into its string, or pass a custom one through.
	 * @method resolveFormat
	 * @static
	 * @param {string} $name
	 * @return {string}
	 */
	static function resolveFormat($name)
	{
		static $named = array(
			'common'   => '%h %l %u %t "%r" %>s %b',
			'combined' => '%h %l %u %t "%r" %>s %b "%{Referer}i" "%{User-Agent}i"',
			// What this server wrote before the format was configurable:
			// combined, plus the response time. Kept as the default so an
			// existing log keeps its shape and whatever parses it keeps working.
			'qbix'     => '%h %l %u %t "%r" %>s %b "%{Referer}i" "%{User-Agent}i" %{ms}T',
		);
		if (!is_string($name) or $name === '') return $named['qbix'];
		return isset($named[$name]) ? $named[$name] : $name;
	}

	/**
	 * Build one access line.
	 *
	 * @method formatAccess
	 * @static
	 * @param {string} $format
	 * @param {array} $f Fields: ip, method, uri, path, query, protocol,
	 *   status, size, ms, headers
	 * @return {string}
	 */
	static function formatAccess($format, $f)
	{
		$size = (int) ($f['size'] ?? 0);
		$ms = (float) ($f['ms'] ?? 0);
		$path = $f['path'] ?? ($f['uri'] ?? '');
		$query = $f['query'] ?? '';
		if ($query !== '' and $query[0] !== '?') $query = '?' . $query;
		$proto = $f['protocol'] ?? 'HTTP/1.1';
		$request = trim(($f['method'] ?? '') . ' ' . ($f['uri'] ?? '') . ' ' . $proto);
		$headers = $f['headers'] ?? array();

		return preg_replace_callback(
			'/%(?:\{([^}]*)\}([ioTt])|>?([a-zA-Z%]))/',
			function ($m) use ($f, $size, $ms, $path, $query, $proto, $request, $headers) {
				if ($m[1] !== '' or ($m[2] ?? '') !== '') {
					$arg = $m[1];
					switch ($m[2]) {
						case 'i':
							$k = strtolower($arg);
							$v = $headers[$k] ?? '';
							return $v === '' ? '-' : $v;
						case 'T':
							if ($arg === 'ms') return sprintf('%.1fms', $ms);
							if ($arg === 'us') return (string) (int) ($ms * 1000);
							return (string) (int) ($ms / 1000);
						case 't':
							return date($arg ?: 'd/M/Y:H:i:s O');
					}
					return $m[0];
				}
				switch ($m[3]) {
					case 'h': return $f['ip'] ?? '-';
					case 'l': return '-';
					case 'u': return '-';
					case 't': return '[' . date('d/M/Y:H:i:s O') . ']';
					case 'r': return $request;
					case 'm': return $f['method'] ?? '-';
					case 'U': return $path;
					case 'q': return $query;
					case 'H': return $proto;
					case 's': return (string) (int) ($f['status'] ?? 0);
					case 'b': return $size > 0 ? (string) $size : '-';
					case 'B': return (string) $size;
					case 'D': return (string) (int) ($ms * 1000);
					case 'T': return (string) (int) ($ms / 1000);
					case '%': return '%';
				}
				return $m[0];
			},
			$format
		);
	}

	static function access($ip, $method, $uri, $status, $size, $referer, $ua, $ms, $extra = array())
	{
		if (!self::$accessFp) return;
		$headers = $extra['headers'] ?? array();
		// Referer and user agent are passed separately for callers that have
		// them but not the header array.
		if ($referer !== '' and $referer !== null and !isset($headers['referer'])) {
			$headers['referer'] = $referer;
		}
		if ($ua !== '' and $ua !== null and !isset($headers['user-agent'])) {
			$headers['user-agent'] = $ua;
		}
		$line = self::formatAccess(self::$format ?: self::resolveFormat('qbix'), array(
			'ip' => $ip, 'method' => $method, 'uri' => $uri,
			'path' => $extra['path'] ?? null, 'query' => $extra['query'] ?? '',
			'protocol' => $extra['protocol'] ?? 'HTTP/1.1',
			'status' => $status, 'size' => $size, 'ms' => $ms,
			'headers' => $headers,
		)) . "\n";

		if (self::$bufferSize <= 0) {
			// Unbuffered — write immediately
			@fwrite(self::$accessFp, $line);
			return;
		}

		self::$accessBuf .= $line;
		if (strlen(self::$accessBuf) >= self::$bufferSize) {
			self::flushAccess();
		}
	}

	/**
	 * Log an error. Errors always flush immediately (they're rare
	 * and you want them on disk before a crash).
	 * @method error
	 * @static
	 */
	static function error($message, $context = '')
	{
		$time = date('Y-m-d H:i:s');
		$line = "[$time] $message $context\n";
		if (self::$errorFp) {
			// Errors bypass the buffer — flush immediately
			self::flushAccess(); // flush any pending access lines too
			@fwrite(self::$errorFp, $line);
		}
		fwrite(STDERR, "[ERROR] $message $context\n");
	}

	/**
	 * Flush all buffered log lines to disk.
	 * Called by the timer and on shutdown.
	 * @method flush
	 * @static
	 */
	static function flush()
	{
		self::flushAccess();
	}

	private static function flushAccess()
	{
		if (self::$accessBuf !== '' && self::$accessFp) {
			@fwrite(self::$accessFp, self::$accessBuf);
			self::$accessBuf = '';
		}
	}

	/**
	 * Daily rotation + mid-day size rotation.
	 * @method checkRotation
	 * @static
	 */
	static function checkRotation()
	{
		if (!self::$enabled) return;

		// Flush before checking sizes
		self::flush();

		$today = date('Y-m-d');
		if ($today !== self::$currentDate) {
			$yesterday = self::$currentDate;
			self::$currentDate = $today;
			if (self::$accessFp) {
				self::rotateTo(self::$accessPath, self::$accessFp,
					self::rotatedPath(self::$accessName, $yesterday));
				self::$accessFp = fopen(self::$accessPath, 'a');
			}
			if (self::$errorFp) {
				self::rotateTo(self::$errorPath, self::$errorFp,
					self::rotatedPath(self::$errorName, $yesterday));
				self::$errorFp = fopen(self::$errorPath, 'a');
			}
			self::archiveAndPrune();
			return;
		}

		// Size-based mid-day rotation
		if (self::$accessPath && self::exceedsMax(self::$accessPath)) {
			$stamp = date('Y-m-d-His');
			self::rotateTo(self::$accessPath, self::$accessFp,
				self::rotatedPath(self::$accessName, $stamp));
			self::$accessFp = fopen(self::$accessPath, 'a');
		}
		if (self::$errorPath && self::exceedsMax(self::$errorPath)) {
			$stamp = date('Y-m-d-His');
			self::rotateTo(self::$errorPath, self::$errorFp,
				self::rotatedPath(self::$errorName, $stamp));
			self::$errorFp = fopen(self::$errorPath, 'a');
		}
	}

	/**
	 * Where a log rotates to: the date goes before the .log, so the
	 * rotated file sorts next to the live one and still ends in .log,
	 * which is what archiveAndPrune() globs for.
	 *
	 * @method rotatedPath
	 * @static
	 * @private
	 * @param {string} $name The live log's filename
	 * @param {string} $stamp A date, or a date and time for a mid-day rotation
	 * @return {string}
	 */
	private static function rotatedPath($name, $stamp)
	{
		$stem = substr($name, -4) === '.log' ? substr($name, 0, -4) : $name;
		return self::$dir . "/$stem.$stamp.log";
	}

	/**
	 * A log filename out of config, or the default when it is not one.
	 *
	 * Only a bare filename is accepted -- no directory part, and not
	 * . or .. -- so a name out of config writes inside `dir` and
	 * nowhere else.
	 *
	 * @method sanitizeName
	 * @static
	 * @private
	 * @param {string} $name The configured name, or null
	 * @param {string} $default Used when $name is not a bare filename
	 * @return {string}
	 */
	private static function sanitizeName($name, $default)
	{
		if (!is_string($name)) return $default;
		$name = trim($name);
		if ($name === '' or $name === '.' or $name === '..') return $default;
		if (strpbrk($name, "/\\") !== false) return $default;
		if (strpos($name, "\0") !== false) return $default;
		return $name;
	}

	private static function rotateTo($currentPath, &$fp, $targetPath)
	{
		if ($fp) @fclose($fp);
		if (file_exists($currentPath) && filesize($currentPath) > 0) {
			@rename($currentPath, $targetPath);
		}
	}

	private static function exceedsMax($path)
	{
		if (!file_exists($path)) return false;
		clearstatcache(true, $path);
		return filesize($path) > self::$maxSize;
	}

	/**
	 * Compress old logs to .gz, delete expired ones.
	 * @method archiveAndPrune
	 * @static
	 */
	static function archiveAndPrune()
	{
		if (!self::$dir || !is_dir(self::$dir)) return;

		$now = time();
		$archiveCutoff = $now - (self::$archiveAfterDays * 86400);
		$deleteCutoff = $now - (self::$deleteAfterDays * 86400);

		foreach (glob(self::$dir . '/*.log') as $file) {
			$base = basename($file);
			if ($base === self::$accessName || $base === self::$errorName) continue;
			$mtime = filemtime($file);
			if ($mtime < $deleteCutoff) { @unlink($file); continue; }
			if ($mtime < $archiveCutoff && function_exists('gzopen')) {
				$gzPath = $file . '.gz';
				if (!file_exists($gzPath)) {
					$in = fopen($file, 'rb');
					$out = gzopen($gzPath, 'wb9');
					if ($in && $out) {
						while (!feof($in)) gzwrite($out, fread($in, 65536));
						fclose($in); gzclose($out);
						@unlink($file); @touch($gzPath, $mtime);
					} else {
						if ($in) fclose($in);
						if ($out) gzclose($out);
					}
				}
			}
		}

		foreach (glob(self::$dir . '/*.log.gz') as $file) {
			if (filemtime($file) < $deleteCutoff) @unlink($file);
		}
	}

	/**
	 * Log stats for the dashboard.
	 * @method stats
	 * @static
	 */
	static function stats()
	{
		if (!self::$enabled) return null;
		$accessSize = self::$accessPath && file_exists(self::$accessPath)
			? filesize(self::$accessPath) : 0;
		$errorSize = self::$errorPath && file_exists(self::$errorPath)
			? filesize(self::$errorPath) : 0;
		$archives = self::$dir ? count(glob(self::$dir . '/*.gz')) : 0;
		return array(
			'dir' => self::$dir,
			'accessSize' => $accessSize,
			'errorSize' => $errorSize,
			'archives' => $archives,
			'bufferBytes' => strlen(self::$accessBuf),
		);
	}

	static function shutdown()
	{
		self::flush();
		if (self::$accessFp) { @fclose(self::$accessFp); self::$accessFp = null; }
		if (self::$errorFp) { @fclose(self::$errorFp); self::$errorFp = null; }
	}
}
