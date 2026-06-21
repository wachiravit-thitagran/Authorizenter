<?php
/**
 * JWT Verifier logic unit tests.
 *
 * @package Authorizenter\Core\Tests
 */

namespace Authorizenter\Core\Tests;

use PHPUnit\Framework\TestCase;
use Authorizenter\Core\JWT_Verifier;
use Firebase\JWT\JWT;
use WP_Error;

/**
 * Tests for the JWT_Verifier class.
 */
class JwtVerifierTest extends TestCase {

	private $verifier;

	protected function setUp(): void {
		parent::setUp();
		$this->verifier = new JWT_Verifier();
		$GLOBALS['__transients'] = array();
		$GLOBALS['__mock_wp_remote_get'] = array();
	}

	public function test_verify_fails_if_jwks_fetch_fails() {
		$GLOBALS['__mock_wp_remote_get']['https://example.com/jwks'] = new WP_Error( 'http_error', 'Error' );
		
		$result = $this->verifier->verify( 'header.payload.signature', 'https://example.com/jwks', 'issuer', 'audience' );
		
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'http_error', $result->get_error_code() );
	}

	public function test_verify_fails_if_jwks_format_invalid() {
		$GLOBALS['__mock_wp_remote_get']['https://example.com/jwks'] = array(
			'body' => '{"invalid":"json"}'
		);
		
		$result = $this->verifier->verify( 'header.payload.signature', 'https://example.com/jwks', 'issuer', 'audience' );
		
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'authorizenter_jwks_error', $result->get_error_code() );
	}
	
	public function test_verify_fails_on_invalid_token() {
		$GLOBALS['__mock_wp_remote_get']['https://example.com/jwks'] = array(
			'body' => json_encode( array(
				'keys' => array(
					array(
						'kty' => 'RSA',
						'alg' => 'RS256',
						'use' => 'sig',
						'kid' => '1',
						'n'   => 'abc',
						'e'   => 'AQAB'
					)
				)
			) )
		);
		
		$result = $this->verifier->verify( 'invalid.token.string', 'https://example.com/jwks', 'issuer', 'audience' );
		
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'authorizenter_jwt_invalid', $result->get_error_code() );
	}
}
