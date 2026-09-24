<?php
/**
 * build/extensions.json is valid against build/extensions.schema.json, and
 * says what the rest of the engine relies on it saying.
 *
 * The schema is checked with a small validator for the keywords it uses
 * (type, required, properties, additionalProperties, items, enum, const,
 * pattern, minItems, minLength, $ref), so the test needs no library.
 * Then the rules no schema can state: every variant's tiers exist and
 * stack; every extension's unavailable platforms are real platforms; the
 * default variant and PHP version exist; add-on and documented drivers have
 * an install recipe; nothing names the application platform.
 *
 *   php tests/unit-extensions-manifest.php
 */
$pass = 0;
$fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what,
		var_export($got, true), var_export($want, true));
}

$dir = __DIR__ . '/../build';
$raw = file_get_contents("$dir/extensions.json");
$m = json_decode($raw, true);
$schema = json_decode(file_get_contents("$dir/extensions.schema.json"), true);
check('the manifest is valid JSON', is_array($m), true);
check('the schema is valid JSON', is_array($schema), true);

/** Validate $v against $s; append "path: problem" to $errors. */
function validate($v, array $s, array $root, $path, array &$errors)
{
	if (isset($s['$ref'])) {
		$ref = $root;
		foreach (explode('/', substr($s['$ref'], 2)) as $k) $ref = $ref[$k];
		return validate($v, $ref, $root, $path, $errors);
	}
	if (array_key_exists('const', $s) && $v !== $s['const']) $errors[] = "$path: not " . json_encode($s['const']);
	if (isset($s['enum']) && !in_array($v, $s['enum'], true)) $errors[] = "$path: " . json_encode($v) . ' not one of ' . json_encode($s['enum']);
	$type = $s['type'] ?? null;
	$isObj = is_array($v) && ($v === array() || array_keys($v) !== range(0, count($v) - 1));
	if ($type === 'object' && !$isObj) { $errors[] = "$path: not an object"; return; }
	if ($type === 'array' && (!is_array($v) || ($v !== array() && array_keys($v) !== range(0, count($v) - 1)))) { $errors[] = "$path: not an array"; return; }
	if ($type === 'string' && !is_string($v)) { $errors[] = "$path: not a string"; return; }
	if ($type === 'boolean' && !is_bool($v)) { $errors[] = "$path: not a boolean"; return; }
	if (is_string($v)) {
		if (isset($s['pattern']) && !preg_match('/' . str_replace('/', '\/', $s['pattern']) . '/', $v)) $errors[] = "$path: '$v' does not match {$s['pattern']}";
		if (isset($s['minLength']) && strlen($v) < $s['minLength']) $errors[] = "$path: shorter than {$s['minLength']}";
	}
	if ($type === 'array') {
		if (isset($s['minItems']) && count($v) < $s['minItems']) $errors[] = "$path: fewer than {$s['minItems']} items";
		if (isset($s['items'])) foreach ($v as $i => $item) validate($item, $s['items'], $root, "$path/$i", $errors);
	}
	if ($type === 'object') {
		foreach ($s['required'] ?? array() as $k) if (!array_key_exists($k, $v)) $errors[] = "$path: missing '$k'";
		foreach ($v as $k => $item) {
			if (isset($s['properties'][$k])) validate($item, $s['properties'][$k], $root, "$path/$k", $errors);
			elseif (isset($s['additionalProperties']) && is_array($s['additionalProperties'])) validate($item, $s['additionalProperties'], $root, "$path/$k", $errors);
			elseif (($s['additionalProperties'] ?? true) === false) $errors[] = "$path: unexpected '$k'";
		}
	}
}
$errors = array();
validate($m, $schema, $schema, '', $errors);
check('the manifest is valid against its schema', array_slice($errors, 0, 5), array());

// The validator itself catches what it should.
$bad = $m; $bad['extensions']['curl']['tier'] = 'mandatory'; unset($bad['variants']['mini']);
$errors = array();
validate($bad, $schema, $schema, '', $errors);
check('...and the validator rejects a bad tier and a missing variant', count($errors) >= 2, true);

// Rules beyond the schema.
$tiers = array_keys($m['tiers']);
foreach ($m['variants'] as $name => $v) {
	check("variant $name names only known tiers", array_diff($v['tiers'], $tiers), array());
	check("variant $name starts with the server tier", $v['tiers'][0], 'server');
}
$sizes = array();
foreach (array('mini', 'lite', 'standard', 'full') as $v) $sizes[] = count($m['variants'][$v]['tiers']);
check('mini < lite < standard < full stack tiers', $sizes, array(1, 2, 3, 4));
check('the default variant exists', isset($m['variants'][$m['defaultVariant']]), true);
check('the default PHP version is one that is built', in_array($m['php']['default'], $m['php']['versions'], true), true);
check('PHP 8.2 to 8.5 are built', $m['php']['versions'], array('8.2', '8.3', '8.4', '8.5'));
$badPlatforms = array();
foreach ($m['extensions'] as $n => $e) {
	foreach (array_keys($e['unavailable'] ?? array()) as $p) if (!isset($m['platforms'][$p])) $badPlatforms[] = "$n:$p";
}
check('every unavailable platform is a known platform', $badPlatforms, array());
foreach (array('mysqli', 'pdo_mysql', 'pgsql', 'pdo_pgsql', 'sqlite3', 'pdo_sqlite', 'mongodb', 'odbc', 'pdo_odbc') as $db) {
	check("database driver $db is in the standard set", in_array($m['extensions'][$db]['tier'] ?? '', array('server', 'required', 'recommended'), true), true);
}
foreach (array('opcache', 'apcu', 'redis', 'memcached', 'igbinary', 'gd', 'exif') as $x) {
	check("$x is in the standard set", in_array($m['extensions'][$x]['tier'] ?? '', array('server', 'required', 'recommended'), true), true);
}
check('gd is built with JPEG, PNG, WebP and FreeType', $m['withLibs']['gd'], array('libjpeg', 'libpng', 'libwebp', 'freetype'));
foreach (array('oci8', 'pdo_oci', 'pdo_firebird') as $a) check("$a is a loadable add-on with a recipe", !empty($m['addons'][$a]['install']), true);
foreach (array('sqlsrv', 'pdo_sqlsrv', 'msaccess') as $d) check("$d is documented with a recipe", !empty($m['documented'][$d]['install']), true);
check('the server tier is exactly what the server needs', array_keys(array_filter($m['extensions'], function ($e) { return $e['tier'] === 'server'; })),
	array('ctype', 'filter', 'mbstring', 'openssl', 'pcntl', 'phar', 'posix', 'session', 'sockets', 'sqlite3', 'tokenizer'));
check('no extension says it is unavailable without a reason', array_keys(array_filter($m['extensions'], function ($e) {
	foreach ($e['unavailable'] ?? array() as $why) if (trim($why) === '') return true;
	return false;
})), array());
check('the manifest describes the standard as its own, naming no application', preg_match('/exponential|ez ?publish|ezp5/i', $raw), 0);

if ($fail) { printf("  FAIL - %d of %d case(s)\n", $fail, $pass + $fail); exit(1); }
printf("  PASS - %d case(s)\n", $pass);
