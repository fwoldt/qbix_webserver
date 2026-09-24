<?php

/**
 * The dashboard's "Live requests" panel: newest first, still, and honest
 * about memory.
 *
 * Two things were wrong with it.
 *
 * The Mem column never showed a value. The pooled path -- where every PHP
 * request goes -- recorded 0 for memory, because the figure lived in the
 * worker and nothing carried it out; and the in-process path reported the
 * parent's own heap moving while it served a static file or a cache hit,
 * which is not memory any script used. Now the worker resets its heap peak
 * before running the script, sends memory_get_peak_usage() back with the
 * response, and the panel shows it for PHP rows in B/KB/MB. Rows where no PHP
 * ran show a dash, never 0.
 *
 * The list ran oldest to newest downwards inside a column-reverse box, which
 * anchored the scroll at the bottom, and the initial list came out in the
 * opposite order to the live one. Now the newest row is always on top, both
 * on first paint and live, the panel never scrolls by itself, and a reader
 * who has scrolled down keeps their place when rows arrive above them.
 *
 * The panel's functions are run under node (when available) against a small
 * fake list and scroll box, so the behaviour is tested rather than grepped.
 *
 *   php tests/unit-dashboard-live-requests.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);

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

$src = __DIR__ . '/../src/Q/WebServer/Dashboard.php';
$dash = file_get_contents($src);
$pool = file_get_contents(__DIR__ . '/../src/Q/WebServer/Pool.php');
$web = file_get_contents(__DIR__ . '/../src/Q/WebServer.php');

// ── Source: the scroll box and the memory path ───────────────────

check('the log box no longer reverses its column (that anchored it at the bottom)',
	(bool) preg_match('/\.log-wrap\{[^}]*column-reverse/', $dash), false);
check('the log box still scrolls, same height',
	(bool) preg_match('/\.log-wrap\{max-height:50vh;overflow-y:auto/', $dash), true);
check('the browser\'s own scroll anchoring is off, so the script\'s adjustment is not doubled',
	(bool) preg_match('/\.log-wrap\{[^}]*overflow-anchor:none/', $dash), true);
check('nothing scrolls the log to its bottom',
	(bool) preg_match('/scrollTop\s*=\s*[^;]*scrollHeight|scrollIntoView|scrollTo\(/', $dash), false);
check('the columns are unchanged, Mem included',
	strpos($dash, '<span class="ld">ms</span><span class="lmem">Mem</span>') !== false, true);
check('the first paint goes through renderRecent()', strpos($dash, 'renderRecent(R);') !== false, true);
check('live rows go through insertRow()', strpos($dash, 'insertRow(L,LW,d,MAX_LOG);') !== false, true);
check('the cap is kept', strpos($dash, 'MAX_LOG=300') !== false, true);

check('the worker resets its heap peak before running the script',
	(bool) preg_match('/memory_reset_peak_usage\(\);\s*\$resp = self::executeScript\(\$req\);/', $pool), true);
check('the worker sends its heap peak with the response',
	strpos($pool, "\$canPeak ? memory_get_peak_usage() : 0);") !== false, true);
check('the parent takes the figure off the response before the client or cache sees it',
	strpos($pool, "unset(\$response['_mem']);") !== false, true);
check('the parent passes it to recordCompleted()', strpos($pool, "true, \$mem\n") !== false, true);
check('recordCompleted() passes it to the dashboard',
	strpos($web, "\$isPhp, '', (int) \$memUsed, \$parsed['cookies'] ?? array()") !== false, true);
check('the parent no longer reports its own heap delta as a request\'s memory',
	strpos($web, 'memory_get_usage() - $memBefore'), false);

// ── The worker's message carries _mem, and only when it has one ─

if (!function_exists('stream_socket_pair')) {
	printf("  skip  no stream_socket_pair; worker message checked by source only\n");
} else {
	require_once __DIR__ . '/../src/Q/WebServer/Pool.php';
	$m = new ReflectionMethod('Q_WebServer_Pool', 'writeMsg');
	$m->setAccessible(true);
	$read = function ($mem) use ($m) {
		$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
		$m->invoke(null, $pair[0], 200, 'hello', array('X' => 'y'), array(), '', $mem);
		fclose($pair[0]);
		$raw = stream_get_contents($pair[1]);
		fclose($pair[1]);
		$len = unpack('N', substr($raw, 0, 4))[1];
		return json_decode(substr($raw, 4, $len), true);
	};
	$with = $read(2621440);
	check('a measured peak travels as _mem', $with['_mem'] ?? null, 2621440);
	check('...beside the body, unchanged', $with['body'] ?? null, 'hello');
	$without = $read(0);
	check('no measurement, no _mem: the message keeps its old shape',
		array_keys($without), array('status', 'body', 'headers', 'cookies'));
}

// ── The script's functions, run under node ──────────────────────

function jsFunction($dash, $name)
{
	if (!preg_match('/^function ' . $name . '\(/m', $dash, $m, PREG_OFFSET_CAPTURE)) return null;
	$start = $m[0][1];
	$open = strpos($dash, '{', $start);
	for ($i = $open, $depth = 0, $n = strlen($dash); $i < $n; ++$i) {
		if ($dash[$i] === '{') ++$depth;
		elseif ($dash[$i] === '}' and --$depth === 0) return substr($dash, $start, $i - $start + 1);
	}
	return null;
}

function findNode()
{
	foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
		if ($dir !== '' and is_executable($dir . '/node')) return $dir . '/node';
	}
	return null;
}

$node = findNode();
if (!$node or !function_exists('proc_open')) {
	printf("  skip  node not found; panel behaviour checked by source only\n");
} else {
	$names = array('esc', 'fmtMem', 'rowMem', 'insertRow', 'shouldShow', 'A', 'mkRow', 'renderRecent');
	$js = '';
	foreach ($names as $n) {
		$f = jsFunction($dash, $n);
		check("the script defines $n()", $f !== null, true);
		$js .= $f . "\n";
	}
	$js = <<<'JS'
// A list and a scroll box just big enough for the panel's functions.
function El(){this.children=[];this.style={};this.attrs={};this.innerHTML='';this.offsetHeight=18}
El.prototype.setAttribute=function(k,v){this.attrs[k]=String(v)};
El.prototype.getAttribute=function(k){return this.attrs[k]};
Object.defineProperty(El.prototype,'firstChild',{get:function(){return this.children[0]||null}});
Object.defineProperty(El.prototype,'lastChild',{get:function(){return this.children[this.children.length-1]||null}});
El.prototype.insertBefore=function(n,ref){var i=ref?this.children.indexOf(ref):this.children.length;this.children.splice(i<0?this.children.length:i,0,n);return n};
El.prototype.removeChild=function(n){var i=this.children.indexOf(n);if(i>=0)this.children.splice(i,1);return n};
var document={createElement:function(){return new El()}};
var BASE='https://example.test',K={},paused=false,MAX_LOG=5,sidFilter='',scFilter='',
    knownSids={},knownCodes={},L=new El(),LW={scrollTop:0,scrollHeight:9999};
function addSidOption(){}function addScOption(){}
function uris(){return L.children.map(function(d){return (d.innerHTML.match(/>(\/[^<]*)</)||[])[1]})}
function entry(n,extra){var e={time:'12:00:0'+n,method:'GET',uri:'/r'+n,status:200,ms:1.5,kind:'php',mem:0,sid:''};
  for(var k in extra)e[k]=extra[k];return e}
var out={};

JS . $js . <<<'JS'

// First paint: R arrives newest-first, exactly as the server embeds it.
var R=[entry(3),entry(2),entry(1)];
renderRecent(R);
out.initial=uris();

// A live entry goes on top.
A(entry(4));
out.afterLive=uris();

// The cap drops the oldest from the bottom.
A(entry(5));A(entry(6));A(entry(7));
out.capped=uris();

// At the top, nothing scrolls.
LW.scrollTop=0;A(entry(8));
out.topScroll=LW.scrollTop;

// Scrolled down, the reader keeps their place: shifted by the new row's height.
LW.scrollTop=100;
var row=document.createElement('div');row.offsetHeight=23;
insertRow(L,LW,row,MAX_LOG);
out.keptScroll=LW.scrollTop;
out.rowOnTop=L.firstChild===row;
out.stillCapped=L.children.length;

// A hidden row (filtered out) has no height and moves nothing.
LW.scrollTop=40;
var hidden=document.createElement('div');hidden.offsetHeight=0;
insertRow(L,LW,hidden,MAX_LOG);
out.hiddenScroll=LW.scrollTop;

// Paused: nothing is added, nothing moves.
paused=true;var before=uris().join(',');LW.scrollTop=7;A(entry(9));
out.pausedSame=uris().join(',')===before;out.pausedScroll=LW.scrollTop;paused=false;

// Memory cells.
function memCell(e){return (mkRow(e).match(/<span class="lmem">([^<]*)<\/span>/)||[])[1]}
out.memPhp=memCell(entry(1,{kind:'php',mem:2621440}));
out.memPhpKb=memCell(entry(1,{kind:'php',mem:524288}));
out.memPhpNone=memCell(entry(1,{kind:'php',mem:0}));
out.memStatic=memCell(entry(1,{kind:'css',mem:4096}));
out.memCacheHit=memCell(entry(1,{kind:'html',mem:0}));
out.memMissing=memCell({time:'x',method:'GET',uri:'/',status:200,ms:1,kind:'js'});
process.stdout.write(JSON.stringify(out));
JS;

	$proc = proc_open(array($node), array(0 => array('pipe', 'r'),
		1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	fwrite($pipes[0], $js); fclose($pipes[0]);
	$out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
	fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
	$got = json_decode($out, true);
	check('node ran the panel functions', is_array($got), true);
	if (!is_array($got)) {
		printf("        %s\n", trim($err));
	} else {
		check('the initial list is rendered newest-first', $got['initial'], array('/r3', '/r2', '/r1'));
		check('a new entry is inserted at the top', $got['afterLive'], array('/r4', '/r3', '/r2', '/r1'));
		check('the cap drops the oldest, from the bottom', $got['capped'], array('/r7', '/r6', '/r5', '/r4', '/r3'));
		check('at the top, inserting does not scroll', $got['topScroll'], 0);
		check('scrolled down, the position is kept by the inserted height', $got['keptScroll'], 123);
		check('...the new row is on top', $got['rowOnTop'], true);
		check('...and the cap still holds', $got['stillCapped'], 5);
		check('a hidden row moves nothing', $got['hiddenScroll'], 40);
		check('paused: no row added', $got['pausedSame'], true);
		check('paused: no scroll change', $got['pausedScroll'], 7);
		check('a PHP request shows its memory in MB', $got['memPhp'], '2.5 MB');
		check('a PHP request shows its memory in KB', $got['memPhpKb'], '512.0 KB');
		check('a PHP request with no measurement shows a dash, not 0', $got['memPhpNone'], "\u{2014}");
		check('a static file shows a dash', $got['memStatic'], "\u{2014}");
		check('a cache hit shows a dash', $got['memCacheHit'], "\u{2014}");
		check('an entry without mem shows a dash', $got['memMissing'], "\u{2014}");
	}
}

if ($fail) {
	printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail);
	exit(1);
}
printf("  PASS - %d case(s)\n", $pass);
