<?php
/**
 * @module Q
 */

/**
 * Pre-fork worker pool for PHP script execution.
 *
 * Each worker handles ONE request, then exits. The parent
 * maintains N idle workers at all times. When one finishes,
 * a replacement is forked immediately.
 *
 * Why one-request-per-process:
 *   PHP has no way to fully reset static state — Foo::$bar,
 *   DB connections, registered shutdown functions, output
 *   buffers all persist. The only clean reset is process exit.
 *
 * Why this is fast:
 *   fork() on Linux uses copy-on-write. The child inherits
 *   all loaded classes, opcache, config — everything the
 *   parent loaded during bootstrap — without copying memory.
 *   Cost: ~0.5ms per fork.
 *
 * Important: the parent must NOT open DB connections or
 * stateful resources before forking. Q's DB connections are
 * lazy (opened on first query), so this is natural.
 *
 *   Parent (event loop, Q.inc.php loaded)
 *     ├── Worker 0 [idle, waiting on socketpair]
 *     ├── Worker 1 [busy, processing request]  → exits → replacement forked
 *     ├── Worker 2 [idle]
 *     └── Worker 3 [idle]
 *
 * @class Q_WebServer_Pool
 */
class Q_WebServer_Pool
{
	public $targetSize;
	protected $workers = array();       // index => [pid, socket, busy]
	protected $workerClients = array(); // index => HTTP client socket
	protected $workerBuffers = array(); // index => partial response data
	protected $watchers = array();      // index => Q_Evented watcher id
	protected $pending = array();       // queued [client, parsed, scriptPath, responder]
	protected $nextIndex = 0;
	protected static $inputWrapperRegistered = false;

	/**
	 * Whether workers persist between requests, rather than one per request.
	 *
	 * Declared, along with $maxRequests below, because PHP 8.2 deprecates
	 * creating a property by assigning to it. The constructor assigned both,
	 * so every start emitted two deprecation notices -- harmless where they
	 * are only displayed, and fatal where the installation has chosen to treat
	 * a deprecation as an error, which is what stopped the server on FreeBSD
	 * after the banner had already been printed.
	 *
	 * @property $octane
	 * @type {boolean}
	 */
	protected $octane = true;

	/**
	 * How many requests a persistent worker serves before retiring itself.
	 * @property $maxRequests
	 * @type {integer}
	 */
	protected $maxRequests = 1000;

	/**
	 * @method __construct
	 * @param {integer} [$size=4]
	 */
	function __construct($size = null)
	{
		// Load Fork abstraction (pcntl on Unix, FFI+dll on Windows)
		$forkFile = dirname(__DIR__) . '/WebServer/Fork.php';
		if (!class_exists('Q_WebServer_Fork', false) && is_file($forkFile)) {
			require_once $forkFile;
		}
		if (!Q_WebServer_Fork::available()) {
			throw new Exception(
				"Q_WebServer_Pool requires fork capability. "
				. "Install pcntl (Linux/macOS) or qbix_fork.dll (Windows). "
				. "Or use --workers=0 for php-cgi mode."
			);
		}
		$this->targetSize = $size ?: (int) Q_Config::get(
			'Q', 'webserver', 'workers', 4
		);
		$this->octane = !Q_Config::get(
			'Q', 'webserver', 'forkPerRequest', false
		);
		$this->maxRequests = (int) Q_Config::get(
			'Q', 'webserver', 'maxRequests', 1000
		);

		// Compat on by default unless explicitly disabled, in either mode.
		// It shims the lifecycle functions for shared-nothing safety, which
		// only a persistent worker needs -- but it also shims header() and
		// setcookie(), which every worker needs. Those are no-ops under the
		// CLI SAPI, so without the shim headers_list() comes back empty and
		// the response goes out with neither Set-Cookie nor Location: the
		// credentials are accepted, the redirect is issued, and the session
		// cookie never reaches the browser.
		// Q_WebServer_Compat::init() returns immediately when compat is
		// configured off, so the decision stays with the config key and is
		// not implied by the fork mode.
		$compatSet = Q_Config::get('Q', 'compat', 'skipSourceCodeTransform', null);
		if ($compatSet === null) {
			Q_Config::set('Q', 'compat', 'skipSourceCodeTransform', false);
		}
		$compatFile = dirname(__DIR__) . '/WebServer/Compat.php';
		if (!class_exists('Q_WebServer_Compat', false) && is_file($compatFile)) {
			require_once $compatFile;
		}
		if (class_exists('Q_WebServer_Compat', false)) {
			Q_WebServer_Compat::init();
		}

		// Parent-side preload, before the snapshot and before the fork.
		//
		// This is the one lever that moves per-worker memory. A worker that
		// bootstraps a heavy framework on its first request builds that state in
		// its own heap -- private to it, paid once per worker. Measured on this
		// installation each warm worker held ~209 MB, so 64 of them was ~13 GB of
		// the same kernel loaded 64 times.
		//
		// Loading it HERE, in the parent, before the children exist, means fork()
		// hands every worker the same pages copy-on-write. They stay shared until
		// a worker writes to them, and a warmed framework is mostly read -- class
		// tables, parsed configuration, type registries -- so the shared fraction
		// is large and the private residue small.
		//
		// The preload is a script named by Q.webserver.preload, run once in an
		// isolated scope. It is off unless configured, because what is safe to
		// load before a fork is application-specific: loading classes and parsing
		// configuration is fork-safe, but a database handle opened here would be
		// shared by every child and corrupt. The script's job is to warm the
		// former and open none of the latter; the app provides it.
		$preload = Q_Config::get('Q', 'webserver', 'preload', null);
		if (is_string($preload) && $preload !== '' && is_file($preload)) {
			$before = memory_get_usage(true);
			$__run = static function ($__preloadFile) {
				require $__preloadFile;
			};
			try {
				$__run($preload);
				$grew = (memory_get_usage(true) - $before) / 1048576;
				fwrite(STDERR, sprintf(
					"  preload %s warmed %.1f MB in the parent (shared by every worker)\n",
					basename($preload), $grew));
			} catch (\Throwable $e) {
				// A preload that throws must not stop the server from starting --
				// the workers can still warm themselves lazily, the old way.
				if (class_exists('Q_WebServer_Log', false)) {
					Q_WebServer_Log::error('preload ' . basename($preload)
						. ' failed, workers will warm lazily: ' . $e->getMessage());
				}
			}
		}

		if ($this->octane) {
			$snapFile = dirname(__DIR__) . '/WebServer/Snapshot.php';
			if (!class_exists('Q_WebServer_Snapshot', false) && is_file($snapFile)) {
				require_once $snapFile;
			}
			if (class_exists('Q_WebServer_Snapshot', false)) {
				$n = Q_WebServer_Snapshot::take();
			}
		}
		if (function_exists('pcntl_signal')) {
			pcntl_signal(SIGCHLD, SIG_DFL);
		}
		for ($i = 0; $i < $this->targetSize; $i++) {
			$this->forkWorker();
		}
	}

	/**
	 * Fork one worker. Child inherits parent's loaded state
	 * via copy-on-write.
	 * @method forkWorker
	 * @return {integer} Worker index
	 */
	protected function forkWorker()
	{
		// STREAM_PF_UNIX doesn't exist on Windows — use INET loopback
		$family = defined('STREAM_PF_UNIX') ? STREAM_PF_UNIX : STREAM_PF_INET;
		$pair = stream_socket_pair(
			$family, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP
		);
		if (!$pair) throw new Exception("socketpair failed");

		$pid = Q_WebServer_Fork::fork();
		if ($pid === -1 || $pid === false) throw new Exception("fork failed");

		if ($pid === 0) {
			// ── CHILD ──
			//
			// fork() hands the child the parent's entire descriptor table. The
			// worker needs one descriptor -- its half of this pair -- and this
			// used to close exactly one: the other half. Everything else came
			// with it.
			//
			// What it inherited: the listening socket, the TLS listener, the
			// Unix socket, every client connection the parent had open at that
			// instant, and the parent's end of every *other* worker's socket
			// pair. A worker could therefore read another visitor's request,
			// and another worker's replies, on an installation serving one
			// site with one tenant. It also kept the listening socket alive
			// after the parent closed it.
			//
			// Order matters only in that all of it must happen before the
			// worker runs any application code.
			fclose($pair[0]);

			// The parent's end of every worker forked before this one.
			foreach ($this->workers as $other) {
				if (isset($other['socket']) and is_resource($other['socket'])) {
					@fclose($other['socket']);
				}
			}
			$this->workers = array();
			$this->watchers = array();

			// Listeners and client connections live on the server, not here.
			if (method_exists('Q_WebServer', 'closeInheritedDescriptors')) {
				Q_WebServer::closeInheritedDescriptors();
			}

			self::childRun($pair[1], $this->octane, $this->maxRequests);
			exit(0);
		}

		// ── PARENT ──
		fclose($pair[1]);
		$sock = $pair[0];
		stream_set_blocking($sock, false);

		$index = $this->nextIndex++;
		$this->workers[$index] = array(
			'pid' => $pid, 'socket' => $sock, 'busy' => false
		);

		$pool = $this;
		$this->watchers[$index] = Q_Evented::onReadable(
			$sock,
			function ($s) use ($pool, $index) {
				$pool->onWorkerData($index, $s);
			}
		);
		Q_Evented::disable($this->watchers[$index]);
		return $index;
	}

	// ── Child process ────────────────────────────────────

	/**
	 * Child: handle requests. In octane mode, loops with snapshot restore
	 * between requests (0.05ms) instead of dying and re-forking (8ms).
	 * In classic mode, handles one request and exits.
	 *
	 * @method childRun
	 * @static
	 * @param {resource} $socket  Unix socket pair to the parent
	 * @param {boolean}  $octane  Whether to loop (true) or die after one (false)
	 * @param {integer}  $maxReqs Maximum requests before voluntary exit (0=unlimited)
	 */
	protected static function childRun($socket, $octane = false, $maxReqs = 0)
	{
		stream_set_blocking($socket, true);
		// An octane worker blocks on readExact() between requests, sometimes
		// for minutes. Without this, PHP's default_socket_timeout (60s) makes
		// that read return '' and the worker exits, so the first request after
		// an idle period gets a 502. There is no deadline on waiting for work.
		stream_set_timeout($socket, 86400);
		$handled = 0;

		do {
			// Read length-prefixed request
			$hdr = self::readExact($socket, 4);
			if ($hdr === false) break;
			$len = unpack('N', $hdr)[1];
			if ($len > 10485760) break;
			$json = self::readExact($socket, $len);
			if ($json === false) break;
			$req = json_decode($json, true);
			if (!$req) {
				self::writeMsg($socket, 500, 'Bad message', array());
				if (!$octane) break;
				continue;
			}
			if (!empty($req['requestUndecodable'])) {
				// The parent could not encode this request at all, so there
				// is nothing to run. Say so, rather than running the payload
				// that stands in for it and serving the wrong page.
				self::writeMsg($socket, 400, 'Bad Request', array());
				if (!$octane) break;
				continue;
			}

			// Execute the PHP script
			$resp = self::executeScript($req);
			self::writeMsg($socket, $resp['status'], $resp['body'],
				$resp['headers'], $resp['cookies'] ?? array());
			$handled++;

			if (!$octane) {
				// This worker is about to exit. Compat has taken over
				// register_shutdown_function, so PHP's own shutdown will not
				// fire what the request registered -- and eZ writes its
				// session in one of those callbacks. Without this the session
				// is never stored: the login is accepted, the cookie goes out,
				// and the next request finds nothing behind it, so the user is
				// back at the login form.
				if (class_exists('Q_WebServer_Compat', false)
					and Q_WebServer_Compat::isEnabled()) {
					try {
						Q_WebServer_Compat::shutdown();
					} catch (\Throwable $e) {
						// The response is already on its way; a failure in
						// cleanup must not turn it into a lost request.
					}
				}
				break;
			}

			// ── Octane: reset state for the next request ──

			// Compat layer: fire shutdown callbacks, restore error/exception
			// handlers, unregister request autoloaders, restore env vars,
			// close sessions, clean up uploads — all BEFORE snapshot restore
			// so shutdown callbacks see the request's final state.
			if (class_exists('Q_WebServer_Compat', false)
				&& Q_WebServer_Compat::isEnabled()) {
				Q_WebServer_Compat::shutdown();
				// Re-init for next request (re-registers file:// wrapper)
				Q_WebServer_Compat::init();
			}

			// Static properties: the snapshot captures the clean state the parent
			// had after preloading. restoreStatics() resets them via
			// ReflectionProperty::setValue — 0.05ms, vs 8ms for fork.
			if (class_exists('Q_WebServer_Snapshot', false)) {
				// Scripts may declare classes that were not there at preload
				// time; track those too, so their request state is cleared
				// like everything else.
				Q_WebServer_Snapshot::updateNewClasses();
				Q_WebServer_Snapshot::restoreStatics();
			}

			// Global variables: remove anything the script added.
			// Keep superglobals and the server's own bookkeeping.
			$keepGlobals = array('_GET','_POST','_COOKIE','_SERVER','_REQUEST',
				'_FILES','_ENV','_SESSION','GLOBALS','argv','argc',
				'_Q_RAW_INPUT');

			// Applications may keep a global of their own across requests.
			// A registry populated by an include_once is the case that needs
			// it: clearing the global leaves the registry empty while
			// include_once refuses to run the file that would fill it again,
			// so every later request in that worker sees an empty registry
			// and no way to refill it. Naming such a global is narrow and
			// reviewable; keeping globals wholesale is not, because the
			// request-scoped ones still have to be cleared.
			$extraGlobals = Q_Config::get('Q', 'webserver', 'keepGlobals', array());
			if (is_string($extraGlobals)) {
				$extraGlobals = preg_split('/\s*,\s*/', $extraGlobals, -1, PREG_SPLIT_NO_EMPTY);
			}
			if (is_array($extraGlobals) && $extraGlobals) {
				$keepGlobals = array_values(array_unique(
					array_merge($keepGlobals, $extraGlobals)
				));
			}
			foreach (array_keys($GLOBALS) as $gk) {
				if (!in_array($gk, $keepGlobals, true)) {
					unset($GLOBALS[$gk]);
				}
			}

			// Superglobals: overwritten by executeScript() on next iteration.
			// Output buffers: non-removable buffer in executeScript, read via ob_get_contents.
			// Error state: clear it.
			error_clear_last();

			// Response headers: clear Q_WebServer_State's accumulated headers
			// and any native header() calls from the previous request.
			if (class_exists('Q_WebServer_State', false)) {
				Q_WebServer_State::clear();
			}
			// Issue #16: also clear Q_Response accumulated state (scripts,
			// styles, cookies, errors) so they don't leak between requests.
			if (class_exists('Q_Response', false)
				&& method_exists('Q_Response', 'clear')) {
				Q_Response::clear();
			}
			if (function_exists('header_remove')) {
				@header_remove();
			}

			// DB connections: flush transaction state. A persistent worker that
			// serves request A (which starts a transaction) and then request B
			// would leak A's uncommitted transaction into B. ROLLBACK is safe
			// even if no transaction is active (it's a no-op).
			if (class_exists('Db', false) && method_exists('Db', 'getConnection')) {
				try {
					foreach (Db::getConnections() as $conn) {
						if (method_exists($conn, 'rawQuery')) {
							$conn->rawQuery('ROLLBACK');
						}
					}
				} catch (\Throwable $e) { /* no DB configured — that's fine */ }
			}

			// Voluntary recycling: after N requests, exit so the parent
			// re-forks a clean worker. Safety net for state the snapshot
			// can't reach (C extension internals, accumulated closures).
			if ($maxReqs > 0 && $handled >= $maxReqs) break;

		} while (true);

		fclose($socket);
	}

	/**
	 * Set up superglobals and include the PHP script.
	 * The script (index.php, action.php, etc.) internally calls
	 * Q_WebController::execute() or Q_ActionController::execute().
	 */
	protected static function executeScript($req)
	{
		// ── Reset ALL superglobals to prevent cross-request leaks ──
		// $_SERVER: strip all HTTP_* headers and app-injected keys from
		// the previous request, then repopulate from this request only.
		foreach (array_keys($_SERVER) as $k) {
			if (strncmp($k, 'HTTP_', 5) === 0) unset($_SERVER[$k]);
		}
		unset($_SERVER['CONTENT_TYPE'], $_SERVER['CONTENT_LENGTH']);
		// Remove any keys the previous script injected
		$_serverKeep = array('PATH','HOME','LANG','USER','SHELL','TERM',
			'SHLVL','_','SERVER_SOFTWARE','GATEWAY_INTERFACE',
			'REQUEST_SCHEME','HTTPS','PHP_SELF','argv','argc');
		foreach (array_keys($_SERVER) as $k) {
			if (!in_array($k, $_serverKeep, true)
				&& strncmp($k, 'REQUEST_', 8) !== 0
				&& strncmp($k, 'SERVER_', 7) !== 0
				&& strncmp($k, 'SCRIPT_', 7) !== 0
				&& strncmp($k, 'DOCUMENT_', 9) !== 0
				&& strncmp($k, 'REMOTE_', 7) !== 0
				&& strncmp($k, 'QUERY_', 6) !== 0
			) {
				unset($_SERVER[$k]);
			}
		}

		$_SERVER['REQUEST_METHOD'] = $req['method'];
		$_SERVER['REQUEST_URI'] = $req['uri'];
		$_SERVER['QUERY_STRING'] = $req['query'] ?? '';
		$_SERVER['SCRIPT_FILENAME'] = $req['scriptFilename'];
		$_SERVER['SCRIPT_NAME'] = $req['scriptName'] ?? '/index.php';
		$_SERVER['PHP_SELF'] = $req['scriptName'] ?? '/index.php';
		$_SERVER['DOCUMENT_ROOT'] = $req['documentRoot'] ?? '';
		// SERVER_NAME is the host, without the port. SERVER_PORT is the port.
		//
		// Assigning the raw Host header here put "example.com:8080" in a
		// variable every CGI-speaking application expects to be a bare
		// hostname, and applications do arithmetic on it. One of them derived a
		// request path by removing the server name from the front of a URL and
		// was left holding ":8080/admin/setup/info", which it then stored as
		// the page to return to after signing in -- so signing in landed on
		// /admin/%3A8080/admin/setup/info and a 404.
		//
		// Split on the last colon so an IPv6 literal in brackets survives.
		$host = (string) ($req['headers']['host'] ?? 'localhost');
		if ($host === '') {
			$host = 'localhost';
		} else if ($host[0] === '[') {
			$end = strpos($host, ']');
			if ($end !== false) $host = substr($host, 0, $end + 1);
		} else {
			$colon = strrpos($host, ':');
			if ($colon !== false) $host = substr($host, 0, $colon);
		}
		$_SERVER['SERVER_NAME'] = $host;
		$_SERVER['SERVER_PORT'] = $req['serverPort'] ?? '8080';
		$_SERVER['REMOTE_ADDR'] = $req['remoteAddr'] ?? '127.0.0.1';
		$_SERVER['SERVER_SOFTWARE'] = 'QbixServer/' . (defined('QBIX_SERVER_VERSION') ? QBIX_SERVER_VERSION : '1.0');
		$_SERVER['GATEWAY_INTERFACE'] = 'CGI/1.1';
		$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

		foreach ($req['headers'] as $k => $v) {
			$_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
		}
		if (isset($req['headers']['content-type']))
			$_SERVER['CONTENT_TYPE'] = $req['headers']['content-type'];
		if (isset($req['headers']['content-length']))
			$_SERVER['CONTENT_LENGTH'] = $req['headers']['content-length'];

		// REQUEST_SCHEME / HTTPS from proxy headers or direct
		$proto = $req['headers']['x-forwarded-proto'] ?? '';
		if (strtolower($proto) === 'https' || ($req['https'] ?? false)) {
			$_SERVER['HTTPS'] = 'on';
			$_SERVER['REQUEST_SCHEME'] = 'https';
		} else {
			$_SERVER['REQUEST_SCHEME'] = 'http';
			unset($_SERVER['HTTPS']);
		}

		// PHP_AUTH_USER / PHP_AUTH_PW from Authorization header
		$authHeader = $req['headers']['authorization'] ?? '';
		if ($authHeader && stripos($authHeader, 'Basic ') === 0) {
			$decoded = base64_decode(substr($authHeader, 6));
			if ($decoded !== false && strpos($decoded, ':') !== false) {
				list($user, $pass) = explode(':', $decoded, 2);
				$_SERVER['PHP_AUTH_USER'] = $user;
				$_SERVER['PHP_AUTH_PW'] = $pass;
			}
		} else {
			unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
		}

		// Populate getallheaders() / apache_request_headers()
		if (!class_exists('Q_WebServer_GetAllHeaders', false)) {
			$_gah = __DIR__ . '/GetAllHeaders.php';
			if (is_file($_gah)) require_once $_gah;
		}
		if (class_exists('Q_WebServer_GetAllHeaders', false)) {
			Q_WebServer_GetAllHeaders::register();
			Q_WebServer_GetAllHeaders::set(
				$req['headers'] ?? array(),
				$req['rawHeaders'] ?? array()
			);
		}

		// ── Clear and rebuild all input superglobals ──
		$_GET = $_POST = $_REQUEST = $_FILES = array();
		$_COOKIE = array();
		if (!empty($req['query'])) parse_str($req['query'], $_GET);

		// Parse cookies from the Cookie header
		$cookieHeader = $req['headers']['cookie'] ?? '';
		if ($cookieHeader !== '') {
			foreach (explode(';', $cookieHeader) as $c) {
				$c = trim($c);
				if ($c === '') continue;
				$eq = strpos($c, '=');
				if ($eq !== false) {
					$_COOKIE[urldecode(substr($c, 0, $eq))] = urldecode(substr($c, $eq + 1));
				}
			}
		}

		$ct = strtolower($req['headers']['content-type'] ?? '');
		$origCt = $req['headers']['content-type'] ?? '';
		// Restore a body that had to be base64-encoded to survive the
		// journey; see sendTo(). Done before anything looks at the body, so
		// multipart parsing and php://input both see the original bytes.
		if (!empty($req['bodyB64'])) {
			$req['body'] = base64_decode($req['bodyB64']);
		}

		$raw = $req['body'] ?? '';
		if (strpos($ct, 'application/x-www-form-urlencoded') !== false) {
			parse_str($raw, $_POST);
		} elseif (strpos($ct, 'application/json') !== false) {
			$_POST = json_decode($raw, true) ?: array();
		} elseif (strpos($ct, 'multipart/form-data') !== false) {
			\Q_WebServer::parseMultipart($origCt, $raw, $_POST, $_FILES);
		}
		$_REQUEST = array_merge($_GET, $_POST);

		// php://input workaround — register a custom stream wrapper
		// so file_get_contents('php://input') works in persistent workers.
		// PHP's built-in php://input is empty in CLI SAPI for included scripts.
		$GLOBALS['_Q_RAW_INPUT'] = $raw;
		if (!self::$inputWrapperRegistered) {
			stream_wrapper_unregister('php');
			stream_wrapper_register('php', 'Q_WebServer_PhpInputStream');
			self::$inputWrapperRegistered = true;
		}

		// Non-removable buffer: Q_Dispatcher::dispatch() calls ob_end_flush()
		// which would destroy a normal buffer. Passing flags=0 makes
		// ob_end_flush()/ob_end_clean() fail on this buffer, so it survives.
		// We read it with ob_get_contents(). (Same fix as dispatchToQ, issue #12.)
		ob_start(null, 0, 0);
		$status = 200;
		$headers = array();
		// mod_php and fpm run a script with its own directory as the working
		// directory. A persistent worker has no reason to change directory at
		// all, so it kept the one the server was started in -- and an
		// application resolving a relative path got the server's directory
		// instead of its own. eZ Publish wrote its template cache into the
		// server's tree, read a half-written file back and died on a parse
		// error pointing at a file it had never heard of.
		$prevCwd = getcwd();
		$scriptDir = dirname($req['scriptFilename']);
		if ($scriptDir !== '' and is_dir($scriptDir)) {
			@chdir($scriptDir);
		}

		try {
			include($req['scriptFilename']);
			// Collect headers from native header() (works in fpm, no-op in CLI)
			foreach (headers_list() as $h) {
				if (strpos($h, ':') !== false) {
					list($k, $v) = explode(':', $h, 2);
					$headers[trim($k)] = trim($v);
				}
			}
			// Also collect headers from Q_WebServer_State (works in CLI/octane)
			if (class_exists('Q_WebServer_State', false)) {
				foreach (\Q_WebServer_State::getHeaders() as $k => $v) {
					$headers[$k] = $v;
				}
			}
			$code = http_response_code();
			if ($code && $code !== 200) $status = $code;

			// Check Q_WebServer_State for status code (CLI SAPI ignores http_response_code)
			if (class_exists('Q_WebServer_State', false)) {
				$stateCode = \Q_WebServer_State::getStatusCode();
				if ($stateCode && $stateCode !== 200) $status = $stateCode;
			}

			// Recover status from the Platform's own error state.
			// Same fix as dispatchToQ: http_response_code() is a no-op under
			// CLI SAPI, so the Platform's 412/424 errors arrive as 200.
			if ($status === 200
			and class_exists('Q_Response', false)
			and method_exists('Q_Response', 'getErrors')) {
				try {
					foreach ((array) \Q_Response::getErrors() as $err) {
						if (is_object($err) and !empty($err->httpResponseCode)) {
							$status = (int) $err->httpResponseCode;
							break;
						}
					}
				} catch (\Throwable $ignore) {}
			}
		} catch (\Q_WebServer_ExitSignal $e) {
			// The script called exit or die. That is an ordinary end of a
			// request, not a failure: whatever it printed before stopping is
			// the response, and the headers it set still stand. Only the
			// process must not actually end, because the process is a worker
			// that has other requests to serve.
			foreach (headers_list() as $h) {
				if (strpos($h, ':') !== false) {
					list($k, $v) = explode(':', $h, 2);
					$headers[trim($k)] = trim($v);
				}
			}
			if (class_exists('Q_WebServer_State', false)) {
				foreach (\Q_WebServer_State::getHeaders() as $k => $v) {
					$headers[$k] = $v;
				}
				$stateCode = \Q_WebServer_State::getStatusCode();
				if ($stateCode and $stateCode !== 200) $status = $stateCode;
			}
			$code = http_response_code();
			if ($code and $code !== 200 and $status === 200) $status = $code;
		} catch (\Throwable $e) {
			$status = 500;
			if (ob_get_level()) ob_clean();
			echo $e->getMessage();
		}
		// Back to where the worker started, so the next request is not
		// affected by where this one went.
		if ($prevCwd !== false) {
			@chdir($prevCwd);
		}

		// ob_get_contents reads the non-removable buffer; ob_get_clean would
		// return false. Then drop any buffers we can.
		$body = '';
		if (ob_get_level()) {
			$body = (string) ob_get_contents();
			@ob_clean();
		}
		while (@ob_end_clean()) { /* drop removable buffers */ }

		// Cookies live in Q_Response, which is the worker's memory. The
		// parent used to read its own copy when writing the response and so
		// found nothing: setcookie() reached the client from no script at
		// all. Carry them across with the response.
		$cookies = array();
		if (class_exists('Q_WebServer_State', false)
		and method_exists('Q_WebServer_State', 'cookieHeaders')) {
			$cookies = (array) Q_WebServer_State::cookieHeaders();
		}
		return compact('status', 'body', 'headers', 'cookies');
	}

	// ── Parent-side dispatch ─────────────────────────────

	/**
	 * The URL path of a script, for SCRIPT_NAME and PHP_SELF.
	 *
	 * This used to be '/' . basename($scriptPath), which is right only for a
	 * script sitting in the document root. Anything in a subdirectory lost
	 * it: an application under /shop/ was told it lived at /, and every
	 * absolute URL it built from SCRIPT_NAME -- stylesheets, form actions,
	 * redirects -- pointed one or more directories too high. The page still
	 * rendered, which is what made it hard to see: it just arrived without
	 * its styling, and posting a form landed somewhere else.
	 *
	 * mod_php and fpm report the script's path below the document root, so
	 * that is what is built here.
	 *
	 * @method scriptName
	 * @static
	 * @protected
	 * @param {string} $scriptPath Absolute path of the script on disk
	 * @return {string}
	 */
	protected static function scriptName($scriptPath)
	{
		$root = rtrim(str_replace('\\', '/', (string) (Q_WebServer::$rootDir ?? '')), '/');
		$path = str_replace('\\', '/', (string) $scriptPath);
		if ($root !== '' and strpos($path, $root . '/') === 0) {
			return '/' . ltrim(substr($path, strlen($root)), '/');
		}
		// Outside the document root -- an alias or a rewrite target. The
		// basename is all that can honestly be said about it.
		return '/' . basename($path);
	}

	/**
	 * Send a request to an idle worker. Queues if all busy.
	 */
	function dispatch($client, $parsed, $scriptPath, $responder = null)
	{
		$idle = $this->findIdle();
		if ($idle === null) {
			$this->pending[] = array($client, $parsed, $scriptPath, $responder);
			return;
		}
		$this->sendTo($idle, $client, $parsed, $scriptPath, $responder);
	}

	protected function sendTo($index, $client, $parsed, $scriptPath, $responder = null)
	{
		$this->workers[$index]['busy'] = true;
		$this->workerClients[$index] = $client;
		// Where the answer goes. Normally it is written to the client socket
		// as an HTTP/1.1 response; an HTTP/2 connection multiplexes many
		// requests over one socket and has to put the answer on the stream it
		// came from, so it supplies a callback and takes the response array
		// instead.
		$this->workerResponders[$index] = $responder;
		$this->workerBuffers[$index] = '';
		$this->workerRequestHeaders[$index] = $parsed['headers'];
		// The reverse proxy cache needs the whole parsed request, not just
		// the headers: it keys on host, path and query, and checks the
		// method and the bypass cookies.
		$this->workerRequests[$index] = $parsed;
		// When the response goes out we need this to report how long it took.
		$this->workerStarted[$index] = microtime(true);
		// In octane mode, the watcher was cancelled after the previous
		// response to prevent stream_select from firing endlessly on the
		// idle socket. Create a fresh one-shot watcher for this dispatch.
		if (!isset($this->watchers[$index])) {
			$pool = $this;
			$this->watchers[$index] = Q_Evented::onReadable(
				$this->workers[$index]['socket'],
				function ($s) use ($pool, $index) {
					$pool->onWorkerData($index, $s);
				}
			);
		} else {
			Q_Evented::enable($this->watchers[$index]);
		}

		$payload = array(
			'method'         => $parsed['method'],
			'uri'            => $parsed['uri'],
			'path'           => $parsed['path'],
			'query'          => $parsed['query'],
			'headers'        => $parsed['headers'],
			'rawHeaders'     => $parsed['rawHeaders'] ?? array(),
			'body'           => $parsed['body'],
			'scriptFilename' => $scriptPath,
			'scriptName'     => self::scriptName($scriptPath),
			'documentRoot'   => rtrim(Q_WebServer::$rootDir ?? '', '/\\'),
			'serverPort'     => (string)($_SERVER['SERVER_PORT'] ?? '8080'),
			'remoteAddr'     => '127.0.0.1',
			// Whether this connection is TLS. The worker already looks for
			// this and reports HTTPS and REQUEST_SCHEME from it, but nothing
			// ever sent it -- so every request looked like plain HTTP no
			// matter which listener it arrived on.
			//
			// An application building an absolute URL asks exactly those two
			// variables. Getting them wrong sends a browser that is on
			// https:// a redirect to http:// on the same port, and that port
			// speaks TLS, so the browser opens a plain connection into a TLS
			// listener and the only possible outcome is a reset. It presents
			// as "Secure Connection Failed" with nothing wrong in any log,
			// and it breaks every redirect: after a login, after a publish.
			'https'          => self::isTlsClient($client)
		);
		$msg = json_encode($payload);
		// A request body that is not valid UTF-8 cannot go through
		// json_encode(), which returns false for it. strlen(false) is 0, so
		// the frame went out as a length prefix of zero and nothing else; the
		// worker read an empty message, could not decode it, and answered
		// "Bad message" with a 500.
		//
		// Every file upload is exactly that. An image, a PDF, a zip -- any
		// multipart POST carrying bytes rather than text -- failed before the
		// application saw it. In a browser the upload simply never completes:
		// the editor's progress dialog waits for a result it will never be
		// given.
		//
		// Base64 carries those bytes, and only those: a text body keeps its
		// exact previous shape and cost. This mirrors what writeMsg() does in
		// the other direction, and uses the same flag.
		if ($msg === false) {
			$payload['bodyB64'] = base64_encode((string) $payload['body']);
			$payload['body'] = '';
			$msg = json_encode($payload);
		}

		if ($msg === false) {
			// Something other than the body cannot be encoded. Fail this one
			// request rather than sending a frame the worker cannot read.
			$msg = json_encode(array(
				'method' => 'GET', 'uri' => '/', 'path' => '/', 'query' => '',
				'headers' => array(), 'rawHeaders' => array(), 'body' => '',
				'scriptFilename' => '', 'scriptName' => '',
				'requestUndecodable' => true
			));
		}

		// The request is length-prefixed and the parent's end of the socket is
		// non-blocking, so fwrite() may place only part of it. Testing for
		// false or zero caught a worker that had died but counted a short
		// write as a success: the worker then read the length we declared,
		// consumed fewer bytes than that, and every request it was sent
		// afterwards began mid-message. writeFully() completes the record or
		// reports that it could not, and does not block the parent -- which is
		// dispatching for every other worker and client at the same time.
		$packet = pack('N', strlen($msg)) . $msg;
		if (!Q_WebServer::writeFully($this->workers[$index]['socket'], $packet)) {
			// Worker died before receiving the request — recycle and re-queue.
			// The responder goes back on the queue too: without it a request
			// that arrived over HTTP/2 would be answered as HTTP/1.1, written
			// straight into an open h2 connection, which corrupts it.
			$this->pending[] = array($client, $parsed, $scriptPath, $responder);
			$this->recycle($index, true);
		}
	}

	/**
	 * Called when data or EOF arrives from a worker.
	 */
	function onWorkerData($index, $sock)
	{
		$chunk = @fread($sock, 65536);

		if ($chunk === false || $chunk === '') {
			// Empty read. In octane mode, the worker stays alive between
			// requests: an empty read means the socket has no new data,
			// NOT that the worker exited. Only recycle if the worker process
			// is actually gone.
			if ($this->octane) {
				$pid = $this->workers[$index]['pid'] ?? 0;
				$alive = $pid && posix_kill($pid, 0);
				if ($alive) return; // worker is idle, not dead
			}
			$this->recycle($index, true);
			return;
		}

		$this->workerBuffers[$index] .= $chunk;
		$buf = $this->workerBuffers[$index];
		if (strlen($buf) < 4) return;

		$len = unpack('N', substr($buf, 0, 4))[1];
		if (strlen($buf) < 4 + $len) return;

		// Got complete response
		$json = substr($buf, 4, $len);
		$response = json_decode($json, true);

		// Binary bodies travel base64-encoded; see writeMsg().
		if ($response and !empty($response['b64'])) {
			$response['body'] = base64_decode($response['body']);
			unset($response['b64']);
		}

		// Check for cache messages piggybacked on the response
		if ($response && !empty($response['_cacheMessages'])) {
			foreach ($response['_cacheMessages'] as $msg) {
				Q_WebServer_Cache_Components::processChildMessage($msg);
			}
			unset($response['_cacheMessages']);
		}

		$client = $this->workerClients[$index] ?? null;
		$reqHeaders = $this->workerRequestHeaders[$index] ?? [];
		if ($response && $client && is_resource($client)) {
			$reqHeaders['_keepAlive'] = false;
			$this->workerRequestHeaders[$index] = $reqHeaders;
			$this->sendHttp($client, $response, $index);
			Q_WebServer::closeClient((int) $client);
		}

		// In octane mode the worker is still alive — mark it idle so it
		// can receive the next request. In classic mode, recycle it (the
		// child exited after one request).
		if ($this->octane) {
			$this->workers[$index]['busy'] = false;
			$this->workers[$index]['requests'] = ($this->workers[$index]['requests'] ?? 0) + 1;
			$this->workerBuffers[$index] = '';
			unset($this->workerClients[$index]);

			// Recycle if marked for graceful recycling, or hit maxRequests
			$shouldRecycle = !empty($this->workers[$index]['recycleAfter'])
				|| ($this->maxRequests > 0 && $this->workers[$index]['requests'] >= $this->maxRequests);

			if ($shouldRecycle) {
				$this->recycle($index, false);
				return;
			}

			if (isset($this->watchers[$index])) {
				Q_Evented::cancel($this->watchers[$index]);
				unset($this->watchers[$index]);
			}
			if (!empty($this->pending)) {
				$next = array_shift($this->pending);
				$this->dispatch($next[0], $next[1], $next[2], $next[3] ?? null);
			}
		} else {
			$this->recycle($index, false);
		}
	}

	/**
	 * Clean up a finished worker: close socket, reap pid,
	 * fork replacement, process pending queue.
	 */
	protected function recycle($index, $isEof)
	{
		if (isset($this->watchers[$index])) {
			Q_Evented::cancel($this->watchers[$index]);
			unset($this->watchers[$index]);
		}

		// EOF with no response → 502
		if ($isEof && isset($this->workerClients[$index])
			&& empty($this->workerBuffers[$index])
		) {
			$c = $this->workerClients[$index];
			if (is_resource($c)) {
				Q_WebServer::sendResponse($c, 502, 'Worker died');
				if (is_resource($c)) @fclose($c);
			}
		} elseif (isset($this->workerClients[$index])) {
			$c = $this->workerClients[$index];
			if (is_resource($c)) @fclose($c);
		}

		if (isset($this->workers[$index])) {
			$sock = $this->workers[$index]['socket'];
			if (is_resource($sock)) @fclose($sock);
			Q_WebServer_Fork::waitpid($this->workers[$index]["pid"], $st, 1);
		}
		unset($this->workers[$index], $this->workerClients[$index],
			$this->workerBuffers[$index], $this->workerRequestHeaders[$index]);

		// Immediately fork replacement
		$newIdx = $this->forkWorker();

		// Drain pending queue
		if (!empty($this->pending)) {
			$next = array_shift($this->pending);
			$this->sendTo($newIdx, $next[0], $next[1], $next[2], $next[3] ?? null);
		}
	}

	/**
	 * We need the original request headers for compression
	 * negotiation. Store them alongside the client.
	 * @property $workerRequestHeaders
	 */
	protected $workerRequestHeaders = array();

	/**
	 * Where each worker's answer should go: a callback that takes the response
	 * array, or null to write it to the client socket as HTTP/1.1.
	 *
	 * One socket carries one HTTP/1.1 request, so writing the response to it
	 * is unambiguous. An HTTP/2 connection multiplexes many requests over one
	 * socket, so the answer has to be put on the stream it belongs to, and
	 * only the connection knows which that is.
	 * @property $workerResponders
	 */
	protected $workerResponders = array();

	/**
	 * The parsed request each worker is serving, kept so the response can
	 * be offered to the reverse proxy cache once the worker is done.
	 * @property $workerRequests
	 */
	protected $workerRequests = array();

	/**
	 * When each worker was handed its request, for the response time the
	 * access log and the dashboard report.
	 * @property $workerStarted
	 */
	protected $workerStarted = array();

	protected function sendHttp($client, $resp, $index)
	{
		$responder = $this->workerResponders[$index] ?? null;
		if ($responder) {
			$this->workerResponders[$index] = null;
			call_user_func($responder, $resp);
		} else {
			$reqHeaders = $this->workerRequestHeaders[$index] ?? array();
			Q_WebServer_Headers::processResponse($client, $resp, $reqHeaders);
		}

		// Recorded either way. How the response reached the client does not
		// change what the log and the dashboard should say about it.
		//
		// The parent skipped recording this one -- dispatch() marked it as
		// handed on, because back then neither the status nor the size existed.
		if (isset($this->workerRequests[$index])) {
			$started = $this->workerStarted[$index] ?? microtime(true);
			Q_WebServer::recordCompleted(
				$this->workerRequests[$index],
				$resp['status'] ?? 200,
				strlen($resp['body'] ?? ''),
				(microtime(true) - $started) * 1000,
				true
			);
		}
		// Offer the response to the cache. Every other dispatch path does
		// this; the pooled path did not, so Q_WebServer_Cache::get() in
		// route() could only ever miss -- nothing was ever stored.
		if (isset($this->workerRequests[$index])) {
			Q_WebServer_Cache::put($this->workerRequests[$index], $resp);
		}
	}

	/**
	 * Whether a client socket is running TLS.
	 *
	 * @method isTlsClient
	 * @static
	 * @param {resource} $client
	 * @return {boolean}
	 */
	static function isTlsClient($client)
	{
		if (!is_resource($client)) return false;
		$meta = @stream_get_meta_data($client);
		return !empty($meta['crypto']);
	}

	protected function findIdle()
	{
		foreach ($this->workers as $i => $w) {
			if (!$w['busy']) return $i;
		}
		return null;
	}

	/**
	 * Gracefully recycle a single worker: let it finish its current request,
	 * then replace it with a fresh fork.
	 * If the worker is idle, recycle immediately.
	 */
	function recycleWorker($index)
	{
		if (!isset($this->workers[$index])) return false;
		if ($this->workers[$index]['busy']) {
			// Mark for recycling after current request finishes
			$this->workers[$index]['recycleAfter'] = true;
			return 'pending';
		}
		$this->recycle($index, false);
		return 'recycled';
	}

	/**
	 * Gracefully recycle ALL workers (rolling restart).
	 * Idle workers are replaced immediately. Busy workers are marked
	 * and replaced when their current request finishes.
	 * Returns count of immediately recycled vs pending.
	 */
	function recycleAll()
	{
		$immediate = 0;
		$pending = 0;
		foreach ($this->workers as $i => $w) {
			if ($w['busy']) {
				$this->workers[$i]['recycleAfter'] = true;
				$pending++;
			} else {
				$this->recycle($i, false);
				$immediate++;
			}
		}
		return ['immediate' => $immediate, 'pending' => $pending];
	}

	/**
	 * Get stats for the control panel.
	 */
	function workerStats()
	{
		$stats = [];
		foreach ($this->workers as $i => $w) {
			$stats[] = [
				'index' => $i,
				'pid' => $w['pid'],
				'busy' => $w['busy'],
				'requests' => $w['requests'] ?? 0,
				'recycleAfter' => !empty($w['recycleAfter']),
			];
		}
		return [
			'workers' => $stats,
			'total' => count($this->workers),
			'busy' => count(array_filter($this->workers, function($w) { return $w['busy']; })),
			'idle' => count(array_filter($this->workers, function($w) { return !$w['busy']; })),
			'maxRequests' => $this->maxRequests,
			'mode' => $this->octane ? 'persistent' : 'fork-per-request',
			'pending' => count($this->pending),
		];
	}

	/**
	 * Graceful shutdown: SIGTERM all workers, wait up to $timeout seconds,
	 * then SIGKILL any remaining.
	 * @param {float} $timeout Seconds to wait after SIGTERM before SIGKILL
	 */
	function shutdown($timeout = 3.0)
	{
		// Cancel watchers and close sockets
		foreach ($this->workers as $i => $w) {
			if (isset($this->watchers[$i])) {
				Q_Evented::cancel($this->watchers[$i]);
			}
			if (is_resource($w['socket'])) @fclose($w['socket']);
		}

		// Send SIGTERM to all workers
		foreach ($this->workers as $w) {
			if (function_exists("posix_kill")) posix_kill($w["pid"], SIGTERM);
		}

		// Wait for workers to exit gracefully
		$deadline = microtime(true) + $timeout;
		$remaining = $this->workers;
		while (!empty($remaining) && microtime(true) < $deadline) {
			foreach ($remaining as $i => $w) {
				$result = Q_WebServer_Fork::waitpid($w['pid'], $st, 1);
				if ($result > 0 || $result === -1) {
					unset($remaining[$i]);
				}
			}
			if (!empty($remaining)) {
				usleep(50000); // 50ms
			}
		}

		// SIGKILL any workers that didn't exit in time
		foreach ($remaining as $w) {
			if (function_exists("posix_kill")) posix_kill($w["pid"], SIGKILL);
			Q_WebServer_Fork::waitpid($w['pid'], $st, 0);
		}

		$this->workers = array();
	}

	function idleCount()
	{
		$n = 0;
		foreach ($this->workers as $w) if (!$w['busy']) $n++;
		return $n;
	}

	/**
	 * Get per-worker stats: PID, busy status, RSS memory.
	 * Linux: /proc/$pid/statm. macOS: ps -o rss. Windows: tasklist.
	 */
	function getWorkerStats()
	{
		$count = count($this->workers);

		// Memory is SAMPLED, never read per worker. This runs every couple of
		// seconds while a dashboard is open, in the single event-loop process, and
		// reading /proc for hundreds of workers there stalled the loop long enough
		// to wedge the whole server -- the front page timed out, not just the
		// dashboard. Workers are near-identical (same fork, same warmed baseline),
		// so a bounded sample scaled to the count is the right order of magnitude
		// at a fixed cost, where the per-worker read was O(workers) and unbounded.
		$pids = array();
		foreach ($this->workers as $w) $pids[] = $w['pid'];
		$sample = $pids;
		$sampleMax = 24;
		if (count($sample) > $sampleMax) {
			shuffle($sample);
			$sample = array_slice($sample, 0, $sampleMax);
		}

		$rssMap = self::getProcessRss($sample);
		$pssMap = self::getProcessPss($sample);
		$avgRss = $rssMap ? array_sum($rssMap) / count($rssMap) : 0;
		$avgPss = $pssMap ? array_sum($pssMap) / count($pssMap) : 0;
		$totalRss = (int) round($avgRss * $count);
		$totalPss = 0;
		if ($avgPss > 0) {
			// The parent holds the shared pages the workers' PSS is a fraction of;
			// read it exactly and add it.
			$selfMap = self::getProcessPss(array(getmypid()));
			$totalPss = (int) round($avgPss * $count) + ($selfMap[getmypid()] ?? 0);
		}

		return array(
			// Per-worker detail is deliberately omitted: the UI does not use it and
			// a list of hundreds would be built and sent on every tick.
			'workers' => array(),
			'count' => $count,
			'target' => $this->targetSize,
			'idle' => $this->idleCount(),
			'sampled' => count($sample) < $count ? count($sample) : 0,
			'totalRssKb' => $totalRss,
			// 0 off Linux; the dashboard then falls back to RSS and says so.
			'totalPssKb' => $totalPss,
		);
	}

	/**
	 * Get RSS in KB for a list of PIDs. Cross-platform.
	 */
	/**
	 * Real memory (PSS) in KB for a list of PIDs, Linux only.
	 *
	 * RSS counts a shared copy-on-write page in full against every process that
	 * maps it, so summing worker RSS reports the warmed baseline once per worker
	 * -- hundreds of workers each showing ~40 MB became ~15 GB, which is the
	 * opposite of what copy-on-write does. PSS ("proportional set size") divides
	 * each shared page by the number of processes sharing it, so the sum over
	 * the parent and its workers is the actual physical memory. That is the
	 * number the "Worker Memory (COW)" card exists to show.
	 *
	 * smaps_rollup is one small read per pid; the caller caches it. Returns an
	 * empty map off Linux, where the card falls back to RSS.
	 */
	static function getProcessPss($pids)
	{
		$map = array();
		if (empty($pids) || PHP_OS_FAMILY !== 'Linux') return $map;
		foreach ($pids as $pid) {
			$roll = @file_get_contents("/proc/$pid/smaps_rollup");
			if ($roll && preg_match('/^Pss:\s+(\d+)/m', $roll, $m)) {
				$map[$pid] = (int) $m[1]; // already KB
			}
		}
		return $map;
	}

	static function getProcessRss($pids)
	{
		$map = array();
		if (empty($pids)) return $map;

		if (PHP_OS_FAMILY === 'Linux') {
			foreach ($pids as $pid) {
				$statm = @file_get_contents("/proc/$pid/statm");
				if ($statm) {
					$fields = explode(' ', $statm);
					$map[$pid] = (int) ($fields[1] ?? 0) * 4; // pages * 4KB
				}
			}
		} elseif (PHP_OS_FAMILY === 'Darwin') {
			// macOS: single ps call for all PIDs
			$pidList = implode(',', $pids);
			$out = @shell_exec("ps -o pid=,rss= -p $pidList 2>/dev/null");
			if ($out) {
				foreach (explode("\n", trim($out)) as $line) {
					$parts = preg_split('/\s+/', trim($line));
					if (count($parts) >= 2) {
						$map[(int) $parts[0]] = (int) $parts[1]; // ps rss is in KB
					}
				}
			}
		} elseif (PHP_OS_FAMILY === 'Windows') {
			// Windows: tasklist for each PID
			foreach ($pids as $pid) {
				$out = @shell_exec("tasklist /FI \"PID eq $pid\" /FO CSV /NH 2>NUL");
				if ($out && preg_match('/"(\d[\d,]+)\s*K"/', $out, $m)) {
					$map[$pid] = (int) str_replace(',', '', $m[1]);
				}
			}
		}
		return $map;
	}

	// ── Wire helpers ─────────────────────────────────────

	protected static function readExact($sock, $n)
	{
		$buf = '';
		while (strlen($buf) < $n) {
			$c = fread($sock, $n - strlen($buf));
			if ($c === false || $c === '') {
				// '' means EOF only when feof() says so. A read timeout —
				// or a signal interrupting the read — also yields '', and
				// treating that as EOF kills a perfectly healthy worker.
				if (feof($sock)) return false;
				$meta = @stream_get_meta_data($sock);
				if (!empty($meta['timed_out'])) continue;
				return false;
			}
			$buf .= $c;
		}
		return $buf;
	}

	protected static function writeMsg($sock, $status, $body, $headers,
		$cookies = array())
	{
		// json_encode() returns false on bytes that are not valid UTF-8, and
		// strlen(false) is 0, so a binary body used to go out as a length
		// prefix of zero and nothing else: the parent read an empty frame and
		// answered with an empty response while still logging 200. Anything a
		// script generated that was not text -- an image, a PDF, a zip --
		// vanished silently. Base64 carries those bytes through, and only
		// those: text responses keep their exact previous shape and cost.
		//
		// The cookies travel with it: they are built in the worker and this
		// is the only thing that carries them out.
		$j = json_encode(compact('status', 'body', 'headers', 'cookies'));
		if ($j === false) {
			$b64 = true;
			$body = base64_encode($body);
			$j = json_encode(compact('status', 'body', 'headers', 'cookies', 'b64'));
		}
		if ($j === false) {
			// Headers themselves are not encodable. Say so rather than
			// hanging up on the client.
			$j = json_encode(array(
				'status' => 500,
				'body' => 'Response could not be encoded',
				'headers' => array('Content-Type' => 'text/plain')
			));
		}
		// The worker's reply is length-prefixed exactly like the request that
		// arrived, so a short write here desynchronises the parent's reader
		// instead of losing a few bytes: it takes the length we declared,
		// finds the next reply beginning inside this one, and every response
		// after it belongs to the wrong request.
		//
		// Written out rather than handed to Q_WebServer::writeAll(), which
		// ends by putting the stream back into non-blocking mode. That is
		// right for the event loop it was written for and wrong here:
		// childRun() sets this socket blocking on purpose and then waits on it
		// for the next request, so a worker that came back non-blocking read
		// an empty string and exited. The first request of each worker
		// succeeded and the second did not.
		//
		// The socket is already blocking, so a plain loop completes the write
		// without touching the mode.
		$packet = pack('N', strlen($j)) . $j;
		$length = strlen($packet);
		$written = 0;
		while ($written < $length) {
			$n = @fwrite($sock, substr($packet, $written));
			if ($n === false or $n === 0) break;
			$written += $n;
		}
	}
}

/**
 * Custom stream wrapper for php://input in persistent workers.
 * PHP's built-in php://input is empty in CLI SAPI for included scripts.
 * This wrapper reads from $GLOBALS['_Q_RAW_INPUT'] which the Pool sets
 * before each request.
 *
 * Only handles php://input — all other php:// streams pass through to
 * PHP's built-in handler.
 *
 * @class Q_WebServer_PhpInputStream
 */
class Q_WebServer_PhpInputStream
{
	protected $data = '';
	protected $pos = 0;
	protected $path = '';

	/**
	 * @var resource|null Fallback wrapper handle for non-input streams
	 */
	protected $fallback = null;

	function stream_open($path, $mode, $options, &$openedPath)
	{
		$this->path = $path;
		if ($path === 'php://input') {
			$this->data = $GLOBALS['_Q_RAW_INPUT'] ?? '';
			$this->pos = 0;
			return true;
		}
		// For php://stdout, php://stderr, php://temp, php://memory, etc.
		// restore the built-in wrapper, open, then re-register ours
		stream_wrapper_restore('php');
		$this->fallback = fopen($path, $mode);
		stream_wrapper_unregister('php');
		stream_wrapper_register('php', __CLASS__);
		return $this->fallback !== false;
	}

	function stream_read($count)
	{
		if ($this->fallback) return fread($this->fallback, $count);
		$chunk = substr($this->data, $this->pos, $count);
		$this->pos += strlen($chunk);
		return $chunk;
	}

	function stream_write($data)
	{
		if ($this->fallback) return fwrite($this->fallback, $data);
		return 0;
	}

	function stream_tell()
	{
		if ($this->fallback) return ftell($this->fallback);
		return $this->pos;
	}

	function stream_eof()
	{
		if ($this->fallback) return feof($this->fallback);
		return $this->pos >= strlen($this->data);
	}

	function stream_stat()
	{
		if ($this->fallback) return fstat($this->fallback);
		return ['size' => strlen($this->data)];
	}

	function stream_close()
	{
		if ($this->fallback) { fclose($this->fallback); $this->fallback = null; }
	}

	function stream_seek($offset, $whence = SEEK_SET)
	{
		if ($this->fallback) return fseek($this->fallback, $offset, $whence) === 0;
		switch ($whence) {
			case SEEK_SET: $this->pos = $offset; break;
			case SEEK_CUR: $this->pos += $offset; break;
			case SEEK_END: $this->pos = strlen($this->data) + $offset; break;
		}
		return true;
	}


}
