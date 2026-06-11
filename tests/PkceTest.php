<?php
/**
 * Tests for the PKCE S256 code challenge derivation, using the RFC 7636
 * Appendix B reference vector.
 *
 * @package Authorizenter\Core\Tests
 */

namespace Authorizenter\Core\Tests;

use Authorizenter\Core\Settings;
use Authorizenter\Core\Provider_Registry;
use Authorizenter\Core\Org_Policy;
use Authorizenter\Core\User_Mapper;
use Authorizenter\Core\OAuth_Engine;
use PHPUnit\Framework\TestCase;

class PkceTest extends TestCase {

	private function engine(): OAuth_Engine {
		azr_test_reset();
		$settings  = new Settings();
		$providers = new Provider_Registry( $settings );
		$policy    = new Org_Policy( $settings );
		$users     = new User_Mapper( $settings, $policy );
		return new OAuth_Engine( $settings, $providers, $policy, $users );
	}

	private function invoke( object $obj, string $method, array $args ) {
		$ref = new \ReflectionMethod( $obj, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $obj, $args );
	}

	public function test_pkce_challenge_matches_rfc7636_vector(): void {
		$verifier  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
		$expected  = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

		$challenge = $this->invoke( $this->engine(), 'pkce_challenge', array( $verifier ) );

		$this->assertSame( $expected, $challenge );
	}

	public function test_random_is_url_safe_and_unpadded(): void {
		$value = $this->invoke( $this->engine(), 'random', array( 24 ) );

		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_\-]+$/', $value );
		$this->assertStringNotContainsString( '=', $value );
	}
}
