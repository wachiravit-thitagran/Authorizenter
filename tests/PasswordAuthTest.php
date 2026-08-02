<?php
/**
 * Tests for disabling username/password sign-in.
 *
 * @package Authorizenter\Core\Tests
 */

namespace Authorizenter\Core\Tests;

use Authorizenter\Core\Settings;
use Authorizenter\Core\Password_Auth;
use PHPUnit\Framework\TestCase;

class PasswordAuthTest extends TestCase {

	protected function setUp(): void {
		azr_test_reset();
		unset( $GLOBALS['pagenow'], $_REQUEST['external'], $_GET['external'], $_POST['external'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['pagenow'], $_REQUEST['external'], $_GET['external'], $_POST['external'] );
		parent::tearDown();
	}

	private function auth( array $advanced ): Password_Auth {
		update_option( Settings::OPTION, array( 'advanced' => $advanced ) );
		return new Password_Auth( new Settings() );
	}

	/**
	 * Put the request on wp-login.php with the escape-hatch marker.
	 *
	 * @param string $method 'GET' or 'POST' — the marker travels as a hidden field
	 *                       on submission, so both must be recognised.
	 */
	private function on_escape_hatch( string $method = 'GET' ): void {
		$GLOBALS['pagenow'] = 'wp-login.php';
		if ( 'POST' === $method ) {
			$_POST['external'] = 'wordpress';
		} else {
			$_GET['external'] = 'wordpress';
		}
		$_REQUEST['external'] = 'wordpress';
	}

	public function test_password_login_allowed_by_default(): void {
		$auth = $this->auth( array() ); // disable_password_auth defaults false.
		$user = azr_test_make_user( 1, array( 'read' => true ) );

		$this->assertSame( $user, $auth->maybe_block( $user, 'user1', 'secret' ) );
	}

	public function test_password_login_blocked_when_disabled(): void {
		$auth = $this->auth( array( 'disable_password_auth' => true, 'password_auth_admin_bypass' => false ) );
		$user = azr_test_make_user( 2, array( 'read' => true ) );

		$result = $auth->maybe_block( $user, 'user2', 'secret' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'authorizenter_password_disabled', $result->get_error_code() );
	}

	public function test_admin_bypass_lets_admins_in_on_the_escape_hatch(): void {
		$auth  = $this->auth( array( 'disable_password_auth' => true, 'password_auth_admin_bypass' => true ) );
		$admin = azr_test_make_user( 3, array( 'manage_options' => true, 'read' => true ) );
		$this->on_escape_hatch();

		$this->assertSame( $admin, $auth->maybe_block( $admin, $admin->user_login, 'secret' ) );
	}

	/**
	 * The marker arrives as a hidden field on the POST, because wp-login.php posts
	 * to itself without the query string.
	 */
	public function test_admin_bypass_survives_the_form_post(): void {
		$auth  = $this->auth( array( 'disable_password_auth' => true, 'password_auth_admin_bypass' => true ) );
		$admin = azr_test_make_user( 31, array( 'manage_options' => true, 'read' => true ) );
		$this->on_escape_hatch( 'POST' );

		$this->assertSame( $admin, $auth->maybe_block( $admin, $admin->user_login, 'secret' ) );
	}

	public function test_admin_may_sign_in_with_the_email_address(): void {
		$auth  = $this->auth( array( 'disable_password_auth' => true, 'password_auth_admin_bypass' => true ) );
		$admin = azr_test_make_user( 32, array( 'manage_options' => true, 'read' => true ) );
		$this->on_escape_hatch();

		$this->assertSame( $admin, $auth->maybe_block( $admin, $admin->user_email, 'secret' ) );
	}

	/**
	 * The point of the whole change: an administrator password must be useless on
	 * any other login form — a theme's, Tutor LMS's, REST, XML-RPC.
	 */
	public function test_admin_bypass_is_refused_away_from_the_escape_hatch(): void {
		$auth  = $this->auth( array( 'disable_password_auth' => true, 'password_auth_admin_bypass' => true ) );
		$admin = azr_test_make_user( 33, array( 'manage_options' => true, 'read' => true ) );

		$result = $auth->maybe_block( $admin, $admin->user_login, 'secret' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'authorizenter_password_disabled', $result->get_error_code() );
	}

	/**
	 * A third-party form cannot smuggle the marker into its own submission: the
	 * request must actually be on wp-login.php.
	 */
	public function test_marker_alone_does_not_open_the_bypass(): void {
		$auth  = $this->auth( array( 'disable_password_auth' => true, 'password_auth_admin_bypass' => true ) );
		$admin = azr_test_make_user( 34, array( 'manage_options' => true, 'read' => true ) );

		$GLOBALS['pagenow']   = 'index.php';
		$_REQUEST['external'] = 'wordpress';
		$_POST['external']    = 'wordpress';

		$this->assertInstanceOf( \WP_Error::class, $auth->maybe_block( $admin, $admin->user_login, 'secret' ) );
	}

	public function test_escape_hatch_still_refuses_admins_when_the_bypass_is_off(): void {
		$auth  = $this->auth( array( 'disable_password_auth' => true, 'password_auth_admin_bypass' => false ) );
		$admin = azr_test_make_user( 35, array( 'manage_options' => true, 'read' => true ) );
		$this->on_escape_hatch();

		$this->assertInstanceOf( \WP_Error::class, $auth->maybe_block( $admin, $admin->user_login, 'secret' ) );
	}

	public function test_non_admin_is_refused_on_the_escape_hatch_too(): void {
		$auth = $this->auth( array( 'disable_password_auth' => true, 'password_auth_admin_bypass' => true ) );
		$user = azr_test_make_user( 36, array( 'read' => true ) );
		$this->on_escape_hatch();

		$this->assertInstanceOf( \WP_Error::class, $auth->maybe_block( $user, $user->user_login, 'secret' ) );
	}

	/**
	 * No credential oracle: a valid non-admin password and a wrong password must be
	 * indistinguishable, otherwise the endpoint validates stolen credential lists.
	 */
	public function test_wrong_and_correct_non_admin_passwords_answer_identically(): void {
		$auth = $this->auth( array( 'disable_password_auth' => true, 'password_auth_admin_bypass' => true ) );
		$user = azr_test_make_user( 37, array( 'read' => true ) );
		$this->on_escape_hatch();

		$correct = $auth->maybe_block( $user, $user->user_login, 'right-password' );
		$wrong   = $auth->maybe_block( new \WP_Error( 'incorrect_password', 'Wrong.' ), $user->user_login, 'nope' );
		$unknown = $auth->maybe_block( new \WP_Error( 'invalid_username', 'Unknown.' ), 'nobody', 'nope' );

		$this->assertSame( $correct->get_error_code(), $wrong->get_error_code() );
		$this->assertSame( $correct->get_error_message(), $wrong->get_error_message() );
		$this->assertSame( $correct->get_error_code(), $unknown->get_error_code() );
		$this->assertSame( $correct->get_error_message(), $unknown->get_error_message() );
	}

	/**
	 * An administrator mistyping a password on the emergency door needs the real
	 * reason, not the generic SSO notice.
	 */
	public function test_admin_gets_the_real_error_on_the_escape_hatch(): void {
		$auth = $this->auth( array( 'disable_password_auth' => true, 'password_auth_admin_bypass' => true ) );
		azr_test_make_user( 38, array( 'manage_options' => true, 'read' => true ) );
		$this->on_escape_hatch();

		$core = new \WP_Error( 'incorrect_password', 'Wrong.' );

		$this->assertSame( $core, $auth->maybe_block( $core, 'user38', 'nope' ) );
	}

	public function test_hidden_field_is_printed_only_on_the_escape_hatch(): void {
		$auth = $this->auth( array( 'disable_password_auth' => true, 'password_auth_admin_bypass' => true ) );

		ob_start();
		$auth->print_escape_hatch_field();
		$without = ob_get_clean();

		$this->on_escape_hatch();
		ob_start();
		$auth->print_escape_hatch_field();
		$with = ob_get_clean();

		$this->assertSame( '', $without );
		$this->assertStringContainsString( 'name="external"', $with );
		$this->assertStringContainsString( 'value="wordpress"', $with );
	}

	public function test_hidden_field_is_not_printed_when_password_auth_is_allowed(): void {
		$auth = $this->auth( array() );
		$this->on_escape_hatch();

		ob_start();
		$auth->print_escape_hatch_field();

		$this->assertSame( '', ob_get_clean() );
	}

	public function test_credential_form_is_hidden_unless_the_hatch_is_used(): void {
		$auth = $this->auth( array( 'disable_password_auth' => true ) );

		ob_start();
		$auth->maybe_hide_form();
		$hidden = ob_get_clean();

		$this->on_escape_hatch();
		ob_start();
		$auth->maybe_hide_form();
		$revealed = ob_get_clean();

		$this->assertStringContainsString( 'authorizenter-hide-login', $hidden );
		$this->assertSame( '', $revealed );
	}

	public function test_non_admin_still_blocked_with_bypass_on(): void {
		$auth = $this->auth( array( 'disable_password_auth' => true, 'password_auth_admin_bypass' => true ) );
		$user = azr_test_make_user( 4, array( 'read' => true ) );

		$this->assertInstanceOf( \WP_Error::class, $auth->maybe_block( $user, 'user4', 'secret' ) );
	}

	public function test_empty_password_is_ignored(): void {
		$auth = $this->auth( array( 'disable_password_auth' => true ) );
		$user = azr_test_make_user( 5, array( 'read' => true ) );

		// Cookie auth / no password attempt — must pass through untouched.
		$this->assertSame( $user, $auth->maybe_block( $user, '', '' ) );
	}

	public function test_existing_error_is_normalised_when_password_auth_is_disabled(): void {
		$auth  = $this->auth( array( 'disable_password_auth' => true ) );
		$error = new \WP_Error( 'incorrect_password', 'bad' );

		$result = $auth->maybe_block( $error, 'user', 'wrong' );

		$this->assertNotSame( $error, $result );
		$this->assertSame( 'authorizenter_password_disabled', $result->get_error_code() );
	}

	public function test_existing_error_passes_through_when_password_auth_is_allowed(): void {
		$auth  = $this->auth( array() );
		$error = new \WP_Error( 'incorrect_password', 'bad' );

		$this->assertSame( $error, $auth->maybe_block( $error, 'user', 'wrong' ) );
	}
}
