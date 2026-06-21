<?php
/**
 * PHPUnit bootstrap: load WP stubs then the Core classes under test.
 *
 * These are pure unit tests — they do not require a running WordPress. WordPress
 * functions are replaced by the lightweight stubs in wp-stubs.php.
 *
 * @package Authorizenter\Core\Tests
 */

require __DIR__ . '/wp-stubs.php';

if ( file_exists( dirname( __DIR__ ) . '/vendor/autoload.php' ) ) {
	require dirname( __DIR__ ) . '/vendor/autoload.php';
}

$inc = dirname( __DIR__ ) . '/plugins/authorizenter-core/includes/';

if ( ! defined( 'Authorizenter\Core\AUTHORIZENTER_REST_NAMESPACE' ) ) {
	define( 'Authorizenter\Core\AUTHORIZENTER_REST_NAMESPACE', 'authorizenter/v1' );
}

require $inc . 'class-identity.php';
require $inc . 'class-settings.php';
require $inc . 'class-provider-base.php';
require $inc . 'class-jwt-verifier.php';
require $inc . 'class-oidc-client.php';
require $inc . 'providers/class-oidc.php';
require $inc . 'providers/class-oauth2.php';
require $inc . 'providers/class-google.php';
require $inc . 'providers/class-line.php';
require $inc . 'providers/class-facebook.php';
require $inc . 'class-provider-registry.php';
require $inc . 'class-access-list.php';
require $inc . 'class-org-policy.php';
require $inc . 'class-user-mapper.php';
require $inc . 'class-questions.php';
require $inc . 'class-oauth-engine.php';
require $inc . 'class-reports.php';
require $inc . 'class-github-updater.php';
require $inc . 'class-password-auth.php';
require $inc . 'class-login-throttle.php';
require $inc . 'class-private-site.php';
require $inc . 'class-admin-settings.php';
require $inc . 'class-shortcodes.php';
require $inc . 'class-rest-api.php';

$ui_inc = dirname( __DIR__ ) . '/plugins/authorizenter-ui/includes/';
if ( ! defined( 'AUTHORIZENTER_UI_URL' ) ) {
	define( 'AUTHORIZENTER_UI_URL', 'https://example.test/ui/' );
}
if ( ! defined( 'AUTHORIZENTER_UI_DIR' ) ) {
	define( 'AUTHORIZENTER_UI_DIR', __DIR__ . '/fixtures/' );
}
if ( ! defined( 'AUTHORIZENTER_UI_VERSION' ) ) {
	define( 'AUTHORIZENTER_UI_VERSION', '1.0.0' );
}
require $ui_inc . 'class-logos.php';
require $ui_inc . 'class-page-installer.php';
require $ui_inc . 'class-frontend.php';
require $ui_inc . 'class-blocks.php';
require __DIR__ . '/core-namespace-stub.php';
