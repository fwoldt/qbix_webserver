<?php
/**
 * @module Q
 */
/**
 * Runs one external command for the shell: an argv array handed straight to
 * proc_open(), so no shell ever re-reads it, with its output streamed into a
 * sink as it arrives.
 *
 * @class Q_WebServer_Shell_Exec
 * @static
 */
class Q_WebServer_Shell_Exec
{
	/**
	 * @method run
	 * @static
	 * @param {array} $argv the program and its arguments, as separate strings
	 * @param {string} $stdin what the command reads, then end of file
	 * @param {Q_WebServer_Shell_Sink} $sink
	 * @param {array} [$env=array()] extra environment, name => value
	 * @param {string|null} [$cwd=null]
	 * @return {integer} exit status (127 when it cannot start)
	 */
	static function run(array $argv, $stdin, Q_WebServer_Shell_Sink $sink, array $env = array(), $cwd = null)
	{
		$argv = array_values(array_map('strval', $argv));
		if (!$argv || $argv[0] === '') return 127;
		$environment = self::environment($env);
		$pipes = array();
		$proc = @proc_open($argv, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
			$pipes, $cwd, $environment);
		if (!is_resource($proc)) {
			$sink->error('qsh: cannot run ' . $argv[0] . "\n");
			return 127;
		}
		if ((string) $stdin !== '') @fwrite($pipes[0], (string) $stdin);
		fclose($pipes[0]);
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);
		$open = array(1 => $pipes[1], 2 => $pipes[2]);
		$cut = false;
		while ($open) {
			$read = array_values($open);
			$w = $e = null;
			if (@stream_select($read, $w, $e, 1) === false) break;
			foreach ($read as $stream) {
				$chunk = fread($stream, 65536);
				$which = array_search($stream, $open, true);
				if ($chunk === '' || $chunk === false) {
					if (feof($stream)) { fclose($stream); unset($open[$which]); }
					continue;
				}
				if ($which === 2) { $sink->error($chunk); continue; }
				if (!$cut && !$sink->write($chunk)) {
					$cut = true;
					proc_terminate($proc, 15);
				}
			}
		}
		foreach ($open as $s) fclose($s);
		$status = proc_close($proc);
		return $cut ? 141 : ($status < 0 ? 1 : $status);
	}

	/**
	 * The environment a command gets: the server's own, so its tools find
	 * their configuration, plus the shell's exported variables, and colour on
	 * even though no terminal is attached.
	 */
	static function environment(array $extra)
	{
		$env = getenv();
		if (!is_array($env)) $env = array();
		$env['QBIX_FORCE_COLOR'] = '1';
		$env['TERM'] = 'xterm-256color';
		foreach ($extra as $k => $v) {
			if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $k)) $env[$k] = (string) $v;
		}
		return $env;
	}
}
