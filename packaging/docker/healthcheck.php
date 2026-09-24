#!/usr/bin/env php
<?php
/**
 * The container's health check: /Q/health on the server's own port answers
 * 200 with "status":"ok". /Q/health is always open to the machine itself.
 * QBIX_HEALTH_URL overrides the address (a different --port, say).
 */
$url = getenv('QBIX_HEALTH_URL') ?: 'http://127.0.0.1:8080/Q/health';
$ctx = stream_context_create(array('http' => array('timeout' => 4, 'ignore_errors' => true)));
$body = @file_get_contents($url, false, $ctx);
$status = 0;
foreach ($http_response_header ?? array() as $h) {
	if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $status = (int) $m[1];
}
if ($status === 200 && is_string($body) && strpos($body, '"status":"ok"') !== false) exit(0);
fwrite(STDERR, "unhealthy: $url answered $status\n");
exit(1);
