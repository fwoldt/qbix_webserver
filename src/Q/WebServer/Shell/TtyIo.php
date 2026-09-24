<?php
/**
 * @module Q
 */
/**
 * qshell.php in a terminal: plain text out, answers read from the keyboard.
 * @class Q_WebServer_Shell_TtyIo
 */
class Q_WebServer_Shell_TtyIo extends Q_WebServer_Shell_Io
{
	function out($text) { fwrite(STDOUT, $text); }
	function err($text) { fwrite(STDERR, $text); }

	function prompt($question, $secret = false)
	{
		fwrite(STDOUT, $question);
		$line = fgets(STDIN);
		return $line === false ? null : rtrim($line, "\r\n");
	}

	function ctl(array $message)
	{
		if (($message['op'] ?? '') === 'clear') fwrite(STDOUT, "\033[H\033[2J");
	}

	function interactive() { return function_exists('stream_isatty') ? @stream_isatty(STDIN) : true; }
}
