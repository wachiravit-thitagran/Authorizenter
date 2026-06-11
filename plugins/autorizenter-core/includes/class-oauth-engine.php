<?php
/**
 * OAuth2 Authorization Code flow orchestration.
 *
 * @package Autorizenter\Core
 */

namespace Autorizenter\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Generates authorization redirects and handles callbacks: PKCE, state, nonce,
 * org policy enforcement, and user provisioning + login.
 *
 * Transient flow state is stored server-side keyed by the opaque `state` value,
 * so nothing sensitive is trusted from the client on return.
 */
class OAuth_Engine {

	const FLOW_TTL = 600; // 10 minutes.

	/**
	 * PHP session key holding the in-flight OIDC login (provider/context/return_to).
	 * OIDC state/nonce/PKCE themselves live in the jumbojett session keys.
	 */
	const SESSION_KEY = 'autorizenter_oidc_flow';

	/**
	 * Settings store.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Lazily-built OIDC client (jumbojett wrapper).
	 *
	 * @var Oidc_Client|null
	 */
	private $oidc_client = null;

	/**
	 * Provider registry.
	 *
	 * @var Provider_Registry
	 */
	private $providers;

	/**
	 * Org policy.
	 *
	 * @var Org_Policy
	 */
	private $policy;

	/**
	 * User mapper.
	 *
	 * @var User_Mapper
	 */
	private $users;

	/**
	 * Constructor.
	 *
	 * @param Settings          $settings  Settings store.
	 * @param Provider_Registry $providers Provider registry.
	 * @param Org_Policy        $policy    Org policy.
	 * @param User_Mapper       $users     User mapper.
	 */
	public function __construct( Settings $settings, Provider_Registry $providers, Org_Policy $policy, User_Mapper $users ) {
		$this->settings  = $settings;
		$this->providers = $providers;
		$this->policy    = $policy;
		$this->users     = $users;
	}

	/**
	 * The single callback/redirect URI for all providers.
	 *
	 * @return string
	 */
	public function redirect_uri() {
		return rest_url( AUTORIZENTER_REST_NAMESPACE . '/callback' );
	}

	/**
	 * Begin a login: returns an authorization URL to redirect the user to.
	 *
	 * @param string $provider_id   Provider id.
	 * @param string $return_to     Where to send the user after success (relative or same-host URL).
	 * @param string $context_id    Login context id (e.g. "default", "admin").
	 * @return string|\WP_Error
	 */
	public function begin( $provider_id, $return_to = '', $context_id = 'default' ) {
		$provider = $this->providers->get( $provider_id );
		if ( ! $provider || ! $provider->is_enabled() ) {
			return new \WP_Error( 'autorizenter_provider_disabled', __( 'This sign-in method is not available.', 'autorizenter' ), array( 'status' => 400 ) );
		}

		// Enforce that the provider is permitted within this context.
		$context = $this->settings->get_context( $context_id );
		if ( ! $this->providers->is_allowed_in_context( $provider_id, $context ) ) {
			return new \WP_Error( 'autorizenter_provider_not_in_context', __( 'This sign-in method is not available here.', 'autorizenter' ), array( 'status' => 400 ) );
		}

		$return_to = $this->sanitize_return_to( $return_to );

		// OIDC providers (Google, LINE, generic) are driven by jumbojett.
		if ( $provider->is_oidc() ) {
			return $this->begin_oidc( $provider, $context, $return_to );
		}

		// Legacy OAuth2 path (e.g. Facebook): our own state/nonce/PKCE + transient.
		$state          = $this->random( 24 );
		$nonce          = $this->random( 24 );
		$code_verifier  = $this->random( 48 );
		$code_challenge = $this->pkce_challenge( $code_verifier );

		$flow = array(
			'provider'      => $provider_id,
			'context'       => $context['id'],
			'nonce'         => $nonce,
			'code_verifier' => $code_verifier,
			'return_to'     => $return_to,
			'created'       => time(),
		);
		set_transient( $this->flow_key( $state ), $flow, self::FLOW_TTL );

		return $provider->authorization_url( $state, $this->redirect_uri(), $code_challenge, $nonce );
	}

	/**
	 * Begin an OIDC login via jumbojett (redirects + exits on success).
	 *
	 * @param Provider_Base $provider  OIDC provider.
	 * @param array         $context   Resolved context.
	 * @param string        $return_to Sanitized post-login destination.
	 * @return \WP_Error Returns only on failure.
	 */
	private function begin_oidc( Provider_Base $provider, array $context, $return_to ) {
		$this->maybe_start_session();
		$_SESSION[ self::SESSION_KEY ] = array(
			'provider'  => $provider->id(),
			'context'   => $context['id'],
			'return_to' => $return_to,
			'created'   => time(),
		);

		// start() redirects the browser to the IdP and exits on success.
		return $this->oidc_client()->start(
			$provider->config(),
			$provider->oidc_provider_url(),
			$this->redirect_uri(),
			$provider->scopes_list()
		);
	}

	/**
	 * Handle the provider callback.
	 *
	 * @param string $code  Authorization code.
	 * @param string $state Opaque state from the provider.
	 * @return array|\WP_Error On success: array( user => WP_User, return_to => string ).
	 */
	public function handle_callback( $code, $state ) {
		// OIDC providers: jumbojett validated state/nonce/PKCE from the PHP session.
		$session = $this->oidc_session();

		autorizenter_log(
			'callback received',
			array(
				'code'                 => '' !== $code,
				'state'                => '' !== $state,
				'oidc_session_present' => is_array( $session ),
				'php_session'          => session_status(),
				'has_session_cookie'   => isset( $_COOKIE[ session_name() ] ),
			)
		);

		if ( is_array( $session ) ) {
			return $this->handle_callback_oidc( $session );
		}

		// Legacy OAuth2 path (e.g. Facebook): our transient-backed state.
		if ( '' === $code || '' === $state ) {
			return new \WP_Error( 'autorizenter_callback_missing', __( 'Missing authorization parameters.', 'autorizenter' ), array( 'status' => 400 ) );
		}

		$flow = get_transient( $this->flow_key( $state ) );
		if ( ! is_array( $flow ) ) {
			autorizenter_log( 'flow transient missing/expired for state' );
			return new \WP_Error( 'autorizenter_state_invalid', __( 'Login session expired or invalid. Please try again.', 'autorizenter' ), array( 'status' => 400 ) );
		}
		delete_transient( $this->flow_key( $state ) ); // single use.

		$provider = $this->providers->get( $flow['provider'] );
		if ( ! $provider || ! $provider->is_enabled() ) {
			autorizenter_log( 'provider disabled at callback', array( 'provider' => $flow['provider'] ) );
			return new \WP_Error( 'autorizenter_provider_disabled', __( 'This sign-in method is not available.', 'autorizenter' ), array( 'status' => 400 ) );
		}

		$context = $this->settings->get_context( isset( $flow['context'] ) ? $flow['context'] : 'default' );

		$identity = $provider->exchange( $code, $this->redirect_uri(), $flow['code_verifier'], $flow['nonce'] );
		if ( is_wp_error( $identity ) ) {
			autorizenter_log(
				'token exchange failed',
				array(
					'provider' => $flow['provider'],
					'error'    => $identity->get_error_code(),
					'message'  => $identity->get_error_message(),
				)
			);
			return $identity;
		}

		autorizenter_log(
			'identity obtained',
			array(
				'provider' => $identity->provider,
				'email'    => $identity->email,
				'verified' => $identity->email_verified,
			)
		);

		$return_to = isset( $flow['return_to'] ) ? $flow['return_to'] : '';
		return $this->finish_login( $identity, $context, $provider, $return_to );
	}

	/**
	 * Complete an OIDC callback through jumbojett, then run the shared pipeline.
	 *
	 * @param array $session Stored flow (provider/context/return_to) from the session.
	 * @return array|\WP_Error
	 */
	private function handle_callback_oidc( array $session ) {
		$this->clear_oidc_session(); // single use.

		$provider_id = isset( $session['provider'] ) ? $session['provider'] : '';
		$provider    = $this->providers->get( $provider_id );
		if ( ! $provider || ! $provider->is_enabled() ) {
			autorizenter_log( 'provider disabled at callback', array( 'provider' => $provider_id ) );
			return new \WP_Error( 'autorizenter_provider_disabled', __( 'This sign-in method is not available.', 'autorizenter' ), array( 'status' => 400 ) );
		}

		$context = $this->settings->get_context( isset( $session['context'] ) ? $session['context'] : 'default' );

		$claims = $this->oidc_client()->complete(
			$provider->config(),
			$provider->oidc_provider_url(),
			$this->redirect_uri(),
			$provider->scopes_list()
		);
		if ( is_wp_error( $claims ) ) {
			autorizenter_log(
				'token exchange failed',
				array(
					'provider' => $provider->id(),
					'error'    => $claims->get_error_code(),
					'message'  => $claims->get_error_message(),
				)
			);
			return $claims;
		}

		$identity = method_exists( $provider, 'identity_from_claims_public' )
			? $provider->identity_from_claims_public( $claims )
			: new Identity( $provider->id(), $claims );
		if ( is_wp_error( $identity ) ) {
			autorizenter_log(
				'identity mapping failed',
				array(
					'provider' => $provider->id(),
					'error'    => $identity->get_error_code(),
				)
			);
			return $identity;
		}

		autorizenter_log(
			'identity obtained',
			array(
				'provider' => $identity->provider,
				'email'    => $identity->email,
				'verified' => $identity->email_verified,
			)
		);

		$return_to = isset( $session['return_to'] ) ? $session['return_to'] : '';
		return $this->finish_login( $identity, $context, $provider, $return_to );
	}

	/**
	 * Shared post-identity pipeline: identity filter, policy, provisioning, the
	 * per-context capability gate, then sign the user in.
	 *
	 * @param Identity      $identity  Normalized identity.
	 * @param array         $context   Resolved login context.
	 * @param Provider_Base $provider  Provider that produced the identity.
	 * @param string        $return_to Post-login destination.
	 * @return array|\WP_Error
	 */
	private function finish_login( Identity $identity, array $context, Provider_Base $provider, $return_to ) {
		/**
		 * Inspect/short-circuit a freshly obtained identity.
		 *
		 * @param Identity $identity Normalized identity.
		 * @param array    $context  Resolved login context.
		 */
		$identity = apply_filters( 'autorizenter_identity', $identity, $context );

		// Domain / verified-email / hd policy, scoped to the context.
		$allowed = $this->policy->is_allowed( $identity, $context );
		if ( is_wp_error( $allowed ) ) {
			autorizenter_log( 'policy denied', array( 'error' => $allowed->get_error_code() ) );
			return $this->attach_deny_redirect( $allowed, $context );
		}

		$user = $this->users->resolve( $identity, $context );
		if ( is_wp_error( $user ) ) {
			autorizenter_log(
				'user resolve failed',
				array(
					'error'   => $user->get_error_code(),
					'message' => $user->get_error_message(),
				)
			);
			return $this->attach_deny_redirect( $user, $context );
		}

		autorizenter_log(
			'user resolved',
			array(
				'user_id' => $user->ID,
				'login'   => $user->user_login,
			)
		);

		// Per-context capability gate (e.g. admin context requires manage_options).
		$cap_ok = $this->policy->check_capability( $user, $context );
		if ( is_wp_error( $cap_ok ) ) {
			autorizenter_log(
				'capability denied',
				array(
					'user_id' => $user->ID,
					'context' => $context['id'],
				)
			);
			/**
			 * Fires when a user is denied entry to a context due to capability.
			 *
			 * @param \WP_User $user    The user.
			 * @param array    $context Resolved context.
			 */
			do_action( 'autorizenter_context_denied', $user, $context );
			return $this->attach_deny_redirect( $cap_ok, $context );
		}

		// Remember which provider/context this user last used (for SSO logout).
		update_user_meta( $user->ID, 'autorizenter_last_provider', $provider->id() );

		// Log the user in. wp_set_auth_cookie sends the Set-Cookie header, so this
		// must run before any output (the REST callback buffers output to protect
		// it). wp_login finalizes the session for core and other plugins.
		$headers_already_sent = headers_sent( $hs_file, $hs_line );
		autorizenter_log(
			'about to set auth cookie',
			array(
				'user_id'      => $user->ID,
				'headers_sent' => $headers_already_sent,
				'output_at'    => $headers_already_sent ? ( $hs_file . ':' . $hs_line ) : '',
				'is_ssl'       => is_ssl(),
			)
		);

		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true );
		do_action( 'wp_login', $user->user_login, $user );

		if ( ! headers_sent() ) {
			$set_cookie = array();
			foreach ( headers_list() as $header ) {
				if ( 0 === stripos( $header, 'Set-Cookie:' ) && false !== stripos( $header, 'wordpress_logged_in' ) ) {
					$set_cookie[] = 'wordpress_logged_in';
				}
			}
			autorizenter_log( 'auth cookie issued', array( 'logged_in_cookie_present' => ! empty( $set_cookie ) ) );
		}

		/**
		 * Fires after a successful Autorizenter login.
		 *
		 * @param \WP_User $user     The logged-in user.
		 * @param string   $provider Provider id.
		 * @param Identity $identity The identity used.
		 * @param array    $context  Resolved login context.
		 */
		do_action( 'autorizenter_login_success', $user, $provider->id(), $identity, $context );

		// Context redirect wins over return_to when configured.
		$destination = '' !== $context['redirect'] ? $context['redirect'] : (string) $return_to;

		autorizenter_log(
			'login complete',
			array(
				'user_id'     => $user->ID,
				'destination' => '' !== $destination ? $destination : home_url( '/' ),
			)
		);

		return array(
			'user'      => $user,
			'context'   => $context,
			'return_to' => $destination,
		);
	}

	/**
	 * Lazily build the jumbojett-backed OIDC client.
	 *
	 * @return Oidc_Client
	 */
	private function oidc_client() {
		if ( null === $this->oidc_client ) {
			$this->oidc_client = new Oidc_Client( $this->settings );
		}
		return $this->oidc_client;
	}

	/**
	 * Start the PHP session if one is not already active.
	 *
	 * Jumbojett stores OIDC state/nonce/PKCE in the session across the authorize
	 * and callback requests, so a session is required for the OIDC flow.
	 *
	 * @return void
	 */
	private function maybe_start_session() {
		if ( 'cli' === PHP_SAPI ) {
			return; // No sessions under CLI/test runs.
		}
		if ( PHP_SESSION_NONE === session_status() && ! headers_sent() ) {
			session_start();
		}
	}

	/**
	 * Read the in-flight OIDC session payload (provider/context/return_to).
	 *
	 * @return array|null
	 */
	private function oidc_session() {
		$this->maybe_start_session();
		if ( ! isset( $_SESSION[ self::SESSION_KEY ] ) || ! is_array( $_SESSION[ self::SESSION_KEY ] ) ) {
			return null;
		}
		// Plugin-controlled payload (written in begin_oidc); each value is
		// re-validated downstream (provider registry, get_context, return_to).
		return $_SESSION[ self::SESSION_KEY ]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	/**
	 * Clear the in-flight OIDC session payload (single-use).
	 *
	 * @return void
	 */
	private function clear_oidc_session() {
		if ( isset( $_SESSION[ self::SESSION_KEY ] ) ) {
			unset( $_SESSION[ self::SESSION_KEY ] );
		}
	}

	/**
	 * Log the current user out and return where to send the browser.
	 *
	 * Performs a local WordPress logout. If SSO logout is enabled (filter
	 * `autorizenter_sso_logout`) and the user's last provider supports RP-initiated
	 * logout (OIDC `end_session_endpoint`), returns the provider end-session URL so
	 * the IdP session is also terminated.
	 *
	 * @param string $return_to Post-logout destination (same-host).
	 * @return string URL to redirect to.
	 */
	public function logout( $return_to = '' ) {
		$user_id     = get_current_user_id();
		$provider_id = $user_id ? (string) get_user_meta( $user_id, 'autorizenter_last_provider', true ) : '';

		$destination = $this->sanitize_return_to( $return_to );
		$destination = '' !== $destination ? $destination : home_url( '/' );

		wp_logout();

		if ( '' !== $provider_id ) {
			/**
			 * Enable RP-initiated (single) logout at the identity provider.
			 *
			 * @param bool   $enabled     Default false (local logout only).
			 * @param string $provider_id Provider id.
			 */
			$sso = apply_filters( 'autorizenter_sso_logout', false, $provider_id );
			if ( $sso ) {
				$provider = $this->providers->get( $provider_id );
				if ( $provider && method_exists( $provider, 'end_session_url' ) ) {
					$url = $provider->end_session_url( $destination );
					if ( is_string( $url ) && '' !== $url ) {
						return $url;
					}
				}
			}
		}

		return $destination;
	}

	/**
	 * Attach the context's deny-redirect target to a WP_Error so the REST layer
	 * can send the user somewhere sensible.
	 *
	 * Fallback chain: context deny_redirect → global deny_redirect (already merged
	 * into the resolved context) → context login page (via filter) with an error.
	 *
	 * @param \WP_Error $error   Denial error.
	 * @param array     $context Resolved context.
	 * @return \WP_Error
	 */
	private function attach_deny_redirect( \WP_Error $error, array $context ) {
		if ( 'autorizenter_not_approved' === $error->get_error_code() ) {
			$data     = (array) $error->get_error_data();
			$provider = isset( $data['provider'] ) ? (string) $data['provider'] : '';
			$pending  = isset( $context['pending_redirect'] ) ? (string) $context['pending_redirect'] : '';

			/**
			 * Filter where an awaiting-approval (pending) user is sent. The UI uses
			 * this to route to the pre-approval questions form when questions apply
			 * to the login provider, and otherwise to the configured pending page
			 * (or a sensible default).
			 *
			 * @param string $pending  Configured pending_redirect (may be empty).
			 * @param string $provider Provider id the user signed in with.
			 * @param array  $context  Resolved context.
			 */
			$pending = (string) apply_filters( 'autorizenter_pending_redirect', $pending, $provider, $context );

			if ( '' !== $pending ) {
				$token            = isset( $data['pending_token'] ) ? (string) $data['pending_token'] : '';
				$url              = '' !== $token
					? add_query_arg( 'azr_pending_token', rawurlencode( $token ), $pending )
					: $pending;
				$data['redirect'] = $url;
				$error->add_data( $data );
				return $error;
			}
		}

		$target = isset( $context['deny_redirect'] ) ? (string) $context['deny_redirect'] : '';

		if ( '' === $target ) {
			/**
			 * Filter the login page URL used as the final deny fallback for a context.
			 *
			 * @param string $url        Default (wp_login_url()).
			 * @param string $context_id Context id.
			 */
			$login  = apply_filters( 'autorizenter_context_login_url', wp_login_url(), $context['id'] );
			$target = add_query_arg( 'autorizenter_error', rawurlencode( $error->get_error_code() ), $login );
		}

		$data             = (array) $error->get_error_data();
		$data['redirect'] = $target;
		$error->add_data( $data );
		return $error;
	}

	/**
	 * Generate a URL-safe random string.
	 *
	 * @param int $bytes Number of random bytes.
	 * @return string
	 */
	private function random( $bytes ) {
		return rtrim( strtr( base64_encode( random_bytes( $bytes ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Compute the S256 PKCE code challenge.
	 *
	 * @param string $verifier Code verifier.
	 * @return string
	 */
	private function pkce_challenge( $verifier ) {
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Transient key for a flow.
	 *
	 * @param string $state State value.
	 * @return string
	 */
	private function flow_key( $state ) {
		return 'autorizenter_flow_' . hash( 'sha256', $state );
	}

	/**
	 * Restrict the post-login redirect to the current host to prevent open redirects.
	 *
	 * @param string $return_to Candidate URL.
	 * @return string Safe URL or empty.
	 */
	private function sanitize_return_to( $return_to ) {
		$return_to = trim( (string) $return_to );
		if ( '' === $return_to ) {
			return '';
		}
		$safe = wp_validate_redirect( $return_to, '' );
		return $safe;
	}
}
