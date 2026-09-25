<?php
/**
 * @module Q
 */
/**
 * Recognises a Qbix app and adds a panel command to show its configuration.
 *
 * Registered by Q_WebServer_Distribution_Qbix.
 *
 * @class Q_WebServer_Distribution_Qbix_App
 */
class Q_WebServer_Distribution_Qbix_App implements Q_WebServer_Framework_Detector
{
	function detect($dir)
	{
		if (!is_file("$dir/config/app.json") and !is_file("$dir/web/Q.php")) return null;

		$app = json_decode((string) @file_get_contents("$dir/config/app.json"), true);
		if (!is_array($app)) $app = array();
		$local = is_file("$dir/local/app.json") ? json_decode((string) @file_get_contents("$dir/local/app.json"), true) : null;
		$q = $app['Q'] ?? array();
		if (is_array($local) and isset($local['Q']) and is_array($local['Q'])) {
			$q = array_merge($q, $local['Q']);
		}

		$appName = (isset($q['app']) && is_string($q['app']) && $q['app'] !== '') ? $q['app'] : basename($dir);
		$details = array('App' => $appName);
		if (isset($q['web']['appRootUrl']) and is_string($q['web']['appRootUrl']) and $q['web']['appRootUrl'] !== '') {
			$details['URL'] = $q['web']['appRootUrl'];
		}

		$composer = is_file("$dir/composer.json") ? json_decode((string) @file_get_contents("$dir/composer.json"), true) : null;
		if (is_array($composer) and !empty($composer['name'])) {
			$details['Package'] = $composer['name'] . (!empty($composer['version']) ? ' ' . $composer['version'] : '');
		}

		$php = Q_WebServer_Framework::php();
		$info = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'qbix-appinfo.php';
		$commands = array();
		if (is_file($info)) {
			$commands[] = array('name' => 'Site installation info', 'cmd' => 'appinfo',
				'argv' => array($php, $info));
		}

		return array(
			'kind' => 'qbix',
			'name' => 'Qbix app',
			'details' => $details,
			'webRoot' => is_dir("$dir/web") ? "$dir/web" : $dir,
			'commands' => $commands,
		);
	}
}
