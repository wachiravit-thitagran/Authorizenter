<?php
/**
 * Tests for role mapping during provisioning, and private-site decisions.
 *
 * @package Authorizenter\Core\Tests
 */

namespace Authorizenter\Core\Tests;

use Authorizenter\Core\Settings;
use Authorizenter\Core\Org_Policy;
use Authorizenter\Core\User_Mapper;
use Authorizenter\Core\Private_Site;
use Authorizenter\Core\Identity;
use PHPUnit\Framework\TestCase;

class RoleMapTest extends TestCase {

	/** @var User_Mapper */
	private $mapper;

	protected function setUp(): void {
		azr_test_reset();
		$settings     = new Settings();
		$this->mapper = new User_Mapper( $settings, new Org_Policy( $settings ) );
	}

	private function id( string $provider, string $email ): Identity {
		return new Identity( $provider, array( 'email' => $email, 'email_verified' => true ) );
	}

	public function test_default_role_when_no_map(): void {
		$cfg  = array( 'default_role' => 'subscriber', 'role_map' => array() );
		$role = $this->mapper->resolve_role( $this->id( 'google', 'a@psu.ac.th' ), $cfg );
		$this->assertSame( 'subscriber', $role );
	}

	public function test_domain_match_wins(): void {
		$cfg = array(
			'default_role' => 'subscriber',
			'role_map'     => array(
				array( 'match' => 'domain:staff.psu.ac.th', 'role' => 'editor' ),
			),
		);
		$this->assertSame( 'editor', $this->mapper->resolve_role( $this->id( 'google', 'p@staff.psu.ac.th' ), $cfg ) );
		$this->assertSame( 'subscriber', $this->mapper->resolve_role( $this->id( 'google', 'p@student.psu.ac.th' ), $cfg ) );
	}

	public function test_provider_match(): void {
		$cfg = array(
			'default_role' => 'subscriber',
			'role_map'     => array( array( 'match' => 'provider:oidc', 'role' => 'author' ) ),
		);
		$this->assertSame( 'author', $this->mapper->resolve_role( $this->id( 'oidc', 'x@psu.ac.th' ), $cfg ) );
	}

	public function test_first_match_wins(): void {
		$cfg = array(
			'default_role' => 'subscriber',
			'role_map'     => array(
				array( 'match' => 'email:boss@psu.ac.th', 'role' => 'administrator' ),
				array( 'match' => 'domain:psu.ac.th', 'role' => 'editor' ),
			),
		);
		$this->assertSame( 'administrator', $this->mapper->resolve_role( $this->id( 'google', 'boss@psu.ac.th' ), $cfg ) );
		$this->assertSame( 'editor', $this->mapper->resolve_role( $this->id( 'google', 'other@psu.ac.th' ), $cfg ) );
	}

	public function test_email_matcher(): void {
		$cfg = array(
			'default_role' => 'subscriber',
			'role_map'     => array( array( 'match' => 'email:vip@psu.ac.th', 'role' => 'editor' ) ),
		);
		$this->assertSame( 'editor', $this->mapper->resolve_role( $this->id( 'google', 'vip@psu.ac.th' ), $cfg ) );
		$this->assertSame( 'subscriber', $this->mapper->resolve_role( $this->id( 'google', 'other@psu.ac.th' ), $cfg ) );
	}

	public function test_malformed_rules_are_ignored(): void {
		$cfg = array(
			'default_role' => 'subscriber',
			'role_map'     => array(
				array( 'match' => '', 'role' => 'editor' ),        // empty match.
				array( 'match' => 'domain:psu.ac.th' ),            // missing role.
				array( 'match' => 'nocolon', 'role' => 'author' ), // invalid matcher.
			),
		);
		$this->assertSame( 'subscriber', $this->mapper->resolve_role( $this->id( 'google', 'a@psu.ac.th' ), $cfg ) );
	}

	public function test_provision_applies_mapped_role(): void {
		update_option(
			Settings::OPTION,
			array(
				'users' => array(
					'auto_provision' => true,
					'default_role'   => 'subscriber',
					'role_map'       => array( array( 'match' => 'domain:psu.ac.th', 'role' => 'editor' ) ),
				),
			)
		);
		$settings = new Settings();
		$mapper   = new User_Mapper( $settings, new Org_Policy( $settings ) );

		$user = $mapper->resolve( $this->id( 'google', 'new@psu.ac.th' ) );
		$this->assertInstanceOf( \WP_User::class, $user );
		$this->assertSame( 'editor', $user->role );
	}

	public function test_local_regex_matcher(): void {
		$cfg = array(
			'default_role' => 'subscriber',
			'role_map'     => array( array( 'match' => 'local:^\d{10}$', 'role' => 'student' ) ),
		);
		// 10-digit local part -> student.
		$this->assertSame( 'student', $this->mapper->resolve_role( $this->id( 'oidc', '6312345678@psu.ac.th' ), $cfg ) );
		// 9 digits / non-numeric -> default.
		$this->assertSame( 'subscriber', $this->mapper->resolve_role( $this->id( 'oidc', '631234567@psu.ac.th' ), $cfg ) );
		$this->assertSame( 'subscriber', $this->mapper->resolve_role( $this->id( 'oidc', 'staff01@psu.ac.th' ), $cfg ) );
	}

	public function test_compound_provider_and_local_regex(): void {
		$cfg = array(
			'default_role' => 'subscriber',
			'role_map'     => array( array( 'match' => 'provider:oidc && local:^\d{10}$', 'role' => 'student' ) ),
		);
		// Right provider + 10-digit local -> student.
		$this->assertSame( 'student', $this->mapper->resolve_role( $this->id( 'oidc', '6312345678@psu.ac.th' ), $cfg ) );
		// Correct pattern but wrong provider -> default (AND fails).
		$this->assertSame( 'subscriber', $this->mapper->resolve_role( $this->id( 'google', '6312345678@psu.ac.th' ), $cfg ) );
	}

	public function test_or_groups_match_either_branch(): void {
		$cfg = array(
			'default_role' => 'subscriber',
			'role_map'     => array(
				array( 'match' => 'provider:oidc && local:^\d{10}$ || domain:alumni.psu.ac.th', 'role' => 'student' ),
			),
		);
		// Branch 1: oidc + 10-digit local.
		$this->assertSame( 'student', $this->mapper->resolve_role( $this->id( 'oidc', '6312345678@psu.ac.th' ), $cfg ) );
		// Branch 2: alumni domain (any provider).
		$this->assertSame( 'student', $this->mapper->resolve_role( $this->id( 'google', 'old@alumni.psu.ac.th' ), $cfg ) );
		// Neither branch.
		$this->assertSame( 'subscriber', $this->mapper->resolve_role( $this->id( 'google', 'random@gmail.com' ), $cfg ) );
	}

	public function test_and_has_higher_precedence_than_or(): void {
		$cfg = array(
			'default_role' => 'subscriber',
			// (provider:line && domain:psu.ac.th) || provider:oidc
			'role_map'     => array(
				array( 'match' => 'provider:line && domain:psu.ac.th || provider:oidc', 'role' => 'member' ),
			),
		);
		// oidc alone (right side of OR) -> matches.
		$this->assertSame( 'member', $this->mapper->resolve_role( $this->id( 'oidc', 'x@gmail.com' ), $cfg ) );
		// line but wrong domain -> left AND fails, no oidc -> default.
		$this->assertSame( 'subscriber', $this->mapper->resolve_role( $this->id( 'line', 'x@gmail.com' ), $cfg ) );
		// line + psu domain -> left AND matches.
		$this->assertSame( 'member', $this->mapper->resolve_role( $this->id( 'line', 'x@psu.ac.th' ), $cfg ) );
	}

	public function test_not_operator(): void {
		$cfg = array(
			'default_role' => 'subscriber',
			'role_map'     => array( array( 'match' => 'provider:oidc && !local:^\d{10}$', 'role' => 'staff' ) ),
		);
		// oidc that is NOT a 10-digit id -> staff.
		$this->assertSame( 'staff', $this->mapper->resolve_role( $this->id( 'oidc', 'somchai@psu.ac.th' ), $cfg ) );
		// oidc 10-digit id -> negated -> default.
		$this->assertSame( 'subscriber', $this->mapper->resolve_role( $this->id( 'oidc', '6312345678@psu.ac.th' ), $cfg ) );
	}

	public function test_parentheses_override_precedence(): void {
		$cfg = array(
			'default_role' => 'subscriber',
			'role_map'     => array(
				array( 'match' => '( provider:google || provider:facebook ) && domain:psu.ac.th', 'role' => 'social' ),
			),
		);
		$this->assertSame( 'social', $this->mapper->resolve_role( $this->id( 'google', 'a@psu.ac.th' ), $cfg ) );
		$this->assertSame( 'social', $this->mapper->resolve_role( $this->id( 'facebook', 'b@psu.ac.th' ), $cfg ) );
		// Right provider, wrong domain -> the AND fails.
		$this->assertSame( 'subscriber', $this->mapper->resolve_role( $this->id( 'google', 'c@gmail.com' ), $cfg ) );
		// Wrong provider, right domain.
		$this->assertSame( 'subscriber', $this->mapper->resolve_role( $this->id( 'oidc', 'd@psu.ac.th' ), $cfg ) );
	}

	public function test_quoted_atom_with_regex_alternation(): void {
		$cfg = array(
			'default_role' => 'subscriber',
			'role_map'     => array(
				array( 'match' => 'provider:oidc && "local:^(\d{10}|\d{13})$"', 'role' => 'student' ),
			),
		);
		$this->assertSame( 'student', $this->mapper->resolve_role( $this->id( 'oidc', '6312345678@psu.ac.th' ), $cfg ) );     // 10
		$this->assertSame( 'student', $this->mapper->resolve_role( $this->id( 'oidc', '6312345678901@psu.ac.th' ), $cfg ) );  // 13
		$this->assertSame( 'subscriber', $this->mapper->resolve_role( $this->id( 'oidc', '631234567@psu.ac.th' ), $cfg ) );   // 9
	}

	public function test_malformed_expression_does_not_match(): void {
		$cfg = array(
			'default_role' => 'subscriber',
			'role_map'     => array( array( 'match' => '( provider:oidc', 'role' => 'x' ) ), // unbalanced paren.
		);
		$this->assertSame( 'subscriber', $this->mapper->resolve_role( $this->id( 'oidc', 'a@psu.ac.th' ), $cfg ) );
	}

	public function test_regex_matcher_preserves_case(): void {
		$cfg = array(
			'default_role' => 'subscriber',
			// \D would be broken by lowercasing; ensure pattern is preserved.
			'role_map'     => array( array( 'match' => 'regex:^[a-z]+\d+@', 'role' => 'staff' ) ),
		);
		$this->assertSame( 'staff', $this->mapper->resolve_role( $this->id( 'oidc', 'abc123@psu.ac.th' ), $cfg ) );
	}

	public function test_wildcard_catch_all(): void {
		$cfg = array(
			'default_role' => 'subscriber',
			'role_map'     => array( array( 'match' => '*', 'role' => 'contributor' ) ),
		);
		$this->assertSame( 'contributor', $this->mapper->resolve_role( $this->id( 'facebook', 'z@any.com' ), $cfg ) );
	}

	public function test_private_site_decision(): void {
		update_option( Settings::OPTION, array( 'private_site' => array( 'enabled' => true ) ) );
		$ps = new Private_Site( new Settings() );

		$this->assertTrue( $ps->should_block( false, false, false ) );  // anon, normal page.
		$this->assertFalse( $ps->should_block( true, false, false ) );  // logged in.
		$this->assertFalse( $ps->should_block( false, true, false ) );  // login page.
		$this->assertFalse( $ps->should_block( false, false, true ) );  // explicitly allowed.
	}

	public function test_private_site_disabled_never_blocks(): void {
		update_option( Settings::OPTION, array( 'private_site' => array( 'enabled' => false ) ) );
		$ps = new Private_Site( new Settings() );
		$this->assertFalse( $ps->should_block( false, false, false ) );
	}

	public function test_email_regex_matches_digit_local_part(): void {
		$cfg = array(
			'default_role' => 'subscriber',
			'role_map'     => array(
				array( 'match' => 'email_regex:^\d+@abc\.co\.th$', 'role' => 'student' ),
				array( 'match' => 'email_regex:^[a-z]+\.[a-z]+@abc\.co\.th$', 'role' => 'staff' ),
			),
		);
		$this->assertSame( 'student', $this->mapper->resolve_role( $this->id( 'oidc', '6401234@abc.co.th' ), $cfg ) );
		$this->assertSame( 'staff', $this->mapper->resolve_role( $this->id( 'oidc', 'john.doe@abc.co.th' ), $cfg ) );
		$this->assertSame( 'subscriber', $this->mapper->resolve_role( $this->id( 'oidc', 'other@gmail.com' ), $cfg ) );
	}

	public function test_email_regex_invalid_pattern_does_not_crash(): void {
		$cfg = array(
			'default_role' => 'subscriber',
			'role_map'     => array( array( 'match' => 'email_regex:[invalid', 'role' => 'editor' ) ),
		);
		$this->assertSame( 'subscriber', $this->mapper->resolve_role( $this->id( 'google', 'a@b.com' ), $cfg ) );
	}

	public function test_dynamic_raw_claim_matcher(): void {
		$cfg = array(
			'default_role' => 'subscriber',
			'role_map'     => array(
				array( 'match' => 'fac_id:09', 'role' => 'student' ),
				array( 'match' => 'Department:IT', 'role' => 'editor' ),
			),
		);

		$id_student = new Identity( 'oidc', array( 'email' => 'a@psu.ac.th', 'raw' => array( 'fac_id' => '09' ) ) );
		$id_editor  = new Identity( 'oidc', array( 'email' => 'b@psu.ac.th', 'raw' => array( 'department' => 'IT' ) ) );
		$id_none    = new Identity( 'oidc', array( 'email' => 'c@psu.ac.th', 'raw' => array( 'fac_id' => '10' ) ) );

		$this->assertSame( 'student', $this->mapper->resolve_role( $id_student, $cfg ) );
		$this->assertSame( 'editor', $this->mapper->resolve_role( $id_editor, $cfg ) );
		$this->assertSame( 'subscriber', $this->mapper->resolve_role( $id_none, $cfg ) );
	}
}
