<?php
/**
 * The exponential preset configures what Exponential needs to run under the
 * server, in one setting, so nobody has to rediscover it.
 *
 * Two of its settings were found the hard way: the source-code transform must
 * stay on (the kernel's header()/setcookie() calls reach the response through
 * it), and the type registries must be kept across requests (cleared, they
 * empty for the worker's life and fail publishing). The preset carries both,
 * and this holds them in place.
 *
 *   php tests/unit-preset-exponential.php
 */
if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q.php';
require __DIR__ . '/../src/Q/WebServer/Compat.php';

$pass = 0; $fail = 0;
function check($what, $got, $want) {
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what,
		var_export($got, true), var_export($want, true));
}

$ok = Q_WebServer_Compat::loadPreset('exponential');
check('the preset loads', $ok, true);

check('the front controller is index.php',
	Q_Config::get('Q', 'compat', 'rewrite', null), 'index.php');
check('the source-code transform is left ON (skip = false)',
	Q_Config::get('Q', 'compat', 'skipSourceCodeTransform', 'unset'), false);
check('compat is enabled',
	Q_Config::get('Q', 'compat', 'enabled', null), true);

// The registries -- the trap. They must land under Q.webserver, not Q.compat.
$keep = Q_Config::get('Q', 'webserver', 'keepGlobals', null);
check('the kept globals reached Q.webserver as an array', is_array($keep), true);
check('...and include the datatype registry',
	in_array('eZDataTypes', (array) $keep, true), true);
check('...and the notification registry that publishing needs',
	in_array('eZNotificationEventTypes', (array) $keep, true), true);
check('the private _webserver key did not leak into Q.compat',
	Q_Config::get('Q', 'compat', '_webserver', 'absent'), 'absent');

// An unknown preset is refused, not silently ignored.
check('an unknown preset returns false',
	Q_WebServer_Compat::loadPreset('nonesuch'), false);

if ($fail) { printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail); exit(1); }
printf("  PASS - %d case(s)\n", $pass);
