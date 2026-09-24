<?php
/**
 * @module Q
 */
/**
 * $(( arithmetic )) for the shell: integers only, evaluated by a small
 * recursive-descent parser, never by eval().
 *
 * Operators, lowest precedence first: || && == != < <= > >= + - * / % ** and
 * unary - + !, with parentheses. Names are shell variables (unset or
 * non-numeric is 0), with or without a leading $.
 *
 * @class Q_WebServer_Shell_Arith
 */
class Q_WebServer_Shell_Arith
{
	private $t = array();
	private $i = 0;
	private $vars;

	/**
	 * @method evaluate
	 * @static
	 * @param {string} $expr
	 * @param {array} $vars name => value
	 * @return {integer}
	 * @throws Q_WebServer_Shell_SyntaxError
	 */
	static function evaluate($expr, array $vars)
	{
		$a = new self();
		$a->vars = $vars;
		$tok = '/\d+|\$?[A-Za-z_][A-Za-z0-9_]*|\*\*|==|!=|<=|>=|&&|\|\||[-+*\/%()<>!]/';
		if (trim(preg_replace($tok, ' ', $expr)) !== '') {
			throw new Q_WebServer_Shell_SyntaxError('arithmetic: cannot read "' . trim($expr) . '"');
		}
		preg_match_all($tok, $expr, $m);
		$a->t = $m[0];
		if (!$a->t) return 0;
		$v = $a->orExpr();
		if ($a->i < count($a->t)) throw new Q_WebServer_Shell_SyntaxError('arithmetic: unexpected "' . $a->t[$a->i] . '"');
		return $v;
	}

	private function peek() { return $this->t[$this->i] ?? null; }
	private function take() { return $this->t[$this->i++] ?? null; }

	private function orExpr()
	{
		$v = $this->andExpr();
		while ($this->peek() === '||') { $this->take(); $r = $this->andExpr(); $v = ($v || $r) ? 1 : 0; }
		return $v;
	}

	private function andExpr()
	{
		$v = $this->cmpExpr();
		while ($this->peek() === '&&') { $this->take(); $r = $this->cmpExpr(); $v = ($v && $r) ? 1 : 0; }
		return $v;
	}

	private function cmpExpr()
	{
		$v = $this->addExpr();
		while (in_array($this->peek(), array('==', '!=', '<', '<=', '>', '>='), true)) {
			$op = $this->take();
			$r = $this->addExpr();
			switch ($op) {
				case '==': $v = (int) ($v == $r); break;
				case '!=': $v = (int) ($v != $r); break;
				case '<': $v = (int) ($v < $r); break;
				case '<=': $v = (int) ($v <= $r); break;
				case '>': $v = (int) ($v > $r); break;
				default: $v = (int) ($v >= $r);
			}
		}
		return $v;
	}

	private function addExpr()
	{
		$v = $this->mulExpr();
		while (in_array($this->peek(), array('+', '-'), true)) {
			$op = $this->take();
			$r = $this->mulExpr();
			$v = $op === '+' ? $v + $r : $v - $r;
		}
		return (int) $v;
	}

	private function mulExpr()
	{
		$v = $this->powExpr();
		while (in_array($this->peek(), array('*', '/', '%'), true)) {
			$op = $this->take();
			$r = $this->powExpr();
			if ($op !== '*' && $r == 0) throw new Q_WebServer_Shell_SyntaxError('arithmetic: division by zero');
			$v = $op === '*' ? $v * $r : ($op === '/' ? intdiv($v, $r) : $v % $r);
		}
		return (int) $v;
	}

	private function powExpr()
	{
		$v = $this->unary();
		if ($this->peek() === '**') {
			$this->take();
			$r = $this->powExpr();
			if ($r < 0) throw new Q_WebServer_Shell_SyntaxError('arithmetic: negative exponent');
			$v = (int) min(PHP_INT_MAX, pow($v, min($r, 64)));
		}
		return $v;
	}

	private function unary()
	{
		$t = $this->peek();
		if ($t === '-') { $this->take(); return -$this->unary(); }
		if ($t === '+') { $this->take(); return $this->unary(); }
		if ($t === '!') { $this->take(); return $this->unary() ? 0 : 1; }
		return $this->primary();
	}

	private function primary()
	{
		$t = $this->take();
		if ($t === null) throw new Q_WebServer_Shell_SyntaxError('arithmetic: expression ends too soon');
		if ($t === '(') {
			$v = $this->orExpr();
			if ($this->take() !== ')') throw new Q_WebServer_Shell_SyntaxError('arithmetic: ")" expected');
			return $v;
		}
		if (ctype_digit($t)) return (int) $t;
		if (preg_match('/^\$?([A-Za-z_][A-Za-z0-9_]*)$/', $t, $m)) {
			$v = $this->vars[$m[1]] ?? '0';
			return is_numeric($v) ? (int) $v : 0;
		}
		throw new Q_WebServer_Shell_SyntaxError('arithmetic: unexpected "' . $t . '"');
	}
}
