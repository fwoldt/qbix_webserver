<?php

/**
 * Shared-dictionary compression: does it work, and is it worth anything?
 *
 * The name is a homage to HBO's *Silicon Valley*. The technique is real and
 * old: give the compressor a block of bytes it may reference before it has
 * seen them, and every document compresses against the whole corpus instead of
 * only against itself. A page cache is the ideal case, because every page in
 * it carries the same head, nav and footer and gzip's 32KB window starts empty
 * for each one.
 *
 * Two things have to hold, and the second matters more than the first:
 *
 *   - it must compress better than plain gzip on documents that share markup
 *   - it must never return the wrong bytes
 *
 * A dictionary is part of the format. Data compressed with one is unreadable
 * without exactly the same bytes, and a rebuilt dictionary is a different
 * dictionary. An entry that cannot be read is a cache miss, which is merely
 * slow; an entry read against the wrong dictionary is a corrupted page.
 *
 *   php tests/unit-middle-out.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q/WebServer/MiddleOut.php';

$M = 'Q_WebServer_MiddleOut';
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

if (!$M::available()) {
	echo "\n  SKIP - zlib dictionary support is not present here\n\n";
	exit(0);
}

// A corpus shaped like a real site: a large shared shell, small unique bodies.
$shell_head = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
	. '<link rel="stylesheet" href="/var/site/cache/public/stylesheets/d5a065d0229acc15697dd20de7051304_all.css">'
	. '<script src="/var/site/cache/public/javascript/2a672133cddce35ccc870bc799677d02.js"></script>'
	. '<meta name="viewport" content="width=device-width, initial-scale=1"></head><body>'
	. '<nav class="site-navigation"><ul><li><a href="/fitness">Fitness</a></li>'
	. '<li><a href="/healthy-eating">Healthy eating</a></li><li><a href="/recipes">Recipes</a></li>'
	. '<li><a href="/running">Running</a></li><li><a href="/video">Video</a></li></ul></nav>';
$shell_foot = '<footer class="site-footer"><p>&copy; 2026</p>'
	. '<ul><li><a href="/about">About</a></li><li><a href="/contact">Contact</a></li></ul>'
	. '</footer></body></html>';

$corpus = array();
for ($i = 0; $i < 30; ++$i) {
	$corpus[] = $shell_head
		. '<main><h1>Article number ' . $i . '</h1><p>Body text for article '
		. $i . ', which differs from the others.</p></main>'
		. $shell_foot;
}

// ── It round-trips ───────────────────────────────────────────────────────
$dict = $M::buildDictionary($corpus);
check('a dictionary is built from the corpus', strlen($dict) > 0, true);
check('and is bounded by the window size', strlen($dict) <= 32768, true);

$doc = $corpus[0];
$packed = $M::compress($doc, $dict);
check('a document compresses', is_string($packed), true);
check('and comes back exactly', $M::decompress($packed, $dict), $doc);

// Every document, not just the first.
$allBack = true;
foreach ($corpus as $d) {
	if ($M::decompress($M::compress($d, $dict), $dict) !== $d) { $allBack = false; break; }
}
check('every document in the corpus round-trips', $allBack, true);

// Binary-safe: a cache holds gzipped bodies, which are not text.
$binary = random_bytes(4096);
check('binary data round-trips', $M::decompress($M::compress($binary, $dict), $dict), $binary);

// ── It refuses rather than guessing ──────────────────────────────────────
$other = $M::buildDictionary(array(str_repeat('completely different content. ', 200),
                                   str_repeat('completely different content. ', 180)));
check('the wrong dictionary is refused, not guessed at',
	$M::decompress($packed, $other), null);
check('an empty dictionary is refused', $M::decompress($packed, ''), null);
check('rubbish is refused', $M::decompress('not a payload', $dict), null);
check('a truncated payload is refused', $M::decompress(substr($packed, 0, 6), $dict), null);
check('plain gzip is not mistaken for this format',
	$M::decompress(gzencode('hello'), $dict), null);

check('compressing with no dictionary returns null', $M::compress($doc, ''), null);
check('compressing nothing returns null', $M::compress('', $dict), null);

// A dictionary built from nothing helps nothing, and says so.
check('an empty corpus yields no dictionary', $M::buildDictionary(array()), '');
check('a corpus of one yields no dictionary', $M::buildDictionary(array($doc)), '');

// ── Is it worth anything? ────────────────────────────────────────────────
$m = $M::measure($corpus, $dict);
check('it beats plain gzip on a corpus that shares markup', $m['ratio'] < 1.0, true);
printf("\n  %d document(s) sharing a common shell:\n", count($corpus));
printf("    plain gzip       %7d bytes\n", $m['plain']);
printf("    with dictionary  %7d bytes\n", $m['dictionary']);
printf("    saved            %7d bytes  (%.1f%% smaller)\n",
	$m['saved'], 100.0 * (1 - $m['ratio']));

// And honestly: on documents that share nothing, it should not claim a win.
$unrelated = array();
for ($i = 0; $i < 10; ++$i) $unrelated[] = base64_encode(random_bytes(3000));
$d2 = $M::buildDictionary($unrelated);
$m2 = $M::measure($unrelated, $d2 !== '' ? $d2 : $dict);
printf("    on unrelated documents it is %.2fx plain gzip (no shared markup to find)\n",
	$m2['ratio']);
check('and it does not corrupt them either',
	$M::decompress($M::compress($unrelated[0], $dict), $dict), $unrelated[0]);

// ── Through the cache's own storage format ───────────────────────────────
// The compressor is correct on its own; what matters is that an entry written
// through encodeEntry() comes back through decodeEntry() unchanged, and that a
// dictionary mismatch reads as a MISS rather than as wrong bytes.
require_once __DIR__ . '/../src/Q/WebServer/Cache.php';

$C = 'Q_WebServer_Cache';
$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qcache-mo-' . getmypid();
@mkdir($root, 0755, true);
register_shutdown_function(function () use ($root) {
	foreach (glob($root . '/*') ?: array() as $f) { @unlink($f); }
	@rmdir($root);
});

$C::$dir = $root;
$C::$middleOut = true;
$C::$dictionary = null;
file_put_contents($root . DIRECTORY_SEPARATOR . 'middle-out.dict', $dict);

$entry = array(
	'status' => 200,
	'headers' => array('Content-Type' => 'text/html'),
	'body' => $corpus[3],
	'expires' => time() + 300,
	'stored' => time(),
);

$wire = $C::encodeEntry($entry);
$back = $C::decodeEntry($wire);
check('an entry survives the storage format', $back['body'], $corpus[3]);
check('and keeps its status', $back['status'], 200);
check('the body on disk is smaller than the body in hand',
	strlen($wire) < strlen($entry['body']), true);

$meta = json_decode(substr($wire, 0, strpos($wire, "\n")), true);
check('the entry records that it was compressed', $meta['mo'], 1);
check('and the flag does not leak back to the caller',
	isset($back['mo']), false);

// A dictionary that has been rebuilt is a different dictionary.
$C::$dictionary = null;
file_put_contents($root . DIRECTORY_SEPARATOR . 'middle-out.dict', $other);
check('an entry written against another dictionary reads as a miss',
	$C::decodeEntry($wire), null);

// No dictionary at all: still a miss, never a guess.
$C::$dictionary = null;
@unlink($root . DIRECTORY_SEPARATOR . 'middle-out.dict');
check('with no dictionary it is a miss, not a crash',
	$C::decodeEntry($wire), null);

// And with the feature off, entries are written the ordinary way and anything
// already stored the ordinary way still reads.
$C::$middleOut = false;
$C::$dictionary = null;
$plainWire = $C::encodeEntry($entry);
$plainMeta = json_decode(substr($plainWire, 0, strpos($plainWire, "\n")), true);
check('with the feature off nothing is marked compressed',
	isset($plainMeta['mo']), false);
check('and it round-trips as before',
	$C::decodeEntry($plainWire)['body'], $corpus[3]);

// ── Clearing the cache must not destroy the dictionary ───────────────────
// It lives in the cache directory but is not an entry. Deleting it would leave
// every later write uncompressed until somebody noticed.
$C::$dir = $root;
$C::$apcuEnabled = false;
$C::$middleOut = true;
$C::$dictionary = null;
@mkdir($root . DIRECTORY_SEPARATOR . 'ab', 0755, true);
file_put_contents($root . DIRECTORY_SEPARATOR . 'middle-out.dict', $dict);
file_put_contents($root . DIRECTORY_SEPARATOR . 'ab' . DIRECTORY_SEPARATOR . 'x.json', 'entry');

$C::clear();
check('clear() removes the entries',
	file_exists($root . DIRECTORY_SEPARATOR . 'ab' . DIRECTORY_SEPARATOR . 'x.json'), false);
check('but keeps the dictionary',
	file_exists($root . DIRECTORY_SEPARATOR . 'middle-out.dict'), true);
check('and it is still the same dictionary',
	file_get_contents($root . DIRECTORY_SEPARATOR . 'middle-out.dict'), $dict);

echo "\n";
if ($fail === 0) { printf("  PASS - %d case(s)\n\n", $pass); exit(0); }
printf("  FAIL - %d of %d\n\n", $fail, $pass + $fail);
exit(1);
