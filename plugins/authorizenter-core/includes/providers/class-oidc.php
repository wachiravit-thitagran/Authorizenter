<?php
/**
 * Generic OpenID Connect provider driven by a discovery document.
 *
 * @package Authorizenter\Core
 */

namespace Authorizenter\Core\Providers;

use Authorizenter\Core\Provider_Base;
use Authorizenter\Core\Identity;
use Authorizenter\Core\JWT_Verifier;

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
	public function is_oidc() {
		return true;
	}

	/**
	 * Issuer base URL for the jumbojett client: the discovery URL with the
	 * /.well-known/openid-configuration suffix removed.
	 *
	 * @return string
	 */
	public function oidc_provider_url() {
		$url = isset( $this->config['discovery_url'] ) ? (string) $this->config['discovery_url'] : '';
		if ( '' === $url ) {
			return '';
		}
		$url = preg_replace( '#/\.well-known/openid-configuration/?$#', '', $url );
		return rtrim( (string) $url, '/' );
	}

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
			: __( 'SSO', 'authorizenter' );
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
			return new \WP_Error( 'authorizenter_oidc_no_discovery', __( 'No OIDC discovery URL configured.', 'authorizenter' ) );
		}
		if ( ! $this->is_secure_url( $url ) ) {
			return new \WP_Error( 'authorizenter_oidc_insecure', __( 'OIDC discovery URL must use HTTPS.', 'authorizenter' ) );
		}

		$cache_key = 'authorizenter_oidc_disc_' . md5( $url );
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
			return new \WP_Error( 'authorizenter_oidc_bad_discovery', __( 'Invalid OIDC discovery document.', 'authorizenter' ) );
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
	 * Issuer — config override takes precedence over discovery.
	 *
	 * @return string
	 */
	protected function issuer() {
		if ( ! empty( $this->config['issuer_url'] ) ) {
			return (string) $this->config['issuer_url'];
		}
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
		$endpoint = $this->token_endpoint();
		$base     = array(
			'grant_type'    => 'authorization_code',
			'code'          => $code,
			'redirect_uri'  => $redirect_uri,
			'code_verifier' => $code_verifier,
		);
		$req      = $this->build_token_request( $base, $endpoint );
		if ( isset( $req['_error'] ) ) {
			return $req['_error'];
		}
		$token = $this->request_token_with_args( $endpoint, $req );

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
			return new \WP_Error( 'authorizenter_oidc_no_identity', __( 'Provider returned no usable identity.', 'authorizenter' ), array( 'status' => 502 ) );
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
	 * Map OIDC claims to an Identity, using configured attribute names.
	 *
	 * @param array $claims Claim set.
	 * @return Identity|\WP_Error
	 */
	protected function identity_from_claims( array $claims ) {
		// Treat the email as verified when the claim says so, or when the admin
		// has chosen to trust this IdP's email (many enterprise/university OIDC
		// servers do not send the email_verified claim at all).
		$claim_verified = isset( $claims['email_verified'] )
			? filter_var( $claims['email_verified'], FILTER_VALIDATE_BOOLEAN )
			: false;
		$email_verified = ! empty( $this->config['trust_email'] ) ? true : $claim_verified;

		if ( ! empty( $this->config['oidc_require_verified_email'] ) && ! $email_verified ) {
			return new \WP_Error(
				'authorizenter_oidc_email_unverified',
				__( 'Your email address must be verified to sign in.', 'authorizenter' ),
				array( 'status' => 403 )
			);
		}

		$attr_username   = ! empty( $this->config['attr_username'] ) ? $this->config['attr_username'] : '';
		$attr_email      = ! empty( $this->config['attr_email'] ) ? $this->config['attr_email'] : 'email';
		$attr_first_name = ! empty( $this->config['attr_first_name'] ) ? $this->config['attr_first_name'] : 'given_name';
		$attr_last_name  = ! empty( $this->config['attr_last_name'] ) ? $this->config['attr_last_name'] : 'family_name';

		$username = '' !== $attr_username && isset( $claims[ $attr_username ] )
			? (string) $claims[ $attr_username ]
			: ( isset( $claims['sub'] ) ? (string) $claims['sub'] : '' );

		return new Identity(
			$this->id(),
			array(
				'sub'            => isset( $claims['sub'] ) ? $claims['sub'] : '',
				'email'          => isset( $claims[ $attr_email ] ) ? $claims[ $attr_email ] : '',
				'email_verified' => $email_verified,
				'name'           => isset( $claims['name'] ) ? $claims['name'] : '',
				'first_name'     => isset( $claims[ $attr_first_name ] ) ? $claims[ $attr_first_name ] : '',
				'last_name'      => isset( $claims[ $attr_last_name ] ) ? $claims[ $attr_last_name ] : '',
				'username'       => $username,
				'hd'             => isset( $claims['hd'] ) ? $claims['hd'] : '',
				'raw'            => $claims,
			)
		);
	}

	/**
	 * Public wrapper for tests.
	 *
	 * @param array $claims Claim set.
	 * @return Identity|\WP_Error
	 */
	public function identity_from_claims_public( array $claims ) {
		return $this->identity_from_claims( $claims );
	}

	/**
	 * Build HTTP request args for the token endpoint with the configured auth method.
	 *
	 * @param array  $base_body Base POST params.
	 * @param string $endpoint  Token endpoint URL.
	 * @return array Args for wp_remote_post (headers + body).
	 */
	protected function build_token_request( array $base_body, $endpoint ) {
		$method  = isset( $this->config['auth_method'] ) ? $this->config['auth_method'] : 'auto';
		$headers = array(
			'Accept'       => 'application/json',
			'Content-Type' => 'application/x-www-form-urlencoded',
		);

		if ( 'auto' === $method ) {
			$doc       = $this->discovery();
			$supported = ( ! is_wp_error( $doc ) && isset( $doc['token_endpoint_auth_methods_supported'] ) )
				? (array) $doc['token_endpoint_auth_methods_supported']
				: array();
			$order     = array( 'private_key_jwt', 'client_secret_jwt', 'client_secret_basic', 'client_secret_post' );
			$map       = array(
				'client_secret_post'  => 'post',
				'client_secret_basic' => 'basic',
				'client_secret_jwt'   => 'secret_jwt',
				'private_key_jwt'     => 'private_key_jwt',
			);
			$method    = 'post';
			foreach ( $order as $m ) {
				if ( in_array( $m, $supported, true ) ) {
					$method = $map[ $m ];
					break;
				}
			}
		}

		if ( 'basic' === $method ) {
			$headers['Authorization'] = 'Basic ' . base64_encode( $this->client_id() . ':' . $this->client_secret() ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			return array(
				'headers' => $headers,
				'body'    => $base_body,
			);
		}

		if ( 'secret_jwt' === $method ) {
			$assertion = $this->build_client_assertion_hs256( $endpoint );
			return array(
				'headers' => $headers,
				'body'    => array_merge(
					$base_body,
					array(
						'client_id'             => $this->client_id(),
						'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
						'client_assertion'      => $assertion,
					)
				),
			);
		}

		if ( 'private_key_jwt' === $method ) {
			$assertion = $this->build_client_assertion_rs256( $endpoint );
			if ( is_wp_error( $assertion ) ) {
				return array(
					'headers' => $headers,
					'body'    => $base_body,
					'_error'  => $assertion,
				);
			}
			return array(
				'headers' => $headers,
				'body'    => array_merge(
					$base_body,
					array(
						'client_id'             => $this->client_id(),
						'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
						'client_assertion'      => $assertion,
					)
				),
			);
		}

		// Default: client_secret_post.
		return array(
			'headers' => $headers,
			'body'    => array_merge(
				$base_body,
				array(
					'client_id'     => $this->client_id(),
					'client_secret' => $this->client_secret(),
				)
			),
		);
	}

	/**
	 * Public wrapper for tests.
	 *
	 * @param array  $body     Base POST params.
	 * @param string $endpoint Token endpoint URL.
	 * @return array
	 */
	public function build_token_request_public( array $body, $endpoint ) {
		return $this->build_token_request( $body, $endpoint );
	}

	/**
	 * POST to the token endpoint using pre-built request args.
	 *
	 * @param string $endpoint Token endpoint URL.
	 * @param array  $args     Request args (headers + body).
	 * @return array|\WP_Error
	 */
	protected function request_token_with_args( $endpoint, array $args ) {
		$response = wp_remote_post( $endpoint, array_merge( array( 'timeout' => 15 ), $args ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			return new \WP_Error(
				'authorizenter_token_error',
				// translators: %1$s is the provider ID.
				sprintf( __( 'Token exchange failed for provider %1$s.', 'authorizenter' ), $this->id() ),
				array( 'status' => 502 )
			);
		}
		return $data;
	}

	/**
	 * Build a client assertion JWT signed with client_secret (HS256).
	 *
	 * @param string $audience Token endpoint URL.
	 * @return string JWT.
	 */
	private function build_client_assertion_hs256( $audience ) {
		$now    = time();
		$header = $this->jwt_encode(
			array(
				'alg' => 'HS256',
				'typ' => 'JWT',
			)
		);
		$claims = $this->jwt_encode(
			array(
				'iss' => $this->client_id(),
				'sub' => $this->client_id(),
				'aud' => $audience,
				'jti' => bin2hex( random_bytes( 16 ) ),
				'iat' => $now,
				'exp' => $now + 60,
			)
		);
		$sig    = hash_hmac( 'sha256', $header . '.' . $claims, $this->client_secret(), true );
		return $header . '.' . $claims . '.' . $this->base64url( $sig );
	}

	/**
	 * Build a client assertion JWT signed with a private key (RS256).
	 *
	 * @param string $audience Token endpoint URL.
	 * @return string|\WP_Error
	 */
	private function build_client_assertion_rs256( $audience ) {
		$pem = $this->settings->decrypt(
			isset( $this->config['private_key'] ) ? $this->config['private_key'] : ''
		);
		if ( '' === $pem ) {
			return new \WP_Error( 'authorizenter_oidc_no_private_key', __( 'No private key configured for private_key_jwt.', 'authorizenter' ) );
		}
		$key = openssl_pkey_get_private( $pem );
		if ( false === $key ) {
			return new \WP_Error( 'authorizenter_oidc_bad_private_key', __( 'Could not load OIDC private key.', 'authorizenter' ) );
		}
		$now    = time();
		$header = $this->jwt_encode(
			array(
				'alg' => 'RS256',
				'typ' => 'JWT',
			)
		);
		$claims = $this->jwt_encode(
			array(
				'iss' => $this->client_id(),
				'sub' => $this->client_id(),
				'aud' => $audience,
				'jti' => bin2hex( random_bytes( 16 ) ),
				'iat' => $now,
				'exp' => $now + 60,
			)
		);
		$sig    = '';
		openssl_sign( $header . '.' . $claims, $sig, $key, OPENSSL_ALGO_SHA256 );
		return $header . '.' . $claims . '.' . $this->base64url( $sig );
	}

	/**
	 * JSON-encode and base64url-encode a payload for JWT.
	 *
	 * @param array $data Payload.
	 * @return string
	 */
	private function jwt_encode( array $data ) {
		return $this->base64url( wp_json_encode( $data ) );
	}

	/**
	 * Base64url encode without padding.
	 *
	 * @param string $data Binary or JSON string.
	 * @return string
	 */
	private function base64url( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
}
