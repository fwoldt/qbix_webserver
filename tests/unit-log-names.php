<?php

/**
 * Where does a log line end up?
 *
 * Two things decide it: the accessName/errorName keys, which name the
 * server's own two files, and the `log` key on a virtual host, which gives
 * that host files of its own. Both are config a person writes by hand, so
 * the interesting cases are the ones where they write something the code
 * did not expect -- a name with a path in it, a host whose files collide
 * with the server's, a request with no Host header at all.
 *
 * Q_WebServer_Log is driven directly here, against a temporary directory.
 * The stubs below are the whole of what it needs from the rest of Q: a
 * config to read and a timer to register that never fires.
 *
 *   php tests/unit-log-names.php
 *
 * init() announces itself on STDERR once per case, so a passing run is
 * chatty. The runner only shows a test's output when it fails.
 */

$pass = 0;
$fail = 0;

function ok($cond, $what)
{
	global $pass, $fail;
	if ($cond) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n", $what);
}

function same($expected, $got, $what)
{
	global $pass, $fail;
	if ($expected === $got) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        expected %s\n        got      %s\n",
		$what, var_export($expected, true), var_export($got, true));
}

// ── Stubs ────────────────────────────────────────────────────────────────

class Q_Config
{
	static $data = array();

	static function get()
	{
		$args = func_get_args();
		$default = array_pop($args);
		$node = self::$data;
		foreach ($args as $key) {
			if (!is_array($node) or !array_key_exists($key, $node)) return $default;
			$node = $node[$key];
		}
		return $node;
	}

	static function set($config)
	{
		self::$data = array('Q' => array('webserver' => $config));
	}
}

class Q
{
	static function ifset($array, $key, $default = null)
	{
		return isset($array[$key]) ? $array[$key] : $default;
	}
}

class Q_Evented
{
	// The real one schedules the flush and rotation timers. Nothing in
	// this test runs an event loop, so registering is all that matters.
	static function repeat($interval, $callback) {}
}

require_once __DIR__ . '/../src/Q/WebServer/Log.php';

// ── Harness ──────────────────────────────────────────────────────────────

$tmp = sys_get_temp_dir() . '/qbix-log-names-' . getmypid();

function reset_logs($logConfig, $hosts = array())
{
	global $tmp;
	rrmdir($tmp);
	mkdir($tmp, 0755, true);
	Q_WebServer_Log::shutdown();
	Q_WebServer_Log::$hosts = array();
	Q_WebServer_Log::$enabled = false;
	Q_WebServer_Log::$accessFp = null;
	Q_WebServer_Log::$errorFp = null;
	Q_WebServer_Log::$accessBuf = '';
	$logConfig['dir'] = isset($logConfig['dir']) ? $logConfig['dir'] : $tmp;
	Q_Config::set(array('log' => $logConfig, 'hosts' => $hosts));
	Q_WebServer_Log::init();
}

function rrmdir($dir)
{
	if (!is_dir($dir)) return;
	foreach (scandir($dir) as $f) {
		if ($f === '.' or $f === '..') continue;
		$path = "$dir/$f";
		is_dir($path) ? rrmdir($path) : unlink($path);
	}
	rmdir($dir);
}

function hit($host, $uri = '/')
{
	Q_WebServer_Log::access('127.0.0.1', 'GET', $uri, 200, 12, '', 'curl', 1.5,
		array('headers' => $host === null ? array() : array('host' => $host)));
	Q_WebServer_Log::flush();
}

function files($dir = null)
{
	global $tmp;
	$dir = $dir ?: $tmp;
	$out = array();
	foreach (glob("$dir/*") as $f) {
		if (is_file($f)) $out[basename($f)] = filesize($f);
	}
	ksort($out);
	return $out;
}

echo "\n";

// ── The server's own two files ───────────────────────────────────────────

reset_logs(array('bufferSize' => 0));
hit(null);
ok(isset(files()['access.log']), 'default is still access.log');
ok(isset(files()['error.log']), 'default is still error.log');
ok(files()['access.log'] > 0, 'the line lands in it');

reset_logs(array('accessName' => 'web.log', 'errorName' => 'oops.log'));
hit(null);
$f = files();
ok(isset($f['web.log']) and isset($f['oops.log']), 'both files take their configured name');
ok(!isset($f['access.log']), 'and the default name is not created alongside');

// A name is a filename. Anything else is refused and the default stands,
// so a name out of config cannot write outside the log directory.
foreach (array('../escape.log', '/etc/passwd', 'a/b.log', '.', '..', '',
		'   ', "nul\0.log") as $bad) {
	reset_logs(array('accessName' => $bad));
	hit(null);
	same(true, isset(files()['access.log']),
		'refuses ' . var_export($bad, true) . ' and keeps access.log');
}

// ── A virtual host with its own files ────────────────────────────────────

reset_logs(array('bufferSize' => 0), array(
	'a.example.com' => array('root' => '/srv/a', 'log' => true),
	'b.example.com' => array('root' => '/srv/b', 'log' => array(
		'accessName' => 'b-web.log'
	)),
	'c.example.com' => array('root' => '/srv/c'),   // no log key
));

hit('a.example.com');
hit('b.example.com');
hit('c.example.com', '/from-c');
hit(null);

$f = files();
ok(isset($f['a.example.com-access.log']), 'log:true names the file after the host');
ok($f['a.example.com-access.log'] > 0, 'and a.example.com lands in it');
ok(isset($f['b-web.log']) and $f['b-web.log'] > 0, 'an explicit name is used');
ok(isset($f['b.example.com-error.log']),
	'the name not given still falls back to the host');
ok(!isset($f['c.example.com-access.log']),
	'a host without a log key gets no file');

$server = file_get_contents("$tmp/access.log");
same(2, substr_count($server, "\n"),
	'the server log takes the hostless request and the host without its own');
// Checked by URI, not by hostname: none of the named formats carries the
// Host header, so a shared log does not say which host a line came from.
ok(strpos($server, '/from-c') !== false,
	'and the request for the host without its own log is one of the two');

$a = file_get_contents("$tmp/a.example.com-access.log");
same(1, substr_count($a, "\n"), 'a host log takes only its own requests');

// The port in a Host header is not part of the host.
hit('a.example.com:8080');
same(2, substr_count(file_get_contents("$tmp/a.example.com-access.log"), "\n"),
	'a Host header with a port still finds the host');

// So is the case.
hit('A.Example.COM');
same(3, substr_count(file_get_contents("$tmp/a.example.com-access.log"), "\n"),
	'and so does one in capitals');

// ── A host that would write to the server's own log is refused ───────────

reset_logs(array('accessName' => 'shared.log'), array(
	'a.example.com' => array('log' => array('accessName' => 'shared.log')),
));
ok(!isset(Q_WebServer_Log::$hosts['a.example.com']),
	"a host is skipped when its file is the server's own");
hit('a.example.com');
same(1, substr_count(file_get_contents("$tmp/shared.log"), "\n"),
	'and its requests fall back to that one log, written once');

// ── A host with its own directory ────────────────────────────────────────

$other = sys_get_temp_dir() . '/qbix-log-names-other-' . getmypid();
rrmdir($other);
reset_logs(array(), array(
	'a.example.com' => array('log' => array('dir' => $other)),
));
hit('a.example.com');
ok(isset(files($other)['a.example.com-access.log']),
	'a host can log to a directory of its own');

// ── Rotation keeps the name, and the live files survive the sweep ────────

reset_logs(array('accessName' => 'web.log', 'maxSize' => 200, 'bufferSize' => 0),
	array('a.example.com' => array('log' => true)));
for ($i = 0; $i < 6; ++$i) { hit(null); hit('a.example.com'); }
Q_WebServer_Log::checkRotation();
$f = files();
$rotated = preg_grep('/^web\.\d{4}-\d{2}-\d{2}-\d{6}\.log$/', array_keys($f));
ok(count($rotated) === 1, "the server's log rotates under its own name");
$rotatedHost = preg_grep(
	'/^a\.example\.com-access\.\d{4}-\d{2}-\d{2}-\d{6}\.log$/', array_keys($f));
ok(count($rotatedHost) === 1, "and so does a host's");
ok(isset($f['web.log']) and $f['web.log'] === 0,
	'a fresh live file takes its place');
ok(isset($f['a.example.com-access.log']) and $f['a.example.com-access.log'] === 0,
	"and a fresh one takes the host's");

// archiveAndPrune() deletes old rotated logs. It must never take a file
// that is open and being written -- in the server's directory or a host's.
reset_logs(array('accessName' => 'web.log', 'deleteAfterDays' => 1,
	'archiveAfterDays' => 1), array('a.example.com' => array('log' => true)));
hit(null);
hit('a.example.com');
Q_WebServer_Log::flush();
$old = time() - (5 * 86400);
touch("$tmp/web.log", $old);
touch("$tmp/a.example.com-access.log", $old);
touch("$tmp/web.2000-01-01.log", $old);
file_put_contents("$tmp/web.2000-01-01.log", "old\n");
touch("$tmp/web.2000-01-01.log", $old);
Q_WebServer_Log::archiveAndPrune();
$f = files();
ok(isset($f['web.log']), 'the live server log survives the sweep');
ok(isset($f['a.example.com-access.log']), "and so does the live host log");
ok(!isset($f['web.2000-01-01.log']), 'while an old rotated one is taken');

// A shared log can still say which host a line came from, by asking for
// the header in a custom format.
reset_logs(array('format' => '%h "%r" %>s %{Host}i', 'bufferSize' => 0));
hit('c.example.com', '/from-c');
ok(strpos(file_get_contents("$tmp/access.log"), 'c.example.com') !== false,
	'%{Host}i puts the host in the line');

// ── Stats name the hosts ─────────────────────────────────────────────────

reset_logs(array(), array('a.example.com' => array('log' => true)));
hit('a.example.com');
$stats = Q_WebServer_Log::stats();
ok(isset($stats['hosts']['a.example.com']), 'stats carry the per-host files');
same('a.example.com-access.log', $stats['hosts']['a.example.com']['accessName'],
	'with the name the host writes to');

// ── Done ─────────────────────────────────────────────────────────────────

Q_WebServer_Log::shutdown();
rrmdir($tmp);
rrmdir($other);

echo "\n";
if ($fail === 0) { printf("  PASS - %d case(s)\n\n", $pass); exit(0); }
printf("  FAIL - %d of %d\n\n", $fail, $pass + $fail);
exit(1);
