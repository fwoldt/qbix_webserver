<?php
/**
 * @module Q
 */

/**
 * An ECDSA P-256 key through the openssl extension: the first choice. Small,
 * fast to make and to handshake with, and accepted by strict crypto policies.
 *
 * @class Q_WebServer_Certificate_Provider_OpensslEcdsa
 */
class Q_WebServer_Certificate_Provider_OpensslEcdsa extends Q_WebServer_Certificate_Provider_Openssl
{
	function name()
	{
		return 'openssl-ecdsa';
	}

	function available(&$why = '')
	{
		if (!parent::available($why)) return false;
		if (!defined('OPENSSL_KEYTYPE_EC') or !in_array('prime256v1', (array) @openssl_get_curve_names(), true)) {
			$why = 'this openssl has no P-256 curve';
			return false;
		}
		return true;
	}

	protected function keyOptions()
	{
		return array('private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1');
	}
}
