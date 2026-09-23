#!/usr/bin/env php
<?php
/**
 * Build qbixserver.phar — single-file distributable.
 *
 * Usage: php -d phar.readonly=0 build-phar.php
 * Output: bin/qbixserver.phar
 */

if (ini_get('phar.readonly')) {
	echo "Error: phar.readonly is enabled.\n";
	echo "Run with: php -d phar.readonly=0 build-phar.php\n";
	exit(1);
}

$pharFile = __DIR__ . '/bin/qbixserver.phar';
if (file_exists($pharFile)) {
	unlink($pharFile);
}

@mkdir(__DIR__ . '/bin', 0755, true);

echo "Building qbixserver.phar...\n";

$phar = new Phar($pharFile, 0, 'qbixserver.phar');
$phar->startBuffering();

// Add src/ tree WITH the src/ prefix so __DIR__.'/src/Q.php' works
$baseDir = __DIR__;
$srcDir = __DIR__ . '/src';
$it = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS)
);
foreach ($it as $file) {
	$rel = substr($file->getPathname(), strlen($baseDir) + 1); // e.g. src/Q.php
	$phar->addFile($file->getPathname(), $rel);
}

// Add the main server file at root
$phar->addFile(__DIR__ . '/qbixserver.php', 'qbixserver.php');

// Add web/ directory for self-contained mode
$webDir = __DIR__ . '/web';
if (is_dir($webDir)) {
	$wit = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($webDir, RecursiveDirectoryIterator::SKIP_DOTS)
	);
	foreach ($wit as $file) {
		$rel = substr($file->getPathname(), strlen($baseDir) + 1); // e.g. web/index.php
		$phar->addFile($file->getPathname(), $rel);
	}
}

// Stamp the build: the short commit and the date it was built. Inside a phar
// there is no git to ask at runtime, so it is recorded now, at build time, and
// bundled. From source the server falls back to asking git directly.
$sha = @trim((string) @shell_exec('git -C ' . escapeshellarg(__DIR__)
	. ' rev-parse --short HEAD 2>/dev/null'));
$dirty = @trim((string) @shell_exec('git -C ' . escapeshellarg(__DIR__)
	. ' status --porcelain 2>/dev/null'));
if ($sha === '') $sha = 'unknown';
if ($dirty !== '') $sha .= '-dirty';
$buildDate = gmdate('Y-m-d H:i:s') . ' UTC';
$buildPhp = "<?php\n"
	. "if (!defined('QBIX_SERVER_BUILD')) define('QBIX_SERVER_BUILD', " . var_export($sha, true) . ");\n"
	. "if (!defined('QBIX_SERVER_BUILD_DATE')) define('QBIX_SERVER_BUILD_DATE', " . var_export($buildDate, true) . ");\n";
$phar->addFromString('qbix-build.php', $buildPhp);
// Also on disk beside qbixserver.php: the server is often run as a plain
// file (a Composer vendor copy), not through the phar stub, and then the
// bundled copy is never reached. Written both places, read whichever
// applies.
file_put_contents(__DIR__ . '/qbix-build.php', $buildPhp);
echo "Stamped build: $sha ($buildDate)\n";

$fileCount = $phar->count();

// Minimal stub
$stub = <<<'STUB'
#!/usr/bin/env php
<?php
Phar::mapPhar('qbixserver.phar');
@include 'phar://qbixserver.phar/qbix-build.php';
require 'phar://qbixserver.phar/qbixserver.php';
__HALT_COMPILER();
STUB;

$phar->setStub($stub);
$phar->stopBuffering();

chmod($pharFile, 0755);

$size = filesize($pharFile);
echo "Built: bin/qbixserver.phar (" . round($size / 1024) . " KB, $fileCount files)\n";
echo "Run:   php bin/qbixserver.phar --root=./web --port=8080\n";
