<?php
/**
 * Tests for the SSO button/URL shortcodes.
 *
 * The visual [authorizenter_button] is owned by Authorizenter\UI\Frontend
 * (template-level markup: label + icon). The bare [authorizenter_url] is owned by
 * Authorizenter\Core\Shortcodes (logic only, no markup).
 *
 * @package Authorizenter\Core\Tests
 */

namespace Authorizenter\Core\Tests;

use Authorizenter\Core\Settings;
use Authorizenter\Core\Provider_Registry;
use Authorizenter\Core\Shortcodes;
use Authorizenter\UI\Frontend;
use PHPUnit\Framework\TestCase;

class FrontendTest extends TestCase {

	/** @var Shortcodes */
	private $shortcodes;

	/** @var Frontend */
	private $frontend;

	protected function setUp(): void {
		azr_test_reset();

		$this->frontend = new Frontend();
	}

	private function make_core( array $provider_config = array() ): void {
		update_option(
			Settings::OPTION,
			array( 'providers' => $provider_config )
		);
		$settings          = new Settings();
		$providers         = new Provider_Registry( $settings );
		$questions         = new \Authorizenter\Core\Questions( $settings );
		$core              = new \stdClass();
		$core->settings    = $settings;
		$core->providers   = $providers;
		$core->questions   = $questions;
		$GLOBALS['__core'] = $core;

		$this->shortcodes = new Shortcodes( $settings, $providers );
	}

	// --- UI [authorizenter_button]: early-exit cases -------------------------

	public function test_returns_empty_when_no_provider_given(): void {
		$this->make_core();
		$this->assertSame( '', $this->frontend->render_button( array() ) );
	}

	public function test_returns_empty_when_provider_not_enabled(): void {
		$this->make_core(
			array(
				'google' => array(
					'enabled'   => false,
					'client_id' => 'G',
				),
			)
		);
		$this->assertSame( '', $this->frontend->render_button( array( 'provider' => 'google' ) ) );
	}

	public function test_returns_button_even_when_user_is_logged_in(): void {
		$this->make_core(
			array(
				'google' => array(
					'enabled'   => true,
					'client_id' => 'G',
				),
			)
		);
		$GLOBALS['__logged_in'] = true;
		$html                   = $this->frontend->render_button( array( 'provider' => 'google' ) );
		$this->assertStringContainsString( 'authorizenter-btn--google', $html );
	}

	public function test_returns_empty_when_provider_not_configured(): void {
		$this->make_core();
		$this->assertSame( '', $this->frontend->render_button( array( 'provider' => 'google' ) ) );
	}

	// --- UI [authorizenter_button]: HTML output ------------------------------

	public function test_renders_anchor_with_provider_class(): void {
		$this->make_core(
			array(
				'google' => array(
					'enabled'   => true,
					'client_id' => 'G',
				),
			)
		);
		$html = $this->frontend->render_button( array( 'provider' => 'google' ) );
		$this->assertStringContainsString( 'authorizenter-btn--google', $html );
		$this->assertStringContainsString( '<a ', $html );
	}

	public function test_renders_provider_label_in_button(): void {
		$this->make_core(
			array(
				'google' => array(
					'enabled'   => true,
					'client_id' => 'G',
				),
			)
		);
		$html = $this->frontend->render_button( array( 'provider' => 'google' ) );
		$this->assertStringContainsString( 'Google', $html );
	}

	public function test_custom_label_appears_in_button(): void {
		$this->make_core(
			array(
				'google' => array(
					'enabled'   => true,
					'client_id' => 'G',
					'label'     => 'PSU Login',
				),
			)
		);
		$html = $this->frontend->render_button( array( 'provider' => 'google' ) );
		$this->assertStringContainsString( 'PSU Login', $html );
	}

	public function test_button_href_contains_provider_and_context(): void {
		$this->make_core(
			array(
				'google' => array(
					'enabled'   => true,
					'client_id' => 'G',
				),
			)
		);
		$html = $this->frontend->render_button(
			array(
				'provider' => 'google',
				'context'  => 'default',
			)
		);
		$this->assertStringContainsString( 'authorizenter/v1/authorize/google', $html );
		$this->assertStringContainsString( 'context=default', $html );
	}

	public function test_return_to_is_included_in_href(): void {
		$this->make_core(
			array(
				'google' => array(
					'enabled'   => true,
					'client_id' => 'G',
				),
			)
		);
		$html = $this->frontend->render_button(
			array(
				'provider'  => 'google',
				'return_to' => 'https://example.test/dashboard/',
			)
		);
		$this->assertStringContainsString( 'return_to=', $html );
	}

	// --- render_url: bare authorize URL -------------------------------------

	public function test_url_returns_empty_when_no_provider_given(): void {
		$this->make_core();
		$this->assertSame( '', $this->shortcodes->render_url( array() ) );
	}

	public function test_url_returns_empty_when_provider_not_enabled(): void {
		$this->make_core(
			array(
				'google' => array(
					'enabled'   => false,
					'client_id' => 'G',
				),
			)
		);
		$this->assertSame( '', $this->shortcodes->render_url( array( 'provider' => 'google' ) ) );
	}

	public function test_url_returns_url_even_when_user_is_logged_in(): void {
		$this->make_core(
			array(
				'google' => array(
					'enabled'   => true,
					'client_id' => 'G',
				),
			)
		);
		$GLOBALS['__logged_in'] = true;
		$url                    = $this->shortcodes->render_url(
			array(
				'provider' => 'google',
				'context'  => 'default',
			)
		);
		$this->assertStringContainsString( 'authorizenter/v1/authorize/google', $url );
	}

	public function test_url_returns_authorize_url_without_markup(): void {
		$this->make_core(
			array(
				'google' => array(
					'enabled'   => true,
					'client_id' => 'G',
				),
			)
		);
		$url = $this->shortcodes->render_url(
			array(
				'provider' => 'google',
				'context'  => 'default',
			)
		);
		$this->assertStringContainsString( 'authorizenter/v1/authorize/google', $url );
		$this->assertStringContainsString( 'context=default', $url );
		$this->assertStringNotContainsString( '<a', $url );
	}

	public function test_url_includes_return_to(): void {
		$this->make_core(
			array(
				'google' => array(
					'enabled'   => true,
					'client_id' => 'G',
				),
			)
		);
		$url = $this->shortcodes->render_url(
			array(
				'provider'  => 'google',
				'return_to' => 'https://example.test/dashboard/',
			)
		);
		$this->assertStringContainsString( 'return_to=', $url );
	}

	// --- UI Templates & Pending Form -----------------------------------------

	public function test_template_override_used_when_available(): void {
		$this->make_core();
		if ( ! file_exists( __DIR__ . '/fixtures' ) ) {
			mkdir( __DIR__ . '/fixtures' );
		}
		$mock_file = __DIR__ . '/fixtures/mock-login.php';
		file_put_contents( $mock_file, 'MOCK_LOGIN_OUTPUT' );
		$GLOBALS['__mock_locate_template'] = $mock_file;

		$output = $this->frontend->render_login( array() );
		$this->assertStringContainsString( 'MOCK_LOGIN_OUTPUT', $output );

		// Cleanup
		unlink( $mock_file );
		$GLOBALS['__mock_locate_template'] = '';
	}

	public function test_pending_message_is_filterable(): void {
		$this->make_core();
		$GLOBALS['__mock_filters']['authorizenter_pending_message'] = function ( $message ) {
			return 'CUSTOM PENDING MESSAGE';
		};
		$_GET['azr_pending_token']                                  = 'fake_token';

		// No questions registered, so it will fall back to the message block.
		$output = $this->frontend->render_pending_form( array() );
		$this->assertStringContainsString( 'CUSTOM PENDING MESSAGE', $output );

		unset( $_GET['azr_pending_token'] );
		_azr_test_reset_filters();
	}

	public function test_pending_form_redirects_to_configured_path(): void {
		// Verify that $redirect is passed correctly to the template, using the context configuration
		// and NOT the return_to parameter.
		$this->make_core(
			array(
				'google' => array(
					'enabled'   => true,
					'client_id' => 'G',
					'questions' => array( 'q1' ),
				),
			)
		);
		$opts              = get_option( \Authorizenter\Core\Settings::OPTION, array() );
		$opts['questions'] = array(
			array(
				'id'    => 'q1',
				'label' => 'Q1',
			),
		);
		update_option( \Authorizenter\Core\Settings::OPTION, $opts );
		$_GET['azr_pending_token'] = 'fake_token';
		$_GET['return_to']         = 'https://login.page.test/'; // Should be ignored

		// Update default context pending_redirect
		$opts = get_option( \Authorizenter\Core\Settings::OPTION, array() );
		$opts['contexts']['default']['pending_redirect'] = '/custom-waiting-room/';
		update_option( \Authorizenter\Core\Settings::OPTION, $opts );

		if ( ! file_exists( __DIR__ . '/fixtures' ) ) {
			mkdir( __DIR__ . '/fixtures' );
		}
		$mock_file = __DIR__ . '/fixtures/mock-pending.php';
		file_put_contents( $mock_file, 'REDIRECT_URL: <?php echo esc_url_raw( $redirect ); ?>' );
		$GLOBALS['__mock_locate_template'] = $mock_file;

		$output = $this->frontend->render_pending_form( array() );
		$this->assertStringContainsString( 'REDIRECT_URL: /custom-waiting-room/', $output );

		// Cleanup
		unset( $_GET['azr_pending_token'], $_GET['return_to'] );
		unlink( $mock_file );
		$GLOBALS['__mock_locate_template'] = '';
	}

	// --- UI [authorizenter_questions] ----------------------------------------

	public function test_render_questions_passes_return_to_correctly(): void {
		$this->make_core();
		$GLOBALS['__logged_in'] = true;

		$_GET['return_to'] = 'https://example.test/after-quiz';

		$html = $this->frontend->render_questions( array() );

		$this->assertStringContainsString( 'data-return-to="https://example.test/after-quiz"', $html );

		unset( $_GET['return_to'] );
	}

	public function test_render_questions_shortcode_redirect_wins(): void {
		$this->make_core();
		$GLOBALS['__logged_in'] = true;

		$_GET['return_to'] = 'https://example.test/after-quiz';

		$html = $this->frontend->render_questions( array( 'redirect' => 'https://example.test/shortcode-win' ) );

		$this->assertStringContainsString( 'data-return-to="https://example.test/shortcode-win"', $html );

		unset( $_GET['return_to'] );
	}

	// --- is_login_page -------------------------------------------------------

	public function test_is_login_page_detects_wp_login_by_pagenow(): void {
		$this->make_core();
		$GLOBALS['pagenow'] = 'wp-login.php';
		$this->assertTrue( $this->frontend->is_login_page() );
		$GLOBALS['pagenow'] = 'index.php';
	}

	public function test_is_login_page_detects_custom_login_url(): void {
		$this->make_core();
		$GLOBALS['__mock_wp_login_url'] = 'https://example.test/custom-login';
		$_SERVER['REQUEST_URI']         = '/custom-login/';
		$this->assertTrue( $this->frontend->is_login_page() );

		$_SERVER['REQUEST_URI'] = '/other-page/';
		$this->assertFalse( $this->frontend->is_login_page() );

		unset( $GLOBALS['__mock_wp_login_url'], $_SERVER['REQUEST_URI'] );
	}

	public function test_is_login_page_detects_context_login_page(): void {
		$this->make_core();
		$opts                       = get_option( \Authorizenter\Core\Settings::OPTION, array() );
		$opts['contexts']['custom'] = array( 'login_page_id' => 99 );
		update_option( \Authorizenter\Core\Settings::OPTION, $opts );

		$GLOBALS['__mock_is_page'] = 99;
		$this->assertTrue( $this->frontend->is_login_page() );

		$GLOBALS['__mock_is_page'] = 100;
		$this->assertFalse( $this->frontend->is_login_page() );

		unset( $GLOBALS['__mock_is_page'] );
	}

	public function test_is_login_page_detects_default_login_page(): void {
		$this->make_core();
		update_option( \Authorizenter\UI\Page_Installer::OPT_LOGIN_PAGE, 88 );

		$GLOBALS['__mock_is_page'] = 88;
		$this->assertTrue( $this->frontend->is_login_page() );

		$GLOBALS['__mock_is_page'] = 89;
		$this->assertFalse( $this->frontend->is_login_page() );

		unset( $GLOBALS['__mock_is_page'] );
	}
}
