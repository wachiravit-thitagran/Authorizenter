<?php
/**
 * Facebook Login provider (plain OAuth2 + Graph API).
 *
 * @package Autorizenter\Core
 */

namespace Autorizenter\Core\Providers;

use Autorizenter\Core\Provider_Base;
use Autorizenter\Core\Identity;

defined( 'ABSPATH' ) || exit;

/**
 * Facebook uses OAuth2 but is not a full OIDC provider (no standard id_token /
 * JWKS), so we exchange the code then read the profile from the Graph API.
 *
 * Facebook does not assert a verified email in a standardized way; emails are
 * treated as unverified unless your policy chooses to trust Facebook explicitly.
 */
class Facebook extends Provider_Base {

	const GRAPH_VERSION = 'v19.0';

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'facebook';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return isset( $this->config['label'] ) && '' !== $this->config['label']
			? (string) $this->config['label']
			: __( 'Facebook', 'autorizenter' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function authorization_endpoint() {
		return 'https://www.facebook.com/' . self::GRAPH_VERSION . '/dialog/oauth';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function token_endpoint() {
		return 'https://graph.facebook.com/' . self::GRAPH_VERSION . '/oauth/access_token';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function scopes() {
		return 'public_profile email';
	}

	/**
	 * Exchange an authorization code for a normalized Identity.
	 *
	 * @param string $code          Authorization code.
	 * @param string $redirect_uri  Redirect URI used in the request.
	 * @param string $code_verifier PKCE verifier.
	 * @param string $nonce         Expected nonce (unused; Facebook is not OIDC).
	 * @return Identity|\WP_Error
	 */
	public function exchange( $code, $redirect_uri, $code_verifier, $nonce ) {
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
			return new \WP_Error( 'autorizenter_fb_no_token', __( 'Facebook returned no access token.', 'autorizenter' ), array( 'status' => 502 ) );
		}

		$fields = 'id,name,email';
		$url    = add_query_arg(
			array( 'fields' => $fields ),
			'https://graph.facebook.com/' . self::GRAPH_VERSION . '/me'
		);
		$info   = $this->request_userinfo( $url, $token['access_token'] );
		if ( is_wp_error( $info ) ) {
			return $info;
		}

		return new Identity(
			'facebook',
			array(
				'sub'            => isset( $info['id'] ) ? $info['id'] : '',
				'email'          => isset( $info['email'] ) ? $info['email'] : '',
				'email_verified' => false, // Facebook does not assert this in a standard claim.
				'name'           => isset( $info['name'] ) ? $info['name'] : '',
				'raw'            => $info,
			)
		);
	}
}
