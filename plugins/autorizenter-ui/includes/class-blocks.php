<?php
/**
 * Gutenberg blocks for login / logout, rendered via the existing shortcodes.
 *
 * @package Autorizenter\UI
 */

namespace Autorizenter\UI;

defined( 'ABSPATH' ) || exit;

/**
 * Registers two dynamic blocks (autorizenter/login, autorizenter/logout). Both are
 * server-rendered through the corresponding shortcodes so there is a single source
 * of truth for markup, and they preview live in the editor via ServerSideRender.
 */
class Blocks {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'init', array( $this, 'register' ) );
	}

	/**
	 * Register the editor script and both block types.
	 *
	 * @return void
	 */
	public function register() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return; // Classic-only WordPress.
		}

		wp_register_script(
			'autorizenter-blocks',
			AUTORIZENTER_UI_URL . 'blocks/blocks.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n' ),
			AUTORIZENTER_UI_VERSION,
			true
		);
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'autorizenter-blocks', 'autorizenter', AUTORIZENTER_UI_DIR . 'languages' );
		}

		register_block_type(
			'autorizenter/login',
			array(
				'api_version'     => 2,
				'editor_script'   => 'autorizenter-blocks',
				'render_callback' => array( $this, 'render_login' ),
				'attributes'      => array(
					'context' => array(
						'type'    => 'string',
						'default' => 'default',
					),
				),
			)
		);

		register_block_type(
			'autorizenter/logout',
			array(
				'api_version'     => 2,
				'editor_script'   => 'autorizenter-blocks',
				'render_callback' => array( $this, 'render_logout' ),
				'attributes'      => array(
					'label' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}

	/**
	 * Render the login block via the shortcode.
	 *
	 * @param array $attrs Block attributes.
	 * @return string
	 */
	public function render_login( $attrs ) {
		$context = isset( $attrs['context'] ) ? sanitize_key( $attrs['context'] ) : 'default';
		return do_shortcode( '[autorizenter_login context="' . esc_attr( $context ) . '"]' );
	}

	/**
	 * Render the logout block via the shortcode.
	 *
	 * @param array $attrs Block attributes.
	 * @return string
	 */
	public function render_logout( $attrs ) {
		$shortcode = '[autorizenter_logout';
		if ( ! empty( $attrs['label'] ) ) {
			$shortcode .= ' label="' . esc_attr( sanitize_text_field( $attrs['label'] ) ) . '"';
		}
		$shortcode .= ']';
		return do_shortcode( $shortcode );
	}
}
