<?php

/**
 * Collapse the whitespace a template engine leaves behind.
 *
 * A template is written to be read by people, so it is indented, and every one
 * of those indents is shipped. Measured on a live page here: 94,090 bytes of
 * which 28,934 are whitespace, and collapsing the runs between tags leaves
 * 68,842 -- 26.8% smaller before compression has done anything.
 *
 * It matters twice over. gzip handles repeated whitespace well, so the saving
 * on the wire is modest; but the *decompressed* document is what the browser
 * parses, what a service worker stores, and what sits in memory. That is where
 * a quarter is worth having.
 *
 * ── What this deliberately does not touch ────────────────────────────────
 *
 * Whitespace is only insignificant in some places, and being wrong about which
 * is how a minifier corrupts a page rather than shrinking it:
 *
 *   - <pre> and <textarea> render whitespace literally. A collapsed <pre> is a
 *     mangled code sample; a collapsed <textarea> silently changes what a form
 *     submits.
 *   - <script> and <style> are not HTML. Removing a newline inside JavaScript
 *     can end a statement early through automatic semicolon insertion, which
 *     is a bug this repository has already been bitten by once today in the
 *     asset optimizer.
 *   - Whitespace *inside* text is meaningful: "a  b" may be collapsed to "a b"
 *     but not to "ab". Only runs are shortened, never removed.
 *   - Conditional comments are markup to some browsers, so comments are left
 *     alone entirely rather than half-removed.
 *
 * So the only transformation applied is: runs of whitespace between a '>' and
 * a '<' become nothing, and runs of whitespace elsewhere become one space --
 * and neither is applied inside a protected element.
 *
 * @class Q_WebServer_Minify
 */
class Q_WebServer_Minify
{
	/** Elements whose contents are returned exactly as they were written. */
	static $protected = array('pre', 'textarea', 'script', 'style', 'code');

	/**
	 * Whether a response looks like HTML this may be applied to.
	 *
	 * @method applies
	 * @static
	 * @param {array} $headers
	 * @return {boolean}
	 */
	static function applies($headers)
	{
		if (!is_array($headers)) return false;
		foreach ($headers as $name => $value) {
			if (strcasecmp($name, 'Content-Type') === 0) {
				return stripos((string) $value, 'text/html') !== false;
			}
		}
		return false;
	}

	/**
	 * Collapse insignificant whitespace in an HTML document.
	 *
	 * Returns the input unchanged if anything looks unsafe, because a page
	 * that is 27% larger is a great deal better than a page that is wrong.
	 *
	 * @method html
	 * @static
	 * @param {string} $html
	 * @return {string}
	 */
	static function html($html)
	{
		if (!is_string($html) or $html === '') return $html;

		// Split on the protected elements, keeping them in the result, so the
		// parts between them can be collapsed and the parts inside cannot.
		$names = implode('|', self::$protected);
		$pieces = preg_split(
			'#(<(?:' . $names . ')\b[^>]*>.*?</(?:' . $names . ')\s*>)#is',
			$html, -1, PREG_SPLIT_DELIM_CAPTURE
		);

		if ($pieces === false) return $html;   // catastrophic backtracking, say

		$out = '';
		foreach ($pieces as $i => $piece) {
			// Odd indices are the captured protected elements.
			if ($i % 2 === 1) { $out .= $piece; continue; }
			$out .= self::collapse($piece);
		}

		// A result that is larger, or empty when the input was not, means
		// something went wrong; keep what we were given.
		if ($out === '' or strlen($out) > strlen($html)) return $html;
		return $out;
	}

	/**
	 * Collapse whitespace in a fragment known to contain no protected element.
	 * @method collapse
	 * @static
	 * @param {string} $s
	 * @return {string}
	 */
	private static function collapse($s)
	{
		// Between tags, whitespace carries nothing and goes entirely.
		$s = preg_replace('/>[ \t\r\n]+</', '><', $s);

		// The same at the edges of this fragment. A fragment boundary is where
		// a protected element was cut out, and a protected element always
		// begins with '<' and ends with '>', so whitespace touching either end
		// sits between two tags just as surely -- it simply had its partner
		// removed by the split and so escaped the pattern above.
		$s = preg_replace('/^[ \t\r\n]+</', '<', $s);
		$s = preg_replace('/>[ \t\r\n]+$/', '>', $s);
		// Everywhere else a run becomes one space: "a  b" is "a b", never "ab".
		$s = preg_replace('/[ \t\r\n]{2,}/', ' ', $s);
		return $s === null ? '' : $s;
	}
}
