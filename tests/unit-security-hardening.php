<?php

/**
 * The medium and low findings of the 2026-09-24 security audit stay fixed.
 *
 *   - Request framing: a bare LF anywhere in the head (not only a head written
 *     wholly in LF), a bare CR, and obsolete line folding are refused with a
 *     400. A CRLF-framed request with a bare LF inside a header value used to
 *     pass, and the access log wrote the LF as a line break of its own.
 *   - The access log escapes control characters, quotes and backslashes in
 *     every value, as Apache does.
 *   - The response cache never stores a response that sets a cookie, and
 *     neither stores nor serves for a request carrying Authorization. A
 *     response with Set-Cookie was stored and handed, cookie and all, to the
 *     next visitor.
 *   - The dashboard no longer puts the Host header in its page (it was inside
 *     a JavaScript string, unescaped), URL-encodes a ?token= it passes on (a
 *     quote in one injected script by link), and no longer writes the
 *     configured dashboard token into the page for a caller who did not give it.
 *   - HPACK bounds the decoded header list, not only the compressed block, and
 *     refuses a table resize above what it advertised.
 *   - Trusted-proxy ranges work for IPv6 and for /0.
 *
 *   php tests/unit-security-hardening.php
 */

require __DIR__ . '/fixtures/race-harness.php';
list($base, $root) = rh_setup('security-hardening');
if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);

file_put_contents($root . DS . 'index.php', '<?php
if (isset($_GET["cookie"])) setcookie("sess", "secret-" . getmypid() . "-" . mt_rand());
header("Cache-Control: public, max-age=300");
echo "fresh-", uniqid("", true);
');

function raw($port, $bytes)
{
	$s = stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 5);
	fwrite($s, $bytes);
	stream_set_timeout($s, 10);
	$out = (string) stream_get_contents($s);
	fclose($s);
	return $out;
}
function statusOf($raw) { return preg_match('~^HTTP/1\.[01] (\d+)~', $raw, $m) ? (int) $m[1] : 0; }

// ── Framing ─────────────────────────────────────────────────────────────

$port = rh_start('framing', array('Q' => array('web' => array('cache' => array('enabled' => false)))), 1);
check('a well-formed request is served',
	statusOf(raw($port, "GET /index.php HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n")), 200);
check('a bare LF inside a header value is refused',
	statusOf(raw($port, "GET /index.php HTTP/1.1\r\nHost: x\r\nUser-Agent: a\nFORGED LOG LINE\r\nConnection: close\r\n\r\n")), 400);
check('a bare CR is refused',
	statusOf(raw($port, "GET /index.php HTTP/1.1\r\nHost: x\r\nUser-Agent: a\rb\r\nConnection: close\r\n\r\n")), 400);
check('obsolete line folding is refused',
	statusOf(raw($port, "GET /index.php HTTP/1.1\r\nHost: x\r\nX-A: one\r\n two\r\nConnection: close\r\n\r\n")), 400);
check('a head in bare LF throughout is still refused',
	statusOf(raw($port, "GET /index.php HTTP/1.1\nHost: x\nConnection: close\n\n")), 400);

// ── Access log escaping ─────────────────────────────────────────────────

require_once __DIR__ . '/../src/Q/WebServer/Log.php';
check('a line break is logged as \x0a', Q_WebServer_Log::logSafe("a\nb"), 'a\x0ab');
check('a quote and a backslash are escaped', Q_WebServer_Log::logSafe('say "hi" \\ bye'), 'say \"hi\" \\\\ bye');
check('a plain value is unchanged', Q_WebServer_Log::logSafe('Mozilla/5.0 (X11)'), 'Mozilla/5.0 (X11)');

// ── Cache: Set-Cookie and Authorization ─────────────────────────────────

$port = rh_start('cache', array('Q' => array('web' => array('cache' => array(
	'enabled' => true, 'dir' => $base . DS . 'cache', 'apcu' => array('enabled' => false))))), 1);
$a = rh_get($port, '/index.php?cookie=1');
$b = rh_get($port, '/index.php?cookie=1');
check('a response that sets a cookie is sent with it', isset($a['headers']['set-cookie']), true);
check('...but not stored: the next visitor is not handed it',
	($b['headers']['x-cache'] ?? '') !== 'HIT' and $a['body'] !== $b['body'], true);
$c = rh_get($port, '/index.php?plain=1');
$d = rh_get($port, '/index.php?plain=1');
check('an ordinary cacheable page is still cached', ($d['headers']['x-cache'] ?? ''), 'HIT');
$e = rh_get($port, '/index.php?plain=1', 15, 'GET', '', array('Authorization' => 'Bearer abc'));
check('a request with Authorization is not served from the cache', ($e['headers']['x-cache'] ?? '') !== 'HIT', true);
$f = rh_get($port, '/index.php?auth=1', 15, 'GET', '', array('Authorization' => 'Bearer abc'));
$g = rh_get($port, '/index.php?auth=1');
check('...nor is its response stored for others', ($g['headers']['x-cache'] ?? '') !== 'HIT' and $f['body'] !== $g['body'], true);

// ── Dashboard reflection ────────────────────────────────────────────────

$tok = 'tok-' . bin2hex(random_bytes(8));
$port = rh_start('dash', array('Q' => array('dashboard' => array('token' => $tok))), 1);
$r = raw($port, "GET /Q/dashboard HTTP/1.1\r\nHost: x'-alert(1)-'y\r\nConnection: close\r\n\r\n");
check('a hostile Host header does not reach the dashboard page', strpos($r, "x'-alert(1)-'y") === false, true);
$r = rh_get($port, "/Q/dashboard?token=" . rawurlencode("';alert(1)//"));
check('a ?token= with a quote is not written raw into the page', strpos($r['body'], "';alert(1)//") === false, true);
$r = rh_get($port, '/Q/dashboard');
check('the configured token is not written into the page for a caller who did not give it',
	$r['status'] === 200 and strpos($r['body'], $tok) === false, true);

// ── HPACK ───────────────────────────────────────────────────────────────

require_once __DIR__ . '/../src/Q/WebServer/Http2/Hpack.php';
$h = new Q_WebServer_Http2_Hpack();
// One 3000-byte entry added to the table, then referred to 60 times: a small
// block that decodes to ~182 KB.
$block = "\x40" . Q_WebServer_Http2_Hpack::encodeString('x-big') . Q_WebServer_Http2_Hpack::encodeString(str_repeat('a', 3000));
$block .= str_repeat("\xbe", 60);   // indexed, dynamic entry 62
$threw = false;
try { $h->decode($block); } catch (Exception $ex) { $threw = true; }
check('an HPACK block that decodes past the header list limit is refused', $threw, true);
check('...though it is small on the wire', strlen($block) < 4000, true);

$h = new Q_WebServer_Http2_Hpack();
$threw = false;
try { $h->decode("\x3f\xe2\x1f"); } catch (Exception $ex) { $threw = true; }   // size update to 4097
check('a table resize above the advertised size is refused', $threw, true);

$h = new Q_WebServer_Http2_Hpack();
$ok = $h->decode("\x82\x86\x84\x41\x0f" . 'www.example.com');   // RFC 7541 C.3.1
check('an ordinary header block still decodes', count($ok), 4);

// ── Proxy CIDR ──────────────────────────────────────────────────────────

require_once __DIR__ . '/../src/Q/WebServer/Proxy.php';
check('IPv4 in range', Q_WebServer_Proxy::ipInCidr('10.1.2.3', '10.0.0.0/8'), true);
check('IPv4 out of range', Q_WebServer_Proxy::ipInCidr('11.1.2.3', '10.0.0.0/8'), false);
check('IPv4 /0 matches everything', Q_WebServer_Proxy::ipInCidr('203.0.113.9', '0.0.0.0/0'), true);
check('IPv6 in range', Q_WebServer_Proxy::ipInCidr('2001:db8::1', '2001:db8::/32'), true);
check('IPv6 out of range', Q_WebServer_Proxy::ipInCidr('2001:db9::1', '2001:db8::/32'), false);
check('IPv6 prefix not on a byte boundary', Q_WebServer_Proxy::ipInCidr('fd12::1', 'fc00::/7'), true);
check('a v4 address never matches a v6 range', Q_WebServer_Proxy::ipInCidr('10.0.0.1', '::/0'), false);
check('a malformed range matches nothing', Q_WebServer_Proxy::ipInCidr('10.0.0.1', '10.0.0.0/abc'), false);

rh_finish();
