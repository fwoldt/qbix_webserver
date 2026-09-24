<?php

/**
 * The compat stat memo never hands a worker an answer about a file that
 * another process has since changed -- across requests, and within one
 * request once the application has asked for fresh answers.
 *
 * Background. Under the compat transform, filemtime() and filesize() go to
 * Q_WebServer_Compat::_filemtime/_filesize, which answer from
 * Q_WebServer_CompatFileWrapper::includeStat(): one real stat (via libcurl's
 * file://) per path per request, remembered in $includeMemo; stat() and
 * friends go through url_stat() -> realStat(), remembered in $statMemo. Both
 * are emptied at request end and on writes this process makes through the
 * wrapper. file_exists()/is_file()/is_dir() ask glob() every time.
 *
 * Properties, and how each would fail:
 *
 *   6. Across requests (per-request memo, persistent workers): request 1 on
 *      every worker caches a file's stat; another process changes the file
 *      (new size, new mtime); request 2 on every worker must report the new
 *      mtime and size. A memo that outlived its request -- e.g. restored by
 *      the snapshot, or not cleared in Compat::shutdown() -- fails this.
 *
 *   7. Within a request, against a concurrent creator/deleter. Another process
 *      loops: delete the file, wait 20 ms, create it with a new generation
 *      whose size is strictly larger, wait 30 ms. A request polls for 1.5 s,
 *      each sample being
 *
 *          clearstatcache(); e1 = file_exists(f); s = filesize(f);
 *          c = strlen(file_get_contents(f)); e2 = file_exists(f);
 *
 *      Because the writer never makes two transitions within microseconds,
 *      e1 && e2 means one generation existed for the whole sample. Then:
 *        (a) !e1 && !e2  =>  filesize is false (no answer for a missing file);
 *        (b)  e1 &&  e2 && c !== false  =>  s === c (the size of the file
 *             that is there, not of one deleted earlier);
 *        (c) sizes seen over the request are non-decreasing and more than a
 *             handful distinct (the writer made ~30 generations).
 *      Plain PHP satisfies all three -- the test first runs the same loop in
 *      this (uncompat'ed) process as a control. (a) holds under compat
 *      because a missing path bypasses the memo. (b) and (c) do not.
 *
 * Real defect (7b/7c): the memo is not dropped by clearstatcache(), which is
 * not shimmed, so after the first stat of a path in a request every later
 * filemtime()/filesize()/stat() of it in that request returns the first
 * answer, whatever other processes have done since and however often the
 * application calls clearstatcache(). PHP documents clearstatcache() as the
 * way to get fresh answers; Exponential relies on it in
 * eZFSFileHandler::loadMetaData($force = true) (clearstatcache(), then
 * filesize()/filemtime()) and eZLog::writeStorageLog().
 * src/Q/WebServer/Compat.php: includeStat() ~l.2506 / realStat() ~l.2472
 * return the memo; forgetStats() ~l.2622 is called only at request end and on
 * the wrapper's own writes; there is no 'clearstatcache' entry in the
 * function map (~l.20-75).
 *
 *   php tests/unit-race-stat-memo.php
 */

require __DIR__ . '/fixtures/race-harness.php';
rh_require_pool();
list($base, $root) = rh_setup('race-statmemo');
@mkdir($root . DS . 'data', 0700);

const BUG_CLEARSTATCACHE = 'the per-request stat memo (Compat.php includeStat() ~l.2506 / realStat() ~l.2472) survives clearstatcache(), which is not in the shim map, so within a request filemtime()/filesize()/stat() of a path keep returning its first answer after another process has replaced the file, however often the application calls clearstatcache(). Plain PHP returns the fresh stat. Affects eZFSFileHandler::loadMetaData($force=true) and any lock/poll loop. Repro: one request does filesize(f); another process rewrites f larger; same request does clearstatcache(); filesize(f) -> old size.';

file_put_contents($root . DS . 'stat.php', '<?php
$f = __DIR__ . "/data/" . preg_replace("/[^a-z0-9]/", "", $_GET["f"]) . ".txt";
$a = $_GET["a"];
if ($a === "once") {
	clearstatcache();
	echo json_encode(array("pid" => getmypid(), "mtime" => @filemtime($f), "size" => @filesize($f),
		"exists" => file_exists($f), "stat" => ($s = @stat($f)) ? array($s["mtime"], $s["size"]) : false));
	return;
}
if ($a === "poll") {
	$until = microtime(true) + (float) $_GET["secs"];
	$samples = array();
	while (microtime(true) < $until) {
		clearstatcache();
		$e1 = file_exists($f);
		$s = @filesize($f);
		$c = @file_get_contents($f);
		$e2 = file_exists($f);
		$samples[] = array($e1, $s, $c === false ? false : strlen($c), $e2);
		usleep(5000);
	}
	echo json_encode($samples);
	return;
}
');

// The creator/deleter, a separate process.
file_put_contents($base . DS . 'flapper.php', '<?php
list(, $f, $secs) = $argv;
$until = microtime(true) + (float) $secs;
$gen = 0;
while (microtime(true) < $until) {
	@unlink($f);
	usleep(20000);
	++$gen;
	file_put_contents("$f.tmp", str_repeat("x", 100 + $gen * 10));
	rename("$f.tmp", $f);
	usleep(30000);
}
echo "generations $gen\n";
');

/** Judge a list of samples against properties (a), (b), (c). */
function judge($samples)
{
	$a = 0; $b = 0; $sizes = array(); $decreasing = 0; $last = -1; $bothExist = 0;
	foreach ($samples as $x) {
		list($e1, $s, $c, $e2) = $x;
		if (!$e1 and !$e2 and $s !== false) ++$a;
		if ($e1 and $e2 and $c !== false) {
			++$bothExist;
			if ($s !== $c) ++$b;
			if ($s !== false) {
				$sizes[$s] = true;
				if ($s < $last) ++$decreasing;
				$last = $s;
			}
		}
	}
	return array('a' => $a, 'b' => $b, 'distinct' => count($sizes), 'decreasing' => $decreasing,
		'samples' => count($samples), 'both' => $bothExist);
}

// ── 6. Across requests, on every worker ─────────────────────────

$workers = 3;
$port = rh_start('memo', array(), $workers);
$f = $root . DS . 'data' . DS . 'cross.txt';
file_put_contents($f, str_repeat('a', 1000));
touch($f, time() - 100);

/** Ask every worker (by pid) about the file; up to 60 tries to reach all. */
function askAll($port, $workers, $name)
{
	$seen = array();
	for ($i = 0; $i < 60 and count($seen) < $workers; ++$i) {
		$res = rh_many($port, array_fill(0, $workers * 2, array('path' => "/stat.php?a=once&f=$name&n=$i")), $workers * 2, 10.0);
		foreach ($res as $r) {
			$j = json_decode($r['body'], true);
			if ($j and !isset($seen[$j['pid']])) $seen[$j['pid']] = $j;
		}
	}
	return $seen;
}

$before = askAll($port, $workers, 'cross');
check('6: every worker answered the first round', count($before), $workers);
$okBefore = true;
foreach ($before as $j) $okBefore = ($okBefore && $j['size'] === 1000 && $j['mtime'] === filemtime($f));
check('6: ...with the file\'s real mtime and size', $okBefore, true);

// Another process (this one) changes the file.
file_put_contents($f, str_repeat('b', 2345));
touch($f, time() - 50);
clearstatcache();
$wantM = filemtime($f);
$after = askAll($port, $workers, 'cross');
$stale = array();
foreach ($after as $pid => $j) {
	if (!isset($before[$pid])) continue;
	if ($j['mtime'] !== $wantM or $j['size'] !== 2345 or $j['stat'] !== array($wantM, 2345)) {
		$stale[] = "$pid: " . json_encode(array($j['mtime'], $j['size'], $j['stat']));
	}
}
check('6: the same workers\' next requests see the new mtime and size (filemtime, filesize, stat)',
	$stale, array());
check('6: ...and that was asked of workers that had cached the old stat',
	count(array_intersect_key($after, $before)) >= 1, true);

// ── 7. Within one request, against a concurrent creator/deleter ─

$g = $root . DS . 'data' . DS . 'flap.txt';
file_put_contents($g, str_repeat('x', 100));

// Control: plain PHP, this process, same loop.
$flap = rh_spawn($base . DS . 'flapper.php', array($g, 2.0));
usleep(100000);
$samples = array();
$until = microtime(true) + 1.5;
while (microtime(true) < $until) {
	clearstatcache();
	$e1 = file_exists($g); $s = @filesize($g); $c = @file_get_contents($g); $e2 = file_exists($g);
	$samples[] = array($e1, $s, $c === false ? false : strlen($c), $e2);
	usleep(5000);
}
rh_wait($flap, 5.0);
$native = judge($samples);
check('7 control (plain PHP): (a) no size for a file missing throughout a sample', $native['a'], 0);
check('7 control (plain PHP): (b) size == bytes read when one generation spans the sample', $native['b'], 0);
check('7 control (plain PHP): (c) sizes never go backwards', $native['decreasing'], 0);
check('7 control (plain PHP): (c) ...and many generations are seen', $native['distinct'] >= 8, true);

// The same loop inside a worker. From the same starting file as the control:
// the control's writer left its last, largest generation behind, and this
// writer counts up from the first again, which read as a size going
// backwards whenever the worker's first sample caught the leftover.
file_put_contents($g, str_repeat('x', 100));
$flap = rh_spawn($base . DS . 'flapper.php', array($g, 2.0));
usleep(100000);
$r = rh_get($port, '/stat.php?a=poll&f=flap&secs=1.5', 15.0);
rh_wait($flap, 5.0);
$samples = json_decode($r['body'], true) ?: array();
check('7: the polling request was answered', $r['status'] === 200 and count($samples) > 50, true);
$w = judge($samples);
if (getenv('RACE_DEBUG') and $w['decreasing']) {
	$last = -1;
	foreach ($samples as $k => $x) {
		if ($x[0] and $x[3] and $x[2] !== false and $x[1] !== false) {
			if ($x[1] < $last) rh_note('7 backwards at ' . $k . ': ' . json_encode(array_slice($samples, max(0, $k - 3), 5)));
			$last = $x[1];
		}
	}
}
rh_note(sprintf('7: worker: %d samples, %d with one generation throughout, %d distinct sizes; plain PHP: %d distinct',
	$w['samples'], $w['both'], $w['distinct'], $native['distinct']));
check('7: (a) no size is reported for a file missing throughout a sample', $w['a'], 0);
rh_bug("7: (c) sizes never go backwards ({$w['decreasing']} times)", $w['decreasing'] === 0, BUG_CLEARSTATCACHE);
rh_bug("7: (b) filesize() after clearstatcache() is the size of the file that is there ({$w['b']} of {$w['both']} samples wrong)",
	$w['b'] === 0, BUG_CLEARSTATCACHE);
rh_bug("7: (c) a request polling with clearstatcache() sees the file change ({$w['distinct']} distinct sizes; plain PHP saw {$native['distinct']})",
	$w['distinct'] >= 8, BUG_CLEARSTATCACHE);

rh_finish();
