<?php
/**
 * PHPUnit bootstrap: load WP stubs then the Core classes under test.
 *
 * These are pure unit tests — they do not require a running WordPress. WordPress
 * functions are replaced by the lightweight stubs in wp-stubs.php.
 *
 * @package Autorizenter\Core\Tests
 */

require __DIR__ . '/wp-stubs.php';

if ( file_exists( dirname( __DIR__ ) . '/vendor/autoload.php' ) ) {
	require dirname( __DIR__ ) . '/vendor/autoload.php';
}

$inc = dirname( __DIR__ ) . '/plugins/autorizenter-core/includes/';

if ( ! defined( 'Autorizenter\Core\AUTORIZENTER_REST_NAMESPACE' ) ) {
	define( 'Autorizenter\Core\AUTORIZENTER_REST_NAMESPACE', 'autorizenter/v1' );
}

require $inc . 'class-identity.php';
require $inc . 'class-settings.php';
require $inc . 'class-provider-base.php';
require $inc . 'class-jwt-verifier.php';
require $inc . 'providers/class-oidc.php';
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
