<?php
/**
 * Plugin Name:       Autorizenter UI
 * Plugin URI:        https://github.com/autorizenter/autorizenter
 * Description:       Front-end for Autorizenter Core: login buttons, question form, shortcodes, and an auto-created login page. Requires Autorizenter Core.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Autorizenter contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       autorizenter
 * Domain Path:       /languages
 *
 * @package Autorizenter\UI
 */

namespace Autorizenter\UI;

defined( 'ABSPATH' ) || exit;

define( 'AUTORIZENTER_UI_VERSION', '0.1.0' );
define( 'AUTORIZENTER_UI_FILE', __FILE__ );
define( 'AUTORIZENTER_UI_DIR', plugin_dir_path( __FILE__ ) );
define( 'AUTORIZENTER_UI_URL', plugin_dir_url( __FILE__ ) );

/**
 * Lightweight autoloader for Autorizenter\UI.
 *
 * @param string $class Class name.
 * @return void
 */
spl_autoload_register(
	function ( $class ) {
		$prefix = 'Autorizenter\\UI\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$base = substr( $class, strlen( $prefix ) );
		$file = AUTORIZENTER_UI_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $base ) ) . '.php';
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
	if ( ! defined( 'AUTORIZENTER_CORE_VERSION' ) ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p>' .
					esc_html__( 'Autorizenter UI requires Autorizenter Core to be installed and active.', 'autorizenter' ) .
					'</p></div>';
			}
		);
		return;
	}

	( new Frontend() )->hooks();
	( new Blocks() )->hooks();

	// Self-hosted updates from GitHub releases (reuses the Core updater).
	if ( class_exists( '\\Autorizenter\\Core\\Github_Updater' ) ) {
		$repo = apply_filters( 'autorizenter_github_repo', defined( 'AUTORIZENTER_GITHUB_REPO' ) ? AUTORIZENTER_GITHUB_REPO : '', 'ui' );
		if ( '' !== $repo ) {
			$updater = new \Autorizenter\Core\Github_Updater( AUTORIZENTER_UI_FILE, 'autorizenter-ui', $repo, AUTORIZENTER_UI_VERSION, 'autorizenter-ui.zip' );
			$updater->hooks();
		}
	}

	add_action(
		'init',
		function () {
			load_plugin_textdomain( 'autorizenter', false, dirname( plugin_basename( AUTORIZENTER_UI_FILE ) ) . '/languages' );
		}
	);
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\boot', 20 );

register_activation_hook( __FILE__, array( __NAMESPACE__ . '\\Page_Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( __NAMESPACE__ . '\\Page_Installer', 'deactivate' ) );
