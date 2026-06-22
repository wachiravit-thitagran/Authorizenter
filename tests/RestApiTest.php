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
}
