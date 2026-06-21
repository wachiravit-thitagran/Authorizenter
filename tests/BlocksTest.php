<?php
/**
 * Blocks logic unit tests.
 *
 * @package Authorizenter\UI\Tests
 */

namespace Authorizenter\UI\Tests;

use PHPUnit\Framework\TestCase;
use Authorizenter\UI\Blocks;

/**
 * Tests for the Blocks class.
 */
class BlocksTest extends TestCase {

	private $blocks;

	protected function setUp(): void {
		parent::setUp();
		$this->blocks = new Blocks();
		$GLOBALS['__mock_blocks'] = array();
	}

	public function test_hooks() {
		$this->blocks->hooks();
		$this->assertTrue( true );
	}

	public function test_register_adds_block_types() {
		$this->blocks->register();
		
		$this->assertArrayHasKey( 'authorizenter/login', $GLOBALS['__mock_blocks'] );
		$this->assertArrayHasKey( 'authorizenter/logout', $GLOBALS['__mock_blocks'] );
	}

	public function test_render_login_calls_do_shortcode() {
		$result = $this->blocks->render_login( array( 'context' => 'test' ) );
		$this->assertEquals( 'MOCK_SHORTCODE: [authorizenter_login context="test"]', $result );
	}

	public function test_render_login_uses_default_context() {
		$result = $this->blocks->render_login( array() );
		$this->assertEquals( 'MOCK_SHORTCODE: [authorizenter_login context="default"]', $result );
	}

	public function test_render_logout_calls_do_shortcode_with_label() {
		$result = $this->blocks->render_logout( array( 'label' => 'Log Out Now' ) );
		$this->assertEquals( 'MOCK_SHORTCODE: [authorizenter_logout label="Log Out Now"]', $result );
	}

	public function test_render_logout_calls_do_shortcode_without_label() {
		$result = $this->blocks->render_logout( array() );
		$this->assertEquals( 'MOCK_SHORTCODE: [authorizenter_logout]', $result );
	}
}
