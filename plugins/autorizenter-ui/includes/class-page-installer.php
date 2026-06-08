<?php
/**
 * Auto-creates the login and questions pages on activation.
 *
 * @package Autorizenter\UI
 */

namespace Autorizenter\UI;

defined( 'ABSPATH' ) || exit;

/**
 * Creates two pages holding the shortcodes so a fresh install works immediately.
 * Users who prefer their own pages can delete these and place the shortcodes
 * wherever they like.
 */
class Page_Installer {

	const OPT_LOGIN_PAGE     = 'autorizenter_login_page_id';
	const OPT_QUESTIONS_PAGE = 'autorizenter_questions_page_id';
	const OPT_CONTEXT_PAGES  = 'autorizenter_context_pages'; // map: context_id => page_id.

	/**
	 * On activation, ensure the base pages and a page per context exist.
	 *
	 * @return void
	 */
	public static function activate() {
		self::ensure_page(
			self::OPT_QUESTIONS_PAGE,
			__( 'A few questions', 'autorizenter' ),
			'autorizenter-questions',
			'[autorizenter_questions]'
		);
		self::ensure_context_pages();
	}

	/**
	 * Ensure every Core login context has a corresponding login page.
	 *
	 * Safe to call repeatedly (on admin_init): it only creates pages that are
	 * missing, so adding a context in settings auto-creates its page. The "default"
	 * context maps to the canonical login page option for backward compatibility.
	 *
	 * @return void
	 */
	public static function ensure_context_pages() {
		if ( ! function_exists( 'Autorizenter\\Core\\autorizenter_core' ) ) {
			return;
		}
		$core = \Autorizenter\Core\autorizenter_core();
		$map  = get_option( self::OPT_CONTEXT_PAGES, array() );
		$map  = is_array( $map ) ? $map : array();

		foreach ( $core->settings->context_ids() as $id ) {
			$ctx   = $core->settings->get_context( $id );
			$label = isset( $ctx['label'] ) && '' !== $ctx['label'] ? $ctx['label'] : __( 'Sign in', 'autorizenter' );

			if ( 'default' === $id ) {
				self::ensure_page( self::OPT_LOGIN_PAGE, $label, 'autorizenter-login', '[autorizenter_login context="default"]' );
				$map['default'] = (int) get_option( self::OPT_LOGIN_PAGE, 0 );
				continue;
			}

			$existing = isset( $map[ $id ] ) ? (int) $map[ $id ] : 0;
			if ( $existing && 'page' === get_post_type( $existing ) && 'trash' !== get_post_status( $existing ) ) {
				continue;
			}
			$page_id = self::create_page(
				$label,
				'autorizenter-login-' . $id,
				'[autorizenter_login context="' . esc_attr( $id ) . '"]'
			);
			if ( $page_id ) {
				$map[ $id ] = $page_id;
			}
		}

		update_option( self::OPT_CONTEXT_PAGES, $map, false );
	}

	/**
	 * Resolve the login page URL for a context (used for deny fallbacks).
	 *
	 * @param string $context_id Context id.
	 * @return string Permalink, or '' if none.
	 */
	public static function url_for_context( $context_id ) {
		$map = get_option( self::OPT_CONTEXT_PAGES, array() );
		$id  = isset( $map[ $context_id ] ) ? (int) $map[ $context_id ] : 0;
		if ( ! $id && isset( $map['default'] ) ) {
			$id = (int) $map['default'];
		}
		return $id ? (string) get_permalink( $id ) : '';
	}

	/**
	 * On deactivation we keep the pages (avoid destroying user content) but do nothing.
	 *
	 * @return void
	 */
	public static function deactivate() {
		// Intentionally left as a no-op; pages are removed on uninstall.
	}

	/**
	 * Create a page if the stored id is missing/invalid.
	 *
	 * @param string $option  Option name storing the page id.
	 * @param string $title   Page title.
	 * @param string $slug    Page slug.
	 * @param string $content Page content.
	 * @return void
	 */
	private static function ensure_page( $option, $title, $slug, $content ) {
		$existing = (int) get_option( $option, 0 );
		if ( $existing && 'page' === get_post_type( $existing ) && 'trash' !== get_post_status( $existing ) ) {
			return;
		}

		$page_id = self::create_page( $title, $slug, $content );
		if ( $page_id ) {
			update_option( $option, $page_id, false );
		}
	}

	/**
	 * Create a published page and return its id (0 on failure).
	 *
	 * @param string $title   Page title.
	 * @param string $slug    Page slug.
	 * @param string $content Page content.
	 * @return int
	 */
	private static function create_page( $title, $slug, $content ) {
		$page_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_content' => $content,
				'post_status'  => 'publish',
				'post_type'    => 'page',
			)
		);
		return ( $page_id && ! is_wp_error( $page_id ) ) ? (int) $page_id : 0;
	}
}
