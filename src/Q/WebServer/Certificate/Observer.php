<?php
/**
 * @module Q
 */

/**
 * Something that wants to know what happens to the server's certificate: the
 * console reporter, the TLS listener that swaps it in, a test. Attached with
 * Q_WebServer_Certificate_Events::attach().
 *
 * Events and what $info carries:
 *   trying    provider                      a provider is about to run
 *   skipped   provider, reason              it cannot run here
 *   failed    provider, reason, seconds     it ran and made nothing usable
 *   created   provider, seconds, certificate
 *   exhausted reasons                        every provider failed
 *   stored    cert, key, previous           written to the store
 *   renewing  reason                        an existing one is being replaced
 *   loaded    cert, key, certificate        a usable one was found in place
 *   swapped   cert, key                     the running listener now presents it
 *   error     reason                        anything else that went wrong
 *
 * @class Q_WebServer_Certificate_Observer
 */
interface Q_WebServer_Certificate_Observer
{
	/**
	 * @param {string} $event
	 * @param {array} $info
	 */
	function update($event, array $info);
}
