<?php
/**
 * @module Q
 */
/**
 * What a distribution implements to add commands and themes to the shell.
 * Register one with Q_WebServer_Shell::addProvider(), typically from the
 * distribution's register().
 *
 * A command spec is an array:
 *
 *   name         'noun verb' or a single word
 *   tier         basic | expanded | advanced (default advanced)
 *   disruptive   true to ask before it runs (default false)
 *   description  one line
 *   usage        the arguments part of its usage line
 *   options      option name => description (for completion and help)
 *   argv         callable(array $args, array $ctx): array -- the program and
 *                its arguments, run as a process of its own
 *   handler      callable(Q_WebServer_Shell_Interpreter, array $args,
 *                string $stdin, Q_WebServer_Shell_Sink): int -- instead of argv
 *
 * A theme is name => array(label, colours...) in the same form as the
 * shipped theme-<name>.json files.
 *
 * @class Q_WebServer_Shell_Provider
 */
interface Q_WebServer_Shell_Provider
{
	/**
	 * @param {array} $ctx the runner's context (serverDir, startOptions, ...)
	 * @return {array} list of command specs
	 */
	function commands(array $ctx);

	/**
	 * @return {array} name => theme
	 */
	function themes();
}
