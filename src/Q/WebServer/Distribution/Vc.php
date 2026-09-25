<?php
/**
 * @module Q
 */
/**
 * Exponential Velocity (vc), 7x's distribution of the engine.
 *
 * The engine stays Qbix: its classes, its /etc/qbix, its QBIX_CONF_DIR. What
 * this distribution adds lives here, under its own class name, and is
 * switched on by the distribution option (--distribution=vc, QBIX_DISTRIBUTION,
 * or the DISTRIBUTION file of the source tree) -- so the engine code around it
 * is the upstream code, and upstream never needs this file.
 *
 * Configuration: Velocity keeps its own tree at /etc/vc (VC_CONF_DIR), laid
 * out exactly like /etc/qbix, and stacked on top of it as an overlay
 * (Q_WebServer_Layout::addOverlay()):
 *
 *   /etc/qbix   the engine's base tree   loaded first
 *   /etc/vc     Velocity's overlay       loaded second, so its values win
 *
 * Everything the engine expects under /etc/qbix still holds; an installation
 * changes only what it overrides. Page designs follow the same order,
 * overlay first (Q_WebServer_Design).
 *
 * @class Q_WebServer_Distribution_Vc
 * @static
 */
class Q_WebServer_Distribution_Vc
{
	/** The distribution's code identifier. */
	const NAME = 'vc';

	/** Its configuration tree, and the variable that moves it. */
	const ETC = '/etc/vc';
	const ENV = 'VC_CONF_DIR';

	/**
	 * Called once at start-up when this distribution is selected.
	 * @method register
	 * @static
	 */
	static function register()
	{
		Q_WebServer_Layout::addOverlay(self::ETC, self::ENV);
		// Exponential installations, any release, in the panel's Apps and
		// Frameworks tabs and the autohost: ahead of the generic detectors.
		if (!class_exists('Q_WebServer_Framework', false)) {
			require_once __DIR__ . '/../Framework.php';
		}
		require_once __DIR__ . '/../Framework/Detector.php';
		require_once __DIR__ . '/Vc/Exponential.php';
		Q_WebServer_Framework::register(new Q_WebServer_Distribution_Vc_Exponential(), 50);
		// Its tools as shell commands (exp info, exp cache:clear-all ...), and a theme.
		if (class_exists('Q_WebServer_Shell')) {
			require_once __DIR__ . '/Vc/Shell.php';
			Q_WebServer_Shell::addProvider(new Q_WebServer_Distribution_Vc_Shell());
		}
	}
}
