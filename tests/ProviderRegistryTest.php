<?php
/**
 * Tests for Provider_Registry context filtering.
 *
 * @package Autorizenter\Core\Tests
 */

namespace Autorizenter\Core\Tests;

use Autorizenter\Core\Settings;
use Autorizenter\Core\Provider_Registry;
use PHPUnit\Framework\TestCase;

class ProviderRegistryTest extends TestCase {

	/** @var Provider_Registry */
	private $registry;

	protected function setUp(): void {
		azr_test_reset();
		update_option(
			Settings::OPTION,
			array(
				'providers' => array(
					'google' => array( 'enabled' => true, 'client_id' => 'g' ),
					'line'   => array( 'enabled' => true, 'client_id' => 'l' ),
					'oidc'   => array( 'enabled' => false, 'client_id' => '' ),
				),
			)
		);
		$this->registry = new Provider_Registry( new Settings() );
	}

	public function test_enabled_lists_only_configured_providers(): void {
		$enabled = $this->registry->enabled();
		$this->assertArrayHasKey( 'google', $enabled );
		$this->assertArrayHasKey( 'line', $enabled );
		$this->assertArrayNotHasKey( 'oidc', $enabled );
		$this->assertArrayNotHasKey( 'facebook', $enabled );
	}

	public function test_empty_context_provider_list_returns_all_enabled(): void {
		$out = $this->registry->enabled_for_context( array( 'providers' => array() ) );
		$this->assertSame( array( 'google', 'line' ), array_keys( $out ) );
	}

	public function test_context_filters_to_listed_providers(): void {
		$out = $this->registry->enabled_for_context( array( 'providers' => array( 'google' ) ) );
		$this->assertSame( array( 'google' ), array_keys( $out ) );
	}

	public function test_is_allowed_in_context(): void {
		$this->assertTrue( $this->registry->is_allowed_in_context( 'google', array( 'providers' => array() ) ) );
		$this->assertTrue( $this->registry->is_allowed_in_context( 'google', array( 'providers' => array( 'google' ) ) ) );
		$this->assertFalse( $this->registry->is_allowed_in_context( 'line', array( 'providers' => array( 'google' ) ) ) );
	}

	public function test_get_unknown_provider_returns_null(): void {
		$this->assertNull( $this->registry->get( 'nope' ) );
	}

	public function test_get_returns_correct_class(): void {
		$this->assertInstanceOf( \Autorizenter\Core\Providers\Google::class, $this->registry->get( 'google' ) );
		$this->assertInstanceOf( \Autorizenter\Core\Providers\Facebook::class, $this->registry->get( 'facebook' ) );
	}
}
