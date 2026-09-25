#!/usr/bin/env php
<?php
/**
 * /Q/phpinfo in the server's chrome: the toolbar with PHP Info as the current
 * view, the brand linked to the view, phpinfo's sections whole, a jump list,
 * and values that stay escaped whether phpinfo() produced text or HTML.
 *
 *   php tests/unit-phpinfo-view.php
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

// The real thing, as this SAPI prints it.
ob_start(); phpinfo(); $raw = ob_get_clean();
$page = Q_WebServer_PhpInfo::page($raw);
check('a complete page', strpos($page, '<!DOCTYPE html>') === 0, true);
check('the view toolbar is there', strpos($page, '<div class="qnav" role="navigation" aria-label="Server views">') !== false, true);
check('...with PHP Info as the current view', strpos($page, '<a href="/Q/phpinfo" aria-current="page">PHP Info</a>') !== false, true);
check('...and no other view marked current', substr_count($page, 'aria-current="page"'), 1);
check('the brand links to the view itself', (bool) preg_match('#<h1><img[^>]*><a href="/Q/phpinfo"[^>]*>[^<]+</a></h1>#', $page), true);
check('the status badge', strpos($page, '<div class="status"><span class="pulse"></span> Running</div>') !== false, true);
check('the filter box', strpos($page, 'id="pi-filter"') !== false, true);
check('phpinfo\'s own stylesheet is gone', stripos($page, 'background-color: #ccf') === false, true);
check('...and so are its document wrappers', substr_count(strtolower($page), '<body'), 1);
check('the Configuration section is kept', strpos($page, '>Configuration</h1>') !== false, true);
check('the version line', (bool) preg_match('/<p class="sub">PHP \d+\.\d+/', $page), true);
check('every table is wrapped to scroll in its card', substr_count($page, '<div class="tw"><table'), substr_count($page, '<table'));
preg_match_all('/<nav class="jump"[^>]*>(.*?)<\/nav>/s', $page, $m);
$links = preg_match_all('/<a href="#([^"]+)"/', $m[1][0] ?? '', $ids);
check('the jump list has sections', $links > 5, true);
$missing = array();
foreach ($ids[1] as $id) if (strpos($page, ' id="' . $id . '"') === false) $missing[] = $id;
check('every jump link has its target', $missing, array());
check('ids are unique', count($ids[1]), count(array_unique($ids[1])));
foreach (array('Core', 'date', 'standard') as $mod) {
	check("the $mod module's section is kept", strpos($page, '>' . $mod . '</h2>') !== false, true);
}

// A value a visitor controls (a request header shown in phpinfo) never
// becomes markup: the text form is escaped by the parser...
$evil = '</table><script>alert(1)</script><img src=x onerror=alert(2)>';
$text = "phpinfo()\nPHP Version => 8.3.0\n\nSystem => Linux\n\nPHP Variables\n\nVariable => Value\n\$_SERVER['HTTP_X_EVIL'] => $evil\n";
$p = Q_WebServer_PhpInfo::page($text);
check('text form: no script from the value', strpos($p, '<script>alert(1)') === false, true);
check('text form: no img from the value', stripos($p, '<img src=x') === false, true);
check('text form: the value is shown escaped', strpos($p, '&lt;/table&gt;&lt;script&gt;alert(1)&lt;/script&gt;') !== false, true);
// ...and the HTML form arrives escaped by PHP and is not decoded here.
$html = '<!DOCTYPE html><html><head><style>td{}</style><title>x</title></head><body><div class="center">'
	. '<table><tr class="h"><td><h1 class="p">PHP Version 8.3.0</h1></td></tr></table>'
	. '<h2>PHP Variables</h2><table><tr><td class="e">$_SERVER[\'HTTP_X_EVIL\']</td><td class="v">'
	. htmlspecialchars($evil, ENT_QUOTES) . '</td></tr></table></div></body></html>';
$p = Q_WebServer_PhpInfo::page($html);
check('HTML form: no script from the value', strpos($p, '<script>alert(1)') === false, true);
check('HTML form: the escaped value stays escaped', strpos($p, htmlspecialchars($evil, ENT_QUOTES)) !== false, true);
check('HTML form: its <style> is dropped', strpos($p, 'td{}') === false, true);
// A crafted heading cannot inject an attribute through the jump list.
$hx = "phpinfo()\nPHP Version => 8.3.0\n\n\"><script>x</script>\n\nDirective => Local Value => Master Value\nfoo => 1 => 1\n";
$p = Q_WebServer_PhpInfo::page($hx);
check('a crafted module name makes no script', strpos($p, '<script>x</script>') === false, true);

printf("  %s - %d case(s)\n", $fail ? "FAIL - $fail of " . ($pass + $fail) : 'PASS', $pass + $fail);
exit($fail ? 1 : 0);
