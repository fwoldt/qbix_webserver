<?php
/**
 * @module Q
 */

/**
 * An RSA 2048 key signed with SHA-256 through the openssl extension: for a
 * system whose openssl has no usable elliptic curves.
 *
 * @class Q_WebServer_Certificate_Provider_OpensslRsa
 */
class Q_WebServer_Certificate_Provider_OpensslRsa extends Q_WebServer_Certificate_Provider_Openssl
{
	function name()
	{
		return 'openssl-rsa';
	}

	protected function keyOptions()
	{
		return array('private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048);
	}
}
