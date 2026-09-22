#!/usr/bin/env php
<?php
/**
 * Is bin/qbixserver.phar built from the sources next to it?
 *
 * The phar is committed and is what a Composer install runs, but nothing
 * rebuilds it, so it drifts away from src/ silently. Comparing bytes does not
 * work -- two builds of identical sources differ in the phar's own metadata --
 * so this compares what is inside: the same set of paths build-phar.php would
 * add, each with the same contents.
 *
 * Exit 0 when current, 1 when stale, 2 when the phar is missing or unreadable.
 */

$base = dirname(__DIR__);
$pharFile = $base . '/bin/qbixserver.phar';

if (!is_file($pharFile)) {
	fwrite(STDERR, "bin/qbixserver.phar is missing\n");
	exit(2);
}

// The same selection build-phar.php makes: all of src/, qbixserver.php, all of web/.
$expected = array();
$add = function ($dir) use (&$expected, $base) {
	if (!is_dir($dir)) return;
	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
	);
	foreach ($it as $file) {
		if (!$file->isFile()) continue;
		$expected[substr($file->getPathname(), strlen($base) + 1)] = sha1_file($file->getPathname());
	}
};
$add($base . '/src');
$expected['qbixserver.php'] = sha1_file($base . '/qbixserver.php');
$add($base . '/web');

try {
	$phar = new Phar($pharFile);
} catch (Throwable $e) {
	fwrite(STDERR, "bin/qbixserver.phar cannot be opened: " . $e->getMessage() . "\n");
	exit(2);
}

$inside = array();
$prefix = 'phar://' . $pharFile;
foreach (new RecursiveIteratorIterator($phar) as $file) {
	$rel = ltrim(substr($file->getPathname(), strlen($prefix)), '/');
	$inside[$rel] = sha1_file($file->getPathname());
}

$missing = array_diff_key($expected, $inside);
$extra   = array_diff_key($inside, $expected);
$changed = array();
foreach ($expected as $rel => $hash) {
	if (isset($inside[$rel]) and $inside[$rel] !== $hash) $changed[] = $rel;
}

if (!$missing and !$extra and !$changed) {
	echo "bin/qbixserver.phar matches its sources (" . count($expected) . " files)\n";
	exit(0);
}

fwrite(STDERR, "bin/qbixserver.phar is older than the sources it is built from.\n");
$report = function ($label, $list) {
	if (!$list) return;
	fwrite(STDERR, "  $label: " . count($list) . "\n");
	foreach (array_slice(array_values($list), 0, 10) as $rel) fwrite(STDERR, "    $rel\n");
	if (count($list) > 10) fwrite(STDERR, "    ...\n");
};
$report('in the tree but not in the phar', array_keys($missing));
$report('in the phar but not in the tree', array_keys($extra));
$report('different contents', $changed);
fwrite(STDERR, "\nRebuild it and commit:\n");
fwrite(STDERR, "  php -d phar.readonly=0 build-phar.php && git add bin/qbixserver.phar\n");
exit(1);
