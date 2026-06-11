<?php
/**
 * Tests for OAuth_Engine error paths and redirect safety.
 *
 * The happy path (begin -> provider redirect -> callback) requires live network
 * calls to the provider, so these tests focus on the branches that do not.
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

class OAuthEngineTest extends TestCase {

	protected function setUp(): void {
		azr_test_reset();
		if ( ! defined( 'AUTHORIZENTER_REST_NAMESPACE' ) ) {
			define( 'AUTHORIZENTER_REST_NAMESPACE', 'authorizenter/v1' );
		}
	}

	private function engine(): OAuth_Engine {
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

	public function test_begin_unknown_provider_errors(): void {
		$result = $this->engine()->begin( 'does-not-exist', '' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'authorizenter_provider_disabled', $result->get_error_code() );
	}

	public function test_begin_disabled_provider_errors(): void {
		// google known but not enabled/configured.
		$result = $this->engine()->begin( 'google', '' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'authorizenter_provider_disabled', $result->get_error_code() );
	}

	public function test_begin_provider_not_allowed_in_context(): void {
		update_option(
			Settings::OPTION,
			array(
				'providers' => array( 'google' => array( 'enabled' => true, 'client_id' => 'g' ) ),
				'contexts'  => array(
					'admin' => array( 'providers' => array( 'oidc' ), 'required_capability' => 'manage_options' ),
				),
			)
		);

		$result = $this->engine()->begin( 'google', '', 'admin' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'authorizenter_provider_not_in_context', $result->get_error_code() );
	}

	public function test_callback_missing_params_errors(): void {
		$result = $this->engine()->handle_callback( '', '' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'authorizenter_callback_missing', $result->get_error_code() );
	}

	public function test_callback_invalid_state_errors(): void {
		$result = $this->engine()->handle_callback( 'some-code', 'unknown-state' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'authorizenter_state_invalid', $result->get_error_code() );
	}

	public function test_redirect_uri_points_at_callback_route(): void {
		$this->assertSame(
			'https://example.test/wp-json/authorizenter/v1/callback',
			$this->engine()->redirect_uri()
		);
	}

	public function test_sanitize_return_to_blocks_offsite(): void {
		$engine = $this->engine();
		$this->assertSame( '/dashboard', $this->invoke( $engine, 'sanitize_return_to', array( '/dashboard' ) ) );
		$this->assertSame( '', $this->invoke( $engine, 'sanitize_return_to', array( 'https://evil.example/x' ) ) );
		$this->assertSame( '', $this->invoke( $engine, 'sanitize_return_to', array( '' ) ) );
	}

	public function test_pending_redirect_used_for_not_approved_error(): void {
		$context = array(
			'id'               => 'default',
			'deny_redirect'    => 'https://example.test/denied/',
			'pending_redirect' => 'https://example.test/waiting/',
		);
		$error   = new \WP_Error( 'authorizenter_not_approved', 'Awaiting approval.', array( 'status' => 403 ) );

		$result = $this->invoke( $this->engine(), 'attach_deny_redirect', array( $error, $context ) );
		$data   = $result->get_error_data();
		$this->assertSame( 'https://example.test/waiting/', $data['redirect'] );
	}

	public function test_pending_redirect_not_used_for_other_denial(): void {
		$context = array(
			'id'               => 'default',
			'deny_redirect'    => 'https://example.test/denied/',
			'pending_redirect' => 'https://example.test/waiting/',
		);
		$error   = new \WP_Error( 'authorizenter_denied', 'Domain not allowed.', array( 'status' => 403 ) );

		$result = $this->invoke( $this->engine(), 'attach_deny_redirect', array( $error, $context ) );
		$data   = $result->get_error_data();
		$this->assertSame( 'https://example.test/denied/', $data['redirect'] );
	}

	public function test_pending_redirect_empty_falls_back_to_deny_redirect(): void {
		$context = array(
			'id'               => 'default',
			'deny_redirect'    => 'https://example.test/denied/',
			'pending_redirect' => '',
		);
		$error   = new \WP_Error( 'authorizenter_not_approved', 'Awaiting approval.', array( 'status' => 403 ) );

		$result = $this->invoke( $this->engine(), 'attach_deny_redirect', array( $error, $context ) );
		$data   = $result->get_error_data();
		$this->assertSame( 'https://example.test/denied/', $data['redirect'] );
	}

	public function test_pending_redirect_appends_token_when_present(): void {
		$context = array(
			'id'               => 'default',
			'deny_redirect'    => '',
			'pending_redirect' => 'https://example.test/waiting/',
		);
		$error = new \WP_Error(
			'authorizenter_not_approved',
			'Awaiting approval.',
			array( 'status' => 403, 'pending_token' => 'abc123' )
		);

		$result = $this->invoke( $this->engine(), 'attach_deny_redirect', array( $error, $context ) );
		$data   = $result->get_error_data();
		$this->assertStringContainsString( 'azr_pending_token=', $data['redirect'] );
		$this->assertStringContainsString( 'abc123', $data['redirect'] );
	}
}
