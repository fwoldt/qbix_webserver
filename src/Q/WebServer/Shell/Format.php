<?php
/**
 * @module Q
 */
/**
 * Output in the manner of zfs and zpool:
 *
 *   -o col,col   choose and order the columns
 *   -H           scripted: no header, fields separated by one tab
 *   -p           exact (parsable) values: bytes and seconds as plain numbers
 *   -j           JSON instead of a table
 *
 * Otherwise an aligned table with an upper-case header.
 *
 * @class Q_WebServer_Shell_Format
 * @static
 */
class Q_WebServer_Shell_Format
{
	/**
	 * Split the formatting flags from the other arguments.
	 * @return {array} array(opts, rest) where opts has H, p, j (bools) and o (array|null)
	 */
	static function options(array $args)
	{
		$opts = array('H' => false, 'p' => false, 'j' => false, 'o' => null);
		$rest = array();
		for ($i = 0, $n = count($args); $i < $n; $i++) {
			$a = $args[$i];
			if ($a === '-o' && $i + 1 < $n) { $opts['o'] = explode(',', $args[++$i]); continue; }
			if (strncmp($a, '-o', 2) === 0 && strlen($a) > 2 && $a[2] !== '-') { $opts['o'] = explode(',', substr($a, 2)); continue; }
			if (preg_match('/^-[Hpj]+$/', $a)) {
				foreach (str_split(substr($a, 1)) as $f) $opts[$f] = true;
				continue;
			}
			$rest[] = $a;
		}
		return array($opts, $rest);
	}

	/**
	 * Render rows.
	 * @param {array} $rows list of associative arrays
	 * @param {array} $columns the default columns
	 * @param {array} $opts from options()
	 * @return {string}
	 */
	static function table(array $rows, array $columns, array $opts)
	{
		$cols = $opts['o'] ? array_values(array_filter(array_map('trim', $opts['o']))) : $columns;
		if ($opts['j']) {
			$out = array();
			foreach ($rows as $r) {
				$o = array();
				foreach ($cols as $c) $o[$c] = $r[$c] ?? null;
				$out[] = $o;
			}
			return json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		}
		$cells = array();
		foreach ($rows as $r) {
			$line = array();
			foreach ($cols as $c) $line[] = self::cell($c, $r[$c] ?? '-', $opts['p']);
			$cells[] = $line;
		}
		if ($opts['H']) {
			$s = '';
			foreach ($cells as $line) $s .= implode("\t", $line) . "\n";
			return $s;
		}
		$widths = array();
		foreach ($cols as $i => $c) $widths[$i] = strlen($c);
		foreach ($cells as $line) foreach ($line as $i => $v) $widths[$i] = max($widths[$i], self::width($v));
		$s = "\033[1m";
		foreach ($cols as $i => $c) $s .= str_pad(strtoupper($c), $widths[$i] + 2);
		$s = rtrim($s) . "\033[0m\n";
		foreach ($cells as $line) {
			$row = '';
			foreach ($line as $i => $v) $row .= $v . str_repeat(' ', max(0, $widths[$i] + 2 - self::width($v)));
			$s .= rtrim($row) . "\n";
		}
		return $s;
	}

	/** Visible width, without colour codes. */
	static function width($s)
	{
		$plain = preg_replace('/\033\[[0-9;]*m/', '', (string) $s);
		return function_exists('mb_strwidth') ? mb_strwidth($plain, 'UTF-8') : strlen($plain);
	}

	/** One value, humanised unless -p. */
	static function cell($column, $v, $exact)
	{
		if (is_bool($v)) return $v ? 'yes' : 'no';
		if (is_array($v)) return json_encode($v);
		$v = (string) $v;
		if ($exact || !is_numeric($v)) return $v;
		if (preg_match('/(bytes|size)$/i', $column)) return self::bytes((float) $v);
		return $v;
	}

	static function bytes($b)
	{
		$u = array('B', 'K', 'M', 'G', 'T');
		$i = 0;
		while ($b >= 1024 && $i < 4) { $b /= 1024; $i++; }
		return ($i ? number_format($b, $b < 10 ? 1 : 0) : (string) (int) $b) . $u[$i];
	}

	/** Properties (name => value) as a NAME VALUE table, like `zfs get`. */
	static function properties(array $props, array $opts, array $columns = array('name', 'value'))
	{
		$rows = array();
		foreach ($props as $k => $v) $rows[] = array('name' => $k, 'value' => $v);
		return self::table($rows, $columns, $opts);
	}
}
