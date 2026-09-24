<?php
/**
 * @module Q
 */

/**
 * The subject the certificate observers attach to. One per process: the
 * certificate is the server's, not a request's. An observer that throws is
 * reported as an "error" event to the others and never stops the one that
 * emitted.
 *
 * @class Q_WebServer_Certificate_Events
 * @static
 */
class Q_WebServer_Certificate_Events
{
	/** @var Q_WebServer_Certificate_Observer[] */
	private static $observers = array();

	/** Attach an observer; attaching the same one twice has no effect. */
	static function attach(Q_WebServer_Certificate_Observer $o)
	{
		self::$observers[spl_object_id($o)] = $o;
	}

	static function detach(Q_WebServer_Certificate_Observer $o)
	{
		unset(self::$observers[spl_object_id($o)]);
	}

	/** Detach every observer (tests, and a re-exec). */
	static function reset()
	{
		self::$observers = array();
	}

	/**
	 * Tell every observer.
	 * @method emit
	 * @static
	 * @param {string} $event
	 * @param {array} $info
	 */
	static function emit($event, array $info = array())
	{
		foreach (self::$observers as $o) {
			try {
				$o->update($event, $info);
			} catch (\Throwable $e) {
				if ($event === 'error') continue;
				self::emit('error', array('reason' => get_class($o) . ': ' . $e->getMessage()));
			}
		}
	}
}
