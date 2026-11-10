<?php
namespace Authorizenter\Core\Tests;

use Authorizenter\Core\Oidc_Client;
use Authorizenter\Core\Settings;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;

class OidcActiveClientTest extends TestCase {
	private $requests;
	private $private_key;
	private $public_key;
	private $advertised_methods;
	protected function setUp(): void {
		azr_test_reset();
		$this->requests = array();
		$key = openssl_pkey_new( array( 'private_key_bits' => 2048 ) );
		openssl_pkey_export( $key, $this->private_key );
		$this->public_key = openssl_pkey_get_details( $key )['key'];
		$_REQUEST = array( 'code' => 'synthetic-code', 'state' => 'test-state' );
		$_SESSION = array( 'openid_connect_code_verifier' => str_repeat( 'v', 48 ) );
		$this->advertised_methods = array( 'client_secret_basic', 'client_secret_post', 'client_secret_jwt', 'private_key_jwt' );
		$GLOBALS['__mock_http'] = function ( $url, $args ) {
			$this->requests[] = array( $url, $args );
			if ( false !== strpos( $url, '.well-known' ) ) {
				return array( 'body' => json_encode( array(
					'issuer' => 'https://idp.example.test',
					'token_endpoint' => 'https://idp.example.test/token',
					'token_endpoint_auth_methods_supported' => $this->advertised_methods,
				) ) );
			}
			return array( 'body' => '{"error":"invalid_grant","error_description":"Synthetic token stop"}' );
		};
	}
	protected function tearDown(): void {
		unset( $GLOBALS['__mock_http'] );
		$_REQUEST = array();
		$_SESSION = array();
	}

	/** @dataProvider methods */
	public function test_complete_sends_exact_configured_authentication( $method, $configured = null ): void {
		$settings = new Settings();
		$result = ( new Oidc_Client( $settings ) )->complete( array(
			'client_id' => 'client', 'client_secret' => $settings->encrypt( str_repeat( 'secret', 8 ) ),
			'auth_method' => $configured ?? $method, 'private_key' => $settings->encrypt( $this->private_key ),
		), 'https://idp.example.test', 'https://example.test/callback', array( 'openid' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'Synthetic token stop', $result->get_error_message() );
		$this->assertCount( 2, $this->requests );
		list( $url, $request ) = $this->requests[1];
		$this->assertSame( 'https://idp.example.test/token', $url );
		parse_str( $request['body'], $body );
		$this->assertSame( 'synthetic-code', $body['code'] );
		$this->assertSame( str_repeat( 'v', 48 ), $body['code_verifier'] );
		if ( in_array( $method, array( 'auto', 'basic' ), true ) ) {
			$this->assertSame( 'Basic ' . base64_encode( 'client:' . str_repeat( 'secret', 8 ) ), $request['headers']['Authorization'] );
			$this->assertArrayNotHasKey( 'client_secret', $body );
		} elseif ( 'post' === $method ) {
			$this->assertArrayNotHasKey( 'Authorization', $request['headers'] );
			$this->assertSame( str_repeat( 'secret', 8 ), $body['client_secret'] );
		} else {
			$this->assertArrayNotHasKey( 'Authorization', $request['headers'] );
			$this->assertArrayNotHasKey( 'client_secret', $body );
			$this->assertSame( 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer', $body['client_assertion_type'] );
			$key = 'private_key_jwt' === $method ? new Key( $this->public_key, 'RS256' ) : new Key( str_repeat( 'secret', 8 ), 'HS256' );
			$claims = JWT::decode( $body['client_assertion'], $key );
			$this->assertSame( 'client', $claims->iss );
			$this->assertSame( 'client', $claims->sub );
			$this->assertSame( $url, $claims->aud );
			$this->assertNotEmpty( $claims->jti );
			$this->assertLessThanOrEqual( 120, $claims->exp - $claims->iat );
		}
	}
	public static function methods(): array {
		return array_map( static function ( $method ) { return array( $method ); }, array( 'auto', 'post', 'basic', 'secret_jwt', 'private_key_jwt' ) );
	}

	public function test_auto_uses_post_when_only_post_is_advertised(): void {
		$this->advertised_methods = array( 'client_secret_post' );
		$this->test_complete_sends_exact_configured_authentication( 'post', 'auto' );
	}

	public function test_explicit_unsupported_method_fails_before_token_request(): void {
		$this->advertised_methods = array( 'client_secret_post' );
		$result = ( new Oidc_Client( new Settings() ) )->complete( array( 'client_id' => 'client', 'auth_method' => 'basic' ), 'https://idp.example.test', 'https://example.test/cb', array( 'openid' ) );
		$this->assertSame( 'authorizenter_oidc_error', $result->get_error_code() );
		$this->assertStringContainsString( 'not supported', $result->get_error_message() );
		$this->assertCount( 1, $this->requests );
	}

	public function test_invalid_private_key_fails_before_network(): void {
		$result = ( new Oidc_Client( new Settings() ) )->complete( array( 'client_id' => 'client', 'auth_method' => 'private_key_jwt' ), 'https://idp.example.test', 'https://example.test/cb', array( 'openid' ) );
		$this->assertSame( 'authorizenter_oidc_private_key', $result->get_error_code() );
		$this->assertSame( array(), $this->requests );
	}

	public function test_each_assertion_has_a_fresh_replay_identifier(): void {
		$one = JWT::decode( Oidc_Client::client_assertion( 'client', 'https://idp.example.test/token', $this->private_key, 'RS256' ), new Key( $this->public_key, 'RS256' ) );
		$two = JWT::decode( Oidc_Client::client_assertion( 'client', 'https://idp.example.test/token', $this->private_key, 'RS256' ), new Key( $this->public_key, 'RS256' ) );
		$this->assertNotSame( $one->jti, $two->jti );
	}
}
