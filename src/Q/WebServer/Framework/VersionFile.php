<?php
/**
 * @module Q
 */
/**
 * Reads the literal values a PHP version file declares, without running it.
 *
 * Applications record their release in a small PHP file -- class constants,
 * define()s, a variable, or functions that return a number. Including such a
 * file inside the server would declare its class or constants in the server
 * process, where a second installation of the same application then collides
 * with the first; so the file is tokenized and only literal values are taken.
 *
 *   $v = Q_WebServer_Framework_VersionFile::read('/srv/app/lib/version.php');
 *   $v['consts']['VERSION_MAJOR'];   // class constant, any class
 *   $v['defines']['APP_VERSION'];    // define('APP_VERSION', '1.2')
 *   $v['vars']['wp_version'];        // $wp_version = '6.4.2';
 *   $v['funcs']['majorVersion'];     // function majorVersion() { return 4; }
 *
 * A function whose body is `return self::X;` or `return Some::X;` resolves to
 * that constant's value when the file declares it. Anything that is not a
 * plain literal (arithmetic, calls, concatenation) is left out.
 *
 * @class Q_WebServer_Framework_VersionFile
 * @static
 */
class Q_WebServer_Framework_VersionFile
{
	/** Version files are small; anything bigger is not one. */
	const MAX_BYTES = 262144;

	/**
	 * @method read
	 * @static
	 * @param {string} $file
	 * @return {array|null} consts, defines, vars, funcs, classes; null when unreadable
	 */
	static function read($file)
	{
		if (!is_file($file) or !is_readable($file)) return null;
		$size = @filesize($file);
		if ($size === false or $size > self::MAX_BYTES) return null;
		$code = @file_get_contents($file);
		if (!is_string($code) or !function_exists('token_get_all')) return null;
		return self::parse($code);
	}

	/**
	 * @method parse
	 * @static
	 * @param {string} $code PHP source
	 * @return {array}
	 */
	static function parse($code)
	{
		$out = array('consts' => array(), 'defines' => array(), 'vars' => array(),
			'funcs' => array(), 'classes' => array());
		$t = array();
		foreach (@token_get_all($code) as $tok) {
			if (is_array($tok) and in_array($tok[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) continue;
			$t[] = $tok;
		}
		$n = count($t);
		$depth = 0;
		$funcRefs = array();
		for ($i = 0; $i < $n; ++$i) {
			$tok = $t[$i];
			if ($tok === '{' or (is_array($tok) and in_array($tok[0], array(T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES), true))) { ++$depth; continue; }
			if ($tok === '}') { --$depth; continue; }
			if (!is_array($tok)) continue;
			switch ($tok[0]) {
				case T_CLASS:
					if (isset($t[$i + 1]) and is_array($t[$i + 1]) and $t[$i + 1][0] === T_STRING) {
						$out['classes'][] = $t[$i + 1][1];
					}
					break;
				case T_CONST:
					// const NAME = literal [, NAME2 = literal];  (a typed constant too)
					$j = $i + 1;
					while ($j < $n and $t[$j] !== ';') {
						if (is_array($t[$j]) and $t[$j][0] === T_STRING and isset($t[$j + 1]) and $t[$j + 1] === '=') {
							$name = $t[$j][1];
							list($val, $end) = self::literal($t, $j + 2, $n);
							if ($val !== self::none()) $out['consts'][$name] = $val;
							$j = max($end, $j + 2);
							continue;
						}
						++$j;
					}
					break;
				case T_STRING:
					if (strcasecmp($tok[1], 'define') === 0 and isset($t[$i + 1]) and $t[$i + 1] === '('
						and isset($t[$i + 2]) and is_array($t[$i + 2]) and $t[$i + 2][0] === T_CONSTANT_ENCAPSED_STRING
						and isset($t[$i + 3]) and $t[$i + 3] === ',') {
						list($val) = self::literal($t, $i + 4, $n);
						if ($val !== self::none()) $out['defines'][self::unquote($t[$i + 2][1])] = $val;
					}
					break;
				case T_VARIABLE:
					if ($depth === 0 and isset($t[$i + 1]) and $t[$i + 1] === '=') {
						list($val, $end) = self::literal($t, $i + 2, $n);
						if ($val !== self::none() and isset($t[$end]) and $t[$end] === ';') {
							$out['vars'][substr($tok[1], 1)] = $val;
						}
					}
					break;
				case T_FUNCTION:
					if (!isset($t[$i + 1]) or !is_array($t[$i + 1]) or $t[$i + 1][0] !== T_STRING) break;
					$name = $t[$i + 1][1];
					// Skip to the body's opening brace, then look for `return X;` as its first statement.
					$j = $i + 2;
					$parens = 0;
					while ($j < $n) {
						if ($t[$j] === '(') ++$parens;
						elseif ($t[$j] === ')') --$parens;
						elseif ($parens === 0 and ($t[$j] === '{' or $t[$j] === ';')) break;
						++$j;
					}
					if (!isset($t[$j]) or $t[$j] !== '{') break;
					if (isset($t[$j + 1]) and is_array($t[$j + 1]) and $t[$j + 1][0] === T_RETURN) {
						list($val, $end) = self::literal($t, $j + 2, $n);
						if ($val !== self::none() and isset($t[$end]) and $t[$end] === ';') {
							$out['funcs'][$name] = $val;
						} elseif (isset($t[$j + 4]) and is_array($t[$j + 2]) and in_array($t[$j + 2][0], array(T_STRING, T_STATIC), true)
							and is_array($t[$j + 3]) and $t[$j + 3][0] === T_DOUBLE_COLON
							and is_array($t[$j + 4]) and $t[$j + 4][0] === T_STRING
							and isset($t[$j + 5]) and $t[$j + 5] === ';') {
							$funcRefs[$name] = $t[$j + 4][1];
						}
					}
					break;
			}
		}
		foreach ($funcRefs as $name => $const) {
			if (array_key_exists($const, $out['consts'])) $out['funcs'][$name] = $out['consts'][$const];
		}
		return $out;
	}

	/** A marker for "not a literal", distinct from null, false and ''. */
	private static function none()
	{
		static $none = null;
		if ($none === null) $none = new stdClass();
		return $none;
	}

	/**
	 * The literal starting at token $i: a string, number, true/false/null, or
	 * a negative number. Returns array(value|none(), index after it).
	 */
	private static function literal($t, $i, $n)
	{
		if (!isset($t[$i])) return array(self::none(), $i);
		$neg = false;
		if ($t[$i] === '-') { $neg = true; ++$i; }
		$tok = $t[$i] ?? null;
		if (!is_array($tok)) return array(self::none(), $i);
		switch ($tok[0]) {
			case T_CONSTANT_ENCAPSED_STRING:
				return $neg ? array(self::none(), $i) : array(self::unquote($tok[1]), $i + 1);
			case T_LNUMBER:
				$v = (int) $tok[1];
				return array($neg ? -$v : $v, $i + 1);
			case T_DNUMBER:
				$v = (float) $tok[1];
				return array($neg ? -$v : $v, $i + 1);
			case T_STRING:
				$w = strtolower($tok[1]);
				if (!$neg and $w === 'true') return array(true, $i + 1);
				if (!$neg and $w === 'false') return array(false, $i + 1);
				if (!$neg and $w === 'null') return array(null, $i + 1);
				return array(self::none(), $i);
		}
		return array(self::none(), $i);
	}

	/** The value of a quoted PHP string literal, for the simple escapes version files use. */
	private static function unquote($s)
	{
		$q = $s[0];
		$s = substr($s, 1, -1);
		if ($q === "'") return str_replace(array("\\\\", "\\'"), array("\\", "'"), $s);
		if (strpbrk($s, '$') !== false) return $s;
		return stripcslashes($s);
	}
}
