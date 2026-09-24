<?php

/**
 * The server's own pages carry icons, a web app manifest and link-preview
 * tags, and every one of those URLs answers.
 *
 * Before, the dashboard, documentation and control panel had none: a blank
 * tab icon, no home-screen image, a bare URL when a link was shared.
 *
 * Asserted against real servers, one with the default brand and one with a
 * custom brand and mark:
 *
 *   - /Q/favicon.svg, favicon.ico, icon-32/180/192/512.png, the manifest and
 *     og-image.png answer 200 with the right type and cache headers;
 *   - each PNG is a real image of the stated size; the ICO holds 16/32/48;
 *     the preview image is 1200 x 630;
 *   - the manifest is valid JSON naming the brand and its icons;
 *   - the dashboard, docs and panel <head> link all of it, with og:image an
 *     absolute URL on the request's own host, and brand text escaped;
 *   - the custom brand's mark uses its letter and colour;
 *   - an application's own /favicon.ico is left alone;
 *   - a hostile Host header never reaches og:image.
 *
 *   php tests/unit-brand-icons.php
 */

require __DIR__ . '/fixtures/race-harness.php';
if (!function_exists('imagecreatefromstring')) { printf("  skip  needs GD\n"); exit(0); }
list($base, $root) = rh_setup('brand-icons');
file_put_contents($root . DS . 'index.php', '<?php echo "app";');

function fetchRaw($port, $path, $host = null)
{
	$s = stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 5);
	fwrite($s, "GET $path HTTP/1.1\r\nHost: " . ($host ?? "127.0.0.1:$port") . "\r\nConnection: close\r\n\r\n");
	stream_set_timeout($s, 15);
	$raw = (string) stream_get_contents($s);
	fclose($s);
	return rh_parse($raw);
}

function pngSize($bytes)
{
	$i = @getimagesizefromstring($bytes);
	return $i ? $i[0] . 'x' . $i[1] . ' ' . $i['mime'] : 'not an image';
}

// ── A custom brand: letter V, 7x orange ─────────────────────────────────

$port = rh_start('custom', array('Q' => array('webserver' => array(
	'brand' => 'Exponential <Velocity>',
	'brandMark' => 'V',
	'brandColor' => '#ff7a1a',
	'brandAccent' => '#7c8aff',
	'maintainer' => '7x',
	'brandDescription' => 'Fast & "safe" PHP',
))), 1);

$want = array(
	'/Q/favicon.svg' => 'image/svg+xml',
	'/Q/favicon.ico' => 'image/x-icon',
	'/Q/icon-32.png' => 'image/png',
	'/Q/icon-180.png' => 'image/png',
	'/Q/icon-192.png' => 'image/png',
	'/Q/icon-512.png' => 'image/png',
	'/Q/manifest.webmanifest' => 'application/manifest+json',
	'/Q/og-image.png' => 'image/png',
);
$got = array();
foreach ($want as $path => $type) {
	$r = fetchRaw($port, $path);
	$got[$path] = $r;
	check("$path answers 200 as $type", $r['status'] . ' ' . strtok($r['headers']['content-type'] ?? '', ';'), "200 $type");
	check("...and may be cached", (bool) preg_match('/max-age=\d+/', $r['headers']['cache-control'] ?? ''), true);
}

foreach (array(32, 180, 192, 512) as $n) {
	check("icon-$n.png is a $n x $n PNG", pngSize($got["/Q/icon-$n.png"]['body']), "{$n}x{$n} image/png");
}
check('og-image.png is 1200 x 630', pngSize($got['/Q/og-image.png']['body']), '1200x630 image/png');

$ico = $got['/Q/favicon.ico']['body'];
$h = unpack('vres/vtype/vcount', substr($ico, 0, 6));
$sizes = array();
for ($i = 0; $i < ($h['count'] ?? 0); ++$i) {
	$e = unpack('Cw/Ch', substr($ico, 6 + 16 * $i, 2));
	$sizes[] = $e['w'];
}
check('favicon.ico is an icon holding 16, 32 and 48', array($h['type'] ?? 0, $sizes), array(1, array(16, 32, 48)));

$svg = $got['/Q/favicon.svg']['body'];
check('the SVG mark carries the configured letter', strpos($svg, '>V</text>') !== false, true);
check('...in the configured colour', strpos($svg, '#ff7a1a') !== false, true);
check('...and is well-formed XML', @simplexml_load_string($svg) !== false, true);

// The drawn icon really uses the colour: the mark's pixels include orange.
$img = imagecreatefromstring($got['/Q/icon-192.png']['body']);
$orange = 0;
for ($x = 60; $x < 180; $x += 3) for ($y = 40; $y < 170; $y += 3) {
	$c = imagecolorsforindex($img, imagecolorat($img, $x, $y));
	if ($c['red'] > 200 and $c['green'] > 90 and $c['green'] < 160 and $c['blue'] < 80) ++$orange;
}
check('the PNG icon is drawn with the mark colour', $orange > 20, true);

$m = json_decode($got['/Q/manifest.webmanifest']['body'], true);
check('the manifest is JSON naming the brand', $m['name'] ?? null, 'Exponential <Velocity>');
check('...and lists the 192 and 512 icons', count(array_filter($m['icons'] ?? array(), function ($i) {
	return in_array($i['sizes'], array('192x192', '512x512'), true); })) >= 2, true);

// The pages link it all.
foreach (array('/Q/dashboard' => 'Dashboard', '/Q/docs' => 'Documentation', '/Q/panel' => 'Control Panel') as $page => $label) {
	$r = fetchRaw($port, $page);
	$b = $r['body'];
	if ($r['status'] !== 200) { check("$page answers", $r['status'], 200); continue; }
	check("$page links the SVG icon", strpos($b, '<link rel="icon" href="/Q/favicon.svg"') !== false, true);
	check("$page links the apple-touch icon and manifest",
		strpos($b, 'rel="apple-touch-icon"') !== false and strpos($b, 'rel="manifest"') !== false, true);
	check("$page has og:image as an absolute URL on its own host",
		strpos($b, '<meta property="og:image" content="http://127.0.0.1:' . $port . '/Q/og-image.png">') !== false, true);
	check("$page has a large-image Twitter card", strpos($b, 'content="summary_large_image"') !== false, true);
	check("$page escapes the brand in its preview tags",
		strpos($b, 'Exponential &lt;Velocity&gt;') !== false and strpos($b, 'Exponential <Velocity>') === false, true);
	check("$page escapes the description", strpos($b, 'Fast &amp; &quot;safe&quot; PHP') !== false, true);
}

// A Host header an attacker chose never lands in og:image.
$r = fetchRaw($port, '/Q/docs', 'evil.example"><script>x</script>');
check('a malformed Host header does not reach og:image', strpos($r['body'], 'evil.example') === false, true);
check('...and nothing unescaped from it appears', strpos($r['body'], '<script>x</script>') === false, true);

// ── The default brand: the shipped logo, the app's favicon untouched ────

$port2 = rh_start('default', array(), 1);
$r = fetchRaw($port2, '/Q/icon-192.png');
check('with the default brand the icon is still a 192 PNG', pngSize($r['body']), '192x192 image/png');
$r = fetchRaw($port2, '/Q/favicon.svg');
check('...and the SVG is the shipped logo', $r['status'] === 200 and strpos($r['body'], '<svg') !== false, true);

file_put_contents($root . DS . 'favicon.ico', 'APP-ICON');
$r = fetchRaw($port2, '/favicon.ico');
check("an application's own /favicon.ico is served, not the server's", $r['body'], 'APP-ICON');

rh_finish();
