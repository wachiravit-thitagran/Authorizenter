<?php
/**
 * Builds provider adapter instances from settings.
 *
 * @package Authorizenter\Core
 */

namespace Authorizenter\Core;

use Authorizenter\Core\Providers\OIDC;
use Authorizenter\Core\Providers\OAuth2;
use Authorizenter\Core\Providers\Google;
use Authorizenter\Core\Providers\Line;
use Authorizenter\Core\Providers\Facebook;

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
	 * Map of built-in provider types => class.
	 *
	 * @return array
	 */
	public function classes() {
		$classes = array(
			'google'   => Google::class,
			'line'     => Line::class,
			'facebook' => Facebook::class,
			'oidc'     => OIDC::class,
			'oauth2'   => OAuth2::class,
		);

		/**
		 * Filter the available provider classes. Add your own adapters here.
		 *
		 * @param array $classes Map of type => class-string extending Provider_Base.
		 */
		return apply_filters( 'authorizenter_provider_classes', $classes );
	}

	/**
	 * Instantiate a single provider by id, or null if not configured/known.
	 *
	 * @param string $id Provider id.
	 * @return Provider_Base|null
	 */
	public function get( $id ) {
		$all = $this->settings->get( 'providers' );
		
		// Ensure the provider is at least configured in settings
		if ( ! isset( $all[ $id ] ) || ! is_array( $all[ $id ] ) ) {
			// For backward compatibility: if a built-in provider isn't strictly in settings yet
			// but matches a built-in type, we can return a blank configuration.
			$classes = $this->classes();
			if ( ! isset( $classes[ $id ] ) ) {
				return null;
			}
			$config = array();
			$type   = $id;
		} else {
			$config = $all[ $id ];
			// Determine the type. If 'type' is not explicitly set, default to $id.
			$type = isset( $config['type'] ) && '' !== $config['type'] ? $config['type'] : $id;
		}

		$classes = $this->classes();
		if ( ! isset( $classes[ $type ] ) ) {
			return null;
		}

		$config['id'] = $id;
		$class        = $classes[ $type ];
		return new $class( $this->settings, $config );
	}

	/**
	 * All enabled providers.
	 *
	 * @return Provider_Base[]
	 */
	public function enabled() {
		$out = array();
		$all = $this->settings->get( 'providers' );
		
		// Standard built-in ids are always checked, plus any custom ids found in settings
		$ids = array_unique( array_merge( array_keys( $this->classes() ), array_keys( $all ) ) );
		
		foreach ( $ids as $id ) {
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
