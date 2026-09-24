<?php
/**
 * @module Q
 */

/**
 * Web-based control panel for managing Qbix apps.
 *
 * Serves at /Q/panel. Provides:
 * - List/create/start/stop apps
 * - Run scripts (configure, install, urls, etc.) via web
 * - Open app folders in Finder/Explorer/VS Code
 * - Plugin management
 * - System info
 *
 * No CLI needed. Everything a normie needs to manage
 * their server from a browser.
 *
 * @class Q_WebServer_Panel
 */
class Q_WebServer_Panel
{
	/** @internal Currently served app dirName, or null */
	static $servingApp = null;
	/**
	 * Handle panel requests with authentication.
	 * First visitor sets a password. All subsequent requests require it.
	 * Password stored in APP_DIR/local/panel.json (gitignored).
	 * @method handle
	 * @static
	 * @param {resource} $client
	 * @param {array} $parsed
	 * @return {boolean} true if handled
	 */
	static function handle($client, $parsed)
	{
		$r = self::respond($parsed);
		if ($r === null) return false;
		$headers = $r['headers'] ?? array();
		$type = $headers['Content-Type'] ?? 'text/plain; charset=utf-8';
		unset($headers['Content-Type']);
		Q_WebServer::sendResponse($client, $r['status'] ?? 200, $r['body'] ?? '', $type, $headers);
		return true;
	}

	/**
	 * The panel's answer to a request, as a response array, or null when the
	 * path is not the panel's. handle() sends it on an HTTP/1.1 connection;
	 * Q_WebServer::route() returns it to HTTP/2, which a browser speaks by
	 * default -- and which, answered by nothing, handed /Q/panel to the
	 * application and showed its 404. One function, so both protocols apply
	 * the same address rule and the same authentication.
	 * @method respond
	 * @static
	 * @param {array} $parsed with clientIp (or _remoteAddr) set
	 * @return {array|null} status, headers, body
	 */
	static function respond($parsed)
	{
		$path = $parsed['path'];
		$json = function ($status, $data) {
			return array('status' => $status, 'body' => json_encode($data),
				'headers' => array('Content-Type' => 'application/json'));
		};

		// Who may reach the panel at all: see allowed(). The same rule on
		// both protocols, since both come through here.
		if (strpos($path, '/Q/panel') === 0 || strpos($path, '/Q/api/') === 0) {
			if (!self::allowed($parsed)) {
				if (strpos($path, '/Q/api/') === 0) {
					return $json(403, array('error' => self::REFUSED_TEXT));
				}
				return array('status' => 403,
					'body' => self::refusedPage(self::REFUSED_HTML),
					'headers' => array('Content-Type' => 'text/html; charset=utf-8'));
			}
		}

		if ($path === '/Q/panel' || $path === '/Q/panel/') {
			return array('status' => 200, 'body' => self::renderPanel($parsed),
				'headers' => array('Content-Type' => 'text/html; charset=utf-8'));
		}

		if (strpos($path, '/Q/api/') !== 0) return null;

		$route = substr($path, 7);
		$body = !empty($parsed['body']) ? (json_decode($parsed['body'], true) ?: array()) : array();

		if ($route === 'auth/login') {
			list($status, $data) = Q_WebServer_Panel_Auth::login($parsed, $body);
			return $json($status, $data);
		}
		if ($route === 'auth/setup') {
			// The first password is set from this machine, with the dashboard
			// token, or where the panel is open remotely on purpose -- never
			// by whoever happens to reach the page first.
			if (!self::setupAllowed($parsed)) {
				return $json(403, array('error' => self::SETUP_REFUSED_TEXT,
					'needsSetup' => !Q_WebServer_Panel_Auth::hasPassword(), 'setupAllowed' => false));
			}
			list($status, $data) = Q_WebServer_Panel_Auth::setup($parsed, $body);
			return $json($status, $data);
		}

		// Everything else needs a session...
		$auth = Q_WebServer_Panel_Auth::checkSession($parsed);
		if (!$auth['ok']) {
			if (!empty($auth['needsSetup'])) $auth += self::setupInfo($parsed);
			return $json(401, $auth);
		}
		// ...that the observers let through: one signed in with the default
		// key can only change it, or sign out.
		$veto = Q_WebServer_Panel_Events::notify('session.request', array(
			'token' => $auth['token'], 'route' => $route,
			'ip' => (string) ($parsed['clientIp'] ?? $parsed['_remoteAddr'] ?? '')));
		if ($veto) return $json($veto['status'], $veto['body']);

		// The shell's HTTP door: the same session, and more checks of its own.
		if (strpos($route, 'shell/') === 0) {
			list($status, $data) = Q_WebServer_Shell_Api::handle($route, $parsed, $auth['token']);
			return $json($status, $data);
		}

		if ($route === 'auth/password') {
			list($status, $data) = Q_WebServer_Panel_Auth::change($parsed, $body);
			return $json($status, $data);
		}
		if ($route === 'auth/logout') {
			list($status, $data) = Q_WebServer_Panel_Auth::logout($parsed);
			return $json($status, $data);
		}

		$result = self::handleApi($path, $parsed);
		return $json($result['status'] ?? 200, $result);
	}

	/** Plain-text refusal, for the API. */
	const REFUSED_TEXT = 'The control panel is available from this machine, with the dashboard token, or once a password is set -- then to anyone who signs in with it. Set one on the server with: qbixctl panel:password --root=<document root>. Or allow the panel remotely with Q.panel.remote.';

	/** The same, for the server's 403 page. Written here, never request data. */
	const REFUSED_HTML = 'The control panel is available from this machine, with the dashboard token, or once a password is set &mdash; then to anyone who signs in with it.<br><br>Set one on the server with <code>qbixctl panel:password</code> <code>--root=&lt;document root&gt;</code>, or allow the panel remotely with <code>Q.panel.remote</code>.';

	/** Why a remote first-time setup was refused, and what to do instead. */
	const SETUP_REFUSED_TEXT = 'The panel password can be set here only from the server itself. Set it on the server with: qbixctl panel:password --root=<document root> (the same --root the server runs with), then sign in here.';

	/**
	 * Whether this request's client is this machine. An empty address is a
	 * Unix-socket client, which is local too.
	 */
	static function isLocal($parsed)
	{
		$ip = (string) ($parsed['clientIp'] ?? $parsed['_remoteAddr'] ?? '');
		return $ip === '' or Q_WebServer::isLocalRequest($parsed);
	}

	/**
	 * Who may reach /Q/panel and /Q/api/: this machine; anyone where
	 * Q.panel.remote is true; anyone once a panel password exists -- the page
	 * then shows its login form, and every API call but auth/login still
	 * needs a session token; and whoever Q_WebServer::adminAllowed() lets see
	 * the dashboard (the dashboard token, a panel session, Q.dashboard.remote).
	 * @method allowed
	 * @static
	 * @param {array} $parsed
	 * @return {boolean}
	 */
	static function allowed($parsed)
	{
		return self::isLocal($parsed)
			|| Q_Config::get('Q', 'panel', 'remote', false)
			|| Q_WebServer_Panel_Auth::canSignIn($parsed)
			|| Q_WebServer::adminAllowed($parsed);
	}

	/**
	 * Whether this request may set the first password: from this machine,
	 * with the dashboard token, or where Q.panel.remote is true. A remote
	 * visitor who merely reached the page is not enough.
	 * @method setupAllowed
	 * @static
	 * @param {array} $parsed
	 * @return {boolean}
	 */
	static function setupAllowed($parsed)
	{
		return self::isLocal($parsed)
			|| Q_Config::get('Q', 'panel', 'remote', false)
			|| Q_WebServer::hasAdminCredential($parsed);
	}

	/** What the page needs to know when no password is set yet. */
	static function setupInfo($parsed)
	{
		$ok = self::setupAllowed($parsed);
		return $ok ? array('setupAllowed' => true)
			: array('setupAllowed' => false, 'setupHelp' => self::SETUP_REFUSED_TEXT);
	}

	/**
	 * The panel's settings file for an application directory -- where the
	 * server keeps it: APP_DIR/local/panel.json, APP_DIR being the directory
	 * above the document root (or the --app directory).
	 * @method configPathFor
	 * @static
	 * @param {string} $appDir
	 * @return {string}
	 */
	static function configPathFor($appDir)
	{
		return rtrim($appDir, '/\\') . '/local/panel.json';
	}

	/**
	 * Set the panel password: the hash auth/setup stores, in the same file
	 * and format. Used by auth/setup and by `qbixctl panel:password`.
	 * @method storePassword
	 * @static
	 * @param {string} $password at least 6 characters
	 * @param {string|null} $configPath default: panelConfigPath()
	 * @param {boolean} $revokeSessions end every existing session (a changed password should)
	 * @return {array} ok, and error or path
	 */
	static function storePassword($password, $configPath = null, $revokeSessions = false)
	{
		return Q_WebServer_Panel_Auth::storePassword($password, $configPath, $revokeSessions);
	}

	/**
	 * Get the panel config file path
	 */
	static function panelConfigPath()
	{
		return defined('APP_DIR')
			? APP_DIR . '/local/panel.json'
			: qbix_data_path('local/panel.json');
	}

	/**
	 * Check if the request has a valid auth token
	 */
	private static function checkAuth($parsed)
	{
		return Q_WebServer_Panel_Auth::checkSession($parsed);
	}

	/**
	 * Validate a session token (for WebSocket auth, etc.)
	 * @method validateToken
	 * @static
	 * @param {string} $token
	 * @return {boolean}
	 */
	static function validateToken($token)
	{
		return Q_WebServer_Panel_Auth::validateToken((string) $token);
	}

	/**
	 * Check whether a panel password has been set
	 * @method hasPassword
	 * @static
	 * @return {boolean}
	 */
	static function hasPassword()
	{
		return Q_WebServer_Panel_Auth::hasPassword();
	}

	static function handleApi($path, $parsed)
	{
		$route = substr($path, 7); // strip /Q/api/

		switch ($route) {
			case 'apps':
				return self::apiListApps();
			case 'apps/fork-mode':
				return self::apiSetForkMode($parsed);
			case 'apps/create':
				return self::apiCreateApp($parsed);
			case 'apps/configure':
				return self::apiRunScript($parsed, 'configure');
			case 'apps/install':
				return self::apiRunScript($parsed, 'install');
			case 'apps/open':
				return self::apiOpenFolder($parsed);
			case 'apps/serve':
				return self::apiServeApp($parsed);
			case 'apps/setdir':
				return self::apiSetAppsDir($parsed);
			case 'scripts':
				return self::apiListScripts($parsed);
			case 'scripts/run':
				return self::apiRunScript($parsed);
			case 'plugins':
				return self::apiListPlugins();
			case 'plugins/add':
				return self::apiAddPlugin($parsed);
			case 'servers':
				return self::apiListServers();
			case 'servers/add':
				return self::apiAddServer($parsed);
			case 'servers/remove':
				return self::apiRemoveServer($parsed);
			case 'servers/deploy':
				return self::apiDeploy($parsed);
			case 'system':
				return self::apiSystemInfo();
			case 'auth/password':
				return self::apiChangePassword($parsed);
			case 'auth/logout':
				return self::apiLogout($parsed);
			case 'playground/run':
				return self::apiPlaygroundRun($parsed);
			case 'platform/install':
				return self::apiInstallPlatform($parsed);
			case 'domains':
				return self::apiListDomains();
			case 'domains/add':
				return self::apiAddDomain($parsed);
			case 'domains/remove':
				return self::apiRemoveDomain($parsed);
			case 'domains/provision':
				return self::apiProvisionCert($parsed);
			case 'domains/hosts':
				return self::apiHostsFile();
			case 'domains/hosts/add':
				return self::apiHostsAdd($parsed);
			case 'autohost':
				require_once dirname(__DIR__) . '/WebServer/Autohost.php';
				return Q_WebServer_Autohost::status();
			case 'autohost/toggle':
				return self::apiAutohostToggle($parsed);
			case 'watchdog':
				require_once dirname(__DIR__) . '/WebServer/Watchdog.php';
				return Q_WebServer_Watchdog::status();
			case 'attestation':
				require_once dirname(__DIR__) . '/WebServer/Trust.php';
				return Q_WebServer_Trust::attestation();
			case 'attestation/sign':
				return self::apiAttestationSign($parsed);
			case 'attestation/verify':
				require_once dirname(__DIR__) . '/WebServer/Trust.php';
				$m = (int) ($parsed['query']['m'] ?? 0) ?: null;
				return Q_WebServer_Trust::verifyBinary(null, $m);
			case 'attestation/publish-rekor':
				require_once dirname(__DIR__) . '/WebServer/Trust.php';
				$bp = realpath($_SERVER['SCRIPT_FILENAME'] ?? $GLOBALS['argv'][0]);
				$uuid = Q_WebServer_Trust::publishToRekor($bp);
				return $uuid
					? ['published' => true, 'uuid' => $uuid, 'url' => "https://search.sigstore.dev/?uuid=$uuid"]
					: ['status' => 500, 'error' => 'Failed. Sign the binary first.'];
			case 'trust':
				require_once dirname(__DIR__) . '/WebServer/Trust.php';
				return Q_WebServer_Trust::status();
			case 'trust/verify':
				return self::apiTrustVerify($parsed);
			case 'metrics':
				require_once dirname(__DIR__) . '/WebServer/Metrics.php';
				return Q_WebServer_Metrics::status();
			case 'metrics/history':
				require_once dirname(__DIR__) . '/WebServer/Metrics.php';
				$minutes = (int) ($parsed['query']['minutes'] ?? 60);
				return ['stats' => Q_WebServer_Metrics::recentStats(min($minutes, 1440))];
			case 'metrics/summary':
				require_once dirname(__DIR__) . '/WebServer/Metrics.php';
				$hours = (int) ($parsed['query']['hours'] ?? 24);
				return Q_WebServer_Metrics::summary(min($hours, 720));
			case 'metrics/flow':
				require_once dirname(__DIR__) . '/WebServer/Metrics.php';
				$limit = (int) ($parsed['query']['limit'] ?? 50);
				return ['edges' => Q_WebServer_Metrics::flow(min($limit, 200))];
			case 'metrics/pages':
				require_once dirname(__DIR__) . '/WebServer/Metrics.php';
				$limit = (int) ($parsed['query']['limit'] ?? 20);
				return ['pages' => Q_WebServer_Metrics::topPages(min($limit, 100))];
			case 'metrics/pageflow':
				require_once dirname(__DIR__) . '/WebServer/Metrics.php';
				$path = $parsed['query']['path'] ?? '/';
				return Q_WebServer_Metrics::pageFlow($path);
			case 'workers':
				return self::apiWorkerStatus();
			case 'workers/resize':
				return self::apiWorkerResize($parsed);
			case 'workers/recycle':
				return self::apiWorkerRecycle($parsed);
			case 'workers/detail':
				return self::apiWorkerDetail();
			case 'logs':
				return self::apiLogs($parsed);
			case 'cron':
				return self::apiCronStatus();
			case 'cron/run':
				return self::apiCronRun($parsed);
			case 'frameworks':
				return self::apiFrameworks();
			case 'frameworks/run':
				return self::apiFrameworkRun($parsed);
			case 'frameworks/packages':
				return self::apiFrameworkPackages($parsed);
			case 'frameworks/composer':
				return self::apiFrameworkComposer($parsed);
			case 'frameworks/pkg-action':
				return self::apiFrameworkPkgAction($parsed);
			case 'frameworks/pkg-download':
				return self::apiFrameworkPkgDownload($parsed);
			case 'qbix/installer':
				return self::apiQbixInstaller($parsed);
			case 'qbix/npm':
				return self::apiQbixNpm($parsed);
			case 'qbix/plugins':
				return self::apiQbixPlugins();
			case 'qbix/plugins/install':
				return self::apiQbixPluginInstall($parsed);
			case 'qbix/plugins/schema':
				return self::apiQbixPluginSchema($parsed);
			default:
				return array('status' => 404, 'error' => 'Unknown endpoint');
		}
	}

	private static function apiChangePassword($parsed)
	{
		$body = !empty($parsed['body']) ? (json_decode($parsed['body'], true) ?: array()) : array();
		list($status, $data) = Q_WebServer_Panel_Auth::change($parsed, $body);
		return $data + array('status' => $status);
	}

	private static function apiLogout($parsed)
	{
		list($status, $data) = Q_WebServer_Panel_Auth::logout($parsed);
		return $data + array('status' => $status);
	}

	// ── Apps API ─────────────────────────────────────────

	static function apiListApps()
	{
		$appsDir = self::appsDir();
		$apps = array();
		if (!$appsDir || !is_dir($appsDir)) {
			return array('apps' => $apps, 'appsDir' => $appsDir);
		}

		foreach (scandir($appsDir) as $name) {
			if ($name[0] === '.' || !is_dir($appsDir . DS . $name)) continue;
			$appDir = $appsDir . DS . $name;

			// Include any directory that has web/ or config/app.json
			$hasWeb = is_dir($appDir . DS . 'web');
			$configFile = $appDir . DS . 'config' . DS . 'app.json';
			$hasConfig = file_exists($configFile);
			if (!$hasWeb && !$hasConfig) continue;

			$config = $hasConfig
				? json_decode(file_get_contents($configFile), true)
				: array();
			$localConfig = null;
			$localFile = $appDir . DS . 'local' . DS . 'app.json';
			if (file_exists($localFile)) {
				$localConfig = json_decode(file_get_contents($localFile), true);
			}

			$appName = $config['Q']['app'] ?? $name;
			$plugins = $config['Q']['plugins'] ?? array();
			$configured = is_dir($appDir . DS . 'local');
			$url = $localConfig['Q']['web']['appRootUrl'] ?? '';
			$hasHandlers = is_dir($appDir . DS . 'handlers');
			$hasClasses = is_dir($appDir . DS . 'classes');
			$hasScripts = is_dir($appDir . DS . 'scripts');
			$isQbixApp = $hasConfig && isset($config['Q']);

			$apps[] = array(
				'name' => $appName,
				'dir' => $appDir,
				'dirName' => $name,
				'plugins' => $plugins,
				'configured' => $configured,
				'url' => $url,
				'hasWeb' => $hasWeb,
				'hasHandlers' => $hasHandlers,
				'hasClasses' => $hasClasses,
				'hasScripts' => $hasScripts,
				'isQbixApp' => $isQbixApp,
				'serving' => (self::$servingApp === $name),
				'forkPerRequest' => $localConfig['Q']['webserver']['forkPerRequest'] ?? $config['Q']['webserver']['forkPerRequest'] ?? null,
			);
		}

		return array('apps' => $apps, 'appsDir' => $appsDir);
	}

	/**
	 * Set forkPerRequest mode for an app.
	 * Writes to the app's local/app.json so it persists.
	 */
	static function apiSetForkMode($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$appDirName = $body['app'] ?? '';
		$forkMode = $body['forkPerRequest'] ?? null;

		if (!$appDirName) return array('status' => 400, 'error' => 'App name required');

		$appsDir = self::appsDir();
		$appDir = $appsDir . DS . $appDirName;
		if (!is_dir($appDir)) return array('status' => 404, 'error' => 'App not found');

		$localDir = $appDir . DS . 'local';
		@mkdir($localDir, 0755, true);
		$localFile = $localDir . DS . 'app.json';

		$config = array();
		if (is_file($localFile)) {
			$config = json_decode(file_get_contents($localFile), true) ?: array();
		}

		if ($forkMode === null || $forkMode === 'auto') {
			// Remove the setting (use server default)
			unset($config['Q']['webserver']['forkPerRequest']);
			// Clean up empty nesting
			if (empty($config['Q']['webserver'])) unset($config['Q']['webserver']);
			if (empty($config['Q'])) unset($config['Q']);
		} else {
			$config['Q']['webserver']['forkPerRequest'] = (bool) $forkMode;
		}

		file_put_contents($localFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		return array('ok' => true, 'forkPerRequest' => $forkMode, 'note' => 'Restart the server for changes to take effect.');
	}

	static function apiCreateApp($parsed)
	{
		$body = json_decode($parsed['body'], true);
		$name = preg_replace('/[^A-Za-z0-9_]/', '', $body['name'] ?? '');
		if (!$name) return array('status' => 400, 'error' => 'App name required');

		$template = $body['template'] ?? 'MyApp';
		$appsDir = self::appsDir();
		$targetDir = $appsDir . DS . $name;

		if (file_exists($targetDir)) {
			return array('status' => 409, 'error' => "App '$name' already exists");
		}

		// Find template
		$templateDir = null;
		$candidates = array(
			$appsDir . DS . $template,
			dirname($appsDir) . DS . $template,
			defined('Q_DIR') ? Q_DIR . DS . '..' . DS . $template : null,
		);
		foreach ($candidates as $c) {
			if (is_dir($c) && file_exists($c . DS . 'config' . DS . 'app.json')) {
				$templateDir = realpath($c);
				break;
			}
		}
		if (!$templateDir) {
			// No template found — create a minimal standalone app
			@mkdir($targetDir . DS . 'web', 0755, true);
			@mkdir($targetDir . DS . 'handlers', 0755, true);
			@mkdir($targetDir . DS . 'classes', 0755, true);
			@mkdir($targetDir . DS . 'config', 0755, true);

			file_put_contents($targetDir . DS . 'config' . DS . 'app.json',
				json_encode(array('Q' => array('app' => $name)), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

			file_put_contents($targetDir . DS . 'web' . DS . 'index.html',
				"<!DOCTYPE html>\n<html><head><title>{$name}</title></head>\n"
				. "<body><h1>{$name}</h1><p>Edit web/index.html to get started.</p></body></html>\n");

			return array('created' => $name, 'dir' => $targetDir);
		}

		// Copy template
		self::copyDir($templateDir, $targetDir);

		// Rename references
		$oldName = basename($templateDir);
		self::renameInApp($targetDir, $oldName, $name);

		return array('created' => $name, 'dir' => $targetDir);
	}

	// ── Scripts API ──────────────────────────────────────

	static function apiListScripts($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$appName = $body['app'] ?? '';

		$scripts = array();

		// Platform scripts
		$platformScripts = defined('Q_DIR') ? Q_DIR . DS . 'scripts' : null;
		if ($platformScripts && is_dir($platformScripts)) {
			foreach (glob($platformScripts . DS . '*.php') as $f) {
				$scripts[] = array(
					'name' => basename($f, '.php'),
					'path' => $f,
					'scope' => 'platform'
				);
			}
		}

		// App scripts
		if ($appName) {
			$appDir = self::appsDir() . DS . $appName;
			$appScripts = $appDir . DS . 'scripts' . DS . 'Q';
			if (is_dir($appScripts)) {
				foreach (glob($appScripts . DS . '*.php') as $f) {
					$scripts[] = array(
						'name' => basename($f, '.php'),
						'path' => $f,
						'scope' => 'app'
					);
				}
			}
		}

		return array('scripts' => $scripts);
	}

	static function apiRunScript($parsed, $scriptName = null)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$appName = $body['app'] ?? '';
		$scriptName = $scriptName ?: ($body['script'] ?? '');
		$args = $body['args'] ?? array();

		if (!$appName || !$scriptName) {
			return array('status' => 400, 'error' => 'app and script required');
		}

		$appDir = self::appsDir() . DS . $appName;
		if (!is_dir($appDir)) {
			return array('status' => 404, 'error' => "App '$appName' not found");
		}

		$scriptPath = $appDir . DS . 'scripts' . DS . 'Q' . DS . $scriptName . '.php';
		if (!file_exists($scriptPath)) {
			return array('status' => 404, 'error' => "Script '$scriptName' not found");
		}

		// Run script as subprocess
		$argStr = '';
		foreach ($args as $k => $v) {
			if (is_numeric($k)) {
				$argStr .= ' ' . escapeshellarg($v);
			} else {
				$argStr .= ' --' . $k . '=' . escapeshellarg($v);
			}
		}

		$cmd = PHP_BINARY . ' ' . escapeshellarg($scriptPath) . $argStr . ' 2>&1';
		$output = array();
		$code = 0;
		exec($cmd, $output, $code);

		return array(
			'script' => $scriptName,
			'app' => $appName,
			'exitCode' => $code,
			'output' => implode("\n", $output)
		);
	}

	// ── Plugins API ──────────────────────────────────────

	static function apiListPlugins()
	{
		$plugins = array();
		$platformDir = null;
		$pluginsDir = null;

		// 1. Find platform via local/paths.json
		if (defined('APP_DIR')) {
			$pathsFile = APP_DIR . DS . 'local' . DS . 'paths.json';
			if (file_exists($pathsFile)) {
				$paths = json_decode(file_get_contents($pathsFile), true);
				if (!empty($paths['platform'])) {
					$platformDir = realpath($paths['platform']);
				}
			}
		}
		if (!$platformDir && defined('Q_DIR')) {
			$platformDir = Q_DIR;
		}

		// 2. Read app's plugin list from config/app.json
		$appPlugins = array();
		if (defined('APP_DIR')) {
			$appConfig = APP_DIR . DS . 'config' . DS . 'app.json';
			if (file_exists($appConfig)) {
				$config = json_decode(file_get_contents($appConfig), true);
				$appPlugins = $config['Q']['plugins'] ?? array();
			}
		}

		// 3. Read installed versions from local/plugins.json
		$installedVersions = array();
		if (defined('APP_DIR')) {
			$localPlugins = APP_DIR . DS . 'local' . DS . 'plugins.json';
			if (file_exists($localPlugins)) {
				$lp = json_decode(file_get_contents($localPlugins), true);
				$installedVersions = $lp['Q']['pluginLocal'] ?? array();
			}
		}

		// 4. Find plugins directory
		if ($platformDir) {
			$pluginsDir = $platformDir . DS . 'plugins';
			if (!is_dir($pluginsDir)) {
				$pluginsDir = dirname($platformDir) . DS . 'plugins';
			}
		}

		// 5. Build plugin list — prefer app's declared plugins, fall back to scanning
		$pluginNames = !empty($appPlugins) ? $appPlugins : array();
		if (empty($pluginNames) && $pluginsDir && is_dir($pluginsDir)) {
			foreach (scandir($pluginsDir) as $name) {
				if ($name[0] === '.' || !is_dir($pluginsDir . DS . $name)) continue;
				$pluginNames[] = $name;
			}
		}

		foreach ($pluginNames as $name) {
			$pDir = $pluginsDir ? $pluginsDir . DS . $name : null;
			$configFile = $pDir ? $pDir . DS . 'config' . DS . 'plugin.json' : null;
			$pConfig = ($configFile && file_exists($configFile))
				? json_decode(file_get_contents($configFile), true) : null;

			$info = $installedVersions[$name] ?? array();
			$pluginInfo = $pConfig['Q']['pluginInfo'][$name] ?? array();

			$plugins[] = array(
				'name' => $name,
				'dir' => $pDir,
				'installed' => isset($info['version']),
				'version' => $info['version'] ?? $pluginInfo['version'] ?? null,
				'compatible' => $info['compatible'] ?? $pluginInfo['compatible'] ?? null,
				'requires' => $pluginInfo['requires'] ?? $info['requires'] ?? array(),
				'connections' => $pluginInfo['connections'] ?? $info['connections'] ?? array(),
				'hasConfig' => $configFile && file_exists($configFile),
				'inApp' => in_array($name, $appPlugins),
			);
		}

		return array(
			'plugins' => $plugins,
			'pluginsDir' => $pluginsDir,
			'platformDir' => $platformDir,
			'appPlugins' => $appPlugins,
		);
	}

	// ── System API ───────────────────────────────────────

	/**
	 * Run PHP code in an isolated forked child. 5 second timeout.
	 * The child has no filesystem write access and no network.
	 */
	/**
	 * Add a plugin by cloning from github.com/Qbix/{name}.
	 * If the repo is private or doesn't exist, returns {private: true}.
	 */
	// ── Server management ─────────────────────────────

	static function apiListServers()
	{
		$config = self::deployConfig();
		$servers = array();
		foreach (($config['targets'] ?? array()) as $name => $t) {
			$servers[] = array_merge(array('name' => $name), $t);
		}
		return array('servers' => $servers);
	}

	static function apiAddServer($parsed)
	{
		$body = json_decode($parsed['body'], true);
		$name = preg_replace('/[^a-zA-Z0-9_-]/', '', $body['name'] ?? '');
		if (!$name) return array('status' => 400, 'error' => 'Name required');
		if (empty($body['host'])) return array('status' => 400, 'error' => 'Host required');

		$config = self::deployConfig();
		$config['targets'][$name] = array(
			'host' => $body['host'],
			'user' => $body['user'] ?? 'deploy',
			'path' => $body['path'] ?? '/var/www/' . $name,
			'key' => $body['key'] ?? '',
			'dirs' => array('web', 'handlers', 'classes', 'config'),
		);
		self::saveDeployConfig($config);
		return array('ok' => true, 'name' => $name);
	}

	static function apiRemoveServer($parsed)
	{
		$body = json_decode($parsed['body'], true);
		$name = $body['name'] ?? '';
		$config = self::deployConfig();
		unset($config['targets'][$name]);
		self::saveDeployConfig($config);
		return array('ok' => true);
	}

	static function apiDeploy($parsed)
	{
		$body = json_decode($parsed['body'], true);
		$target = $body['target'] ?? '';
		$config = self::deployConfig();
		$t = $config['targets'][$target] ?? null;
		if (!$t) return array('error' => "Unknown target: $target");

		$baseDir = defined('APP_DIR') ? APP_DIR : Q_WebServer::$rootDir . '..';
		$dirs = $t['dirs'] ?? array('web', 'handlers', 'classes', 'config');
		$sshKey = !empty($t['key']) ? " -e 'ssh -i " . escapeshellarg($t['key']) . "'" : '';
		$remote = $t['user'] . '@' . $t['host'] . ':' . rtrim($t['path'], '/') . '/';

		$total = 0;
		$log = '';
		foreach ($dirs as $dir) {
			$localDir = $baseDir . DS . $dir;
			if (!is_dir($localDir)) continue;
			$cmd = "rsync -avz --delete{$sshKey} "
				. escapeshellarg(rtrim($localDir, '/') . '/') . " "
				. escapeshellarg($remote . $dir . '/') . " 2>&1";
			$output = shell_exec($cmd);
			$log .= "rsync $dir/\n" . $output . "\n";
			$lines = array_filter(explode("\n", trim($output)), function ($l) {
				return $l && $l[0] !== '.' && substr($l, -1) !== '/'
					&& strpos($l, 'sending') === false && strpos($l, 'total') === false;
			});
			$total += count($lines);
		}

		return array('ok' => true, 'files' => $total, 'output' => $log);
	}

	private static function deployConfigPath()
	{
		$base = defined('APP_DIR') ? APP_DIR : dirname(Q_WebServer::$rootDir);
		return $base . DS . 'config' . DS . 'deploy.json';
	}

	private static function deployConfig()
	{
		$path = self::deployConfigPath();
		return file_exists($path) ? json_decode(file_get_contents($path), true) : array('targets' => array());
	}

	private static function saveDeployConfig($config)
	{
		$path = self::deployConfigPath();
		$dir = dirname($path);
		if (!is_dir($dir)) @mkdir($dir, 0755, true);
		file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	}

	/**
	 * Add a plugin by cloning from github.com/Qbix/{name}.
	 */
	static function apiAddPlugin($parsed)
	{
		$body = json_decode($parsed['body'], true);
		$name = preg_replace('/[^A-Za-z0-9_-]/', '', $body['name'] ?? '');
		if (!$name) return array('status' => 400, 'error' => 'Plugin name required');

		// Find plugins directory
		$platformDir = null;
		if (defined('APP_DIR')) {
			$pathsFile = APP_DIR . DS . 'local' . DS . 'paths.json';
			if (file_exists($pathsFile)) {
				$paths = json_decode(file_get_contents($pathsFile), true);
				if (!empty($paths['platform'])) $platformDir = realpath($paths['platform']);
			}
		}
		if (!$platformDir && defined('Q_DIR')) $platformDir = Q_DIR;
		if (!$platformDir) {
			return array('error' => 'Qbix Platform not installed. Install it from the System tab first.');
		}

		$pluginsDir = $platformDir . DS . 'plugins';
		if (!is_dir($pluginsDir)) {
			$pluginsDir = dirname($platformDir) . DS . 'plugins';
		}
		if (!is_dir($pluginsDir)) {
			return array('error' => 'Plugins directory not found at ' . $pluginsDir);
		}

		$targetDir = $pluginsDir . DS . $name;
		if (is_dir($targetDir)) {
			return array('error' => "$name is already installed at $targetDir");
		}

		if (!self::which('git')) {
			return array('error' => 'git not found. Install git first.');
		}

		// Try to clone — test if accessible first with git ls-remote
		$testCmd = 'git ls-remote https://github.com/Qbix/' . escapeshellarg($name) . '.git HEAD 2>&1';
		$testOutput = shell_exec($testCmd);

		if (strpos($testOutput, 'fatal') !== false
			|| strpos($testOutput, 'not found') !== false
			|| strpos($testOutput, 'could not read') !== false
		) {
			return array('private' => true, 'name' => $name);
		}

		// Clone into plugins directory
		$cmd = 'cd ' . escapeshellarg($pluginsDir)
			. ' && git clone https://github.com/Qbix/' . escapeshellarg($name) . '.git'
			. ' ' . escapeshellarg($name) . ' 2>&1'
			. ' && cd ' . escapeshellarg($name)
			. ' && git submodule init 2>&1'
			. ' && git submodule update --recursive 2>&1';
		$output = shell_exec($cmd);

		if (!is_dir($targetDir)) {
			return array('error' => 'Clone failed', 'output' => $output);
		}

		return array('ok' => true, 'name' => $name, 'dir' => $targetDir, 'output' => $output);
	}

	static function apiPlaygroundRun($parsed)
	{
		$body = json_decode($parsed['body'], true);
		$code = $body['code'] ?? '';
		if (!$code) return array('output' => '', 'ms' => 0);

		// Strip opening <?php tag if present
		$code = preg_replace('/^\s*<\?php\s*/i', '', $code);

		$start = microtime(true);

		// Build a wrapper that loads Q.php for access to Q::, Q_Config, etc.
		$qPath = dirname(dirname(__DIR__)) . DS . 'Q.php';
		$bootstrap = "error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);\n"
			. "require_once " . var_export($qPath, true) . ";\n";
		if (defined('APP_DIR')) {
			$bootstrap .= "if (method_exists('Q','init')) Q::init(" . var_export(APP_DIR, true) . ");\n";
		}
		$fullCode = "<?php\n" . $bootstrap . $code;

		// Write to temp file (safer than -r for complex code)
		$tmpFile = tempnam(sys_get_temp_dir(), 'qplay_');
		file_put_contents($tmpFile, $fullCode);

		$descriptors = array(
			0 => array('pipe', 'r'),
			1 => array('pipe', 'w'),
			2 => array('pipe', 'w'),
		);
		$cmd = PHP_BINARY . ' -d disable_functions=exec,shell_exec,system,passthru,popen,proc_open'
			. ',file_put_contents,fwrite,unlink,rmdir,mkdir,rename,chmod,chown'
			. ',curl_init,fsockopen,stream_socket_client'
			. ' -d disable_classes=SplFileObject'
			. ' -d open_basedir=' . escapeshellarg(sys_get_temp_dir() . ':' . dirname(dirname(__DIR__)))
			. ' -d memory_limit=32M -d max_execution_time=5'
			. ' ' . escapeshellarg($tmpFile);

		$proc = @proc_open($cmd, $descriptors, $pipes);
		if (!is_resource($proc)) {
			@unlink($tmpFile);
			return array('output' => '', 'error' => 'Failed to start process', 'ms' => 0);
		}
		fclose($pipes[0]);

		stream_set_timeout($pipes[1], 5);
		stream_set_timeout($pipes[2], 5);
		$output = stream_get_contents($pipes[1], 65536);
		$stderr = stream_get_contents($pipes[2], 65536);
		fclose($pipes[1]);
		fclose($pipes[2]);

		$exitCode = proc_close($proc);
		@unlink($tmpFile);
		$ms = round((microtime(true) - $start) * 1000, 1);

		// Filter out xdebug noise from stderr
		if ($stderr) {
			$stderr = preg_replace('/^Xdebug:.*\n?/m', '', $stderr);
			$stderr = preg_replace('/^Cannot load Xdebug.*\n?/m', '', $stderr);
			$stderr = trim($stderr);
		}

		$result = array('output' => $output, 'ms' => $ms);
		if ($stderr) $result['error'] = $stderr;
		if ($exitCode !== 0 && !$stderr) $result['error'] = "Exit code: $exitCode";

		return $result;
	}

	static function apiSystemInfo()
	{
		$platformDir = defined('Q_DIR') ? Q_DIR : null;
		if (!$platformDir && defined('APP_DIR')) {
			$pathsFile = APP_DIR . DS . 'local' . DS . 'paths.json';
			if (file_exists($pathsFile)) {
				$paths = json_decode(file_get_contents($pathsFile), true);
				if (!empty($paths['platform'])) {
					$platformDir = realpath($paths['platform']) ?: $paths['platform'];
				}
			}
		}
		return array(
			'php' => PHP_VERSION,
			'os' => PHP_OS,
			'arch' => php_uname('m'),
			'extensions' => get_loaded_extensions(),
			'hasComposer' => self::which('composer') !== null,
			'hasNode' => self::which('node') !== null,
			'hasNpm' => self::which('npm') !== null,
			'hasPcntl' => function_exists('pcntl_fork'),
			'hasApcu' => function_exists('apcu_fetch'),
			'memoryLimit' => ini_get('memory_limit'),
			'platform' => $platformDir,
			'appDir' => defined('APP_DIR') ? APP_DIR : null,
			'hasGit' => self::which('git') !== null,
			'diskFree' => self::formatBytes(disk_free_space(Q_WebServer::$rootDir ?: '.')),
			'serverVersion' => defined('QBIX_SERVER_VERSION') ? 'QbixServer/' . QBIX_SERVER_VERSION : null,
			'sapi' => php_sapi_name(),
			'pid' => getmypid(),
			'uid' => function_exists('posix_getuid') ? posix_getuid() : null,
		);
	}

	/**
	 * Clone Qbix Platform from GitHub and set up local/paths.json
	 */
	static function apiInstallPlatform($parsed)
	{
		$body = json_decode($parsed['body'], true);
		$dir = $body['dir'] ?? '';
		if (!$dir) return array('status' => 400, 'error' => 'Directory required');

		// Safety: don't overwrite existing
		if (is_dir($dir) && file_exists($dir . DS . 'Q.php')) {
			return array('error' => 'Platform already exists at ' . $dir);
		}

		// Check git
		if (!self::which('git')) {
			return array('error' => 'git not found. Install git first.');
		}

		// Clone
		$parentDir = dirname($dir);
		if (!is_dir($parentDir)) {
			@mkdir($parentDir, 0755, true);
		}
		$dirName = basename($dir);
		$cmd = 'cd ' . escapeshellarg($parentDir)
			. ' && git clone https://github.com/Qbix/Platform.git '
			. escapeshellarg($dirName) . ' 2>&1'
			. ' && cd ' . escapeshellarg($dirName)
			. ' && git submodule init 2>&1'
			. ' && git submodule update --recursive 2>&1';
		$output = shell_exec($cmd);

		if (!is_dir($dir)) {
			return array('error' => 'Clone failed', 'output' => $output);
		}

		// Set up local/paths.json pointing to the platform
		if (defined('APP_DIR')) {
			$localDir = APP_DIR . DS . 'local';
			if (!is_dir($localDir)) @mkdir($localDir, 0755, true);
			$pathsFile = $localDir . DS . 'paths.json';
			$platformPath = realpath($dir) ?: $dir;
			// Use the platform subdirectory if it exists (Qbix convention)
			if (is_dir($platformPath . DS . 'platform')) {
				$platformPath = $platformPath . DS . 'platform';
			}
			$paths = file_exists($pathsFile)
				? json_decode(file_get_contents($pathsFile), true) : array();
			$paths['platform'] = $platformPath;
			file_put_contents($pathsFile, json_encode($paths, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		}

		return array(
			'ok' => true,
			'dir' => realpath($dir),
			'output' => $output,
		);
	}

	static function apiServeApp($parsed)
	{
		$body = json_decode($parsed['body'], true);
		$appName = preg_replace('/[^A-Za-z0-9_]/', '', $body['app'] ?? '');
		$enable = !empty($body['enable']);

		if (!$appName) return array('status' => 400, 'error' => 'App name required');

		$appsDir = self::appsDir();
		$appDir = $appsDir . DS . $appName;
		$webDir = $appDir . DS . 'web';

		if ($enable) {
			if (!is_dir($webDir)) {
				return array('status' => 404, 'error' => "No web/ directory in $appName");
			}
			$root = realpath($webDir);
			if (!$root) return array('status' => 500, 'error' => 'Cannot resolve path');
			Q_WebServer::$rootDir = rtrim(str_replace(array('/', '\\'), DS, $root), DS) . DS;
			self::$servingApp = $appName;
			return array('ok' => true, 'serving' => $appName, 'rootDir' => Q_WebServer::$rootDir);
		} else {
			if (defined('APP_DIR')) {
				$orig = APP_DIR . DS . 'web';
				if (is_dir($orig)) {
					Q_WebServer::$rootDir = rtrim(str_replace(array('/', '\\'), DS, realpath($orig)), DS) . DS;
				}
			}
			self::$servingApp = null;
			return array('ok' => true, 'serving' => null, 'rootDir' => Q_WebServer::$rootDir);
		}
	}

	/**
	 * Change the apps directory. Persisted in panel config.
	 */
	static function apiSetAppsDir($parsed)
	{
		$body = json_decode($parsed['body'], true);
		$dir = $body['dir'] ?? '';
		if (!$dir || !is_dir($dir)) {
			return array('status' => 400, 'error' => 'Directory does not exist: ' . $dir);
		}
		// Persist in config
		Q_Config::set('Q', 'webserver', 'panel', 'appsDir', realpath($dir));
		// Also save to panel config file
		$configPath = self::panelConfigPath();
		$config = file_exists($configPath) ? json_decode(file_get_contents($configPath), true) : array();
		$config['appsDir'] = realpath($dir);
		$d = dirname($configPath);
		if (!is_dir($d)) @mkdir($d, 0700, true);
		@file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT));
		return array('ok' => true, 'appsDir' => realpath($dir));
	}

	static function apiOpenFolder($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$dir = $body['dir'] ?? '';
		$editor = $body['editor'] ?? 'folder'; // folder, vscode, textmate

		if (!$dir || !is_dir($dir)) {
			return array('status' => 400, 'error' => 'Invalid directory');
		}

		$os = PHP_OS_FAMILY;
		switch ($editor) {
			case 'vscode':
				$cmd = 'code ' . escapeshellarg($dir);
				break;
			case 'textmate':
				$cmd = 'mate ' . escapeshellarg($dir);
				break;
			default: // open in file manager
				if ($os === 'Darwin') {
					$cmd = 'open ' . escapeshellarg($dir);
				} elseif ($os === 'Windows') {
					$cmd = 'explorer ' . escapeshellarg(str_replace('/', '\\', $dir));
				} else {
					$cmd = 'xdg-open ' . escapeshellarg($dir);
				}
		}

		exec($cmd . ' 2>&1 &');
		return array('opened' => $dir, 'editor' => $editor);
	}

	// ── Framework Package Management ─────────────────────

	/**
	 * Get packages/plugins for a detected framework.
	 * Reads composer.lock, wp plugin dirs, etc.
	 */
	static function apiFrameworkPackages($parsed)
	{
		$query = [];
		if (!empty($parsed['query'])) parse_str($parsed['query'], $query);
		$body = json_decode($parsed['body'] ?? '{}', true);
		$framework = $body['framework'] ?? $query['framework'] ?? '';

		$rootDir = Q_WebServer::$rootDir;
		$projectDir = dirname(rtrim($rootDir, DIRECTORY_SEPARATOR));
		$packages = [];

		switch ($framework) {

		case 'laravel':
		case 'symfony':
			// Read composer.lock for installed packages
			$lockFile = $projectDir . '/composer.lock';
			$jsonFile = $projectDir . '/composer.json';

			$required = [];
			if (is_file($jsonFile)) {
				$cj = json_decode(file_get_contents($jsonFile), true);
				foreach (($cj['require'] ?? []) as $pkg => $ver) {
					$required[$pkg] = $ver;
				}
				foreach (($cj['require-dev'] ?? []) as $pkg => $ver) {
					$required[$pkg] = $ver . ' (dev)';
				}
			}

			if (is_file($lockFile)) {
				$lock = json_decode(file_get_contents($lockFile), true);
				foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $pkg) {
					$name = $pkg['name'] ?? '';
					$isDev = in_array($pkg, $lock['packages-dev'] ?? []);
					$packages[] = [
						'name' => $name,
						'version' => $pkg['version'] ?? '',
						'description' => $pkg['description'] ?? '',
						'type' => $pkg['type'] ?? 'library',
						'constraint' => $required[$name] ?? null,
						'dev' => $isDev,
						'homepage' => $pkg['homepage'] ?? null,
					];
				}
			} elseif (!empty($required)) {
				// No lock file, show requirements
				foreach ($required as $pkg => $ver) {
					$packages[] = [
						'name' => $pkg,
						'version' => null,
						'constraint' => $ver,
						'description' => '(not installed — run composer install)',
					];
				}
			}
			return ['framework' => $framework, 'packages' => $packages, 'source' => is_file($lockFile) ? 'composer.lock' : 'composer.json'];

		case 'wordpress':
			// Try wp-cli first
			$wpDir = is_file($rootDir . 'wp-config.php') ? rtrim($rootDir, '/') : $projectDir;
			$wpCli = null;
			foreach (['wp', $projectDir . '/vendor/bin/wp'] as $p) {
				if (self::which($p)) {
					$wpCli = $p; break;
				}
			}

			if ($wpCli) {
				// wp-cli gives structured JSON
				$pluginJson = shell_exec("cd " . escapeshellarg($wpDir) . " && $wpCli plugin list --format=json 2>/dev/null");
				$plugins = json_decode($pluginJson ?: '[]', true) ?: [];
				foreach ($plugins as $p) {
					$packages[] = [
						'name' => $p['name'] ?? '',
						'version' => $p['version'] ?? '',
						'status' => $p['status'] ?? '',
						'update' => $p['update'] ?? 'none',
						'type' => 'plugin',
					];
				}

				$themeJson = shell_exec("cd " . escapeshellarg($wpDir) . " && $wpCli theme list --format=json 2>/dev/null");
				$themes = json_decode($themeJson ?: '[]', true) ?: [];
				foreach ($themes as $t) {
					$packages[] = [
						'name' => $t['name'] ?? '',
						'version' => $t['version'] ?? '',
						'status' => $t['status'] ?? '',
						'update' => $t['update'] ?? 'none',
						'type' => 'theme',
					];
				}
				return ['framework' => 'wordpress', 'packages' => $packages, 'source' => 'wp-cli'];
			}

			// Fallback: scan wp-content/plugins/ directory
			$pluginsDir = $wpDir . '/wp-content/plugins';
			if (is_dir($pluginsDir)) {
				foreach (scandir($pluginsDir) as $d) {
					if ($d === '.' || $d === '..' || !is_dir($pluginsDir . '/' . $d)) continue;
					// Read plugin header from main PHP file
					$mainFile = $pluginsDir . '/' . $d . '/' . $d . '.php';
					if (!is_file($mainFile)) {
						// Try first .php file
						foreach (glob($pluginsDir . '/' . $d . '/*.php') as $f) {
							$mainFile = $f; break;
						}
					}
					$info = ['name' => $d, 'type' => 'plugin'];
					if (is_file($mainFile)) {
						$header = file_get_contents($mainFile, false, null, 0, 4096);
						if (preg_match('/Plugin Name:\s*(.+)/i', $header, $m)) $info['title'] = trim($m[1]);
						if (preg_match('/Version:\s*(.+)/i', $header, $m)) $info['version'] = trim($m[1]);
						if (preg_match('/Description:\s*(.+)/i', $header, $m)) $info['description'] = trim($m[1]);
					}
					$packages[] = $info;
				}
			}

			// Scan themes too
			$themesDir = $wpDir . '/wp-content/themes';
			if (is_dir($themesDir)) {
				foreach (scandir($themesDir) as $d) {
					if ($d === '.' || $d === '..' || !is_dir($themesDir . '/' . $d)) continue;
					$styleFile = $themesDir . '/' . $d . '/style.css';
					$info = ['name' => $d, 'type' => 'theme'];
					if (is_file($styleFile)) {
						$header = file_get_contents($styleFile, false, null, 0, 2048);
						if (preg_match('/Theme Name:\s*(.+)/i', $header, $m)) $info['title'] = trim($m[1]);
						if (preg_match('/Version:\s*(.+)/i', $header, $m)) $info['version'] = trim($m[1]);
					}
					$packages[] = $info;
				}
			}
			return ['framework' => 'wordpress', 'packages' => $packages, 'source' => 'filesystem'];

		case 'drupal':
			$drush = null;
			foreach (['drush', $projectDir . '/vendor/bin/drush'] as $p) {
				if (self::which($p)) {
					$drush = $p; break;
				}
			}
			if ($drush) {
				$moduleJson = shell_exec("cd " . escapeshellarg($projectDir) . " && $drush pm:list --format=json 2>/dev/null");
				$modules = json_decode($moduleJson ?: '{}', true) ?: [];
				foreach ($modules as $name => $info) {
					$packages[] = [
						'name' => $name,
						'version' => $info['version'] ?? '',
						'status' => $info['status'] ?? '',
						'type' => $info['type'] ?? 'module',
						'description' => $info['display_name'] ?? $name,
					];
				}
				return ['framework' => 'drupal', 'packages' => $packages, 'source' => 'drush'];
			}
			// Fallback to composer
			return self::apiFrameworkPackages(array_merge($parsed, ['body' => json_encode(['framework' => 'symfony'])]));

		case 'joomla':
			// Read administrator/cache or manifest files
			$extDir = rtrim($rootDir, '/') . '/administrator/manifests/packages';
			if (is_dir($extDir)) {
				foreach (glob($extDir . '/*.xml') as $xml) {
					$info = ['name' => basename($xml, '.xml'), 'type' => 'package'];
					$content = file_get_contents($xml, false, null, 0, 4096);
					if (preg_match('/<version>(.+?)<\/version>/i', $content, $m)) $info['version'] = $m[1];
					if (preg_match('/<name>(.+?)<\/name>/i', $content, $m)) $info['title'] = $m[1];
					$packages[] = $info;
				}
			}
			return ['framework' => 'joomla', 'packages' => $packages, 'source' => 'manifests'];

		default:
			return ['status' => 400, 'error' => 'Unknown framework'];
		}
	}

	/**
	 * Run a composer command (require, update, remove).
	 */
	static function apiFrameworkComposer($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$action = $body['action'] ?? '';
		$package = $body['package'] ?? '';

		$rootDir = Q_WebServer::$rootDir;
		$projectDir = dirname(rtrim($rootDir, DIRECTORY_SEPARATOR));

		if (!is_file($projectDir . '/composer.json')) {
			return ['status' => 400, 'error' => 'No composer.json found'];
		}

		$allowed = ['update', 'install', 'dump-autoload'];
		if ($package && in_array($action, ['require', 'remove', 'update'])) {
			// Validate package name (vendor/package format)
			if (!preg_match('#^[a-z0-9]([a-z0-9._-]*/)?[a-z0-9][a-z0-9._-]*$#i', $package)) {
				return ['status' => 400, 'error' => 'Invalid package name'];
			}
			$cmd = "cd " . escapeshellarg($projectDir) . " && composer $action " . escapeshellarg($package) . " --no-interaction 2>&1";
		} elseif (in_array($action, $allowed)) {
			$cmd = "cd " . escapeshellarg($projectDir) . " && composer $action --no-interaction 2>&1";
		} else {
			return ['status' => 400, 'error' => 'Invalid action'];
		}

		$output = shell_exec($cmd);
		return ['output' => $output, 'cmd' => "composer $action" . ($package ? " $package" : '')];
	}

	/**
	 * Unified package management: install, remove, enable, disable, update.
	 * Each framework maps these to its own CLI tool.
	 */
	static function apiFrameworkPkgAction($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$framework = $body['framework'] ?? '';
		$action = $body['action'] ?? '';
		$package = $body['package'] ?? '';

		if (!$framework || !$action || !$package) {
			return ['status' => 400, 'error' => 'Missing framework, action, or package'];
		}

		// Validate package name to prevent injection
		if (!preg_match('#^[a-zA-Z0-9/_.:@^~>=<*-]+$#', $package)) {
			return ['status' => 400, 'error' => 'Invalid package name'];
		}

		$rootDir = Q_WebServer::$rootDir;
		$projectDir = dirname(rtrim($rootDir, DIRECTORY_SEPARATOR));
		$cmd = null;
		$cwd = $projectDir;

		switch ($framework) {

		case 'laravel':
		case 'symfony':
			// Composer-based
			switch ($action) {
				case 'install':
				case 'require':
					$cmd = "composer require " . escapeshellarg($package) . " --no-interaction"; break;
				case 'remove':
					$cmd = "composer remove " . escapeshellarg($package) . " --no-interaction"; break;
				case 'update':
					$cmd = "composer update " . escapeshellarg($package) . " --no-interaction"; break;
				default:
					return ['status' => 400, 'error' => "Unknown action '$action' for $framework"];
			}
			break;

		case 'wordpress':
			$wpDir = is_file($rootDir . 'wp-config.php') ? rtrim($rootDir, '/') : $projectDir;
			$wpCli = null;
			foreach (['wp', $projectDir . '/vendor/bin/wp'] as $p) {
				if (self::which($p)) {
					$wpCli = $p; break;
				}
			}
			if (!$wpCli) {
				return ['status' => 400, 'error' => 'wp-cli not found. Install it: https://wp-cli.org/'];
			}
			$cwd = $wpDir;
			$pathFlag = ' --path=' . escapeshellarg($wpDir);

			// Determine if it's a theme or plugin from package name prefix
			$type = 'plugin';
			if (strpos($package, 'theme:') === 0) {
				$type = 'theme';
				$package = substr($package, 6);
			}

			switch ($action) {
				case 'install':
					$cmd = "$wpCli $type install " . escapeshellarg($package) . "$pathFlag"; break;
				case 'activate':
					$cmd = "$wpCli $type activate " . escapeshellarg($package) . "$pathFlag"; break;
				case 'deactivate':
					$cmd = "$wpCli $type deactivate " . escapeshellarg($package) . "$pathFlag"; break;
				case 'remove':
				case 'delete':
					$cmd = "$wpCli $type delete " . escapeshellarg($package) . "$pathFlag"; break;
				case 'update':
					$cmd = "$wpCli $type update " . escapeshellarg($package) . "$pathFlag"; break;
				default:
					return ['status' => 400, 'error' => "Unknown action '$action' for WordPress"];
			}
			break;

		case 'drupal':
			$drush = null;
			foreach (['drush', $projectDir . '/vendor/bin/drush'] as $p) {
				if (self::which($p)) {
					$drush = $p; break;
				}
			}

			switch ($action) {
				case 'install':
				case 'enable':
					if ($drush) {
						$cmd = "$drush pm:install " . escapeshellarg($package) . " -y";
					} else {
						$cmd = "composer require " . escapeshellarg("drupal/$package") . " --no-interaction";
					}
					break;
				case 'remove':
				case 'uninstall':
					if ($drush) {
						$cmd = "$drush pm:uninstall " . escapeshellarg($package) . " -y";
					} else {
						$cmd = "composer remove " . escapeshellarg("drupal/$package") . " --no-interaction";
					}
					break;
				case 'update':
					$cmd = "composer update " . escapeshellarg("drupal/$package") . " --no-interaction"; break;
				default:
					return ['status' => 400, 'error' => "Unknown action '$action' for Drupal"];
			}
			break;

		case 'joomla':
			switch ($action) {
				case 'install':
					$cmd = "php cli/joomla.php extension:install --package=" . escapeshellarg($package); break;
				case 'remove':
					$cmd = "php cli/joomla.php extension:remove " . escapeshellarg($package); break;
				default:
					return ['status' => 400, 'error' => "Unknown action '$action' for Joomla"];
			}
			$cwd = rtrim($rootDir, '/');
			break;

		default:
			return ['status' => 400, 'error' => "Unknown framework '$framework'"];
		}

		$fullCmd = "cd " . escapeshellarg($cwd) . " && $cmd 2>&1";
		$output = shell_exec($fullCmd);
		return ['output' => $output, 'cmd' => $cmd];
	}

	// ── Package Download (all frameworks) ────────────────

	/**
	 * Download a plugin/package from a URL (GitHub, zip, etc.)
	 * or install via composer/npm.
	 */
	static function apiFrameworkPkgDownload($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$framework = $body['framework'] ?? '';
		$source = trim($body['source'] ?? '');
		$target = $body['target'] ?? '';

		if (!$source) return ['status' => 400, 'error' => 'No source URL provided'];

		$rootDir = Q_WebServer::$rootDir;
		$projectDir = dirname(rtrim($rootDir, DIRECTORY_SEPARATOR));
		$output = '';
		$cmd = '';

		// Determine target directory based on framework
		switch ($framework) {
			case 'qbix':
				// Qbix plugins go into platform/plugins/
				$pluginsDir = null;
				foreach ([
					$projectDir . '/platform/plugins',
					dirname($projectDir) . '/platform/plugins',
				] as $pd) {
					if (is_dir($pd)) { $pluginsDir = $pd; break; }
				}
				if (!$pluginsDir) {
					return ['status' => 400, 'error' => 'Cannot find platform/plugins directory'];
				}
				$targetDir = $pluginsDir . '/' . ($target ?: basename($source, '.git'));
				break;
			case 'wordpress':
				$wpDir = is_file($rootDir . 'wp-config.php') ? rtrim($rootDir, '/') : $projectDir;
				$targetDir = $wpDir . '/wp-content/plugins/' . ($target ?: basename($source, '.git'));
				break;
			case 'drupal':
				$targetDir = $projectDir . '/web/modules/custom/' . ($target ?: basename($source, '.git'));
				if (!is_dir(dirname($targetDir))) {
					$targetDir = $projectDir . '/modules/custom/' . ($target ?: basename($source, '.git'));
				}
				break;
			case 'joomla':
				$targetDir = rtrim($rootDir, '/') . '/plugins/' . ($target ?: basename($source, '.git'));
				break;
			default:
				// Laravel/Symfony: use composer require instead of git clone
				if (preg_match('#^[a-z0-9]([a-z0-9._-]*/)[a-z0-9][a-z0-9._-]*$#i', $source)) {
					$cmd = "cd " . escapeshellarg($projectDir) . " && composer require " . escapeshellarg($source) . " --no-interaction 2>&1";
					$output = shell_exec($cmd);
					return ['output' => $output, 'cmd' => "composer require $source"];
				}
				$targetDir = $projectDir . '/plugins/' . ($target ?: basename($source, '.git'));
		}

		// If it's a GitHub URL or git URL, clone it
		if (preg_match('#^(https?://|git@)#', $source)) {
			if (is_dir($targetDir)) {
				// Already exists — try git pull
				$cmd = "cd " . escapeshellarg($targetDir) . " && git pull 2>&1";
			} else {
				$cmd = "git clone --depth 1 " . escapeshellarg($source) . " " . escapeshellarg($targetDir) . " 2>&1";
			}
			$output = shell_exec($cmd);

			// Check for package.json and composer.json in the downloaded plugin
			$extras = [];
			if (is_file($targetDir . '/package.json')) {
				$extras[] = 'Has package.json — run npm install from the panel';
			}
			if (is_file($targetDir . '/composer.json')) {
				$extras[] = 'Has composer.json — run composer install from the panel';
			}
			if (is_file($targetDir . '/config/plugin.json')) {
				$extras[] = 'Qbix plugin detected — run the installer to set up DB schema';
			}
			if ($extras) {
				$output .= "\n\n" . implode("\n", $extras);
			}

			return ['output' => $output, 'cmd' => $cmd, 'dir' => $targetDir];
		}

		// If it looks like a composer package name
		if (preg_match('#^[a-z0-9]([a-z0-9._-]*/)[a-z0-9][a-z0-9._-]*$#i', $source)) {
			$cmd = "cd " . escapeshellarg($projectDir) . " && composer require " . escapeshellarg($source) . " --no-interaction 2>&1";
			$output = shell_exec($cmd);
			return ['output' => $output, 'cmd' => "composer require $source"];
		}

		return ['status' => 400, 'error' => 'Source must be a git URL (https:// or git@) or a composer package name (vendor/package)'];
	}

	// ── Qbix Installer & NPM ────────────────────────────

	/**
	 * Run the Qbix installer (install.php) with various flags.
	 */
	static function apiQbixInstaller($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$action = $body['action'] ?? '';
		$plugin = $body['plugin'] ?? '';

		$rootDir = Q_WebServer::$rootDir;
		$projectDir = dirname(rtrim($rootDir, DIRECTORY_SEPARATOR));

		// Find install.php
		$installScript = null;
		foreach ([
			$projectDir . '/scripts/Q/install.php',
			dirname($projectDir) . '/scripts/Q/install.php',
		] as $p) {
			if (is_file($p)) { $installScript = $p; break; }
		}

		if (!$installScript) {
			return ['status' => 400, 'error' => 'Cannot find scripts/Q/install.php. Is this a Qbix app?'];
		}

		$appDir = dirname(dirname($installScript));
		$allowed = ['--all', '--plugins', '--app', '--composer', '--npm'];

		switch ($action) {
			case 'all':
				$flags = '--all';
				break;
			case 'plugins':
				$flags = '--plugins';
				break;
			case 'app':
				$flags = '--app';
				break;
			case 'plugin':
				if (!$plugin || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $plugin)) {
					return ['status' => 400, 'error' => 'Invalid plugin name'];
				}
				$flags = '-p ' . escapeshellarg($plugin);
				break;
			case 'composer':
				$flags = '--composer';
				break;
			case 'npm':
				$flags = '--npm';
				break;
			case 'plugin-full':
				// Install a single plugin with its SQL + composer + npm
				if (!$plugin || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $plugin)) {
					return ['status' => 400, 'error' => 'Invalid plugin name'];
				}
				$flags = '-p ' . escapeshellarg($plugin) . ' --composer --npm';
				break;
			default:
				return ['status' => 400, 'error' => "Unknown action: $action. Use: all, plugins, app, plugin, composer, npm, plugin-full"];
		}

		$cmd = "cd " . escapeshellarg($appDir) . " && php " . escapeshellarg($installScript) . " $flags 2>&1";
		$output = shell_exec($cmd);
		return ['output' => $output, 'cmd' => "php scripts/Q/install.php $flags"];
	}

	/**
	 * Run npm commands for Qbix plugins or the app.
	 */
	static function apiQbixNpm($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$action = $body['action'] ?? 'install';
		$target = $body['target'] ?? '';  // plugin name or 'app' or 'platform'

		$rootDir = Q_WebServer::$rootDir;
		$projectDir = dirname(rtrim($rootDir, DIRECTORY_SEPARATOR));

		// Determine directory
		$dir = null;
		if ($target === 'app' || $target === '') {
			$dir = $projectDir;
		} elseif ($target === 'platform') {
			foreach ([
				$projectDir . '/platform',
				dirname($projectDir) . '/platform',
			] as $pd) {
				if (is_dir($pd)) { $dir = $pd; break; }
			}
		} else {
			// Plugin name
			foreach ([
				$projectDir . '/platform/plugins/' . $target,
				dirname($projectDir) . '/platform/plugins/' . $target,
			] as $pd) {
				if (is_dir($pd)) { $dir = $pd; break; }
			}
		}

		if (!$dir || !is_dir($dir)) {
			return ['status' => 400, 'error' => "Directory not found for target: $target"];
		}

		if (!is_file($dir . '/package.json')) {
			return ['status' => 400, 'error' => "No package.json in $dir"];
		}

		$hasNpm = (bool) self::which('npm');
		if (!$hasNpm) {
			return ['status' => 400, 'error' => 'npm is not installed on this system'];
		}

		$allowed = ['install', 'update', 'audit', 'ls'];
		if (!in_array($action, $allowed)) {
			return ['status' => 400, 'error' => "Invalid npm action: $action"];
		}

		$cmd = "cd " . escapeshellarg($dir) . " && npm $action --ignore-scripts 2>&1";
		$output = shell_exec($cmd);
		return ['output' => $output, 'cmd' => "npm $action", 'dir' => $dir];
	}

	// ── Qbix Plugin Management API ──────────────────────

	/**
	 * Parse a Qbix JSON file (tolerant of comments and trailing commas).
	 */
	private static function parseQbixJson($path)
	{
		if (!is_file($path)) return null;
		$raw = file_get_contents($path);
		// Remove block comments
		$raw = preg_replace('#/\*.*?\*/#s', '', $raw);
		// Remove line comments (outside strings)
		$lines = explode("\n", $raw);
		$cleaned = [];
		foreach ($lines as $line) {
			$inStr = false;
			$out = '';
			for ($i = 0; $i < strlen($line); $i++) {
				$ch = $line[$i];
				if ($ch === '"' && ($i === 0 || $line[$i-1] !== '\\'))
					$inStr = !$inStr;
				if (!$inStr && $ch === '/' && $i+1 < strlen($line) && $line[$i+1] === '/')
					break;
				$out .= $ch;
			}
			$cleaned[] = $out;
		}
		$raw = implode("\n", $cleaned);
		// Remove trailing commas before ] or }
		$raw = preg_replace('/,\s*([\]\}])/', '$1', $raw);
		return json_decode($raw, true);
	}

	/**
	 * Scan all plugin sources and return a unified view.
	 */
	static function apiQbixPlugins()
	{
		$rootDir = Q_WebServer::$rootDir;
		$serverDir = Q_WebServer::$serverDir;
		$projectDir = dirname(rtrim($rootDir, DIRECTORY_SEPARATOR));

		// 1. Find the app config
		$appJson = null;
		$appDir = null;
		foreach ([
			$projectDir . '/config/app.json',
			$rootDir . '../config/app.json',
		] as $p) {
			if (is_file($p)) {
				$appJson = self::parseQbixJson($p);
				$appDir = dirname(dirname($p));
				break;
			}
		}

		$declaredPlugins = [];
		$appName = null;
		$appVersion = null;
		if ($appJson) {
			$declaredPlugins = $appJson['Q']['plugins'] ?? [];
			$appName = $appJson['Q']['app'] ?? basename($appDir);
			$appVersion = $appJson['Q']['appInfo']['version'] ?? null;
		}

		// 2. Find local/plugins.json (installed filesystem versions)
		$localPlugins = [];
		foreach ([
			$appDir . '/local/plugins.json',
			$projectDir . '/local/plugins.json',
		] as $p) {
			if (is_file($p)) {
				$lp = self::parseQbixJson($p);
				if ($lp) {
					$localPlugins = $lp['Q']['pluginLocal'] ?? $lp;
				}
				break;
			}
		}

		// 3. Scan platform/plugins/ for available plugins
		$available = [];
		$platformDirs = [
			$projectDir . '/platform/plugins',
			$appDir . '/../platform/plugins',
			dirname($serverDir) . '/Platform/platform/plugins',
		];
		$pluginsDir = null;
		foreach ($platformDirs as $pd) {
			if (is_dir($pd)) {
				$pluginsDir = realpath($pd);
				break;
			}
		}

		if ($pluginsDir) {
			foreach (scandir($pluginsDir) as $pName) {
				if ($pName === '.' || $pName === '..') continue;
				$pDir = $pluginsDir . '/' . $pName;
				if (!is_dir($pDir)) continue;
				$pJson = self::parseQbixJson($pDir . '/config/plugin.json');
				if ($pJson) {
					$pi = $pJson['Q']['pluginInfo'][$pName] ?? [];
					$available[$pName] = [
						'version' => $pi['version'] ?? null,
						'compatible' => $pi['compatible'] ?? null,
						'requires' => $pi['requires'] ?? [],
						'connections' => $pi['connections'] ?? [],
						'dir' => $pDir,
					];
				} else {
					// Directory exists but no parseable plugin.json
					$available[$pName] = [
						'version' => null,
						'dir' => $pDir,
						'noConfig' => true,
					];
				}
			}
		}

		// 4. Check database for schema versions
		$dbPlugins = [];
		$dbError = null;
		try {
			// Try to find SQLite databases in the app
			$dbPaths = [];
			foreach ([
				$appDir . '/local',
				$projectDir . '/local',
				$projectDir . '/data',
				$rootDir . '../data',
			] as $dir) {
				if (!is_dir($dir)) continue;
				foreach (glob($dir . '/*.db') as $dbFile) {
					$dbPaths[] = $dbFile;
				}
				foreach (glob($dir . '/*.sqlite') as $dbFile) {
					$dbPaths[] = $dbFile;
				}
			}

			foreach ($dbPaths as $dbPath) {
				try {
					$pdo = new \PDO('sqlite:' . $dbPath);
					$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

					// Check for Q_plugin table (with any prefix)
					$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '%Q_plugin'")->fetchAll(\PDO::FETCH_COLUMN);
					foreach ($tables as $table) {
						$rows = $pdo->query("SELECT * FROM \"$table\"")->fetchAll(\PDO::FETCH_ASSOC);
						foreach ($rows as $row) {
							$name = $row['plugin'] ?? null;
							if (!$name) continue;
							$dbPlugins[$name] = [
								'schemaVersion' => $row['version'] ?? null,
								'schemaPHPVersion' => $row['versionPHP'] ?? null,
								'extra' => json_decode($row['extra'] ?? '{}', true),
								'db' => basename($dbPath),
								'table' => $table,
							];
						}
					}
				} catch (\Exception $e) {
					// Skip this database
				}
			}
		} catch (\Exception $e) {
			$dbError = $e->getMessage();
		}

		// 5. Build unified plugin list
		$plugins = [];
		$allNames = array_unique(array_merge(
			$declaredPlugins,
			array_keys($localPlugins),
			array_keys($available),
			array_keys($dbPlugins)
		));
		sort($allNames);

		foreach ($allNames as $name) {
			$entry = [
				'name' => $name,
				'declared' => in_array($name, $declaredPlugins),
				'availableVersion' => $available[$name]['version'] ?? null,
				'installedVersion' => $localPlugins[$name]['version'] ?? null,
				'schemaVersion' => $dbPlugins[$name]['schemaVersion'] ?? null,
				'schemaPHPVersion' => $dbPlugins[$name]['schemaPHPVersion'] ?? null,
				'extra' => $dbPlugins[$name]['extra'] ?? null,
				'db' => $dbPlugins[$name]['db'] ?? null,
				'requires' => $available[$name]['requires'] ?? [],
				'connections' => $available[$name]['connections'] ?? [],
				'hasDir' => isset($available[$name]['dir']),
				'hasPackageJson' => $available[$name]['hasPackageJson'] ?? false,
				'hasComposerJson' => $available[$name]['hasComposerJson'] ?? false,
				'hasNodeModules' => $available[$name]['hasNodeModules'] ?? false,
				'hasVendor' => $available[$name]['hasVendor'] ?? false,
			];
			// Status
			if (!$entry['availableVersion'] && !$entry['hasDir']) {
				$entry['status'] = 'missing';  // declared but not on filesystem
			} elseif (!$entry['installedVersion']) {
				$entry['status'] = 'available';  // on filesystem, not installed
			} elseif ($entry['availableVersion']
				&& version_compare($entry['installedVersion'], $entry['availableVersion'], '<')) {
				$entry['status'] = 'upgradable';
			} else {
				$entry['status'] = 'installed';
			}
			// Schema status
			if ($entry['schemaVersion'] && $entry['availableVersion']
				&& version_compare($entry['schemaVersion'], $entry['availableVersion'], '<')) {
				$entry['schemaStatus'] = 'outdated';
			} elseif ($entry['schemaVersion']) {
				$entry['schemaStatus'] = 'current';
			} else {
				$entry['schemaStatus'] = 'none';
			}
			$plugins[] = $entry;
		}

		return [
			'app' => $appName,
			'appVersion' => $appVersion,
			'pluginsDir' => $pluginsDir,
			'plugins' => $plugins,
			'dbError' => $dbError,
		];
	}

	static function apiQbixPluginInstall($parsed)
	{
		// Placeholder — full install requires Q_Plugin::installPlugin()
		$body = json_decode($parsed['body'] ?? '{}', true);
		$name = $body['plugin'] ?? '';
		if (!$name) return ['status' => 400, 'error' => 'Missing plugin name'];
		return ['status' => 501, 'error' => 'Plugin installation from the panel requires the Qbix Platform. Use: php scripts/Q/install.php --plugin=' . $name];
	}

	static function apiQbixPluginSchema($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$name = $body['plugin'] ?? '';
		if (!$name) return ['status' => 400, 'error' => 'Missing plugin name'];

		// Find the plugin's SQL scripts
		$result = self::apiQbixPlugins();
		$scripts = [];
		foreach ($result['plugins'] as $p) {
			if ($p['name'] === $name && $p['hasDir']) {
				$pluginsDir = $result['pluginsDir'];
				$scriptsDir = $pluginsDir . '/' . $name . '/scripts/' . $name;
				if (is_dir($scriptsDir)) {
					foreach (scandir($scriptsDir) as $f) {
						if ($f === '.' || $f === '..') continue;
						$scripts[] = $f;
					}
					sort($scripts);
				}
				break;
			}
		}

		return [
			'plugin' => $name,
			'scripts' => $scripts,
			'schemaVersion' => $result['plugins'][array_search($name, array_column($result['plugins'], 'name'))]['schemaVersion'] ?? null,
		];
	}

	// ── Frameworks API ───────────────────────────────────

	static function apiFrameworks()
	{
		$rootDir = Q_WebServer::$rootDir;
		$projectDir = dirname(rtrim($rootDir, DIRECTORY_SEPARATOR));
		$detected = [];

		// Laravel: artisan file at project root, public/ as web root
		$artisan = $projectDir . '/artisan';
		if (is_file($artisan)) {
			$version = '';
			$envFile = $projectDir . '/.env';
			$env = is_file($envFile) ? parse_ini_file($envFile) : [];
			$detected[] = [
				'framework' => 'laravel',
				'name' => 'Laravel',
				'dir' => $projectDir,
				'webRoot' => $projectDir . '/public',
				'appName' => $env['APP_NAME'] ?? basename($projectDir),
				'appEnv' => $env['APP_ENV'] ?? 'unknown',
				'debug' => ($env['APP_DEBUG'] ?? 'false') === 'true',
				'commands' => [
					['name' => 'Clear cache', 'cmd' => 'cache:clear'],
					['name' => 'Clear config', 'cmd' => 'config:clear'],
					['name' => 'Clear routes', 'cmd' => 'route:clear'],
					['name' => 'Clear views', 'cmd' => 'view:clear'],
					['name' => 'Migrate', 'cmd' => 'migrate --force'],
					['name' => 'Migrate status', 'cmd' => 'migrate:status'],
					['name' => 'Route list', 'cmd' => 'route:list --compact'],
					['name' => 'Queue restart', 'cmd' => 'queue:restart'],
					['name' => 'Storage link', 'cmd' => 'storage:link'],
					['name' => 'Optimize', 'cmd' => 'optimize'],
				],
			];
		}

		// Symfony: bin/console at project root
		$console = $projectDir . '/bin/console';
		if (is_file($console)) {
			$detected[] = [
				'framework' => 'symfony',
				'name' => 'Symfony',
				'dir' => $projectDir,
				'webRoot' => $projectDir . '/public',
				'commands' => [
					['name' => 'Clear cache', 'cmd' => 'cache:clear'],
					['name' => 'Cache warmup', 'cmd' => 'cache:warmup'],
					['name' => 'Route list', 'cmd' => 'debug:router --no-interaction'],
					['name' => 'Container', 'cmd' => 'debug:container --no-interaction'],
					['name' => 'Migrate', 'cmd' => 'doctrine:migrations:migrate --no-interaction'],
					['name' => 'Migration status', 'cmd' => 'doctrine:migrations:status'],
					['name' => 'Assets install', 'cmd' => 'assets:install'],
				],
			];
		}

		// WordPress: wp-config.php in web root or project root
		$wpConfig = is_file($rootDir . 'wp-config.php') ? $rootDir : null;
		if (!$wpConfig && is_file($projectDir . '/wp-config.php')) $wpConfig = $projectDir . '/';
		if ($wpConfig) {
			$wpCli = null;
			foreach (['wp', $projectDir . '/vendor/bin/wp'] as $p) {
				if (is_executable($p) || self::which($p)) {
					$wpCli = $p; break;
				}
			}
			$detected[] = [
				'framework' => 'wordpress',
				'name' => 'WordPress',
				'dir' => rtrim($wpConfig, '/'),
				'webRoot' => $wpConfig,
				'hasCli' => (bool) $wpCli,
				'cliPath' => $wpCli,
				'commands' => $wpCli ? [
					['name' => 'Plugin list', 'cmd' => 'plugin list'],
					['name' => 'Theme list', 'cmd' => 'theme list'],
					['name' => 'Core version', 'cmd' => 'core version --extra'],
					['name' => 'Cache flush', 'cmd' => 'cache flush'],
					['name' => 'Rewrite flush', 'cmd' => 'rewrite flush'],
					['name' => 'DB check', 'cmd' => 'db check'],
					['name' => 'Cron list', 'cmd' => 'cron event list'],
					['name' => 'User list', 'cmd' => 'user list --fields=ID,user_login,user_email,roles'],
				] : [],
			];
		}

		// Drupal: drush or vendor/bin/drush
		$drush = null;
		foreach (['drush', $projectDir . '/vendor/bin/drush'] as $p) {
			if (is_executable($p) || self::which($p)) {
				$drush = $p; break;
			}
		}
		if ($drush || is_dir($projectDir . '/core/modules')) {
			$detected[] = [
				'framework' => 'drupal',
				'name' => 'Drupal',
				'dir' => $projectDir,
				'webRoot' => $projectDir . '/web',
				'hasCli' => (bool) $drush,
				'commands' => $drush ? [
					['name' => 'Cache rebuild', 'cmd' => 'cache:rebuild'],
					['name' => 'Status', 'cmd' => 'status'],
					['name' => 'Module list', 'cmd' => 'pm:list --status=enabled'],
					['name' => 'Update DB', 'cmd' => 'updatedb'],
					['name' => 'Cron run', 'cmd' => 'cron'],
				] : [],
			];
		}

		// Joomla: configuration.php in web root
		if (is_file($rootDir . 'configuration.php') && is_dir($rootDir . 'administrator')) {
			$detected[] = [
				'framework' => 'joomla',
				'name' => 'Joomla',
				'dir' => rtrim($rootDir, '/'),
				'webRoot' => $rootDir,
				'commands' => [
					['name' => 'Clear cache', 'cmd' => 'cache:clean'],
					['name' => 'Extension list', 'cmd' => 'extension:list'],
					['name' => 'Check updates', 'cmd' => 'update:extensions:check'],
					['name' => 'Site info', 'cmd' => 'site:info'],
				],
			];
		}

		return ['frameworks' => $detected];
	}

	static function apiFrameworkRun($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$framework = $body['framework'] ?? '';
		$cmd = $body['cmd'] ?? '';
		if (!$framework || !$cmd) return ['status' => 400, 'error' => 'Missing framework or cmd'];

		// Detect the CLI tool
		$rootDir = Q_WebServer::$rootDir;
		$projectDir = dirname(rtrim($rootDir, DIRECTORY_SEPARATOR));
		$cli = '';
		$cwd = $projectDir;
		switch ($framework) {
			case 'laravel':
				$cli = 'php artisan';
				break;
			case 'symfony':
				$cli = 'php bin/console';
				break;
			case 'wordpress':
				$cli = self::which('wp') ? 'wp' : $projectDir . '/vendor/bin/wp';
				$cwd = is_file($rootDir . 'wp-config.php') ? rtrim($rootDir, '/') : $projectDir;
				$cli .= ' --path=' . escapeshellarg($cwd);
				break;
			case 'drupal':
				$cli = self::which('drush') ? 'drush' : $projectDir . '/vendor/bin/drush';
				break;
			case 'joomla':
				$cli = 'php cli/joomla.php';
				break;
			default:
				return ['status' => 400, 'error' => 'Unknown framework'];
		}

		// Whitelist check: only allow commands from the detected list
		$allowed = false;
		$fwData = self::apiFrameworks();
		foreach ($fwData['frameworks'] as $fw) {
			if ($fw['framework'] === $framework) {
				foreach ($fw['commands'] as $c) {
					if ($c['cmd'] === $cmd) { $allowed = true; break 2; }
				}
			}
		}
		if (!$allowed) return ['status' => 403, 'error' => 'Command not in allowed list'];

		$fullCmd = "cd " . escapeshellarg($cwd) . " && " . $cli . " " . $cmd . " 2>&1";
		$output = shell_exec($fullCmd);
		return ['output' => $output, 'cmd' => $cli . ' ' . $cmd];
	}

	// ── Helpers ──────────────────────────────────────────

	// ── Domains API ──────────────────────────────────────

	static function apiListDomains()
	{
		$domains = Q_Config::get('Q', 'webserver', 'domains', array());
		// Merge domains from panel config (added via UI)
		$configPath = self::panelConfigPath();
		if (file_exists($configPath)) {
			$panelConfig = json_decode(file_get_contents($configPath), true);
			if (!empty($panelConfig['domains'])) {
				$domains = array_merge($domains, $panelConfig['domains']);
			}
		}
		$certDir = Q_Config::get('Q', 'webserver', 'tls', 'certDir', 'local/certs');
		$result = [];
		foreach ($domains as $name => $conf) {
			$certPath = rtrim($certDir, '/') . '/' . $name . '/fullchain.pem';
			$entry = [
				'domain' => $name,
				'root' => $conf['root'] ?? null,
				'app' => $conf['app'] ?? null,
				'tls' => $conf['tls'] ?? 'none',
				'aliases' => $conf['aliases'] ?? [],
			];
			if (is_file($certPath)) {
				$expiry = Q_WebServer_Acme::certExpiry($certPath);
				$entry['certExpires'] = $expiry ? date('Y-m-d', $expiry) : null;
				$entry['certDaysLeft'] = $expiry ? max(0, (int) (($expiry - time()) / 86400)) : null;
				$entry['certDomains'] = Q_WebServer_Acme::certDomains($certPath);
				$entry['certStatus'] = ($expiry && $expiry > time())
					? ($expiry - time() < 30 * 86400 ? 'expiring' : 'valid')
					: 'expired';
			} else {
				$entry['certStatus'] = 'none';
			}
			$result[] = $entry;
		}
		// Also check for certs without config entries
		if (is_dir($certDir)) {
			foreach (scandir($certDir) as $d) {
				if ($d === '.' || $d === '..' || $d === 'account.pem') continue;
				if (!is_dir($certDir . '/' . $d)) continue;
				if (isset($domains[$d])) continue; // already listed
				$certPath = $certDir . '/' . $d . '/fullchain.pem';
				if (!is_file($certPath)) continue;
				$expiry = Q_WebServer_Acme::certExpiry($certPath);
				$result[] = [
					'domain' => $d,
					'tls' => 'manual',
					'certExpires' => $expiry ? date('Y-m-d', $expiry) : null,
					'certDaysLeft' => $expiry ? max(0, (int) (($expiry - time()) / 86400)) : null,
					'certStatus' => ($expiry && $expiry > time()) ? 'valid' : 'expired',
					'certDomains' => Q_WebServer_Acme::certDomains($certPath),
					'unconfigured' => true,
				];
			}
		}
		return ['domains' => $result];
	}

	static function apiAddDomain($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$domain = $body['domain'] ?? '';
		// One validator, shared with the autohost path. The copy that stood
		// here accepted a label ending in a hyphen, and a name ending in a
		// newline, and the domain goes on to be written into a config file.
		if (!$domain || !Q_WebServer_Autohost::validateHostname($domain)) {
			return ['status' => 400, 'error' => 'Invalid domain name'];
		}
		$configPath = self::panelConfigPath();
		$config = file_exists($configPath)
			? json_decode(file_get_contents($configPath), true) : [];
		$config['domains'][$domain] = [
			'root' => $body['root'] ?? null,
			'app' => $body['app'] ?? null,
			'tls' => $body['tls'] ?? 'auto',
			'aliases' => $body['aliases'] ?? [],
		];
		file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT));
		return ['added' => $domain];
	}

	static function apiRemoveDomain($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$domain = $body['domain'] ?? '';
		$configPath = self::panelConfigPath();
		$config = file_exists($configPath)
			? json_decode(file_get_contents($configPath), true) : [];
		unset($config['domains'][$domain]);
		file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT));
		return ['removed' => $domain];
	}

	static function apiProvisionCert($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$domain = $body['domain'] ?? '';
		if (!$domain) return ['status' => 400, 'error' => 'Missing domain'];

		$email = Q_Config::get('Q', 'webserver', 'tls', 'acmeEmail', '');
		if (!$email) return ['status' => 400, 'error' => 'Set Q.webserver.tls.acmeEmail first'];

		$certDir = Q_Config::get('Q', 'webserver', 'tls', 'certDir', 'local/certs');
		$staging = (bool) Q_Config::get('Q', 'webserver', 'tls', 'acmeStaging', false);

		$domains = [$domain];
		$conf = Q_Config::get('Q', 'webserver', 'domains', $domain, []);
		if (!empty($conf['aliases'])) $domains = array_merge($domains, $conf['aliases']);

		$result = Q_WebServer_Acme::provision($domains, $certDir, $email, $staging);
		return $result;
	}

	// ── Hosts File API ──────────────────────────────────

	/**
	 * Get the system hosts file path for the current OS.
	 */
	static function hostsFilePath()
	{
		return PHP_OS_FAMILY === 'Windows'
			? 'C:\\Windows\\System32\\drivers\\etc\\hosts'
			: '/etc/hosts';
	}

	/**
	 * Read and parse the system hosts file.
	 * Returns entries as [{ip, hostname, line}] and the raw content.
	 */
	static function apiHostsFile()
	{
		$path = self::hostsFilePath();
		if (!is_readable($path)) {
			return ['error' => "Cannot read $path", 'entries' => [], 'writable' => false];
		}
		$raw = file_get_contents($path);
		$entries = [];
		foreach (explode("\n", $raw) as $i => $line) {
			$trimmed = trim($line);
			if ($trimmed === '' || $trimmed[0] === '#') continue;
			$parts = preg_split('/\s+/', $trimmed);
			if (count($parts) >= 2) {
				$ip = array_shift($parts);
				foreach ($parts as $host) {
					if ($host === '' || $host[0] === '#') break;
					$entries[] = ['ip' => $ip, 'hostname' => $host, 'line' => $i + 1];
				}
			}
		}

		// Cross-reference with configured domains
		$domains = Q_Config::get('Q', 'webserver', 'domains', array());
		$configPath = self::panelConfigPath();
		if (file_exists($configPath)) {
			$panelConfig = json_decode(file_get_contents($configPath), true);
			if (!empty($panelConfig['domains'])) {
				$domains = array_merge($domains, $panelConfig['domains']);
			}
		}

		$mapped = [];
		$hostsMap = [];
		foreach ($entries as $e) {
			$hostsMap[$e['hostname']] = $e['ip'];
		}
		foreach ($domains as $name => $conf) {
			$mapped[] = [
				'domain' => $name,
				'inHosts' => isset($hostsMap[$name]),
				'hostsIp' => $hostsMap[$name] ?? null,
			];
		}

		return [
			'entries' => $entries,
			'domains' => $mapped,
			'path' => $path,
			'writable' => is_writable($path),
			'needsElevation' => !is_writable($path),
		];
	}

	/**
	 * Add an entry to /etc/hosts. Returns a shell command for elevation
	 * if the server doesn't have write access (which is the normal case).
	 */
	static function apiHostsAdd($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$hostname = $body['hostname'] ?? '';
		$ip = $body['ip'] ?? '127.0.0.1';

		// A hosts entry may legitimately be a bare label, so the dot is not
		// required here -- everything else about the shape still is.
		if (!$hostname || !Q_WebServer_Autohost::validateHostname($hostname, false)) {
			return ['status' => 400, 'error' => 'Invalid hostname'];
		}
		if (!filter_var($ip, FILTER_VALIDATE_IP)) {
			return ['status' => 400, 'error' => 'Invalid IP'];
		}

		// Check if already in hosts
		$path = self::hostsFilePath();
		if (is_readable($path)) {
			$existing = file_get_contents($path);
			if (preg_match('/^\s*' . preg_quote($ip, '/') . '\s+.*\b' . preg_quote($hostname, '/') . '\b/m', $existing)) {
				return ['already' => true, 'hostname' => $hostname, 'ip' => $ip];
			}
			// Check for conflicting entry (different IP, same hostname)
			if (preg_match('/^\s*(\S+)\s+.*\b' . preg_quote($hostname, '/') . '\b/m', $existing, $m)) {
				return [
					'conflict' => true,
					'hostname' => $hostname,
					'existingIp' => trim($m[1]),
					'requestedIp' => $ip,
				];
			}
		}

		$entry = "$ip\t$hostname";

		// Try direct write first
		if (is_writable($path)) {
			file_put_contents($path, "\n$entry\n", FILE_APPEND);
			return ['added' => true, 'hostname' => $hostname, 'ip' => $ip];
		}

		// Return platform-specific elevation commands
		$cmds = [];
		if (PHP_OS_FAMILY === 'Darwin') {
			$cmds['command'] = "sudo -- sh -c 'echo \"$entry\" >> /etc/hosts'";
			$cmds['gui'] = "osascript -e 'do shell script \"echo \\\"$entry\\\" >> /etc/hosts\" with administrator privileges'";
		} elseif (PHP_OS_FAMILY === 'Windows') {
			$psCmd = "Add-Content -Path '$path' -Value '$entry'";
			$cmds['command'] = "powershell -Command \"Start-Process powershell -Verb RunAs -ArgumentList '-Command $psCmd'\"";
		} else {
			$cmds['command'] = "sudo -- sh -c 'echo \"$entry\" >> /etc/hosts'";
			$cmds['gui'] = "pkexec sh -c 'echo \"$entry\" >> /etc/hosts'";
		}

		return [
			'needsElevation' => true,
			'hostname' => $hostname,
			'ip' => $ip,
			'entry' => $entry,
			'commands' => $cmds,
		];
	}

	// ── Attestation & Trust API ─────────────────────────

	static function apiAttestationSign($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$keyPem = $body['key'] ?? '';
		$signer = $body['signer'] ?? 'panel-user';

		if (!$keyPem) {
			return ['status' => 400, 'error' => 'Provide a PEM private key in the "key" field'];
		}

		// Write key to temp file
		$tmpKey = tempnam(sys_get_temp_dir(), 'qbix_sign_');
		file_put_contents($tmpKey, $keyPem);

		require_once dirname(__DIR__) . '/WebServer/Trust.php';
		$binaryPath = realpath($_SERVER['SCRIPT_FILENAME'] ?? $GLOBALS['argv'][0]);
		$result = Q_WebServer_Trust::signBinary($binaryPath, $tmpKey, $signer);
		@unlink($tmpKey);

		if (!$result) {
			return ['status' => 500, 'error' => 'Signing failed — check key format'];
		}
		return [
			'signed' => true,
			'hash' => $result['binary_hash'],
			'signers' => count($result['signatures']),
		];
	}

	static function apiTrustVerify($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$dir = $body['dir'] ?? null;

		require_once dirname(__DIR__) . '/WebServer/Trust.php';
		if ($dir) {
			$dir = realpath($dir);
			if (!$dir || !is_dir($dir)) {
				return ['status' => 400, 'error' => 'Directory not found'];
			}
			return Q_WebServer_Trust::verifyDirectory($dir);
		}
		// Verify all known directories
		return Q_WebServer_Trust::status();
	}

	// ── Autohost API ────────────────────────────────────

	static function apiAutohostToggle($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$configPath = self::panelConfigPath();
		$config = is_file($configPath)
			? json_decode(file_get_contents($configPath), true) : [];

		if (isset($body['enabled'])) {
			$config['autohost']['enabled'] = (bool) $body['enabled'];
		}
		if (isset($body['authorize'])) {
			$config['autohost']['authorize'] = $body['authorize'];
		}
		if (isset($body['dnsCheck'])) {
			$config['autohost']['dnsCheck'] = (bool) $body['dnsCheck'];
		}
		if (isset($body['acmeEmail'])) {
			$config['autohost']['acmeEmail'] = $body['acmeEmail'];
		}
		if (isset($body['allowlist'])) {
			$config['autohost']['allowlist'] = array_values(array_filter(
				array_map('trim', explode("\n", $body['allowlist']))
			));
		}

		file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

		// Apply to runtime config
		foreach ($config['autohost'] ?? [] as $k => $v) {
			Q_Config::set('Q', 'webserver', 'autohost', $k, $v);
		}

		return ['saved' => true, 'autohost' => $config['autohost'] ?? []];
	}

	// ── Workers API ──────────────────────────────────────

	static function apiWorkerStatus()
	{
		$pool = Q_WebServer::$pool ?? null;
		if (!$pool) {
			return ['mode' => 'in-process', 'workers' => 0];
		}
		$stats = Q_WebServer_Dashboard::getStats();
		return [
			'mode' => 'persistent',
			'workers' => $stats['workers'] ?? 0,
			'activeWorkers' => $stats['activeWorkers'] ?? 0,
			'totalRequests' => $stats['totalRequests'] ?? 0,
			'uptime' => $stats['uptime'] ?? 0,
			'memoryUsage' => memory_get_usage(true),
			'memoryPeak' => memory_get_peak_usage(true),
			'pid' => getmypid(),
		];
	}

	static function apiWorkerResize($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$count = (int) ($body['workers'] ?? 0);
		if ($count < 1 || $count > 10000) {
			return ['status' => 400, 'error' => 'Worker count must be 1-10000'];
		}
		$pool = Q_WebServer::$pool ?? null;
		if (!$pool) {
			return ['status' => 400, 'error' => 'No worker pool (in-process mode)'];
		}
		if (method_exists($pool, 'resize')) {
			return array('resized' => $count) + $pool->resize($count);
		}
		return ['status' => 501, 'error' => 'Pool does not support dynamic resize yet'];
	}

	static function apiWorkerRecycle($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$pool = Q_WebServer::$pool ?? null;
		if (!$pool) {
			return ['status' => 400, 'error' => 'No worker pool'];
		}
		$index = $body['index'] ?? null;
		if ($index !== null) {
			// Recycle a specific worker
			$result = $pool->recycleWorker((int) $index);
			return ['worker' => (int) $index, 'result' => $result];
		}
		// Recycle all workers (rolling restart)
		$result = $pool->recycleAll();
		return ['recycled' => $result];
	}

	static function apiWorkerDetail()
	{
		$pool = Q_WebServer::$pool ?? null;
		if (!$pool) {
			return ['mode' => 'in-process', 'workers' => []];
		}
		return $pool->workerStats();
	}

	// ── Logs API ─────────────────────────────────────────

	static function apiLogs($parsed)
	{
		$query = $parsed['query'] ?? [];
		parse_str($query, $params);
		$lines = (int) ($params['lines'] ?? 50);
		$lines = max(1, min($lines, 500));
		$type = $params['type'] ?? 'access'; // access or error

		// Ask the log itself where it writes: it resolved `dir` against
		// APP_DIR at startup and knows the configured filenames, neither
		// of which can be reconstructed from the config key alone.
		// ?host= picks a virtual host's own log; without it, the server's.
		$host = strtolower(trim($params['host'] ?? ''));
		if ($host !== '' and isset(Q_WebServer_Log::$hosts[$host])) {
			$rec = Q_WebServer_Log::$hosts[$host];
			$file = $type === 'error' ? $rec['errorPath'] : $rec['accessPath'];
		} else {
			$file = $type === 'error'
				? Q_WebServer_Log::$errorPath
				: Q_WebServer_Log::$accessPath;
		}
		if (!$file) {
			$logDir = Q_Config::get('Q', 'webserver', 'log', 'dir', 'logs');
			$name = $type === 'error'
				? Q_WebServer_Log::$errorName
				: Q_WebServer_Log::$accessName;
			$file = $logDir . '/' . $name;
		}

		if (!is_file($file)) {
			return ['lines' => [], 'file' => $file, 'exists' => false];
		}

		// Tail the file efficiently
		$result = [];
		$fp = fopen($file, 'r');
		if ($fp) {
			$size = filesize($file);
			$chunk = min($size, $lines * 512); // rough estimate
			fseek($fp, max(0, $size - $chunk));
			$content = fread($fp, $chunk);
			fclose($fp);
			$allLines = explode("\n", trim($content));
			$result = array_slice($allLines, -$lines);
		}

		return ['lines' => $result, 'file' => $file, 'exists' => true, 'size' => filesize($file)];
	}

	// ── Cron / Scheduler API ─────────────────────────────

	static function apiCronStatus()
	{
		$tasks = Q_Config::get('Q', 'scheduler', array());
		$result = [];
		foreach ($tasks as $name => $conf) {
			$entry = [
				'name' => $name,
				'handler' => $conf['handler'] ?? $name,
				'every' => $conf['every'] ?? null,
				'times' => $conf['times'] ?? null,
				'weekdays' => $conf['weekdays'] ?? null,
				'monthdays' => $conf['monthdays'] ?? null,
			];
			$result[] = $entry;
		}
		return ['tasks' => $result];
	}

	static function apiCronRun($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$name = $body['task'] ?? '';
		$tasks = Q_Config::get('Q', 'scheduler', array());
		if (!isset($tasks[$name])) {
			return ['status' => 404, 'error' => "Task '{$name}' not found"];
		}
		$handler = $tasks[$name]['handler'] ?? $name;
		// Dispatch in a forked process
		if (function_exists('pcntl_fork')) {
			$pid = pcntl_fork();
			if ($pid === 0) {
				Q::event($handler);
				exit(0);
			}
			return ['dispatched' => $name, 'handler' => $handler, 'pid' => $pid];
		}
		return ['status' => 501, 'error' => 'pcntl_fork not available'];
	}

	static function appsDir()
	{
		// 1. Explicit Q config
		$dir = Q_Config::get('Q', 'webserver', 'panel', 'appsDir', null);
		if ($dir && is_dir($dir)) return $dir;
		// 2. Saved in panel config file
		$configPath = self::panelConfigPath();
		if (file_exists($configPath)) {
			$config = json_decode(file_get_contents($configPath), true);
			if (!empty($config['appsDir']) && is_dir($config['appsDir'])) {
				return $config['appsDir'];
			}
		}
		// 3. Platform mode: parent of APP_DIR
		if (defined('APP_DIR')) return dirname(APP_DIR);
		return null;
	}

	static function which($cmd)
	{
		$path = trim(shell_exec((PHP_OS_FAMILY === 'Windows' ? 'where' : 'which')
			. ' ' . escapeshellarg($cmd) . ' 2>/dev/null') ?? '');
		return $path ?: null;
	}

	static function formatBytes($bytes)
	{
		if ($bytes === false) return 'N/A';
		$units = ['B', 'KB', 'MB', 'GB', 'TB'];
		$i = 0;
		while ($bytes >= 1024 && $i < 4) { $bytes /= 1024; $i++; }
		return round($bytes, 1) . ' ' . $units[$i];
	}

	static function copyDir($src, $dst)
	{
		$dir = opendir($src);
		@mkdir($dst, 0755, true);
		while (($file = readdir($dir)) !== false) {
			if ($file === '.' || $file === '..') continue;
			$srcPath = $src . DS . $file;
			$dstPath = $dst . DS . $file;
			if (is_dir($srcPath)) {
				self::copyDir($srcPath, $dstPath);
			} else {
				copy($srcPath, $dstPath);
			}
		}
		closedir($dir);
	}

	static function renameInApp($dir, $oldName, $newName)
	{
		// Rename in config/app.json
		$configFile = $dir . DS . 'config' . DS . 'app.json';
		if (file_exists($configFile)) {
			$content = file_get_contents($configFile);
			$content = str_replace($oldName, $newName, $content);
			file_put_contents($configFile, $content);
		}

		// Rename handler/class directories
		foreach (array('handlers', 'classes', 'views', 'text') as $sub) {
			$oldDir = $dir . DS . $sub . DS . $oldName;
			$newDir = $dir . DS . $sub . DS . $newName;
			if (is_dir($oldDir)) {
				rename($oldDir, $newDir);
			}
		}

		// Rename script directories
		$oldScripts = $dir . DS . 'scripts' . DS . $oldName;
		$newScripts = $dir . DS . 'scripts' . DS . $newName;
		if (is_dir($oldScripts)) {
			rename($oldScripts, $newScripts);
		}
	}

	// ── Panel HTML ───────────────────────────────────────

	static function renderPanel($parsed)
	{
		$host = $parsed['headers']['host'] ?? 'localhost:8080';
		$wsUrl = "ws://$host/Q/ws";
		// The panel HTML is too large for inline — load from file
		// or generate. For now, inline a functional SPA.
		return self::panelHtml($host, $wsUrl);
	}

	/**
	 * The panel's own page for a visitor it refuses: its toolbar and design,
	 * with the reason where the login form would be. Sent with status 403.
	 * Falls back to the server's error page when the design has no
	 * refused.html (an older custom design).
	 * @method refusedPage
	 * @static
	 * @param {string} $messageHtml written by the server, never request data
	 * @return {string}
	 */
	static function refusedPage($messageHtml)
	{
		$brand = class_exists('Q_WebServer', false) ? Q_WebServer::brand() : 'Qbix';
		$page = Q_WebServer_Design::render('panel', array(
			'brandHead' => Q_WebServer_Brand::headTags($brand . ' Control Panel', '/Q/panel'),
			'brand'     => htmlspecialchars($brand, ENT_QUOTES, 'UTF-8'),
			'message'   => $messageHtml,
		), 'refused.html');
		return $page !== null ? Q_WebServer_Shell::decorate($page) : Q_WebServer::renderErrorPage(403, '/Q/panel', $messageHtml);
	}

	static function panelHtml($host, $wsUrl)
	{
		$brand = class_exists('Q_WebServer', false)
			? Q_WebServer::brand() : 'Qbix';
		// The page is a design on disk -- designs/default/panel/ (page.html,
		// style.css, script.js), or the same files in the configuration
		// directory's designs/ -- byte for byte what this method used to
		// return from a nowdoc here (see tests/unit-design-render.php).
		// Icons, manifest and link-preview tags; escaped by headTags().
		$page = Q_WebServer_Design::render('panel', array(
			'brandHead' => Q_WebServer_Brand::headTags($brand . ' Control Panel', '/Q/panel'),
			'brand'     => htmlspecialchars($brand, ENT_QUOTES, 'UTF-8'),
		));
		return $page !== null ? Q_WebServer_Shell::decorate($page)
			: '<!DOCTYPE html><html><body><p>The panel design is missing (designs/default/panel).</p></body></html>';
	}
}
