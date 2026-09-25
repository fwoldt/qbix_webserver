#!/usr/bin/env php
<?php
/**
 * /Q/metrics answers a browser with the metrics view and every scraper with
 * the Prometheus text format, unchanged.
 *
 * Asserted:
 *   - the format decision, for the Accept headers real clients send;
 *   - on a real server, curl, Prometheus, no Accept and ?format=text get
 *     text/plain; version=0.0.4 and exposition text, never HTML;
 *   - a browser gets the view: the toolbar with Metrics current, the brand,
 *     the raw link, and the same metrics as the text format.
 *
 *   php tests/unit-metrics-view.php
 */
require __DIR__ . '/fixtures/race-harness.php';

// ── The decision ─────────────────────────────────────────────────────────
require_once __DIR__ . '/../src/Q.php';
$cases = array(
	'no Accept'                 => array('', '', false),
	'curl'                      => array('*/*', '', false),
	'Prometheus'                => array('application/openmetrics-text;version=1.0.0,application/openmetrics-text;version=0.0.1;q=0.75,text/plain;version=0.0.4;q=0.5,*/*;q=0.1', '', false),
	'plain text first'          => array('text/plain, text/html;q=0.5', '', false),
	'Chrome'                    => array('text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8', '', true),
	'Firefox'                   => array('text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', '', true),
	'browser, ?format=text'     => array('text/html,*/*;q=0.8', 'format=text', false),
	'curl, ?format=html'        => array('*/*', 'format=html', true),
	'html refused (q=0)'        => array('text/html;q=0, */*', '', false),
);
foreach ($cases as $what => $c) {
	$parsed = array('headers' => $c[0] === '' ? array() : array('accept' => $c[0]), 'query' => $c[1]);
	check("decision: $what", Q_WebServer::metricsWantsHtml($parsed), $c[2]);
}

// ── A real server ────────────────────────────────────────────────────────
rh_require_pool();
list($base, $root) = rh_setup('metrics-view');
file_put_contents($root . DS . 'index.php', '<?php echo "app";');
$port = rh_start('mv', array(), 2);

$scrapers = array(
	'curl'       => array('Accept' => '*/*'),
	'no Accept'  => array(),
	'Prometheus' => array('Accept' => 'application/openmetrics-text;version=1.0.0,text/plain;version=0.0.4;q=0.5,*/*;q=0.1'),
);
$names = null;
foreach ($scrapers as $who => $h) {
	$r = rh_get($port, '/Q/metrics', 15.0, 'GET', '', $h);
	check("$who: 200", $r['status'], 200);
	check("$who: the Prometheus content type", $r['headers']['content-type'] ?? '', 'text/plain; version=0.0.4');
	check("$who: exposition text, not HTML", strpos($r['body'], '# HELP ') === 0 && stripos($r['body'], '<html') === false, true);
	preg_match_all('/^# TYPE (\S+)/m', $r['body'], $m);
	if ($names === null) $names = $m[1];
	check("$who: the same metric families", $m[1], $names);
}
$r = rh_get($port, '/Q/metrics?format=text', 15.0, 'GET', '', array('Accept' => 'text/html,*/*;q=0.8'));
check('?format=text from a browser: text', $r['headers']['content-type'] ?? '', 'text/plain; version=0.0.4');

$r = rh_get($port, '/Q/metrics', 15.0, 'GET', '', array('Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8'));
check('browser: 200', $r['status'], 200);
check('browser: HTML', strpos($r['headers']['content-type'] ?? '', 'text/html') === 0, true);
check('browser: Metrics is the current view', (bool) preg_match('#<a href="/Q/metrics" aria-current="page"#', $r['body']), true);
check('browser: the raw link', strpos($r['body'], 'href="/Q/metrics?format=text"') !== false, true);
check('browser: the shell item', strpos($r['body'], 'qshell') !== false || strpos($r['body'], 'shell') !== false, true);
check('browser: the metrics are embedded as data', (bool) preg_match('/var METRICS_TEXT = "# HELP /', $r['body']), true);
check('browser: no raw < from the metrics text', strpos(substr($r['body'], strpos($r['body'], 'var METRICS_TEXT')), '</script') > 0, true);
check('Vary: Accept on both', ($r['headers']['vary'] ?? ''), 'Accept');

rh_finish();
