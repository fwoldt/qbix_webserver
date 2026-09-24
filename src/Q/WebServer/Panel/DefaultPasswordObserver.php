<?php
/**
 * @module Q
 */

/**
 * Makes whoever signs in with the default key change it before anything
 * else. On login.succeeded with the default credential the session is marked
 * must-change; every session.request from such a session is vetoed except
 * changing the password and signing out; on password.changed the mark is
 * cleared (the new password is stored as a chosen one, default false, by
 * Q_WebServer_Panel_Auth::storePassword()). The panel page itself is not an
 * API call and is always served, so it can show the change form.
 *
 * @class Q_WebServer_Panel_DefaultPasswordObserver
 */
class Q_WebServer_Panel_DefaultPasswordObserver implements Q_WebServer_Panel_Observer
{
	/** The API routes a must-change session may still use. */
	private static $allowed = array('auth/password', 'auth/logout');

	function update($event, array $context)
	{
		$token = (string) ($context['token'] ?? '');
		switch ($event) {
			case 'login.succeeded':
				if (!empty($context['default']) and $token !== '') {
					Q_WebServer_Panel_Auth::setMustChange($token, true);
				}
				return null;
			case 'session.request':
				if ($token !== '' and Q_WebServer_Panel_Auth::mustChange($token)
					and !in_array((string) ($context['route'] ?? ''), self::$allowed, true)) {
					return array('status' => 403, 'body' => array(
						'error' => 'change the default password first', 'mustChange' => true));
				}
				return null;
			case 'password.changed':
				if ($token !== '') Q_WebServer_Panel_Auth::setMustChange($token, false);
				return null;
		}
		return null;
	}
}
