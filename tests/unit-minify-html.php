<?php

/**
 * Collapsing whitespace must never change what the page means.
 *
 * A template is indented for people to read and every indent is shipped: 94,090
 * bytes of a live page here, of which 28,934 are whitespace. Collapsing the
 * runs leaves 68,842, and the decompressed document is what the browser parses,
 * what a service worker stores and what sits in memory.
 *
 * The risk is entirely in the exceptions. Whitespace is insignificant in most
 * of an HTML document and load-bearing in a handful of places, and a minifier
 * that is wrong about which does not make the page smaller, it makes it wrong:
 * a mangled code sample, a form that submits different text, or a script cut
 * short by automatic semicolon insertion -- which this repository was bitten by
 * once already today.
 *
 *   php tests/unit-minify-html.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q/WebServer/Minify.php';

$M = 'Q_WebServer_Minify';
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

// ── The ordinary case ────────────────────────────────────────────────────
check('whitespace between tags goes entirely',
	$M::html("<div>\n    <p>hello</p>\n</div>"), '<div><p>hello</p></div>');

check('a run inside text becomes one space, never nothing',
	$M::html('<p>one    two</p>'), '<p>one two</p>');

check('a single space inside text is left alone',
	$M::html('<p>one two</p>'), '<p>one two</p>');

check('newlines inside text become a space',
	$M::html("<p>one\n\ntwo</p>"), '<p>one two</p>');

check('attributes are untouched',
	$M::html('<img  src="a.jpg"   alt="a  b">'), '<img src="a.jpg" alt="a b">');

// ── The exceptions, which are the whole point ────────────────────────────
$pre = "<pre>\n  line one\n    line two\n</pre>";
check('a <pre> is returned exactly as written', $M::html($pre), $pre);

$ta = "<textarea>\n  keep   this\n</textarea>";
check('a <textarea> is returned exactly as written', $M::html($ta), $ta);

// This is the automatic-semicolon-insertion trap. Collapsing the newline after
// "return" turns a returned value into a return of undefined.
$js = "<script>\nfunction f() {\n  return\n    1;\n}\n</script>";
check('a <script> is returned exactly as written', $M::html($js), $js);
check('and the newline after return survives',
	strpos($M::html($js), "return\n") !== false, true);

$css = "<style>\n.a {\n  color: red;\n}\n</style>";
check('a <style> is returned exactly as written', $M::html($css), $css);

$code = "<code>\n  a    b\n</code>";
check('a <code> is returned exactly as written', $M::html($code), $code);

// Protected elements sit in the middle of a document that IS collapsed.
$mixed = "<div>\n  <p>a</p>\n  <pre>\n  keep  me\n</pre>\n  <p>b</p>\n</div>";
$out = $M::html($mixed);
check('the document around a <pre> is still collapsed',
	strpos($out, '<div><p>a</p>') === 0, true);
check('and the <pre> inside it is not',
	strpos($out, "<pre>\n  keep  me\n</pre>") !== false, true);

// Several protected elements in one document.
$two = "<p>a</p>\n<pre>  x  </pre>\n<p>b</p>\n<script>  y  </script>\n<p>c</p>";
$out = $M::html($two);
check('two protected elements both survive',
	(strpos($out, '<pre>  x  </pre>') !== false
	 and strpos($out, '<script>  y  </script>') !== false), true);
// The whitespace between them is at a fragment boundary, where the split
// removed the '>' or '<' the pattern would otherwise have matched against.
check('and the whitespace around them is still collapsed',
	$out, '<p>a</p><pre>  x  </pre><p>b</p><script>  y  </script><p>c</p>');

// ── Refusing rather than risking ─────────────────────────────────────────
check('an empty document is returned as-is', $M::html(''), '');
check('a non-string is returned as-is', $M::html(null), null);
check('a document with no whitespace is unchanged',
	$M::html('<p>a</p>'), '<p>a</p>');

// Comments are left alone: some are markup to some browsers.
$c = "<!--[if IE]>\n  <p>old</p>\n<![endif]-->";
check('a conditional comment is not half-removed',
	strpos($M::html($c), '<![endif]-->') !== false, true);

// ── Which responses it applies to ────────────────────────────────────────
check('it applies to text/html',
	$M::applies(array('Content-Type' => 'text/html; charset=utf-8')), true);
check('regardless of header case',
	$M::applies(array('content-type' => 'TEXT/HTML')), true);
check('but not to JSON',
	$M::applies(array('Content-Type' => 'application/json')), false);
check('nor to an image',
	$M::applies(array('Content-Type' => 'image/jpeg')), false);
check('nor when there is no Content-Type at all',
	$M::applies(array('X-Thing' => 'y')), false);
check('nor to rubbish', $M::applies(null), false);

// ── It actually saves something ──────────────────────────────────────────
$doc = "<html>\n  <body>\n" . str_repeat("    <div>\n      <p>text</p>\n    </div>\n", 200)
     . "  </body>\n</html>";
$small = $M::html($doc);
$saved = 100.0 * (strlen($doc) - strlen($small)) / strlen($doc);
check('a realistically indented document shrinks by a quarter or more',
	$saved > 25, true);
printf("  (a %d byte document became %d, %.1f%% smaller)\n",
	strlen($doc), strlen($small), $saved);

echo "\n";
if ($fail === 0) { printf("  PASS - %d case(s)\n\n", $pass); exit(0); }
printf("  FAIL - %d of %d\n\n", $fail, $pass + $fail);
exit(1);
