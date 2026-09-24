<?php
/**
 * @module Q
 */
/**
 * Collects everything a runner says, and answers prompts from a list; for
 * tests, and for command substitution.
 * @class Q_WebServer_Shell_BufferIo
 */
class Q_WebServer_Shell_BufferIo extends Q_WebServer_Shell_Io
{
	public $out = '';
	public $err = '';
	public $ctl = array();
	public $prompts = array();
	public $answers = array();
	public $isInteractive = true;

	function __construct(array $answers = array(), $interactive = true)
	{
		$this->answers = $answers;
		$this->isInteractive = $interactive;
	}

	function out($text) { $this->out .= $text; }
	function err($text) { $this->err .= $text; }
	function ctl(array $message) { $this->ctl[] = $message; }
	function interactive() { return $this->isInteractive; }

	function prompt($question, $secret = false)
	{
		$this->prompts[] = $question;
		return $this->answers ? array_shift($this->answers) : null;
	}
}
