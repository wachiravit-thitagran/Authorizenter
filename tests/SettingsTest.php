<?php
/**
 * Tests for Settings, focused on context resolution and secret encryption.
 *
 * @package Autorizenter\Core\Tests
 */

namespace Autorizenter\Core\Tests;

use Autorizenter\Core\Settings;
use PHPUnit\Framework\TestCase;

class SettingsTest extends TestCase {

	protected function setUp(): void {
		azr_test_reset();
	}

	public function test_default_context_has_read_capability(): void {
		$settings = new Settings();
		$ctx      = $settings->get_context( 'default' );

		$this->assertSame( 'default', $ctx['id'] );
		$this->assertSame( 'read', $ctx['required_capability'] );
	}

	public function test_context_inherits_global_allowed_domains_when_null(): void {
		update_option(
			Settings::OPTION,
			array(
				'policy'   => array( 'allowed_domains' => array( 'psu.ac.th' ) ),
				'contexts' => array(
					'default' => array( 'allowed_domains' => null ), // inherit.
				),
			)
		);

		$settings = new Settings();
		$ctx      = $settings->get_context( 'default' );

		$this->assertSame( array( 'psu.ac.th' ), $ctx['allowed_domains'] );
	}

	public function test_context_overrides_global_allowed_domains(): void {
		update_option(
			Settings::OPTION,
			array(
				'policy'   => array( 'allowed_domains' => array( 'psu.ac.th' ) ),
				'contexts' => array(
					'admin' => array(
						'required_capability' => 'manage_options',
						'allowed_domains'     => array( 'staff.psu.ac.th' ),
					),
				),
			)
		);

		$settings = new Settings();
		$ctx      = $settings->get_context( 'admin' );

		$this->assertSame( array( 'staff.psu.ac.th' ), $ctx['allowed_domains'] );
		$this->assertSame( 'manage_options', $ctx['required_capability'] );
	}

	public function test_unknown_context_falls_back_to_default(): void {
		update_option(
			Settings::OPTION,
			array(
				'contexts' => array(
					'default' => array( 'label' => 'Base', 'required_capability' => 'read' ),
				),
			)
		);

		$settings = new Settings();
		$ctx      = $settings->get_context( 'does-not-exist' );

		// id reflects the requested id but values come from default.
		$this->assertSame( 'read', $ctx['required_capability'] );
	}

	public function test_deny_redirect_falls_back_to_global(): void {
		update_option(
			Settings::OPTION,
			array(
				'advanced' => array( 'deny_redirect' => 'https://example.org/no' ),
				'contexts' => array(
					'admin' => array( 'required_capability' => 'manage_options' ),
				),
			)
		);

		$settings = new Settings();
		$ctx      = $settings->get_context( 'admin' );

		$this->assertSame( 'https://example.org/no', $ctx['deny_redirect'] );
	}

	public function test_get_unknown_section_returns_empty_array(): void {
		$settings = new Settings();
		$this->assertSame( array(), $settings->get( 'no_such_section' ) );
	}

	public function test_context_ids_always_include_default(): void {
		update_option(
			Settings::OPTION,
			array( 'contexts' => array( 'admin' => array( 'required_capability' => 'manage_options' ) ) )
		);
		$ids = ( new Settings() )->context_ids();
		$this->assertContains( 'default', $ids );
		$this->assertContains( 'admin', $ids );
	}

	public function test_save_roundtrip_persists_values(): void {
		$settings = new Settings();
		$all      = $settings->all();
		$all['policy']['allowed_domains'] = array( 'psu.ac.th' );
		$settings->save( $all );

		$reloaded = new Settings();
		$this->assertSame( array( 'psu.ac.th' ), $reloaded->get( 'policy' )['allowed_domains'] );
	}

	public function test_secret_encrypt_decrypt_roundtrip(): void {
		$settings  = new Settings();
		$plaintext = 'super-secret-value';
		$stored    = $settings->encrypt( $plaintext );

		$this->assertNotSame( $plaintext, $stored );
		$this->assertSame( $plaintext, $settings->decrypt( $stored ) );
	}
}
