<?php
/**
 * Logos logic unit tests.
 *
 * @package Authorizenter\UI\Tests
 */

namespace Authorizenter\UI\Tests;

use PHPUnit\Framework\TestCase;
use Authorizenter\UI\Logos;

/**
 * Tests for the Logos class.
 */
class LogosTest extends TestCase {

	public function test_svg_returns_google_logo() {
		$svg = Logos::svg( 'google' );
		$this->assertStringContainsString( '<svg', $svg );
		$this->assertStringContainsString( '#EA4335', $svg );
	}

	public function test_svg_returns_facebook_logo() {
		$svg = Logos::svg( 'facebook' );
		$this->assertStringContainsString( '<svg', $svg );
		$this->assertStringContainsString( '#1877F2', $svg );
	}

	public function test_svg_returns_line_logo() {
		$svg = Logos::svg( 'line' );
		$this->assertStringContainsString( '<svg', $svg );
		$this->assertStringContainsString( '#06C755', $svg );
	}

	public function test_svg_returns_oidc_lock_icon() {
		$svg = Logos::svg( 'oidc' );
		$this->assertStringContainsString( '<svg', $svg );
		$this->assertStringContainsString( 'stroke="#6b46c1"', $svg );
		$this->assertStringContainsString( '<rect x="3" y="11"', $svg );
	}

	public function test_svg_returns_generic_lock_icon_for_unknown() {
		$svg = Logos::svg( 'unknown_provider' );
		$this->assertStringContainsString( '<svg', $svg );
		$this->assertStringContainsString( 'stroke="currentColor"', $svg );
		$this->assertStringContainsString( '<rect x="3" y="11"', $svg );
	}
}
