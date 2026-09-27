<?php
/**
 * Which cache answers first. The application's own cache (Q.web.appCache)
 * knows who the visitor is, so it answers the requests with a session cookie
 * (or credentials) that the server's response cache skips. For every other
 * request the server's cache answers first -- it is the faster of the two --
 * and the application's is asked only when it misses.
 *
 *   php tests/unit-app-cache-order.php
 */

require __DIR__ . '/fixtures/cache-stubs.php';
require __DIR__ . '/../src/Q/WebServer/AppCache.php';

$pass = 0;
$fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what,
		var_export($got, true), var_export($want, true));
}

class OrderAppCache
{
	static $asked = 0;
	static function fromDir($dir) { return new self(); }
	function serve(array $request)
	{
		self::$asked++;
		$h = array('X-From' => 'app', 'Content-Type' => 'text/html', 'Cache-Control' => 'public, max-age=300');
		if (strpos($request['uri'], 'gz') !== false) $h['Content-Encoding'] = 'gzip';
		if (strpos($request['uri'], 'br') !== false) $h['Content-Encoding'] = 'br';
		// Whitespace a minifier would take out: a compressed body must be kept as it came.
		return array(200, $h, isset($h['Content-Encoding']) ? "zz   \n\n   zz" : 'app page');
	}
}

$C = 'Q_WebServer_Cache';
cache_init(array('dir' => cache_tmpdir('app-order'), 'skip' => array('cookies' => array('eZSESSID'))));
Q_WebServer_AppCache::$class = 'OrderAppCache';
Q_WebServer_AppCache::$dir = '/unused';

$C::put(cache_req('/stored'), cache_page());

OrderAppCache::$asked = 0;
$r = $C::get(cache_req('/stored'));
check('no session cookie, stored here: the server cache answers', ($r['headers']['X-From'] ?? 'server'), 'server');
check('...and the application cache is not asked', OrderAppCache::$asked, 0);

OrderAppCache::$asked = 0;
$r = $C::get(cache_req('/not-stored'));
check('no session cookie, not stored here: the application cache answers', $r['headers']['X-From'] ?? null, 'app');
check('...asked once', OrderAppCache::$asked, 1);

OrderAppCache::$asked = 0;
$r = $C::get(cache_req('/stored', array('cookie' => 'eZSESSIDab12=x')));
check('with a session cookie: the application cache answers', $r['headers']['X-From'] ?? null, 'app');

OrderAppCache::$asked = 0;
$r = $C::get(cache_req('/stored', array('authorization' => 'Bearer x')));
check('with credentials: the application cache answers', $r['headers']['X-From'] ?? null, 'app');


// The application's answer to a request without a session is kept here, so
// the next one does not ask it again.
OrderAppCache::$asked = 0;
$C::get(cache_req('/fill-me'));
$r = $C::get(cache_req('/fill-me'));
check('after the application answered once, this cache answers', OrderAppCache::$asked, 1);
check('...the same page', $r['body'] ?? null, 'app page');

// A gzip answer to a browser (which asks for gzip) is kept here: it is the
// coding this cache stores that request in.
OrderAppCache::$asked = 0;
$gz = array('accept-encoding' => 'gzip, deflate, br, zstd');
$C::get(cache_req('/gz-page', $gz));
$r = $C::get(cache_req('/gz-page', $gz));
check('a gzip answer to a gzip request is kept here', OrderAppCache::$asked, 1);
check('...and served from here with its coding', strtolower($r['headers']['Content-Encoding'] ?? ''), 'gzip');
check('...its body as it came (not minified)', $r['body'] ?? null, "zz   \n\n   zz");

// A coding this cache does not store in is left to the application's cache.
OrderAppCache::$asked = 0;
$C::get(cache_req('/br-page', $gz));
$C::get(cache_req('/br-page', $gz));
check('a br answer is not kept here', OrderAppCache::$asked, 2);

// A gzip answer to a client that did not ask for gzip is not kept either.
OrderAppCache::$asked = 0;
$C::get(cache_req('/gz-plain'));
$C::get(cache_req('/gz-plain'));
check('a gzip answer to a request without gzip is not kept', OrderAppCache::$asked, 2);

check('contentCoding: none', $C::contentCoding(array('Content-Type' => 'text/html')), '');
check('contentCoding: identity is none', $C::contentCoding(array('content-encoding' => 'Identity')), '');
check('contentCoding: gzip', $C::contentCoding(array('Content-Encoding' => ' GZIP ')), 'gzip');
Q_WebServer_AppCache::$class = null;
check('no application cache, session cookie: nothing', $C::get(cache_req('/stored', array('cookie' => 'eZSESSIDab12=x'))), null);
check('no application cache, no cookie: the server cache still answers', is_array($C::get(cache_req('/stored'))), true);

echo "\n";
if ($fail === 0) { printf("  PASS - %d case(s)\n\n", $pass); exit(0); }
printf("  FAIL - %d of %d\n\n", $fail, $pass + $fail);
exit(1);
