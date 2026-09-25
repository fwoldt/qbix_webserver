<?php
/**
 * @module Q
 */
/**
 * The base Qbix distribution of the engine.
 *
 * Loaded when the engine is run with --distribution=qbix, the source tree's
 * DISTRIBUTION file says 'qbix', or QBIX_DISTRIBUTION=qbix. It adds no overlay
 * beyond the standard /etc/qbix tree; it registers the richer detection of
 * Qbix apps and their panel command.
 *
 * @class Q_WebServer_Distribution_Qbix
 * @static
 */
class Q_WebServer_Distribution_Qbix
{
	/** The distribution's code identifier. */
	const NAME = 'qbix';

	/**
	 * Called once at start-up when this distribution is selected.
	 * @method register
	 * @static
	 */
	static function register()
	{
		// No overlay: /etc/qbix is already the base configuration tree
		// (Q_WebServer_Layout), and stacking it on itself would load its
		// sites twice.
		if (!class_exists('Q_WebServer_Framework', false)) {
			require_once __DIR__ . '/../Framework.php';
		}
		require_once __DIR__ . '/../Framework/Detector.php';
		require_once __DIR__ . '/Qbix/App.php';
		Q_WebServer_Framework::register(new Q_WebServer_Distribution_Qbix_App(), 50);
	}
}
