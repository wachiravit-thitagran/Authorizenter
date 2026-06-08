<?php
/**
 * Private site mode: require login to view the front-end.
 *
 * @package Autorizenter\Core
 */

namespace Autorizenter\Core;

defined( 'ABSPATH' ) || exit;

/**
 * When enabled, anonymous visitors are redirected to the login page before they
 * can view any front-end content (Authorizer-style "everyone must log in").
 *
 * The login page itself and anything allowed via the `autorizenter_private_allow`
 * filter remain reachable so users are not trapped in a redirect loop.
 */
class Private_Site {

	/**
	 * Settings store.
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
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ) );
	}

	/**
	 * Whether private mode is enabled.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		$p = $this->settings->get( 'private_site' );
		return ! empty( $p['enabled'] );
	}

	/**
	 * Pure decision: should this request be blocked for anonymous users?
	 *
	 * @param bool $is_logged_in   Whether a user is logged in.
	 * @param bool $is_login_page  Whether the current page is the login page.
	 * @param bool $is_allowed     Whether a filter explicitly allowed this request.
	 * @return bool
	 */
	public function should_block( $is_logged_in, $is_login_page, $is_allowed ) {
		if ( ! $this->is_enabled() ) {
			return false;
		}
		if ( $is_logged_in || $is_login_page || $is_allowed ) {
			return false;
		}
		return true;
	}

	/**
	 * Redirect anonymous visitors to the login page when private mode is on.
	 *
	 * @return void
	 */
	public function maybe_redirect() {
		if ( ! $this->is_enabled() || is_user_logged_in() ) {
			return;
		}

		$login_page_id = (int) apply_filters( 'autorizenter_login_page_id', 0 );
		$is_login_page = $login_page_id && function_exists( 'is_page' ) && is_page( $login_page_id );

		/**
		 * Allow specific front-end requests through while private mode is on.
		 *
		 * @param bool $allowed Default false.
		 */
		$is_allowed = (bool) apply_filters( 'autorizenter_private_allow', false );

		if ( ! $this->should_block( false, $is_login_page, $is_allowed ) ) {
			return;
		}

		$login = apply_filters( 'autorizenter_login_url', wp_login_url( home_url( add_query_arg( array() ) ) ) );
		wp_safe_redirect( $login );
		exit;
	}
}
