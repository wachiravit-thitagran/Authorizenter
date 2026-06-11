<?php
/**
 * Tests for approved/blocked/pending access lists.
 *
 * @package Authorizenter\Core\Tests
 */

namespace Authorizenter\Core\Tests;

use Authorizenter\Core\Settings;
use Authorizenter\Core\Access_List;
use Authorizenter\Core\Identity;
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
		$this->assertSame( 'authorizenter_blocked', $result->get_error_code() );
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
		$this->assertSame( 'authorizenter_not_approved', $result->get_error_code() );
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
		$this->assertSame( 'authorizenter_not_approved', $result->get_error_code() );
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

	public function test_trusted_provider_bypasses_approval_enforcement(): void {
		$list = $this->list_with( array( 'enabled' => true, 'approved' => array( 'psu.ac.th' ) ) );
		$id   = new Identity( 'oidc', array( 'email' => 'user@gmail.com', 'email_verified' => true ) );

		// Without trust: denied and added to pending.
		$result = $list->evaluate( $id );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'authorizenter_not_approved', $result->get_error_code() );

		// With trust: allowed outright, not added to pending again.
		azr_test_reset();
		$list   = $this->list_with( array( 'enabled' => true, 'approved' => array( 'psu.ac.th' ) ) );
		$result = $list->evaluate( $id, array( 'oidc' ) );
		$this->assertTrue( $result );
		$this->assertSame( array(), $list->entries( 'pending' ) );
	}

	public function test_trusted_provider_still_blocked_by_blocked_list(): void {
		$list = $this->list_with(
			array(
				'enabled' => true,
				'blocked' => array( 'banned@gmail.com' ),
			)
		);
		$id     = new Identity( 'oidc', array( 'email' => 'banned@gmail.com', 'email_verified' => true ) );
		$result = $list->evaluate( $id, array( 'oidc' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'authorizenter_blocked', $result->get_error_code() );
	}

	public function test_add_pending_returns_token(): void {
		$list  = $this->list_with( array( 'enabled' => true ) );
		$token = $list->add_pending( 'wait@example.com', array( 'provider' => 'google', 'name' => 'Jane' ) );
		$this->assertIsString( $token );
		$this->assertNotEmpty( $token );
	}

	public function test_evaluate_not_approved_error_contains_pending_token(): void {
		$list   = $this->list_with( array( 'enabled' => true, 'approved' => array( 'psu.ac.th' ) ) );
		$id     = new Identity( 'google', array( 'email' => 'outsider@gmail.com', 'email_verified' => true ) );
		$result = $list->evaluate( $id );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$data = (array) $result->get_error_data();
		$this->assertArrayHasKey( 'pending_token', $data );
		$this->assertNotEmpty( $data['pending_token'] );
	}

	public function test_save_pending_answers_stores_and_retrieves(): void {
		$list  = $this->list_with( array( 'enabled' => true ) );
		$token = $list->add_pending( 'student@example.com', array( 'provider' => 'oidc', 'name' => 'Bob' ) );

		$result = $list->save_pending_answers( $token, array( 'role' => 'student', 'dept' => 'Engineering' ) );
		$this->assertTrue( $result );

		$meta = $list->get_pending_meta();
		$this->assertArrayHasKey( 'student@example.com', $meta );
		$this->assertSame( 'student', $meta['student@example.com']['answers']['role'] );
		$this->assertSame( 'Engineering', $meta['student@example.com']['answers']['dept'] );
		$this->assertSame( 'oidc', $meta['student@example.com']['provider'] );
		$this->assertSame( 'Bob', $meta['student@example.com']['name'] );
	}

	public function test_save_pending_answers_invalid_token_returns_error(): void {
		$list   = $this->list_with( array( 'enabled' => true ) );
		$result = $list->save_pending_answers( 'totally-invalid-token', array( 'role' => 'x' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'authorizenter_invalid_token', $result->get_error_code() );
	}

	public function test_existing_account_skips_approval(): void {
		azr_test_make_user( 50, array( 'read' => true ), 'member@gmail.com' );
		$list   = $this->list_with( array( 'enabled' => true, 'approved' => array( 'psu.ac.th' ) ) );
		$result = $list->evaluate( $this->id( 'member@gmail.com' ) );

		$this->assertTrue( $result );
		$this->assertNotContains( 'member@gmail.com', $list->entries( 'pending' ) );
	}

	public function test_existing_account_bypass_can_be_disabled(): void {
		azr_test_make_user( 51, array( 'read' => true ), 'member2@gmail.com' );
		$list   = $this->list_with( array( 'enabled' => true, 'allow_existing' => false, 'approved' => array( 'psu.ac.th' ) ) );
		$result = $list->evaluate( $this->id( 'member2@gmail.com' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'authorizenter_not_approved', $result->get_error_code() );
	}

	public function test_blocked_beats_existing_account_bypass(): void {
		azr_test_make_user( 52, array( 'read' => true ), 'member3@gmail.com' );
		$list   = $this->list_with( array( 'enabled' => true, 'blocked' => array( 'member3@gmail.com' ) ) );
		$result = $list->evaluate( $this->id( 'member3@gmail.com' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'authorizenter_blocked', $result->get_error_code() );
	}

	public function test_no_existing_account_still_pending_when_default_on(): void {
		$list   = $this->list_with( array( 'enabled' => true, 'approved' => array( 'psu.ac.th' ) ) );
		$result = $list->evaluate( $this->id( 'noaccount@gmail.com' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'authorizenter_not_approved', $result->get_error_code() );
	}

	public function test_approve_stores_per_email_role(): void {
		$list = $this->list_with( array( 'enabled' => true, 'pending' => array( 'wait@x.com' ) ) );
		$list->approve( array( 'wait@x.com' ), array( 'wait@x.com' => 'editor' ) );

		$this->assertContains( 'wait@x.com', $list->entries( 'approved' ) );
		$this->assertSame( 'editor', $list->approved_role( 'wait@x.com' ) );
		$this->assertSame( '', $list->approved_role( 'someone-else@x.com' ) );
	}

	public function test_approve_without_role_leaves_role_empty(): void {
		$list = $this->list_with( array( 'enabled' => true, 'pending' => array( 'norole@x.com' ) ) );
		$list->approve( array( 'norole@x.com' ) );

		$this->assertSame( '', $list->approved_role( 'norole@x.com' ) );
	}

	public function test_release_after_provision_removes_exact_email(): void {
		$list = $this->list_with(
			array(
				'enabled'        => true,
				'allow_existing' => true,
				'approved'       => array( 'a@x.com', 'psu.ac.th' ),
				'approved_roles' => array( 'a@x.com' => 'editor' ),
			)
		);
		$list->release_after_provision( 'a@x.com' );

		$this->assertNotContains( 'a@x.com', $list->entries( 'approved' ) );
		$this->assertContains( 'psu.ac.th', $list->entries( 'approved' ) ); // domain entry kept.
		$this->assertSame( '', $list->approved_role( 'a@x.com' ) );
	}

	public function test_release_after_provision_kept_when_bypass_disabled(): void {
		$list = $this->list_with( array( 'enabled' => true, 'allow_existing' => false, 'approved' => array( 'a@x.com' ) ) );
		$list->release_after_provision( 'a@x.com' );

		$this->assertContains( 'a@x.com', $list->entries( 'approved' ) );
	}

	public function test_release_after_provision_ignores_domain_match(): void {
		$list = $this->list_with( array( 'enabled' => true, 'allow_existing' => true, 'approved' => array( 'psu.ac.th' ) ) );
		$list->release_after_provision( 'someone@psu.ac.th' );

		$this->assertContains( 'psu.ac.th', $list->entries( 'approved' ) );
	}

	public function test_approve_preserves_pending_meta(): void {
		$list = $this->list_with(
			array(
				'enabled' => true,
				'pending' => array( 'wait@example.com' ),
				'pending_meta' => array(
					'wait@example.com' => array(
						'provider' => 'google',
						'name'     => 'Alice',
						'answers'  => array( 'role' => 'staff' ),
					),
				),
			)
		);
		$list->approve( array( 'wait@example.com' ) );
		$this->assertArrayHasKey( 'wait@example.com', $list->get_pending_meta() );
	}

	public function test_approve_sends_email_with_default_template(): void {
		$list = $this->list_with( array( 'enabled' => true, 'pending' => array( 'wait@example.com' ) ) );
		$list->approve( array( 'wait@example.com' ) );

		$this->assertNotEmpty( $GLOBALS['__wp_mail'] );
		$mail = $GLOBALS['__wp_mail'][0];
		$this->assertSame( 'wait@example.com', $mail['to'] );
		$this->assertSame( 'Your account has been approved', $mail['subject'] );
		$this->assertStringContainsString( 'Test Site', $mail['message'] );
		$this->assertStringContainsString( 'wp-login.php', $mail['message'] );
	}

	public function test_approve_sends_email_with_custom_template_and_placeholders(): void {
		$list = $this->list_with( array(
			'enabled' => true,
			'pending' => array( 'custom@example.com' ),
			'approval_subject' => 'Welcome!',
			'approval_body' => 'Hello {user_email}, login here: {login_url} at {site_name}'
		) );
		$list->approve( array( 'custom@example.com' ) );

		$this->assertNotEmpty( $GLOBALS['__wp_mail'] );
		$mail = $GLOBALS['__wp_mail'][0];
		$this->assertSame( 'custom@example.com', $mail['to'] );
		$this->assertSame( 'Welcome!', $mail['subject'] );
		$this->assertSame( 'Hello custom@example.com, login here: https://example.test/wp-login.php at Test Site', $mail['message'] );
	}
}
