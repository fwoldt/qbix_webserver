#!/usr/bin/env php
<?php
/**
 * Site installation info for the Q panel, for an installation of the
 * application this distribution serves.
 *
 * The panel runs it in the installation's directory. From anywhere else it
 * walks up from the current directory, then from its own, to the first
 * directory with autoload.php and lib/version.php.
 *
 * Prints the product release and every extension with its version,
 * copyright, licence, description and whether it is active.
 *
 * @package Q
 */
$rootDir = null;
foreach (array(getcwd(), __DIR__) as $start) {
    for ($dir = $start, $i = 0; $dir !== '' && $i < 12; $dir = dirname($dir), $i++) {
        if (is_file("$dir/autoload.php") && is_file("$dir/lib/version.php")) { $rootDir = $dir; break 2; }
        if (dirname($dir) === $dir) break;
    }
}
if (!$rootDir) {
    fwrite(STDERR, "Run this from an installation root (where autoload.php and lib/version.php live).\n");
    exit(1);
}
chdir($rootDir);
$autoload = $rootDir . '/autoload.php';

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

require_once $autoload;

$composer = is_file('composer.json') ? json_decode((string) @file_get_contents('composer.json'), true) : null;
$package = is_array($composer) && !empty($composer['name']) ? $composer['name'] . (!empty($composer['version']) ? ' ' . $composer['version'] : '') : '';

$product = 'Exponential';
if (is_file('lib/version.php')) {
    require_once 'lib/version.php';
    if (class_exists('eZPublishSDK', false)) {
        $ref = new ReflectionClass('eZPublishSDK');
        $major = $ref->getConstant('VERSION_MAJOR');
        $minor = $ref->getConstant('VERSION_MINOR');
        $release = $ref->getConstant('VERSION_RELEASE');
        $state = $ref->getConstant('VERSION_STATE');
        $edition = $ref->getConstant('EDITION');
        if (is_scalar($major) && is_scalar($minor)) {
            $product = (is_scalar($edition) && $edition !== '' ? $edition : 'Exponential') . ' ' . $major . '.' . $minor;
            if (is_scalar($release) && $release !== '') $product .= '.' . $release;
            if (is_scalar($state) && $state !== '') $product .= ' (' . $state . ')';
        }
    }
}

echo "Product: $product\n";
if ($package !== '') echo "Package: $package\n";
echo "\n";

if (class_exists('expInfo')) {
    $info = expInfo::availableExtensions();
} else {
    // Older releases have no extension-info class: list the extension
    // directories and mark the ones site.ini activates.
    $info = array();
    $activeList = array();
    foreach (array('settings/site.ini', 'settings/override/site.ini.append.php', 'settings/override/site.ini.append') as $ini) {
        if (is_file($ini) && preg_match_all('/^\\s*ActiveExtensions\\[\\]\\s*=\\s*(\\S+)/m', (string) file_get_contents($ini), $m)) {
            $activeList = array_merge($activeList, $m[1]);
        }
    }
    foreach (is_dir('extension') ? scandir('extension') : array() as $e) {
        if ($e[0] === '.' || !is_dir("extension/$e")) continue;
        $info[$e] = array('extension_name' => $e, 'name' => $e, 'active' => in_array($e, $activeList, true));
    }
}
$active = 0;
foreach ($info as $ext) {
    if (!empty($ext['active'])) $active++;
}
$total = count($info);
echo "Extensions: $total total, $active active, " . ($total - $active) . " inactive\n\n";

uasort($info, function ($a, $b) {
    $aa = empty($a['active']) ? 0 : 1;
    $ba = empty($b['active']) ? 0 : 1;
    if ($aa !== $ba) return $ba - $aa;
    $an = $a['extension_name'] ?? '';
    $bn = $b['extension_name'] ?? '';
    return strcasecmp($an, $bn);
});

$maxName = 0;
$maxVersion = 0;
foreach ($info as $name => $ext) {
    $n = strlen($ext['extension_name'] ?? $name);
    if ($n > $maxName) $maxName = $n;
    $v = strlen($ext['version'] ?? '');
    if ($v > $maxVersion) $maxVersion = $v;
}

// One line per field: descriptions often carry indentation and newlines.
$clean = function ($v) { return is_scalar($v) ? trim(preg_replace('/\s+/', ' ', (string) $v)) : ''; };

foreach ($info as $name => $ext) {
    $name = $ext['extension_name'] ?? $name;
    $version = $clean($ext['version'] ?? '');
    $title = $clean($ext['name'] ?? $name);
    $desc = $clean($ext['description'] ?? '') ?: $clean($ext['summary'] ?? '');
    $copyright = $clean($ext['copyright'] ?? '');
    $author = $clean($ext['author'] ?? '');
    $license = $clean($ext['license'] ?? '');
    $url = $clean($ext['info_url'] ?? '');

    $status = !empty($ext['active']) ? 'active' : 'inactive';
    $versionS = $version !== '' ? 'v' . $version : '';
    $head = sprintf('[%s] %-' . $maxName . 's %-' . ($maxVersion + 1) . 's %s', $status, $name, $versionS, $title);
    echo $head . "\n";

    $details = array();
    if ($desc !== '') $details[] = $desc;
    if ($copyright !== '') $details[] = 'copyright: ' . $copyright;
    if ($author !== '') $details[] = 'author: ' . $author;
    if ($license !== '') $details[] = 'license: ' . $license;
    if ($url !== '') $details[] = 'url: ' . $url;
    if ($details) {
        echo '    ' . implode(' | ', $details) . "\n";
    }
}
