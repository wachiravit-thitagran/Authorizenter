<?php
/**
 * Generic OpenID Connect provider driven by a discovery document.
 *
 * @package Autorizenter\Core
 */

namespace Autorizenter\Core\Providers;

use Autorizenter\Core\Provider_Base;
use Autorizenter\Core\Identity;
use Autorizenter\Core\JWT_Verifier;

defined( 'ABSPATH' ) || exit;

/**
 * Works with any spec-compliant OIDC IdP (Azure AD, Keycloak, Google, PSU Passport,
 * Okta, Auth0, ...). Configured with just a discovery URL + client credentials.
 */
class OIDC extends Provider_Base {

	/**
	 * Cached discovery document.
	 *
	 * @var array|null
	 */
	protected $discovery = null;

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return isset( $this->config['id'] ) ? (string) $this->config['id'] : 'oidc';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return isset( $this->config['label'] ) && '' !== $this->config['label']
			? (string) $this->config['label']
			: __( 'SSO', 'autorizenter' );
	}

	/**
	 * Fetch (and cache) the discovery document.
	 *
	 * @return array|\WP_Error
	 */
	protected function discovery() {
		if ( is_array( $this->discovery ) ) {
			return $this->discovery;
		}

		$url = isset( $this->config['discovery_url'] ) ? esc_url_raw( $this->config['discovery_url'] ) : '';
		if ( '' === $url ) {
			return new \WP_Error( 'autorizenter_oidc_no_discovery', __( 'No OIDC discovery URL configured.', 'autorizenter' ) );
		}
		if ( ! $this->is_secure_url( $url ) ) {
			return new \WP_Error( 'autorizenter_oidc_insecure', __( 'OIDC discovery URL must use HTTPS.', 'autorizenter' ) );
		}

		$cache_key = 'autorizenter_oidc_disc_' . md5( $url );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			$this->discovery = $cached;
			return $cached;
		}

		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$doc = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $doc ) || empty( $doc['authorization_endpoint'] ) || empty( $doc['token_endpoint'] ) ) {
			return new \WP_Error( 'autorizenter_oidc_bad_discovery', __( 'Invalid OIDC discovery document.', 'autorizenter' ) );
		}

		set_transient( $cache_key, $doc, HOUR_IN_SECONDS );
		$this->discovery = $doc;
		return $doc;
	}

	/**
	 * Read a value from discovery (returns empty string on failure).
	 *
	 * @param string $key Discovery key.
	 * @return string
	 */
	protected function disc( $key ) {
		$doc = $this->discovery();
		if ( is_wp_error( $doc ) ) {
			return '';
		}
		return isset( $doc[ $key ] ) ? (string) $doc[ $key ] : '';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function authorization_endpoint() {
		return $this->disc( 'authorization_endpoint' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function token_endpoint() {
		return $this->disc( 'token_endpoint' );
	}

	/**
	 * Issuer from discovery.
	 *
	 * @return string
	 */
	protected function issuer() {
		return $this->disc( 'issuer' );
	}

	/**
	 * JWKS URI from discovery.
	 *
	 * @return string
	 */
	protected function jwks_uri() {
		return $this->disc( 'jwks_uri' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function scopes() {
		$scopes = isset( $this->config['scopes'] ) && '' !== $this->config['scopes']
			? (string) $this->config['scopes']
			: 'openid email profile';
		return $scopes;
	}

	/**
	 * Exchange an authorization code for a normalized Identity.
	 *
	 * @param string $code          Authorization code.
	 * @param string $redirect_uri  Redirect URI used in the request.
	 * @param string $code_verifier PKCE verifier.
	 * @param string $nonce         Expected nonce.
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

		// Prefer verifying the id_token (OIDC); fall back to userinfo.
		if ( ! empty( $token['id_token'] ) ) {
			$verifier = new JWT_Verifier();
			$claims   = $verifier->verify(
				$token['id_token'],
				$this->jwks_uri(),
				$this->issuer(),
				$this->client_id(),
				$nonce
			);
			if ( is_wp_error( $claims ) ) {
				return $claims;
			}
			return $this->identity_from_claims( $claims );
		}

		// No id_token: use userinfo endpoint.
		$userinfo_url = $this->disc( 'userinfo_endpoint' );
		if ( '' === $userinfo_url || empty( $token['access_token'] ) ) {
			return new \WP_Error( 'autorizenter_oidc_no_identity', __( 'Provider returned no usable identity.', 'autorizenter' ), array( 'status' => 502 ) );
		}
		$info = $this->request_userinfo( $userinfo_url, $token['access_token'] );
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		return $this->identity_from_claims( $info );
	}

	/**
	 * RP-initiated logout URL using the discovery end_session_endpoint.
	 *
	 * @param string $post_logout_redirect Return URL after IdP logout.
	 * @return string
	 */
	public function end_session_url( $post_logout_redirect ) {
		$endpoint = $this->disc( 'end_session_endpoint' );
		if ( '' === $endpoint ) {
			return '';
		}
		return add_query_arg(
			array(
				'post_logout_redirect_uri' => rawurlencode( $post_logout_redirect ),
				'client_id'                => rawurlencode( $this->client_id() ),
			),
			$endpoint
		);
	}

	/**
	 * Map OIDC claims to an Identity.
	 *
	 * @param array $claims Claim set.
	 * @return Identity
	 */
	protected function identity_from_claims( array $claims ) {
		return new Identity(
			$this->id(),
			array(
				'sub'            => isset( $claims['sub'] ) ? $claims['sub'] : '',
				'email'          => isset( $claims['email'] ) ? $claims['email'] : '',
				'email_verified' => isset( $claims['email_verified'] ) ? $claims['email_verified'] : false,
				'name'           => isset( $claims['name'] ) ? $claims['name'] : '',
				'hd'             => isset( $claims['hd'] ) ? $claims['hd'] : '',
				'raw'            => $claims,
			)
		);
	}
}
