<?php

/**
 * A PHP file rewritten while workers are including it: an include returns
 * one complete version, never a mixture, a bad moment costs at most that one
 * request, and once the writer stops every request sees the final version.
 *
 * What this guards. The compat file wrapper keeps, per worker, the bytes of
 * each included file (Q_WebServer_CompatFileWrapper::$contentCache) and the
 * transform of each file that needs one (Q_WebServer_Compat::$transformCache).
 * Both are reused while a stat taken in the current request agrees with the
 * stat recorded when they were filled. A writer on another process races
 * both: the bytes can change between the stat and the read, and a stat has
 * one-second resolution, so a file rewritten within the second it was cached
 * in looks unchanged.
 *
 * Cases:
 *
 *   A. Atomic writer (temp file + rename) in a tight loop, versions all the
 *      same size, 3 workers and 8 concurrent clients including it. Every
 *      response must be one whole version (checked by a self-describing body:
 *      "V<k>:" + "<k>," x 1500 + ":V<k>"). After the writer stops, every
 *      request must return the final version.
 *   B. The same with a file that needs the source transform (it calls
 *      header()), sizes varying: exercises the transform cache, and the
 *      header must name the same version as the body.
 *   C. In-place writer (fopen "w", write half, pause, write the rest), which
 *      leaves the file truncated or half-written for moments at a time.
 *      Responses may be a whole version, an empty include, or a 500 from
 *      the parse error; never a 502 (worker lost), never a mixture presented
 *      as a 200. Counts are reported. Afterwards: final version everywhere.
 *      Exception, environmental: with the ionCube Loader installed (it is on
 *      the reference host) a worker compiling a file that another process is
 *      truncating dies of SIGBUS inside the loader -- it hooks compile_file
 *      and mmaps the real file (core: memcpy <- ioncube _zval_dup <-
 *      compile_filename). The request gets 502 "Worker died"; php-fpm loses
 *      its child the same way. The test then asserts only that the pool
 *      recovers and later requests are right.
 *   D. Deterministic, one worker: include version A, rewrite it within the
 *      same second (1) same size, (2) needing the transform with a different
 *      size; the next request must return B.
 *
 * Real defects this exposes (see the FAILS lines when run):
 *
 *   - content cache: bytes are reused when mtime (seconds) and size match.
 *     A same-size rewrite in the same second is served stale -- for as long
 *     as the file is not touched again, i.e. possibly forever.
 *     src/Q/WebServer/Compat.php stream_open(), the `$kept[0] === $mtime and
 *     $kept[1] === size` test (~l.2711).
 *   - transform cache: compared on mtime alone (no size), and the mtime it is
 *     stored with is read by saveCache() with filemtime() AFTER the source
 *     was read (~l.636-641), so a rewrite landing between the read and the
 *     filemtime() pairs the OLD transform with the NEW mtime -- served stale
 *     until the file changes again.
 *
 *   php tests/unit-race-include-rewrite.php
 */

require __DIR__ . '/fixtures/race-harness.php';
rh_require_pool();
list($base, $root) = rh_setup('race-include');

const BODY_REPEAT = 1500;
const BUG_CONTENT_CACHE = 'the include content cache (Compat.php stream_open ~l.2708-2712) reuses a file\'s bytes while the current request\'s stat has the same mtime and size as when they were kept. mtime has one-second resolution, so a same-size rewrite within the second it was cached in is invisible and the old bytes are served until the file changes again -- indefinitely if it does not (opcache\'s file_update_protection exists for exactly this). Repro: workers=1; write A; GET (caches A); within the same second write B of equal length; GET returns A.';
const BUG_TRANSFORM_CACHE = 'the transform cache is validated on mtime alone (Compat.php stream_open ~l.2723, cachedMtime() !== stat mtime) -- size is not compared -- and saveCache() (~l.636) records filemtime() taken AFTER the source was read, so (1) any rewrite in the same second, whatever its size, is served the stale transform, and (2) a rewrite landing between the read and filemtime() stores the OLD transform under the NEW mtime, served until the file changes again. Repro: workers=1; file calling header(); write A; GET; within the same second write B of a different length; GET returns A.';

/** Source of version $k. $transform adds a header() call; $vary varies the size. */
function versionSource($k, $transform = false, $vary = false)
{
	$id = sprintf('%06d', $k);
	$n = BODY_REPEAT + ($vary ? $k % 7 : 0);
	return "<?php\n" . ($transform ? "header('X-Race: $id');\n" : '')
		. "return 'V$id:" . str_repeat("$id,", $n) . ":V$id';\n";
}

/** The version a body names, or null if it is not one whole version. */
function versionOf($body)
{
	if (!preg_match('/^V(\d{6}):((?:\1,)+):V\1$/', $body, $m)) return null;
	return (int) $m[1];
}

file_put_contents($root . DS . 'index.php', '<?php
$f = __DIR__ . "/" . preg_replace("/[^a-z]/", "", $_GET["f"]) . ".php";
$v = include $f;
echo is_string($v) ? $v : ("NOT-A-VERSION:" . var_export($v, true));
');

// The writer runs in its own process: it is "another process" to the workers.
file_put_contents($base . DS . 'writer.php', '<?php
list(, $path, $mode, $secs, $transform, $vary, $final) = $argv;
' . 'function versionSource($k, $transform, $vary) {
	$id = sprintf("%06d", $k); $n = ' . BODY_REPEAT . ' + ($vary ? $k % 7 : 0);
	return "<?php\n" . ($transform ? "header(\'X-Race: $id\');\n" : "")
		. "return \'V$id:" . str_repeat("$id,", $n) . ":V$id\';\n";
}
$until = microtime(true) + (float) $secs;
$k = 1; $n = 0;
while (microtime(true) < $until) {
	$src = versionSource($k, $transform === "1", $vary === "1");
	if ($mode === "rename") {
		file_put_contents("$path.tmp", $src);
		rename("$path.tmp", $path);
	} else {
		$h = fopen($path, "w");
		$half = (int) (strlen($src) / 2);
		fwrite($h, substr($src, 0, $half)); fflush($h);
		usleep(2000);
		fwrite($h, substr($src, $half)); fclose($h);
	}
	++$k; ++$n;
	usleep(500);
}
$src = versionSource((int) $final, $transform === "1", $vary === "1");
file_put_contents("$path.tmp", $src); rename("$path.tmp", $path);
echo "wrote $n versions then final $final\n";
');

$port = rh_start('include', array(), 3);

/**
 * Run the writer for $secs while clients include the file; return per-status
 * counts, mixtures and responses after the writer stopped.
 */
function race($port, $name, $mode, $transform, $vary, $secs = 3.0)
{
	global $base, $root;
	$path = $root . DS . $name . '.php';
	file_put_contents($path, versionSource(1, $transform, $vary));
	$final = 900000 + random_int(1, 99999);
	$writer = rh_spawn($base . DS . 'writer.php',
		array($path, $mode, $secs, $transform ? 1 : 0, $vary ? 1 : 0, $final));
	$stats = array('whole' => 0, 'mixed' => 0, 'empty' => 0, '500' => 0, 'other' => array(),
		'hdrMismatch' => 0, 'versions' => array());
	$until = microtime(true) + $secs;
	$seq = 0;
	while (microtime(true) < $until) {
		$reqs = array();
		for ($i = 0; $i < 24; ++$i) $reqs[] = array('path' => "/index.php?f=$name&n=" . (++$seq));
		foreach (rh_many($port, $reqs, 8, 20.0, null, 2.0) as $r) {
			if ($r['status'] === 200) {
				$v = versionOf($r['body']);
				if ($v !== null) {
					++$stats['whole'];
					$stats['versions'][$v] = true;
					if ($transform and ($r['headers']['x-race'] ?? '') !== sprintf('%06d', $v)) ++$stats['hdrMismatch'];
				} elseif (strpos($r['body'], 'NOT-A-VERSION:1') === 0) {
					++$stats['empty'];   // include of a zero-length file returns 1
				} else {
					++$stats['mixed'];
				}
			} elseif ($r['status'] === 500) {
				++$stats['500'];
			} else {
				$stats['other'][] = $r['status'] . ' ' . $r['error'];
				if (getenv('RACE_DEBUG')) rh_note('other: ' . rh_short(json_encode(array($r['status'], $r['error'], $r['ms'], substr($r['raw'], 0, 600)))));
			}
		}
	}
	rh_wait($writer, 10.0);
	clearstatcache();
	$want = versionOf(trim(substr(include_file_value($path), 0)));
	// After the writer stopped: every worker, several times.
	$after = array();
	$reqs = array();
	for ($i = 0; $i < 12; ++$i) $reqs[] = array('path' => "/index.php?f=$name&after=$i");
	foreach (rh_many($port, $reqs, 3, 20.0, null, 2.0) as $r) {
		$after[] = $r['status'] === 200 ? versionOf($r['body']) : 'status ' . $r['status'];
	}
	return array($stats, $final, $want, $after);
}

/** What the file on disk returns, evaluated here without any cache. */
function include_file_value($path)
{
	$src = file_get_contents($path);
	if (preg_match("/return '([^']*)';/", $src, $m)) return $m[1];
	return '';
}

function report($label, $stats)
{
	rh_note(sprintf('%s: %d whole, %d mixed, %d empty, %d x 500, %d distinct versions seen',
		$label, $stats['whole'], $stats['mixed'], $stats['empty'], $stats['500'],
		count($stats['versions'])));
}

// ── A. Atomic rename, same-size versions ────────────────────────

list($s, $final, $want, $after) = race($port, 'atomic', 'rename', false, false);
report('A atomic', $s);
check('A: the final version is what is on disk', $want, $final);
check('A: every response during the rewrites was one whole version (no mixture)', $s['mixed'], 0);
check('A: ...no request failed (atomic replacement never shows a partial file)',
	array($s['500'], $s['empty'], $s['other']), array(0, 0, array()));
check('A: the writer really raced the readers (several versions observed)', count($s['versions']) > 3, true);
$stale = array_values(array_filter($after, function ($v) use ($final) { return $v !== $final; }));
rh_bug('A: after the writer stops, every request returns the final version (stale: '
	. count($stale) . '/12' . ($stale ? ', e.g. ' . var_export($stale[0], true) : '') . ')',
	!$stale, BUG_CONTENT_CACHE);

// ── B. Atomic rename, file needs the transform, sizes vary ──────

list($s, $final, $want, $after) = race($port, 'transformed', 'rename', true, true);
report('B transformed', $s);
check('B: every response was one whole version', $s['mixed'], 0);
check('B: ...and its header() named the same version as its body', $s['hdrMismatch'], 0);
check('B: ...no request failed', array($s['500'], $s['empty'], $s['other']), array(0, 0, array()));
$stale = array_values(array_filter($after, function ($v) use ($final) { return $v !== $final; }));
rh_bug('B: after the writer stops, every request returns the final version (stale: '
	. count($stale) . '/12' . ($stale ? ', e.g. ' . var_export($stale[0], true) : '') . ')',
	!$stale, BUG_TRANSFORM_CACHE);

// ── C. In-place writes that leave the file partial ──────────────

list($s, $final, $want, $after) = race($port, 'inplace', 'inplace', false, true);
report('C in-place', $s);
check('C: a partial file never came back as a 200 with a mixed or cut body', $s['mixed'], 0);
$lost = count(array_filter($s['other'], function ($o) { return strpos($o, '502') === 0; }));
$otherThan502 = array_values(array_filter($s['other'], function ($o) { return strpos($o, '502') !== 0; }));
check('C: no request failed other than by 500 (parse error) or a lost worker', $otherThan502, array());
if (extension_loaded('ionCube Loader')) {
	// ionCube hooks compile_file and reads the real file itself (mmap), so
	// a file truncated by another process under it raises SIGBUS and the
	// worker dies: the request gets 502 "Worker died". php-fpm with the
	// same loader loses its child the same way. What the engine owes here
	// is that it costs only that request and the pool recovers -- asserted
	// below and by the "after" requests.
	if ($lost) rh_note("C: $lost worker(s) lost to SIGBUS in the ionCube Loader compiling a file truncated under it (environmental; see docblock)");
	check('C: the pool recovered to 3 workers after those losses',
		rh_wait_children(rh_server_pid('include'), 3, 5.0), 3);
} else {
	check('C: no request lost its worker (no 502)', $lost, 0);
}
check('C: whole versions were still served between partial moments', $s['whole'] > 0, true);
if ($s['other'] and getenv('RACE_DEBUG')) {
	foreach (explode("\n", rh_log('include')) as $l) if (preg_match('/502|replaced|died|Fatal|fatal|Segm|signal/i', $l)) rh_note('log: ' . $l);
}
$stale = array_values(array_filter($after, function ($v) use ($final) { return $v !== $final; }));
rh_bug('C: after the writer stops, every request returns the final version (stale: '
	. count($stale) . '/12' . ($stale ? ', e.g. ' . var_export($stale[0], true) : '') . ')',
	!$stale, BUG_CONTENT_CACHE);

// ── D. Deterministic same-second rewrites, one worker ───────────

$one = rh_start('one', array(), 1);

/** Write A, include it, write B within the same second, include again. */
function sameSecond($port, $name, $srcA, $srcB)
{
	global $root;
	$path = $root . DS . $name . '.php';
	for ($try = 0; $try < 5; ++$try) {
		// Start just after a second boundary so both writes share a second.
		$t = microtime(true); usleep((int) ((ceil($t) - $t) * 1e6) + 20000);
		file_put_contents("$path.tmp", $srcA); rename("$path.tmp", $path);
		$a = rh_get($port, "/index.php?f=$name&x=a$try");
		file_put_contents("$path.tmp", $srcB); rename("$path.tmp", $path);
		$b = rh_get($port, "/index.php?f=$name&x=b$try");
		clearstatcache();
		if ((int) floor(microtime(true)) === (int) floor($t) + 1) {
			return array(versionOf($a['body']), versionOf($b['body']));
		}
	}
	return array(null, null);
}

list($a, $b) = sameSecond($one, 'samesize', versionSource(111111), versionSource(222222));
check('D1: version A is served first', $a, 111111);
rh_bug("D1: a same-size rewrite within the same second is served (got " . var_export($b, true) . ')',
	$b === 222222, BUG_CONTENT_CACHE);

list($a, $b) = sameSecond($one, 'difsize', versionSource(333333), versionSource(444444, false, true) . "\n//pad\n");
check('D2: a different-size plain rewrite within the same second is served', array($a, $b), array(333333, 444444));

list($a, $b) = sameSecond($one, 'difxform', versionSource(555555, true), versionSource(666666, true, true) . "\n//pad\n");
check('D3: version A (needs the transform) is served first', $a, 555555);
rh_bug("D3: a different-size rewrite of a transformed file within the same second is served (got "
	. var_export($b, true) . ')', $b === 666666, BUG_TRANSFORM_CACHE);

// Control: the same same-second rewrites with the compat layer off (plain
// PHP include, still a persistent worker, same ionCube loader). If these
// pass, the staleness above is the compat caches' and nobody else's.
$plain = rh_start('plain', array('Q' => array('compat' => array('skipSourceCodeTransform' => true))), 1);
list($a, $b) = sameSecond($plain, 'ctrlsame', versionSource(777777), versionSource(888888));
check('control (compat off): the same-size same-second rewrite is served', array($a, $b), array(777777, 888888));
// Without the compat layer header() is PHP's own, which cannot send once the
// server has written to stdout: it warns, and where display_errors is on (a
// stock php.ini-less build) the warning lands in the body. Silence it -- this
// control is about which version is included, not about headers.
$plainHeader = function ($src) { return str_replace("header(", "@header(", $src); };
list($a, $b) = sameSecond($plain, 'ctrlxform', $plainHeader(versionSource(123123, true)), $plainHeader(versionSource(321321, true, true)) . "\n//pad\n");
check('control (compat off): the different-size same-second rewrite is served', array($a, $b), array(123123, 321321));

rh_finish();

