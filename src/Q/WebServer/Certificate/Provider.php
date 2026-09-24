<?php
/**
 * @module Q
 */

/**
 * One way of making a self-signed certificate. Q_WebServer_Certificate_Factory
 * tries its providers in order until one returns a usable certificate, so a
 * system that refuses one method (a crypto policy, a missing extension, no
 * openssl binary) falls through to the next.
 *
 * A distribution adds or reorders providers in its register() through
 * Q_WebServer_Certificate_Factory::add() and ::prepend().
 *
 * @class Q_WebServer_Certificate_Provider
 */
interface Q_WebServer_Certificate_Provider
{
	/** @return {string} a short name for logs, e.g. "openssl-ecdsa" */
	function name();

	/**
	 * Whether this provider can run here at all. When it cannot, the reason is
	 * returned by reference, for the -v report.
	 * @param {string} $why
	 * @return {boolean}
	 */
	function available(&$why = '');

	/**
	 * Make a certificate for these host names and addresses, valid for $days.
	 * Null when it fails; the reason is returned by reference.
	 * @param {array} $hosts the first is the common name
	 * @param {integer} $days
	 * @param {string} $why
	 * @return {Q_WebServer_Certificate|null}
	 */
	function create(array $hosts, $days, &$why = '');
}
