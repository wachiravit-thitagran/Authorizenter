<?php
/**
 * Front-end shortcodes owned by Core (logic only — no markup).
 *
 * @package Authorizenter\Core
 */

namespace Authorizenter\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the shortcodes whose behaviour is purely Core's responsibility:
 * resolving a provider/context and emitting an authorize URL. Anything that
 * renders markup (buttons, labels, icons) belongs to the UI plugin — Core never
 * outputs presentation, so it carries no dependency on a presentation layer.
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
		add_shortcode( 'authorizenter_url', array( $this, 'render_url' ) );
	}

	/**
	 * Bare authorize URL: [authorizenter_url provider="google" context="default"].
	 *
	 * Returns only the authorize URL string (escaped), with no markup — handy for
	 * custom links, redirects, or passing into templates. Returns an empty string
	 * when the provider is missing/disabled in the context, or the visitor is
	 * already logged in.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_url( $atts ) {
		$url = $this->authorize_url( $atts, 'authorizenter_url' );
		return null === $url ? '' : esc_url( $url );
	}

	/**
	 * Validate attributes and build the provider authorize URL.
	 *
	 * @param array  $atts      Shortcode attributes.
	 * @param string $shortcode Shortcode tag (for shortcode_atts context).
	 * @return string|null Authorize URL (unescaped), or null when invalid.
	 */
	private function authorize_url( $atts, $shortcode ) {
		$atts = shortcode_atts(
			array(
				'provider'  => '',
				'context'   => 'default',
				'return_to' => '',
			),
			$atts,
			$shortcode
		);

		if ( ( is_user_logged_in() && ! authorizenter_is_builder_preview() ) || '' === $atts['provider'] ) {
			return null;
		}

		$context_id  = sanitize_key( $atts['context'] );
		$provider_id = sanitize_key( $atts['provider'] );
		$context     = $this->settings->get_context( $context_id );
		$providers   = $this->providers->enabled_for_context( $context );

		if ( ! isset( $providers[ $provider_id ] ) ) {
			return null;
		}

		$return_to = '' !== $atts['return_to'] ? $atts['return_to'] : $this->current_url();
		return add_query_arg(
			array(
				'context'   => $context_id,
				'return_to' => rawurlencode( $return_to ),
			),
			rest_url( AUTHORIZENTER_REST_NAMESPACE . '/authorize/' . $provider_id )
		);
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
