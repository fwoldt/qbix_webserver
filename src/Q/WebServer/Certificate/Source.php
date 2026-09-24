<?php
/**
 * @module Q
 */

/**
 * One way the HTTPS certificate is obtained, chosen by Q.web.https.mode:
 *
 *   files (or manual)  certificate and key files you provide
 *   archive            the same, packed: zip, tar, tar.gz, tar.bz2, tar.xz, rar, 7z
 *   pkcs12             a .p12 / .pfx bundle
 *   acme (letsencrypt) issued and renewed by an ACME CA, built in
 *   certbot            issued and renewed by an installed certbot
 *   remote             downloaded from a URL
 *   self-signed        made here (Q_WebServer_Certificate_SelfSigned)
 *
 * Whatever the source, Q_WebServer_Certs checks the pair, hands the listener
 * a private copy, watches for changes, swaps without a restart, and stands a
 * self-signed certificate in while the source has nothing usable.
 *
 * @class Q_WebServer_Certificate_Source
 */
interface Q_WebServer_Certificate_Source
{
	/** @return {string} the mode name */
	function name();

	/**
	 * Where the pair to serve is, after doing what this source does to put
	 * it there (importing, extracting, downloading). The pair may not be
	 * usable -- an ACME certificate still being issued -- which the caller
	 * checks; null only when there is not even a place for it.
	 * @param {array} $config Q.web.https
	 * @param {string} $why
	 * @return {array|null} array(cert file, key file)
	 */
	function pair(array $config, &$why = '');

	/**
	 * Files whose change means pair() should run again: the files you
	 * provide, the archive, the bundle.
	 * @param {array} $config
	 * @return {array}
	 */
	function watched(array $config);

	/**
	 * Called every watch interval, cheaply. A source that renews (acme,
	 * certbot, remote) starts that here, in the background, when it is due.
	 * @param {array} $config
	 */
	function tick(array $config);
}
