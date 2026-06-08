<?php
/**
 * Tests for the admin settings screen UI contract.
 *
 * @package Autorizenter\Core\Tests
 */

namespace Autorizenter\Core\Tests;

use Autorizenter\Core\Admin_Settings;
use Autorizenter\Core\Provider_Registry;
use Autorizenter\Core\Questions;
use Autorizenter\Core\Settings;
use PHPUnit\Framework\TestCase;

class AdminSettingsUiTest extends TestCase {

	protected function setUp(): void {
		azr_test_reset();
	}

	private function render_settings(): string {
		$settings = new Settings();
		$admin    = new Admin_Settings(
			$settings,
			new Provider_Registry( $settings ),
			new Questions( $settings )
		);

		ob_start();
		$admin->render();
		return (string) ob_get_clean();
	}

	public function test_settings_screen_uses_grouped_tabs(): void {
		$html = $this->render_settings();

		$this->assertStringContainsString( 'nav-tab-wrapper autorizenter-tabs', $html );
		$this->assertStringContainsString( 'href="#autorizenter-tab-providers"', $html );
		$this->assertStringContainsString( 'id="autorizenter-tab-security"', $html );
		$this->assertStringContainsString( 'data-autorizenter-tab', $html );
	}

	public function test_domain_placeholders_are_generic(): void {
		$html = $this->render_settings();

		$this->assertMatchesRegularExpression( '/name="allowed_domains"[^>]+placeholder="example\.com"/', $html );
		$this->assertMatchesRegularExpression( '/name="ctx\[[0-9]+\]\[allowed_domains\]"[^>]+placeholder="example\.com"/', $html );
		$this->assertStringNotContainsString( 'placeholder="psu.ac.th"', $html );
		$this->assertStringNotContainsString( 'staff.psu.ac.th', $html );
		$this->assertStringNotContainsString( 'alice@psu.ac.th', $html );
	}

	public function test_thai_translation_files_cover_templates(): void {
		$root  = dirname( __DIR__ );
		$files = array(
			$root . '/plugins/autorizenter-core/languages/autorizenter-th.po',
			$root . '/plugins/autorizenter-ui/languages/autorizenter-th.po',
		);

		foreach ( $files as $file ) {
			$this->assertFileExists( $file );
			$po = (string) file_get_contents( $file );
			$this->assertStringContainsString( '"Language: th', $po );
			$this->assertDoesNotMatchRegularExpression( '/^msgid "(?!")(.|\n)*?^msgstr ""$/m', $po );
		}

		$this->assertFileExists( $root . '/plugins/autorizenter-core/languages/autorizenter-th.mo' );
		$this->assertFileExists( $root . '/plugins/autorizenter-ui/languages/autorizenter-th.mo' );
	}

	public function test_block_editor_strings_have_thai_script_translations(): void {
		$root = dirname( __DIR__ );

		$blocks = (string) file_get_contents( $root . '/plugins/autorizenter-ui/includes/class-blocks.php' );
		$this->assertStringContainsString( 'wp_set_script_translations', $blocks );

		$json_file = $root . '/plugins/autorizenter-ui/languages/autorizenter-th-autorizenter-blocks.json';
		$this->assertFileExists( $json_file );

		$json = (string) file_get_contents( $json_file );
		$this->assertStringContainsString( 'เข้าสู่ระบบด้วย Autorizenter', $json );
		$this->assertStringContainsString( 'การตั้งค่าการออกจากระบบ', $json );
	}
}
