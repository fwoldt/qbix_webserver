<?php
/**
 * @module Q
 */

/**
 * Slows down password guessing: after 5 failed sign-ins from one address,
 * that address is locked out for 60 seconds, and each lockout after that
 * doubles, up to an hour. A successful sign-in clears its record. While
 * locked out, login.attempt is vetoed with 429 and the seconds left.
 *
 * Kept in the server's memory, which is where the panel runs; a restart
 * forgets it, which a guesser gains nothing from that a restart would not
 * give anyone.
 *
 * @class Q_WebServer_Panel_LockoutObserver
 */
class Q_WebServer_Panel_LockoutObserver implements Q_WebServer_Panel_Observer
{
	const FAILURES = 5;
	const FIRST = 60;
	const LONGEST = 3600;

	/** @var integer|null a fixed "now", for tests; null is the real clock */
	public static $clock = null;

	/** @var array ip => array(failures, lockouts, until) */
	private static $state = array();

	/** Forget every address (tests). */
	static function reset()
	{
		self::$state = array();
	}

	function update($event, array $context)
	{
		$ip = (string) ($context['ip'] ?? '');
		$now = self::$clock !== null ? (int) self::$clock : time();
		$s = self::$state[$ip] ?? array('failures' => 0, 'lockouts' => 0, 'until' => 0);
		switch ($event) {
			case 'login.attempt':
				if ($s['until'] > $now) {
					return array('status' => 429, 'body' => array(
						'error' => sprintf('Too many failed sign-ins; try again in %d seconds', $s['until'] - $now),
						'retryAfter' => $s['until'] - $now));
				}
				return null;
			case 'login.failed':
				$s['failures']++;
				if ($s['failures'] >= self::FAILURES) {
					$seconds = min(self::LONGEST, self::FIRST * (1 << min(10, $s['lockouts'])));
					$s['until'] = $now + $seconds;
					$s['lockouts']++;
					$failures = $s['failures'];
					$s['failures'] = 0;
					self::$state[$ip] = $s;
					Q_WebServer_Panel_Events::notify('lockout', array('ip' => $ip, 'seconds' => $seconds, 'failures' => $failures));
					return null;
				}
				self::$state[$ip] = $s;
				return null;
			case 'login.succeeded':
				unset(self::$state[$ip]);
				return null;
		}
		return null;
	}
}
