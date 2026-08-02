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
 * a password, so a misconfigured or unreachable IdP cannot lock everyone out. That
 * door is deliberately narrow: it opens only on `wp-login.php?external=wordpress`.
 * An administrator password is therefore useless everywhere else — a themed or
 * plugin-supplied login form, REST, XML-RPC, application passwords — because the
 * bypass is scoped to the request, not just to the capability. Turn the bypass off
 * once SSO is confirmed working and no password opens any door at all.
 *
 * Any authentication that submits a username and password runs through the
 * `authenticate` filter, which is what this class hooks. Cookie auth and the SSO
 * flow use different code paths and are untouched.
 *
 * Away from that one URL every rejection is identical, whether the password was
 * right, wrong, or the account does not exist. A distinguishable "password is
 * disabled for you" answer would confirm valid credentials to anyone replaying a
 * stolen list, so the block deliberately gives nothing away.
 *
 * This is self-contained: it needs no cooperation from the theme. Note that a
 * theme or plugin which redirects `wp-login.php` to its own branded page will also
 * redirect this URL — that is the theme's redirect to exempt, and Authorizenter
 * does not fight it, because silently cancelling another extension's login
 * redirect would break sites that rely on it.
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
		// wp-login.php posts to itself without the query string, so carry the
		// escape-hatch marker through the submission as a hidden field.
		add_action( 'login_form', array( $this, 'print_escape_hatch_field' ) );
	}

	/**
	 * Whether this request is the administrator escape hatch.
	 *
	 * Authorizer-style: append `?external=wordpress` to the login URL. Two things
	 * must both hold — the marker is present, and we really are on `wp-login.php`.
	 * The second half matters: the marker is read from `$_REQUEST` so it survives
	 * the form POST, and without the page check any third-party login form could
	 * smuggle `external=wordpress` into its own submission and reopen the bypass.
	 *
	 * `$GLOBALS['pagenow']` is set by WordPress for both the GET and the POST of
	 * wp-login.php, which is why it is used instead of inspecting the request URI.
	 *
	 * @return bool
	 */
	private function is_escape_hatch() {
		if ( ! isset( $GLOBALS['pagenow'] ) || 'wp-login.php' !== $GLOBALS['pagenow'] ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$marker = isset( $_REQUEST['external'] ) ? sanitize_key( wp_unslash( $_REQUEST['external'] ) ) : '';

		return 'wordpress' === $marker;
	}

	/**
	 * Keep the escape-hatch marker on the credential form so it is still present
	 * when the browser POSTs back to wp-login.php.
	 *
	 * @return void
	 */
	public function print_escape_hatch_field() {
		if ( ! $this->is_disabled() || ! $this->is_escape_hatch() ) {
			return;
		}

		echo '<input type="hidden" name="external" value="wordpress" />';
	}

	/**
	 * Whether the administrator safety valve is switched on.
	 *
	 * @return bool
	 */
	private function bypass_enabled() {
		$adv = $this->settings->get( 'advanced' );

		return ! empty( $adv['password_auth_admin_bypass'] );
	}

	/**
	 * Whether a submitted login/email belongs to an administrator.
	 *
	 * Resolved from the submitted name rather than from the authentication result,
	 * so a wrong password on the escape hatch can still be reported honestly to an
	 * administrator while everyone else gets the indistinguishable answer.
	 *
	 * @param string $username Submitted username or email.
	 * @return bool
	 */
	private function username_is_admin( $username ) {
		$user = get_user_by( 'login', $username );

		if ( ! $user && false !== strpos( $username, '@' ) ) {
			$user = get_user_by( 'email', $username );
		}

		return $user instanceof \WP_User && user_can( $user, 'manage_options' );
	}

	/**
	 * Hide the username/password fields on wp-login.php when password auth is
	 * disabled (so users are pushed to SSO). The server-side block in maybe_block()
	 * is the real enforcement; this only removes the now-useless form.
	 *
	 * @return void
	 */
	public function maybe_hide_form() {
		if ( ! $this->is_disabled() || $this->is_escape_hatch() ) {
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
		// Password auth is allowed for everyone.
		if ( ! $this->is_disabled() ) {
			return $user;
		}

		// Safety valve, scoped to the escape-hatch URL: an administrator signing in
		// there gets WordPress's own verdict, including a truthful "wrong password".
		if ( $this->is_escape_hatch() && $this->bypass_enabled() && $this->username_is_admin( $username ) ) {
			return $user;
		}

		// One answer for every other case — correct password, wrong password, or no
		// such account — so nothing here confirms a credential or an account.
		return new \WP_Error(
			'authorizenter_password_disabled',
			__( 'Password sign-in is disabled for this site. Please sign in with single sign-on.', 'authorizenter' )
		);
	}
}
