<?php
/**
 * OIDC flow backed by jumbojett/openid-connect-php.
 *
 * @package Authorizenter\Core
 */

namespace Authorizenter\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around \Jumbojett\OpenIDConnectClient.
 *
 * It replaces the hand-rolled token exchange + JWKS/JWT verification for OIDC
 * providers (Google, LINE, generic OIDC). The library manages state, nonce and
 * PKCE in the PHP session, so callers must ensure a session is active across the
 * authorize and callback requests (see OAuth_Engine::maybe_start_session()).
 *
 * Non-OIDC providers (e.g. Facebook plain OAuth2) do NOT use this class.
 */
class Oidc_Client {

	/**
	 * Settings store (for secret decryption).
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Whether the jumbojett library is available.
	 *
	 * @return bool
	 */
	public function is_available() {
		return class_exists( '\\Jumbojett\\OpenIDConnectClient' );
	}

	/**
	 * Begin an OIDC login: redirects the browser to the IdP and exits.
	 *
	 * @param array  $config       Provider config (client_id, client_secret, ...).
	 * @param string $provider_url Issuer base URL (discovery is appended).
	 * @param string $redirect_uri Callback URL registered with the IdP.
	 * @param array  $scopes       Scopes to request.
	 * @return \WP_Error Only returns on failure; success redirects + exits.
	 */
	public function start( array $config, $provider_url, $redirect_uri, array $scopes ) {
		$client = $this->client( $config, $provider_url, $redirect_uri, $scopes );
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		try {
			// No authorization code on this leg, so authenticate() builds the
			// request (storing state/nonce/PKCE in the session) and redirects.
			$client->authenticate();
		} catch ( \Throwable $e ) {
			authorizenter_log( 'OIDC start failed', array( 'message' => $e->getMessage() ) );
			return new \WP_Error( 'authorizenter_oidc_start_failed', $e->getMessage(), array( 'status' => 502 ) );
		}

		// authenticate() redirects + exits on the no-code leg; reaching here is unexpected.
		return new \WP_Error( 'authorizenter_oidc_no_redirect', __( 'Could not start the OIDC sign-in.', 'authorizenter' ), array( 'status' => 500 ) );
	}

	/**
	 * Complete an OIDC login from the callback request.
	 *
	 * @param array  $config       Provider config.
	 * @param string $provider_url Issuer base URL.
	 * @param string $redirect_uri Callback URL.
	 * @param array  $scopes       Scopes (must match the authorize leg).
	 * @return array|\WP_Error Merged claim set (id_token + userinfo) or error.
	 */
	public function complete( array $config, $provider_url, $redirect_uri, array $scopes ) {
		$client = $this->client( $config, $provider_url, $redirect_uri, $scopes );
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		try {
			$ok = $client->authenticate();
			if ( ! $ok ) {
				return new \WP_Error( 'authorizenter_oidc_auth_failed', __( 'OIDC authentication failed.', 'authorizenter' ), array( 'status' => 401 ) );
			}

			$claims = (array) $client->getVerifiedClaims();

			// Pull profile/email from userinfo to fill gaps the id_token may omit
			// (some IdPs keep these out of the id_token).
			try {
				$userinfo = (array) $client->requestUserInfo();
				$claims   = array_merge( $userinfo, $claims );
			} catch ( \Throwable $e ) {
				// userinfo is best-effort; the id_token claims are authoritative.
				authorizenter_log( 'oidc userinfo unavailable', array( 'message' => $e->getMessage() ) );
			}

			return $claims;
		} catch ( \Throwable $e ) {
			authorizenter_log( 'OIDC authentication error', array( 'message' => $e->getMessage() ) );
			return new \WP_Error( 'authorizenter_oidc_error', $e->getMessage(), array( 'status' => 502 ) );
		}
	}

	/**
	 * Build and configure an OpenIDConnectClient.
	 *
	 * @param array  $config       Provider config.
	 * @param string $provider_url Issuer base URL.
	 * @param string $redirect_uri Callback URL.
	 * @param array  $scopes       Scopes.
	 * @return \Jumbojett\OpenIDConnectClient|\WP_Error
	 */
	private function client( array $config, $provider_url, $redirect_uri, array $scopes ) {
		if ( ! $this->is_available() ) {
			return new \WP_Error(
				'authorizenter_oidc_lib_missing',
				__( 'The jumbojett/openid-connect-php library is required for OIDC sign-in. Run composer install.', 'authorizenter' ),
				array( 'status' => 500 )
			);
		}
		if ( '' === (string) $provider_url ) {
			return new \WP_Error( 'authorizenter_oidc_no_provider_url', __( 'No OIDC issuer/discovery URL configured.', 'authorizenter' ), array( 'status' => 500 ) );
		}

		$client_id     = isset( $config['client_id'] ) ? (string) $config['client_id'] : '';
		$client_secret = $this->settings->decrypt( isset( $config['client_secret'] ) ? $config['client_secret'] : '' );

		$client = new \Jumbojett\OpenIDConnectClient( $provider_url, $client_id, $client_secret );
		$client->setRedirectURL( $redirect_uri );
		$client->setCodeChallengeMethod( 'S256' );

		// jumbojett's addScope() expects an array of scopes (not one string).
		$scopes = array_values( array_filter( array_map( 'strval', $scopes ) ) );
		if ( ! empty( $scopes ) ) {
			$client->addScope( $scopes );
		}

		if ( ! empty( $config['issuer_url'] ) ) {
			$client->setIssuer( (string) $config['issuer_url'] );
		}

		/**
		 * Filter the configured OIDC client (e.g. setHttpProxy, setVerifyHost,
		 * provider config overrides) before the flow runs.
		 *
		 * @param \Jumbojett\OpenIDConnectClient $client The client.
		 * @param array                          $config Provider config.
		 */
		return apply_filters( 'authorizenter_oidc_client', $client, $config );
	}
}
