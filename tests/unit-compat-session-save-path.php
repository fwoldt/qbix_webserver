<?php
/**
 * Sessions go where session.save_path says, in the form PHP's files handler
 * reads: "[N;[MODE;]]/path".
 *
 * The compatibility layer runs sessions itself and took the whole value for
 * a directory, so "0;0660;/path" (the Plesk way to make session files group
 * readable) named no directory at all. Given with -d, where ";" starts a
 * comment, the value arrived as "0" and every session went to the temporary
 * directory, created with the umask (0644) instead of PHP's 0600. A second
 * server sharing the sessions (Apache beside Velocity) could then neither
 * find them nor write them.
 *
 *   php tests/unit-compat-session-save-path.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);

class Q_Config
{
	static $data = array();

	static function get()
	{
		$args = func_get_args();
		$default = array_pop($args);
		$node = self::$data;
		foreach ($args as $k) {
			if (!is_array($node) or !array_key_exists($k, $node)) return $default;
			$node = $node[$k];
		}
		return $node;
	}

	static function set()
	{
		$args = func_get_args();
		$value = array_pop($args);
		$node = &self::$data;
		foreach ($args as $k) {
			if (!isset($node[$k]) or !is_array($node[$k])) $node[$k] = array();
			$node = &$node[$k];
		}
		$node = $value;
	}
}

require __DIR__ . '/../src/Q/WebServer/Compat.php';

$pass = 0;
$fail = 0;

function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what,
		var_export($got, true), var_export($want, true));
}


$C = 'Q_WebServer_Compat';
$tmp = rtrim(sys_get_temp_dir(), DS);

check('plain path', $C::parseSavePath('/var/sess'), array('/var/sess', 0, 0600));
check('trailing slash', $C::parseSavePath('/var/sess/'), array('/var/sess', 0, 0600));
check('depth', $C::parseSavePath('2;/var/sess'), array('/var/sess', 2, 0600));
check('depth and mode', $C::parseSavePath('0;0660;/var/sess'), array('/var/sess', 0, 0660));
check('three-digit mode', $C::parseSavePath('1;660;/var/sess'), array('/var/sess', 1, 0660));
check('empty: temporary directory', $C::parseSavePath(''), array($tmp, 0, 0600));
check('null: temporary directory', $C::parseSavePath(null), array($tmp, 0, 0600));
check('"0" (what -d leaves of "0;0660;/path"): temporary directory', $C::parseSavePath('0'), array($tmp, 0, 0600));

$dir = $tmp . DS . 'qbix-sesspath-' . getmypid();
@mkdir($dir, 0700);
$old = umask(022);

// A mode given in the path, and a subdirectory level named after the id.
$id = 'abcdefabcdefabcdefabcdef01';
@mkdir($dir . DS . 'a', 0700);
Q_Config::set('Q', 'compat', 'ini', 'session.save_path', '1;0660;' . $dir);
$_COOKIE['PHPSESSID'] = $id;
$C::_session_start();
$_SESSION['x'] = 1;
$C::_session_write_close();
$file = $dir . DS . 'a' . DS . 'sess_' . $id;
clearstatcache();
check('file in the id subdirectory', is_file($file), true);
check('created with the given mode', is_file($file) ? fileperms($file) & 0777 : null, 0660);
check('data written', is_file($file) ? file_get_contents($file) : null, 'x|i:1;');
@unlink($file);
@rmdir($dir . DS . 'a');

// No mode: PHP's 0600, whatever the umask. (A new request: the id of the
// last session is forgotten at the request boundary.)
$p = new ReflectionProperty($C, 'sessionId');
$p->setAccessible(true);
$p->setValue(null, '');
$id2 = 'bcdefabcdefabcdefabcdef012';
Q_Config::set('Q', 'compat', 'ini', 'session.save_path', $dir);
$_COOKIE['PHPSESSID'] = $id2;
$C::_session_start();
$_SESSION['y'] = 2;
$C::_session_write_close();
$file2 = $dir . DS . 'sess_' . $id2;
clearstatcache();
check('plain path: file there', is_file($file2), true);
check('plain path: 0600', is_file($file2) ? fileperms($file2) & 0777 : null, 0600);
@unlink($file2);

umask($old);
@rmdir($dir);
echo "\n";
if ($fail === 0) { printf("  PASS - %d case(s)\n\n", $pass); exit(0); }
printf("  FAIL - %d of %d\n\n", $fail, $pass + $fail);
exit(1);
