<?php
/**
 * @module Q
 */
/**
 * Where a shell runner's output goes and its answers come from.
 *
 * Three implementations: Q_WebServer_Shell_JsonIo (the server's runner: one
 * JSON message per line on stdout, answers on stdin), Q_WebServer_Shell_TtyIo
 * (qshell.php in a terminal) and Q_WebServer_Shell_BufferIo (tests).
 *
 * @class Q_WebServer_Shell_Io
 * @abstract
 */
abstract class Q_WebServer_Shell_Io
{
	/** Standard output text. */
	abstract function out($text);

	/** Standard error text. */
	abstract function err($text);

	/**
	 * Ask a question and wait for the answer; a secret one (a password) is
	 * not echoed and never recorded.
	 * @return {string|null} the answer without its newline; null when nobody can answer
	 */
	abstract function prompt($question, $secret = false);

	/**
	 * A control message for the terminal: clear, hide, theme, bind, layout,
	 * pager, apply (a runtime setting for the server). Ignored where it means
	 * nothing.
	 */
	abstract function ctl(array $message);

	/** Whether a person is at the other end (confirmations can be asked). */
	function interactive() { return true; }
}
