<?php
/**
 * Tests for provider adapters (presets, secure-URL guard, claim mapping).
 *
 * @package Authorizenter\Core\Tests
 */

namespace Authorizenter\Core\Tests;

use Authorizenter\Core\Settings;
use Authorizenter\Core\Providers\OIDC;
use Authorizenter\Core\Providers\Google;
use Authorizenter\Core\Providers\Line;
use Authorizenter\Core\Providers\Facebook;
use Authorizenter\Core\Identity;
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

	public function test_trust_email_forces_verified(): void {
		$oidc = new OIDC( new Settings(), array( 'id' => 'oidc', 'trust_email' => true ) );
		$id   = $oidc->identity_from_claims_public( array( 'sub' => 'S', 'email' => 'a@psu.ac.th' ) ); // no email_verified claim.
		$this->assertTrue( $id->email_verified );
	}

	public function test_email_verified_follows_claim_when_not_trusted(): void {
		$oidc = new OIDC( new Settings(), array( 'id' => 'oidc' ) );

		$verified = $oidc->identity_from_claims_public( array( 'sub' => 'S', 'email' => 'a@psu.ac.th', 'email_verified' => true ) );
		$this->assertTrue( $verified->email_verified );

		$unverified = $oidc->identity_from_claims_public( array( 'sub' => 'S', 'email' => 'a@psu.ac.th' ) );
		$this->assertFalse( $unverified->email_verified );
	}

	public function test_is_oidc_flag(): void {
		$settings = new Settings();
		$this->assertTrue( $this->oidc()->is_oidc() );
		$this->assertTrue( ( new Google( $settings, array() ) )->is_oidc() );
		$this->assertTrue( ( new Line( $settings, array() ) )->is_oidc() );
		$this->assertFalse( ( new Facebook( $settings, array() ) )->is_oidc() );
	}

	public function test_oidc_provider_url_for_presets(): void {
		$settings = new Settings();
		$this->assertSame( 'https://accounts.google.com', ( new Google( $settings, array() ) )->oidc_provider_url() );
		$this->assertSame( 'https://access.line.me', ( new Line( $settings, array() ) )->oidc_provider_url() );
	}

	public function test_oidc_provider_url_strips_well_known_suffix(): void {
		$oidc = new OIDC( new Settings(), array( 'id' => 'oidc', 'discovery_url' => 'https://idp.example.org/.well-known/openid-configuration' ) );
		$this->assertSame( 'https://idp.example.org', $oidc->oidc_provider_url() );

		$oidc2 = new OIDC( new Settings(), array( 'id' => 'oidc', 'discovery_url' => 'https://idp.example.org/auth/.well-known/openid-configuration' ) );
		$this->assertSame( 'https://idp.example.org/auth', $oidc2->oidc_provider_url() );

		$empty = new OIDC( new Settings(), array( 'id' => 'oidc' ) );
		$this->assertSame( '', $empty->oidc_provider_url() );
	}

	public function test_scopes_list_splits_scope_string(): void {
		$this->assertSame( array( 'openid', 'email', 'profile' ), ( new Google( new Settings(), array() ) )->scopes_list() );
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

