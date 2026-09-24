#!/usr/bin/env php
<?php
/**
 * The Docker build matrix, from the manifest: every PHP version it lists x
 * every binary variant, for amd64 and arm64. Printed as GitHub Actions
 * outputs:
 *
 *   builds=[{"php":"8.3","variant":"standard","arch":"amd64"}, ...]
 *   images=[{"php":"8.3","variant":"standard"}, ...]
 *   newest=8.5
 *
 *   php packaging/docker/matrix.php [php versions] [variants]
 *
 * Each argument is a comma list; empty means all. Unknown names fail.
 */
require dirname(__DIR__) . '/ci/baseline.php';

function pick($arg, array $all, $what)
{
	$arg = trim((string) $arg);
	if ($arg === '') return $all;
	$want = array_values(array_filter(array_map('trim', explode(',', $arg)), 'strlen'));
	if ($bad = array_diff($want, $all)) {
		fwrite(STDERR, "unknown $what: " . implode(', ', $bad) . ' (known: ' . implode(', ', $all) . ")\n");
		exit(2);
	}
	return $want;
}

$phps = baseline_php_versions();
$variants = array_values(array_filter(baseline_variants(), function ($v) {
	return empty(Q_WebServer_Extensions::manifest()['variants'][$v]['kit']);
}));
$builds = array();
$images = array();
foreach (pick($argv[1] ?? '', $phps, 'PHP version') as $php) {
	foreach (pick($argv[2] ?? '', $variants, 'variant') as $v) {
		$images[] = array('php' => $php, 'variant' => $v);
		foreach (array('amd64', 'arm64') as $arch) {
			$builds[] = array('php' => $php, 'variant' => $v, 'arch' => $arch);
		}
	}
}
usort($phps, 'version_compare');
echo 'builds=' . json_encode($builds), "\n";
echo 'images=' . json_encode($images), "\n";
echo 'newest=' . end($phps), "\n";
