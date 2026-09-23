<?php

/**
 * A property has to be declared before it is assigned.
 *
 * PHP 8.2 deprecates creating a property by assigning to one that was never
 * declared. Where deprecations are merely displayed this is untidy; where an
 * installation has chosen to treat them as errors it is fatal, and it is fatal
 * at the moment the object is built rather than anywhere near the declaration
 * that is missing.
 *
 * This has already cost twice. Q_Evented_StreamSelect assigned $lastError in
 * the catch that exists to stop one connection killing the server, so the very
 * exception it was swallowing became a fatal instead. And Q_WebServer_Pool
 * assigned $octane and $maxRequests in its constructor, which stopped the
 * server on FreeBSD after the startup banner had already been printed -- the
 * worst place to look, because everything up to that point had gone right.
 *
 * Reading the source is the only way to catch this without running every path
 * on a PHP configured to be strict, so that is what this does.
 *
 *   php tests/unit-dynamic-properties.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);

$pass = 0;
$fail = 0;

$root = dirname(__DIR__) . '/src';
$files = array();
$dir = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($dir as $f) {
	if (substr($f->getFilename(), -4) === '.php') $files[] = $f->getPathname();
}
sort($files);

if (count($files) < 10) {
	fwrite(STDERR, "  FAIL - found only " . count($files) . " source files\n");
	exit(1);
}

$offenders = array();

foreach ($files as $file) {
	$src = file_get_contents($file);
	$tokens = token_get_all($src);

	// Declared properties, per class. A file may hold more than one class, and
	// a name declared in one says nothing about another, so they are tracked
	// separately rather than pooled.
	$declared = array();
	$methods = array();
	$class = null;
	$depth = 0;
	$inClassAt = array();

	$count = count($tokens);
	for ($i = 0; $i < $count; ++$i) {
		$t = $tokens[$i];

		if (is_array($t) && $t[0] === T_CLASS) {
			// Skip ::class and anonymous classes.
			for ($j = $i + 1; $j < $count; ++$j) {
				if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) continue;
				if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
					$class = $tokens[$j][1];
					$declared[$class] = array();
					$methods[$class] = array();
					$inClassAt[$class] = $depth;
				}
				break;
			}
			continue;
		}

		if ($t === '{') { ++$depth; continue; }
		if ($t === '}') { --$depth; continue; }

		if ($class === null) continue;

		// A property declaration: a visibility keyword, then optionally
		// static/readonly or a type, then the variable.
		if (is_array($t) && in_array($t[0],
				array(T_PUBLIC, T_PROTECTED, T_PRIVATE, T_VAR), true)) {
			for ($j = $i + 1; $j < $count && $j < $i + 8; ++$j) {
				$n = $tokens[$j];
				if (is_array($n) && in_array($n[0], array(
						T_WHITESPACE, T_STATIC, T_STRING, T_ARRAY, T_NS_SEPARATOR), true)) {
					continue;
				}
				if (is_array($n) && $n[0] === T_FUNCTION) break;   // a method
				if ($n === '?') continue;                          // nullable type
				if (is_array($n) && $n[0] === T_VARIABLE) {
					$declared[$class][ltrim($n[1], '$')] = true;
				}
				break;
			}
			continue;
		}

		if (is_array($t) && $t[0] === T_FUNCTION) {
			for ($j = $i + 1; $j < $count; ++$j) {
				if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) continue;
				if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
					$methods[$class][$tokens[$j][1]] = true;
				}
				break;
			}
		}
	}

	// Now every assignment to $this->name, and whether the class declared it.
	$class = null;
	for ($i = 0; $i < $count; ++$i) {
		$t = $tokens[$i];
		if (is_array($t) && $t[0] === T_CLASS) {
			for ($j = $i + 1; $j < $count; ++$j) {
				if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) continue;
				if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) $class = $tokens[$j][1];
				break;
			}
			continue;
		}
		if ($class === null) continue;

		if (!is_array($t) || $t[0] !== T_VARIABLE || $t[1] !== '$this') continue;
		if (!isset($tokens[$i + 1]) || !is_array($tokens[$i + 1])
			|| $tokens[$i + 1][0] !== T_OBJECT_OPERATOR) continue;
		if (!isset($tokens[$i + 2]) || !is_array($tokens[$i + 2])
			|| $tokens[$i + 2][0] !== T_STRING) continue;

		$name = $tokens[$i + 2][1];

		// A call is not an assignment.
		$after = $i + 3;
		while (isset($tokens[$after]) && is_array($tokens[$after])
			&& $tokens[$after][0] === T_WHITESPACE) ++$after;
		if (isset($tokens[$after]) && $tokens[$after] === '(') continue;

		// Only an assignment creates the property. Reading an undeclared one
		// is a different (and louder) problem.
		$isAssign = false;
		if (isset($tokens[$after])) {
			$a = $tokens[$after];
			if ($a === '=') $isAssign = true;
			if ($a === '[') {
				// $this->thing['k'] = ... also creates $this->thing.
				$depth2 = 0;
				for ($k = $after; $k < $count; ++$k) {
					if ($tokens[$k] === '[') ++$depth2;
					else if ($tokens[$k] === ']') {
						--$depth2;
						if ($depth2 === 0) {
							$n2 = $k + 1;
							while (isset($tokens[$n2]) && is_array($tokens[$n2])
								&& $tokens[$n2][0] === T_WHITESPACE) ++$n2;
							if (isset($tokens[$n2]) && $tokens[$n2] === '=') $isAssign = true;
							break;
						}
					}
				}
			}
			if (is_array($a) && in_array($a[0], array(
					T_PLUS_EQUAL, T_MINUS_EQUAL, T_CONCAT_EQUAL, T_MUL_EQUAL,
					T_DIV_EQUAL, T_COALESCE_EQUAL), true)) {
				$isAssign = true;
			}
		}
		if (!$isAssign) continue;

		if (isset($methods[$class][$name])) continue;
		if (isset($declared[$class][$name])) continue;

		$offenders[] = sprintf('%s::$%s  (%s line %d)',
			$class, $name, basename($file), $tokens[$i][2]);
	}
}

$offenders = array_values(array_unique($offenders));

if ($offenders) {
	printf("  FAIL  %d property assignment(s) with no declaration:\n",
		count($offenders));
	foreach ($offenders as $o) printf("        %s\n", $o);
	printf("\n        PHP 8.2 deprecates these, and an installation that treats\n");
	printf("        a deprecation as an error dies when the object is built.\n");
	printf("        Declare each one on its class.\n");
	++$fail;
} else {
	++$pass;
}

// The scan is only worth anything if it can actually see the tree.
if (count($files) > 30) {
	++$pass;
} else {
	printf("  FAIL  only %d files scanned; expected the whole of src/\n", count($files));
	++$fail;
}

if ($fail) {
	printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail);
	exit(1);
}
printf("  PASS - %d file(s) scanned, no undeclared properties\n", count($files));
