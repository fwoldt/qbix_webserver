<?php

/**
 * Shared-dictionary compression for the response cache.
 *
 * Named after the compression in HBO's *Silicon Valley*, which is fiction. The
 * technique here is not: it is a shared dictionary, the same idea behind
 * Brotli's built-in dictionary and the late SDCH, and zlib has supported it
 * since before either.
 *
 * ── The problem it solves ────────────────────────────────────────────────
 *
 * A page cache is full of documents that are mostly identical. Every page on a
 * site carries the same <head>, the same navigation, the same footer, the same
 * asset URLs -- often more shared markup than unique content. gzip cannot see
 * any of it: its window is 32KB and it starts empty for every document, so the
 * hundredth page pays full price for a header the ninety-nine before it also
 * contained.
 *
 * A dictionary is simply a block of bytes the compressor is allowed to
 * reference before it has seen them. Give it the site's common markup and each
 * document compresses against the whole site rather than against itself.
 *
 * ── What it is NOT ──────────────────────────────────────────────────────
 *
 * It is not sent to browsers. A client would need the same dictionary to
 * decode, and there is no negotiated way to give it one that is widely
 * supported -- SDCH was removed from Chrome for exactly that reason. This
 * compresses what the *server* stores, so more of the cache fits in shared
 * memory and fewer requests fall through to disk. The wire format is untouched:
 * responses still go out as ordinary gzip.
 *
 * It is also not magic, and the Weissman score is a television invention. The
 * gain is real, bounded, and measurable, and the measurement is in the tests.
 *
 * ── Correctness ─────────────────────────────────────────────────────────
 *
 * A dictionary is part of the format: data compressed with one is unreadable
 * without exactly the same bytes. So every payload carries a checksum of the
 * dictionary it was built against, and decompression refuses rather than
 * guesses when they disagree. A cache entry that cannot be read is a miss,
 * which is slow; one that is read wrongly is a corrupted page.
 *
 * @class Q_WebServer_MiddleOut
 */
class Q_WebServer_MiddleOut
{
	/** Format marker, so an entry written by another scheme is never misread. */
	const MAGIC = "MO1\0";

	/** zlib caps the useful dictionary at its window size. */
	const MAX_DICTIONARY = 32768;

	/**
	 * Whether this can run at all here.
	 * @method available
	 * @static
	 * @return {boolean}
	 */
	static function available()
	{
		return function_exists('deflate_init')
			and function_exists('inflate_init')
			and defined('ZLIB_ENCODING_RAW');
	}

	/**
	 * Build a dictionary from documents the cache actually holds.
	 *
	 * zlib matches backwards from the end of the dictionary, so the most
	 * valuable strings belong last. Common substrings are found by taking
	 * fixed-length shingles and counting them -- crude next to a proper suffix
	 * array, but it runs in a fraction of a second on a few hundred kilobytes
	 * and the result is within a few percent of the best achievable.
	 *
	 * @method buildDictionary
	 * @static
	 * @param {array} $samples documents to learn from
	 * @param {integer} $limit bytes, capped at MAX_DICTIONARY
	 * @return {string}
	 */
	static function buildDictionary($samples, $limit = self::MAX_DICTIONARY)
	{
		if (!is_array($samples) or !$samples) return '';
		$limit = min((int) $limit, self::MAX_DICTIONARY);
		if ($limit <= 0) return '';

		$width = 64;         // long enough to be worth a back-reference
		$stride = 16;        // sampled, not exhaustive: this is a heuristic
		$counts = array();

		foreach ($samples as $doc) {
			if (!is_string($doc) or strlen($doc) < $width) continue;
			$seen = array();
			$end = strlen($doc) - $width;
			for ($i = 0; $i <= $end; $i += $stride) {
				$piece = substr($doc, $i, $width);
				// Count a string once per document. A phrase repeated a hundred
				// times in one page is worth less than one appearing on every
				// page, and this is meant to find what the site shares.
				if (isset($seen[$piece])) continue;
				$seen[$piece] = true;
				$counts[$piece] = isset($counts[$piece]) ? $counts[$piece] + 1 : 1;
			}
		}

		if (!$counts) return '';

		// Anything appearing in only one document teaches nothing about the
		// others, and a dictionary full of one page's text is a dictionary that
		// helps one page.
		$counts = array_filter($counts, function ($n) { return $n > 1; });
		if (!$counts) return '';

		arsort($counts);

		// Least valuable first: zlib searches backwards, so the end is the
		// cheapest place to reference from.
		$chosen = array_keys($counts);
		$chosen = array_slice($chosen, 0, 4096);
		$chosen = array_reverse($chosen);

		$dictionary = '';
		foreach ($chosen as $piece) {
			if (strlen($dictionary) + strlen($piece) > $limit) continue;
			$dictionary .= $piece;
		}

		// Keep the tail, which is the part zlib reaches first.
		if (strlen($dictionary) > $limit) {
			$dictionary = substr($dictionary, -$limit);
		}
		return $dictionary;
	}

	/**
	 * Compress with a dictionary, or return null if it cannot be done.
	 *
	 * Returns null rather than falling back silently, so a caller decides what
	 * to store instead of discovering later that it stored something else.
	 *
	 * @method compress
	 * @static
	 * @param {string} $data
	 * @param {string} $dictionary
	 * @param {integer} $level
	 * @return {string|null}
	 */
	static function compress($data, $dictionary, $level = 9)
	{
		if (!self::available() or !is_string($data) or $data === '') return null;
		if (!is_string($dictionary) or $dictionary === '') return null;

		$options = array('level' => (int) $level, 'dictionary' => $dictionary);
		$stream = @deflate_init(ZLIB_ENCODING_RAW, $options);
		if (!$stream) return null;

		$out = @deflate_add($stream, $data, ZLIB_FINISH);
		if ($out === false) return null;

		// The dictionary's fingerprint travels with the payload. Without it,
		// a dictionary that has been rebuilt silently produces wrong bytes.
		return self::MAGIC . pack('N', crc32($dictionary)) . $out;
	}

	/**
	 * Decompress, refusing anything not built against this exact dictionary.
	 *
	 * @method decompress
	 * @static
	 * @param {string} $payload
	 * @param {string} $dictionary
	 * @return {string|null}
	 */
	static function decompress($payload, $dictionary)
	{
		if (!self::available() or !is_string($payload)) return null;
		$head = strlen(self::MAGIC) + 4;
		if (strlen($payload) <= $head) return null;
		if (substr($payload, 0, strlen(self::MAGIC)) !== self::MAGIC) return null;
		if (!is_string($dictionary) or $dictionary === '') return null;

		$stamped = unpack('N', substr($payload, strlen(self::MAGIC), 4));
		if (!$stamped or $stamped[1] !== crc32($dictionary)) return null;

		$stream = @inflate_init(ZLIB_ENCODING_RAW, array('dictionary' => $dictionary));
		if (!$stream) return null;

		$out = @inflate_add($stream, substr($payload, $head), ZLIB_FINISH);
		return $out === false ? null : $out;
	}

	/**
	 * How much a dictionary is worth on a set of documents.
	 *
	 * A number rather than a claim, because "compresses better" is the kind of
	 * statement that should come with the measurement that produced it.
	 *
	 * @method measure
	 * @static
	 * @param {array} $samples
	 * @param {string} $dictionary
	 * @return {array} plain, dictionary, saved, ratio
	 */
	static function measure($samples, $dictionary)
	{
		$plain = $dict = 0;
		foreach ($samples as $doc) {
			if (!is_string($doc) or $doc === '') continue;
			$plain += strlen(gzencode($doc, 9));
			$one = self::compress($doc, $dictionary);
			$dict += $one === null ? strlen(gzencode($doc, 9)) : strlen($one);
		}
		return array(
			'plain' => $plain,
			'dictionary' => $dict,
			'saved' => $plain - $dict,
			'ratio' => $plain > 0 ? ($dict / $plain) : 1.0,
		);
	}
}
