<?php
/**
 * REST API surface for Autorizenter.
 *
 * @package Autorizenter\Core
 */

namespace Autorizenter\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes the contract the UI (or any front-end) consumes.
 *
 * Routes (namespace autorizenter/v1):
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
		$ns = AUTORIZENTER_REST_NAMESPACE;

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
					'token'   => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
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
					rest_url( AUTORIZENTER_REST_NAMESPACE . '/authorize/' . $id )
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
		$context   = (string) $request->get_param( 'context' );
		$context   = '' !== $context ? $context : 'default';

		$url = $this->engine->begin( $provider, $return_to, $context );
		if ( is_wp_error( $url ) ) {
			return $this->error_response( $url );
		}

		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- external IdP URL by design.
		exit;
	}

	/**
	 * GET /callback — complete the login then redirect.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|void
	 */
	public function callback( \WP_REST_Request $request ) {
		// Provider-reported error (user denied, etc.).
		$provider_error = $request->get_param( 'error' );
		if ( $provider_error ) {
			return $this->redirect_with_error( sanitize_text_field( $provider_error ) );
		}

		$code  = (string) $request->get_param( 'code' );
		$state = (string) $request->get_param( 'state' );

		$result = $this->engine->handle_callback( $code, $state );
		if ( is_wp_error( $result ) ) {
			// The engine may attach a deny-redirect target (e.g. context fallback).
			$data = (array) $result->get_error_data();
			if ( ! empty( $data['redirect'] ) ) {
				wp_safe_redirect( $data['redirect'] );
				exit;
			}
			return $this->redirect_with_error( $result->get_error_code() );
		}

		$user      = $result['user'];
		$return_to = '' !== $result['return_to'] ? $result['return_to'] : home_url( '/' );

		// If questions are pending, send the user to the questions page first.
		if ( $this->questions->has_pending_required( $user->ID ) ) {
			$questions_url = apply_filters( 'autorizenter_questions_url', '', $return_to );
			if ( '' !== $questions_url ) {
				wp_safe_redirect( add_query_arg( 'return_to', rawurlencode( $return_to ), $questions_url ) );
				exit;
			}
		}

		/**
		 * Filter the final post-login redirect target.
		 *
		 * @param string   $return_to Destination URL.
		 * @param \WP_User $user      User.
		 */
		$return_to = apply_filters( 'autorizenter_post_login_redirect', $return_to, $user );

		wp_safe_redirect( $return_to );
		exit;
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

		// $url may be an external IdP end-session endpoint, so wp_redirect is used;
		// the value is either a validated same-host URL or a configured provider URL.
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
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
	 * @return void
	 */
	private function redirect_with_error( $error_code ) {
		$base = apply_filters( 'autorizenter_login_url', wp_login_url() );
		wp_safe_redirect( add_query_arg( 'autorizenter_error', rawurlencode( $error_code ), $base ) );
		exit;
	}
}
