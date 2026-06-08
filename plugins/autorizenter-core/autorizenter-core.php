<?php
/**
 * Plugin Name:       Autorizenter Core
 * Plugin URI:        https://github.com/autorizenter/autorizenter
 * Description:       Flexible OAuth2/OIDC Single Sign-On engine for WordPress with organization restriction and customizable post-login questions. Provides the REST API and hooks; pair with Autorizenter UI or build your own front-end.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Autorizenter contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       autorizenter
 * Domain Path:       /languages
 *
 * @package Autorizenter\Core
 */

namespace Autorizenter\Core;

defined( 'ABSPATH' ) || exit;

define( 'AUTORIZENTER_CORE_VERSION', '0.1.0' );
define( 'AUTORIZENTER_CORE_FILE', __FILE__ );

/**
 * GitHub repository ("owner/repo") used for self-hosted updates.
 *
 * Change this to your fork, or override via the `autorizenter_github_repo` filter.
 * Releases must attach a built `autorizenter-core.zip` asset (see the release
 * workflow in .github/workflows/release.yml).
 */
if ( ! defined( 'AUTORIZENTER_GITHUB_REPO' ) ) {
	define( 'AUTORIZENTER_GITHUB_REPO', 'autorizenter/autorizenter' );
}
define( 'AUTORIZENTER_CORE_DIR', plugin_dir_path( __FILE__ ) );
define( 'AUTORIZENTER_CORE_URL', plugin_dir_url( __FILE__ ) );
define( 'AUTORIZENTER_REST_NAMESPACE', 'autorizenter/v1' );

/**
 * Minimal PSR-4-ish autoloader for the Autorizenter\Core namespace.
 *
 * Maps Autorizenter\Core\Foo_Bar to includes/class-foo-bar.php and
 * Autorizenter\Core\Providers\Foo to includes/providers/class-foo.php.
 *
 * @param string $class Fully-qualified class name.
 * @return void
 */
spl_autoload_register(
	function ( $class ) {
		$prefix = 'Autorizenter\\Core\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$relative = str_replace( '\\', '/', $relative );
		$parts    = explode( '/', $relative );
		$base     = array_pop( $parts );
		$file     = 'class-' . strtolower( str_replace( '_', '-', $base ) ) . '.php';
		$sub      = $parts ? strtolower( implode( '/', $parts ) ) . '/' : '';
		$path     = AUTORIZENTER_CORE_DIR . 'includes/' . $sub . $file;

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

// Optional Composer autoloader (firebase/php-jwt). Loaded from monorepo root or plugin vendor.
foreach ( array( AUTORIZENTER_CORE_DIR . 'vendor/autoload.php', dirname( AUTORIZENTER_CORE_DIR, 2 ) . '/vendor/autoload.php' ) as $autoload ) {
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
function autorizenter_core() {
	static $instance = null;
	if ( null === $instance ) {
		$instance = new Plugin();
	}
	return $instance;
}

add_action( 'plugins_loaded', __NAMESPACE__ . '\\autorizenter_core' );

// Lifecycle hooks.
register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );
