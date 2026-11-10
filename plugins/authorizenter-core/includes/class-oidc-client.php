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
				if ( ! isset( $claims['sub'], $userinfo['sub'] ) || ! is_string( $claims['sub'] ) || ! is_string( $userinfo['sub'] ) || '' === $claims['sub'] || ! hash_equals( $claims['sub'], $userinfo['sub'] ) ) {
					return new \WP_Error( 'authorizenter_oidc_userinfo_subject', __( 'OIDC UserInfo subject does not match the authenticated identity.', 'authorizenter' ), array( 'status' => 401 ) );
				}
				$claims = array_merge( $userinfo, $claims );
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

		$client  = new class( $provider_url, $client_id, $client_secret ) extends \Jumbojett\OpenIDConnectClient {
			/**
			 * Configured token authentication method.
			 *
			 * @var string
			 */
			public $configured_auth_method = 'auto';

			/**
			 * Select exactly one supported method before the library builds the request.
			 *
			 * @param string $code Authorization code.
			 * @param array  $headers Extra HTTP headers.
			 * @return mixed
			 * @throws \Jumbojett\OpenIDConnectClientException Unsupported method.
			 */
			protected function requestTokens( string $code, array $headers = array() ) {
				$methods = (array) $this->getProviderConfigValue( 'token_endpoint_auth_methods_supported', array( 'client_secret_basic' ) );
				$method  = $this->configured_auth_method;
				if ( 'auto' === $method ) {
					$method = in_array( 'client_secret_basic', $methods, true ) ? 'client_secret_basic' : 'client_secret_post';
				}
				if ( ! in_array( $method, $methods, true ) ) {
					throw new \Jumbojett\OpenIDConnectClientException( 'Configured token authentication method is not supported by the provider.' );
				}
				$this->setTokenEndpointAuthMethodsSupported( array( $method ) );
				return parent::requestTokens( $code, $headers );
			}

			/**
			 * Short-lived client_secret_jwt assertion.
			 *
			 * @param string $aud Token endpoint URL.
			 * @return string
			 */
			protected function getJWTClientAssertion( $aud ): string {
				return Oidc_Client::client_assertion( $this->getClientID(), $aud, $this->getClientSecret(), 'HS256' );
			}

			/**
			 * Route the library's HTTP requests through the WordPress HTTP API.
			 *
			 * @param string      $url       Request URL.
			 * @param string|null $post_body POST body, or null for a GET request.
			 * @param array       $headers   Extra headers as "Name: value" strings.
			 * @return string Response body.
			 * @throws \Jumbojett\OpenIDConnectClientException On transport failure.
			 */
			protected function fetchURL( string $url, ?string $post_body = null, array $headers = array() ) {
				// The library's private_key_jwt builder retains client_secret in
				// its body. JWT authentication must use only the signed assertion.
				if ( null !== $post_body ) {
					parse_str( $post_body, $params );
					if ( isset( $params['client_assertion'] ) ) {
						unset( $params['client_secret'] );
						$post_body = http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
					}
				}
				$scheme    = wp_parse_url( $url, PHP_URL_SCHEME );
				$host      = wp_parse_url( $url, PHP_URL_HOST );
				$local_dev = defined( 'WP_ENVIRONMENT_TYPE' )
					&& 'local' === WP_ENVIRONMENT_TYPE
					&& in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true );

				if ( ( 'https' !== $scheme && ! $local_dev )
					|| ( ! $local_dev && function_exists( 'wp_http_validate_url' ) && false === wp_http_validate_url( $url ) ) ) {
					throw new \Jumbojett\OpenIDConnectClientException( 'Unsafe OIDC endpoint URL.' );
				}

				// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- camelCase properties are inherited from the jumbojett library.
				$args = array(
					'timeout'    => $this->timeOut,
					'user-agent' => $this->getUserAgent(),
					'headers'    => array(),
				);

				foreach ( $headers as $header ) {
					$parts = explode( ':', $header, 2 );
					if ( count( $parts ) === 2 ) {
						$args['headers'][ trim( $parts[0] ) ] = trim( $parts[1] );
					}
				}

				if ( null !== $post_body ) {
					$args['method'] = 'POST';
					$args['body']   = $post_body;

					$content_type = 'application/x-www-form-urlencoded';
					if ( is_object( json_decode( $post_body, false ) ) ) {
						$content_type = 'application/json';
					}
					$args['headers']['Content-Type'] = $content_type;
				}

				if ( isset( $this->verifyPeer ) && ! $this->verifyPeer ) {
					$args['sslverify'] = false;
				}

				$response = wp_safe_remote_request( $url, $args );

				if ( is_wp_error( $response ) ) {
					throw new \Jumbojett\OpenIDConnectClientException( 'WP HTTP error: ' . esc_html( $response->get_error_message() ) );
				}

				// Note: the parent's $responseContentType is private, so it cannot
				// be mirrored from a subclass (JWT/JARM content-type detection in
				// the parent is therefore unavailable with this transport).
				$this->responseCode = wp_remote_retrieve_response_code( $response );
				$output             = wp_remote_retrieve_body( $response );

				if ( false !== strpos( $url, '/.well-known/openid-configuration' ) ) {
					$discovery = json_decode( $output, true );
					if ( is_array( $discovery ) ) {
						foreach ( array( 'authorization_endpoint', 'token_endpoint', 'jwks_uri', 'userinfo_endpoint', 'end_session_endpoint' ) as $endpoint_key ) {
							if ( empty( $discovery[ $endpoint_key ] ) ) {
								continue;
							}
							$endpoint           = (string) $discovery[ $endpoint_key ];
							$endpoint_scheme    = wp_parse_url( $endpoint, PHP_URL_SCHEME );
							$endpoint_host      = wp_parse_url( $endpoint, PHP_URL_HOST );
							$endpoint_local_dev = defined( 'WP_ENVIRONMENT_TYPE' )
								&& 'local' === WP_ENVIRONMENT_TYPE
								&& in_array( $endpoint_host, array( 'localhost', '127.0.0.1', '::1' ), true );
							if ( ( 'https' !== $endpoint_scheme && ! $endpoint_local_dev )
								|| ( ! $endpoint_local_dev && function_exists( 'wp_http_validate_url' ) && false === wp_http_validate_url( $endpoint ) ) ) {
								throw new \Jumbojett\OpenIDConnectClientException( 'OIDC discovery returned an unsafe endpoint URL.' );
							}
						}
					}
				}

				if ( false !== strpos( $url, 'jwks' ) ) {
					$decoded = json_decode( $output, false );
					if ( null === $decoded && function_exists( 'authorizenter_log' ) ) {
						authorizenter_log(
							'JWKS fetch error: Non-JSON response',
							array(
								'url'    => $url,
								'status' => $this->responseCode,
								'body'   => substr( $output, 0, 500 ),
							)
						);
					}
				}

				return $output;
				// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			}
		};
		$methods = array(
			'auto'            => 'auto',
			'post'            => 'client_secret_post',
			'basic'           => 'client_secret_basic',
			'secret_jwt'      => 'client_secret_jwt',
			'private_key_jwt' => 'private_key_jwt',
		);
		$method  = $config['auth_method'] ?? 'auto';
		if ( ! isset( $methods[ $method ] ) ) {
			return new \WP_Error( 'authorizenter_oidc_auth_method', __( 'Invalid token authentication method.', 'authorizenter' ), array( 'status' => 500 ) );
		}
		$client->configured_auth_method = $methods[ $method ];
		if ( 'secret_jwt' === $method ) {
			$client->providerConfigParam( array( 'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer' ) );
		}
		if ( 'private_key_jwt' === $method ) {
			$private_key = $this->settings->decrypt( $config['private_key'] ?? '' );
			$key         = openssl_pkey_get_private( $private_key );
			$details     = false !== $key ? openssl_pkey_get_details( $key ) : false;
			if ( ! $details || OPENSSL_KEYTYPE_RSA !== $details['type'] || $details['bits'] < 2048 ) {
				return new \WP_Error( 'authorizenter_oidc_private_key', __( 'A valid RSA private key of at least 2048 bits is required.', 'authorizenter' ), array( 'status' => 500 ) );
			}
			$client->setPrivateKeyJwtGenerator(
				static function ( $endpoint ) use ( $client_id, $private_key ) {
					return Oidc_Client::client_assertion( $client_id, $endpoint, $private_key, 'RS256' );
				}
			);
		}
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

	/**
	 * Sign a token-endpoint client assertion; no identity claims are accepted here.
	 *
	 * @param string $client_id Client identifier.
	 * @param string $endpoint Token endpoint audience.
	 * @param string $key Secret or PEM private key.
	 * @param string $algorithm HS256 or RS256.
	 * @return string
	 */
	public static function client_assertion( $client_id, $endpoint, $key, $algorithm ) {
		$now = time();
		return \Firebase\JWT\JWT::encode(
			array(
				'iss' => $client_id,
				'sub' => $client_id,
				'aud' => $endpoint,
				'iat' => $now,
				'exp' => $now + 60,
				'jti' => bin2hex( random_bytes( 24 ) ),
			),
			$key,
			$algorithm
		);
	}
}
