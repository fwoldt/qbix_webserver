<?php
/**
 * The extension baseline, for the build and packaging scripts: the engine's
 * own Q_WebServer_Extensions over build/extensions.json -- the same source
 * `qbixctl ext:list` and `ext:check` read -- so no script keeps a list.
 *
 *   require __DIR__ . '/baseline.php';
 *   $r = baseline_resolve('standard', 'linux-x86_64', '8.3');   // include, exclude, spc, libs
 */
require_once dirname(__DIR__, 2) . '/src/Q/WebServer/Extensions.php';

/** What a variant carries on a platform and PHP version (see Q_WebServer_Extensions::resolve). */
function baseline_resolve($variant, $platform, $php, $static = true)
{
	return Q_WebServer_Extensions::resolve($variant, $platform, $php, array(), $static);
}

/** One extension's manifest entry, or null. */
function baseline_extension($name)
{
	$m = Q_WebServer_Extensions::manifest();
	return $m['extensions'][$name] ?? null;
}

/** The manifest's add-on drivers (loadable, never static). */
function baseline_addons()
{
	return Q_WebServer_Extensions::manifest()['addons'] ?? array();
}

/** The PHP versions and variants the manifest defines. */
function baseline_php_versions() { return Q_WebServer_Extensions::manifest()['php']['versions']; }
function baseline_variants() { return array_keys(Q_WebServer_Extensions::manifest()['variants']); }
function baseline_platforms() { return array_keys(Q_WebServer_Extensions::manifest()['platforms']); }
