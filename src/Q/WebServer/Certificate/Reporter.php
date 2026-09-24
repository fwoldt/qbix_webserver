<?php
/**
 * @module Q
 */

/**
 * Reports certificate events on the console, at the verbosity the command
 * line asked for -- the same levels for every tool:
 *
 *   QUIET    (-q, --quiet)        errors only
 *   NORMAL   (default)            one line: what is in use and where it came from
 *   VERBOSE  (-v, --verbose)      also every provider tried, and why one was skipped
 *   DEBUG    (-vv, --debug)       every event, with timings
 *
 * On a terminal, while a provider runs, a progress line shows which one; it is
 * overwritten by the result. Piped or logged output never gets it.
 *
 * @class Q_WebServer_Certificate_Reporter
 */
class Q_WebServer_Certificate_Reporter implements Q_WebServer_Certificate_Observer
{
	const QUIET = 0;
	const NORMAL = 1;
	const VERBOSE = 2;
	const DEBUG = 3;

	private $level;
	private $stream;
	private $tty;
	private $progress = false;

	/**
	 * @param {integer} $level one of the constants
	 * @param {resource} $stream default STDERR, where the server logs
	 */
	function __construct($level = self::NORMAL, $stream = null)
	{
		$this->level = (int) $level;
		$this->stream = $stream ?: (defined('STDERR') ? STDERR : fopen('php://stderr', 'w'));
		$this->tty = function_exists('stream_isatty') && @stream_isatty($this->stream)
			&& getenv('NO_COLOR') === false;
	}

	/**
	 * The level a set of parsed options asks for: quiet, v (a count when
	 * repeated), verbose, debug.
	 * @method levelFromOptions
	 * @static
	 * @param {array} $opts
	 * @return {integer}
	 */
	static function levelFromOptions(array $opts)
	{
		if (!empty($opts['quiet']) or !empty($opts['q'])) return self::QUIET;
		if (!empty($opts['debug'])) return self::DEBUG;
		$v = isset($opts['v']) ? (is_int($opts['v']) ? $opts['v'] : 1) : 0;
		if (!empty($opts['verbose'])) $v = max($v, 1);
		return min(self::DEBUG, self::NORMAL + $v);
	}

	function update($event, array $info)
	{
		$this->clearProgress();
		$p = isset($info['provider']) ? $info['provider'] : '';
		$secs = isset($info['seconds']) ? sprintf(', %.1fs', $info['seconds']) : '';
		switch ($event) {
			case 'trying':
				if ($this->level >= self::DEBUG) $this->line("tls: trying $p");
				elseif ($this->tty and $this->level >= self::NORMAL) $this->showProgress("tls: making a certificate ($p)...");
				return;
			case 'skipped':
				if ($this->level >= self::VERBOSE) $this->line("tls: skipped $p: " . $info['reason']);
				return;
			case 'failed':
				if ($this->level >= self::VERBOSE) $this->line("tls: $p failed: " . $info['reason'] . $secs);
				return;
			case 'created':
				$c = $info['certificate'];
				if ($this->level >= self::NORMAL) {
					$this->line(sprintf('tls: certificate ready (%s, %s%s, %d days, %s)', $c->keyType() ?: '?', $p, $secs,
						(int) $c->daysLeft(), implode(' ', $c->hosts())));
				}
				return;
			case 'loaded':
				if ($this->level >= self::VERBOSE) {
					$c = $info['certificate'];
					$this->line(sprintf('tls: using %s (%d days left, %s)', $info['cert'], (int) $c->daysLeft(), implode(' ', $c->hosts())));
				}
				return;
			case 'exhausted':
				$this->line('tls: could not make a certificate: ' . implode('; ', (array) $info['reasons']));
				return;
			case 'renewing':
				if ($this->level >= self::NORMAL) $this->line('tls: renewing the certificate: ' . $info['reason']);
				return;
			case 'swapped':
				if ($this->level >= self::NORMAL) $this->line('tls: new connections now get ' . $info['cert']);
				return;
			case 'error':
				$this->line('tls: ' . $info['reason']);
				return;
			default:
				if ($this->level >= self::DEBUG) $this->line("tls: $event " . json_encode(array_diff_key($info, array('certificate' => 1))));
		}
	}

	private function line($text)
	{
		fwrite($this->stream, $text . "\n");
	}

	private function showProgress($text)
	{
		fwrite($this->stream, "\r" . $text);
		$this->progress = true;
	}

	private function clearProgress()
	{
		if (!$this->progress) return;
		fwrite($this->stream, "\r\033[K");
		$this->progress = false;
	}
}
