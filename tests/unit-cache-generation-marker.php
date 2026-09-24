<?php

/**
 * Touching the cache's generation marker invalidates every stored page.
 *
 * The response cache revalidates against what the application says about a
 * page, and an application judges that by its content: a template or a
 * stylesheet changed on disk moves nothing it looks at. So a page rendered
 * from the old template kept being served, and nothing short of deleting the
 * cache directory -- which does not reach APCu -- could say "all of it".
 *
 * Asserted against a running server with the cache on:
 *   - a page is cached, and a change behind it is not seen while the entry
 *     lives (the premise);
 *   - after the marker is touched, the next request renders the page afresh
 *     and caches the new version;
 *   - a marker that is never touched changes nothing.
 *
 *   php tests/unit-cache-generation-marker.php
 */

require __DIR__ . '/fixtures/race-harness.php';
rh_require_pool();
list($base, $root) = rh_setup('cache-generation');

$data = $base . DS . 'data.txt';
file_put_contents($root . DS . 'page.php', '<?php header("Cache-Control: public, max-age=300"); echo file_get_contents(' . var_export($data, true) . ');');
file_put_contents($data, 'first');

$cacheDir = $base . DS . 'cache';
$marker = $cacheDir . DS . '.generation';
$port = rh_start('cg', array('Q' => array('web' => array('cache' => array(
	'enabled' => true, 'dir' => $cacheDir, 'defaultTtl' => 300,
	'apcu' => array('enabled' => false),
)))), 2);

function page($port) { $r = rh_get($port, '/page.php'); return trim($r['body']) . ' ' . ($r['headers']['x-cache'] ?? 'MISS'); }

check('the first request renders the page', page($port), 'first MISS');
check('the second is served from the cache', page($port), 'first HIT');

file_put_contents($data, 'second');
check('a change behind a cached page is not seen while the entry lives (the premise)', page($port), 'first HIT');

// Touched from outside the server, as a deploy or exp:velocity would. Kept a
// second clear of the entry's store time, which counts as older either way.
sleep(1);
touch($marker);
clearstatcache();
sleep(1);   // the server reads the marker at most once a second
check('after the marker is touched the next request renders afresh', page($port), 'second MISS');
check('...and the new version is what is cached from then on', page($port), 'second HIT');

file_put_contents($data, 'third');
check('an untouched marker changes nothing: the cache still serves what it holds', page($port), 'second HIT');

rh_finish();
