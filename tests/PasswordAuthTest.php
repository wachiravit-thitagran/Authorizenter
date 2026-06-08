<?php
/**
 * Tests for disabling username/password sign-in.
 *
 * @package Autorizenter\Core\Tests
 */

namespace Autorizenter\Core\Tests;

use Autorizenter\Core\Settings;
use Autorizenter\Core\Password_Auth;
use PHPUnit\Framework\TestCase;

class PasswordAuthTest extends TestCase {

	protected function setUp(): void {
		azr_test_reset();
	}

	private function auth( array $advanced ): Password_Auth {
		update_option( Settings::OPTION, array( 'advanced' => $advanced ) );
		return new Password_Auth( new Settings() );
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
		$this->assertSame( 'autorizenter_password_disabled', $result->get_error_code() );
	}

	public function test_admin_bypass_lets_admins_in(): void {
		$auth  = $this->auth( array( 'disable_password_auth' => true, 'password_auth_admin_bypass' => true ) );
		$admin = azr_test_make_user( 3, array( 'manage_options' => true, 'read' => true ) );

		$this->assertSame( $admin, $auth->maybe_block( $admin, 'admin', 'secret' ) );
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

	public function test_existing_error_passes_through(): void {
		$auth  = $this->auth( array( 'disable_password_auth' => true ) );
		$error = new \WP_Error( 'incorrect_password', 'bad' );

		$this->assertSame( $error, $auth->maybe_block( $error, 'user', 'wrong' ) );
	}
}
