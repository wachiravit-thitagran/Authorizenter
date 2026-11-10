<?php
namespace Authorizenter\Core\Tests;

use Authorizenter\Core\Admin_Settings;
use Authorizenter\Core\Provider_Registry;
use Authorizenter\Core\Questions;
use Authorizenter\Core\Settings;
use PHPUnit\Framework\TestCase;

/** Stop after the real settings write, before the admin redirect/exit. */
class RoundtripSettings extends Settings {
	public function save( array $settings ) {
		parent::save( $settings );
		throw new \RuntimeException( 'saved' );
	}
}

class OAuthSettingsRoundtripTest extends TestCase {
	protected function setUp(): void { azr_test_reset(); $_POST = array(); }
	protected function tearDown(): void { $_POST = array(); unset( $GLOBALS['__mock_wp_remote_get'] ); }
	private function save(): void {
		$s = new RoundtripSettings();
		try {
			( new Admin_Settings( $s, new Provider_Registry( $s ), new Questions( $s ) ) )->handle_save();
			$this->fail( 'Expected settings write.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'saved', $e->getMessage() );
		}
	}
	public function test_generic_oauth2_save_reload_endpoints_mapping_and_hidden_values(): void {
		$provider = array(
			'type' => 'oauth2', 'enabled' => '1', 'client_id' => 'custom-client',
			'authorization_endpoint' => 'https://oauth.example.test/authorize',
			'token_endpoint' => 'https://oauth.example.test/token', 'userinfo_endpoint' => 'https://oauth.example.test/me',
			'attr_username' => 'uid', 'attr_email' => 'mail', 'attr_first_name' => 'first', 'attr_last_name' => 'last',
			'name_update' => 'always', 'trust_email' => '1', 'link_by_username' => '1',
		);
		update_option( Settings::OPTION, array( 'providers' => array( 'custom' => array_merge( $provider, array( 'label' => 'Stored label' ) ) ) ) );
		$_POST = array( 'providers' => array( 'custom' => $provider ) );
		$this->save();
		$saved = ( new Settings() )->get( 'providers' )['custom'];
		foreach ( array( 'authorization_endpoint', 'token_endpoint', 'userinfo_endpoint', 'attr_username', 'attr_email', 'attr_first_name', 'attr_last_name', 'name_update' ) as $key ) {
			$this->assertSame( $provider[ $key ], $saved[ $key ] ?? null, $key );
		}
		$this->assertTrue( $saved['trust_email'] );
		$this->assertTrue( $saved['link_by_username'] );
		$this->assertSame( 'Stored label', $saved['label'] );
		$p = ( new Provider_Registry( new Settings() ) )->get( 'custom' );
		$this->assertStringStartsWith( $provider['authorization_endpoint'], $p->authorization_url( 'state', 'https://example.test/cb', 'challenge', 'nonce' ) );
		$GLOBALS['__mock_wp_remote_get'][ $provider['token_endpoint'] ] = array( 'body' => '{"access_token":"synthetic"}' );
		$GLOBALS['__mock_wp_remote_get'][ $provider['userinfo_endpoint'] ] = array( 'body' => '{"uid":"123","mail":"u@example.test","first":"First","last":"Last"}' );
		$identity = $p->exchange( 'code', 'https://example.test/cb', 'verifier', '' );
		$this->assertInstanceOf( \Authorizenter\Core\Identity::class, $identity );
		$this->assertSame( '123', $identity->sub );
		$this->assertSame( 'u@example.test', $identity->email );
		// A partial/custom settings form must retain fields it does not render.
		unset( $_POST['providers']['custom']['authorization_endpoint'], $_POST['providers']['custom']['token_endpoint'], $_POST['providers']['custom']['userinfo_endpoint'] );
		$this->save();
		$this->assertSame( $provider['token_endpoint'], ( new Settings() )->get( 'providers' )['custom']['token_endpoint'] );
	}
}
