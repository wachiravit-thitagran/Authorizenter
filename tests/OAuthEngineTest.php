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

	public function test_flow_ttl_filter_applied(): void {
		update_option( Settings::OPTION, array( 'providers' => array( 'facebook' => array( 'enabled' => true, 'client_id' => 'f' ) ) ) );
		
		$filter_ran = false;
		$GLOBALS['__mock_filters']['authorizenter_flow_ttl'] = function( $ttl ) use ( &$filter_ran ) {
			$filter_ran = true;
			return $ttl;
		};

		$this->engine()->begin( 'facebook', '' );
		$this->assertTrue( $filter_ran );
	}

	public function test_authorization_url_filter(): void {
		update_option( Settings::OPTION, array( 'providers' => array( 'facebook' => array( 'enabled' => true, 'client_id' => 'f' ) ) ) );
		
		$GLOBALS['__mock_filters']['authorizenter_authorization_url'] = function( $url ) {
			return $url . '&custom_hook=1';
		};

		$result = $this->engine()->begin( 'facebook', '' );
		$this->assertStringContainsString( '&custom_hook=1', $result );
	}

	public function test_before_logout_action_fires(): void {
		$fired = false;
		$GLOBALS['__mock_actions']['authorizenter_before_logout'] = function( $user_id, $provider_id ) use ( &$fired ) {
			$fired = true;
		};

		$this->engine()->logout();
		$this->assertTrue( $fired );
	}

	public function test_finish_login_saves_provider_data(): void {
		update_option( Settings::OPTION, array( 'providers' => array( 'google' => array( 'enabled' => true, 'client_id' => 'g', 'client_secret' => 's' ) ) ) );
		
		$engine = $this->engine();
		
		$ref = new \ReflectionProperty( $engine, 'providers' );
		$ref->setAccessible( true );
		$providers = $ref->getValue( $engine );
		$provider = $providers->get( 'google' );

		$identity = new \Authorizenter\Core\Identity( 'google', array(
			'sub'   => '123',
			'email' => 'test@example.com',
			'raw'   => array( 'raw_key' => 'raw_value' ),
		) );

		azr_test_make_user( 10, array( 'read' => true ), 'test@example.com' );
		
		// Map the user to skip provisioning since we made the user.
		update_user_meta( 10, 'authorizenter_link_google', '123' );

		$context = array( 'id' => 'default', 'redirect' => '' );

		$result = $this->invoke( $engine, 'finish_login', array( $identity, $context, $provider, '' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 10, $result['user']->ID );

		$saved_raw = get_user_meta( 10, 'authorizenter_provider_data_google', true );
		$this->assertSame( array( 'raw_key' => 'raw_value' ), $saved_raw );
		
		$last_provider = get_user_meta( 10, 'authorizenter_last_provider', true );
		$this->assertSame( 'google', $last_provider );
	}
}
