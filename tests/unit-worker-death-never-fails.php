#!/usr/bin/env php
<?php

/**
 * A worker dying never costs a visitor a request that could have been served.
 *
 * Found on a dynamic pool (tests/unit-worker-pool-dynamic.php): a worker killed
 * while idle was still handed the next request, which came back
 * "502 Worker died". An idle worker's socket is not watched, so nothing had
 * noticed. Behind that were two more defects in the same place:
 *
 *   - the parent asked posix_kill($pid, 0) whether a worker that had gone
 *     quiet was alive, and that succeeds on a zombie -- so it returned and
 *     left the watcher firing on the dead socket;
 *   - a request re-queued after a failed write was ALSO answered 502 by the
 *     recycle that followed, so one request got two answers.
 *
 * What stops it now, in layers, and what this asserts for each:
 *
 *   1. a timer sweeps idle workers every two seconds and replaces dead ones;
 *   2. dispatch checks the chosen idle worker with a reaping waitpid first;
 *   3. a GET/HEAD/OPTIONS whose worker dies before writing any of the answer
 *      is run again on another worker -- once, so a request that itself kills
 *      its worker costs one extra worker, not the pool;
 *   4. a POST is never run twice: it gets a 502 rather than a duplicate
 *      order, comment or publish;
 *   5. whatever happens, the pool returns to its size and leaves no zombies,
 *      and the parent does not grow with the workers it has replaced.
 *
 *   php tests/unit-worker-death-never-fails.php
 */

require __DIR__ . '/fixtures/race-harness.php';

// Helper mode: one request in the background, result written as JSON, so the
// test can kill the worker serving it meanwhile.
if (($argv[1] ?? '') === '--fetch') {
	$r = rh_get((int) $argv[2], $argv[3], 20, $argv[5] ?? 'GET', ($argv[5] ?? '') === 'POST' ? 'x=1' : '',
		($argv[5] ?? '') === 'POST' ? array('Content-Type' => 'application/x-www-form-urlencoded') : array());
	file_put_contents($argv[4], json_encode(array('status' => $r['status'], 'body' => $r['body'], 'error' => $r['error'])));
	exit(0);
}

rh_require_pool();
list($base, $root) = rh_setup('death-never-fails');

// Every request appends a line to a per-tag file, so the test can count how
// many times the application actually ran it.
file_put_contents($root . DS . 'index.php', '<?php
$t = isset($_GET["t"]) ? preg_replace("/[^a-z0-9]/", "", $_GET["t"]) : "none";
$a = isset($_GET["a"]) ? $_GET["a"] : "";
file_put_contents(dirname(__DIR__) . "/ran-" . $t, getmypid() . "\n", FILE_APPEND);
if ($a === "slow") {
	file_put_contents(dirname(__DIR__) . "/busy-" . $t, getmypid());
	usleep(1500000);
}
if ($a === "suicide") { echo "partial"; posix_kill(getmypid(), 9); usleep(1000000); }
if ($a === "retire") { Q_WebServer_Pool::retireAfterResponse("test"); }
echo getmypid();
');

function ran($t)
{
	$f = $GLOBALS['rh']['base'] . DS . 'ran-' . $t;
	return is_file($f) ? count(file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : 0;
}

function waitFile($f, $timeout = 5.0)
{
	$until = microtime(true) + $timeout;
	while (microtime(true) < $until) { if (is_file($f) and filesize($f) > 0) return true; usleep(20000); }
	return false;
}

function zombies($pid)
{
	return count(array_filter(rh_children($pid), function ($s) { return $s === 'Z'; }));
}

function livePids($pid)
{
	return array_keys(array_filter(rh_children($pid), function ($s) { return $s !== 'Z'; }));
}

function vmRssKb($pid)
{
	return preg_match('/^VmRSS:\s+(\d+)/m', (string) @file_get_contents("/proc/$pid/status"), $m) ? (int) $m[1] : 0;
}

// ── The retry rule itself ────────────────────────────────────────────────

if (!class_exists('Q_WebServer_Pool', false)) require_once __DIR__ . '/../src/Q/WebServer/Pool.php';
check('a GET whose worker died is retried', Q_WebServer_Pool::mayRetry(array('method' => 'GET')), true);
check('...as are HEAD and OPTIONS', Q_WebServer_Pool::mayRetry(array('method' => 'head'))
	&& Q_WebServer_Pool::mayRetry(array('method' => 'OPTIONS')), true);
check('...but only once', Q_WebServer_Pool::mayRetry(array('method' => 'GET', 'poolRetries' => 1)), false);
foreach (array('POST', 'PUT', 'PATCH', 'DELETE') as $m) {
	check("a $m is never run twice", Q_WebServer_Pool::mayRetry(array('method' => $m)), false);
}
check('a request with no method is not retried', Q_WebServer_Pool::mayRetry(array()), false);

// ── Fixed pool of two ────────────────────────────────────────────────────

$port = rh_start('fixed', array(), 2);
$spid = rh_server_pid('fixed');
rh_wait_children($spid, 2);

// Every worker killed while idle, then a request straight away -- before the
// sweep can have run. Five rounds.
$bad = array();
for ($round = 1; $round <= 5; ++$round) {
	foreach (livePids($spid) as $w) posix_kill($w, 9);
	$r = rh_get($port, "/index.php?t=idle$round");
	if ($r['status'] !== 200 or !ctype_digit($r['body'])) $bad[] = "round $round: {$r['status']} {$r['error']} " . substr($r['raw'], 0, 80);
	rh_wait_children($spid, 2);
}
check('a request sent just after every idle worker was killed is answered 200', $bad, array());

// One idle worker killed, then a burst.
$kids = livePids($spid);
posix_kill($kids[0], 9);
$reqs = array();
for ($i = 0; $i < 10; ++$i) $reqs[] = array('path' => "/index.php?t=burst$i");
$res = rh_many($port, $reqs, 10);
$ok = 0; foreach ($res as $r) if ($r['status'] === 200) ++$ok;
check('a burst right after a worker was killed is answered in full', $ok, 10);
rh_wait_children($spid, 2);

// A GET killed from outside while it is being served: run again elsewhere.
@unlink("$base/busy-slowget");
$bg = rh_spawn(__FILE__, array('--fetch', $port, '/index.php?t=slowget&a=slow', "$base/out-slowget"));
$killed = waitFile("$base/busy-slowget") ? (int) file_get_contents("$base/busy-slowget") : -1;
if ($killed > 0) posix_kill($killed, 9);
rh_wait($bg, 15);
$out = json_decode((string) @file_get_contents("$base/out-slowget"), true) ?: array();
check('a GET whose worker is killed mid-request is still answered 200', $out['status'] ?? null, 200);
check('...by a different worker', isset($out['body']) && (int) $out['body'] !== $killed && ctype_digit($out['body']), true);
rh_wait_children($spid, 2);

// A POST killed the same way: 502, and it ran exactly once.
@unlink("$base/busy-slowpost");
$bg = rh_spawn(__FILE__, array('--fetch', $port, '/index.php?t=slowpost&a=slow', "$base/out-slowpost", 'POST'));
if (waitFile("$base/busy-slowpost")) posix_kill((int) file_get_contents("$base/busy-slowpost"), 9);
rh_wait($bg, 15);
$out = json_decode((string) @file_get_contents("$base/out-slowpost"), true) ?: array();
check('a POST whose worker is killed mid-request is answered 502, not hung', $out['status'] ?? null, 502);
check('...and is never run a second time', ran('slowpost'), 1);
rh_wait_children($spid, 2);

// A GET that kills its own worker every time: retried once, then 502.
$r = rh_get($port, '/index.php?t=suicide&a=suicide', 15);
check('a GET that kills every worker it runs on is answered 502, not hung', $r['status'], 502);
check('...after exactly one retry (two workers, not the pool)', ran('suicide'), 2);
check('...and the partial output of the dead worker is not sent', strpos($r['body'], 'partial'), false);
check('the pool returns to its size', rh_wait_children($spid, 2), 2);
$r = rh_get($port, '/index.php?t=after');
check('and serves normally afterwards', $r['status'], 200);

// Nothing left behind.
usleep(2500000);
check('no zombie workers are left', zombies($spid), 0);

// A worker that dies while idle is replaced without waiting for a request.
$kids = livePids($spid);
posix_kill($kids[0], 9);
usleep(3500000);
check('an idle worker that died is noticed by the sweep', strpos(rh_log('fixed'), 'exited while idle') !== false, true);
check('...and replaced before any request arrives', count(livePids($spid)), 2);
check('...leaving no zombie', zombies($spid), 0);

// ── The parent does not grow with the workers it replaces ────────────────

$port1 = rh_start('churn', array(), 1);
$cpid = rh_server_pid('churn');
for ($i = 0; $i < 40; ++$i) rh_get($port1, '/index.php?t=warm&a=retire');
$before = vmRssKb($cpid);
$bad = 0;
for ($i = 0; $i < 300; ++$i) {
	$r = rh_get($port1, "/index.php?t=churn&a=retire");
	if ($r['status'] !== 200) ++$bad;
}
$grew = vmRssKb($cpid) - $before;
check('300 requests that each replace their worker are all answered', $bad, 0);
check(sprintf('the parent did not grow with 300 replaced workers (%+d KB)', $grew), $grew < 3072, true);
usleep(2500000);
check('...and left no zombies', zombies($cpid), 0);

// ── Dynamic pool: deaths never take it below its spare count ─────────────

$port2 = rh_start('dyn', array('Q' => array('webserver' => array(
	'spareWorkers' => 2, 'idleWorkerTimeout' => 2))), 6);
$dpid = rh_server_pid('dyn');
rh_wait_children($dpid, 2);
foreach (livePids($dpid) as $w) posix_kill($w, 9);
$r = rh_get($port2, '/index.php?t=dyn');
check('a dynamic pool with every worker killed still answers', $r['status'], 200);
check('...and returns to its spare count', rh_wait_children($dpid, 2, 8), 2);
usleep(2500000);
check('...with no zombies', zombies($dpid), 0);

rh_finish();
