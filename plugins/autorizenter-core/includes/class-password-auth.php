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
		<style id="autorizenter-hide-login">
			/* Hide every credential row on wp-login.php (and wp_login_form output):
			   username, password, remember-me and submit — without relying on :has(). */
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

		// Offer the admin escape hatch to the password form (for the bypass) when
		// it is enabled and the form is currently hidden.
		$adv = $this->settings->get( 'advanced' );
		if ( ! empty( $adv['password_auth_admin_bypass'] ) && ! $this->form_revealed() ) {
			$url     = add_query_arg( 'external', 'wordpress', wp_login_url() );
			$notice .= '<p class="message"><a href="' . esc_url( $url ) . '">' .
				esc_html__( 'Administrator? Sign in with a password.', 'autorizenter' ) .
				'</a></p>';
		}

		return $message . $notice;
	}
}
