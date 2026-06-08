<?php
/**
 * Optionally disables WordPress username/password sign-in (force SSO).
 *
 * @package Autorizenter\Core
 */

namespace Autorizenter\Core;

defined( 'ABSPATH' ) || exit;

/**
 * When enabled, blocks interactive username/password logins so users must sign in
 * through a configured provider.
 *
 * A safety valve keeps administrators (the `manage_options` capability) able to use
 * a password, so a misconfigured or unreachable IdP cannot lock everyone out. This
 * bypass can be turned off once SSO is confirmed working.
 *
 * Only interactive password attempts are affected — cookie auth, application
 * passwords, and the SSO flow itself use different code paths and are untouched.
 */
class Password_Auth {

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
		// Run after WP's own username/password checks (priority 20).
		add_filter( 'authenticate', array( $this, 'maybe_block' ), 30, 3 );
		add_filter( 'login_message', array( $this, 'login_notice' ) );
	}

	/**
	 * Whether password auth is disabled.
	 *
	 * @return bool
	 */
	private function is_disabled() {
		$adv      = $this->settings->get( 'advanced' );
		$disabled = ! empty( $adv['disable_password_auth'] );

		/**
		 * Filter whether username/password sign-in is disabled.
		 *
		 * @param bool $disabled Current setting.
		 */
		return (bool) apply_filters( 'autorizenter_disable_password_auth', $disabled );
	}

	/**
	 * Block a successful password authentication when disabled.
	 *
	 * @param null|\WP_User|\WP_Error $user     Authentication result so far.
	 * @param string                  $username Submitted username.
	 * @param string                  $password Submitted password.
	 * @return null|\WP_User|\WP_Error
	 */
	public function maybe_block( $user, $username, $password ) {
		// Not an interactive password attempt — leave untouched.
		if ( '' === (string) $username || '' === (string) $password ) {
			return $user;
		}
		// Already failing, or password auth is allowed.
		if ( is_wp_error( $user ) || ! $this->is_disabled() ) {
			return $user;
		}

		// Safety valve: let administrators keep using a password.
		$adv = $this->settings->get( 'advanced' );
		if ( ! empty( $adv['password_auth_admin_bypass'] ) && $user instanceof \WP_User && user_can( $user, 'manage_options' ) ) {
			return $user;
		}

		return new \WP_Error(
			'autorizenter_password_disabled',
			__( 'Password sign-in is disabled for this site. Please sign in with single sign-on.', 'autorizenter' )
		);
	}

	/**
	 * Show a notice on the WordPress login form when password auth is disabled.
	 *
	 * @param string $message Existing message HTML.
	 * @return string
	 */
	public function login_notice( $message ) {
		if ( ! $this->is_disabled() ) {
			return $message;
		}
		$notice = '<p class="message">' . esc_html__( 'This site uses single sign-on. Password login is disabled.', 'autorizenter' ) . '</p>';
		return $message . $notice;
	}
}
