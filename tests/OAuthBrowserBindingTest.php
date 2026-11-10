<?php
namespace Authorizenter\Core\Tests;

use Authorizenter\Core\Settings;
use Authorizenter\Core\OAuth_Engine;
use Authorizenter\Core\Org_Policy;
use Authorizenter\Core\Provider_Registry;
use Authorizenter\Core\User_Mapper;
use PHPUnit\Framework\TestCase;

class BindingProvider extends \Authorizenter\Core\Providers\OAuth2 {
	public function exchange( $code, $redirect_uri, $code_verifier, $nonce ) {
		return new \WP_Error( 'exchange_reached', 'Synthetic provider exchange.' );
	}
}

class OAuthBrowserBindingTest extends TestCase {
	private $engine;
	protected function setUp(): void {
		azr_test_reset();
		$_SESSION = array();
		$GLOBALS['__mock_filters']['authorizenter_provider_classes'] = function ( $classes ) {
			$classes['oauth2'] = BindingProvider::class;
			return $classes;
		};
		update_option( Settings::OPTION, array( 'providers' => array( 'custom' => array(
			'type' => 'oauth2', 'enabled' => true, 'client_id' => 'test', 'authorization_endpoint' => 'https://idp.example.test/authorize',
		) ) ) );
		$s = new Settings();
		$p = new Org_Policy( $s );
		$this->engine = new OAuth_Engine( $s, new Provider_Registry( $s ), $p, new User_Mapper( $s, $p ) );
	}
	protected function tearDown(): void { $_SESSION = array(); }
	private function begin(): string {
		$url = $this->engine->begin( 'custom' );
		parse_str( parse_url( $url, PHP_URL_QUERY ), $query );
		return $query['state'];
	}
	public function test_other_browser_cannot_use_state_and_does_not_consume_owner_flow(): void {
		$state = $this->begin();
		$owner = $_SESSION;
		$_SESSION = array();
		$result = $this->engine->handle_callback( 'code', $state );
		$this->assertSame( 'authorizenter_state_invalid', $result->get_error_code() );
		$_SESSION = $owner;
		$this->assertSame( 'exchange_reached', $this->engine->handle_callback( 'code', $state )->get_error_code() );
	}
	public function test_multiple_tabs_and_replays(): void {
		$one = $this->begin();
		$two = $this->begin();
		$this->assertNotSame( $one, $two );
		$this->assertSame( 'exchange_reached', $this->engine->handle_callback( 'code', $two )->get_error_code() );
		$this->assertSame( 'exchange_reached', $this->engine->handle_callback( 'code', $one )->get_error_code() );
		$this->assertSame( 'authorizenter_state_invalid', $this->engine->handle_callback( 'code', $one )->get_error_code() );
	}
	public function test_pending_oidc_tab_does_not_steal_oauth2_callback(): void {
		$state = $this->begin();
		$_SESSION[ OAuth_Engine::SESSION_KEY ] = array( 'provider' => 'oidc' );
		$this->assertSame( 'exchange_reached', $this->engine->handle_callback( 'code', $state )->get_error_code() );
		$this->assertSame( array( 'provider' => 'oidc' ), $_SESSION[ OAuth_Engine::SESSION_KEY ] );
	}
}
