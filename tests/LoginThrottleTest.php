<?php
/**
 * Tests for failed-login throttling.
 *
 * @package Authorizenter\Core\Tests
 */

namespace Authorizenter\Core\Tests;

use Authorizenter\Core\Settings;
use Authorizenter\Core\Login_Throttle;
use PHPUnit\Framework\TestCase;

class LoginThrottleTest extends TestCase {

	protected function setUp(): void {
		azr_test_reset();
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
	}

	private function throttle( array $cfg ): Login_Throttle {
		update_option( Settings::OPTION, array( 'throttle' => $cfg ) );
		return new Login_Throttle( new Settings() );
	}

	public function test_not_locked_initially(): void {
		$t = $this->throttle( array( 'enabled' => true, 'max_attempts' => 3, 'lockout_seconds' => 60 ) );
		$this->assertFalse( $t->is_locked( $t->client_ip() ) );
	}

	public function test_locks_after_threshold(): void {
		$t = $this->throttle( array( 'enabled' => true, 'max_attempts' => 3, 'lockout_seconds' => 60 ) );

		$t->record_failure();
		$t->record_failure();
		$this->assertFalse( $t->is_locked( $t->client_ip() ) ); // 2 < 3.

		$t->record_failure();
		$this->assertTrue( $t->is_locked( $t->client_ip() ) ); // 3 >= 3.
	}

	public function test_maybe_block_returns_error_when_locked(): void {
		$t = $this->throttle( array( 'enabled' => true, 'max_attempts' => 1, 'lockout_seconds' => 60 ) );
		$t->record_failure(); // 1 >= 1 -> locked.

		$result = $t->maybe_block( null );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'authorizenter_locked_out', $result->get_error_code() );
	}

	public function test_disabled_never_locks(): void {
		$t = $this->throttle( array( 'enabled' => false, 'max_attempts' => 1 ) );
		$t->record_failure();
		$this->assertFalse( $t->is_locked( $t->client_ip() ) );
		$this->assertNull( $t->maybe_block( null ) );
	}

	public function test_lockout_is_progressive(): void {
		$t = $this->throttle( array( 'enabled' => true, 'max_attempts' => 1, 'lockout_seconds' => 60 ) );

		$t->record_failure();                       // over=1 -> ttl 60.
		$first = $t->lockout_until( $t->client_ip() );

		$t->record_failure();                       // over=2 -> ttl 120.
		$second = $t->lockout_until( $t->client_ip() );

		$this->assertGreaterThan( $first, $second );
	}

	public function test_maybe_block_passes_through_when_not_locked(): void {
		$t    = $this->throttle( array( 'enabled' => true, 'max_attempts' => 5, 'lockout_seconds' => 60 ) );
		$user = azr_test_make_user( 9, array( 'read' => true ) );
		$this->assertSame( $user, $t->maybe_block( $user ) );
	}

	public function test_client_ip_strips_unexpected_characters(): void {
		$_SERVER['REMOTE_ADDR'] = ' 10.0.0.1 ';
		$t                      = $this->throttle( array( 'enabled' => true ) );
		$this->assertSame( '10.0.0.1', $t->client_ip() );
	}

	public function test_clear_resets_after_success(): void {
		$t = $this->throttle( array( 'enabled' => true, 'max_attempts' => 1, 'lockout_seconds' => 60 ) );
		$t->record_failure();
		$this->assertTrue( $t->is_locked( $t->client_ip() ) );

		$t->clear();
		$this->assertFalse( $t->is_locked( $t->client_ip() ) );
	}
}
