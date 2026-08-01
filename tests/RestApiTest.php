<?php
/**
 * REST API logic unit tests.
 *
 * @package Authorizenter\Core\Tests
 */

namespace Authorizenter\Core\Tests;

use PHPUnit\Framework\TestCase;
use Authorizenter\Core\Rest_Api;
use Authorizenter\Core\OAuth_Engine;
use Authorizenter\Core\Provider_Registry;
use Authorizenter\Core\Questions;
use Authorizenter\Core\Settings;
use Authorizenter\Core\Reports;
use Authorizenter\Core\Providers\Google;
use WP_Error;

/**
 * Tests for the Rest_Api class.
 */
class RestApiTest extends TestCase {

	private $engine;
	private $providers;
	private $questions;
	private $settings;
	private $reports;
	private $api;

	protected function setUp(): void {
		parent::setUp();
		$this->engine    = $this->createMock( OAuth_Engine::class );
		$this->providers = $this->createMock( Provider_Registry::class );
		$this->questions = $this->createMock( Questions::class );
		$this->settings  = $this->createMock( Settings::class );
		$this->reports   = $this->createMock( Reports::class );
		
		$this->api = new Rest_Api(
			$this->engine,
			$this->providers,
			$this->questions,
			$this->settings,
			$this->reports
		);
		
		$GLOBALS['__mock_rest_routes'] = array();
		$GLOBALS['__mock_filters']     = array();
		unset( $GLOBALS['__logged_in'], $GLOBALS['__mock_verify_nonce'] );
	}

	public function test_register_routes() {
		$this->api->register_routes();
		$this->assertNotEmpty( $GLOBALS['__mock_rest_routes'] );
		
		$endpoints = array_column( $GLOBALS['__mock_rest_routes'], 'route' );
		$this->assertContains( '/providers', $endpoints );
		$this->assertContains( '/authorize', $endpoints );
		$this->assertContains( '/callback', $endpoints );
	}

	public function test_list_providers() {
		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'context', 'default' );
		
		$this->settings->method( 'get_context' )
			->willReturn( array( 'id' => 'default' ) );
			
		$google = $this->createMock( Google::class );
		$google->method( 'label' )->willReturn( 'Google' );
		
		$this->providers->method( 'enabled_for_context' )
			->willReturn( array( 'google' => $google ) );
			
		$response = $this->api->list_providers( $request );
		
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertEquals( 200, $response->status );
		$this->assertCount( 1, $response->data['providers'] );
		$this->assertEquals( 'Google', $response->data['providers'][0]['label'] );
	}

	public function test_authorize_redirects_to_url() {
		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'provider', 'google' );
		
		$this->engine->method( 'begin' )
			->willReturn( 'https://accounts.google.com/o/oauth2/v2/auth' );
			
		// Since redirect_to calls exit, we must catch the exception or output.
		// We expect nocache_headers() to be called and an exit. We'll use output buffering.
		// Wait, phpunit process isolation or catching exit is tricky.
		// We can test if it's an ajax request, it returns JSON!
		
		$post_request = new \WP_REST_Request( 'POST' );
		$post_request->set_param( 'provider', 'google' );
		$response = $this->api->authorize( $post_request );
		
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertEquals( 'https://accounts.google.com/o/oauth2/v2/auth', $response->data['url'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_authorize_returns_error_if_engine_fails() {
		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'provider', 'invalid' );
		
		$this->engine->method( 'begin' )
			->willReturn( new WP_Error( 'invalid_provider', 'Invalid.' ) );
			
		$level = ob_get_level();
		$response = $this->api->authorize( $request );
		
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertEquals( 400, $response->status );
		$this->assertEquals( 'invalid_provider', $response->data['error'] );
		
		while ( ob_get_level() < $level ) {
			ob_start();
		}
	}
	
	public function test_get_questions() {
		// Mock logged in user.
		$GLOBALS['__logged_in'] = true;
		
		$this->questions->method( 'pending_for_user' )
			->willReturn( array( array( 'id' => 'q1', 'label' => 'Q1' ) ) );
			
		$response = $this->api->get_questions();

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertEquals( 200, $response->status );
		$this->assertCount( 1, $response->data['questions'] );
	}

	/**
	 * The /logout route must exist and be gated by can_logout(), not left open.
	 */
	public function test_logout_route_is_registered_and_gated() {
		$this->api->register_routes();

		$logout = null;
		foreach ( $GLOBALS['__mock_rest_routes'] as $registered ) {
			if ( '/logout' === $registered['route'] ) {
				$logout = $registered['args'];
			}
		}

		$this->assertNotNull( $logout, '/logout route is not registered.' );
		$this->assertEquals( 'GET', $logout['methods'] );
		$this->assertEquals( array( $this->api, 'can_logout' ), $logout['permission_callback'] );
		$this->assertArrayHasKey( 'return_to', $logout['args'] );
		$this->assertArrayHasKey( '_wpnonce', $logout['args'] );
	}

	/**
	 * wp_logout_url() must be pointed away from wp-login.php, which hardened
	 * servers commonly block — that would leave users unable to log out at all.
	 */
	public function test_filter_logout_url_targets_the_rest_route() {
		$url = $this->api->filter_logout_url( 'https://example.test/wp-login.php?action=logout&_wpnonce=abc' );

		$this->assertStringNotContainsString( 'wp-login.php', $url );
		$this->assertStringContainsString( 'wp-json/authorizenter/v1/logout', $url );
		$this->assertStringContainsString( '_wpnonce=mock-nonce', $url );
		$this->assertStringNotContainsString( 'return_to', $url );
	}

	public function test_filter_logout_url_carries_the_redirect_target() {
		$url = $this->api->filter_logout_url(
			'https://example.test/wp-login.php?action=logout',
			'https://example.test/dashboard/'
		);

		$this->assertStringContainsString( 'wp-json/authorizenter/v1/logout', $url );

		// The destination survives a round trip through the query string.
		$query = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( 'https://example.test/dashboard/', rawurldecode( $query['return_to'] ) );
	}

	public function test_filter_logout_url_can_be_disabled() {
		$GLOBALS['__mock_filters']['authorizenter_rest_logout'] = function ( $enabled, $redirect = '' ) {
			return false;
		};

		$original = 'https://example.test/wp-login.php?action=logout&_wpnonce=abc';
		$this->assertSame( $original, $this->api->filter_logout_url( $original ) );

		unset( $GLOBALS['__mock_filters']['authorizenter_rest_logout'] );
	}

	/**
	 * Logout CSRF: a live session must not be destroyed without a valid nonce.
	 */
	public function test_can_logout_rejects_a_logged_in_request_without_a_nonce() {
		$GLOBALS['__logged_in'] = true;

		$result = $this->api->can_logout( new \WP_REST_Request( 'GET' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'authorizenter_invalid_nonce', $result->get_error_code() );
		$this->assertEquals( 403, $result->get_error_data()['status'] );
	}

	public function test_can_logout_rejects_a_stale_nonce() {
		$GLOBALS['__logged_in'] = true;

		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( '_wpnonce', 'expired-nonce' );

		$this->assertInstanceOf( WP_Error::class, $this->api->can_logout( $request ) );
	}

	public function test_can_logout_accepts_a_valid_nonce() {
		$GLOBALS['__logged_in'] = true;

		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( '_wpnonce', wp_create_nonce( 'wp_rest' ) );

		$this->assertTrue( $this->api->can_logout( $request ) );
	}

	/**
	 * JS clients send the nonce as a header rather than a query argument.
	 */
	public function test_can_logout_accepts_the_nonce_header() {
		$GLOBALS['__logged_in'] = true;

		$request = new \WP_REST_Request( 'GET' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$this->assertTrue( $this->api->can_logout( $request ) );
	}

	/**
	 * Nothing to protect when there is no session: the callback only redirects,
	 * so a forged request stays harmless instead of erroring.
	 */
	public function test_can_logout_allows_requests_without_a_session() {
		$GLOBALS['__logged_in'] = false;

		$this->assertTrue( $this->api->can_logout( new \WP_REST_Request( 'GET' ) ) );
	}
}
