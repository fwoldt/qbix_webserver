<?php
/**
 * @module Q
 */

/**
 * Writes one line to the server log for each thing that happens at the
 * panel's door. A sign-in with the default key is a warning, with the
 * address it came from: until it is changed, anyone who knows it can do the
 * same. Session requests are not logged -- they are ordinary traffic.
 *
 * @class Q_WebServer_Panel_LogObserver
 */
class Q_WebServer_Panel_LogObserver implements Q_WebServer_Panel_Observer
{
	private $stream;

	/** @param {resource} $stream default STDERR, where the server logs */
	function __construct($stream = null)
	{
		$this->stream = $stream ?: (defined('STDERR') ? STDERR : fopen('php://stderr', 'w'));
	}

	function update($event, array $context)
	{
		$ip = (string) ($context['ip'] ?? '?');
		$line = null;
		switch ($event) {
			case 'login.succeeded':
				$line = !empty($context['default'])
					? "WARNING: signed in with the DEFAULT panel password from $ip; it must be changed now"
					: "signed in from $ip";
				break;
			case 'login.failed':
				$line = 'failed sign-in from ' . $ip . (!empty($context['default']) ? ' (the default password is in force)' : '');
				break;
			case 'password.changed':
				$line = "password changed from $ip";
				break;
			case 'lockout':
				$line = sprintf('WARNING: %s locked out for %ds after %d failed sign-ins', $ip,
					(int) ($context['seconds'] ?? 0), (int) ($context['failures'] ?? 0));
				break;
		}
		if ($line !== null) @fwrite($this->stream, '  panel: ' . $line . "\n");
		return null;
	}
}
