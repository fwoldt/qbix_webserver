<?php
/**
 * @module Q
 */
/**
 * The PHP extensions this server provides, read from build/extensions.json:
 * which ones a variant carries on a platform and PHP version, which the
 * running PHP has, and how to install what it lacks on this machine.
 *
 * The manifest is the single source of truth. Builds take their extension
 * lists from it (qbixctl ext:list --format=spc), the start-up check and the
 * dashboard compare the running PHP with it, and docs/requirements.md
 * describes it.
 *
 *   $r = Q_WebServer_Extensions::resolve('standard', 'linux-x86_64', '8.3');
 *   $r['include'];   // ['ctype', 'filter', ...] in tier order
 *   $r['exclude'];   // ['odbc' => 'A fully static binary cannot ...']
 *   Q_WebServer_Extensions::check('standard');  // what this PHP lacks
 *   Q_WebServer_Extensions::installHints(array('intl', 'redis'));
 *
 * @class Q_WebServer_Extensions
 * @static
 */
class Q_WebServer_Extensions
{
	/** The tiers in the order variants stack them. */
	const TIERS = array('server', 'required', 'recommended', 'extra', 'other');

	/** @var array|null the loaded manifest */
	private static $manifest = null;

	/** @var string|null a manifest to read instead of the bundled one (tests, QBIX_EXTENSIONS_MANIFEST) */
	public static $manifestPath = null;

	/**
	 * Where the manifest is: QBIX_EXTENSIONS_MANIFEST, else build/extensions.json
	 * beside src/ -- which is also where it sits inside the phar.
	 * @method manifestPath
	 * @static
	 * @return {string}
	 */
	static function manifestPath()
	{
		if (self::$manifestPath !== null) return self::$manifestPath;
		$env = getenv('QBIX_EXTENSIONS_MANIFEST');
		if (is_string($env) && $env !== '') return $env;
		return dirname(__DIR__, 3) . '/build/extensions.json';
	}

	/**
	 * The manifest, loaded once per process.
	 * @method manifest
	 * @static
	 * @return {array}
	 * @throws RuntimeException when it is missing or not valid JSON
	 */
	static function manifest()
	{
		if (self::$manifest === null) {
			$path = self::manifestPath();
			$json = @file_get_contents($path);
			if ($json === false) throw new RuntimeException("extension manifest not found: $path");
			$m = json_decode($json, true);
			if (!is_array($m) || !isset($m['extensions'], $m['variants'])) {
				throw new RuntimeException("extension manifest is not valid: $path");
			}
			self::$manifest = $m;
		}
		return self::$manifest;
	}

	/** Forget the loaded manifest (tests; a changed manifestPath). */
	static function reset()
	{
		self::$manifest = null;
	}

	/**
	 * The platform id of this machine, as the manifest names platforms:
	 * linux-x86_64, linux-aarch64, macos-arm64, windows-x64; anything else
	 * is os-arch in lower case (freebsd-x86_64), which no static build targets.
	 * @method platform
	 * @static
	 * @param {string} [$os] PHP_OS_FAMILY
	 * @param {string} [$arch] php_uname('m')
	 * @return {string}
	 */
	static function platform($os = null, $arch = null)
	{
		$os = $os ?? PHP_OS_FAMILY;
		$arch = strtolower($arch ?? php_uname('m'));
		if (in_array($arch, array('amd64', 'x86_64', 'x64'), true)) $arch = 'x86_64';
		if (in_array($arch, array('arm64', 'aarch64'), true)) $arch = 'arm64';
		switch ($os) {
			case 'Linux': return 'linux-' . ($arch === 'arm64' ? 'aarch64' : $arch);
			case 'Darwin': return 'macos-' . $arch;
			case 'Windows': return 'windows-' . ($arch === 'x86_64' ? 'x64' : $arch);
		}
		return strtolower($os) . '-' . $arch;
	}

	/** This PHP's version as the manifest writes it: 8.3. */
	static function phpVersion()
	{
		return PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
	}

	/**
	 * What a variant carries on a platform and PHP version.
	 *
	 * Extensions come in tier order (server, required, recommended, extra)
	 * and by name within a tier. One the platform cannot have is listed in
	 * exclude with the manifest's reason; so is one a variant names but the
	 * tier rules leave out (notInFull). $with adds extensions by name,
	 * whatever their tier -- still subject to the platform's exclusions.
	 *
	 * @method resolve
	 * @static
	 * @param {string} $variant mini, lite, standard, full or source
	 * @param {string} [$platform] default: this machine
	 * @param {string} [$php] default: this PHP
	 * @param {array} [$with] extra extension names
	 * @return {array} variant, platform, php, kit, tiers, include, exclude (name => reason), libs, spc
	 * @throws InvalidArgumentException for an unknown variant or PHP version
	 */
	static function resolve($variant, $platform = null, $php = null, array $with = array(), $static = true)
	{
		$m = self::manifest();
		if (!isset($m['variants'][$variant])) {
			throw new InvalidArgumentException("unknown variant '$variant' (one of: " . implode(', ', array_keys($m['variants'])) . ')');
		}
		$platform = $platform ?? self::platform();
		$php = $php ?? self::phpVersion();
		if (!in_array($php, $m['php']['versions'], true) && version_compare($php, $m['php']['minimum'], '<')) {
			throw new InvalidArgumentException("PHP $php is older than the minimum, " . $m['php']['minimum']);
		}
		$tiers = $m['variants'][$variant]['tiers'];
		$include = array(); $exclude = array();
		foreach (self::byTier() as $name => $e) {
			$wanted = in_array($e['tier'], $tiers, true) || in_array($name, $with, true);
			if (!$wanted) continue;
			if (isset($e['notInFull']) && !in_array($name, $with, true)) { $exclude[$name] = $e['notInFull']; continue; }
			// A static build lacks what its toolchain cannot build; any PHP on
			// an OS lacks what cannot exist there (fork() on Windows).
			if ($static && isset($e['unavailable'][$platform])) { $exclude[$name] = $e['unavailable'][$platform]; continue; }
			if (!$static && in_array(self::osOf($platform), $e['unsupportedOs'] ?? array(), true)) {
				$exclude[$name] = $e['unavailable'][$platform] ?? ('Not available on ' . self::osOf($platform));
				continue;
			}
			$include[] = $name;
		}
		foreach ($with as $w) {
			if (!isset($m['extensions'][$w]) && !isset($exclude[$w])) $exclude[$w] = 'Not in the manifest';
		}
		$libs = array();
		foreach ($include as $name) {
			foreach ($m['withLibs'][$name] ?? array() as $l) $libs[$l] = true;
		}
		$spc = array();
		foreach ($include as $name) {
			if (isset($m['extensions'][$name]['spc'])) $spc[] = $m['extensions'][$name]['spc'];
		}
		return array('variant' => $variant, 'platform' => $platform, 'php' => $php,
			'kit' => !empty($m['variants'][$variant]['kit']), 'tiers' => $tiers,
			'include' => $include, 'exclude' => $exclude, 'libs' => array_keys($libs), 'spc' => $spc);
	}

	/** The PHP_OS_FAMILY a platform id belongs to: linux-x86_64 -> Linux. */
	static function osOf($platform)
	{
		$p = self::manifest()['platforms'][$platform] ?? null;
		if ($p) return $p['os'];
		$prefix = strtolower(strtok((string) $platform, '-'));
		$map = array('linux' => 'Linux', 'macos' => 'Darwin', 'darwin' => 'Darwin', 'windows' => 'Windows');
		return $map[$prefix] ?? 'BSD';
	}

	/**
	 * Whether this PHP is one of the static builds (the micro SAPI a combined
	 * binary runs under), whose build exclusions then apply; QBIX_STATIC_BUILD=1
	 * says so too, for a static PHP run another way.
	 * @method isStaticBuild
	 * @static
	 * @return {boolean}
	 */
	static function isStaticBuild()
	{
		return PHP_SAPI === 'micro' || getenv('QBIX_STATIC_BUILD') === '1';
	}

	/** The manifest's extensions ordered by tier, then name. */
	static function byTier()
	{
		$ext = self::manifest()['extensions'];
		$order = array_flip(self::TIERS);
		uksort($ext, function ($a, $b) use ($ext, $order) {
			$ta = $order[$ext[$a]['tier']] ?? 99; $tb = $order[$ext[$b]['tier']] ?? 99;
			return $ta === $tb ? strcmp($a, $b) : $ta - $tb;
		});
		return $ext;
	}

	/**
	 * Whether this PHP has an extension, by its manifest name. A few are not
	 * named the way extension_loaded() knows them.
	 * @method loaded
	 * @static
	 * @param {string} $name
	 * @return {boolean}
	 */
	static function loaded($name)
	{
		switch ($name) {
			case 'opcache': return extension_loaded('Zend OPcache');
			case 'password-argon2': return defined('PASSWORD_ARGON2I');
			case 'mbregex': return function_exists('mb_ereg');
		}
		if (strpos($name, 'swoole-hook-') === 0) return extension_loaded('swoole');
		return extension_loaded($name);
	}

	/**
	 * What a loaded extension lacks of the capabilities the standard expects:
	 * gd without JPEG, PNG, WebP or FreeType support.
	 * @method capabilities
	 * @static
	 * @param {string} $name
	 * @return {array} missing capability names; empty when complete or not applicable
	 */
	static function capabilities($name)
	{
		if ($name === 'gd' && function_exists('gd_info')) {
			$info = gd_info();
			$missing = array();
			foreach (array('JPEG Support' => 'jpeg', 'PNG Support' => 'png', 'WebP Support' => 'webp', 'FreeType Support' => 'freetype') as $k => $label) {
				if (empty($info[$k])) $missing[] = $label;
			}
			return $missing;
		}
		return array();
	}

	/**
	 * The running PHP against a variant, on this platform: what is present,
	 * what is missing per tier, and the largest variant this PHP satisfies.
	 * Extensions this platform cannot have are not counted as missing.
	 *
	 * @method check
	 * @static
	 * @param {string} [$variant] default: the manifest's default variant
	 * @param {callable} [$isLoaded] function(string $name): bool, for tests
	 * @return {array} variant, platform, php, variant_detected, rows[], missing (tier => names), missing_required, missing_recommended, incomplete (name => missing capabilities)
	 */
	static function check($variant = null, $isLoaded = null)
	{
		$m = self::manifest();
		$variant = $variant ?? $m['defaultVariant'];
		$isLoaded = $isLoaded ?? array(__CLASS__, 'loaded');
		$r = self::resolve($variant, null, null, array(), self::isStaticBuild());
		$rows = array(); $missing = array(); $incomplete = array();
		foreach ($r['include'] as $name) {
			$e = $m['extensions'][$name];
			$has = (bool) call_user_func($isLoaded, $name);
			$caps = $has ? self::capabilities($name) : array();
			if ($caps) $incomplete[$name] = $caps;
			if (!$has) $missing[$e['tier']][] = $name;
			$rows[] = array('name' => $name, 'tier' => $e['tier'], 'loaded' => $has, 'missingCapabilities' => $caps, 'purpose' => $e['purpose']);
		}
		$required = array_merge($missing['server'] ?? array(), $missing['required'] ?? array());
		return array('variant' => $variant, 'platform' => $r['platform'], 'php' => $r['php'],
			'variant_detected' => self::detectVariant($isLoaded), 'rows' => $rows, 'missing' => $missing,
			'missing_required' => $required, 'missing_recommended' => $missing['recommended'] ?? array(),
			'incomplete' => $incomplete);
	}

	/**
	 * The largest variant this PHP fully satisfies on this platform
	 * (mini < lite < standard < full), or 'none'.
	 * @method detectVariant
	 * @static
	 * @param {callable} [$isLoaded]
	 * @return {string}
	 */
	static function detectVariant($isLoaded = null)
	{
		$isLoaded = $isLoaded ?? array(__CLASS__, 'loaded');
		$best = 'none';
		foreach (array('mini', 'lite', 'standard', 'full') as $v) {
			foreach (self::resolve($v, null, null, array(), self::isStaticBuild())['include'] as $name) {
				if (!call_user_func($isLoaded, $name)) return $best;
			}
			$best = $v;
		}
		return $best;
	}

	/**
	 * What must be present for the server to start at all, given how it is
	 * configured. Process isolation (pcntl or php-cgi) is checked by
	 * Q_WebServer::requireIsolation(); this covers the rest.
	 *
	 * @method hardNeeds
	 * @static
	 * @param {array} $opts https (bool): TLS is configured; transform (bool): the source transform is on
	 * @param {callable} [$isLoaded]
	 * @return {array} list of array(name, why)
	 */
	static function hardNeeds(array $opts, $isLoaded = null)
	{
		$isLoaded = $isLoaded ?? array(__CLASS__, 'loaded');
		$need = array();
		if (!empty($opts['transform']) && !call_user_func($isLoaded, 'tokenizer')) {
			$need[] = array('tokenizer', 'the source transform that lets applications run in persistent workers reads code with token_get_all()');
		}
		if (!empty($opts['https']) && !call_user_func($isLoaded, 'openssl')) {
			$need[] = array('openssl', 'HTTPS is configured, and TLS needs openssl');
		}
		return $need;
	}

	/**
	 * The package manager that installs PHP extensions on this machine:
	 * apt, dnf, dnf-scl (Remi's php83-php-* collections), apk, pkg, brew,
	 * windows, or '' when unknown.
	 * @method packageManager
	 * @static
	 * @param {string} [$osRelease] contents of /etc/os-release, for tests
	 * @param {string} [$phpBinary] PHP_BINARY, for tests
	 * @return {string}
	 */
	static function packageManager($osRelease = null, $phpBinary = null)
	{
		$phpBinary = $phpBinary ?? PHP_BINARY;
		if (PHP_OS_FAMILY === 'Windows' && $osRelease === null) return 'windows';
		if (PHP_OS_FAMILY === 'Darwin' && $osRelease === null) return 'brew';
		if (PHP_OS_FAMILY === 'BSD' && $osRelease === null) return 'pkg';
		if (preg_match('#/opt/remi/php\d\d/#', (string) $phpBinary)) return 'dnf-scl';
		if (preg_match('#/opt/plesk/php/\d+\.\d+/#', (string) $phpBinary)) return 'plesk';
		$osRelease = $osRelease ?? (string) @file_get_contents('/etc/os-release');
		$ids = array();
		if (preg_match('/^ID="?([^"\n]+)"?/m', $osRelease, $mm)) $ids[] = strtolower($mm[1]);
		if (preg_match('/^ID_LIKE="?([^"\n]+)"?/m', $osRelease, $mm)) $ids = array_merge($ids, preg_split('/\s+/', strtolower($mm[1])));
		foreach ($ids as $id) {
			if (in_array($id, array('debian', 'ubuntu'), true)) return 'apt';
			if ($id === 'alpine') return 'apk';
			if (in_array($id, array('rhel', 'fedora', 'centos', 'rocky', 'almalinux', 'amzn', 'ol'), true)) return 'dnf';
			if ($id === 'freebsd') return 'pkg';
		}
		return '';
	}

	/**
	 * How to install extensions on this machine: one install command for the
	 * packaged ones, the add-on and documented drivers' own steps, and notes
	 * for anything that cannot be installed that way.
	 *
	 * @method installHints
	 * @static
	 * @param {array} $names extension or driver names (manifest names, or msaccess)
	 * @param {string} [$manager] default: packageManager()
	 * @param {string} [$php] default: this PHP, 8.3
	 * @return {array} manager, commands[], notes[]
	 */
	static function installHints(array $names, $manager = null, $php = null)
	{
		$m = self::manifest();
		$manager = $manager ?? self::packageManager();
		$php = $php ?? self::phpVersion();
		$subst = function ($s, $name) use ($php) {
			return strtr($s, array('{v}' => $php, '{vv}' => str_replace('.', '', $php), '{ext}' => $name));
		};
		$packages = array(); $commands = array(); $notes = array(); $pecl = array();
		$core = array_map('strtolower', $m['core']);
		foreach (array_unique($names) as $name) {
			$driver = $m['addons'][$name] ?? $m['documented'][$name] ?? null;
			if ($driver) {
				$steps = $driver['install'][$manager] ?? null;
				if ($steps === null) {
					$notes[] = "$name ({$driver['database']}): no recipe for " . ($manager ?: 'this system') . '; ' . $driver['why'];
					continue;
				}
				foreach ($steps as $s) $commands[] = $subst($s, $name);
				continue;
			}
			if (in_array(strtolower($name), $core, true)) { $notes[] = "$name is part of PHP itself; reinstall PHP"; continue; }
			$e = $m['extensions'][$name] ?? null;
			if ($e === null) { $notes[] = "$name is not in the manifest"; continue; }
			$type = $e['type'] === 'pecl' ? 'pecl' : 'bundled';
			$pattern = $e['packages'][$manager] ?? $m['packageDefaults'][$type][$manager] ?? null;
			if ($manager === '' || $pattern === null) {
				if ($type === 'pecl') $pecl[] = $name;
				else $notes[] = "$name: build PHP with --enable-$name / --with-$name, or install your distribution's package for it";
				continue;
			}
			if ($pattern === '') {
				$notes[] = $manager === 'plesk'
					? "$name ships with Plesk's PHP $php; switch it on under Tools & Settings > PHP Settings"
					: "$name is included with PHP from Homebrew (brew install shivammathur/php/php@$php)";
				continue;
			}
			// A pattern naming its own command rather than a package.
			if (strncmp($pattern, 'pecl:', 5) === 0) {
				$commands[] = $subst(substr($pattern, 5), $name) . '   # then add extension=' . $name . ' to php.ini';
				continue;
			}
			$packages[$subst($pattern, $name)] = true;
		}
		$list = implode(' ', array_keys($packages));
		if ($list !== '') {
			switch ($manager) {
				case 'apt': $commands[] = "sudo apt-get install -y $list"; break;
				case 'dnf': case 'dnf-scl': $commands[] = "sudo dnf install -y $list"; break;
				case 'apk': $commands[] = "apk add $list"; break;
				case 'pkg': $commands[] = "pkg install -y $list"; break;
				case 'brew': $commands[] = "brew install $list"; break;
				case 'windows': foreach (array_keys($packages) as $p) $commands[] = $p; break;
			}
		}
		foreach ($pecl as $p) $commands[] = "pecl install $p   # then add extension=$p to php.ini";
		if ($list !== '' && in_array($manager, array('apt', 'dnf', 'dnf-scl', 'apk', 'pkg'), true)) {
			$notes[] = 'Restart PHP-FPM, or the server, afterwards so the extensions load.';
		}
		return array('manager' => $manager, 'commands' => $commands, 'notes' => $notes);
	}

	/**
	 * The summary /Q/health and the dashboard show: computed once per process
	 * (extensions do not change while PHP runs).
	 * @method summary
	 * @static
	 * @return {array} variant_detected, platform, php, missing_required, missing_recommended, incomplete, hints
	 */
	static function summary()
	{
		static $s = null;
		if ($s !== null) return $s;
		try {
			$c = self::check();
		} catch (Throwable $e) {
			return $s = array('error' => $e->getMessage());
		}
		$missing = array_merge($c['missing_required'], $c['missing_recommended']);
		$s = array('variant_detected' => $c['variant_detected'], 'platform' => $c['platform'], 'php' => $c['php'],
			'missing_required' => $c['missing_required'], 'missing_recommended' => $c['missing_recommended'],
			'incomplete' => $c['incomplete'], 'hints' => $missing ? self::installHints($missing)['commands'] : array());
		return $s;
	}

	/**
	 * The warning block the server prints at start when the running PHP lacks
	 * baseline extensions, or '' when it has them all.
	 * @method startupWarning
	 * @static
	 * @return {string}
	 */
	static function startupWarning()
	{
		$s = self::summary();
		if (isset($s['error'])) return "  extensions: cannot check ({$s['error']})\n";
		if (!$s['missing_required'] && !$s['missing_recommended'] && !$s['incomplete']) return '';
		$out = "  extensions: this PHP provides the '{$s['variant_detected']}' set; missing from the standard set:\n";
		if ($s['missing_required']) $out .= '    required:    ' . implode(', ', $s['missing_required']) . "\n";
		if ($s['missing_recommended']) $out .= '    recommended: ' . implode(', ', $s['missing_recommended']) . "\n";
		foreach ($s['incomplete'] as $name => $caps) $out .= "    $name without: " . implode(', ', $caps) . "\n";
		foreach ($s['hints'] as $h) $out .= "    fix: $h\n";
		$out .= "    (qbixctl ext:check for the full report; docs/requirements.md for what each is for)\n";
		return $out;
	}
}
