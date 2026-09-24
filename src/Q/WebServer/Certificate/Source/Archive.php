<?php
/**
 * @module Q
 */

/**
 * Certificate files packed in an archive, as CAs and hosting panels send them:
 *
 *   "https": {
 *     "mode": "archive",
 *     "archive": "/etc/ssl/example.com.zip",   // .zip .tar .tar.gz .tgz .tar.bz2 .tbz2 .tar.xz .txz .rar .7z
 *     "password": "...",                       // for an encrypted zip, rar or 7z (or passwordFile / passwordEnv)
 *     "keyPassword": "..."                     // for an encrypted key inside (or keyPasswordFile / keyPasswordEnv)
 *   }
 *
 * Which member is the key, which the certificate and which the chain is found
 * from the contents, not the names; a .p12/.pfx inside is read as well. The
 * pair is imported into <ssl dir>/imported/archive.*, and again whenever the
 * archive changes -- dropping a renewed archive in place is enough.
 *
 * Read in memory where PHP can (zip through ZipArchive, tar and its gzip and
 * bzip2 forms through PharData); otherwise with bsdtar (libarchive, which
 * reads all of them, rar and 7z included), or tar, unrar or 7z, into a private
 * temporary directory. Member names never decide where anything is written,
 * links are never followed, and sizes are capped.
 *
 * @class Q_WebServer_Certificate_Source_Archive
 */
class Q_WebServer_Certificate_Source_Archive extends Q_WebServer_Certificate_Source_Base
{
	const MAX_MEMBERS = 1000;
	const MAX_MEMBER_BYTES = 5242880;
	const MAX_TOTAL_BYTES = 52428800;

	function name()
	{
		return 'archive';
	}

	function watched(array $config)
	{
		return empty($config['archive']) ? array() : array($config['archive']);
	}

	function pair(array $config, &$why = '')
	{
		$file = isset($config['archive']) ? $config['archive'] : '';
		if ($file === '' or !is_file($file)) { $why = "no archive at $file"; return null; }
		$members = self::members($file, self::secret($config, 'password'), $why);
		if (!$members) return null;
		$c = self::fromMembers($members, self::secret($config, 'keyPassword'), self::secret($config, 'password'), $why);
		return $c ? $this->keep($c, $why) : null;
	}

	/**
	 * A pair from archive members: loose files first, then any PKCS#12 inside.
	 * @return {Q_WebServer_Certificate|null}
	 */
	static function fromMembers(array $members, $keyPassword, $bundlePassword, &$why = '')
	{
		$loose = array();
		$bundles = array();
		foreach ($members as $name => $bytes) {
			if (preg_match('/\.(p12|pfx)$/i', $name)) $bundles[] = $bytes;
			else $loose[] = $bytes;
		}
		$c = $loose ? Q_WebServer_Certificate_Import::fromMaterial($loose, $keyPassword, $why) : null;
		foreach ($bundles as $b) {
			if ($c) break;
			$c = Q_WebServer_Certificate_Source_Pkcs12::read($b, (string) ($keyPassword ?? $bundlePassword ?? ''), $why);
		}
		return $c;
	}

	/**
	 * The regular files in an archive: name => bytes.
	 * @method members
	 * @static
	 * @return {array}
	 */
	static function members($file, $password = null, &$why = '')
	{
		$lower = strtolower($file);
		if (substr($lower, -4) === '.zip' and class_exists('ZipArchive')) {
			return self::zip($file, $password, $why);
		}
		if (preg_match('/\.(tar|tar\.gz|tgz|tar\.bz2|tbz2?)$/', $lower) and class_exists('PharData')) {
			$m = self::phar($file, $why);
			if ($m) return $m;
		}
		return self::extract($file, $password, $why);
	}

	private static function zip($file, $password, &$why)
	{
		$z = new ZipArchive();
		if ($z->open($file) !== true) { $why = 'not a readable zip file'; return array(); }
		if ($password !== null) $z->setPassword($password);
		$out = array();
		$total = 0;
		for ($i = 0; $i < $z->numFiles and $i < self::MAX_MEMBERS; $i++) {
			$st = $z->statIndex($i);
			if (!$st or substr($st['name'], -1) === '/' or $st['size'] > self::MAX_MEMBER_BYTES) continue;
			if (($total += $st['size']) > self::MAX_TOTAL_BYTES) break;
			$bytes = $z->getFromIndex($i);
			if ($bytes === false) { $why = 'a member could not be read (encrypted? give its password)'; continue; }
			$out[$st['name']] = $bytes;
		}
		$z->close();
		if (!$out and $why === '') $why = 'the zip file is empty';
		return $out;
	}

	private static function phar($file, &$why)
	{
		try {
			$p = new PharData($file);
			$out = array();
			$total = 0;
			foreach (new RecursiveIteratorIterator($p) as $entry) {
				if (count($out) >= self::MAX_MEMBERS) break;
				if (!$entry->isFile() or $entry->getSize() > self::MAX_MEMBER_BYTES) continue;
				if (($total += $entry->getSize()) > self::MAX_TOTAL_BYTES) break;
				$bytes = method_exists($entry, 'getContent') ? (string) $entry->getContent() : (string) @file_get_contents($entry->getPathname());
				// Some PHP builds (8.3.33 seen) read a compressed member back empty;
				// then the tar tool reads the archive instead.
				if (strlen($bytes) !== (int) $entry->getSize()) return array();
				$out[$entry->getFilename()] = $bytes;
			}
			return $out;
		} catch (\Throwable $e) {
			$why = 'the tar file could not be read: ' . $e->getMessage();
			return array();
		}
	}

	/** Anything else: an external tool into a private temporary directory. */
	private static function extract($file, $password, &$why)
	{
		$lower = strtolower($file);
		$bsdtar = self::tool(array('bsdtar'));
		$cmd = null;
		$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qbix-archive-' . bin2hex(random_bytes(6));
		if (!@mkdir($dir, 0700)) { $why = 'could not create a temporary directory'; return array(); }
		$env = array();
		if ($bsdtar and $password === null) {
			$cmd = array($bsdtar, '-x', '-f', $file, '-C', $dir, '--no-same-owner', '--no-same-permissions');
		} elseif ($bsdtar) {
			$cmd = array($bsdtar, '-x', '-f', $file, '-C', $dir, '--no-same-owner', '--no-same-permissions', '--passphrase', $password);
		} elseif (preg_match('/\.(tar\.xz|txz|tar\.gz|tgz|tar\.bz2|tbz2?|tar)$/', $lower) and ($tar = self::tool(array('tar', 'gtar')))) {
			$cmd = array($tar, '-x', '-f', $file, '-C', $dir, '--no-same-owner');
		} elseif (substr($lower, -4) === '.rar' and ($unrar = self::tool(array('unrar')))) {
			$cmd = array($unrar, 'x', '-o+', '-p' . ($password === null ? '-' : $password), $file, $dir . DIRECTORY_SEPARATOR);
		} elseif (substr($lower, -3) === '.7z' and ($z7 = self::tool(array('7z', '7za', '7zz')))) {
			$cmd = array($z7, 'x', '-y', '-o' . $dir, '-p' . ($password === null ? '' : $password), $file);
		} elseif (substr($lower, -4) === '.zip' and ($unzip = self::tool(array('unzip')))) {
			$cmd = $password === null ? array($unzip, '-o', '-qq', $file, '-d', $dir)
				: array($unzip, '-o', '-qq', '-P', $password, $file, '-d', $dir);
		}
		$out = array();
		try {
			if (!$cmd) { $why = 'no tool here reads ' . basename($file) . ' (install bsdtar / libarchive-tools)'; return array(); }
			$code = self::run($cmd, $output, $env);
			$total = 0;
			$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
			foreach ($it as $f) {
				if (count($out) >= self::MAX_MEMBERS) break;
				if ($f->isLink() or !$f->isFile() or $f->getSize() > self::MAX_MEMBER_BYTES) continue;
				if (($total += $f->getSize()) > self::MAX_TOTAL_BYTES) break;
				$out[$f->getFilename()] = (string) file_get_contents($f->getPathname());
			}
			if (!$out) $why = 'nothing could be extracted' . ($code !== 0 ? ': ' . trim((string) $output) : '');
		} finally {
			self::rmtree($dir);
		}
		return $out;
	}

	private static function rmtree($dir)
	{
		if (!is_dir($dir)) return;
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($it as $f) { ($f->isDir() and !$f->isLink()) ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
		@rmdir($dir);
	}
}
