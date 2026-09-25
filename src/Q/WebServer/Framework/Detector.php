<?php
/**
 * @module Q
 */
/**
 * Recognises one kind of PHP application in a directory.
 *
 * Detectors are registered with Q_WebServer_Framework::register(). The panel's
 * Apps and Frameworks tabs and the autohost all ask the same registry, so an
 * application one of them recognises is recognised by all three.
 *
 * detect() must be cheap: a handful of is_file()/is_dir() calls and, at most,
 * reading a small version file. It must never include or run the
 * application's code.
 *
 * @class Q_WebServer_Framework_Detector
 */
interface Q_WebServer_Framework_Detector
{
	/**
	 * What this directory holds, or null when it is not this kind.
	 *
	 * The array may carry any of these keys; the registry fills in the rest:
	 *   kind      string   short identifier ('laravel', 'wordpress', ...)
	 *   name      string   display name ('Laravel')
	 *   version   string   release, as the application states it ('11.9.2')
	 *   state     string   'stable', 'beta', ... when the application says so
	 *   edition   string   an edition or distribution name
	 *   webRoot   string   the directory it serves from
	 *   details   array    label => value, shown on the card
	 *   links     array    list of array('label' => ..., 'url' => ...)
	 *   cli       array    argv prefix for commands, e.g. array(PHP, 'artisan')
	 *   commands  array    list of array('name', 'cmd', 'argv'?, 'disruptive'?)
	 *   composerWrite bool false: never run composer install/update/require here
	 *
	 * @method detect
	 * @param {string} $dir absolute directory, no trailing separator
	 * @return {array|null}
	 */
	function detect($dir);
}
