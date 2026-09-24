<?php
/**
 * @module Q
 */

/**
 * Where the self-signed certificate lives: <conf dir>/ssl, like
 * /etc/apache2/ssl -- /etc/qbix/ssl for the base tree, or the top overlay's
 * ssl/ when a distribution stacks one. Without a configuration directory it
 * falls back to the certs directory Q_WebServer_Certs already uses. Set
 * Q.web.https.selfSigned.dir to put it anywhere else.
 *
 *   ssl/self-signed.pem        the certificate (0644)
 *   ssl/self-signed.key        its key (0600)
 *   ssl/self-signed.pem.prev   the pair it replaced, kept once
 *   ssl/self-signed.key.prev
 *
 * Writes are atomic (a temporary file renamed into place), so a reader --
 * the server swapping certificates, a second process -- never sees half a
 * file, and a lock keeps two writers from interleaving.
 *
 * @class Q_WebServer_Certificate_Store
 */
class Q_WebServer_Certificate_Store
{
	const NAME = 'self-signed';

	/** @var string */
	private $dir;

	/** @param {string|null} $dir null for the configured default */
	function __construct($dir = null)
	{
		$this->dir = rtrim($dir !== null ? $dir : self::defaultDir(), '/\\');
	}

	/** @return {string} the directory chosen when none is given */
	static function defaultDir()
	{
		$dir = Q_Config::get('Q', 'web', 'https', 'selfSigned', 'dir', null);
		if ($dir) return $dir;
		$conf = Q_Config::get('Q', 'webserver', 'confDir', null);
		if ($conf and is_dir($conf)) return rtrim($conf, '/\\') . DIRECTORY_SEPARATOR . 'ssl';
		return Q_WebServer_Certs::certsDir() . DIRECTORY_SEPARATOR . self::NAME;
	}

	function dir()
	{
		return $this->dir;
	}

	function certFile()
	{
		return $this->dir . DIRECTORY_SEPARATOR . self::NAME . '.pem';
	}

	function keyFile()
	{
		return $this->dir . DIRECTORY_SEPARATOR . self::NAME . '.key';
	}

	/** @return {Q_WebServer_Certificate|null} what is stored, usable or not */
	function load()
	{
		return Q_WebServer_Certificate::fromFiles($this->certFile(), $this->keyFile());
	}

	/**
	 * Store a certificate, keeping the pair it replaces as .prev.
	 * @method save
	 * @param {Q_WebServer_Certificate} $c
	 * @return {boolean}
	 */
	function save(Q_WebServer_Certificate $c)
	{
		if (!is_dir($this->dir) and !@mkdir($this->dir, 0755, true) and !is_dir($this->dir)) return false;
		return $this->locked(function () use ($c) {
			$previous = false;
			foreach (array($this->certFile(), $this->keyFile()) as $f) {
				if (is_file($f)) { @copy($f, $f . '.prev'); @chmod($f . '.prev', fileperms($f) & 0777); $previous = true; }
			}
			// The key first: a reader that finds a new certificate always finds its key.
			if (!self::writeAtomic($this->keyFile(), $c->keyPem, 0600)) return false;
			if (!self::writeAtomic($this->certFile(), $c->certPem, 0644)) return false;
			Q_WebServer_Certificate_Events::emit('stored', array('cert' => $this->certFile(),
				'key' => $this->keyFile(), 'previous' => $previous, 'provider' => $c->provider));
			return true;
		});
	}

	/**
	 * Run $fn holding this store's lock.
	 * @return mixed what $fn returns
	 */
	function locked(callable $fn)
	{
		// Re-entrant: ensure() holds the lock while save() takes it again, and
		// a second flock() on a new handle blocks even within one process.
		$key = $this->dir;
		if (!empty(self::$held[$key])) {
			++self::$held[$key];
			try { return $fn(); } finally { --self::$held[$key]; }
		}
		if (!is_dir($this->dir)) @mkdir($this->dir, 0755, true);
		$h = @fopen($this->dir . DIRECTORY_SEPARATOR . '.lock', 'c');
		if ($h) @flock($h, LOCK_EX);
		self::$held[$key] = 1;
		try {
			return $fn();
		} finally {
			unset(self::$held[$key]);
			if ($h) { @flock($h, LOCK_UN); fclose($h); }
		}
	}

	/** @var array directory => depth, for the lock this process holds */
	private static $held = array();

	/** Write a file through a temporary one renamed into place. */
	static function writeAtomic($file, $data, $mode)
	{
		$tmp = $file . '.tmp.' . getmypid();
		$old = umask(0077);
		try {
			if (@file_put_contents($tmp, $data) !== strlen($data)) { @unlink($tmp); return false; }
		} finally {
			umask($old);
		}
		@chmod($tmp, $mode);
		if (!@rename($tmp, $file)) { @unlink($tmp); return false; }
		return true;
	}
}
