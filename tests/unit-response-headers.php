#!/usr/bin/env php
<?php
/**
 * Q.webserver.headers, Q.webserver.headersOnScripts and Q.webserver.hsts.
 *
 *   - the settings as values: invalid names and values and the reserved
 *     names left out, the forms of hsts (a number, an object, a string, off);
 *   - apply(): set only when missing, in any letter case; a script's
 *     response only with headersOnScripts; HSTS over TLS only, a domain's
 *     own record first;
 *   - real servers, HTTP/1.1 and, with TLS, HTTP/1.1 and HTTP/2: a static
 *     file (twice, so the in-memory copy is checked too), a 304, the server's
 *     own 404 and a script's response carry the headers; the script's own
 *     X-Frame-Options wins; HSTS never over plain HTTP; with
 *     headersOnScripts off a script keeps only what it sent, plus HSTS.
 *
 *   php tests/unit-response-headers.php
 */
if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q.php';
require_once __DIR__ . '/../src/Q/WebServer/Layout.php';
require_once __DIR__ . '/../src/Q/WebServer/Panel.php';
require_once __DIR__ . '/../src/Q/WebServer/Panel/Store.php';
require_once __DIR__ . '/../src/Q/WebServer/Domains.php';
require_once __DIR__ . '/../src/Q/WebServer/DomainUsage.php';
require_once __DIR__ . '/../src/Q/WebServer.php';
require_once __DIR__ . '/../src/Q/WebServer/ResponseHeaders.php';
require_once __DIR__ . '/fixtures/race-harness.php';

// A private panel store before anything looks a domain up, so the default
// one beside the sources is never read or created. It wants root.
$D = 'Q_WebServer_Domains';
$isRoot = function_exists('posix_geteuid') and posix_geteuid() === 0;
$dbase = sys_get_temp_dir() . DS . 'qbix-resphdr-store-' . getmypid();
@mkdir("$dbase/site", 0755, true);
chmod($dbase, 0755);
Q_Config::set('Q', 'panel', 'aclDir', "$dbase/acl");
Q_Config::set('Q', 'panel', 'sessionsDir', "$dbase/sessions");
Q_Config::set('Q', 'webserver', 'domains', array());
Q_WebServer_Panel_Store::reset();
if ($isRoot) {
	Q_WebServer_Panel_Store::dirTrusted("$dbase/acl", true, $why);
	Q_WebServer_Panel_Store::dirTrusted("$dbase/sessions", true, $why);
}

$pass = 0; $fail = 0;
function ck($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}

$R = 'Q_WebServer_ResponseHeaders';
$four = array(
	'X-Content-Type-Options' => 'nosniff',
	'X-Frame-Options' => 'SAMEORIGIN',
	'Referrer-Policy' => 'strict-origin-when-cross-origin',
	'Permissions-Policy' => 'camera=(), microphone=(), payment=(), usb=()',
);

// ── The settings ─────────────────────────────────────────────────────────
Q_Config::set('Q', 'webserver', 'headers', $four + array(
	'Bad Name' => 'x', "X-Split" => "a\r\nInjected: yes", 'Content-Length' => '1',
	'Connection' => 'close', 'Strict-Transport-Security' => 'max-age=1', 'X-Empty' => '',
	'X-Array' => array('a'), 'X-Number' => 7,
));
ck('configured(): the valid ones, in order', $R::configured(), $four + array('X-Number' => '7'));
Q_Config::set('Q', 'webserver', 'headers', 'nosniff');
ck('configured(): not an object -> none', $R::configured(), array());
Q_Config::set('Q', 'webserver', 'headers', $four);

ck('hsts: a number is max-age', $R::hstsValue(300), 'max-age=300');
ck('hsts: digits in a string too', $R::hstsValue('300'), 'max-age=300');
ck('hsts: an object', $R::hstsValue(array('maxAge' => 600, 'includeSubDomains' => true, 'preload' => true)),
	'max-age=600; includeSubDomains; preload');
ck('hsts: an object turned off', $R::hstsValue(array('enabled' => false, 'maxAge' => 600)), null);
ck('hsts: an object without max-age', $R::hstsValue(array('includeSubDomains' => true)), null);
ck('hsts: a string as it is', $R::hstsValue('max-age=60; includeSubDomains'), 'max-age=60; includeSubDomains');
ck('hsts: a string without max-age', $R::hstsValue('includeSubDomains'), null);
ck('hsts: a string with a line break', $R::hstsValue("max-age=60\r\nX: y"), null);
foreach (array(0, '0', false, null, '', -5) as $off) {
	ck('hsts: off for ' . var_export($off, true), $R::hstsValue($off), null);
}

// ── apply() ──────────────────────────────────────────────────────────────
Q_Config::set('Q', 'webserver', 'hsts', array('maxAge' => 300));
Q_Config::set('Q', 'webserver', 'headersOnScripts', false);

$h = array('Content-Type' => 'text/html');
$R::apply($h, 'x.test', false);
ck('apply(): the server\'s own response over HTTP gets the four, no HSTS', $h, array('Content-Type' => 'text/html') + $four);

$h = array('x-frame-options' => 'DENY');
$R::apply($h, 'x.test', true);
ck('apply(): over HTTPS HSTS too; a header present in another case is kept',
	$h, array('x-frame-options' => 'DENY', 'Strict-Transport-Security' => 'max-age=300')
		+ array_diff_key($four, array('X-Frame-Options' => 1)));

$h = array('Strict-Transport-Security' => 'max-age=5');
$R::apply($h, 'x.test', true, true);
ck('apply(): a script without headersOnScripts: only HSTS, and not over its own', $h, array('Strict-Transport-Security' => 'max-age=5'));

$h = array();
$R::apply($h, 'x.test', true, true);
ck('apply(): a script without headersOnScripts gets HSTS over HTTPS', $h, array('Strict-Transport-Security' => 'max-age=300'));

Q_Config::set('Q', 'webserver', 'headersOnScripts', true);
$h = array('X-Frame-Options' => 'DENY');
$R::apply($h, 'x.test', false, true);
ck('apply(): headersOnScripts adds what the script did not send',
	$h, array('X-Frame-Options' => 'DENY') + array_diff_key($four, array('X-Frame-Options' => 1)));
Q_Config::set('Q', 'webserver', 'headersOnScripts', 'enabled');
ck('headersOnScripts: "enabled" means on', $R::onScripts(), true);
Q_Config::set('Q', 'webserver', 'headersOnScripts', 'no');
ck('headersOnScripts: "no" means off', $R::onScripts(), false);

ck('lines(): leaves out what the block has', $R::lines(array('x-frame-options', 'referrer-policy')),
	"X-Content-Type-Options: nosniff\r\nPermissions-Policy: camera=(), microphone=(), payment=(), usb=()\r\n");

// A domain's own HSTS record comes first. The store refuses a directory
// below one another user may write to, a TMPDIR inside a checkout say.
if ($isRoot and $D::setRoot('own.test', "$dbase/site")) {
	$D::setHsts('own.test', array('enabled' => true, 'maxAge' => 900));
	$D::forget();
	ck('hstsFor(): a domain\'s own record', $R::hstsFor('own.test', true), 'max-age=900');
	ck('hstsFor(): any other host, the server-wide one', $R::hstsFor('other.test', true), 'max-age=300');
	ck('hstsFor(): no host, the server-wide one', $R::hstsFor(null, true), 'max-age=300');
	ck('hstsFor(): never over HTTP', $R::hstsFor('own.test', false), null);
	$h = array();
	$R::apply($h, 'own.test', true);
	ck('apply(): the domain\'s record rather than the server-wide one', $h['Strict-Transport-Security'] ?? null, 'max-age=900');
	Q_Config::set('Q', 'webserver', 'hsts', null);
	ck('hstsFor(): no setting, no domain record: none', $R::hstsFor('other.test', true), null);
	$D::setHsts('own.test', null);
	$D::forget();
} else {
	printf("  skip  a domain's own HSTS record: the panel store needs root and a trusted directory\n");
}
Q_Config::set('Q', 'webserver', 'hsts', null);
rh_rmtree($dbase);

// ── Real servers ─────────────────────────────────────────────────────────
function rawReq($port, $path, $extra = '')
{
	$s = @stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 5);
	if (!$s) return array(0, array());
	stream_set_timeout($s, 10);
	fwrite($s, "GET $path HTTP/1.1\r\nHost: x.test\r\nConnection: close\r\n$extra\r\n");
	$raw = (string) stream_get_contents($s);
	fclose($s);
	$head = explode("\r\n\r\n", $raw, 2)[0];
	$status = preg_match('#^HTTP/1\.[01] (\d{3})#', $raw, $m) ? (int) $m[1] : 0;
	$headers = array();
	foreach (array_slice(explode("\r\n", $head), 1) as $line) {
		$c = strpos($line, ':');
		if ($c !== false) $headers[strtolower(trim(substr($line, 0, $c)))][] = trim(substr($line, $c + 1));
	}
	return array($status, $headers);
}
/** The headers of interest, one value each, or a list where a name came twice. */
function pick($headers)
{
	$out = array();
	foreach (array('x-content-type-options', 'x-frame-options', 'referrer-policy', 'permissions-policy', 'strict-transport-security') as $n) {
		if (!isset($headers[$n])) continue;
		$out[$n] = count($headers[$n]) === 1 ? $headers[$n][0] : $headers[$n];
	}
	return $out;
}
$lower = array_change_key_case($four, CASE_LOWER);
$hstsOn = array('strict-transport-security' => 'max-age=300');
$scriptLower = array_merge($lower, array('x-frame-options' => 'DENY'));

if (function_exists('pcntl_fork')) {
	require_once __DIR__ . '/fixtures/race-harness.php';
	list($rbase, $root) = rh_setup('resphdr');
	file_put_contents($root . DS . 'index.php', '<?php header("X-Frame-Options: DENY"); echo "SCRIPT";');
	file_put_contents($root . DS . 'hello.txt', 'static');
	@mkdir($root . DS . '.git');
	file_put_contents($root . DS . '.git' . DS . 'config', 'secret');
	$h2ok = function_exists('curl_init') && defined('CURL_HTTP_VERSION_2TLS') && (curl_version()['features'] & CURL_VERSION_HTTP2);

	foreach (array(true, false) as $onScripts) {
		$tls = rh_free_port();
		$GLOBALS['rh_extra_args'] = array('--https-port=' . $tls);
		$name = $onScripts ? 'on' : 'off';
		$port = rh_start($name, array('Q' => array(
			'web' => array('http2' => array('enabled' => true)),
			'webserver' => array('headers' => $four, 'headersOnScripts' => $onScripts, 'hsts' => 300),
		)), 2);
		$GLOBALS['rh_extra_args'] = array();
		$tag = $onScripts ? '' : ' (headersOnScripts off)';

		list($st, $hd) = rawReq($port, '/hello.txt');
		ck("HTTP/1.1: a static file carries the four, no HSTS$tag", array($st, pick($hd)), array(200, $lower));
		list($st, $hd) = rawReq($port, '/hello.txt');
		ck("HTTP/1.1: ...from the in-memory copy too$tag", array($st, pick($hd)), array(200, $lower));
		$etag = $hd['etag'][0] ?? '';
		list($st, $hd) = rawReq($port, '/hello.txt', "If-None-Match: $etag\r\n");
		ck("HTTP/1.1: the 304 too$tag", array($st, pick($hd)), array(304, $lower));
		list($st, $hd) = rawReq($port, '/.git/config');
		ck("HTTP/1.1: the server's own 403$tag", array($st, pick($hd)), array(403, $lower));
		list($st, $hd) = rawReq($port, '/');
		ck("HTTP/1.1: a script's response$tag", array($st, pick($hd)),
			array(200, $onScripts ? $scriptLower : array('x-frame-options' => 'DENY')));

		if ($h2ok) {
			$h2 = function ($path, $version = CURL_HTTP_VERSION_2TLS) use ($tls) {
				$ch = curl_init("https://x.test:$tls$path");
				curl_setopt_array($ch, array(
					CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_HTTP_VERSION => $version,
					CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_TIMEOUT => 15,
					CURLOPT_RESOLVE => array("x.test:$tls:127.0.0.1"),
				));
				$raw = (string) curl_exec($ch);
				$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
				$hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
				$isH2 = curl_getinfo($ch, CURLINFO_HTTP_VERSION) === CURL_HTTP_VERSION_2_0;
				curl_close($ch);
				$headers = array();
				foreach (explode("\r\n", substr($raw, 0, $hsize)) as $line) {
					$c = strpos($line, ':');
					if ($c !== false) $headers[strtolower(trim(substr($line, 0, $c)))][] = trim(substr($line, $c + 1));
				}
				return array($status, $headers, $isH2);
			};
			foreach (array('HTTP/2' => CURL_HTTP_VERSION_2TLS, 'HTTP/1.1 over TLS' => CURL_HTTP_VERSION_1_1) as $label => $v) {
				list($st, $hd, $isH2) = $h2('/hello.txt', $v);
				ck("$label: the protocol asked for$tag", $isH2, $v === CURL_HTTP_VERSION_2TLS);
				ck("$label: a static file carries the four and HSTS$tag", array($st, pick($hd)), array(200, $lower + $hstsOn));
				list($st, $hd) = $h2('/hello.txt', $v);
				ck("$label: ...again$tag", array($st, pick($hd)), array(200, $lower + $hstsOn));
				list($st, $hd) = $h2('/.git/config', $v);
				ck("$label: the server's own 403$tag", array($st, pick($hd)), array(403, $lower + $hstsOn));
				list($st, $hd) = $h2('/', $v);
				ck("$label: a script's response$tag", array($st, pick($hd)),
					array(200, ($onScripts ? $scriptLower : array('x-frame-options' => 'DENY')) + $hstsOn));
			}
		} else {
			printf("  skip  HTTP/2 and TLS: this PHP's curl has no HTTP/2\n");
		}
		rh_stop($GLOBALS['rh']['servers'][$name]);
	}

	// Nothing configured: nothing added, as before.
	$tls = rh_free_port();
	$GLOBALS['rh_extra_args'] = array('--https-port=' . $tls);
	$port = rh_start('none', array('Q' => array('web' => array('http2' => array('enabled' => true)))), 2);
	$GLOBALS['rh_extra_args'] = array();
	list($st, $hd) = rawReq($port, '/hello.txt');
	ck('no settings: a static file gets none of them', array($st, pick($hd)), array(200, array()));
	list($st, $hd) = rawReq($port, '/.git/config');
	ck('no settings: nor its own 403', array($st, pick($hd)), array(403, array()));
	if ($h2ok) {
		$ch = curl_init("https://x.test:$tls/hello.txt");
		curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_NOBODY => false,
			CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_TIMEOUT => 15,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2TLS, CURLOPT_RESOLVE => array("x.test:$tls:127.0.0.1")));
		$raw = (string) curl_exec($ch);
		curl_close($ch);
		ck('no settings: no HSTS over HTTPS without a domain record', stripos($raw, 'strict-transport-security'), false);
	}
	rh_stop($GLOBALS['rh']['servers']['none']);
}

printf("%s - %d case(s)%s\n", $fail ? '  FAIL' : '  PASS', $pass + $fail, $fail ? " ($fail failed)" : '');
exit($fail ? 1 : 0);
