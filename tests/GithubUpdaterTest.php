<?php
/**
 * Tests for the GitHub release updater (version logic + asset selection).
 *
 * @package Autorizenter\Core\Tests
 */

namespace Autorizenter\Core\Tests;

use Autorizenter\Core\Github_Updater;
use PHPUnit\Framework\TestCase;

class GithubUpdaterTest extends TestCase {

	private const FILE = '/plugins/autorizenter-core/autorizenter-core.php';
	private const REPO = 'owner/repo';

	protected function setUp(): void {
		azr_test_reset();
	}

	private function updater( string $version ): Github_Updater {
		return new Github_Updater( self::FILE, 'autorizenter-core', self::REPO, $version, 'autorizenter-core.zip' );
	}

	private function invoke( object $obj, string $method, array $args ) {
		$ref = new \ReflectionMethod( $obj, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $obj, $args );
	}

	private function seed_release( array $release ): void {
		set_transient( 'autorizenter_gh_' . md5( self::REPO ), $release );
	}

	public function test_normalize_strips_v_prefix(): void {
		$u = $this->updater( '0.1.0' );
		$this->assertSame( '1.2.3', $this->invoke( $u, 'normalize', array( 'v1.2.3' ) ) );
		$this->assertSame( '1.2.3', $this->invoke( $u, 'normalize', array( '1.2.3' ) ) );
	}

	public function test_package_url_prefers_matching_asset(): void {
		$u       = $this->updater( '0.1.0' );
		$release = array(
			'assets'      => array(
				array( 'name' => 'autorizenter-core.zip', 'browser_download_url' => 'https://dl/core.zip' ),
				array( 'name' => 'other.zip', 'browser_download_url' => 'https://dl/other.zip' ),
			),
			'zipball_url' => 'https://dl/zipball',
		);
		$this->assertSame( 'https://dl/core.zip', $this->invoke( $u, 'package_url', array( $release ) ) );
	}

	public function test_package_url_falls_back_to_zipball(): void {
		$u       = $this->updater( '0.1.0' );
		$release = array( 'assets' => array(), 'zipball_url' => 'https://dl/zipball' );
		$this->assertSame( 'https://dl/zipball', $this->invoke( $u, 'package_url', array( $release ) ) );
	}

	public function test_inject_update_offers_newer_version(): void {
		$this->seed_release(
			array(
				'tag_name'    => 'v9.9.9',
				'assets'      => array( array( 'name' => 'autorizenter-core.zip', 'browser_download_url' => 'https://dl/core.zip' ) ),
				'zipball_url' => 'https://dl/zipball',
			)
		);
		$u         = $this->updater( '0.1.0' );
		$transient = (object) array( 'response' => array(), 'no_update' => array() );

		$result = $u->inject_update( $transient );

		$key = plugin_basename( self::FILE );
		$this->assertArrayHasKey( $key, $result->response );
		$this->assertSame( '9.9.9', $result->response[ $key ]->new_version );
		$this->assertSame( 'https://dl/core.zip', $result->response[ $key ]->package );
	}

	public function test_inject_update_no_update_when_current(): void {
		$this->seed_release( array( 'tag_name' => 'v0.1.0', 'assets' => array(), 'zipball_url' => 'https://z' ) );
		$u         = $this->updater( '0.1.0' );
		$transient = (object) array( 'response' => array(), 'no_update' => array() );

		$result = $u->inject_update( $transient );

		$key = plugin_basename( self::FILE );
		$this->assertArrayNotHasKey( $key, $result->response );
		$this->assertArrayHasKey( $key, $result->no_update );
	}

	public function test_inject_update_ignores_non_object(): void {
		$u = $this->updater( '0.1.0' );
		$this->assertFalse( $u->inject_update( false ) );
	}

	public function test_plugin_info_for_matching_slug(): void {
		$this->seed_release(
			array(
				'tag_name' => 'v2.0.0',
				'body'     => 'Release notes',
				'assets'   => array( array( 'name' => 'autorizenter-core.zip', 'browser_download_url' => 'https://dl/core.zip' ) ),
			)
		);
		$res = $this->updater( '0.1.0' )->plugin_info( false, 'plugin_information', (object) array( 'slug' => 'autorizenter-core' ) );

		$this->assertIsObject( $res );
		$this->assertSame( '2.0.0', $res->version );
		$this->assertSame( 'https://dl/core.zip', $res->download_link );
	}

	public function test_plugin_info_passthrough_for_other_slug(): void {
		$res = $this->updater( '0.1.0' )->plugin_info( false, 'plugin_information', (object) array( 'slug' => 'other' ) );
		$this->assertFalse( $res );
	}
}
