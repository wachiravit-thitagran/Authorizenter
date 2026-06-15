<?php
/**
 * Generic OAuth2 provider adapter.
 *
 * @package Authorizenter\Core
 */

namespace Authorizenter\Core\Providers;

use Authorizenter\Core\Provider_Base;
use Authorizenter\Core\Identity;

defined( 'ABSPATH' ) || exit;

/**
 * Generic OAuth2 provider for services that do not support OpenID Connect.
 */
class OAuth2 extends Provider_Base {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return isset( $this->config['id'] ) ? (string) $this->config['id'] : 'oauth2';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return isset( $this->config['label'] ) && '' !== $this->config['label']
			? (string) $this->config['label']
			: __( 'SSO', 'authorizenter' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function authorization_endpoint() {
		return isset( $this->config['authorization_endpoint'] ) ? (string) $this->config['authorization_endpoint'] : '';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function token_endpoint() {
		return isset( $this->config['token_endpoint'] ) ? (string) $this->config['token_endpoint'] : '';
	}

	/**
	 * User info endpoint.
	 *
	 * @return string
	 */
	protected function userinfo_endpoint() {
		return isset( $this->config['userinfo_endpoint'] ) ? (string) $this->config['userinfo_endpoint'] : '';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function scopes() {
		return isset( $this->config['scopes'] ) ? (string) $this->config['scopes'] : '';
	}

	/**
	 * Exchange an authorization code for a normalized Identity.
	 *
	 * @param string $code          Authorization code.
	 * @param string $redirect_uri  Redirect URI used in the request.
	 * @param string $code_verifier PKCE verifier.
	 * @param string $nonce         Expected nonce (unused in plain OAuth2).
	 * @return Identity|\WP_Error
	 */
	public function exchange( $code, $redirect_uri, $code_verifier, $nonce ) {
		$token_endpoint = $this->token_endpoint();
		if ( empty( $token_endpoint ) ) {
			return new \WP_Error( 'authorizenter_oauth2_no_token_url', __( 'Token endpoint is not configured.', 'authorizenter' ), array( 'status' => 500 ) );
		}

		$token = $this->request_token(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'redirect_uri'  => $redirect_uri,
				'client_id'     => $this->client_id(),
				'client_secret' => $this->client_secret(),
				'code_verifier' => $code_verifier,
			)
		);

		if ( is_wp_error( $token ) ) {
			return $token;
		}
		if ( empty( $token['access_token'] ) ) {
			return new \WP_Error( 'authorizenter_oauth2_no_token', __( 'Provider returned no access token.', 'authorizenter' ), array( 'status' => 502 ) );
		}

		$userinfo_endpoint = $this->userinfo_endpoint();
		if ( empty( $userinfo_endpoint ) ) {
			return new \WP_Error( 'authorizenter_oauth2_no_userinfo_url', __( 'User info endpoint is not configured.', 'authorizenter' ), array( 'status' => 500 ) );
		}

		$info = $this->request_userinfo( $userinfo_endpoint, $token['access_token'] );
		if ( is_wp_error( $info ) ) {
			return $info;
		}

		$attr_sub        = isset( $this->config['attr_username'] ) && '' !== $this->config['attr_username'] ? $this->config['attr_username'] : 'id';
		$attr_email      = isset( $this->config['attr_email'] ) && '' !== $this->config['attr_email'] ? $this->config['attr_email'] : 'email';
		$attr_first_name = isset( $this->config['attr_first_name'] ) && '' !== $this->config['attr_first_name'] ? $this->config['attr_first_name'] : 'given_name';
		$attr_last_name  = isset( $this->config['attr_last_name'] ) && '' !== $this->config['attr_last_name'] ? $this->config['attr_last_name'] : 'family_name';

		$sub   = isset( $info[ $attr_sub ] ) ? (string) $info[ $attr_sub ] : '';
		$email = isset( $info[ $attr_email ] ) ? (string) $info[ $attr_email ] : '';
		$first = isset( $info[ $attr_first_name ] ) ? (string) $info[ $attr_first_name ] : '';
		$last  = isset( $info[ $attr_last_name ] ) ? (string) $info[ $attr_last_name ] : '';

		// Attempt to construct a full name if possible.
		$name = '';
		if ( isset( $info['name'] ) ) {
			$name = (string) $info['name'];
		} elseif ( $first || $last ) {
			$name = trim( $first . ' ' . $last );
		}

		return new Identity(
			$this->id(),
			array(
				'sub'            => $sub,
				'email'          => $email,
				'email_verified' => false, // Plain OAuth2 providers typically do not assert email verification
				'name'           => $name,
				'first_name'     => $first,
				'last_name'      => $last,
				'raw'            => $info,
			)
		);
	}
}
