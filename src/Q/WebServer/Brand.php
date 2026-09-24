<?php

/**
 * Icons, web app manifest and link-preview (OpenGraph / Twitter card) data
 * for the server's own pages: the dashboard, the documentation and the
 * control panel.
 *
 * Those pages had none. A browser tab showed a blank page icon, a bookmark or
 * home-screen shortcut had no image, and a link pasted into a chat showed a
 * bare URL -- for a product that otherwise carries its name everywhere
 * (Q.webserver.brand).
 *
 * What the icon is:
 *
 *   - Q.webserver.brandIcon: a file (.svg, .png, .jpg, .ico) used as is and,
 *     for a raster file, resampled for every size;
 *   - otherwise, with the default brand and no mark set, the Qbix logo this
 *     server already ships (src/Q/logo.png, logo.svg);
 *   - otherwise a mark drawn here: a rounded tile with one letter
 *     (Q.webserver.brandMark, default the brand's first letter) in
 *     Q.webserver.brandColor, and three speed streaks in
 *     Q.webserver.brandAccent. One geometry, drawn as SVG and with GD, so
 *     every size is the same picture.
 *
 * Routes, all under /Q/ so an application's own /favicon.ico is never
 * touched:
 *
 *   /Q/favicon.svg  /Q/favicon.ico  /Q/icon-32.png  /Q/icon-180.png
 *   /Q/icon-192.png  /Q/icon-512.png  /Q/manifest.webmanifest
 *   /Q/og-image.png (1200 x 630)
 *
 * headTags() returns the <link>/<meta> block the pages put in their <head>.
 * The preview image URL must be absolute; the page's own scheme and host are
 * used, taken from the request that asked for it (noteRequest()).
 *
 * @class Q_WebServer_Brand
 * @static
 */
class Q_WebServer_Brand
{
	/** Generated images, by name, for the life of the process. */
	protected static $cache = array();

	/** Scheme and host of the request being answered, for absolute URLs. */
	protected static $origin = '';

	const ROUTES = array(
		'/Q/favicon.svg' => 'svg',
		'/Q/favicon.ico' => 'ico',
		'/Q/icon-32.png' => 32,
		'/Q/icon-48.png' => 48,
		'/Q/icon-180.png' => 180,
		'/Q/icon-192.png' => 192,
		'/Q/icon-512.png' => 512,
		'/Q/manifest.webmanifest' => 'manifest',
		'/Q/og-image.png' => 'og',
	);

	/**
	 * One setting under Q.webserver, as a trimmed string.
	 * @method setting
	 * @static
	 */
	static function setting($key, $default = '')
	{
		$v = class_exists('Q_Config', false)
			? Q_Config::get('Q', 'webserver', $key, $default) : $default;
		return is_string($v) ? trim($v) : (string) $default;
	}

	/** The product name (Q.webserver.brand). */
	static function name()
	{
		return class_exists('Q_WebServer', false) ? Q_WebServer::brand() : 'Qbix Server';
	}

	/** One line describing the product, for previews and the manifest. */
	static function description()
	{
		$d = self::setting('brandDescription');
		return $d !== '' ? $d : self::name() . ' — a PHP application server with persistent workers';
	}

	/** A #rgb / #rrggbb colour setting, or the default when invalid. */
	static function colour($key, $default)
	{
		$v = self::setting($key);
		return preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $v) ? strtolower($v) : $default;
	}

	static function markColour()   { return self::colour('brandColor', '#7c8aff'); }
	static function accentColour() { return self::colour('brandAccent', '#22d3ee'); }
	static function tileColour()   { return self::colour('brandBackground', '#0f1117'); }

	/** The letter on the mark: Q.webserver.brandMark, else the brand's first letter. */
	static function markLetter()
	{
		$m = self::setting('brandMark');
		if ($m === '') $m = self::name();
		if (preg_match('/[\p{L}\p{N}]/u', $m, $x)) {
			return function_exists('mb_strtoupper') ? mb_strtoupper($x[0], 'UTF-8') : strtoupper($x[0]);
		}
		return 'Q';
	}

	/** A configured icon file, when readable. */
	static function iconFile()
	{
		$f = self::setting('brandIcon');
		return ($f !== '' and is_file($f) and is_readable($f)) ? $f : '';
	}

	/** Whether to use the Qbix logo the server ships: default brand, nothing configured. */
	static function usesShippedLogo()
	{
		return self::iconFile() === '' and self::setting('brandMark') === ''
			and self::name() === 'Qbix Server' and is_file(dirname(__DIR__) . '/logo.png');
	}

	/**
	 * Remember the scheme and host of the request being answered.
	 * @method noteRequest
	 * @static
	 * @param {array} $parsed the parsed request
	 * @param {boolean} $tls whether it arrived over TLS
	 */
	static function noteRequest($parsed, $tls)
	{
		$host = (string) ($parsed['headers']['host'] ?? '');
		// A Host header is the client's to write: only a plausible host[:port].
		if (!preg_match('/^[a-z0-9.\-]+(:\d{1,5})?$/i', $host)
			and !preg_match('/^\[[0-9a-f:.]+\](:\d{1,5})?$/i', $host)) {
			$host = '';
		}
		self::$origin = $host === '' ? '' : ($tls ? 'https://' : 'http://') . $host;
	}

	// ── Responses ───────────────────────────────────────────────────────

	/**
	 * The response for one of the brand routes, or null for any other path.
	 * Shared by the HTTP/1.1 and HTTP/2 paths.
	 *
	 * @method route
	 * @static
	 * @param {string} $path
	 * @return {array|null} status, body, headers
	 */
	static function route($path)
	{
		if (!isset(self::ROUTES[$path])) return null;
		$what = self::ROUTES[$path];
		try {
			if ($what === 'manifest') {
				return self::respond(self::manifest(), 'application/manifest+json; charset=utf-8', 3600);
			}
			if ($what === 'svg') {
				list($body, $type) = self::svgIcon();
				return self::respond($body, $type, 86400);
			}
			if ($what === 'ico') {
				return self::respond(self::ico(), 'image/x-icon', 86400);
			}
			if ($what === 'og') {
				$body = self::ogImage();
				return $body === '' ? null : self::respond($body, 'image/png', 86400);
			}
			$body = self::png((int) $what);
			return $body === '' ? null : self::respond($body, 'image/png', 86400);
		} catch (\Throwable $e) {
			return array('status' => 500, 'body' => 'icon unavailable',
				'headers' => array('Content-Type' => 'text/plain; charset=utf-8'));
		}
	}

	protected static function respond($body, $type, $maxAge)
	{
		return array('status' => 200, 'body' => $body, 'headers' => array(
			'Content-Type' => $type,
			'Cache-Control' => 'public, max-age=' . (int) $maxAge,
			'ETag' => '"' . substr(md5($body), 0, 16) . '"',
		));
	}

	/**
	 * The <head> block for one of the server's pages.
	 *
	 * @method headTags
	 * @static
	 * @param {string} $title the page title, as shown in the preview
	 * @param {string} [$path] the page's path, for og:url
	 * @return {string} HTML, every value escaped
	 */
	static function headTags($title, $path = '')
	{
		$e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
		$name = self::name();
		$desc = self::description();
		$o = self::$origin;
		$tags = array(
			'<link rel="icon" href="/Q/favicon.svg" type="image/svg+xml">',
			'<link rel="icon" href="/Q/icon-32.png" sizes="32x32" type="image/png">',
			'<link rel="alternate icon" href="/Q/favicon.ico" sizes="16x16 32x32 48x48">',
			'<link rel="apple-touch-icon" href="/Q/icon-180.png">',
			'<link rel="manifest" href="/Q/manifest.webmanifest">',
			'<meta name="theme-color" content="' . $e(self::tileColour()) . '">',
			'<meta name="description" content="' . $e($desc) . '">',
			'<meta property="og:type" content="website">',
			'<meta property="og:site_name" content="' . $e($name) . '">',
			'<meta property="og:title" content="' . $e($title) . '">',
			'<meta property="og:description" content="' . $e($desc) . '">',
			'<meta name="twitter:card" content="summary_large_image">',
			'<meta name="twitter:title" content="' . $e($title) . '">',
			'<meta name="twitter:description" content="' . $e($desc) . '">',
		);
		if ($o !== '') {
			$tags[] = '<meta property="og:image" content="' . $e($o . '/Q/og-image.png') . '">';
			$tags[] = '<meta property="og:image:width" content="1200">';
			$tags[] = '<meta property="og:image:height" content="630">';
			$tags[] = '<meta property="og:image:alt" content="' . $e($name) . '">';
			$tags[] = '<meta name="twitter:image" content="' . $e($o . '/Q/og-image.png') . '">';
			if ($path !== '') $tags[] = '<meta property="og:url" content="' . $e($o . $path) . '">';
		}
		return implode("\n", $tags) . "\n";
	}

	/** The web app manifest, as JSON. */
	static function manifest()
	{
		return json_encode(array(
			'name' => self::name(),
			'short_name' => self::name(),
			'description' => self::description(),
			'start_url' => '/Q/dashboard',
			'scope' => '/Q/',
			'display' => 'standalone',
			'background_color' => self::tileColour(),
			'theme_color' => self::tileColour(),
			'icons' => array(
				array('src' => '/Q/favicon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml'),
				array('src' => '/Q/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'),
				array('src' => '/Q/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'),
				array('src' => '/Q/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'),
			),
		), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	// ── The mark ────────────────────────────────────────────────────────
	//
	// On a 64 x 64 grid: a tile with corner radius 14, three streaks, and the
	// letter centred a little right of the middle, slanted as if moving.

	/** The speed streaks, each array(x1, y, x2) on the 64-unit grid. */
	const STREAKS = array(array(9, 22, 21), array(5, 32, 19), array(9, 42, 21));

	/** The SVG icon and its type. */
	static function svgIcon()
	{
		$f = self::iconFile();
		if ($f !== '') {
			$ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
			if ($ext === 'svg') return array((string) file_get_contents($f), 'image/svg+xml');
			// A raster icon: an SVG that shows it, so /Q/favicon.svg always works.
			return array(self::svgWrapping(self::png(64), 64), 'image/svg+xml');
		}
		if (self::usesShippedLogo() and is_file(dirname(__DIR__) . '/logo.svg')) {
			return array((string) file_get_contents(dirname(__DIR__) . '/logo.svg'), 'image/svg+xml');
		}
		return array(self::markSvg(), 'image/svg+xml');
	}

	protected static function svgWrapping($png, $size)
	{
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $size . ' ' . $size . '">'
			. '<image width="' . $size . '" height="' . $size . '" href="data:image/png;base64,'
			. base64_encode($png) . '"/></svg>';
	}

	/** The drawn mark as SVG. */
	static function markSvg()
	{
		$e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8'); };
		$s = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
			. '<rect width="64" height="64" rx="14" fill="' . $e(self::tileColour()) . '"/>'
			. '<g stroke="' . $e(self::accentColour()) . '" stroke-width="4" stroke-linecap="round">';
		foreach (self::STREAKS as $st) {
			list($y, $x1, $x2) = array($st[1], $st[0], $st[2]);
			$s .= '<line x1="' . $x1 . '" y1="' . $y . '" x2="' . $x2 . '" y2="' . $y . '"/>';
		}
		$s .= '</g><text x="41" y="46" text-anchor="middle" font-family="DejaVu Sans,Segoe UI,Helvetica,Arial,sans-serif"'
			. ' font-size="38" font-weight="800" font-style="italic" fill="' . $e(self::markColour()) . '">'
			. $e(self::markLetter()) . '</text></svg>';
		return $s;
	}

	/** A bold TrueType font, when there is one. */
	static function font()
	{
		$candidates = array(self::setting('brandFont'),
			'/usr/share/fonts/dejavu-sans-fonts/DejaVuSans-BoldOblique.ttf',
			'/usr/share/fonts/dejavu-sans-fonts/DejaVuSans-Bold.ttf',
			'/usr/share/fonts/truetype/dejavu/DejaVuSans-BoldOblique.ttf',
			'/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
			'/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
			'/usr/local/share/fonts/dejavu/DejaVuSans-Bold.ttf',
			'/System/Library/Fonts/Supplemental/Arial Bold.ttf',
			'C:\\Windows\\Fonts\\arialbd.ttf');
		foreach ($candidates as $f) {
			if ($f !== '' and is_file($f)) return $f;
		}
		return '';
	}

	/** A regular-weight font for body text on the preview image. */
	static function textFont()
	{
		foreach (array('/usr/share/fonts/dejavu-sans-fonts/DejaVuSans.ttf',
			'/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', '/usr/share/fonts/TTF/DejaVuSans.ttf',
			'/System/Library/Fonts/Supplemental/Arial.ttf', 'C:\\Windows\\Fonts\\arial.ttf') as $f) {
			if (is_file($f)) return $f;
		}
		return self::font();
	}

	/** $hex scaled towards black by $factor (0..1). */
	static function shade($hex, $factor)
	{
		$hex = ltrim($hex, '#');
		if (strlen($hex) === 3) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		$out = '#';
		foreach (str_split($hex, 2) as $c) {
			$out .= sprintf('%02x', (int) round(hexdec($c) * $factor));
		}
		return $out;
	}

	protected static function rgb($img, $hex, $alpha = 0)
	{
		$hex = ltrim($hex, '#');
		if (strlen($hex) === 3) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		return imagecolorallocatealpha($img, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)),
			hexdec(substr($hex, 4, 2)), $alpha);
	}

	/** A filled rounded rectangle. */
	protected static function roundRect($img, $x1, $y1, $x2, $y2, $r, $col)
	{
		imagefilledrectangle($img, $x1 + $r, $y1, $x2 - $r, $y2, $col);
		imagefilledrectangle($img, $x1, $y1 + $r, $x2, $y2 - $r, $col);
		foreach (array(array($x1 + $r, $y1 + $r), array($x2 - $r, $y1 + $r),
			array($x1 + $r, $y2 - $r), array($x2 - $r, $y2 - $r)) as $c) {
			imagefilledellipse($img, $c[0], $c[1], 2 * $r, 2 * $r, $col);
		}
	}

	/**
	 * Draw the mark onto $img in the square at ($ox, $oy) of side $side.
	 */
	protected static function drawMark($img, $ox, $oy, $side)
	{
		$k = $side / 64;
		self::roundRect($img, $ox, $oy, $ox + $side - 1, $oy + $side - 1, (int) round(14 * $k),
			self::rgb($img, self::tileColour()));
		$acc = self::rgb($img, self::accentColour());
		$w = max(1, (int) round(4 * $k));
		foreach (self::STREAKS as $st) {
			list($y, $x1, $x2) = array($st[1], $st[0], $st[2]);
			$yy = (int) round($oy + $y * $k);
			imagefilledrectangle($img, (int) round($ox + $x1 * $k), (int) round($yy - $w / 2),
				(int) round($ox + $x2 * $k), (int) round($yy + $w / 2), $acc);
			imagefilledellipse($img, (int) round($ox + $x1 * $k), $yy, $w, $w, $acc);
			imagefilledellipse($img, (int) round($ox + $x2 * $k), $yy, $w, $w, $acc);
		}
		$col = self::rgb($img, self::markColour());
		$letter = self::markLetter();
		$font = self::font();
		if ($font !== '' and function_exists('imagettftext')) {
			$size = 30 * $k;   // points at 72 dpi ~ the SVG's 38px cap height
			$box = imagettfbbox($size, 0, $font, $letter);
			$tw = $box[2] - $box[0];
			$x = (int) round($ox + 41 * $k - $tw / 2 - $box[0]);
			$y = (int) round($oy + 46 * $k);
			imagettftext($img, $size, 0, $x, $y, $col, $font, $letter);
		} else {
			// No TrueType font: the built-in one, drawn small and scaled up.
			$fw = imagefontwidth(5); $fh = imagefontheight(5);
			$tmp = imagecreatetruecolor($fw, $fh);
			imagealphablending($tmp, false);
			imagefill($tmp, 0, 0, imagecolorallocatealpha($tmp, 0, 0, 0, 127));
			imagestring($tmp, 5, 0, 0, $letter, self::rgb($tmp, self::markColour()));
			imagealphablending($img, true);
			imagecopyresampled($img, $tmp, (int) round($ox + 28 * $k), (int) round($oy + 14 * $k), 0, 0,
				(int) round(26 * $k), (int) round(36 * $k), $fw, $fh);
			imagedestroy($tmp);
		}
	}

	/** A transparent true-colour canvas. */
	protected static function canvas($w, $h)
	{
		$img = imagecreatetruecolor($w, $h);
		imagealphablending($img, false);
		imagesavealpha($img, true);
		imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
		imagealphablending($img, true);
		return $img;
	}

	protected static function encodePng($img)
	{
		imagealphablending($img, false);
		imagesavealpha($img, true);
		ob_start();
		imagepng($img, null, 9);
		$png = (string) ob_get_clean();
		imagedestroy($img);
		return $png;
	}

	/** Load a raster image file with GD, or null. */
	protected static function loadRaster($file)
	{
		$data = @file_get_contents($file);
		if ($data === false or !function_exists('imagecreatefromstring')) return null;
		$img = @imagecreatefromstring($data);
		return $img ?: null;
	}

	/**
	 * The icon as a PNG of $size x $size, or '' without GD.
	 * @method png
	 * @static
	 */
	static function png($size)
	{
		$size = max(16, min(1024, (int) $size));
		if (isset(self::$cache["png$size"])) return self::$cache["png$size"];
		if (!function_exists('imagecreatetruecolor')) return '';
		$src = null;
		$f = self::iconFile();
		if ($f !== '' and strtolower(pathinfo($f, PATHINFO_EXTENSION)) !== 'svg') {
			$src = self::loadRaster($f);
		} elseif ($f === '' and self::usesShippedLogo()) {
			$src = self::loadRaster(dirname(__DIR__) . '/logo.png');
		}
		$out = self::canvas($size, $size);
		if ($src) {
			// Fitted into the square, aspect kept, centred.
			$sw = imagesx($src); $sh = imagesy($src);
			$scale = min($size / $sw, $size / $sh);
			$dw = (int) round($sw * $scale); $dh = (int) round($sh * $scale);
			imagealphablending($out, true);
			imagecopyresampled($out, $src, (int) (($size - $dw) / 2), (int) (($size - $dh) / 2), 0, 0, $dw, $dh, $sw, $sh);
			imagedestroy($src);
		} else {
			// Drawn four times larger and scaled down: GD's shapes have no
			// anti-aliasing of their own.
			$big = self::canvas($size * 4, $size * 4);
			self::drawMark($big, 0, 0, $size * 4);
			imagealphablending($out, false);
			imagecopyresampled($out, $big, 0, 0, 0, 0, $size, $size, $size * 4, $size * 4);
			imagedestroy($big);
		}
		return self::$cache["png$size"] = self::encodePng($out);
	}

	/**
	 * favicon.ico: 16, 32 and 48 pixel PNGs in an ICO container, which every
	 * current browser reads.
	 * @method ico
	 * @static
	 */
	static function ico()
	{
		if (isset(self::$cache['ico'])) return self::$cache['ico'];
		$f = self::iconFile();
		if ($f !== '' and strtolower(pathinfo($f, PATHINFO_EXTENSION)) === 'ico') {
			return self::$cache['ico'] = (string) file_get_contents($f);
		}
		$images = array();
		foreach (array(16, 32, 48) as $s) {
			$p = self::png($s);
			if ($p !== '') $images[$s] = $p;
		}
		$head = pack('vvv', 0, 1, count($images));
		$dir = ''; $data = '';
		$offset = 6 + 16 * count($images);
		foreach ($images as $s => $p) {
			$dir .= pack('CCCCvvVV', $s, $s, 0, 0, 1, 32, strlen($p), $offset + strlen($data));
			$data .= $p;
		}
		return self::$cache['ico'] = $head . $dir . $data;
	}

	/**
	 * The link-preview image, 1200 x 630: the icon, the product name, the
	 * description and the maintainer credit. Q.webserver.brandOgImage, a PNG
	 * or JPEG file, replaces it.
	 * @method ogImage
	 * @static
	 */
	static function ogImage()
	{
		if (isset(self::$cache['og'])) return self::$cache['og'];
		$custom = self::setting('brandOgImage');
		if ($custom !== '' and is_file($custom)) {
			return self::$cache['og'] = (string) file_get_contents($custom);
		}
		if (!function_exists('imagecreatetruecolor')) return '';
		$W = 1200; $H = 630;
		$img = imagecreatetruecolor($W, $H);
		imagealphablending($img, true);
		// Darker than the icon's tile, so the tile reads as a tile.
		imagefilledrectangle($img, 0, 0, $W, $H, self::rgb($img, self::shade(self::tileColour(), 0.55)));
		// A band in the mark's colour along the bottom, and a faint one in
		// the accent above it.
		imagefilledrectangle($img, 0, $H - 14, $W, $H, self::rgb($img, self::markColour()));
		imagefilledrectangle($img, 0, $H - 20, $W, $H - 15, self::rgb($img, self::accentColour(), 60));

		// The icon, from the same source as every other size.
		$icon = imagecreatefromstring(self::png(256));
		if ($icon) {
			imagecopy($img, $icon, 96, 150, 0, 0, 256, 256);
			imagedestroy($icon);
		}

		$txt = self::rgb($img, '#e1e4ed');
		$dim = self::rgb($img, '#8b90a8');
		$bold = self::font();
		$reg = self::textFont();
		$name = self::name();
		$x = 400;
		if ($bold !== '' and function_exists('imagettftext')) {
			$size = 54;
			while ($size > 28 and (imagettfbbox($size, 0, $bold, $name)[2] > $W - $x - 60)) $size -= 2;
			imagettftext($img, $size, 0, $x, 268, $txt, $bold, $name);
			$lines = self::wrap(self::description(), $reg, 24, $W - $x - 70);
			$y = 330;
			foreach (array_slice($lines, 0, 3) as $line) {
				imagettftext($img, 24, 0, $x, $y, $dim, $reg, $line);
				$y += 38;
			}
			$maint = self::maintainer();
			if ($maint !== '') {
				imagettftext($img, 22, 0, $x, $y + 34, self::rgb($img, self::markColour()), $reg, 'Maintained by ' . $maint);
			}
		} else {
			imagestring($img, 5, $x, 230, $name, $txt);
			imagestring($img, 4, $x, 270, self::description(), $dim);
		}
		ob_start();
		imagepng($img, null, 9);
		imagedestroy($img);
		return self::$cache['og'] = (string) ob_get_clean();
	}

	protected static function maintainer()
	{
		return class_exists('Q_WebServer', false) ? Q_WebServer::brandLink('maintainer') : self::setting('maintainer');
	}

	/** Word-wrap $text to $width pixels in $font at $size. */
	protected static function wrap($text, $font, $size, $width)
	{
		$lines = array(); $line = '';
		foreach (preg_split('/\s+/', trim($text)) as $word) {
			$try = $line === '' ? $word : "$line $word";
			if ($line !== '' and imagettfbbox($size, 0, $font, $try)[2] > $width) {
				$lines[] = $line; $line = $word;
			} else {
				$line = $try;
			}
		}
		if ($line !== '') $lines[] = $line;
		return $lines;
	}

	/** Forget generated images (after a configuration change, and in tests). */
	static function reset()
	{
		self::$cache = array();
	}
}
