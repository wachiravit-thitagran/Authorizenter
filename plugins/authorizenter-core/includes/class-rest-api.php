<?php
/**
 * REST API surface for Authorizenter.
 *
 * @package Authorizenter\Core
 */

namespace Authorizenter\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes the contract the UI (or any front-end) consumes.
 *
 * Routes (namespace authorizenter/v1):
 *   GET  /providers          List enabled providers + their authorize URLs.
 *   GET  /authorize/{id}     302 redirect into the provider (browser entry point).
 *   GET  /callback           Provider redirect target; completes login.
 *   GET  /questions          Pending questions for the current user.
 *   POST /answers            Submit answers (nonce-protected).
 */
class Rest_Api {

	/**
	 * OAuth engine.
	 *
	 * @var OAuth_Engine
	 */
	private $engine;

	/**
	 * Providers.
	 *
	 * @var Provider_Registry
	 */
	private $providers;

	/**
	 * Questions.
	 *
	 * @var Questions
	 */
	private $questions;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Reports.
	 *
	 * @var Reports
	 */
	private $reports;

	/**
	 * Constructor.
	 *
	 * @param OAuth_Engine      $engine    Engine.
	 * @param Provider_Registry $providers Providers.
	 * @param Questions         $questions Questions.
	 * @param Settings          $settings  Settings store.
	 * @param Reports           $reports   Reports aggregator.
	 */
	public function __construct( OAuth_Engine $engine, Provider_Registry $providers, Questions $questions, Settings $settings, Reports $reports ) {
		$this->engine    = $engine;
		$this->providers = $providers;
		$this->questions = $questions;
		$this->settings  = $settings;
		$this->reports   = $reports;
	}

	/**
	 * Register all routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$ns = AUTHORIZENTER_REST_NAMESPACE;

		register_rest_route(
			$ns,
			'/providers',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_providers' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'context' => array( 'sanitize_callback' => 'sanitize_key' ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/authorize/(?P<provider>[a-z0-9_\-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'authorize' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'provider'  => array( 'sanitize_callback' => 'sanitize_key' ),
					'return_to' => array( 'sanitize_callback' => 'esc_url_raw' ),
					'context'   => array( 'sanitize_callback' => 'sanitize_key' ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/callback',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'callback' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			$ns,
			'/logout',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'logout' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'return_to' => array( 'sanitize_callback' => 'esc_url_raw' ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/questions',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_questions' ),
				'permission_callback' => function () {
					return is_user_logged_in();
				},
			)
		);

		register_rest_route(
			$ns,
			'/answers',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'post_answers' ),
				'permission_callback' => function () {
					return is_user_logged_in();
				},
			)
		);

		register_rest_route(
			$ns,
			'/pending/answers',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'pending_answers' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token'   => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'answers' => array(),
				),
			)
		);

		register_rest_route(
			$ns,
			'/answers/report',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'answers_report' ),
				'permission_callback' => function () {
					return current_user_can( 'list_users' );
				},
			)
		);
	}

	/**
	 * POST /pending/answers — store pre-approval answers for a not-yet-logged-in user.
	 *
	 * No authentication required; the one-time token from the pending redirect URL
	 * proves the user was legitimately placed in pending state.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function pending_answers( \WP_REST_Request $request ) {
		$token   = (string) $request->get_param( 'token' );
		$answers = $request->get_param( 'answers' );
		$answers = is_array( $answers ) ? $answers : array();

		$access = new Access_List( $this->settings );
		$result = $access->save_pending_answers( $token, $answers );
		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result );
		}

		return new \WP_REST_Response( array( 'saved' => true ), 200 );
	}

	/**
	 * GET /answers/report — per-question aggregates (admin only).
	 *
	 * @return \WP_REST_Response
	 */
	public function answers_report() {
		return new \WP_REST_Response( array( 'report' => $this->reports->summary() ), 200 );
	}

	/**
	 * GET /providers.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_providers( \WP_REST_Request $request ) {
		$context_id = (string) $request->get_param( 'context' );
		$context_id = '' !== $context_id ? $context_id : 'default';
		$context    = $this->settings->get_context( $context_id );

		$out = array();
		foreach ( $this->providers->enabled_for_context( $context ) as $id => $provider ) {
			$out[] = array(
				'id'            => $id,
				'label'         => $provider->label(),
				'authorize_url' => add_query_arg(
					'context',
					$context_id,
					rest_url( AUTHORIZENTER_REST_NAMESPACE . '/authorize/' . $id )
				),
			);
		}
		return new \WP_REST_Response(
			array(
				'context'   => $context_id,
				'providers' => $out,
			),
			200
		);
	}

	/**
	 * GET /authorize/{provider} — redirect the browser into the provider.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|void
	 */
	public function authorize( \WP_REST_Request $request ) {
		$provider  = $request->get_param( 'provider' );
		$return_to = (string) $request->get_param( 'return_to' );

		if ( '' === $return_to && isset( $_COOKIE['authorizenter_redirect'] ) ) {
			$return_to = wp_validate_redirect( esc_url_raw( wp_unslash( $_COOKIE['authorizenter_redirect'] ) ), '' );
			setcookie( 'authorizenter_redirect', '', time() - 3600, '/' );
		}

		$context = (string) $request->get_param( 'context' );
		$context = '' !== $context ? $context : 'default';

		$url = $this->engine->begin( $provider, $return_to, $context );
		if ( is_wp_error( $url ) ) {
			return $this->error_response( $url );
		}

		// Fail loudly instead of a blank page when no URL could be built.
		if ( ! is_string( $url ) || '' === $url ) {
			return $this->error_response(
				new \WP_Error(
					'authorizenter_no_authorize_url',
					__( 'The provider did not return a sign-in URL. Check that the provider is configured (client ID, and for OIDC a reachable discovery URL).', 'authorizenter' ),
					array( 'status' => 500 )
				)
			);
		}

		// External IdP URL by design.
		$this->redirect_to( $url, false );
	}

	/**
	 * GET /callback — complete the login then redirect.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|void
	 */
	public function callback( \WP_REST_Request $request ) {
		// Buffer any stray output produced while completing the login (notices from
		// other plugins, the IdP HTTP round-trip, provisioning hooks, ...). Without
		// this, such output would mark the headers as sent and silently prevent
		// wp_set_auth_cookie() from issuing the login cookie — the user would return
		// to the site without a session. The buffer is discarded before we redirect.
		ob_start();

		// Provider-reported error (user denied, etc.).
		$provider_error = $request->get_param( 'error' );
		if ( $provider_error ) {
			$this->redirect_with_error( sanitize_text_field( $provider_error ) );
		}

		$code  = (string) $request->get_param( 'code' );
		$state = (string) $request->get_param( 'state' );

		$result = $this->engine->handle_callback( $code, $state );
		if ( is_wp_error( $result ) ) {
			// The engine may attach a deny-redirect target (e.g. context fallback).
			$data = (array) $result->get_error_data();
			if ( ! empty( $data['redirect'] ) ) {
				$this->redirect_to( $data['redirect'], true );
			}
			$this->redirect_with_error( $result->get_error_code() );
		}

		$user      = $result['user'];
		$return_to = '' !== $result['return_to'] ? $result['return_to'] : home_url( '/' );

		// If questions are pending (scoped to the provider used), send the user to
		// the questions page first.
		$login_provider = (string) get_user_meta( $user->ID, 'authorizenter_last_provider', true );
		if ( $this->questions->has_pending_required( $user->ID, $login_provider ) ) {
			$questions_url = apply_filters( 'authorizenter_questions_url', '', $return_to );
			if ( '' !== $questions_url ) {
				$this->redirect_to( add_query_arg( 'return_to', rawurlencode( $return_to ), $questions_url ), true );
			}
		}

		/**
		 * Filter the final post-login redirect target.
		 *
		 * @param string   $return_to Destination URL.
		 * @param \WP_User $user      User.
		 */
		$return_to = apply_filters( 'authorizenter_post_login_redirect', $return_to, $user );

		$this->redirect_to( $return_to, true );
	}

	/**
	 * GET /logout — end the session, then redirect (optionally via the IdP).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return void
	 */
	public function logout( \WP_REST_Request $request ) {
		$return_to = (string) $request->get_param( 'return_to' );
		$url       = $this->engine->logout( $return_to );
		if ( ! is_string( $url ) || '' === $url ) {
			$url = home_url( '/' );
		}

		// $url may be an external IdP end-session endpoint (validated same-host URL
		// or a configured provider URL), so the unsafe redirect is intentional.
		$this->redirect_to( $url, false );
	}

	/**
	 * GET /questions — pending questions for current user.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_questions() {
		$user_id = get_current_user_id();
		return new \WP_REST_Response(
			array(
				'questions' => $this->questions->pending_for_user( $user_id ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
			),
			200
		);
	}

	/**
	 * POST /answers — save answers for current user.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function post_answers( \WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$input   = $request->get_param( 'answers' );
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$result = $this->questions->save_answers( $user_id, $input );
		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result );
		}

		return new \WP_REST_Response(
			array(
				'saved'   => true,
				'pending' => $this->questions->pending_for_user( $user_id ),
			),
			200
		);
	}

	/**
	 * Convert a WP_Error into a REST response.
	 *
	 * @param \WP_Error $error Error.
	 * @return \WP_REST_Response
	 */
	private function error_response( \WP_Error $error ) {
		// Drop any buffered output so the JSON body is clean.
		$this->discard_buffers();

		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
		return new \WP_REST_Response(
			array(
				'error'   => $error->get_error_code(),
				'message' => $error->get_error_message(),
			),
			$status
		);
	}

	/**
	 * Redirect the browser back to the login page with an error code.
	 *
	 * @param string $error_code Error code.
	 * @return never
	 */
	private function redirect_with_error( $error_code ) {
		$base = apply_filters( 'authorizenter_login_url', wp_login_url() );
		$this->redirect_to( add_query_arg( 'authorizenter_error', rawurlencode( $error_code ), $base ), true );
	}

	/**
	 * Redirect the browser and terminate.
	 *
	 * A plain `wp_redirect(); exit;` in a REST callback fails silently — a blank
	 * page with no error — when a 302 `Location` header cannot be sent because
	 * output already started (a stray notice, whitespace, or BOM ahead of us).
	 * To stay robust we send the header when possible AND always print a tiny
	 * meta-refresh + JS fallback so the browser still navigates either way.
	 *
	 * @param string $url  Destination URL.
	 * @param bool   $safe Restrict to allowed hosts (wp_safe_redirect) when true.
	 * @return never
	 */
	private function redirect_to( $url, $safe = false ) {
		$url = (string) $url;

		// Throw away buffered output so it cannot flush and "send" the headers,
		// which would block the Location header (and any auth cookie set earlier).
		$this->discard_buffers();

		nocache_headers();

		if ( ! headers_sent() ) {
			if ( $safe ) {
				wp_safe_redirect( $url );
			} else {
				wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- may target an external IdP by design.
			}
		}

		// Fallback navigation for the headers-already-sent case (harmless once a
		// 302 has been sent — the browser follows the header and ignores this body).
		printf(
			'<!DOCTYPE html><html><head><meta charset="utf-8"><meta http-equiv="refresh" content="0;url=%1$s"><script>window.location.replace(%2$s);</script></head><body><p><a href="%1$s">%3$s</a></p></body></html>',
			esc_url( $url ),
			wp_json_encode( $url ),
			esc_html__( 'Continue', 'authorizenter' )
		);
		exit;
	}

	/**
	 * Discard all active output buffers without flushing them.
	 *
	 * @return void
	 */
	private function discard_buffers() {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
	}
}
