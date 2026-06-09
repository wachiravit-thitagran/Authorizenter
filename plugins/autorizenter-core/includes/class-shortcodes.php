<?php
/**
 * Front-end shortcodes owned by Core (logic only; markup is delegated to UI).
 *
 * @package Autorizenter\Core
 */

namespace Autorizenter\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Registers shortcodes whose behaviour is Core's responsibility. The actual HTML
 * is produced by template-level code (the UI plugin) through filters, so Core has
 * no dependency on any presentation layer and the shortcode still works — in a
 * minimal, unstyled form — even without the UI plugin installed.
 */
class Shortcodes {

	/**
	 * Settings store.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Provider registry.
	 *
	 * @var Provider_Registry
	 */
	private $providers;

	/**
	 * Constructor.
	 *
	 * @param Settings          $settings  Settings store.
	 * @param Provider_Registry $providers Provider registry.
	 */
	public function __construct( Settings $settings, Provider_Registry $providers ) {
		$this->settings  = $settings;
		$this->providers = $providers;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_shortcode( 'autorizenter_button', array( $this, 'render_button' ) );
	}

	/**
	 * Single provider login button: [autorizenter_button provider="google" context="default"].
	 *
	 * Core resolves the provider/context and builds the authorize URL, then hands a
	 * data array to the `autorizenter_button_html` filter so a template can render
	 * the styled markup. The returned default is a plain, functional link.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_button( $atts ) {
		$atts = shortcode_atts(
			array(
				'provider'  => '',
				'context'   => 'default',
				'return_to' => '',
			),
			$atts,
			'autorizenter_button'
		);

		if ( is_user_logged_in() || '' === $atts['provider'] ) {
			return '';
		}

		$context_id  = sanitize_key( $atts['context'] );
		$provider_id = sanitize_key( $atts['provider'] );
		$context     = $this->settings->get_context( $context_id );
		$providers   = $this->providers->enabled_for_context( $context );

		if ( ! isset( $providers[ $provider_id ] ) ) {
			return '';
		}

		$provider  = $providers[ $provider_id ];
		$return_to = '' !== $atts['return_to'] ? $atts['return_to'] : $this->current_url();
		$url       = add_query_arg(
			array(
				'context'   => $context_id,
				'return_to' => rawurlencode( $return_to ),
			),
			rest_url( AUTORIZENTER_REST_NAMESPACE . '/authorize/' . $provider_id )
		);

		$data = array(
			'provider_id' => $provider_id,
			'label'       => $provider->label(),
			'url'         => $url,
			'logo_url'    => $provider->logo_url(),
			'context'     => $context_id,
		);

		$default = '<a class="autorizenter-btn autorizenter-btn--' . esc_attr( $provider_id ) . '" href="' . esc_url( $url ) . '">' .
			/* translators: %s: provider label */
			sprintf( esc_html__( 'Continue with %s', 'autorizenter' ), esc_html( $data['label'] ) ) .
			'</a>';

		/**
		 * Filter the rendered single-provider button (template-level markup).
		 *
		 * @param string $default Minimal default markup.
		 * @param array  $data    Button data: provider_id, label, url, logo_url, context.
		 */
		return apply_filters( 'autorizenter_button_html', $default, $data );
	}

	/**
	 * Current front-end URL (host-safe) for the default post-login destination.
	 *
	 * @return string
	 */
	private function current_url() {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return home_url( $path );
	}
}
