<?php
/**
 * Tests for Page_Installer.
 *
 * @package Authorizenter\UI\Tests
 */

namespace Authorizenter\UI\Tests;

use Authorizenter\UI\Page_Installer;
use PHPUnit\Framework\TestCase;

class PageInstallerTest extends TestCase {

	protected function setUp(): void {
		azr_test_reset();
	}

	public function test_ensure_page_creates_new_post_if_not_exists(): void {
		// Verify that wp_insert_post is called.
		$method = new \ReflectionMethod( Page_Installer::class, 'ensure_page' );
		$method->setAccessible( true );
		
		$method->invoke( null, 'authorizenter_page_test_page_id', 'Test Page', 'test-page', '[test_shortcode]' );
		
		// ID should be 1 because it's the first post.
		$this->assertEquals( 1, get_option( 'authorizenter_page_test_page_id' ) );
		$this->assertNotEmpty( $GLOBALS['__mock_posts'] );
		$this->assertEquals( 'Test Page', $GLOBALS['__mock_posts'][1]->post_title );
	}

	public function test_ensure_page_reuses_existing_page_by_slug(): void {
		// Mock an existing page with the same slug.
		$GLOBALS['__mock_pages_by_path']['test-page'] = array(
			'ID'          => 42,
			'post_title'  => 'Existing Test Page',
			'post_type'   => 'page',
			'post_status' => 'publish',
		);
		$GLOBALS['__mock_posts'][42] = (object) $GLOBALS['__mock_pages_by_path']['test-page'];

		$method = new \ReflectionMethod( Page_Installer::class, 'ensure_page' );
		$method->setAccessible( true );
		
		$method->invoke( null, 'authorizenter_page_test_page_id', 'Test Page', 'test-page', '[test_shortcode]' );
		
		// It should update the option with the existing ID.
		$this->assertEquals( 42, get_option( 'authorizenter_page_test_page_id' ) );
		
		// And it should NOT insert a new post.
		$this->assertArrayNotHasKey( 1, $GLOBALS['__mock_posts'] );
	}
}
