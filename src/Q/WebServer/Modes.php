<?php
/**
 * @module Q
 */

/**
 * File and directory permissions for the files this server writes.
 *
 * A file created by fopen() or file_put_contents() gets 0666 minus the
 * umask, which on most machines is 0644: readable by everyone with an
 * account. That is the wrong default for an access log naming the people
 * who visited, and for a cache holding the bodies that were served. It
 * is the wrong default even on a single-tenant machine, and on one where
 * each site runs as its own user it is the thing that makes the
 * separation pointless.
 *
 * So the log, the cache and the precompressor each take a `fileMode` and
 * a `dirMode`, written the way `Q/webserver/socketMode` already is -- a
 * string of octal digits, "0660" or "2770". This class parses them and
 * applies them.
 *
 * Nothing here changes what happens when the keys are absent. An unset
 * mode is null, and null means every call falls through to exactly the
 * syscall the caller made before.
 *
 * ## Why both umask and chmod
 *
 * Setting the mode after creating the file leaves a window in which it
 * exists with 0644, and chmod takes nothing away from a descriptor that
 * is already open. The window is not a one-off either: every rotation
 * creates a log, every cache write creates an entry.
 *
 * Narrowing the umask instead closes that window but cannot do the rest.
 * The umask is per-process, and this process runs application code whose
 * own writes would change with it. It can only clear bits, so it can
 * never set setgid. And it does not touch a file that already exists, so
 * a mode configured after the fact would never take effect.
 *
 * Hence both, in that order: create the file already narrow, then chmod
 * for the two cases the umask cannot reach.
 *
 * @class Q_WebServer_Modes
 */
class Q_WebServer_Modes
{
	/**
	 * A configured mode as an integer, or $default when it is not one.
	 *
	 * Accepts what a person would write in JSON: "0660", "660", "02770",
	 * "2770", and an integer 660 meaning what it looks like rather than
	 * what it is in decimal. Anything else -- a symbolic "rwxrwx---", an
	 * "0o660", a digit outside octal, a value too long to be a mode --
	 * returns $default, so a typo leaves the server writing files the
	 * way it did before rather than refusing to start.
	 *
	 * @method parse
	 * @static
	 * @param {string|integer|null} $value The configured value
	 * @param {integer|null} [$default=null] Used when $value is not a mode
	 * @return {integer|null}
	 */
	static function parse($value, $default = null)
	{
		if ($value === null or is_bool($value) or is_array($value)) {
			return $default;
		}
		if (!is_string($value) and !is_int($value)) return $default;
		$s = trim((string) $value);
		if (!preg_match('/^[0-7]{1,5}$/', $s)) return $default;
		$mode = octdec($s);
		if ($mode < 0 or $mode > 07777) return $default;
		return (int) $mode;
	}

	/**
	 * chmod, unless there is no mode to set.
	 *
	 * Silent on failure, like the socketMode it follows: the path may
	 * belong to another user, and a server that has just written a log
	 * line should not die because it could not relabel the file.
	 * Log::init() and Cache::init() check once at startup and say so.
	 *
	 * @method chmod
	 * @static
	 * @param {string} $path
	 * @param {integer|null} $mode
	 * @return {boolean}
	 */
	static function chmod($path, $mode)
	{
		if ($mode === null) return true;
		return @chmod($path, $mode);
	}

	/**
	 * fopen() with the file created at $mode rather than at the umask.
	 *
	 * @method open
	 * @static
	 * @param {string} $path
	 * @param {string} $fopenMode Whatever fopen() takes, e.g. 'a'
	 * @param {integer|null} $mode
	 * @return {resource|false}
	 */
	static function open($path, $fopenMode, $mode)
	{
		if ($mode === null) return fopen($path, $fopenMode);
		$old = self::narrow($mode);
		$fp = fopen($path, $fopenMode);
		self::restore($old);
		if ($fp) self::chmod($path, $mode);
		return $fp;
	}

	/**
	 * file_put_contents() with the file created at $mode.
	 *
	 * @method put
	 * @static
	 * @param {string} $path
	 * @param {string} $data
	 * @param {integer} $flags Passed through -- LOCK_EX matters to callers
	 * @param {integer|null} $mode
	 * @return {integer|false} Bytes written
	 */
	static function put($path, $data, $flags, $mode)
	{
		if ($mode === null) return @file_put_contents($path, $data, $flags);
		$old = self::narrow($mode);
		$written = @file_put_contents($path, $data, $flags);
		self::restore($old);
		if ($written !== false) self::chmod($path, $mode);
		return $written;
	}

	/**
	 * gzopen() with the archive created at $mode.
	 *
	 * A rotated log that has been gzipped is the same log. It gets the
	 * same mode as the one it came from, or an archive directory ends up
	 * being the readable copy of a log that was not.
	 *
	 * @method gzopen
	 * @static
	 * @param {string} $path
	 * @param {string} $gzMode Whatever gzopen() takes, e.g. 'wb9'
	 * @param {integer|null} $mode
	 * @return {resource|false}
	 */
	static function gzopen($path, $gzMode, $mode)
	{
		if ($mode === null) return gzopen($path, $gzMode);
		$old = self::narrow($mode);
		$gz = gzopen($path, $gzMode);
		self::restore($old);
		if ($gz) self::chmod($path, $mode);
		return $gz;
	}

	/**
	 * mkdir -p, with every level this call creates set to $mode.
	 *
	 * Two things make this more than a one-liner.
	 *
	 * mkdir() masks its mode argument with the umask, and on Linux it
	 * does not take setgid from that argument at all -- it inherits
	 * S_ISGID from the parent. So mkdir($d, 02770) reliably does not
	 * produce a setgid directory, and chmod has to follow.
	 *
	 * And mkdir($d, $mode, true) creates every missing level, while a
	 * chmod afterwards fixes only the last one. A dir of
	 * "/var/log/qbix/a.example.com" under an empty /var/log leaves two
	 * levels at the umask and without setgid -- which is exactly the
	 * inheritance the setgid bit was for. So the levels that were
	 * missing are collected first and chmod'ed from the top down.
	 *
	 * $explicit separates "the caller configured 0755" from "0755 is the
	 * default": only a configured mode is forced past the umask, so a
	 * server with no mode in its config behaves as it always did.
	 *
	 * @method dir
	 * @static
	 * @param {string} $path
	 * @param {integer|null} $mode
	 * @param {boolean} [$explicit=false] Whether $mode came from config
	 * @return {boolean} Whether the directory exists afterwards
	 */
	static function dir($path, $mode, $explicit = false)
	{
		if (is_dir($path)) {
			if ($explicit and $mode !== null) self::chmod($path, $mode);
			return true;
		}

		// Which levels this call is about to create, parent first.
		$missing = array();
		$level = rtrim($path, '/');
		while ($level !== '' and $level !== '/' and !is_dir($level)) {
			$missing[] = $level;
			$parent = dirname($level);
			if ($parent === $level) break;
			$level = $parent;
		}
		$missing = array_reverse($missing);

		$made = @mkdir($path, $mode === null ? 0755 : $mode, true);
		if (!$made and !is_dir($path)) return false;

		if ($explicit and $mode !== null) {
			foreach ($missing as $created) {
				self::chmod($created, $mode);
			}
		}
		return true;
	}

	/**
	 * Does $path actually carry $mode?
	 *
	 * For the one check at startup. chmod needs ownership, so a
	 * directory left behind by another user keeps its own permissions
	 * however it is configured -- worth a line on STDERR, and worth
	 * finding out once rather than per file.
	 *
	 * @method verify
	 * @static
	 * @param {string} $path
	 * @param {integer|null} $mode
	 * @return {boolean} True when there is nothing to complain about
	 */
	static function verify($path, $mode)
	{
		if ($mode === null) return true;
		if (!file_exists($path)) return true;
		clearstatcache(true, $path);
		$perms = @fileperms($path);
		if ($perms === false) return true;
		return ($perms & 07777) === $mode;
	}

	/**
	 * Set the umask so a file created next gets exactly $mode.
	 *
	 * Returns what the umask was, or null when it could not be changed
	 * -- umask() is on the list people disable, and then chmod alone has
	 * to do. The server is single-threaded and the umask is restored
	 * after the one creating call, so nothing else is created while it is
	 * narrowed. Under ZTS with real threads that would not hold.
	 *
	 * @method narrow
	 * @static
	 * @private
	 * @param {integer} $mode
	 * @return {integer|null}
	 */
	private static function narrow($mode)
	{
		if (!function_exists('umask')) return null;
		return umask(~$mode & 0777);
	}

	/**
	 * Put the umask back.
	 *
	 * @method restore
	 * @static
	 * @private
	 * @param {integer|null} $old
	 */
	private static function restore($old)
	{
		if ($old !== null and function_exists('umask')) umask($old);
	}
}
