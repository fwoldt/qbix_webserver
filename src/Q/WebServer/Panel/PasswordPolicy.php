<?php
/**
 * @module Q
 */

/**
 * The rules a control panel password has to meet, in one place. Every path
 * that sets one -- the page's change form, the first-time setup, the forced
 * change of the default key, and `qbixctl panel:password` -- asks check(),
 * so the page and the command line give the same verdict for the same
 * password. The rules are listed, each with an example, in docs/passwords.md.
 *
 * The minimums can be raised in configuration (Q.panel.passwordMinLength,
 * passwordMinDistinct, passwordMinBits) but not lowered: a value below its
 * floor is ignored, with a warning in the log.
 *
 * @class Q_WebServer_Panel_PasswordPolicy
 * @static
 */
class Q_WebServer_Panel_PasswordPolicy
{
	/** bcrypt reads no further than this; a longer password would be silently cut. */
	const MAX_BYTES = 72;

	/** The floors. Configuration can only raise them. */
	const MIN_LENGTH = 16;
	const MIN_DISTINCT = 10;
	const MIN_BITS = 80;

	/** Words no password may contain, whatever the case. */
	private static $forbidden = array('qbix', 'password', 'admin');

	/** Runs of four or more along these, either way, are refused. */
	private static $sequences = array(
		'abcdefghijklmnopqrstuvwxyz', '0123456789',
		'qwertyuiop', 'asdfghjkl', 'zxcvbnm', '1234567890',
	);

	/** @var array|null lower-cased common passwords, as keys */
	private static $common = null;

	/** @var array warnings already logged, so each is logged once */
	private static $warned = array();

	/**
	 * The failed rules for a password, as sentences; empty when it passes.
	 *
	 * @method check
	 * @static
	 * @param {string} $password
	 * @param {array} $context optional: 'default' (the default key), 'brand',
	 *   'host', 'currentHash' (the stored hash, which it must differ from)
	 * @return {array}
	 */
	static function check($password, array $context = array())
	{
		$pw = (string) $password;
		$failed = array();
		$bytes = strlen($pw);
		$chars = self::chars($pw);
		$n = count($chars);
		$lower = function_exists('mb_strtolower') ? mb_strtolower($pw, 'UTF-8') : strtolower($pw);
		$min = self::minimum('passwordMinLength', self::MIN_LENGTH);

		if ($bytes > self::MAX_BYTES) {
			$failed[] = sprintf('It is %d bytes long; bcrypt reads only the first %d, so at most %d bytes are allowed.',
				$bytes, self::MAX_BYTES, self::MAX_BYTES);
		}
		if ($n < $min) {
			$failed[] = sprintf('At least %d characters (this has %d).', $min, $n);
		}
		$classes = self::classes($chars);
		foreach (array('upper' => 'uppercase letter', 'lower' => 'lowercase letter',
			'digit' => 'digit', 'symbol' => 'symbol') as $class => $label) {
			if (empty($classes[$class])) $failed[] = "At least one $label.";
		}
		$distinct = count(array_unique($chars));
		$minDistinct = self::minimum('passwordMinDistinct', self::MIN_DISTINCT);
		if ($distinct < $minDistinct) {
			$failed[] = sprintf('At least %d different characters (this has %d).', $minDistinct, $distinct);
		}
		if (preg_match('/(.)\1\1/u', $pw)) {
			$failed[] = 'No character three or more times in a row (like "aaa").';
		}
		if (($run = self::sequenceIn($lower)) !== null) {
			$failed[] = sprintf('No run of four or more in order, forwards or backwards (found "%s").', $run);
		}
		$words = self::$forbidden;
		foreach (array('default', 'brand') as $k) {
			if (!empty($context[$k]) and is_string($context[$k])) $words[] = $context[$k];
		}
		if (!empty($context['host']) and is_string($context['host'])) {
			$host = strtolower(preg_replace('/:\d+$/', '', trim($context['host'], '[] ')));
			$words[] = $host;
			$first = explode('.', $host)[0];
			if (strlen($first) >= 4) $words[] = $first;
		}
		foreach (array_unique($words) as $w) {
			$w = function_exists('mb_strtolower') ? mb_strtolower(trim($w), 'UTF-8') : strtolower(trim($w));
			if (strlen($w) >= 3 and strpos($lower, $w) !== false) {
				$failed[] = sprintf('It must not contain "%s".', $w);
			}
		}
		if (self::isCommon($lower)) {
			$failed[] = 'It is, or is built on, one of the most common passwords.';
		}
		$bits = self::bits($chars);
		$minBits = self::minimum('passwordMinBits', self::MIN_BITS);
		if ($bits < $minBits) {
			$failed[] = sprintf('An estimated strength of at least %d bits (this is %d).', $minBits, (int) floor($bits));
		}
		if (!empty($context['currentHash']) and is_string($context['currentHash'])
			and $bytes <= self::MAX_BYTES and password_verify($pw, $context['currentHash'])) {
			$failed[] = 'It must differ from the current password.';
		}
		return $failed;
	}

	/**
	 * A random password that passes check() -- 24 characters from a
	 * cryptographically secure source.
	 * @method generate
	 * @static
	 * @param {array} $context as for check()
	 * @return {string}
	 */
	static function generate(array $context = array())
	{
		$sets = array('ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!#$%&*+-=?@^_~.,:;');
		$all = implode('', $sets);
		for ($try = 0; $try < 1000; ++$try) {
			$pw = array();
			foreach ($sets as $s) $pw[] = $s[random_int(0, strlen($s) - 1)];
			while (count($pw) < 24) $pw[] = $all[random_int(0, strlen($all) - 1)];
			for ($i = count($pw) - 1; $i > 0; --$i) {
				$j = random_int(0, $i);
				$t = $pw[$i]; $pw[$i] = $pw[$j]; $pw[$j] = $t;
			}
			$pw = implode('', $pw);
			if (!self::check($pw, $context)) return $pw;
		}
		throw new RuntimeException('could not generate a password that passes the policy');
	}

	/**
	 * The rules as the change form lists them, for its checklist.
	 * @method rules
	 * @static
	 * @return {array} minLength, minDistinct, minBits, maxBytes
	 */
	static function rules()
	{
		return array(
			'minLength' => self::minimum('passwordMinLength', self::MIN_LENGTH),
			'minDistinct' => self::minimum('passwordMinDistinct', self::MIN_DISTINCT),
			'minBits' => self::minimum('passwordMinBits', self::MIN_BITS),
			'maxBytes' => self::MAX_BYTES,
		);
	}

	/** A configured minimum, never below its floor. */
	private static function minimum($key, $floor)
	{
		$v = class_exists('Q_Config', false) ? Q_Config::get('Q', 'panel', $key, null) : null;
		if ($v === null) return $floor;
		if (!is_numeric($v) or (int) $v < $floor) {
			if (!isset(self::$warned[$key])) {
				self::$warned[$key] = true;
				fwrite(defined('STDERR') ? STDERR : fopen('php://stderr', 'w'), sprintf(
					"  panel: Q.panel.%s = %s is below its floor of %d and is ignored\n", $key, var_export($v, true), $floor));
			}
			return $floor;
		}
		return (int) $v;
	}

	/** The password's characters (UTF-8 aware). */
	private static function chars($pw)
	{
		$c = preg_split('//u', $pw, -1, PREG_SPLIT_NO_EMPTY);
		return $c === false ? str_split($pw) : $c;
	}

	/** Which classes the characters cover. A non-ASCII letter counts as its case, an uncased one as a symbol. */
	private static function classes(array $chars)
	{
		$has = array('upper' => false, 'lower' => false, 'digit' => false, 'symbol' => false, 'other' => false);
		foreach ($chars as $ch) {
			if (preg_match('/^\p{Lu}$/u', $ch)) $has['upper'] = true;
			elseif (preg_match('/^\p{Ll}$/u', $ch)) $has['lower'] = true;
			elseif (preg_match('/^\p{Nd}$/u', $ch)) $has['digit'] = true;
			else {
				$has['symbol'] = true;
				if (strlen($ch) > 1) $has['other'] = true;
			}
		}
		return $has;
	}

	/** log2(pool size) x length, the pool being the classes it draws on. */
	private static function bits(array $chars)
	{
		$c = self::classes($chars);
		$pool = ($c['lower'] ? 26 : 0) + ($c['upper'] ? 26 : 0) + ($c['digit'] ? 10 : 0)
			+ ($c['symbol'] ? 33 : 0) + ($c['other'] ? 64 : 0);
		return $pool > 1 ? log($pool, 2) * count($chars) : 0.0;
	}

	/** The first run of four in order (alphabet, digits, keyboard rows, either way), or null. */
	private static function sequenceIn($lower)
	{
		$len = strlen($lower);
		for ($i = 0; $i + 4 <= $len; ++$i) {
			$four = substr($lower, $i, 4);
			foreach (self::$sequences as $s) {
				if (strpos($s, $four) !== false or strpos(strrev($s), $four) !== false) return $four;
			}
		}
		return null;
	}

	/** Whether it is a common password, whole or with trailing digits and symbols taken off. */
	private static function isCommon($lower)
	{
		if (self::$common === null) {
			self::$common = array();
			$file = __DIR__ . '/common-passwords.txt';
			foreach (is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array()) : array() as $w) {
				$w = strtolower(trim($w));
				if ($w !== '' and $w[0] !== '#') self::$common[$w] = true;
			}
		}
		if (isset(self::$common[$lower])) return true;
		$stripped = preg_replace('/[\d\W_]+$/u', '', $lower);
		$core = preg_replace('/^[\d\W_]+/u', '', (string) $stripped);
		return ($stripped !== '' and isset(self::$common[$stripped]))
			or ($core !== '' and isset(self::$common[$core]));
	}
}
