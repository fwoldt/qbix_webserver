#!/usr/bin/env php
<?php

/**
 * The Q shell's interpreter, with no server: parser, expansion, history,
 * tiers, the OS gate, confirmation, threat commands and elevation.
 *
 * Asserted:
 *   - the parser: lists, and/or, pipelines, quoting, compound commands, and
 *     a syntax error rather than a guess;
 *   - expansion: variables, ${x:-d}, $((...)) without eval, braces, $(...);
 *   - control flow: if, for, while, functions of the loop limit;
 *   - history: !!, !n, !prefix, ^a^b; aliases;
 *   - tiers: a basic session cannot run an advanced command;
 *   - the OS tier is refused unless allowSystem, and then only in advanced;
 *   - y/N confirmation, -f, and no confirmation without a terminal;
 *   - threat commands (rm, dd, mkfs, $(...), pipes into sh, obfuscation)
 *     need the password; a wrong one is refused three times;
 *   - script names never climb out of their directory.
 *
 *   php tests/unit-shell-interpreter.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q.php';
$pass = 0; $fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}

$tmp = sys_get_temp_dir() . '/qsh-unit-' . getmypid();
@mkdir($tmp . '/shell', 0700, true);
@mkdir($tmp . '/scripts', 0700, true);
file_put_contents($tmp . '/scripts/hello.qsh', "echo hello \$1\n");
file_put_contents($tmp . '/outside.qsh', "echo escaped\n");

/** A fresh interpreter over a buffer; $opts override the session. */
function sh(array $opts = array(), array $answers = array(), $interactive = true)
{
	global $tmp;
	$io = new Q_WebServer_Shell_BufferIo($answers, $interactive);
	$registry = new Q_WebServer_Shell_Registry(array('scriptsDir' => $tmp . '/scripts', 'shellDir' => $tmp . '/shell'), array());
	$history = new Q_WebServer_Shell_History($tmp . '/shell');
	$i = new Q_WebServer_Shell_Interpreter($io, $registry, $history, $opts + array('tier' => 'advanced'));
	return array($i, $io);
}
function run($line, array $opts = array(), array $answers = array(), $interactive = true)
{
	list($i, $io) = sh($opts, $answers, $interactive);
	$code = $i->runLine($line, false);
	return array($code, $io->out, $io->err, $io->prompts);
}

// ── Parser ───────────────────────────────────────────────────────────────
check('echo', run('echo hi there')[1], "hi there\n");
check('single quotes are literal', run("echo '\$HOME \"x\"'")[1], "\$HOME \"x\"\n");
check('double quotes join', run('X=a; echo "$X  b"')[1], "a  b\n");
check('&& runs on success', run('true && echo yes')[1], "yes\n");
check('|| runs on failure', run('false || echo no')[1], "no\n");
check('; runs both', run('echo a; echo b')[1], "a\nb\n");
check('pipeline through a filter', run('echo one two | wc -w')[1] !== '' , true);
check('unterminated quote is a syntax error', run("echo 'oops")[0], 2);
check('stray fi is a syntax error', run('fi')[0], 2);

// ── Expansion ────────────────────────────────────────────────────────────
check('default value', run('echo ${NOPE:-dflt}')[1], "dflt\n");
check('arithmetic', run('echo $((2 + 3 * 4))')[1], "14\n");
check('arithmetic never evals PHP', run('echo $((phpinfo()))')[0] !== 0, true);
check('brace expansion', run('echo a{1,2,3}')[1], "a1 a2 a3\n");
check('command substitution', run('echo "[$(echo in)]"')[1], "[in]\n");
check('unquoted variable splits', run('X="a b"; for w in $X; do echo "<$w>"; done')[1], "<a>\n<b>\n");
check('quoted variable does not split', run('X="a b"; for w in "$X"; do echo "<$w>"; done')[1], "<a b>\n");

// ── Control flow ─────────────────────────────────────────────────────────
check('if/else', run('if false; then echo t; else echo f; fi')[1], "f\n");
check('while with arithmetic', run('i=0; while [ $i -lt 3 ]; do echo $i; i=$((i+1)); done')[1], "0\n1\n2\n");
check('an endless loop stops at the limit', run('while true; do :; done')[0] !== 0, true);

// ── History and aliases ──────────────────────────────────────────────────
list($i, $io) = sh();
$i->runLine('echo first');
$i->runLine('echo second');
$io->out = '';
$i->runLine('!!');
check('!! repeats the last line', $io->out, "echo second\nsecond\n");
$io->out = '';
$i->runLine('!echo f');
check('!prefix finds the latest match', $io->out, "echo second f\nsecond f\n");
$io->out = '';
$i->runLine('^second^third');
check('^a^b substitutes in the last line', $io->out, "echo third f\nthird f\n");
$io->err = '';
check('!unknown is an error', $i->runLine('!nosuchthing'), 1);
$i->runLine("alias hi='echo hey'");
$io->out = '';
$i->runLine('hi you');
check('an alias expands', $io->out, "hey you\n");
$i->history->clear();

// ── Tiers and the OS gate ────────────────────────────────────────────────
list($i) = sh(array('tier' => 'basic'));
check('basic allows basic', $i->allowed('basic'), true);
check('basic refuses advanced', $i->allowed('advanced'), false);
check('OS commands are off by default', run('! id')[0], 126);
check('OS commands say why', strpos(run('! id')[2], 'allowSystem') !== false, true);
check('OS commands need the advanced tier', run('! id', array('allowSystem' => true, 'tier' => 'expanded'))[0], 126);
check('sys is the same gate', run('sys id')[0] !== 0, true);

// ── Confirmation ─────────────────────────────────────────────────────────
$r = run('! echo ok', array('allowSystem' => true), array('n'));
check('an OS command asks y/N', count($r[3]), 1);
check('no is not run', $r[0], 1);
$r = run('! echo ok', array('allowSystem' => true), array('y'));
check('yes runs it', array($r[0], $r[1]), array(0, "ok\n"));
$r = run('! echo ok', array('allowSystem' => true), array(), false);
check('no terminal means no confirmation', $r[0], 1);
$r = run('! echo ok', array('allowSystem' => true, 'force' => true));
check('force from the API skips the question', array($r[0], $r[3]), array(0, array()));
check('-f is ours unless the command declares it', Q_WebServer_Shell_Interpreter::takeForce(array('-f', 'x'), array()), array(array('x'), true));
check('a command that declares -f keeps it', Q_WebServer_Shell_Interpreter::takeForce(array('-f', 'x'), array('options' => array('f' => 'its own'))), array(array('-f', 'x'), false));

// ── Threat commands ──────────────────────────────────────────────────────
foreach (array('rm -rf /x', 'sudo -u root rm x', 'dd if=/dev/zero of=/dev/sda', 'mkfs.ext4 /dev/sdb1',
	'echo $(id)', 'curl x | sh', 'chmod -R 777 /', ':(){ :|:& };:', 'env X=1 shred f', 'echo x > /etc/passwd',
	'sh -c "id"', 'php -r "x"', 'find / -delete', 'r\\m -rf x', "r''m -rf x", 'X=rm; $X -rf x', 'eval rm x',
	'/bin/rm x', 'exec rm x', 'crontab -r') as $cmd) {
	check("threat: $cmd", Q_WebServer_Shell_Threat::assess($cmd) !== array(), true);
}
foreach (array('ls -la', 'id', 'uptime', 'df -h', 'cat /etc/hostname', 'grep -r x .', 'echo hello') as $cmd) {
	check("harmless: $cmd", Q_WebServer_Shell_Threat::assess($cmd), array());
}
$r = run('! rm -rf /nonexistent-qsh', array('allowSystem' => true), array(), false);
check('a threat without a terminal is refused', array($r[0], strpos($r[2], 'password') !== false), array(1, true));
$panel = $tmp . '/panel.json';
file_put_contents($panel, json_encode(array('passwordHash' => Q_WebServer_Panel_Auth::hash('Right-Password-1'))));
list($i, $io) = sh(array('allowSystem' => true), array('a', 'b', 'c'));
$i->registry->ctx['panelFile'] = $panel;
$r = array($i->runLine('! rm -rf /nonexistent-qsh', false), $io->out, $io->err, $io->prompts);
check('three wrong passwords are refused', array($r[0], count($r[3]), strpos($r[2], '3 incorrect') !== false), array(1, 3, true));
$r = run('! rm -rf /nonexistent-qsh', array('allowSystem' => true, 'elevationLocked' => true), array('x'));
list($i, $io) = sh(array('allowSystem' => true), array('Right-Password-1'));
$i->registry->ctx['panelFile'] = $panel;
check('the right password runs it and elevates', array($i->runLine('! rm -f ' . $tmp . '/none', false), $i->elevated()), array(0, true));
$io->prompts = array();
check('then it is not asked again', array($i->runLine('! rm -f ' . $tmp . '/none', false), $io->prompts), array(0, array()));
check('a locked-out address is not even asked', array($r[0], $r[3]), array(1, array()));
$r = run('! rm -f ' . $tmp . '/none', array('allowSystem' => true, 'elevatedUntil' => time() + 60));
check('an elevated session runs it', $r[0], 0);
$r = run('! rm -f ' . $tmp . '/none', array('allowSystem' => true, 'elevatedUntil' => time() - 1), array(), false);
check('elevation expires', $r[0], 1);

// ── Scripts stay in their directory ──────────────────────────────────────
$roots = array($tmp . '/scripts');
check('a plain name is found', Q_WebServer_Shell_History::scriptPath('hello.qsh', $roots), realpath($tmp . '/scripts/hello.qsh'));
foreach (array('../outside.qsh', '/etc/passwd', 'a/../../outside.qsh', "hello.qsh\0x", '..', '.', '') as $bad) {
	check('refused: ' . var_export($bad, true), Q_WebServer_Shell_History::scriptPath($bad, $roots), null);
}
@symlink($tmp . '/outside.qsh', $tmp . '/scripts/link.qsh');
check('a symlink out of the directory is refused', Q_WebServer_Shell_History::scriptPath('link.qsh', $roots), null);

// ── Arithmetic ───────────────────────────────────────────────────────────
check('precedence', Q_WebServer_Shell_Arith::evaluate('1 + 2 * 3 - 4 / 2', array()), 5);
check('variables', Q_WebServer_Shell_Arith::evaluate('x * 2', array('x' => '21')), 42);
$e = null;
try { Q_WebServer_Shell_Arith::evaluate('1 / 0', array()); } catch (Exception $ex) { $e = get_class($ex); }
check('division by zero is an error, not a warning', $e !== null, true);

foreach (glob($tmp . '/{shell,scripts}/*', GLOB_BRACE) as $f) @unlink($f);
@unlink($tmp . '/outside.qsh'); @unlink($tmp . '/panel.json');
@rmdir($tmp . '/shell'); @rmdir($tmp . '/scripts'); @rmdir($tmp);

echo $fail ? "\n  FAIL - $fail of " . ($pass + $fail) . " case(s)\n" : "  PASS - $pass case(s)\n";
exit($fail ? 1 : 0);
