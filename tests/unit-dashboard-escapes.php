<?php

/**
 * A JavaScript escape written into HTML is just text.
 *
 * The dashboard's markup carried · and — directly in its HTML, and
 * nothing interprets them there. PHP only reads \u{00B7}, with braces, and only
 * in a double-quoted string; JavaScript reads · but JavaScript never saw
 * these. So the page rendered, with no error anywhere, showing
 *
 *   546 ok · 28 redir · 2 4xx · 0 5xx
 *
 * and the Workers, System RAM and Worker Memory cards showed — where a
 * value should be, and the pause and close buttons were labelled ⏸ and
 * ✕. It was reported by somebody reading the dashboard, which is the only
 * way it could be: there is nothing to fail.
 *
 * The same escapes are correct inside <script>, and three files use them there,
 * so this cannot be a plain search for a backslash-u. The check splits each
 * file on its script blocks and looks only at what a browser treats as text.
 *
 *   php tests/unit-dashboard-escapes.php
 */

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

/** Every .php under src/, plus the entry point. */
function sources()
{
	$found = array();
	$dir = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator(__DIR__ . '/../src',
			FilesystemIterator::SKIP_DOTS));
	foreach ($dir as $f) {
		if ($f->isFile() and strtolower($f->getExtension()) === 'php') {
			$found[] = $f->getPathname();
		}
	}
	sort($found);
	$entry = __DIR__ . '/../qbixserver.php';
	if (is_file($entry)) $found[] = $entry;
	return $found;
}

$offenders = array();
$inScript = 0;

foreach (sources() as $path) {
	$src = file_get_contents($path);
	if ($src === false or strpos($src, '\\u') === false) continue;

	// Blank out script bodies, keeping the offsets so a line number still
	// means something, then look at what is left.
	$masked = preg_replace_callback('#<script\b.*?</script>#is',
		function ($m) { return str_repeat(' ', strlen($m[0])); }, $src);

	$inScript += preg_match_all('/\\\\u[0-9A-Fa-f]{4}/', $src)
		- preg_match_all('/\\\\u[0-9A-Fa-f]{4}/', $masked);

	if (preg_match_all('/\\\\u[0-9A-Fa-f]{4}/', $masked, $m, PREG_OFFSET_CAPTURE)) {
		foreach ($m[0] as $hit) {
			$offenders[] = sprintf('%s:%d  %s',
				basename($path), substr_count($src, "\n", 0, $hit[1]) + 1, $hit[0]);
		}
	}
}

check('no JavaScript escape is written where only HTML will read it',
	$offenders, array());
if ($offenders) {
	foreach ($offenders as $o) printf("        %s\n", $o);
	printf("        Use an HTML entity there: &#183; &#8212; &#9208; &#10005;\n");
}

// The other half of the rule. If this ever reaches zero, the check above has
// stopped being able to tell the two cases apart and is passing for free.
check('escapes inside <script> are left alone, where they are correct',
	$inScript > 0, true);

// And the entities that replaced them are actually present, so a future
// "tidy-up" that deletes them is caught rather than silently blanking the page.
$dash = file_get_contents(__DIR__ . '/../src/Q/WebServer/Dashboard.php');
check('the status line separates its counts', substr_count($dash, '&#183;') >= 3, true);
check('the cards have a placeholder value', substr_count($dash, '&#8212;') >= 4, true);

if ($fail) {
	printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail);
	exit(1);
}
printf("  PASS - %d case(s), %d escape(s) correctly inside <script>\n", $pass, $inScript);
