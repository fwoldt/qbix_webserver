<?php
/**
 * @module Q
 */

/**
 * Something that wants to know what happens at the control panel's door.
 * Attached with Q_WebServer_Panel_Events::attach().
 *
 * Events and what $context carries:
 *   login.attempt    ip                              before a password is checked
 *   login.succeeded  token, ip, default               a session was issued
 *   login.failed     ip, default                      a wrong password
 *   password.changed token, ip                        a new password was stored
 *   session.request  token, ip, route                a signed-in API call
 *
 * 'default' is true when the credential used was the default key.
 *
 * update() may veto the request by returning a response -- array('status' =>
 * int, 'body' => array) -- which is sent instead; null lets it through. Only
 * login.attempt and session.request can be vetoed.
 *
 * @class Q_WebServer_Panel_Observer
 */
interface Q_WebServer_Panel_Observer
{
	/**
	 * @param {string} $event
	 * @param {array} $context
	 * @return {array|null} a veto response, or null
	 */
	function update($event, array $context);
}
