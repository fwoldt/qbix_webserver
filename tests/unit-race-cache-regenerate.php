<?php

/**
 * Several workers regenerating the same cache file at once -- the pattern of
 * every PHP application cache (compiled templates, INI caches, class maps):
 * "if the file is missing or stale, write it to a temp file, rename it into
 * place, then include it."
 *
 * The races it guards:
 *
 *   - two workers renaming over each other while a third includes: every
 *     include must return one complete, self-consistent generation (the file
 *     carries an md5 of its own payload), never a torn or empty file;
 *   - the compat wrapper sits under every one of those calls: file_exists()
 *     and filemtime() are transformed to Compat shims with a per-request stat
 *     memo, file_put_contents()/rename() go through the wrapper and must drop
 *     that memo, and include is served from a per-worker content cache. A
 *     memo or cache that survived the write would show up as an include that
 *     returns something other than what is on disk;
 *   - read-your-own-write: a request that has just regenerated the file must
 *     include what it wrote (with one worker there is no other writer, so
 *     anything else is the cache serving an older generation).
 *
 * How it fails: a 5xx or 502 (a torn file failing to parse, a worker lost),
 * an include whose md5 does not match (mixture), or -- the defect this finds
 * -- the regenerating request getting the previous generation back.
 *
 * Real defect: when the new generation has the same size as the previous one
 * and is written within the same second, the worker's include content cache
 * (Compat.php stream_open ~l.2708-2712, keyed on one-second mtime + size) is
 * not dropped by the worker's own write or rename -- forgetStats() clears the
 * stat memo only -- so the include returns the generation the worker cached
 * before, not the one it just wrote.
 *
 *   php tests/unit-race-cache-regenerate.php
 */

require __DIR__ . '/fixtures/race-harness.php';
rh_require_pool();
list($base, $root) = rh_setup('race-regen');
@mkdir($root . DS . 'cache', 0700);

const BUG_OWN_WRITE = 'a request that rewrites a file (temp + rename, through the wrapper) and then includes it gets the PREVIOUS contents when the new file has the same size and the same one-second mtime: the include content cache (Compat.php stream_open ~l.2708-2712) is keyed on mtime+size and is not dropped by the wrapper\'s own writes/renames (stream_open write modes and rename() call forgetStats(), which empties only $statMemo/$includeMemo, not $contentCache). Repro: workers=1; two sequential requests within one second each writing a same-length payload and including it; the second returns the first\'s payload.';

file_put_contents($root . DS . 'regen.php', '<?php
$f = __DIR__ . "/cache/data.php";
$t = preg_replace("/[^a-z0-9]/", "", $_GET["t"]);
$regen = false;
if (!empty($_GET["force"]) or !file_exists($f) or time() - filemtime($f) >= 1) {
	// Fixed-width tokens: every generation has the same size.
	$data = str_repeat($t, 400);
	$src = "<?php return " . var_export(array("gen" => $t, "data" => $data, "md5" => md5($data)), true) . ";\n";
	$tmp = $f . "." . getmypid() . "." . mt_rand();
	file_put_contents($tmp, $src);
	rename($tmp, $f);
	$regen = true;
}
$d = include $f;
$valid = (is_array($d) && isset($d["data"], $d["md5"], $d["gen"])
	&& md5($d["data"]) === $d["md5"] && $d["data"] === str_repeat($d["gen"], 400));
echo json_encode(array("valid" => $valid, "regen" => $regen,
	"gen" => is_array($d) ? $d["gen"] : null, "pid" => getmypid()));
');

function token($i)
{
	static $seq = 0;
	return sprintf('g%05dx%s', $i + 100 * $seq++, bin2hex(random_bytes(3)));
}

// ── Concurrent regeneration, 4 workers ──────────────────────────

$port = rh_start('regen', array(), 4);
$written = array();   // every token any request was given, across both modes
foreach (array('force' => 1, 'stale' => 0) as $mode => $force) {
	$reqs = array(); $tokens = array();
	for ($round = 0; $round < ($force ? 1 : 3); ++$round) {
		for ($i = 0; $i < 50; ++$i) {
			$t = token(count($tokens));
			$tokens[] = $t;
			$written[] = $t;
			$reqs[] = array('path' => "/regen.php?t=$t" . ($force ? '&force=1' : ''));
		}
	}
	$all = array();
	// The stale mode runs three bursts a second apart, so each finds the
	// file stale and a crowd of workers regenerates it at once.
	foreach (array_chunk($reqs, 50, true) as $n => $chunk) {
		if ($n) usleep(1100000);
		$all += rh_many($port, $chunk, 25, 20.0, null, 2.0);
	}
	$codes = array(); $invalid = 0; $regens = 0; $gens = array();
	foreach ($all as $r) {
		$codes[$r['status']] = ($codes[$r['status']] ?? 0) + 1;
		$j = json_decode($r['body'], true);
		if (!$j or !$j['valid']) ++$invalid;
		if ($j and $j['regen']) ++$regens;
		if ($j and $j['gen']) $gens[$j['gen']] = true;
	}
	$n = count($reqs);
	check("$mode: all $n concurrent requests answered 200", $codes, array(200 => $n));
	check("$mode: every include returned one whole, self-consistent generation", $invalid, 0);
	check("$mode: every generation included was one some request wrote",
		array_values(array_diff(array_keys($gens), $written)), array());
	rh_note("$mode: $regens of $n requests regenerated the file, " . count($gens) . ' distinct generations served');
	rh_note_no_eof($mode, $all);
}
// The file on disk is intact afterwards.
$final = include $root . DS . 'cache' . DS . 'data.php';
check('the cache file on disk is a complete generation',
	is_array($final) and md5($final['data']) === $final['md5'], true);

// ── Read your own write, one worker, same second ────────────────

$one = rh_start('one', array(), 1);
$own = 0; $total = 0; $stale = array();
for ($try = 0; $try < 3; ++$try) {
	$t0 = microtime(true); usleep((int) ((ceil($t0) - $t0) * 1e6) + 20000);   // start of a second
	for ($i = 0; $i < 4; ++$i) {
		$t = token(9000 + $try * 10 + $i);
		$r = rh_get($one, "/regen.php?force=1&t=$t");
		$j = json_decode($r['body'], true);
		++$total;
		if ($j and $j['gen'] === $t) ++$own; else $stale[] = ($j['gen'] ?? '?') . " for $t";
	}
}
rh_bug("a request that regenerated the file includes what it wrote ($own of $total"
	. ($stale ? '; e.g. got ' . $stale[0] : '') . ')', $own === $total, BUG_OWN_WRITE);

rh_finish();
