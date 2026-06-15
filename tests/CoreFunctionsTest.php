<?php
/**
 * Tests for global helper functions in authorizenter-core.php.
 *
 * @package Authorizenter\Core\Tests
 */

namespace Authorizenter\Core\Tests;

use PHPUnit\Framework\TestCase;

class CoreFunctionsTest extends TestCase {

	protected function setUp(): void {
		azr_test_reset();
		require_once dirname( __DIR__ ) . '/plugins/authorizenter-core/authorizenter-core.php';
	}

	public function test_get_provider_data_with_specific_provider() {
		azr_test_make_user( 1 );
		update_user_meta( 1, 'authorizenter_provider_data_facebook', array( 'id' => 'fb123' ) );

		$data = \Authorizenter\Core\authorizenter_get_provider_data( 1, 'facebook' );
		$this->assertSame( array( 'id' => 'fb123' ), $data );
	}

	public function test_get_provider_data_falls_back_to_last_provider() {
		azr_test_make_user( 1 );
		update_user_meta( 1, 'authorizenter_last_provider', 'google' );
		update_user_meta( 1, 'authorizenter_provider_data_google', array( 'sub' => 'g123' ) );

		$data = \Authorizenter\Core\authorizenter_get_provider_data( 1 );
		$this->assertSame( array( 'sub' => 'g123' ), $data );
	}

	public function test_get_provider_data_returns_false_if_no_provider() {
		azr_test_make_user( 1 );
		// No last_provider meta set

		$data = \Authorizenter\Core\authorizenter_get_provider_data( 1 );
		$this->assertFalse( $data );
	}

	public function test_get_provider_data_returns_false_if_no_data() {
		azr_test_make_user( 1 );
		update_user_meta( 1, 'authorizenter_last_provider', 'line' );
		// No provider_data meta set

		$data = \Authorizenter\Core\authorizenter_get_provider_data( 1 );
		$this->assertFalse( $data );
	}
}
