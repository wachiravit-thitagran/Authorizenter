<?php
/**
 * Tests for provider adapters (presets, secure-URL guard, claim mapping).
 *
 * @package Autorizenter\Core\Tests
 */

namespace Autorizenter\Core\Tests;

use Autorizenter\Core\Settings;
use Autorizenter\Core\Providers\OIDC;
use Autorizenter\Core\Providers\Google;
use Autorizenter\Core\Providers\Line;
use Autorizenter\Core\Providers\Facebook;
use Autorizenter\Core\Identity;
use PHPUnit\Framework\TestCase;

class ProvidersTest extends TestCase {

	protected function setUp(): void {
		azr_test_reset();
	}

	private function oidc(): OIDC {
		return new OIDC( new Settings(), array( 'id' => 'oidc' ) );
	}

	private function invoke( object $obj, string $method, array $args ) {
		$ref = new \ReflectionMethod( $obj, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $obj, $args );
	}

	public function test_preset_ids_and_labels(): void {
		$settings = new Settings();
		$this->assertSame( 'google', ( new Google( $settings, array() ) )->id() );
		$this->assertSame( 'line', ( new Line( $settings, array() ) )->id() );
		$this->assertSame( 'facebook', ( new Facebook( $settings, array() ) )->id() );
		$this->assertSame( 'Google', ( new Google( $settings, array() ) )->label() );
		$this->assertSame( 'LINE', ( new Line( $settings, array() ) )->label() );
		$this->assertSame( 'Facebook', ( new Facebook( $settings, array() ) )->label() );
	}

	public function test_preset_label_override(): void {
		$settings = new Settings();
		$this->assertSame( 'PSU Login', ( new Google( $settings, array( 'label' => 'PSU Login' ) ) )->label() );
		$this->assertSame( 'My LINE', ( new Line( $settings, array( 'label' => 'My LINE' ) ) )->label() );
		$this->assertSame( 'My Facebook', ( new Facebook( $settings, array( 'label' => 'My Facebook' ) ) )->label() );
	}

	public function test_secure_url_guard(): void {
		$oidc = $this->oidc();
		$this->assertTrue( $this->invoke( $oidc, 'is_secure_url', array( 'https://idp.example.org/x' ) ) );
		$this->assertTrue( $this->invoke( $oidc, 'is_secure_url', array( 'http://localhost/x' ) ) );
		$this->assertTrue( $this->invoke( $oidc, 'is_secure_url', array( 'http://127.0.0.1/x' ) ) );
		$this->assertFalse( $this->invoke( $oidc, 'is_secure_url', array( 'http://evil.example.org/x' ) ) );
	}

	public function test_identity_from_claims_normalizes(): void {
		$oidc     = $this->oidc();
		$identity = $this->invoke(
			$oidc,
			'identity_from_claims',
			array(
				array(
					'sub'            => 'abc',
					'email'          => 'User@PSU.AC.TH',
					'email_verified' => true,
					'name'           => 'Jane',
					'hd'             => 'PSU.AC.TH',
				),
			)
		);

		$this->assertInstanceOf( Identity::class, $identity );
		$this->assertSame( 'oidc', $identity->provider );
		$this->assertSame( 'user@psu.ac.th', $identity->email );
		$this->assertTrue( $identity->email_verified );
		$this->assertSame( 'psu.ac.th', $identity->hd );
	}

	public function test_facebook_has_no_end_session_url(): void {
		$fb = new Facebook( new Settings(), array() );
		$this->assertSame( '', $fb->end_session_url( 'https://example.test/' ) );
	}

	public function test_is_enabled_requires_client_id(): void {
		$settings = new Settings();
		$this->assertFalse( ( new Google( $settings, array( 'enabled' => true, 'client_id' => '' ) ) )->is_enabled() );
		$this->assertTrue( ( new Google( $settings, array( 'enabled' => true, 'client_id' => 'abc' ) ) )->is_enabled() );
		$this->assertFalse( ( new Google( $settings, array( 'enabled' => false, 'client_id' => 'abc' ) ) )->is_enabled() );
	}
}

