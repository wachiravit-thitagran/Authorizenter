<?php
/**
 * Tests for Org_Policy: domain matching, verified email, trusted providers,
 * and the per-context capability gate.
 *
 * @package Autorizenter\Core\Tests
 */

namespace Autorizenter\Core\Tests;

use Autorizenter\Core\Settings;
use Autorizenter\Core\Org_Policy;
use Autorizenter\Core\Identity;
use PHPUnit\Framework\TestCase;

class OrgPolicyTest extends TestCase {

	/** @var Org_Policy */
	private $policy;

	protected function setUp(): void {
		azr_test_reset();
		$this->policy = new Org_Policy( new Settings() );
	}

	private function identity( array $data ): Identity {
		return new Identity( $data['provider'] ?? 'google', $data );
	}

	public function test_no_domains_allows_any_authenticated_user(): void {
		$ctx = $this->context( array( 'allowed_domains' => array() ) );
		$id  = $this->identity( array( 'email' => 'anyone@gmail.com', 'email_verified' => true ) );

		$this->assertTrue( $this->policy->is_allowed( $id, $ctx ) );
	}

	public function test_disabled_policy_allows_any_domain(): void {
		$ctx = $this->context(
			array(
				'policy_enabled'  => false, // opt-in: off.
				'allowed_domains' => array( 'psu.ac.th' ),
			)
		);
		$id = $this->identity( array( 'email' => 'outsider@gmail.com', 'email_verified' => true ) );

		$this->assertTrue( $this->policy->is_allowed( $id, $ctx ) );
	}

	public function test_matching_domain_is_allowed(): void {
		$ctx = $this->context( array( 'allowed_domains' => array( 'psu.ac.th' ) ) );
		$id  = $this->identity( array( 'email' => 'user@psu.ac.th', 'email_verified' => true ) );

		$this->assertTrue( $this->policy->is_allowed( $id, $ctx ) );
	}

	public function test_subdomain_is_allowed(): void {
		$ctx = $this->context( array( 'allowed_domains' => array( 'psu.ac.th' ) ) );
		$id  = $this->identity( array( 'email' => 'student@sci.psu.ac.th', 'email_verified' => true ) );

		$this->assertTrue( $this->policy->is_allowed( $id, $ctx ) );
	}

	public function test_non_matching_domain_is_denied(): void {
		$ctx = $this->context( array( 'allowed_domains' => array( 'psu.ac.th' ) ) );
		$id  = $this->identity( array( 'email' => 'user@gmail.com', 'email_verified' => true ) );

		$result = $this->policy->is_allowed( $id, $ctx );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'autorizenter_denied', $result->get_error_code() );
	}

	public function test_unverified_email_is_denied_when_domains_set(): void {
		$ctx = $this->context(
			array(
				'allowed_domains'        => array( 'psu.ac.th' ),
				'require_verified_email' => true,
			)
		);
		$id  = $this->identity( array( 'email' => 'user@psu.ac.th', 'email_verified' => false ) );

		$this->assertInstanceOf( \WP_Error::class, $this->policy->is_allowed( $id, $ctx ) );
	}

	public function test_trusted_provider_bypasses_domain_check(): void {
		$ctx = $this->context(
			array(
				'allowed_domains'   => array( 'psu.ac.th' ),
				'trusted_providers' => array( 'oidc' ),
			)
		);
		$id  = $this->identity( array( 'provider' => 'oidc', 'email' => 'user@gmail.com', 'email_verified' => true ) );

		$this->assertTrue( $this->policy->is_allowed( $id, $ctx ) );
	}

	public function test_google_hd_required_and_matching(): void {
		$ctx = $this->context(
			array(
				'allowed_domains'   => array( 'psu.ac.th' ),
				'require_google_hd' => true,
			)
		);
		$ok  = $this->identity( array( 'provider' => 'google', 'email' => 'u@psu.ac.th', 'email_verified' => true, 'hd' => 'psu.ac.th' ) );
		$bad = $this->identity( array( 'provider' => 'google', 'email' => 'u@psu.ac.th', 'email_verified' => true, 'hd' => '' ) );

		$this->assertTrue( $this->policy->is_allowed( $ok, $ctx ) );
		$this->assertInstanceOf( \WP_Error::class, $this->policy->is_allowed( $bad, $ctx ) );
	}

	public function test_global_path_uses_settings_when_no_context(): void {
		update_option(
			\Autorizenter\Core\Settings::OPTION,
			array( 'policy' => array( 'enabled' => true, 'allowed_domains' => array( 'psu.ac.th' ), 'require_verified_email' => true ) )
		);
		$id = $this->identity( array( 'email' => 'u@psu.ac.th', 'email_verified' => true ) );

		// No context arg -> reads global settings.
		$this->assertTrue( $this->policy->is_allowed( $id ) );
	}

	public function test_global_disabled_by_default_allows_everyone(): void {
		// Empty settings => policy.enabled defaults to false.
		$id = $this->identity( array( 'email' => 'outsider@gmail.com', 'email_verified' => false ) );
		$this->assertTrue( $this->policy->is_allowed( $id ) );
	}

	public function test_blocked_list_denies_even_when_policy_disabled(): void {
		update_option(
			\Autorizenter\Core\Settings::OPTION,
			array( 'access' => array( 'blocked' => array( 'banned@psu.ac.th' ) ) )
		);
		$id     = $this->identity( array( 'email' => 'banned@psu.ac.th', 'email_verified' => true ) );
		$result = $this->policy->is_allowed( $id );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'autorizenter_blocked', $result->get_error_code() );
	}

	public function test_capability_gate_allows_capable_user(): void {
		$user = azr_test_make_user( 1, array( 'manage_options' => true, 'read' => true ) );
		$ctx  = $this->context( array( 'required_capability' => 'manage_options' ) );

		$this->assertTrue( $this->policy->check_capability( $user, $ctx ) );
	}

	public function test_capability_gate_denies_incapable_user(): void {
		$user = azr_test_make_user( 2, array( 'read' => true ) ); // subscriber.
		$ctx  = $this->context( array( 'required_capability' => 'manage_options' ) );

		$result = $this->policy->check_capability( $user, $ctx );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'autorizenter_insufficient_capability', $result->get_error_code() );
	}

	/**
	 * Build a resolved-context-like array with sensible defaults.
	 */
	private function context( array $overrides ): array {
		return array_merge(
			array(
				'id'                     => 'test',
				'policy_enabled'         => true, // tests exercise the enforced path.
				'allowed_domains'        => array(),
				'trusted_providers'      => array(),
				'require_verified_email' => true,
				'require_google_hd'      => false,
				'required_capability'    => 'read',
				'block_message'          => 'denied',
			),
			$overrides
		);
	}
}
