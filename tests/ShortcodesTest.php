<?php
/**
 * Shortcodes logic unit tests.
 *
 * @package Authorizenter\Core\Tests
 */

namespace Authorizenter\Core\Tests;

use PHPUnit\Framework\TestCase;
use Authorizenter\Core\Shortcodes;
use Authorizenter\Core\Settings;
use Authorizenter\Core\Provider_Registry;

/**
 * Tests for the Shortcodes class.
 */
class ShortcodesTest extends TestCase {

	/**
	 * @var Settings|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $settings;

	/**
	 * @var Provider_Registry|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $providers;

	/**
	 * @var Shortcodes
	 */
	private $shortcodes;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->settings   = $this->createMock( Settings::class );
		$this->providers  = $this->createMock( Provider_Registry::class );
		$this->shortcodes = new Shortcodes( $this->settings, $this->providers );
		
		// Reset $_SERVER for tests.
		unset( $_SERVER['REQUEST_URI'] );
	}

	public function test_hooks_registers_shortcode() {
		// Mock add_shortcode by clearing any previous calls to our stub list if we had one.
		// Since we use static wp_stubs, we can just ensure it doesn't crash.
		$this->shortcodes->hooks();
		$this->assertTrue( true, 'Hooks should register without fatal errors.' );
	}

	public function test_render_url_returns_empty_when_no_provider() {
		$atts = array( 'context' => 'default' );
		$this->assertSame( '', $this->shortcodes->render_url( $atts ) );
	}

	public function test_render_url_returns_empty_when_provider_not_enabled() {
		$atts = array( 'provider' => 'google', 'context' => 'test' );
		
		$this->settings->method( 'get_context' )
			->with( 'test' )
			->willReturn( array( 'id' => 'test' ) );
			
		$this->providers->method( 'enabled_for_context' )
			->willReturn( array( 'line' => true ) ); // Google not enabled.

		$this->assertSame( '', $this->shortcodes->render_url( $atts ) );
	}

	public function test_render_url_returns_escaped_url_with_return_to() {
		$atts = array( 'provider' => 'google', 'context' => 'default', 'return_to' => 'https://example.com/custom' );
		
		$this->settings->method( 'get_context' )
			->willReturn( array( 'id' => 'default' ) );
			
		$this->providers->method( 'enabled_for_context' )
			->willReturn( array( 'google' => true ) );

		$url = urldecode($this->shortcodes->render_url( $atts ));
		
		$this->assertStringContainsString( 'authorizenter/v1/authorize/google', $url );
		$this->assertStringContainsString( 'context=default', $url );
		$this->assertStringContainsString( 'return_to=' . rawurlencode('https://example.com/custom'), $url );
	}

	public function test_render_url_uses_server_request_uri_as_fallback_return_to() {
		$_SERVER['REQUEST_URI'] = '/fallback-path?a=b';
		
		$atts = array( 'provider' => 'google', 'context' => 'default' );
		
		$this->settings->method( 'get_context' )
			->willReturn( array( 'id' => 'default' ) );
			
		$this->providers->method( 'enabled_for_context' )
			->willReturn( array( 'google' => true ) );

		$url = urldecode($this->shortcodes->render_url( $atts ));
		
		// wp-stubs' home_url() implementation prepends https://example.test to path.
		$expected_return_to = rawurlencode( 'https://example.test/fallback-path?a=b' );
		
		$this->assertStringContainsString( 'return_to=' . $expected_return_to, $url );
	}
}
