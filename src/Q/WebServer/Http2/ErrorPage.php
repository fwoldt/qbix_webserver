<?php

/**
 * The page a client gets when HTTP/2 goes wrong.
 *
 * This exists because of how HTTP/2 fails. The protocol is chosen during the
 * TLS handshake, and a client that agreed to h2 will not fall back to HTTP/1.1
 * afterwards. So a framing fault, a bad header block or an exception in a
 * handler does not produce a slow page or an error page -- it produces
 * nothing. A blank window, and nothing in any log the person looking at the
 * blank window can reach.
 *
 * So whenever a stream is still open, something is sent on it. Even a wrong
 * answer is more use than silence: it names what failed, on which stream, at
 * what time, and it is obviously an error rather than an empty site.
 *
 * Everything about it is configurable, because an error page is the one page
 * you cannot preview before it is needed and every installation wants
 * different words on it:
 *
 *   Q.web.http2.errorPage.template   a file to use instead of all of this.
 *                                    Receives {title} {message} {detail}
 *                                    {image} {stream} {time} to substitute.
 *   Q.web.http2.errorPage.title      heading
 *   Q.web.http2.errorPage.message    the sentence under it
 *   Q.web.http2.errorPage.image      a URL, a data: URI, or a path to a file
 *                                    to inline. "none" removes it entirely.
 *   Q.web.http2.errorPage.detail     false hides the technical line, for an
 *                                    installation that would rather not say
 *                                    what broke to whoever is looking.
 *
 * @module Q
 */
class Q_WebServer_Http2_ErrorPage
{
	/**
	 * A monkey opening a server with a wrench.
	 *
	 * Inline, and drawn rather than fetched, for a reason: this page appears
	 * exactly when the connection carrying it is unreliable, so it must not
	 * depend on a second request succeeding. It is a data URI in one document
	 * with no external anything.
	 *
	 * Replace it with Q.web.http2.errorPage.image.
	 */
	static function defaultImage()
	{
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 240 170" width="240" height="170" role="img" aria-label="A monkey opening a server with a wrench">'
			// server chassis
			. '<rect x="18" y="30" width="120" height="110" rx="8" fill="#2b3440" stroke="#1b212a" stroke-width="3"/>'
			// rack slots
			. '<rect x="30" y="44" width="96" height="18" rx="3" fill="#3a4654"/>'
			. '<rect x="30" y="68" width="96" height="18" rx="3" fill="#3a4654"/>'
			. '<rect x="30" y="92" width="96" height="18" rx="3" fill="#3a4654"/>'
			// status lights, one of them unhappy
			. '<circle cx="40" cy="53" r="4" fill="#5dd39e"/>'
			. '<circle cx="40" cy="77" r="4" fill="#5dd39e"/>'
			. '<circle cx="40" cy="101" r="4" fill="#ef6461"/>'
			// the panel being levered off
			. '<path d="M126 36 L150 28 L156 58 L132 66 Z" fill="#48566a" stroke="#1b212a" stroke-width="2"/>'
			// sparks
			. '<path d="M138 70 l8 -6 -3 9 9 -4 -7 8" fill="#ffd166"/>'
			. '<path d="M128 24 l5 -8 0 9 7 -3 -6 7" fill="#ffd166"/>'
			// wrench
			. '<g transform="rotate(-24 178 74)">'
			. '<rect x="150" y="68" width="60" height="11" rx="5" fill="#9aa5b1"/>'
			. '<path d="M146 62 a13 13 0 1 0 0 23 l0 -7 a7 7 0 1 1 0 -9 z" fill="#c3ccd6"/>'
			. '</g>'
			// monkey: tail, body, arms, head
			. '<path d="M214 120 q22 -6 14 -28 q-6 -16 -22 -10" fill="none" stroke="#8a5a3b" stroke-width="7" stroke-linecap="round"/>'
			. '<ellipse cx="196" cy="112" rx="26" ry="30" fill="#a16a45"/>'
			. '<ellipse cx="196" cy="118" rx="16" ry="19" fill="#d9a878"/>'
			. '<path d="M176 96 q-18 -12 -30 -18" fill="none" stroke="#a16a45" stroke-width="11" stroke-linecap="round"/>'
			. '<circle cx="176" cy="64" r="11" fill="#a16a45"/>'
			. '<circle cx="216" cy="64" r="11" fill="#a16a45"/>'
			. '<circle cx="196" cy="66" r="27" fill="#a16a45"/>'
			. '<ellipse cx="196" cy="74" rx="19" ry="16" fill="#d9a878"/>'
			. '<circle cx="188" cy="60" r="3.6" fill="#2b3440"/>'
			. '<circle cx="204" cy="60" r="3.6" fill="#2b3440"/>'
			. '<ellipse cx="196" cy="72" rx="3" ry="2.2" fill="#2b3440"/>'
			// a grin, because it is pleased with itself
			. '<path d="M186 80 q10 9 20 0" fill="none" stroke="#2b3440" stroke-width="2.6" stroke-linecap="round"/>'
			. '</svg>';
	}

	/**
	 * Resolve the configured image to something that can go in the document.
	 *
	 * @method image
	 * @static
	 * @return {string} markup, or '' for none
	 */
	static function image()
	{
		$configured = self::config('image', '');

		if ($configured === 'none') return '';

		if ($configured === '') {
			return '<img alt="A monkey opening a server with a wrench" src="data:image/svg+xml;base64,'
				. base64_encode(self::defaultImage()) . '">';
		}

		// A file on disk is inlined, for the same reason the default is: this
		// page appears when the connection is already misbehaving and must not
		// need a second request to be legible.
		if (is_file($configured)) {
			$ext = strtolower(pathinfo($configured, PATHINFO_EXTENSION));
			$types = array(
				'svg' => 'image/svg+xml', 'png' => 'image/png',
				'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
				'gif' => 'image/gif', 'webp' => 'image/webp',
			);
			$mime = isset($types[$ext]) ? $types[$ext] : 'application/octet-stream';
			$data = @file_get_contents($configured);
			if ($data !== false) {
				return '<img alt="" src="data:' . $mime . ';base64,' . base64_encode($data) . '">';
			}
		}

		// Otherwise it is a URL or a data: URI, used as given.
		return '<img alt="" src="' . htmlspecialchars($configured, ENT_QUOTES) . '">';
	}

	/**
	 * A configured value, or a default.
	 *
	 * @method config
	 * @static
	 * @param {string} $key
	 * @param {mixed} $default
	 * @return {mixed}
	 */
	static function config($key, $default = null)
	{
		if (!class_exists('Q_Config', false)) return $default;
		return Q_Config::get('Q', 'web', 'http2', 'errorPage', $key, $default);
	}

	/**
	 * Build the page.
	 *
	 * @method render
	 * @static
	 * @param {string} $detail what actually went wrong, in technical terms
	 * @param {integer} $stream the stream it happened on, 0 for the connection
	 * @return {string}
	 */
	static function render($detail, $stream = 0)
	{
		$title = self::config('title', 'This page did not finish loading');
		$message = self::config('message',
			'The connection to this site uses HTTP/2, and something went wrong '
			. 'inside it. The server noticed and is telling you, rather than '
			. 'leaving you with an empty window.');
		$showDetail = self::config('detail', true);
		$time = gmdate('Y-m-d H:i:s') . ' UTC';
		$image = self::image();

		$detailHtml = $showDetail
			? '<p class="detail"><code>' . htmlspecialchars($detail, ENT_QUOTES) . '</code></p>'
			: '';

		$template = self::config('template', '');
		if ($template !== '' and is_file($template)) {
			$html = @file_get_contents($template);
			if ($html !== false) {
				return strtr($html, array(
					'{title}'   => htmlspecialchars($title, ENT_QUOTES),
					'{message}' => htmlspecialchars($message, ENT_QUOTES),
					'{detail}'  => $showDetail ? htmlspecialchars($detail, ENT_QUOTES) : '',
					'{image}'   => $image,
					'{stream}'  => (string) $stream,
					'{time}'    => $time,
				));
			}
		}

		$t = htmlspecialchars($title, ENT_QUOTES);
		$m = htmlspecialchars($message, ENT_QUOTES);

		return '<!doctype html>'
			. '<html lang="en"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<title>' . $t . '</title><style>'
			. ':root{color-scheme:light dark}'
			. 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
			. 'background:#f6f7f9;color:#1b212a;'
			. 'font:16px/1.55 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}'
			. '@media(prefers-color-scheme:dark){body{background:#14181e;color:#e7ebf0}'
			. '.card{background:#1b212a!important;box-shadow:none!important;border:1px solid #2b3440}'
			. 'code{background:#0f1319!important;color:#c3ccd6!important}}'
			. '.card{max-width:640px;margin:24px;padding:32px;background:#fff;border-radius:14px;'
			. 'box-shadow:0 10px 30px rgba(20,24,30,.10);text-align:center}'
			. 'img{max-width:240px;height:auto;margin:0 auto 12px;display:block}'
			. 'h1{font-size:1.4rem;margin:.2em 0 .5em;line-height:1.25}'
			. 'p{margin:.6em 0}'
			. '.detail{margin-top:1.4em}'
			. 'code{display:inline-block;padding:.45em .7em;border-radius:7px;background:#eef1f5;'
			. 'font:13px/1.5 ui-monospace,SFMono-Regular,Menlo,monospace;word-break:break-word}'
			. '.meta{margin-top:1.2em;font-size:12px;opacity:.62}'
			. '</style></head><body><div class="card">'
			. $image
			. '<h1>' . $t . '</h1>'
			. '<p>' . $m . '</p>'
			. $detailHtml
			. '<p class="meta">HTTP/2 stream ' . (int) $stream . ' &middot; ' . $time . '</p>'
			. '</div></body></html>';
	}

	/**
	 * The page as a response array, ready to go on a stream.
	 *
	 * 503 rather than 500: this says the server could not complete the
	 * exchange, which is what happened, and it is the status a retry is
	 * reasonable after.
	 *
	 * @method response
	 * @static
	 * @param {string} $detail
	 * @param {integer} $stream
	 * @param {integer} $status
	 * @return {array}
	 */
	static function response($detail, $stream = 0, $status = 503)
	{
		$body = self::render($detail, $stream);
		return array(
			'status' => $status,
			'headers' => array(
				'content-type' => 'text/html; charset=utf-8',
				'content-length' => (string) strlen($body),
				'cache-control' => 'no-store',
			),
			'body' => $body,
		);
	}
}
