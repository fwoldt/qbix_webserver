#!/usr/bin/env php
<?php

/**
 * The shell's Exponential commands (Q_WebServer_Distribution_Vc_Shell).
 *
 * Asserted:
 *   - registering vc adds the provider, and its "velocity" theme is listed;
 *   - no commands unless the document root is an Exponential installation;
 *   - exp info and exp siteaccess:list print what the detector found;
 *   - exp cache:list runs the installation's ezcache.php in the document
 *     root; arguments typed after the verb are not passed on;
 *   - clearing caches is expanded tier and asks first; a basic session is
 *     refused, a "no" is not run, -f runs it.
 *
 *   php tests/unit-distribution-vc-shell.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q.php';
require_once __DIR__ . '/../src/Q/WebServer/Layout.php';
require_once __DIR__ . '/../src/Q/WebServer/Distribution/Vc.php';
$pass = 0; $fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}

$base = sys_get_temp_dir() . '/qsh-vc-' . getmypid();
$site = $base . '/site';
$files = array(
	'lib/version.php' => "<?php\nclass eZPublishSDK\n{\n    const VERSION_MAJOR = 6;\n    const VERSION_MINOR = 0;\n    const VERSION_RELEASE = 15;\n    const VERSION_STATE = 'stable';\n    const VERSION_ALIAS = '6.0';\n    const EDITION = 'Exponential';\n}\n",
	'kernel/content/view.php' => '<?php',
	'settings/site.ini' => "[SiteSettings]\nDefaultAccess=site\n[SiteAccessSettings]\nAvailableSiteAccessList[]=site\nAvailableSiteAccessList[]=admin\n",
	'bin/php/ezcache.php' => "<?php echo 'ezcache ', implode(' ', array_slice(\$argv, 1)), ' in ', getcwd(), \"\\n\";",
);
foreach ($files as $rel => $body) {
	@mkdir(dirname("$site/$rel"), 0700, true);
	file_put_contents("$site/$rel", $body);
}
@mkdir($base . '/plain', 0700, true);

Q_WebServer_Distribution_Vc::register();
$names = array_map('get_class', Q_WebServer_Shell::providers());
check('registering vc adds the shell provider', in_array('Q_WebServer_Distribution_Vc_Shell', $names, true), true);
check('the velocity theme is listed', isset(Q_WebServer_Shell::themes()['velocity']), true);

$p = new Q_WebServer_Distribution_Vc_Shell();
check('no commands for a root that is not an installation', $p->commands(array('startOptions' => array('root' => $base . '/plain'))), array());
check('no commands without a root', $p->commands(array()), array());

function sh($tier, array $answers = array())
{
	global $site, $base;
	$io = new Q_WebServer_Shell_BufferIo($answers, true);
	$reg = new Q_WebServer_Shell_Registry(array('startOptions' => array('root' => $site)), array());
	$i = new Q_WebServer_Shell_Interpreter($io, $reg, new Q_WebServer_Shell_History($base . '/hist'), array('tier' => $tier));
	return array($i, $io);
}
$real = realpath($site);
list($i, $io) = sh('basic');
check('exp info', array($i->runLine('exp info', false), strpos($io->out, 'Exponential 6.0.15 (stable)') === 0), array(0, true));
$io->out = '';
$i->runLine('exp siteaccess:list', false);
check('exp siteaccess:list marks the default', $io->out, "site  (default)\nadmin\n");
$io->out = '';
check('exp cache:list runs in the document root', array($i->runLine('exp cache:list; --clear-all', false), strpos($io->out, "ezcache --list-tags --allow-root-user in $real\n")), array(127, 0));
$io->out = '';
$i->runLine('exp cache:list --clear-all', false);
check('arguments after the verb are not passed on', strpos($io->out, '--clear-all'), false);
$io->err = '';
check('a basic session cannot clear caches', $i->runLine('exp cache:clear-all', false), 126);
check('and says why', strpos($io->err, 'expanded') !== false, true);

list($i, $io) = sh('expanded', array('n'));
check('clearing asks, and no is not run', array($i->runLine('exp cache:clear-all', false), count($io->prompts), $io->out), array(1, 1, ''));
list($i, $io) = sh('expanded');
check('-f runs it', array($i->runLine('exp cache:clear-all -f', false), $io->out), array(0, "ezcache --clear-all --allow-root-user in $real\n"));

foreach (array_keys($files) as $rel) @unlink("$site/$rel");
foreach (array('bin/php', 'bin', 'kernel/content', 'kernel', 'lib', 'settings') as $d) @rmdir("$site/$d");
foreach ((array) glob($base . '/hist/*') as $f) @unlink($f);
@rmdir($base . '/hist'); @rmdir($site); @rmdir($base . '/plain'); @rmdir($base);

echo $fail ? "\n  FAIL - $fail of " . ($pass + $fail) . " case(s)\n" : "  PASS - $pass case(s)\n";
exit($fail ? 1 : 0);
