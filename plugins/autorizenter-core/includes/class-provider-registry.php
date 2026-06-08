<?php
/**
 * Builds provider adapter instances from settings.
 *
 * @package Autorizenter\Core
 */

namespace Autorizenter\Core;

use Autorizenter\Core\Providers\OIDC;
use Autorizenter\Core\Providers\Google;
use Autorizenter\Core\Providers\Line;
use Autorizenter\Core\Providers\Facebook;

defined( 'ABSPATH' ) || exit;

/**
 * Knows the available provider classes and instantiates configured ones.
 */
class Provider_Registry {

	/**
	 * Settings store.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Map of built-in provider id => class.
	 *
	 * @return array
	 */
	public function classes() {
		$classes = array(
			'google'   => Google::class,
			'line'     => Line::class,
			'facebook' => Facebook::class,
			'oidc'     => OIDC::class,
		);

		/**
		 * Filter the available provider classes. Add your own adapters here.
		 *
		 * @param array $classes Map of id => class-string extending Provider_Base.
		 */
		return apply_filters( 'autorizenter_provider_classes', $classes );
	}

	/**
	 * Instantiate a single provider by id, or null if not configured/known.
	 *
	 * @param string $id Provider id.
	 * @return Provider_Base|null
	 */
	public function get( $id ) {
		$classes = $this->classes();
		if ( ! isset( $classes[ $id ] ) ) {
			return null;
		}
		$all          = $this->settings->get( 'providers' );
		$config       = isset( $all[ $id ] ) && is_array( $all[ $id ] ) ? $all[ $id ] : array();
		$config['id'] = $id;
		$class        = $classes[ $id ];
		return new $class( $this->settings, $config );
	}

	/**
	 * All enabled providers.
	 *
	 * @return Provider_Base[]
	 */
	public function enabled() {
		$out = array();
		foreach ( array_keys( $this->classes() ) as $id ) {
			$provider = $this->get( $id );
			if ( $provider && $provider->is_enabled() ) {
				$out[ $id ] = $provider;
			}
		}
		return $out;
	}

	/**
	 * Enabled providers limited to those allowed by a resolved context.
	 *
	 * @param array $context Resolved context (see Settings::get_context()).
	 * @return Provider_Base[]
	 */
	public function enabled_for_context( array $context ) {
		$enabled = $this->enabled();
		$allow   = isset( $context['providers'] ) && is_array( $context['providers'] ) ? $context['providers'] : array();
		if ( empty( $allow ) ) {
			return $enabled; // empty list = all enabled providers.
		}
		$out = array();
		foreach ( $allow as $id ) {
			if ( isset( $enabled[ $id ] ) ) {
				$out[ $id ] = $enabled[ $id ];
			}
		}
		return $out;
	}

	/**
	 * Whether a provider id is permitted in a given context.
	 *
	 * @param string $id      Provider id.
	 * @param array  $context Resolved context.
	 * @return bool
	 */
	public function is_allowed_in_context( $id, array $context ) {
		$allow = isset( $context['providers'] ) && is_array( $context['providers'] ) ? $context['providers'] : array();
		return empty( $allow ) || in_array( $id, $allow, true );
	}
}
