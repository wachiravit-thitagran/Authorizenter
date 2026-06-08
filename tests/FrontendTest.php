<?php
/**
 * Tests for the [autorizenter_button] shortcode.
 *
 * @package Autorizenter\UI\Tests
 */

namespace Autorizenter\Core\Tests;

use Autorizenter\Core\Settings;
use Autorizenter\Core\Provider_Registry;
use Autorizenter\UI\Frontend;
use PHPUnit\Framework\TestCase;

class FrontendTest extends TestCase {

	/** @var Frontend */
	private $frontend;

	protected function setUp(): void {
		azr_test_reset();
		$this->frontend = new Frontend();
	}

	private function make_core( array $provider_config = array() ): void {
		update_option(
			Settings::OPTION,
			array( 'providers' => $provider_config )
		);
		$settings              = new Settings();
		$core                  = new \stdClass();
		$core->settings        = $settings;
		$core->providers       = new Provider_Registry( $settings );
		$GLOBALS['__core']     = $core;
	}

	// --- render_button: early-exit cases ------------------------------------

	public function test_returns_empty_when_no_provider_given(): void {
		$this->make_core();
		$this->assertSame( '', $this->frontend->render_button( array() ) );
	}

	public function test_returns_empty_when_user_is_logged_in(): void {
		$this->make_core( array( 'google' => array( 'enabled' => true, 'client_id' => 'G' ) ) );
		$GLOBALS['__logged_in'] = true;
		$this->assertSame( '', $this->frontend->render_button( array( 'provider' => 'google' ) ) );
	}

	public function test_returns_empty_when_provider_not_enabled(): void {
		$this->make_core( array( 'google' => array( 'enabled' => false, 'client_id' => 'G' ) ) );
		$this->assertSame( '', $this->frontend->render_button( array( 'provider' => 'google' ) ) );
	}

	public function test_returns_empty_when_provider_not_configured(): void {
		$this->make_core();
		$this->assertSame( '', $this->frontend->render_button( array( 'provider' => 'google' ) ) );
	}

	// --- render_button: HTML output -----------------------------------------

	public function test_renders_anchor_with_provider_class(): void {
		$this->make_core( array( 'google' => array( 'enabled' => true, 'client_id' => 'G' ) ) );
		$html = $this->frontend->render_button( array( 'provider' => 'google' ) );
		$this->assertStringContainsString( 'autorizenter-btn--google', $html );
		$this->assertStringContainsString( '<a ', $html );
	}

	public function test_renders_provider_label_in_button(): void {
		$this->make_core( array( 'google' => array( 'enabled' => true, 'client_id' => 'G' ) ) );
		$html = $this->frontend->render_button( array( 'provider' => 'google' ) );
		$this->assertStringContainsString( 'Google', $html );
	}

	public function test_custom_label_appears_in_button(): void {
		$this->make_core( array(
			'google' => array( 'enabled' => true, 'client_id' => 'G', 'label' => 'PSU Login' ),
		) );
		$html = $this->frontend->render_button( array( 'provider' => 'google' ) );
		$this->assertStringContainsString( 'PSU Login', $html );
	}

	public function test_button_href_contains_provider_and_context(): void {
		$this->make_core( array( 'google' => array( 'enabled' => true, 'client_id' => 'G' ) ) );
		$html = $this->frontend->render_button( array( 'provider' => 'google', 'context' => 'default' ) );
		$this->assertStringContainsString( 'autorizenter/v1/authorize/google', $html );
		$this->assertStringContainsString( 'context=default', $html );
	}

	public function test_return_to_is_included_in_href(): void {
		$this->make_core( array( 'google' => array( 'enabled' => true, 'client_id' => 'G' ) ) );
		$html = $this->frontend->render_button( array(
			'provider'  => 'google',
			'return_to' => 'https://example.test/dashboard/',
		) );
		$this->assertStringContainsString( 'return_to=', $html );
	}
}
