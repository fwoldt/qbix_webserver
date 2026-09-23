<?php

/**
 * A header value must not be able to write headers of its own.
 *
 * A CR or an LF inside a header value ends the line early. Everything after it
 * is read by the client as a further header, or -- after a blank line -- as a
 * second response body entirely. That is response splitting, and it turns any
 * value we echo back into a way to forge a reply: a cache poisoned through it
 * serves the forged page to everybody who asks afterwards.
 *
 * Values reach the serialiser from application output and from stored cache
 * entries, so this is not a theoretical source.
 *
 * The guard existed, in exactly one of seven places that wrote headers to a
 * client. Six hand-written "foreach ... $out .= " loops went around it. This
 * test covers the filter itself and, just as importantly, checks that those
 * loops have not come back: a guard that protects one of seven responses is
 * not a guard, and the only durable fix is that there is one writer to guard.
 *
 *   php tests/unit-header-injection.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);

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

// Q_WebServer is large and pulls in the world, so the method under test is
// exercised through a copy of its source rather than by loading the class.
// The copy is taken from the file at run time, so it cannot drift from it.
$src = file_get_contents(__DIR__ . '/../src/Q/WebServer.php');
if (!preg_match('/\n\tstatic function headerLines\(.*?\n\t\}\n/s', $src, $m)) {
	fwrite(STDERR, "  FAIL - headerLines() not found in src/Q/WebServer.php\n");
	exit(1);
}
eval('class T { ' . $m[0] . ' }');

// ── The filter itself ───────────────────────────────────────────

check('ordinary header survives',
	T::headerLines(array('X-A' => 'b')), "X-A: b\r\n");

check('several headers keep their order',
	T::headerLines(array('A' => '1', 'B' => '2')), "A: 1\r\nB: 2\r\n");

check('empty map yields nothing',
	T::headerLines(array()), '');

check('a non-array yields nothing rather than a warning',
	T::headerLines(null), '');

check('a string in place of a map yields nothing',
	T::headerLines('X-A: b'), '');

// The four shapes that split a response. A lone CR is included because some
// clients and intermediaries treat it as a line ending on its own.
check('CRLF in a value drops the header',
	T::headerLines(array('X-A' => "b\r\nX-Evil: 1")), '');

check('bare LF in a value drops the header',
	T::headerLines(array('X-A' => "b\nX-Evil: 1")), '');

check('bare CR in a value drops the header',
	T::headerLines(array('X-A' => "b\rX-Evil: 1")), '');

check('CRLF in a name drops the header',
	T::headerLines(array("X-A\r\nX-Evil" => '1')), '');

check('a trailing newline alone drops the header',
	T::headerLines(array('X-A' => "b\n")), '');

// The blank line that starts a forged body.
check('a full response smuggled into a value is dropped',
	T::headerLines(array('X-A' => "b\r\n\r\nHTTP/1.1 200 OK\r\n\r\n<html>evil")), '');

// Dropping is per header, not per response: one poisoned value must not take
// the rest of the reply down with it, or the filter becomes a way to blank a
// page rather than to protect it.
check('a clean header before a poisoned one survives',
	T::headerLines(array('A' => '1', 'X' => "b\r\nEvil: 1")), "A: 1\r\n");

check('a clean header after a poisoned one survives',
	T::headerLines(array('X' => "b\r\nEvil: 1", 'B' => '2')), "B: 2\r\n");

check('clean headers either side of a poisoned one survive',
	T::headerLines(array('A' => '1', 'X' => "b\nEvil: 1", 'B' => '2')),
	"A: 1\r\nB: 2\r\n");

// A dropped header is dropped whole. Truncating at the CR would keep an
// attacker-chosen prefix, and escaping would invent a value nobody asked to
// send; omitting is the only option that states nothing false.
check('a poisoned value is not truncated to its safe prefix',
	strpos(T::headerLines(array('X-A' => "safe\r\nEvil: 1")), 'safe'), false);

// Values that merely look alarming are not the target and must pass through.
check('a semicolon and quotes are fine',
	T::headerLines(array('Set-Cookie' => 'a=b; Path=/; HttpOnly')),
	"Set-Cookie: a=b; Path=/; HttpOnly\r\n");

check('a tab inside a value is fine',
	T::headerLines(array('X-A' => "b\tc")), "X-A: b\tc\r\n");

check('a non-ASCII byte is fine',
	T::headerLines(array('X-A' => "caf\xc3\xa9")), "X-A: caf\xc3\xa9\r\n");

check('an integer value is rendered',
	T::headerLines(array('Content-Length' => 12)), "Content-Length: 12\r\n");

check('a numeric key is rendered',
	T::headerLines(array(7 => 'x')), "7: x\r\n");

// ── The structural half: one writer, not seven ──────────────────

// Every response the server writes is built somewhere. If a new hand-written
// loop appears, the filter stops covering the whole surface again, silently,
// and no behavioural test would notice: the new path would simply be
// unprotected. So the source itself is the fixture here.
$files = array();
$dir = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator(__DIR__ . '/../src', FilesystemIterator::SKIP_DOTS));
foreach ($dir as $f) {
	if (substr($f->getFilename(), -4) === '.php') $files[] = $f->getPathname();
}
sort($files);
check('the source tree was found', count($files) > 10, true);

$offenders = array();
foreach ($files as $file) {
	$lines = explode("\n", file_get_contents($file));
	$inHeaderLines = false;
	foreach ($lines as $i => $line) {
		// headerLines() is the one place allowed to concatenate a header.
		if (strpos($line, 'static function headerLines(') !== false) {
			$inHeaderLines = true;
			continue;
		}
		if ($inHeaderLines) {
			if ($line === "\t}") $inHeaderLines = false;
			continue;
		}
		// "Name: value\r\n" built from a pair of variables, which is what a
		// header loop looks like and what a fixed status line does not.
		// The Set-Cookie loops are deliberately not matched here: a header map
		// is associative and cannot carry two of them, so they build their
		// lines by hand of necessity and are checked for their own inline
		// filter further down instead.
		if (preg_match('/\$\w+\s*\.=\s*"\$\w+:\s*\$\w+\\\\r\\\\n"/', $line)) {
			$offenders[] = basename($file) . ':' . ($i + 1) . '  ' . trim($line);
		}
	}
}
if ($offenders) {
	printf("  FAIL  header lines are still built by hand in %d place(s):\n",
		count($offenders));
	foreach ($offenders as $o) printf("        %s\n", $o);
	printf("        route these through Q_WebServer::headerLines()\n");
	++$fail;
} else {
	++$pass;
}

// The cookie loops cannot use headerLines(), because a header map is
// associative and cannot hold two Set-Cookie entries. They filter inline
// instead, so check that the filter is actually present in each.
$headersSrc = file_get_contents(__DIR__ . '/../src/Q/WebServer/Headers.php');
check('the Set-Cookie loop in Headers.php filters',
	(bool) preg_match('/foreach \(\$cookieHeaders as \$ch\) \{\s*\n\s*if \(preg_match\(\'\/\[\\\\r\\\\n\]\/\'/', $headersSrc),
	true);

check('the Set-Cookie pair loop in WebServer.php filters',
	(bool) preg_match('/foreach \(\$extraHeaders as \$pair\) \{\s*\n\s*if \(preg_match\(\'\/\[\\\\r\\\\n\]\/\'/', $src),
	true);

// http1Head() must delegate rather than keep a second copy of the filter,
// otherwise the two can drift apart.
check('http1Head delegates to headerLines',
	(bool) preg_match('/static function http1Head\(.*?return \$out \. self::headerLines\(\$extra\);/s', $src),
	true);

if ($fail) {
	printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail);
	exit(1);
}
printf("  PASS - %d case(s)\n", $pass);
