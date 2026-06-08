<?php
/**
 * Abstract provider adapter.
 *
 * @package Autorizenter\Core
 */

namespace Autorizenter\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Base class every provider adapter extends.
 *
 * A provider knows how to build an authorization URL, exchange a code for tokens,
 * and normalize the resulting identity into a common Identity object.
 */
abstract class Provider_Base {

	/**
	 * Settings store.
	 *
	 * @var Settings
	 */
	protected $settings;

	/**
	 * Per-provider config (client_id, client_secret, discovery_url, ...).
	 *
	 * @var array
	 */
	protected $config;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings store.
	 * @param array    $config   Provider config.
	 */
	public function __construct( Settings $settings, array $config ) {
		$this->settings = $settings;
		$this->config   = $config;
	}

	/**
	 * Stable provider id, e.g. "google".
	 *
	 * @return string
	 */
	abstract public function id();

	/**
	 * Human-readable label.
	 *
	 * @return string
	 */
	abstract public function label();

	/**
	 * Authorization endpoint URL.
	 *
	 * @return string
	 */
	abstract protected function authorization_endpoint();

	/**
	 * Token endpoint URL.
	 *
	 * @return string
	 */
	abstract protected function token_endpoint();

	/**
	 * OAuth scopes to request.
	 *
	 * @return string Space-separated scopes.
	 */
	abstract protected function scopes();

	/**
	 * Exchange an authorization code for a normalized Identity.
	 *
	 * @param string $code          Authorization code.
	 * @param string $redirect_uri  Redirect URI used in the request.
	 * @param string $code_verifier PKCE verifier.
	 * @param string $nonce         Expected nonce (for OIDC id_token).
	 * @return Identity|\WP_Error
	 */
	abstract public function exchange( $code, $redirect_uri, $code_verifier, $nonce );

	/**
	 * Whether this provider is enabled.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return ! empty( $this->config['enabled'] ) && '' !== $this->client_id();
	}

	/**
	 * Client id.
	 *
	 * @return string
	 */
	public function client_id() {
		return isset( $this->config['client_id'] ) ? (string) $this->config['client_id'] : '';
	}

	/**
	 * Optional custom logo URL for the login button (admin-configurable).
	 *
	 * @return string Empty string when no custom logo is set.
	 */
	public function logo_url() {
		return isset( $this->config['logo_url'] ) && '' !== $this->config['logo_url']
			? esc_url( $this->config['logo_url'] )
			: '';
	}

	/**
	 * Decrypted client secret.
	 *
	 * @return string
	 */
	protected function client_secret() {
		return $this->settings->decrypt( isset( $this->config['client_secret'] ) ? $this->config['client_secret'] : '' );
	}

	/**
	 * Build the authorization URL.
	 *
	 * @param string $state          Opaque state value.
	 * @param string $redirect_uri   Redirect URI.
	 * @param string $code_challenge PKCE challenge (base64url of sha256 verifier).
	 * @param string $nonce          OIDC nonce.
	 * @return string
	 */
	public function authorization_url( $state, $redirect_uri, $code_challenge, $nonce ) {
		$args = array(
			'response_type'         => 'code',
			'client_id'             => $this->client_id(),
			'redirect_uri'          => $redirect_uri,
			'scope'                 => $this->scopes(),
			'state'                 => $state,
			'code_challenge'        => $code_challenge,
			'code_challenge_method' => 'S256',
			'nonce'                 => $nonce,
		);

		/**
		 * Filter authorization request args per provider.
		 *
		 * @param array  $args Query args.
		 * @param string $id   Provider id.
		 */
		$args = apply_filters( 'autorizenter_authorization_args', $args, $this->id() );

		return add_query_arg( array_map( 'rawurlencode', $args ), $this->authorization_endpoint() );
	}

	/**
	 * Helper: POST to the token endpoint and decode JSON.
	 *
	 * @param array $body Form body.
	 * @return array|\WP_Error Decoded token response.
	 */
	protected function request_token( array $body ) {
		$response = wp_remote_post(
			$this->token_endpoint(),
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			return new \WP_Error(
				'autorizenter_token_error',
				/* translators: %s: provider id */
				sprintf( __( 'Token exchange failed for provider %s.', 'autorizenter' ), $this->id() ),
				array( 'status' => 502 )
			);
		}

		return $data;
	}

	/**
	 * RP-initiated logout URL at the provider, if supported.
	 *
	 * @param string $post_logout_redirect Where the IdP should return the user.
	 * @return string Empty string when the provider has no end-session endpoint.
	 */
	public function end_session_url( $post_logout_redirect ) {
		return '';
	}

	/**
	 * Whether a remote URL is safe to call (HTTPS, or a local dev host).
	 *
	 * @param string $url URL to test.
	 * @return bool
	 */
	protected function is_secure_url( $url ) {
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( 'https' === $scheme ) {
			return true;
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true );
	}

	/**
	 * Helper: GET a userinfo/profile endpoint with a bearer token.
	 *
	 * @param string $url          Endpoint.
	 * @param string $access_token Access token.
	 * @return array|\WP_Error
	 */
	protected function request_userinfo( $url, $access_token ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'autorizenter_userinfo_error', __( 'Could not read user profile.', 'autorizenter' ), array( 'status' => 502 ) );
		}
		return $data;
	}
}
