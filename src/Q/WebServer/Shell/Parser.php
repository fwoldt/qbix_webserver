<?php
/**
 * @module Q
 */
/**
 * The shell's tokenizer and parser: a command line in, an AST out.
 *
 * The grammar is the useful core of the POSIX/bash shell, parsed for real
 * rather than pattern-matched, and never evaluated as PHP:
 *
 *   list      := andor ((";" | "&" | newline) andor)* [";" | "&"]
 *   andor     := pipeline (("&&" | "||") pipeline)*
 *   pipeline  := ["!"] command ("|" command)*
 *   command   := simple | "(" list ")" | "{" list "}"
 *              | if list then list (elif list then list)* [else list] fi
 *              | for NAME [in word*] (";" | newline) do list done
 *              | (while | until) list do list done
 *   simple    := (NAME=word)* word* ["<<<" word]
 *
 * A word is a list of parts, expanded later by the interpreter:
 *   array('lit', text)            unquoted text (brace expansion applies)
 *   array('sq', text)             'single quoted'
 *   array('dq', parts)            "double quoted", with $ expansions inside
 *   array('var', name, op, word)  $NAME, ${NAME}, ${NAME:-word}, ${NAME:=word},
 *                                 ${NAME:+word}, ${#NAME}
 *   array('cmd', list)            $(command substitution)
 *   array('arith', text)          $((arithmetic))
 *
 * Node shapes:
 *   list     array('type'=>'list', 'items'=>array(array('node'=>andor, 'bg'=>bool)))
 *   andor    array('type'=>'andor', 'first'=>pipeline, 'rest'=>array(array('op', pipeline)))
 *   pipeline array('type'=>'pipeline', 'neg'=>bool, 'cmds'=>array(command))
 *   simple   array('type'=>'simple', 'assigns'=>array(array(name, word)), 'words'=>array(word), 'here'=>word|null)
 *   subshell / group  array('type'=>..., 'body'=>list)
 *   if       array('type'=>'if', 'clauses'=>array(array(cond, body)), 'else'=>list|null)
 *   for      array('type'=>'for', 'var'=>name, 'words'=>array|null, 'body'=>list)
 *   while    array('type'=>'while', 'until'=>bool, 'cond'=>list, 'body'=>list)
 *
 * @class Q_WebServer_Shell_Parser
 */
class Q_WebServer_Shell_Parser
{
	/** Operators, longest first. */
	const OPS = array('<<<', '&&', '||', ';;', ';', '&', '|', '(', ')');

	/** Words that open or close compound commands, when unquoted and alone. */
	const RESERVED = array('if', 'then', 'elif', 'else', 'fi', 'for', 'in', 'do', 'done',
		'while', 'until', '{', '}', '!');

	/** Nesting limit for $(...) and compound commands: a line is not a program. */
	const MAX_DEPTH = 32;

	/** @var array tokens: array('op', text) | array('word', parts, raw) | array('nl') */
	private $tokens = array();
	private $pos = 0;
	private $depth = 0;

	/**
	 * Parse a command line into a list node.
	 * @method parse
	 * @static
	 * @param {string} $line
	 * @param {integer} [$depth=0]
	 * @return {array}
	 * @throws Q_WebServer_Shell_SyntaxError
	 */
	static function parse($line, $depth = 0)
	{
		if ($depth > self::MAX_DEPTH) throw new Q_WebServer_Shell_SyntaxError('nesting too deep');
		$p = new self();
		$p->depth = $depth;
		$p->tokens = self::tokenize($line, $depth);
		$p->pos = 0;
		$list = $p->parseList(array());
		if ($p->pos < count($p->tokens)) {
			throw new Q_WebServer_Shell_SyntaxError('unexpected ' . $p->describe($p->tokens[$p->pos]));
		}
		return $list;
	}

	// ── Tokenizer ───────────────────────────────────────────────────────

	/**
	 * Split a line into operator and word tokens.
	 * @method tokenize
	 * @static
	 * @param {string} $s
	 * @param {integer} [$depth=0]
	 * @return {array}
	 */
	static function tokenize($s, $depth = 0)
	{
		$tokens = array();
		$n = strlen($s);
		$i = 0;
		while ($i < $n) {
			$c = $s[$i];
			if ($c === "\n") { $tokens[] = array('nl'); $i++; continue; }
			if ($c === ' ' || $c === "\t" || $c === "\r") { $i++; continue; }
			if ($c === '#' ) {
				// A comment runs to the end of the line.
				while ($i < $n && $s[$i] !== "\n") $i++;
				continue;
			}
			foreach (self::OPS as $op) {
				if (substr_compare($s, $op, $i, strlen($op)) === 0) {
					if ($op === ';;') throw new Q_WebServer_Shell_SyntaxError('";;" is not supported (no case statements)');
					$tokens[] = array('op', $op);
					$i += strlen($op);
					continue 2;
				}
			}
			$start = $i;
			$parts = self::readWord($s, $i, $depth);
			$tokens[] = array('word', $parts, substr($s, $start, $i - $start));
		}
		return $tokens;
	}

	/** Characters that end an unquoted word. */
	private static function isBreak($c)
	{
		return $c === ' ' || $c === "\t" || $c === "\n" || $c === "\r"
			|| $c === ';' || $c === '&' || $c === '|' || $c === '(' || $c === ')' || $c === '<';
	}

	/**
	 * Read one word starting at $i, advancing $i past it.
	 * @return {array} parts
	 */
	private static function readWord($s, &$i, $depth)
	{
		$n = strlen($s);
		$parts = array();
		$lit = '';
		$flush = function () use (&$parts, &$lit) {
			if ($lit !== '') { $parts[] = array('lit', $lit); $lit = ''; }
		};
		while ($i < $n) {
			$c = $s[$i];
			if (self::isBreak($c)) {
				// "<" only breaks as the start of "<<<"; alone it is text.
				if ($c === '<' && substr_compare($s, '<<<', $i, 3) !== 0) { $lit .= $c; $i++; continue; }
				break;
			}
			if ($c === '\\') {
				if ($i + 1 < $n) {
					if ($s[$i + 1] === "\n") { $i += 2; continue; }
					// An escaped character is literal, and never a brace or glob.
					$flush();
					$parts[] = array('sq', $s[$i + 1]);
					$i += 2;
				} else {
					$i++;
				}
				continue;
			}
			if ($c === "'") {
				$end = strpos($s, "'", $i + 1);
				if ($end === false) throw new Q_WebServer_Shell_SyntaxError('unterminated single quote');
				$flush();
				$parts[] = array('sq', substr($s, $i + 1, $end - $i - 1));
				$i = $end + 1;
				continue;
			}
			if ($c === '"') {
				$flush();
				$i++;
				$parts[] = array('dq', self::readDouble($s, $i, $depth));
				continue;
			}
			if ($c === '$') {
				$exp = self::readDollar($s, $i, $depth);
				if ($exp === null) { $lit .= '$'; $i++; continue; }
				$flush();
				$parts[] = $exp;
				continue;
			}
			if ($c === '`') {
				throw new Q_WebServer_Shell_SyntaxError('backquotes are not supported; use $(command)');
			}
			$lit .= $c;
			$i++;
		}
		$flush();
		return $parts;
	}

	/** The inside of "double quotes", from just after the opening quote. */
	private static function readDouble($s, &$i, $depth)
	{
		$n = strlen($s);
		$parts = array();
		$lit = '';
		while ($i < $n) {
			$c = $s[$i];
			if ($c === '"') {
				$i++;
				if ($lit !== '') $parts[] = array('sq', $lit);
				return $parts;
			}
			if ($c === '\\' && $i + 1 < $n && strpos('$"\\`' . "\n", $s[$i + 1]) !== false) {
				if ($s[$i + 1] !== "\n") $lit .= $s[$i + 1];
				$i += 2;
				continue;
			}
			if ($c === '$') {
				$exp = self::readDollar($s, $i, $depth);
				if ($exp === null) { $lit .= '$'; $i++; continue; }
				if ($lit !== '') { $parts[] = array('sq', $lit); $lit = ''; }
				$parts[] = $exp;
				continue;
			}
			$lit .= $c;
			$i++;
		}
		throw new Q_WebServer_Shell_SyntaxError('unterminated double quote');
	}

	/**
	 * A $ expansion at $i, or null when the $ is literal.
	 * @return {array|null}
	 */
	private static function readDollar($s, &$i, $depth)
	{
		$n = strlen($s);
		$next = $s[$i + 1] ?? '';
		if (substr_compare($s, '$((', $i, 3) === 0) {
			$j = $i + 3;
			$level = 0;
			while ($j < $n) {
				if ($s[$j] === '(') $level++;
				elseif ($s[$j] === ')') {
					if ($level === 0 && ($s[$j + 1] ?? '') === ')') {
						$expr = substr($s, $i + 3, $j - $i - 3);
						$i = $j + 2;
						return array('arith', $expr);
					}
					$level--;
				}
				$j++;
			}
			throw new Q_WebServer_Shell_SyntaxError('unterminated $((');
		}
		if ($next === '(') {
			$j = self::matchParen($s, $i + 1);
			$inner = substr($s, $i + 2, $j - $i - 2);
			$i = $j + 1;
			return array('cmd', self::parse($inner, $depth + 1));
		}
		if ($next === '{') {
			$end = strpos($s, '}', $i + 2);
			if ($end === false) throw new Q_WebServer_Shell_SyntaxError('unterminated ${');
			$body = substr($s, $i + 2, $end - $i - 2);
			$i = $end + 1;
			if (preg_match('/^#([A-Za-z_][A-Za-z0-9_]*)$/', $body, $m)) return array('var', $m[1], '#', null);
			if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*|[0-9]|[?#$!@*])(?:(:?[-=+?])(.*))?$/s', $body, $m)) {
				$op = isset($m[2]) && $m[2] !== '' ? $m[2] : null;
				$arg = $op !== null ? self::tokenizeInner($m[3], $depth) : null;
				return array('var', $m[1], $op, $arg);
			}
			throw new Q_WebServer_Shell_SyntaxError('bad substitution: ${' . $body . '}');
		}
		if (preg_match('/\G([A-Za-z_][A-Za-z0-9_]*|[0-9?#$!@*])/', $s, $m, 0, $i + 1)) {
			$i += 1 + strlen($m[1]);
			return array('var', $m[1], null, null);
		}
		return null;
	}

	/** The word inside ${NAME:-word}, as parts. */
	private static function tokenizeInner($text, $depth)
	{
		$i = 0;
		$parts = array();
		$n = strlen($text);
		while ($i < $n) {
			// Spaces are part of the default value, not separators.
			$chunk = self::readWord($text, $i, $depth);
			foreach ($chunk as $p) $parts[] = $p;
			if ($i < $n) { $parts[] = array('sq', $text[$i]); $i++; }
		}
		return $parts;
	}

	/** The index of the ")" matching the "(" at $open, respecting quotes. */
	private static function matchParen($s, $open)
	{
		$n = strlen($s);
		$level = 0;
		for ($j = $open; $j < $n; $j++) {
			$c = $s[$j];
			if ($c === '\\') { $j++; continue; }
			if ($c === "'") {
				$e = strpos($s, "'", $j + 1);
				if ($e === false) break;
				$j = $e;
				continue;
			}
			if ($c === '"') {
				for ($j++; $j < $n && $s[$j] !== '"'; $j++) {
					if ($s[$j] === '\\') $j++;
				}
				continue;
			}
			if ($c === '(') $level++;
			elseif ($c === ')') {
				$level--;
				if ($level === 0) return $j;
			}
		}
		throw new Q_WebServer_Shell_SyntaxError('unterminated $(');
	}

	// ── Parser ──────────────────────────────────────────────────────────

	private function peek() { return $this->tokens[$this->pos] ?? null; }

	/** Whether the next token is the unquoted word $w. */
	private function isWord($w)
	{
		$t = $this->peek();
		return $t && $t[0] === 'word' && count($t[1]) === 1 && $t[1][0][0] === 'lit' && $t[1][0][1] === $w;
	}

	private function isOp($op)
	{
		$t = $this->peek();
		return $t && $t[0] === 'op' && $t[1] === $op;
	}

	private function describe($t)
	{
		if ($t[0] === 'nl') return 'end of line';
		if ($t[0] === 'op') return '"' . $t[1] . '"';
		return '"' . $t[2] . '"';
	}

	private function skipNewlines()
	{
		while (($t = $this->peek()) && $t[0] === 'nl') $this->pos++;
	}

	private function expectWord($w)
	{
		$this->skipNewlines();
		if (!$this->isWord($w)) {
			$t = $this->peek();
			throw new Q_WebServer_Shell_SyntaxError('expected "' . $w . '"' . ($t ? ', found ' . $this->describe($t) : ' before the end of the line'));
		}
		$this->pos++;
	}

	/**
	 * A list, stopping before any of $stops (reserved words) or ")".
	 */
	private function parseList(array $stops)
	{
		if (++$this->depth > self::MAX_DEPTH) throw new Q_WebServer_Shell_SyntaxError('nesting too deep');
		$items = array();
		while (true) {
			$this->skipNewlines();
			$t = $this->peek();
			if ($t === null || $this->isOp(')')) break;
			$stop = false;
			foreach ($stops as $w) if ($this->isWord($w)) { $stop = true; break; }
			if ($stop) break;
			$node = $this->parseAndOr();
			$bg = false;
			if ($this->isOp('&')) { $bg = true; $this->pos++; }
			elseif ($this->isOp(';')) { $this->pos++; }
			elseif (($t = $this->peek()) && $t[0] === 'nl') { $this->pos++; }
			$items[] = array('node' => $node, 'bg' => $bg);
		}
		$this->depth--;
		return array('type' => 'list', 'items' => $items);
	}

	private function parseAndOr()
	{
		$first = $this->parsePipeline();
		$rest = array();
		while ($this->isOp('&&') || $this->isOp('||')) {
			$op = $this->tokens[$this->pos++][1];
			$this->skipNewlines();
			$rest[] = array($op, $this->parsePipeline());
		}
		return array('type' => 'andor', 'first' => $first, 'rest' => $rest);
	}

	private function parsePipeline()
	{
		$neg = false;
		if ($this->isWord('!')) { $neg = true; $this->pos++; }
		$cmds = array($this->parseCommand());
		while ($this->isOp('|')) {
			$this->pos++;
			$this->skipNewlines();
			$cmds[] = $this->parseCommand();
		}
		return array('type' => 'pipeline', 'neg' => $neg, 'cmds' => $cmds);
	}

	private function parseCommand()
	{
		$t = $this->peek();
		if ($t === null) throw new Q_WebServer_Shell_SyntaxError('a command was expected before the end of the line');
		if ($this->isOp('(')) {
			$this->pos++;
			$body = $this->parseList(array());
			if (!$this->isOp(')')) throw new Q_WebServer_Shell_SyntaxError('expected ")"');
			$this->pos++;
			return array('type' => 'subshell', 'body' => $body);
		}
		if ($this->isWord('{')) {
			$this->pos++;
			$body = $this->parseList(array('}'));
			$this->expectWord('}');
			return array('type' => 'group', 'body' => $body);
		}
		if ($this->isWord('if')) {
			$this->pos++;
			$clauses = array();
			$cond = $this->parseList(array('then'));
			$this->expectWord('then');
			$body = $this->parseList(array('elif', 'else', 'fi'));
			$clauses[] = array($cond, $body);
			$else = null;
			while (true) {
				$this->skipNewlines();
				if ($this->isWord('elif')) {
					$this->pos++;
					$cond = $this->parseList(array('then'));
					$this->expectWord('then');
					$clauses[] = array($cond, $this->parseList(array('elif', 'else', 'fi')));
					continue;
				}
				if ($this->isWord('else')) {
					$this->pos++;
					$else = $this->parseList(array('fi'));
				}
				break;
			}
			$this->expectWord('fi');
			return array('type' => 'if', 'clauses' => $clauses, 'else' => $else);
		}
		if ($this->isWord('for')) {
			$this->pos++;
			$t = $this->peek();
			if (!$t || $t[0] !== 'word' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $t[2])) {
				throw new Q_WebServer_Shell_SyntaxError('for: a variable name was expected');
			}
			$var = $t[2];
			$this->pos++;
			$words = null;
			if ($this->isWord('in')) {
				$this->pos++;
				$words = array();
				while (($t = $this->peek()) && $t[0] === 'word' && !$this->isWord('do')) {
					$words[] = $t[1];
					$this->pos++;
				}
			}
			if ($this->isOp(';')) $this->pos++;
			$this->expectWord('do');
			$body = $this->parseList(array('done'));
			$this->expectWord('done');
			return array('type' => 'for', 'var' => $var, 'words' => $words, 'body' => $body);
		}
		if ($this->isWord('while') || $this->isWord('until')) {
			$until = $this->isWord('until');
			$this->pos++;
			$cond = $this->parseList(array('do'));
			$this->expectWord('do');
			$body = $this->parseList(array('done'));
			$this->expectWord('done');
			return array('type' => 'while', 'until' => $until, 'cond' => $cond, 'body' => $body);
		}
		foreach (array('then', 'elif', 'else', 'fi', 'do', 'done', '}', 'in') as $w) {
			if ($this->isWord($w)) throw new Q_WebServer_Shell_SyntaxError('unexpected "' . $w . '"');
		}
		return $this->parseSimple();
	}

	private function parseSimple()
	{
		$assigns = array();
		$words = array();
		$here = null;
		while (($t = $this->peek()) !== null) {
			if ($t[0] === 'op') {
				if ($t[1] === '<<<') {
					$this->pos++;
					$w = $this->peek();
					if (!$w || $w[0] !== 'word') throw new Q_WebServer_Shell_SyntaxError('"<<<" needs a word after it');
					$here = $w[1];
					$this->pos++;
					continue;
				}
				break;
			}
			if ($t[0] !== 'word') break;
			if (!$words && preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=/', $t[2], $m) && $t[1] && $t[1][0][0] === 'lit') {
				// NAME=value before the command: strip "NAME=" from the first part.
				$parts = $t[1];
				$parts[0] = array('lit', substr($parts[0][1], strlen($m[1]) + 1));
				if ($parts[0][1] === '') array_shift($parts);
				$assigns[] = array($m[1], $parts);
				$this->pos++;
				continue;
			}
			$words[] = $t[1];
			$this->pos++;
		}
		if (!$assigns && !$words) {
			$t = $this->peek();
			throw new Q_WebServer_Shell_SyntaxError($t ? 'unexpected ' . $this->describe($t) : 'a command was expected');
		}
		return array('type' => 'simple', 'assigns' => $assigns, 'words' => $words, 'here' => $here);
	}

	/**
	 * Whether a parsed line ends by sending its last command to the background
	 * ("cmd &"), the only form the server runs as a job.
	 * @method isBackground
	 * @static
	 * @param {array} $list
	 * @return {boolean}
	 */
	static function isBackground(array $list)
	{
		$items = $list['items'];
		return $items && end($items)['bg'] === true;
	}

	/**
	 * The first word of a simple, plain line, or null: what the server looks
	 * at to answer job commands (jobs, fg, bg, kill %n, wait) itself.
	 * @method firstWord
	 * @static
	 * @param {array} $list
	 * @return {array|null} array(word, args) as plain strings, when every word is literal
	 */
	static function simpleWords(array $list)
	{
		if (count($list['items']) !== 1) return null;
		$andor = $list['items'][0]['node'];
		if ($andor['rest'] || count($andor['first']['cmds']) !== 1) return null;
		$cmd = $andor['first']['cmds'][0];
		if ($cmd['type'] !== 'simple' || $cmd['assigns']) return null;
		$out = array();
		foreach ($cmd['words'] as $w) {
			$s = '';
			foreach ($w as $p) {
				if ($p[0] !== 'lit' && $p[0] !== 'sq') return null;
				$s .= $p[1];
			}
			$out[] = $s;
		}
		return $out;
	}
}

