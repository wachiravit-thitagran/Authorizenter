<?php
/**
 * Tests for OIDC provider: custom claim attributes, require_verified_email, auth methods.
 *
 * @package Autorizenter\Core\Tests
 */

namespace Autorizenter\Core\Tests;

use Autorizenter\Core\Settings;
use Autorizenter\Core\Providers\OIDC;
use PHPUnit\Framework\TestCase;

class OidcTest extends TestCase {

	protected function setUp(): void {
		azr_test_reset();
	}

	private function make_oidc( array $config = array() ): OIDC {
		$settings = new Settings();
		return new OIDC( $settings, array_merge(
			array( 'client_id' => 'test-client', 'enabled' => true ),
			$config
		) );
	}

	// --- Claim mapping -------------------------------------------------------

	public function test_identity_from_claims_uses_default_attributes(): void {
		$oidc = $this->make_oidc();
		$id   = $oidc->identity_from_claims_public( array(
			'sub'            => 'U1',
			'email'          => 'u@example.test',
			'email_verified' => true,
			'given_name'     => 'First',
			'family_name'    => 'Last',
		) );
		$this->assertInstanceOf( \Autorizenter\Core\Identity::class, $id );
		$this->assertSame( 'U1', $id->sub );
		$this->assertSame( 'u@example.test', $id->email );
		$this->assertSame( 'First', $id->first_name );
		$this->assertSame( 'Last', $id->last_name );
	}

	public function test_identity_from_claims_uses_custom_attributes(): void {
		$oidc = $this->make_oidc( array(
			'attr_username'   => 'preferred_username',
			'attr_email'      => 'mail',
			'attr_first_name' => 'fname',
			'attr_last_name'  => 'lname',
		) );
		$id = $oidc->identity_from_claims_public( array(
			'sub'                => 'U2',
			'preferred_username' => 'jdoe',
			'mail'               => 'jdoe@example.test',
			'fname'              => 'John',
			'lname'              => 'Doe',
		) );
		$this->assertInstanceOf( \Autorizenter\Core\Identity::class, $id );
		$this->assertSame( 'jdoe', $id->username );
		$this->assertSame( 'jdoe@example.test', $id->email );
		$this->assertSame( 'John', $id->first_name );
		$this->assertSame( 'Doe', $id->last_name );
	}

	public function test_require_verified_email_rejects_unverified(): void {
		$oidc   = $this->make_oidc( array( 'oidc_require_verified_email' => true ) );
		$result = $oidc->identity_from_claims_public( array(
			'sub'            => 'U3',
			'email'          => 'u@example.test',
			'email_verified' => false,
		) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'autorizenter_oidc_email_unverified', $result->get_error_code() );
	}

	public function test_require_verified_email_allows_verified(): void {
		$oidc   = $this->make_oidc( array( 'oidc_require_verified_email' => true ) );
		$result = $oidc->identity_from_claims_public( array(
			'sub'            => 'U4',
			'email'          => 'u@example.test',
			'email_verified' => true,
		) );
		$this->assertInstanceOf( \Autorizenter\Core\Identity::class, $result );
	}

	// --- Auth methods --------------------------------------------------------

	public function test_build_post_body_auth(): void {
		$oidc   = $this->make_oidc( array( 'auth_method' => 'post' ) );
		$result = $oidc->build_token_request_public(
			array( 'grant_type' => 'authorization_code', 'code' => 'ABC' ),
			'https://idp.example.org/token'
		);
		$this->assertArrayHasKey( 'body', $result );
		$this->assertSame( 'test-client', $result['body']['client_id'] );
		$this->assertArrayNotHasKey( 'Authorization', $result['headers'] ?? array() );
	}

	public function test_build_basic_auth(): void {
		$settings = new Settings();
		$secret   = $settings->encrypt( 'mysecret' );
		$oidc     = $this->make_oidc( array( 'auth_method' => 'basic', 'client_secret' => $secret ) );
		$result   = $oidc->build_token_request_public(
			array( 'grant_type' => 'authorization_code', 'code' => 'ABC' ),
			'https://idp.example.org/token'
		);
		$this->assertArrayHasKey( 'Authorization', $result['headers'] );
		$this->assertStringStartsWith( 'Basic ', $result['headers']['Authorization'] );
		$decoded = base64_decode( substr( $result['headers']['Authorization'], 6 ) );
		$this->assertSame( 'test-client:mysecret', $decoded );
		$this->assertArrayNotHasKey( 'client_secret', $result['body'] );
	}

	public function test_build_secret_jwt_auth(): void {
		$settings = new Settings();
		$secret   = $settings->encrypt( 'jwtsecret' );
		$oidc     = $this->make_oidc( array( 'auth_method' => 'secret_jwt', 'client_secret' => $secret ) );
		$result   = $oidc->build_token_request_public(
			array( 'grant_type' => 'authorization_code', 'code' => 'ABC' ),
			'https://idp.example.org/token'
		);
		$this->assertArrayHasKey( 'client_assertion_type', $result['body'] );
		$this->assertSame(
			'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
			$result['body']['client_assertion_type']
		);
		$this->assertArrayHasKey( 'client_assertion', $result['body'] );
		$this->assertSame( 3, count( explode( '.', $result['body']['client_assertion'] ) ) );
	}

	// --- Auto auth method detection ------------------------------------------

	private function inject_discovery( OIDC $oidc, array $doc ): void {
		$ref = new \ReflectionProperty( $oidc, 'discovery' );
		$ref->setAccessible( true );
		$ref->setValue( $oidc, $doc );
	}

	public function test_auto_picks_client_secret_basic_from_discovery(): void {
		$settings = new Settings();
		$secret   = $settings->encrypt( 'sec' );
		$oidc     = $this->make_oidc( array( 'auth_method' => 'auto', 'client_secret' => $secret ) );
		$this->inject_discovery( $oidc, array(
			'token_endpoint_auth_methods_supported' => array( 'client_secret_basic', 'client_secret_post' ),
		) );

		$result = $oidc->build_token_request_public(
			array( 'grant_type' => 'authorization_code', 'code' => 'X' ),
			'https://idp.example.org/token'
		);
		$this->assertArrayHasKey( 'Authorization', $result['headers'] );
		$this->assertStringStartsWith( 'Basic ', $result['headers']['Authorization'] );
		$this->assertArrayNotHasKey( 'client_secret', $result['body'] );
	}

	public function test_auto_picks_client_secret_jwt_when_preferred(): void {
		$settings = new Settings();
		$secret   = $settings->encrypt( 'sec' );
		$oidc     = $this->make_oidc( array( 'auth_method' => 'auto', 'client_secret' => $secret ) );
		$this->inject_discovery( $oidc, array(
			'token_endpoint_auth_methods_supported' => array( 'client_secret_jwt', 'client_secret_basic' ),
		) );

		$result = $oidc->build_token_request_public(
			array( 'grant_type' => 'authorization_code', 'code' => 'X' ),
			'https://idp.example.org/token'
		);
		$this->assertArrayHasKey( 'client_assertion', $result['body'] );
	}

	public function test_auto_falls_back_to_post_when_no_discovery(): void {
		$oidc = $this->make_oidc( array( 'auth_method' => 'auto' ) );
		// No discovery injected — discovery() will return WP_Error.
		$result = $oidc->build_token_request_public(
			array( 'grant_type' => 'authorization_code', 'code' => 'X' ),
			'https://idp.example.org/token'
		);
		$this->assertSame( 'test-client', $result['body']['client_id'] );
		$this->assertArrayNotHasKey( 'Authorization', $result['headers'] ?? array() );
	}

	public function test_auto_falls_back_to_post_when_methods_list_empty(): void {
		$oidc = $this->make_oidc( array( 'auth_method' => 'auto' ) );
		$this->inject_discovery( $oidc, array(
			'token_endpoint_auth_methods_supported' => array(),
		) );

		$result = $oidc->build_token_request_public(
			array( 'grant_type' => 'authorization_code', 'code' => 'X' ),
			'https://idp.example.org/token'
		);
		$this->assertSame( 'test-client', $result['body']['client_id'] );
		$this->assertArrayNotHasKey( 'client_assertion', $result['body'] );
	}

	// --- Issuer URL override --------------------------------------------------

	public function test_issuer_url_override_takes_precedence(): void {
		$oidc = $this->make_oidc( array( 'issuer_url' => 'https://custom-issuer.example.org' ) );
		$this->inject_discovery( $oidc, array( 'issuer' => 'https://discovery-issuer.example.org' ) );

		$ref = new \ReflectionMethod( $oidc, 'issuer' );
		$ref->setAccessible( true );
		$this->assertSame( 'https://custom-issuer.example.org', $ref->invoke( $oidc ) );
	}

	public function test_issuer_falls_back_to_discovery(): void {
		$oidc = $this->make_oidc( array() );
		$this->inject_discovery( $oidc, array( 'issuer' => 'https://discovery-issuer.example.org' ) );

		$ref = new \ReflectionMethod( $oidc, 'issuer' );
		$ref->setAccessible( true );
		$this->assertSame( 'https://discovery-issuer.example.org', $ref->invoke( $oidc ) );
	}
}
