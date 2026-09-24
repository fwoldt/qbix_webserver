<?php

/**
 * A file the warm-up included, rewritten after the server started, is served
 * as it is now -- not as the warm-up saw it.
 *
 * The warm-up runs in the parent and includes files through the compat
 * wrapper, which remembers each file's stat for the rest of the request.
 * "The request" was the warm-up, and nothing ended it: every worker forked
 * afterwards inherited those stats and compared a rewritten file against the
 * warm-up's mtime, found it unchanged and ran the old code. In fork-per-request
 * mode every request is a worker's first, so this lasted until a restart --
 * and a restart made while the stale file was still on disk kept it. On
 * alpha: a template edit reached Apache at once and Velocity not at all.
 *
 * Asserted in both worker modes: a file included by the warm-up and rewritten
 * after start is served with its new contents, on every request.
 *
 * Fails on the engine before Pool forgot the warm-up's stats.
 *
 *   php tests/unit-warmup-stats-do-not-outlive-start.php
 */

require __DIR__ . '/fixtures/race-harness.php';
rh_require_pool();
list($base, $root) = rh_setup('warmup-stats');

$part = $root . DS . 'part.php';
file_put_contents($root . DS . 'page.php', '<?php echo include __DIR__ . "/part.php";');
file_put_contents($base . DS . 'warmup.php', '<?php include ' . var_export($part, true) . ';');

foreach (array('persistent' => array(), 'fork-per-request' => array('forkPerRequest' => true)) as $mode => $ws) {
	// Old enough that its mtime is trusted, as a real file's would be.
	file_put_contents($part, '<?php return "v1";');
	touch($part, time() - 60);
	clearstatcache();

	$ws['warmup'] = $base . DS . 'warmup.php';
	$name = $mode === 'persistent' ? 'wp' : 'wf';
	$port = rh_start($name, array('Q' => array('webserver' => $ws)), 2);
	check("$mode: before the rewrite the page shows the warm-up's version", trim(rh_get($port, '/page.php')['body']), 'v1');

	// Rewritten after start, with an mtime that is not racy either.
	file_put_contents($part, '<?php return "v2";');
	touch($part, time() - 30);
	clearstatcache();

	$got = array();
	for ($i = 0; $i < 6; ++$i) $got[] = trim(rh_get($port, '/page.php')['body']);
	check("$mode: after the rewrite every request shows the new version", implode(' ', array_unique($got)), 'v2');
	rh_stop($GLOBALS['rh']['servers'][$name]);
	unset($GLOBALS['rh']['servers'][$name]);
}

rh_finish();
