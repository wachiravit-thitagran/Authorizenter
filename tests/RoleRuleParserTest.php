<?php
/**
 * Exhaustive tests for the role-map boolean expression parser (role_match).
 *
 * Reference identity: provider "oidc", email "6312345678@psu.ac.th"
 *   -> provider:oidc TRUE, provider:google FALSE
 *   -> domain:psu.ac.th TRUE, domain:gmail.com FALSE
 *   -> local:^\d{10}$ TRUE, local:^\d{13}$ FALSE
 *
 * @package Authorizenter\Core\Tests
 */

namespace Authorizenter\Core\Tests;

use Authorizenter\Core\Settings;
use Authorizenter\Core\Org_Policy;
use Authorizenter\Core\User_Mapper;
use Authorizenter\Core\Identity;
use PHPUnit\Framework\TestCase;

class RoleRuleParserTest extends TestCase {

	/** @var User_Mapper */
	private $mapper;

	/** @var Identity */
	private $id;

	protected function setUp(): void {
		azr_test_reset();
		$settings     = new Settings();
		$this->mapper = new User_Mapper( $settings, new Org_Policy( $settings ) );
		$this->id     = new Identity(
			'oidc',
			array( 'email' => '6312345678@psu.ac.th', 'email_verified' => true, 'username' => 'student01' )
		);
	}

	/**
	 * Evaluate an expression against the reference identity.
	 */
	private function m( string $expr ): bool {
		$ref = new \ReflectionMethod( $this->mapper, 'role_match' );
		$ref->setAccessible( true );
		return (bool) $ref->invoke( $this->mapper, $expr, $this->id );
	}

	// --- Atoms -------------------------------------------------------------

	public function test_atom_types(): void {
		$this->assertTrue( $this->m( 'provider:oidc' ) );
		$this->assertFalse( $this->m( 'provider:google' ) );
		$this->assertTrue( $this->m( 'domain:psu.ac.th' ) );
		$this->assertTrue( $this->m( 'domain:ac.th' ) ); // subdomain match.
		$this->assertFalse( $this->m( 'domain:gmail.com' ) );
		$this->assertTrue( $this->m( 'email:6312345678@psu.ac.th' ) );
		$this->assertTrue( $this->m( 'username:student01' ) );
		$this->assertTrue( $this->m( 'local:^\d{10}$' ) );
		$this->assertFalse( $this->m( 'local:^\d{13}$' ) );
		$this->assertTrue( $this->m( 'regex:^\d+@psu' ) );
		$this->assertTrue( $this->m( '*' ) );
	}

	public function test_unknown_type_is_false(): void {
		$this->assertFalse( $this->m( 'bogus:whatever' ) );
		$this->assertFalse( $this->m( 'noseparator' ) );
	}

	// --- AND / OR / NOT ----------------------------------------------------

	public function test_and(): void {
		$this->assertTrue( $this->m( 'provider:oidc && domain:psu.ac.th' ) );
		$this->assertFalse( $this->m( 'provider:oidc && domain:gmail.com' ) );
		$this->assertFalse( $this->m( 'provider:google && domain:psu.ac.th' ) );
	}

	public function test_or(): void {
		$this->assertTrue( $this->m( 'provider:google || domain:psu.ac.th' ) );
		$this->assertTrue( $this->m( 'provider:oidc || domain:gmail.com' ) );
		$this->assertFalse( $this->m( 'provider:google || domain:gmail.com' ) );
	}

	public function test_not(): void {
		$this->assertTrue( $this->m( '!provider:google' ) );
		$this->assertFalse( $this->m( '!provider:oidc' ) );
		$this->assertTrue( $this->m( '!!provider:oidc' ) );
		$this->assertFalse( $this->m( '!!provider:google' ) );
	}

	// --- Precedence --------------------------------------------------------

	public function test_and_binds_tighter_than_or(): void {
		// false || (true && true) = true
		$this->assertTrue( $this->m( 'provider:google || provider:oidc && domain:psu.ac.th' ) );
		// (false && true) || true = true
		$this->assertTrue( $this->m( 'provider:google && domain:psu.ac.th || provider:oidc' ) );
		// (true && false) || false = false
		$this->assertFalse( $this->m( 'provider:oidc && domain:gmail.com || provider:google' ) );
	}

	public function test_not_binds_tighter_than_and(): void {
		// (!false) && true = true
		$this->assertTrue( $this->m( '!provider:google && domain:psu.ac.th' ) );
		// (!true) && true = false
		$this->assertFalse( $this->m( '!provider:oidc && domain:psu.ac.th' ) );
	}

	// --- Parentheses -------------------------------------------------------

	public function test_parentheses_override(): void {
		$this->assertTrue( $this->m( '( provider:google || provider:oidc ) && domain:psu.ac.th' ) );
		$this->assertFalse( $this->m( '( provider:google || provider:oidc ) && domain:gmail.com' ) );
		$this->assertTrue( $this->m( 'provider:oidc && ( local:^\d{10}$ || local:^\d{13}$ )' ) );
	}

	public function test_nested_and_negated_parentheses(): void {
		$this->assertTrue( $this->m( '( ( provider:oidc ) )' ) );
		$this->assertTrue( $this->m( '!( provider:google && domain:psu.ac.th )' ) );
		$this->assertFalse( $this->m( '!( provider:oidc && domain:psu.ac.th )' ) );
		$this->assertTrue( $this->m( '!( provider:google ) && ( domain:psu.ac.th || provider:line )' ) );
	}

	// --- Quoting -----------------------------------------------------------

	public function test_quoted_atom_with_alternation(): void {
		$this->assertTrue( $this->m( 'provider:oidc && "local:^(\d{10}|\d{13})$"' ) );
		$this->assertFalse( $this->m( 'provider:oidc && "local:^(\d{13})$"' ) );
	}

	public function test_quoted_preserves_backslashes(): void {
		// \d must survive quoting (not be unescaped to "d").
		$this->assertTrue( $this->m( '"local:^\d+$"' ) );
		$this->assertFalse( $this->m( '"local:^[a-z]+$"' ) );
	}

	// --- Whitespace --------------------------------------------------------

	public function test_whitespace_insensitivity(): void {
		$this->assertTrue( $this->m( 'provider:oidc&&domain:psu.ac.th' ) );      // no spaces.
		$this->assertTrue( $this->m( '   provider:oidc   ' ) );                  // padded.
		$this->assertTrue( $this->m( 'provider:oidc   ||   provider:google' ) ); // wide spaces.
	}

	// --- Malformed expressions return false --------------------------------

	public function test_malformed_returns_false(): void {
		$this->assertFalse( $this->m( '( provider:oidc' ) );      // unbalanced open.
		$this->assertFalse( $this->m( 'provider:oidc )' ) );      // unbalanced close.
		$this->assertFalse( $this->m( '"local:^\d+$' ) );         // unterminated quote.
		$this->assertFalse( $this->m( '&& provider:oidc' ) );     // leading operator.
		$this->assertFalse( $this->m( '()' ) );                   // empty parentheses.
		$this->assertFalse( $this->m( '' ) );                     // empty expression.
	}
}
