<?php
/**
 * Tests for User_Mapper: linking and auto-provisioning.
 *
 * @package Authorizenter\Core\Tests
 */

namespace Authorizenter\Core\Tests;

use Authorizenter\Core\Settings;
use Authorizenter\Core\Org_Policy;
use Authorizenter\Core\User_Mapper;
use Authorizenter\Core\Identity;
use PHPUnit\Framework\TestCase;

class UserMapperTest extends TestCase {

	/** @var User_Mapper */
	private $mapper;

	protected function setUp(): void {
		azr_test_reset();
		$settings     = new Settings();
		$this->mapper = new User_Mapper( $settings, new Org_Policy( $settings ) );
	}

	private function users_config( array $cfg ): void {
		update_option( Settings::OPTION, array( 'users' => $cfg ) );
	}

	public function test_approved_role_overrides_default_and_role_map(): void {
		update_option(
			Settings::OPTION,
			array( 'access' => array( 'approved_roles' => array( 'x@psu.ac.th' => 'editor' ) ) )
		);
		$settings = new Settings();
		$mapper   = new User_Mapper( $settings, new Org_Policy( $settings ) );
		$identity = new Identity( 'oidc', array( 'email' => 'x@psu.ac.th', 'email_verified' => true ) );

		$ref = new \ReflectionMethod( $mapper, 'resolve_role' );
		$ref->setAccessible( true );
		$role = $ref->invoke(
			$mapper,
			$identity,
			array(
				'default_role' => 'subscriber',
				'role_map'     => array( array( 'match' => 'domain:psu.ac.th', 'role' => 'author' ) ),
			)
		);

		$this->assertSame( 'editor', $role );
	}

	public function test_resolve_role_without_approved_role_uses_role_map(): void {
		$settings = new Settings();
		$mapper   = new User_Mapper( $settings, new Org_Policy( $settings ) );
		$identity = new Identity( 'oidc', array( 'email' => 'y@psu.ac.th', 'email_verified' => true ) );

		$ref = new \ReflectionMethod( $mapper, 'resolve_role' );
		$ref->setAccessible( true );
		$role = $ref->invoke(
			$mapper,
			$identity,
			array(
				'default_role' => 'subscriber',
				'role_map'     => array( array( 'match' => 'domain:psu.ac.th', 'role' => 'author' ) ),
			)
		);

		$this->assertSame( 'author', $role );
	}

	public function test_links_existing_user_by_provider_and_sub(): void {
		$this->users_config( array( 'auto_provision' => true, 'link_by_email' => true ) );
		azr_test_make_user( 5, array( 'read' => true ), 'old@psu.ac.th' );
		update_user_meta( 5, 'authorizenter_link_google', 'SUB-123' );

		$identity = new Identity( 'google', array( 'sub' => 'SUB-123', 'email' => 'old@psu.ac.th', 'email_verified' => true ) );
		$user     = $this->mapper->resolve( $identity );

		$this->assertInstanceOf( \WP_User::class, $user );
		$this->assertSame( 5, $user->ID );
	}

	public function test_links_by_verified_email(): void {
		$this->users_config( array( 'auto_provision' => true, 'link_by_email' => true ) );
		azr_test_make_user( 7, array( 'read' => true ), 'staff@psu.ac.th' );

		$identity = new Identity( 'google', array( 'sub' => 'NEW-SUB', 'email' => 'staff@psu.ac.th', 'email_verified' => true ) );
		$user     = $this->mapper->resolve( $identity );

		$this->assertSame( 7, $user->ID );
		// Link is stored for next time.
		$this->assertSame( 'NEW-SUB', get_user_meta( 7, 'authorizenter_link_google', true ) );
	}

	public function test_unverified_email_does_not_link(): void {
		$this->users_config( array( 'auto_provision' => true, 'link_by_email' => true ) );
		azr_test_make_user( 8, array( 'read' => true ), 'taken@psu.ac.th' );

		// Different (unverified) email so no collision with the existing user.
		$identity = new Identity( 'facebook', array( 'sub' => 'FB1', 'email' => 'fresh@psu.ac.th', 'email_verified' => false ) );
		$user     = $this->mapper->resolve( $identity );

		$this->assertNotSame( 8, $user->ID ); // a new user was provisioned.
	}

	public function test_auto_provision_creates_user_and_link(): void {
		$this->users_config( array( 'auto_provision' => true, 'default_role' => 'subscriber' ) );

		$identity = new Identity( 'google', array( 'sub' => 'G-9', 'email' => 'newbie@psu.ac.th', 'email_verified' => true ) );
		$user     = $this->mapper->resolve( $identity );

		$this->assertInstanceOf( \WP_User::class, $user );
		$this->assertSame( 'newbie@psu.ac.th', $user->user_email );
		$this->assertSame( 'G-9', get_user_meta( $user->ID, 'authorizenter_link_google', true ) );
	}

	public function test_auto_provision_disabled_returns_error(): void {
		$this->users_config( array( 'auto_provision' => false ) );

		$identity = new Identity( 'google', array( 'sub' => 'X', 'email' => 'nobody@psu.ac.th', 'email_verified' => true ) );
		$result   = $this->mapper->resolve( $identity );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'authorizenter_no_account', $result->get_error_code() );
	}

	public function test_context_can_override_auto_provision_off(): void {
		$this->users_config( array( 'auto_provision' => true ) );

		$identity = new Identity( 'oidc', array( 'sub' => 'Y', 'email' => 'x@psu.ac.th', 'email_verified' => true ) );
		$result   = $this->mapper->resolve( $identity, array( 'auto_provision' => false ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_link_by_username_finds_existing_user(): void {
		update_option( Settings::OPTION, array(
			'users'     => array( 'auto_provision' => false, 'link_by_email' => false ),
			'providers' => array( 'oidc' => array( 'link_by_username' => true ) ),
		) );
		azr_test_make_user( 20, array( 'read' => true ), 'u@example.test' );

		$identity = new Identity( 'oidc', array(
			'sub'            => 'OIDC-SUB-1',
			'email'          => 'u@example.test',
			'email_verified' => true,
			'username'       => 'user20',
		) );
		$settings = new Settings();
		$mapper   = new User_Mapper( $settings, new Org_Policy( $settings ) );
		$user     = $mapper->resolve( $identity );

		$this->assertInstanceOf( \WP_User::class, $user );
		$this->assertSame( 20, $user->ID );
		$this->assertSame( 'OIDC-SUB-1', get_user_meta( 20, 'authorizenter_link_oidc', true ) );
	}

	public function test_provision_stores_first_and_last_name(): void {
		update_option( Settings::OPTION, array(
			'users' => array( 'auto_provision' => true, 'link_by_email' => false, 'default_role' => 'subscriber' ),
		) );
		$identity = new Identity( 'oidc', array(
			'sub'            => 'SUB-NEW',
			'email'          => 'new@example.test',
			'first_name'     => 'Somchai',
			'last_name'      => 'Jaidee',
			'email_verified' => true,
		) );
		$settings = new Settings();
		$mapper   = new User_Mapper( $settings, new Org_Policy( $settings ) );
		$user     = $mapper->resolve( $identity );

		$this->assertInstanceOf( \WP_User::class, $user );
		$this->assertSame( 'Somchai', get_user_meta( $user->ID, 'first_name', true ) );
		$this->assertSame( 'Jaidee', get_user_meta( $user->ID, 'last_name', true ) );
	}

	public function test_name_update_always_overwrites(): void {
		update_option( Settings::OPTION, array(
			'users'     => array( 'auto_provision' => false, 'link_by_email' => false ),
			'providers' => array( 'oidc' => array( 'name_update' => 'always' ) ),
		) );
		azr_test_make_user( 30, array( 'read' => true ), 'u30@example.test' );
		update_user_meta( 30, 'authorizenter_link_oidc', 'SUB-30' );
		update_user_meta( 30, 'first_name', 'OldFirst' );
		update_user_meta( 30, 'last_name', 'OldLast' );

		$identity = new Identity( 'oidc', array(
			'sub'        => 'SUB-30',
			'first_name' => 'NewFirst',
			'last_name'  => 'NewLast',
		) );
		$settings = new Settings();
		$mapper   = new User_Mapper( $settings, new Org_Policy( $settings ) );
		$mapper->resolve( $identity );

		$this->assertSame( 'NewFirst', get_user_meta( 30, 'first_name', true ) );
		$this->assertSame( 'NewLast', get_user_meta( 30, 'last_name', true ) );
	}

	public function test_name_update_if_empty_does_not_overwrite(): void {
		update_option( Settings::OPTION, array(
			'users'     => array( 'auto_provision' => false, 'link_by_email' => false ),
			'providers' => array( 'oidc' => array( 'name_update' => 'if_empty' ) ),
		) );
		azr_test_make_user( 31, array( 'read' => true ), 'u31@example.test' );
		update_user_meta( 31, 'authorizenter_link_oidc', 'SUB-31' );
		update_user_meta( 31, 'first_name', 'KeepThis' );
		update_user_meta( 31, 'last_name', '' );

		$identity = new Identity( 'oidc', array(
			'sub'        => 'SUB-31',
			'first_name' => 'ShouldNotChange',
			'last_name'  => 'ShouldSet',
		) );
		$settings = new Settings();
		$mapper   = new User_Mapper( $settings, new Org_Policy( $settings ) );
		$mapper->resolve( $identity );

		$this->assertSame( 'KeepThis', get_user_meta( 31, 'first_name', true ) );
		$this->assertSame( 'ShouldSet', get_user_meta( 31, 'last_name', true ) );
	}

	public function test_name_update_none_does_not_change(): void {
		update_option( Settings::OPTION, array(
			'users'     => array( 'auto_provision' => false, 'link_by_email' => false ),
			'providers' => array( 'oidc' => array( 'name_update' => 'none' ) ),
		) );
		azr_test_make_user( 32, array( 'read' => true ), 'u32@example.test' );
		update_user_meta( 32, 'authorizenter_link_oidc', 'SUB-32' );
		update_user_meta( 32, 'first_name', 'Stay' );
		update_user_meta( 32, 'last_name', 'Same' );

		$identity = new Identity( 'oidc', array(
			'sub'        => 'SUB-32',
			'first_name' => 'Changed',
			'last_name'  => 'Changed',
		) );
		$settings = new Settings();
		$mapper   = new User_Mapper( $settings, new Org_Policy( $settings ) );
		$mapper->resolve( $identity );

		$this->assertSame( 'Stay', get_user_meta( 32, 'first_name', true ) );
		$this->assertSame( 'Same', get_user_meta( 32, 'last_name', true ) );
	}

	public function test_custom_role_condition_filter(): void {
		$GLOBALS['__mock_filters']['authorizenter_custom_role_condition'] = function( $matched, $type, $value, $identity ) {
			if ( 'group' === $type && 'admin' === $value && 'expected_sub' === $identity->sub ) {
				return true;
			}
			return $matched;
		};

		$settings = new Settings();
		$mapper   = new User_Mapper( $settings, new Org_Policy( $settings ) );
		
		$ref = new \ReflectionMethod( $mapper, 'role_condition' );
		$ref->setAccessible( true );
		
		$matched_true = $ref->invoke( $mapper, 'group:admin', new Identity( 'oidc', array( 'sub' => 'expected_sub' ) ) );
		$this->assertTrue( $matched_true );
		
		$matched_false = $ref->invoke( $mapper, 'group:admin', new Identity( 'oidc', array( 'sub' => 'other' ) ) );
		$this->assertFalse( $matched_false );
	}

	public function test_generate_username_filter(): void {
		$GLOBALS['__mock_filters']['authorizenter_generate_username'] = function( $base, $identity, $email ) {
			return 'custom_' . $identity->provider;
		};

		$settings = new Settings();
		$mapper   = new User_Mapper( $settings, new Org_Policy( $settings ) );
		$identity = new Identity( 'github', array( 'sub' => '123' ) );

		$ref = new \ReflectionMethod( $mapper, 'username_from' );
		$ref->setAccessible( true );
		$base = $ref->invoke( $mapper, $identity, 'test@example.com' );

		$this->assertSame( 'custom_github', $base );
	}

	public function test_sync_user_name_data_filter(): void {
		azr_test_make_user( 40, array( 'read' => true ), 'u40@example.test' );
		
		$GLOBALS['__mock_filters']['authorizenter_sync_user_name_data'] = function( $update, $user, $identity, $mode ) {
			$update['first_name'] = 'FilteredFirst';
			$update['last_name']  = 'FilteredLast';
			return $update;
		};

		$identity = new Identity( 'oidc', array(
			'sub'        => 'SUB-40',
			'first_name' => 'OriginalFirst',
			'last_name'  => 'OriginalLast',
		) );
		
		$settings = new Settings();
		$mapper   = new User_Mapper( $settings, new Org_Policy( $settings ) );
		
		$ref = new \ReflectionMethod( $mapper, 'maybe_update_name' );
		$ref->setAccessible( true );
		$ref->invoke( $mapper, get_user_by( 'id', 40 ), $identity, array( 'name_update' => 'always' ) );

		$this->assertSame( 'FilteredFirst', get_user_meta( 40, 'first_name', true ) );
		$this->assertSame( 'FilteredLast', get_user_meta( 40, 'last_name', true ) );
	}

	public function test_user_name_updated_action_fires(): void {
		azr_test_make_user( 41, array( 'read' => true ), 'u41@example.test' );

		$fired = false;
		$GLOBALS['__mock_actions']['authorizenter_user_name_updated'] = function( $user, $identity, $update ) use ( &$fired ) {
			$fired = true;
		};

		$identity = new Identity( 'oidc', array(
			'sub'        => 'SUB-41',
			'first_name' => 'NewFirst',
			'last_name'  => 'NewLast',
		) );
		
		$settings = new Settings();
		$mapper   = new User_Mapper( $settings, new Org_Policy( $settings ) );
		
		$ref = new \ReflectionMethod( $mapper, 'maybe_update_name' );
		$ref->setAccessible( true );
		$ref->invoke( $mapper, get_user_by( 'id', 41 ), $identity, array( 'name_update' => 'always' ) );

		$this->assertTrue( $fired );
	}
}
