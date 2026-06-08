<?php
/**
 * Verifies OIDC id_tokens against a provider JWKS.
 *
 * @package Autorizenter\Core
 */

namespace Autorizenter\Core;

use Firebase\JWT\JWT;
use Firebase\JWT\JWK;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around firebase/php-jwt with JWKS caching.
 *
 * Verifying signatures requires the firebase/php-jwt library (composer install).
 * If it is unavailable, verification fails closed (returns WP_Error) rather than
 * trusting an unverified token.
 */
class JWT_Verifier {

	/**
	 * Verify and decode an id_token.
	 *
	 * @param string $id_token    Compact JWS.
	 * @param string $jwks_uri    JWKS endpoint.
	 * @param string $issuer      Expected issuer.
	 * @param string $audience    Expected audience (client id).
	 * @param string $nonce       Expected nonce (optional).
	 * @return array|\WP_Error Decoded claims as an associative array.
	 */
	public function verify( $id_token, $jwks_uri, $issuer, $audience, $nonce = '' ) {
		if ( ! class_exists( JWT::class ) || ! class_exists( JWK::class ) ) {
			return new \WP_Error(
				'autorizenter_jwt_missing',
				__( 'The firebase/php-jwt library is required for OIDC verification. Run composer install.', 'autorizenter' ),
				array( 'status' => 500 )
			);
		}

		$jwks = $this->fetch_jwks( $jwks_uri );
		if ( is_wp_error( $jwks ) ) {
			return $jwks;
		}

		try {
			$keys        = JWK::parseKeySet( $jwks );
			JWT::$leeway = 60;
			$decoded     = (array) JWT::decode( $id_token, $keys );
		} catch ( \Exception $e ) {
			return new \WP_Error( 'autorizenter_jwt_invalid', __( 'Invalid ID token signature.', 'autorizenter' ), array( 'status' => 401 ) );
		}

		if ( '' !== $issuer && ( ! isset( $decoded['iss'] ) || $decoded['iss'] !== $issuer ) ) {
			return new \WP_Error( 'autorizenter_jwt_iss', __( 'ID token issuer mismatch.', 'autorizenter' ), array( 'status' => 401 ) );
		}

		$aud = isset( $decoded['aud'] ) ? (array) $decoded['aud'] : array();
		if ( '' !== $audience && ! in_array( $audience, $aud, true ) ) {
			return new \WP_Error( 'autorizenter_jwt_aud', __( 'ID token audience mismatch.', 'autorizenter' ), array( 'status' => 401 ) );
		}

		if ( '' !== $nonce && ( ! isset( $decoded['nonce'] ) || ! hash_equals( $nonce, (string) $decoded['nonce'] ) ) ) {
			return new \WP_Error( 'autorizenter_jwt_nonce', __( 'ID token nonce mismatch.', 'autorizenter' ), array( 'status' => 401 ) );
		}

		return $decoded;
	}

	/**
	 * Fetch and cache a JWKS document.
	 *
	 * @param string $jwks_uri JWKS endpoint.
	 * @return array|\WP_Error
	 */
	private function fetch_jwks( $jwks_uri ) {
		$cache_key = 'autorizenter_jwks_' . md5( $jwks_uri );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get( $jwks_uri, array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['keys'] ) ) {
			return new \WP_Error( 'autorizenter_jwks_error', __( 'Could not fetch provider signing keys.', 'autorizenter' ), array( 'status' => 502 ) );
		}

		set_transient( $cache_key, $data, HOUR_IN_SECONDS );
		return $data;
	}
}
