<?php
/**
 * MCP abilities tests.
 *
 * @package Authorizenter\Core\Tests
 */

use Authorizenter\Core\MCP;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'wp_register_ability_category' ) ) {
	function wp_register_ability_category( $name, $args ) {
		$GLOBALS['mock_ability_categories'][ $name ] = $args;
	}
}

if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( $name, $args ) {
		$GLOBALS['mock_abilities'][ $name ] = $args;
	}
}

final class MCPTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['mock_ability_categories'] = array();
		$GLOBALS['mock_abilities']          = array();
	}

	public function test_registers_authorizenter_abilities(): void {
		MCP::register_category();
		MCP::register_abilities();

		$this->assertArrayHasKey( 'authorizenter', $GLOBALS['mock_ability_categories'] );
		$this->assertArrayHasKey( 'authorizenter/list-providers', $GLOBALS['mock_abilities'] );
		$this->assertArrayHasKey( 'authorizenter/get-provider-data', $GLOBALS['mock_abilities'] );
		$this->assertArrayHasKey( 'authorizenter/get-answer-report', $GLOBALS['mock_abilities'] );
	}

	public function test_all_current_authorizenter_mcp_abilities_are_read_only(): void {
		MCP::register_abilities();

		foreach ( $GLOBALS['mock_abilities'] as $ability ) {
			$this->assertTrue( $ability['meta']['annotations']['readonly'] );
			$this->assertFalse( $ability['meta']['annotations']['destructive'] );
		}
	}
}
