<?php
/**
 * @module Q
 */
/**
 * The shell's Exponential commands, for Velocity (vc).
 *
 * When the document root is an Exponential installation, the shell gains an
 * `exp` noun whose verbs are the installation's own tools -- the same ones the
 * panel's Apps tab offers (Q_WebServer_Distribution_Vc_Exponential):
 *
 *   exp info                 release, siteaccesses, package
 *   exp siteaccess:list      the siteaccesses, the default marked
 *   exp cache:list           cache tags
 *   exp cache:clear-all      and -template, -content, -ini (asks first)
 *   exp autoloads            regenerate the autoload arrays (asks first)
 *   exp cron:frequent        run cronjobs (asks first)
 *   exp console:list         the installation's console, when it has one
 *
 * Each runs as a process of its own in the document root, as the shell's
 * user, like every other command. Also a "velocity" theme.
 *
 * Registered by Q_WebServer_Distribution_Vc::register().
 *
 * @class Q_WebServer_Distribution_Vc_Shell
 */
class Q_WebServer_Distribution_Vc_Shell implements Q_WebServer_Shell_Provider
{
	function commands(array $ctx)
	{
		$root = (string) ($ctx['startOptions']['root'] ?? '');
		if ($root === '' || !is_dir($root)) return array();
		$root = rtrim(realpath($root) ?: $root, '/');
		$found = (new Q_WebServer_Distribution_Vc_Exponential())->detect($root);
		if (!$found) return array();

		$info = "{$found['edition']} {$found['version']}" . ($found['state'] !== '' ? " ({$found['state']})" : '') . "\n";
		foreach ((array) $found['details'] as $k => $v) $info .= str_pad($k, 20) . $v . "\n";
		$specs = array(self::text('info', 'basic', 'The installation: release, siteaccesses and package', $info));
		foreach ((array) $found['commands'] as $c) {
			$verb = (string) $c['cmd'];
			$disruptive = !empty($c['disruptive']);
			if (isset($c['output'])) {
				$specs[] = self::text($verb, 'basic', (string) $c['name'], (string) $c['output']);
				continue;
			}
			$argv = (array) $c['argv'];
			$specs[] = array(
				'name' => 'exp ' . $verb,
				// Reading is basic; clearing caches and running jobs change the site.
				'tier' => $disruptive ? 'expanded' : 'basic',
				'disruptive' => $disruptive,
				'description' => (string) $c['name'],
				'usage' => '',
				'cwd' => $root,
				// Fixed argv: arguments typed after the verb are not passed on,
				// so a verb cannot be turned into a different command.
				'argv' => function (array $args, array $ctx) use ($argv) { return $argv; },
			);
		}
		return $specs;
	}

	function themes()
	{
		return array('velocity' => array('label' => 'Velocity (violet on night)',
			'background' => '#0a0b14', 'foreground' => '#e1e4ed', 'dim' => '#6b7089', 'accent' => '#7c5cfc',
			'cursor' => '#a78bfa', 'selection' => 'rgba(124,92,252,.35)', 'prompt' => '#a78bfa', 'error' => '#f87171',
			'border' => 'rgba(167,139,250,.25)', 'opacity' => 0.94,
			'black' => '#161828', 'red' => '#f87171', 'green' => '#4ade80', 'yellow' => '#fbbf24',
			'blue' => '#7c8aff', 'magenta' => '#a78bfa', 'cyan' => '#22d3ee', 'white' => '#e1e4ed',
			'brightblack' => '#6b7089', 'brightred' => '#fca5a5', 'brightgreen' => '#86efac', 'brightyellow' => '#fde68a',
			'brightblue' => '#a5b4fc', 'brightmagenta' => '#c4b5fd', 'brightcyan' => '#67e8f9', 'brightwhite' => '#ffffff'));
	}

	/** A verb that only prints what is already known. */
	private static function text($verb, $tier, $description, $text)
	{
		return array('name' => 'exp ' . $verb, 'tier' => $tier, 'disruptive' => false,
			'description' => $description, 'usage' => '',
			'handler' => function ($shell, array $args, $stdin, $sink) use ($text) { $sink->write($text); return 0; });
	}
}
