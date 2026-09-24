<?php
/**
 * @module Q
 */
/**
 * The shell's per-user files: command history, aliases and the autoexec
 * script, all under <data dir>/shell/.
 *
 *   history        one command per line, the newest last, at most MAX lines
 *   aliases.json   name => text
 *   autoexec.qsh   run when a shell session opens
 *
 * With no directory (tests, a read-only disk) everything is kept in memory.
 *
 * @class Q_WebServer_Shell_History
 */
class Q_WebServer_Shell_History
{
	const MAX = 5000;

	/** @var string|null */
	public $dir;

	private $lines = null;
	private $aliases = null;

	/** @var callable|null in a runner: sends changes to the server, which keeps the files */
	private $remote = null;

	/** @var array name => text of the shell directory's scripts, handed over by the server */
	private $files = array();

	/**
	 * History and aliases a runner got from the server. The runner may run as
	 * a user who cannot reach the shell directory, so it never touches the
	 * files: every change goes back to the server as a message.
	 * @method remote
	 * @static
	 */
	static function remote(array $lines, array $aliases, array $files, callable $send)
	{
		$h = new self(null);
		$h->lines = array_values(array_filter($lines, 'is_string'));
		$h->aliases = array_filter($aliases, 'is_string');
		$h->files = array_filter($files, 'is_string');
		$h->remote = $send;
		return $h;
	}

	function __construct($dir = null)
	{
		$this->dir = ($dir !== null && $dir !== '') ? rtrim($dir, '/') : null;
		if ($this->dir !== null && !is_dir($this->dir)) @mkdir($this->dir, 0700, true);
		if ($this->dir !== null && !is_dir($this->dir)) $this->dir = null;
	}

	/** Every recorded line, oldest first. */
	function lines()
	{
		if ($this->lines === null) {
			$this->lines = array();
			if ($this->dir !== null && is_file($this->dir . '/history')) {
				$this->lines = array_values(array_filter(explode("\n", (string) file_get_contents($this->dir . '/history')),
					function ($l) { return $l !== ''; }));
			}
		}
		return $this->lines;
	}

	/** Record a line (not a repeat of the one before, not one starting with a space). */
	function add($line)
	{
		$line = str_replace(array("\r", "\n"), array('', ' '), rtrim($line));
		if ($line === '' || $line[0] === ' ') return;
		$lines = $this->lines();
		if ($lines && end($lines) === $line) return;
		$this->lines[] = $line;
		if (count($this->lines) > self::MAX) $this->lines = array_slice($this->lines, -self::MAX);
		if ($this->remote) { call_user_func($this->remote, array('t' => 'hist', 'd' => $line)); return; }
		if ($this->dir !== null) {
			$file = $this->dir . '/history';
			if (count($this->lines) === self::MAX) {
				@file_put_contents($file . '.tmp', implode("\n", $this->lines) . "\n", LOCK_EX);
				@rename($file . '.tmp', $file);
			} else {
				@file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
			}
			@chmod($file, 0600);
		}
	}

	/** Forget the history. */
	function clear()
	{
		$this->lines = array();
		if ($this->remote) { call_user_func($this->remote, array('t' => 'hist_clear')); return; }
		if ($this->dir !== null) @file_put_contents($this->dir . '/history', '', LOCK_EX);
	}

	/**
	 * History expansion, as zsh and bash do it: !! (the last line), !n (line
	 * n), !-n (n lines back), !prefix (the latest line starting with it) and
	 * ^old^new (the last line with old replaced by new). Nothing inside single
	 * quotes is touched.
	 * @method expand
	 * @param {string} $line
	 * @return {string}
	 * @throws Exception when an event is not found
	 */
	function expand($line)
	{
		$lines = $this->lines();
		if (preg_match('/^\^([^^]*)\^([^^]*)\^?$/', $line, $m)) {
			$last = $lines ? end($lines) : null;
			if ($last === null || strpos($last, $m[1]) === false) throw new Exception('^' . $m[1] . ': substitution failed');
			return preg_replace('/' . preg_quote($m[1], '/') . '/', addcslashes($m[2], '\\$'), $last, 1);
		}
		if (strpos($line, '!') === false) return $line;
		$out = '';
		$n = strlen($line);
		$inSq = false;
		for ($i = 0; $i < $n; $i++) {
			$c = $line[$i];
			if ($c === "'") $inSq = !$inSq;
			if ($c === '\\' && $i + 1 < $n) { $out .= $c . $line[++$i]; continue; }
			if ($c !== '!' || $inSq || $i + 1 >= $n) { $out .= $c; continue; }
			$next = $line[$i + 1];
			if ($next === '!') {
				if (!$lines) throw new Exception('!!: event not found');
				$out .= end($lines);
				$i++;
				continue;
			}
			if (preg_match('/\G(-?\d+)/', $line, $m, 0, $i + 1)) {
				$k = (int) $m[1];
				$idx = $k < 0 ? count($lines) + $k : $k - 1;
				if (!isset($lines[$idx])) throw new Exception('!' . $m[1] . ': event not found');
				$out .= $lines[$idx];
				$i += strlen($m[1]);
				continue;
			}
			if (preg_match('/\G([A-Za-z_][A-Za-z0-9_:.-]*)/', $line, $m, 0, $i + 1)) {
				$hit = null;
				for ($j = count($lines) - 1; $j >= 0; $j--) {
					if (strncmp($lines[$j], $m[1], strlen($m[1])) === 0) { $hit = $lines[$j]; break; }
				}
				if ($hit === null) throw new Exception('!' . $m[1] . ': event not found');
				$out .= $hit;
				$i += strlen($m[1]);
				continue;
			}
			$out .= $c;
		}
		return $out;
	}

	// ── Aliases ─────────────────────────────────────────────────────────

	function aliases()
	{
		if ($this->aliases === null) {
			$this->aliases = array();
			if ($this->dir !== null && is_file($this->dir . '/aliases.json')) {
				$a = json_decode((string) file_get_contents($this->dir . '/aliases.json'), true);
				if (is_array($a)) $this->aliases = array_filter($a, 'is_string');
			}
		}
		return $this->aliases;
	}

	function setAlias($name, $text)
	{
		$this->aliases();
		if ($text === null) unset($this->aliases[$name]);
		else $this->aliases[$name] = (string) $text;
		ksort($this->aliases);
		if ($this->remote) { call_user_func($this->remote, array('t' => 'alias', 'name' => (string) $name, 'text' => $text)); return; }
		if ($this->dir !== null) {
			$file = $this->dir . '/aliases.json';
			@file_put_contents($file . '.tmp', json_encode($this->aliases, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
			@rename($file . '.tmp', $file);
			@chmod($file, 0600);
		}
	}


	/**
	 * The text of a script to exec or source: from the shell directory (as
	 * the server handed it over), else from one of $roots.
	 * @return {string|null}
	 */
	function scriptText($name, array $roots)
	{
		if (isset($this->files[$name]) && preg_match('/^[A-Za-z0-9._-]+$/', (string) $name)) return $this->files[$name];
		$path = self::scriptPath($name, $roots);
		return $path === null ? null : (string) @file_get_contents($path, false, null, 0, 262144);
	}

	/**
	 * The shell directory's scripts (*.qsh, *.sh, *.cfg), for a runner.
	 * @method files
	 * @static
	 */
	static function scriptsIn($dir)
	{
		$out = array();
		$total = 0;
		foreach ((array) @scandir((string) $dir) as $f) {
			if (!is_string($f) || !preg_match('/^[A-Za-z0-9._-]+\.(qsh|sh|cfg)$/', $f) || !is_file("$dir/$f")) continue;
			$text = (string) @file_get_contents("$dir/$f", false, null, 0, 65536);
			$total += strlen($text);
			if ($total > 262144) break;
			$out[$f] = $text;
		}
		return $out;
	}
	// ── Scripts ─────────────────────────────────────────────────────────

	/**
	 * The path of a script the shell may exec or source: a plain name in the
	 * shell's own directory, or in the scripts directory; never a path that
	 * climbs out of either.
	 * @param {string} $name
	 * @param {array} $roots directories to look in
	 * @return {string|null}
	 */
	static function scriptPath($name, array $roots)
	{
		$name = (string) $name;
		if ($name === '' || strpos($name, "\0") !== false) return null;
		if (!preg_match('/^[A-Za-z0-9._-]+(\/[A-Za-z0-9._-]+)*$/', $name) || preg_match('/(^|\/)\.\.?(\/|$)/', $name)) return null;
		foreach ($roots as $root) {
			if (!$root || !is_dir($root)) continue;
			$base = realpath($root);
			$full = realpath($base . '/' . $name);
			if ($full !== false && strpos($full, $base . DIRECTORY_SEPARATOR) === 0 && is_file($full)) return $full;
		}
		return null;
	}
}
