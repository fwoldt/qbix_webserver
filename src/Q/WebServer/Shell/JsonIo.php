<?php
/**
 * @module Q
 */
/**
 * The runner's side of the server protocol: every message is one JSON object
 * on its own line of stdout; answers to prompts arrive the same way on stdin.
 *
 *   out     {"t":"out","d":"text"}          standard output
 *   err     {"t":"err","d":"text"}          standard error
 *   prompt  {"t":"prompt","d":"Proceed? "}  a question; the answer comes as
 *                                           {"t":"stdin","d":"y"} on stdin
 *   ctl     {"t":"ctl","op":"clear"|...}    terminal control (see Io::ctl)
 *   line    {"t":"line","d":"expanded"}     the line after history expansion
 *   state   {"t":"state","vars":{...}}      the session variables at the end
 *   exit    {"t":"exit","code":0}           the last message
 *
 * @class Q_WebServer_Shell_JsonIo
 */
class Q_WebServer_Shell_JsonIo extends Q_WebServer_Shell_Io
{
	private $in;
	private $outStream;
	private $interactive;

	function __construct($in = null, $out = null, $interactive = true)
	{
		$this->in = $in ?: STDIN;
		$this->outStream = $out ?: STDOUT;
		$this->interactive = (bool) $interactive;
	}

	function send(array $message)
	{
		fwrite($this->outStream, json_encode($message, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
		fflush($this->outStream);
	}

	function out($text) { if ($text !== '') $this->send(array('t' => 'out', 'd' => (string) $text)); }
	function err($text) { if ($text !== '') $this->send(array('t' => 'err', 'd' => (string) $text)); }
	function ctl(array $message) { $this->send(array('t' => 'ctl') + $message); }
	function interactive() { return $this->interactive; }


	/**
	 * Have the server check a password (sudo): the runner never reads the
	 * password file itself, and may not be able to (Q.shell.user).
	 * @return {array} array(ok, until, error)
	 */
	function verify($password)
	{
		$this->send(array('t' => 'verify', 'd' => (string) $password));
		while (($line = fgets($this->in)) !== false) {
			$m = json_decode($line, true);
			if (is_array($m) && ($m['t'] ?? '') === 'verified') {
				return array(!empty($m['ok']), (int) ($m['until'] ?? 0), isset($m['error']) ? (string) $m['error'] : null);
			}
		}
		return array(false, 0, 'the server did not answer');
	}
	function prompt($question, $secret = false)
	{
		if (!$this->interactive) return null;
		$this->send(array('t' => 'prompt', 'd' => (string) $question, 'secret' => (bool) $secret));
		while (($line = fgets($this->in)) !== false) {
			$m = json_decode($line, true);
			if (is_array($m) && ($m['t'] ?? '') === 'stdin') return rtrim((string) ($m['d'] ?? ''), "\r\n");
		}
		return null;
	}
}
