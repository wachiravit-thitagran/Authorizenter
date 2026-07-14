<?php
/**
 * Optionally disables WordPress username/password sign-in (force SSO).
 *
 * @package Authorizenter\Core
 */

namespace Authorizenter\Core;

defined( 'ABSPATH' ) || exit;

/**
 * When enabled, blocks interactive username/password logins so users must sign in
 * through a configured provider.
 *
 * A safety valve keeps administrators (the `manage_options` capability) able to use
 * a password, so a misconfigured or unreachable IdP cannot lock everyone out. This
 * bypass can be turned off once SSO is confirmed working.
 *
 * Any authentication that submits a username and password is affected — this
 * includes interactive wp-login.php sign-ins as well as password-based API auth
 * (REST/XML-RPC and application passwords), all of which run through the
 * `authenticate` filter. Cookie auth and the SSO flow itself use different code
 * paths and are untouched. The administrator bypass below still applies, so
 * admins can continue to use passwords (including application passwords).
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
		add_action( 'login_head', array( $this, 'maybe_hide_form' ) );
	}

	/**
	 * Whether the WordPress credential form should be revealed even though
	 * password sign-in is disabled.
	 *
	 * Uses the Authorizer-style escape hatch: append ?external=wordpress to the
	 * login URL. Lets an administrator reach the password form for the bypass even
	 * when the form is otherwise hidden.
	 *
	 * @return bool
	 */
	private function form_revealed() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['external'] ) && 'wordpress' === $_GET['external'];
	}

	/**
	 * Hide the username/password fields on wp-login.php when password auth is
	 * disabled (so users are pushed to SSO). The server-side block in maybe_block()
	 * is the real enforcement; this only removes the now-useless form.
	 *
	 * @return void
	 */
	public function maybe_hide_form() {
		if ( ! $this->is_disabled() || $this->form_revealed() ) {
			return;
		}
		?>
		<style id="authorizenter-hide-login">
			/* Hide credential rows (username/password/remember/submit) on wp-login.php and wp_login_form output, without relying on :has(). */
			#loginform > p,
			#loginform .user-pass-wrap,
			#loginform .forgetmenot,
			#loginform .submit,
			#loginform .login-username,
			#loginform .login-password,
			#loginform .login-remember,
			#loginform .login-submit { display: none !important; }
		</style>
		<?php
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
		return (bool) apply_filters( 'authorizenter_disable_password_auth', $disabled );
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
		// No credentials submitted (e.g. cookie auth) — leave untouched.
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
			'authorizenter_password_disabled',
			__( 'Password sign-in is disabled for this site. Please sign in with single sign-on.', 'authorizenter' )
		);
	}
}
