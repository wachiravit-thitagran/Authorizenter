<?php
/**
 * Tests for approved/blocked/pending access lists.
 *
 * @package Autorizenter\Core\Tests
 */

namespace Autorizenter\Core\Tests;

use Autorizenter\Core\Settings;
use Autorizenter\Core\Access_List;
use Autorizenter\Core\Identity;
use PHPUnit\Framework\TestCase;

class AccessListTest extends TestCase {

	protected function setUp(): void {
		azr_test_reset();
	}

	private function list_with( array $access ): Access_List {
		update_option( Settings::OPTION, array( 'access' => $access ) );
		return new Access_List( new Settings() );
	}

	private function id( string $email ): Identity {
		return new Identity( 'google', array( 'email' => $email, 'email_verified' => true ) );
	}

	public function test_blocked_email_is_denied(): void {
		$list   = $this->list_with( array( 'blocked' => array( 'bad@psu.ac.th' ) ) );
		$result = $list->evaluate( $this->id( 'bad@psu.ac.th' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'autorizenter_blocked', $result->get_error_code() );
	}

	public function test_blocked_domain_is_denied(): void {
		$list = $this->list_with( array( 'blocked' => array( 'spam.com' ) ) );
		$this->assertInstanceOf( \WP_Error::class, $list->evaluate( $this->id( 'x@spam.com' ) ) );
	}

	public function test_no_enforcement_allows_anyone(): void {
		$list = $this->list_with( array( 'enabled' => false ) );
		$this->assertTrue( $list->evaluate( $this->id( 'anyone@gmail.com' ) ) );
	}

	public function test_enforced_allows_approved_email(): void {
		$list = $this->list_with( array( 'enabled' => true, 'approved' => array( 'ok@psu.ac.th' ) ) );
		$this->assertTrue( $list->evaluate( $this->id( 'ok@psu.ac.th' ) ) );
	}

	public function test_enforced_allows_approved_domain_and_subdomain(): void {
		$list = $this->list_with( array( 'enabled' => true, 'approved' => array( 'psu.ac.th' ) ) );
		$this->assertTrue( $list->evaluate( $this->id( 'a@psu.ac.th' ) ) );
		$this->assertTrue( $list->evaluate( $this->id( 'b@sci.psu.ac.th' ) ) );
	}

	public function test_enforced_denies_and_collects_pending(): void {
		$list   = $this->list_with( array( 'enabled' => true, 'approved' => array( 'psu.ac.th' ) ) );
		$result = $list->evaluate( $this->id( 'outsider@gmail.com' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'autorizenter_not_approved', $result->get_error_code() );
		$this->assertContains( 'outsider@gmail.com', $list->entries( 'pending' ) );
	}

	public function test_approve_moves_pending_to_approved(): void {
		$list = $this->list_with( array( 'enabled' => true, 'pending' => array( 'wait@x.com' ) ) );
		$list->approve( array( 'wait@x.com' ) );

		$this->assertContains( 'wait@x.com', $list->entries( 'approved' ) );
		$this->assertNotContains( 'wait@x.com', $list->entries( 'pending' ) );
		$this->assertTrue( $list->evaluate( $this->id( 'wait@x.com' ) ) );
	}

	public function test_pending_is_deduplicated(): void {
		$list = $this->list_with( array( 'enabled' => true, 'approved' => array( 'psu.ac.th' ) ) );
		$list->evaluate( $this->id( 'dup@gmail.com' ) );
		$list->evaluate( $this->id( 'dup@gmail.com' ) );

		$pending = array_filter( $list->entries( 'pending' ), static fn( $e ) => 'dup@gmail.com' === $e );
		$this->assertCount( 1, $pending );
	}

	public function test_already_approved_not_added_to_pending(): void {
		$list = $this->list_with( array( 'enabled' => true, 'approved' => array( 'psu.ac.th' ) ) );
		$list->add_pending( 'inside@psu.ac.th' ); // already covered by approved domain.
		$this->assertNotContains( 'inside@psu.ac.th', $list->entries( 'pending' ) );
	}

	public function test_enforced_with_empty_email_denies_without_pending(): void {
		$list = $this->list_with( array( 'enabled' => true, 'approved' => array( 'psu.ac.th' ) ) );
		$id   = new Identity( 'line', array() ); // no email.

		$result = $list->evaluate( $id );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'autorizenter_not_approved', $result->get_error_code() );
		$this->assertSame( array(), $list->entries( 'pending' ) );
	}

	public function test_at_prefixed_domain_entry_is_normalized(): void {
		$list = $this->list_with( array( 'enabled' => true, 'approved' => array( '@psu.ac.th' ) ) );
		$this->assertTrue( $list->evaluate( $this->id( 'a@psu.ac.th' ) ) );
	}

	public function test_blocked_beats_approved(): void {
		$list = $this->list_with(
			array(
				'enabled'  => true,
				'approved' => array( 'psu.ac.th' ),
				'blocked'  => array( 'banned@psu.ac.th' ),
			)
		);
		$this->assertInstanceOf( \WP_Error::class, $list->evaluate( $this->id( 'banned@psu.ac.th' ) ) );
	}
}
