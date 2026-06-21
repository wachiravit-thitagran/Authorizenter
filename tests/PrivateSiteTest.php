<?php
/**
 * Private Site logic unit tests.
 *
 * @package Authorizenter\Core\Tests
 */

namespace Authorizenter\Core\Tests;

use PHPUnit\Framework\TestCase;
use Authorizenter\Core\Private_Site;
use Authorizenter\Core\Settings;

/**
 * Tests for the Private_Site class.
 */
class PrivateSiteTest extends TestCase {

	private $settings;
	private $private_site;

	protected function setUp(): void {
		parent::setUp();
		$this->settings     = $this->createMock( Settings::class );
		$this->private_site = new Private_Site( $this->settings );
		
		$GLOBALS['__logged_in']      = false;
		$GLOBALS['__mock_is_page']   = '';
		_azr_test_reset_filters();
	}

	public function test_hooks_registers_action() {
		// Mock add_action
		$this->private_site->hooks();
		$this->assertTrue( true, 'Hooks should register without fatal errors.' );
	}

	public function test_is_enabled_returns_true_when_enabled() {
		$this->settings->method( 'get' )
			->with( 'private_site' )
			->willReturn( array( 'enabled' => true ) );
			
		$this->assertTrue( $this->private_site->is_enabled() );
	}

	public function test_should_block_returns_false_if_not_enabled() {
		$this->settings->method( 'get' )
			->willReturn( array( 'enabled' => false ) );
			
		$this->assertFalse( $this->private_site->should_block( false, false, false ) );
	}

	public function test_should_block_returns_false_if_logged_in() {
		$this->settings->method( 'get' )
			->willReturn( array( 'enabled' => true ) );
			
		$this->assertFalse( $this->private_site->should_block( true, false, false ) );
	}

	public function test_should_block_returns_false_if_login_page() {
		$this->settings->method( 'get' )
			->willReturn( array( 'enabled' => true ) );
			
		$this->assertFalse( $this->private_site->should_block( false, true, false ) );
	}

	public function test_should_block_returns_false_if_allowed() {
		$this->settings->method( 'get' )
			->willReturn( array( 'enabled' => true ) );
			
		$this->assertFalse( $this->private_site->should_block( false, false, true ) );
	}

	public function test_should_block_returns_true_otherwise() {
		$this->settings->method( 'get' )
			->willReturn( array( 'enabled' => true ) );
			
		$this->assertTrue( $this->private_site->should_block( false, false, false ) );
	}

	public function test_maybe_redirect_does_nothing_if_disabled() {
		$this->settings->method( 'get' )
			->willReturn( array( 'enabled' => false ) );
			
		// If it did redirect, it would call exit() and break the test.
		$this->private_site->maybe_redirect();
		$this->assertTrue( true );
	}

	public function test_maybe_redirect_does_nothing_if_logged_in() {
		$this->settings->method( 'get' )
			->willReturn( array( 'enabled' => true ) );
			
		$GLOBALS['__logged_in'] = true;
		$this->private_site->maybe_redirect();
		$this->assertTrue( true );
	}

	public function test_maybe_redirect_does_nothing_if_allowed_by_filter() {
		$this->settings->method( 'get' )
			->willReturn( array( 'enabled' => true ) );
			
		$GLOBALS['__mock_filters']['authorizenter_private_allow'] = function() {
			return true;
		};
		
		$this->private_site->maybe_redirect();
		$this->assertTrue( true );
	}

	public function test_maybe_redirect_does_nothing_if_is_login_page() {
		$this->settings->method( 'get' )
			->willReturn( array( 'enabled' => true ) );
			
		$GLOBALS['__mock_filters']['authorizenter_login_page_id'] = function() {
			return 10;
		};
		$GLOBALS['__mock_is_page'] = 10;
		
		$this->private_site->maybe_redirect();
		$this->assertTrue( true );
	}
}
