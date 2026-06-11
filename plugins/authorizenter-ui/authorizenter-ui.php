<?php
/**
 * Plugin Name:       Authorizenter UI
 * Plugin URI:        https://github.com/authorizenter/authorizenter
 * Description:       Front-end for Authorizenter Core: login buttons, question form, shortcodes, and an auto-created login page. Requires Authorizenter Core.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Authorizenter contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       authorizenter
 * Domain Path:       /languages
 *
 * @package Authorizenter\UI
 */

namespace Authorizenter\UI;

defined( 'ABSPATH' ) || exit;

define( 'AUTHORIZENTER_UI_VERSION', '0.1.0' );
define( 'AUTHORIZENTER_UI_FILE', __FILE__ );
define( 'AUTHORIZENTER_UI_DIR', plugin_dir_path( __FILE__ ) );
define( 'AUTHORIZENTER_UI_URL', plugin_dir_url( __FILE__ ) );

/**
 * Lightweight autoloader for Authorizenter\UI.
 *
 * @param string $class Class name.
 * @return void
 */
spl_autoload_register(
	function ( $class ) {
		$prefix = 'Authorizenter\\UI\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$base = substr( $class, strlen( $prefix ) );
		$file = AUTHORIZENTER_UI_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $base ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

/**
 * Boot the UI once everything is loaded, but only if Core is present.
 *
 * @return void
 */
function boot() {
	if ( ! defined( 'AUTHORIZENTER_CORE_VERSION' ) ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p>' .
					esc_html__( 'Authorizenter UI requires Authorizenter Core to be installed and active.', 'authorizenter' ) .
					'</p></div>';
			}
		);
		return;
	}

	( new Frontend() )->hooks();
	( new Blocks() )->hooks();

	// Self-hosted updates from GitHub releases (reuses the Core updater).
	if ( class_exists( '\\Authorizenter\\Core\\Github_Updater' ) ) {
		$repo = apply_filters( 'authorizenter_github_repo', defined( 'AUTHORIZENTER_GITHUB_REPO' ) ? AUTHORIZENTER_GITHUB_REPO : '', 'ui' );
		if ( '' !== $repo ) {
			$updater = new \Authorizenter\Core\Github_Updater( AUTHORIZENTER_UI_FILE, 'authorizenter-ui', $repo, AUTHORIZENTER_UI_VERSION, 'authorizenter-ui.zip' );
			$updater->hooks();
		}
	}

	add_action(
		'init',
		function () {
			load_plugin_textdomain( 'authorizenter', false, dirname( plugin_basename( AUTHORIZENTER_UI_FILE ) ) . '/languages' );
		}
	);
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\boot', 20 );

register_activation_hook( __FILE__, array( __NAMESPACE__ . '\\Page_Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( __NAMESPACE__ . '\\Page_Installer', 'deactivate' ) );
