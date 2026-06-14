<?php
/**
 * Plugin Name:       Authorizenter Core
 * Plugin URI:        https://github.com/authorizenter/authorizenter
 * Description:       Flexible OAuth2/OIDC Single Sign-On engine for WordPress with organization restriction and customizable post-login questions. Provides the REST API and hooks; pair with Authorizenter UI or build your own front-end.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Authorizenter contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       authorizenter
 * Domain Path:       /languages
 *
 * @package Authorizenter\Core
 */

namespace Authorizenter\Core;

defined( 'ABSPATH' ) || exit;

define( 'AUTHORIZENTER_CORE_VERSION', '0.1.0' );
define( 'AUTHORIZENTER_CORE_FILE', __FILE__ );

/**
 * GitHub repository ("owner/repo") used for self-hosted updates.
 *
 * Change this to your fork, or override via the `authorizenter_github_repo` filter.
 * Releases must attach a built `authorizenter-core.zip` asset (see the release
 * workflow in .github/workflows/release.yml).
 */
if ( ! defined( 'AUTHORIZENTER_GITHUB_REPO' ) ) {
	define( 'AUTHORIZENTER_GITHUB_REPO', 'wachiravit-thitagarn/authorizenter' );
}
define( 'AUTHORIZENTER_CORE_DIR', plugin_dir_path( __FILE__ ) );
define( 'AUTHORIZENTER_CORE_URL', plugin_dir_url( __FILE__ ) );

/*
 * REST namespace.
 */
define( 'AUTHORIZENTER_REST_NAMESPACE', 'authorizenter/v1' );

/**
 * Minimal PSR-4-ish autoloader for the Authorizenter\Core namespace.
 *
 * Maps Authorizenter\Core\Foo_Bar to includes/class-foo-bar.php and
 * Authorizenter\Core\Providers\Foo to includes/providers/class-foo.php.
 *
 * @param string $class Fully-qualified class name.
 * @return void
 */
spl_autoload_register(
	function ( $class ) {
		$prefix = 'Authorizenter\\Core\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$relative = str_replace( '\\', '/', $relative );
		$parts    = explode( '/', $relative );
		$base     = array_pop( $parts );
		$file     = 'class-' . strtolower( str_replace( '_', '-', $base ) ) . '.php';
		$sub      = $parts ? strtolower( implode( '/', $parts ) ) . '/' : '';
		$path     = AUTHORIZENTER_CORE_DIR . 'includes/' . $sub . $file;

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

// Optional Composer autoloader (firebase/php-jwt). Loaded from monorepo root or plugin vendor.
foreach ( array( AUTHORIZENTER_CORE_DIR . 'vendor/autoload.php', dirname( AUTHORIZENTER_CORE_DIR, 2 ) . '/vendor/autoload.php' ) as $autoload ) {
	if ( is_readable( $autoload ) ) {
		require_once $autoload;
		break;
	}
}

/**
 * Boot the plugin once all plugins are loaded.
 *
 * @return Plugin
 */
function authorizenter_core() {
	static $instance = null;
	if ( null === $instance ) {
		$instance = new Plugin();
	}
	return $instance;
}

/**
 * Write a diagnostic line to the PHP error log when WordPress debugging is on.
 *
 * Gated on WP_DEBUG, so it uses the standard WordPress debug switch — set
 * `define( 'WP_DEBUG', true );` (and `WP_DEBUG_LOG` to capture it in
 * wp-content/debug.log). Lines are prefixed with [authorizenter] for easy
 * grepping. Never logs secrets or tokens — only flow state useful for diagnosing
 * login issues.
 *
 * @param string $message Message.
 * @param array  $context Optional key/value context appended as JSON.
 * @return void
 */
function authorizenter_log( $message, array $context = array() ) {
	if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
		return;
	}
	$line = '[authorizenter] ' . $message;
	if ( ! empty( $context ) ) {
		$line .= ' ' . wp_json_encode( $context );
	}
	error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
}

/**
 * Whether the current request is a page-builder editor/preview, where the
 * logged-in admin should still see login UI (buttons/URLs) so they can design
 * with it — otherwise those shortcodes render nothing for logged-in users.
 *
 * Detects Elementor out of the box; other builders can opt in via the
 * `authorizenter_is_builder_preview` filter.
 *
 * @return bool
 */
function authorizenter_is_builder_preview() {
	if ( class_exists( '\\Elementor\\Plugin' ) ) {
		$elementor = \Elementor\Plugin::instance();
		if ( isset( $elementor->editor ) && method_exists( $elementor->editor, 'is_edit_mode' ) && $elementor->editor->is_edit_mode() ) {
			return true;
		}
		if ( isset( $elementor->preview ) && method_exists( $elementor->preview, 'is_preview_mode' ) && $elementor->preview->is_preview_mode() ) {
			return true;
		}
	}

	/**
	 * Filter whether to treat this request as a builder preview (force-render the
	 * login button/URL even for logged-in users).
	 *
	 * @param bool $is_preview Current decision.
	 */
	return (bool) apply_filters( 'authorizenter_is_builder_preview', false );
}

/**
 * Retrieve the stored provider identity data for a user.
 *
 * @param int    $user_id     WP User ID.
 * @param string $provider_id Optional. Specific provider ID (e.g. 'google'). If empty, uses the user's last logged in provider.
 * @return array|false The stored identity data, or false if not found.
 */
function authorizenter_get_provider_data( $user_id, $provider_id = '' ) {
	if ( empty( $provider_id ) ) {
		$provider_id = get_user_meta( $user_id, 'authorizenter_last_provider', true );
	}
	if ( empty( $provider_id ) ) {
		return false;
	}
	$data = get_user_meta( $user_id, 'authorizenter_provider_data_' . $provider_id, true );
	return is_array( $data ) ? $data : false;
}

/**
 * Filter hook to retrieve provider data without directly calling the function.
 * Enables other plugins to safely fetch data even if Authorizenter is deactivated.
 *
 * Usage:
 * $data = apply_filters( 'authorizenter_get_provider_data', null, $user_id, $provider_id );
 */
add_filter( 'authorizenter_get_provider_data', function( $data, $user_id, $provider_id = '' ) {
	$provider_data = authorizenter_get_provider_data( $user_id, $provider_id );
	return false !== $provider_data ? $provider_data : $data;
}, 10, 3 );

add_action( 'plugins_loaded', function() {
	authorizenter_core();
} );

// Lifecycle hooks.
register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );
