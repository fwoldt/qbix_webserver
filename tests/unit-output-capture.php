<?php

/**
 * The response capture buffer does not accumulate, whatever a script does.
 *
 * A persistent process used to open a non-removable buffer per request, and
 * because flags 0 also make a buffer impossible to clean, one was left behind
 * per request with that response in it: ~2 MB a request on an Exponential
 * install, a worker at 1.1 GB after 600 requests. See Q_WebServer_Capture.
 *
 * This runs many request cycles in one process, with the things scripts
 * actually do -- leave buffers open, end every buffer they can, clean, exit
 * part-way -- and checks after each that the stack is back to one empty buffer,
 * and at the end that the heap did not grow with the number of requests.
 *
 * Results go to STDOUT with fwrite(): the capture buffer cannot be removed, so
 * anything echoed by the test itself would be captured, which is the point.
 *
 *   php tests/unit-output-capture.php
 */

require __DIR__ . '/../src/Q/WebServer/Capture.php';

$C = 'Q_WebServer_Capture';
$pass = 0;
$fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	fwrite(STDOUT, sprintf("  FAIL  %s\n        got  %s\n        want %s\n", $what,
		var_export($got, true), var_export($want, true)));
}

// ── One request, plainly ────────────────────────────────────────

Q_WebServer_Capture::begin();
echo 'hello';
check('a plain response is captured', Q_WebServer_Capture::end(), 'hello');
check('...and leaves one empty buffer', Q_WebServer_Capture::balanced(), true);
$base = ob_get_level();

// ── What scripts do ─────────────────────────────────────────────

Q_WebServer_Capture::begin();
echo 'A';
ob_start();
echo 'B';
ob_start();
echo 'C';
check('buffers a script leaves open are part of its response',
	Q_WebServer_Capture::end(), 'ABC');
check('...and are gone afterwards', ob_get_level(), $base);

Q_WebServer_Capture::begin();
echo 'lost';
while (@ob_end_clean());
echo 'kept';
// PHP refuses to end a buffer that is not removable, and does so without
// cleaning it or calling its handler, so what was printed before the loop
// stays. That is the same as the non-removable buffer this replaced; the
// alternative -- a removable buffer -- would send everything after the loop
// to the worker's real stdout instead of the client.
check('an end-every-buffer loop stops at the capture buffer',
	Q_WebServer_Capture::end(), 'lostkept');
check('...which is still there', ob_get_level(), $base);

Q_WebServer_Capture::begin();
echo 'discarded';
ob_clean();
echo 'after';
check('ob_clean() discards what was printed, as anywhere',
	Q_WebServer_Capture::end(), 'after');

Q_WebServer_Capture::begin();
echo 'partial';
ob_start();
echo 'more';
Q_WebServer_Capture::discard();
echo 'error page';
check('discard() drops everything, open buffers included',
	Q_WebServer_Capture::end(), 'error page');

Q_WebServer_Capture::begin();
echo 'flushed ';
ob_flush();
echo 'rest';
check('ob_flush() from a script keeps the output', Q_WebServer_Capture::end(),
	'flushed rest');

// A request that never reached end() -- an exception past the caller, say.
Q_WebServer_Capture::begin();
echo 'abandoned';
ob_start();
echo 'also';
Q_WebServer_Capture::begin();
echo 'next';
check('a request that never ended cannot leak into the next one',
	Q_WebServer_Capture::end(), 'next');
check('...and the stack is back to one buffer', ob_get_level(), $base);

// ── Many requests: nothing grows ────────────────────────────────

$page = str_repeat('<p>content</p>', 5000);   // ~70 KB, a real page's size
$levels = array();
$mem = array();
for ($i = 1; $i <= 600; ++$i) {
	Q_WebServer_Capture::begin();
	echo $page;
	if ($i % 3 === 0) { ob_start(); echo $page; }          // left open
	if ($i % 5 === 0) { while (@ob_end_clean()); echo 'x'; }
	Q_WebServer_Capture::end();
	$levels[ob_get_level()] = true;
	if ($i === 50) $mem[50] = memory_get_usage();
	if ($i === 600) $mem[600] = memory_get_usage();
}
check('600 requests never left more than one buffer', array_keys($levels), array($base));
check('...and the process is balanced after them', Q_WebServer_Capture::balanced(), true);
$grew = ($mem[600] - $mem[50]) / 1048576;
check(sprintf('the heap did not grow with requests (%.2f MB from 50 to 600)', $grew),
	$grew < 1.0, true);

if ($fail) {
	fwrite(STDOUT, sprintf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail));
	exit(1);
}
fwrite(STDOUT, sprintf("  PASS - %d case(s)\n", $pass));
