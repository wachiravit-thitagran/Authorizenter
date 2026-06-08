<?php
/**
 * Tests for User_Mapper: linking and auto-provisioning.
 *
 * @package Autorizenter\Core\Tests
 */

namespace Autorizenter\Core\Tests;

use Autorizenter\Core\Settings;
use Autorizenter\Core\Org_Policy;
use Autorizenter\Core\User_Mapper;
use Autorizenter\Core\Identity;
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

	public function test_links_existing_user_by_provider_and_sub(): void {
		$this->users_config( array( 'auto_provision' => true, 'link_by_email' => true ) );
		azr_test_make_user( 5, array( 'read' => true ), 'old@psu.ac.th' );
		update_user_meta( 5, 'autorizenter_link_google', 'SUB-123' );

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
		$this->assertSame( 'NEW-SUB', get_user_meta( 7, 'autorizenter_link_google', true ) );
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
		$this->assertSame( 'G-9', get_user_meta( $user->ID, 'autorizenter_link_google', true ) );
	}

	public function test_auto_provision_disabled_returns_error(): void {
		$this->users_config( array( 'auto_provision' => false ) );

		$identity = new Identity( 'google', array( 'sub' => 'X', 'email' => 'nobody@psu.ac.th', 'email_verified' => true ) );
		$result   = $this->mapper->resolve( $identity );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'autorizenter_no_account', $result->get_error_code() );
	}

	public function test_context_can_override_auto_provision_off(): void {
		$this->users_config( array( 'auto_provision' => true ) );

		$identity = new Identity( 'oidc', array( 'sub' => 'Y', 'email' => 'x@psu.ac.th', 'email_verified' => true ) );
		$result   = $this->mapper->resolve( $identity, array( 'auto_provision' => false ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}
}
