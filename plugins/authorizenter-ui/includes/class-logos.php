<?php
/**
 * Inline brand SVG logos for provider login buttons.
 *
 * @package Authorizenter\UI
 */

namespace Authorizenter\UI;

defined( 'ABSPATH' ) || exit;

/**
 * Returns small, self-contained SVG marks in each provider's brand colors.
 *
 * Markup is static and trusted (no user input), so templates echo it directly.
 * Unknown providers get a neutral lock icon that adopts the button's accent color.
 */
class Logos {

	/**
	 * Get the SVG logo for a provider id.
	 *
	 * @param string $id Provider id.
	 * @return string SVG markup.
	 */
	public static function svg( $id ) {
		switch ( $id ) {
			case 'google':
				return '<svg viewBox="0 0 48 48" width="20" height="20" aria-hidden="true" focusable="false">'
					. '<path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>'
					. '<path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>'
					. '<path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>'
					. '<path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>'
					. '</svg>';

			case 'facebook':
				return '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false">'
					. '<path fill="#1877F2" d="M24 12.07C24 5.4 18.63 0 12 0S0 5.4 0 12.07C0 18.1 4.39 23.1 10.13 24v-8.44H7.08v-3.49h3.05V9.41c0-3.02 1.79-4.69 4.53-4.69 1.31 0 2.68.24 2.68.24v2.97h-1.51c-1.49 0-1.96.93-1.96 1.89v2.25h3.33l-.53 3.49h-2.8V24C19.61 23.1 24 18.1 24 12.07z"/>'
					. '</svg>';

			case 'line':
				return '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false">'
					. '<rect width="24" height="24" rx="6" fill="#06C755"/>'
					. '<path fill="#fff" d="M20 10.4c0-3.58-3.59-6.5-8-6.5s-8 2.92-8 6.5c0 3.21 2.85 5.9 6.69 6.41.26.06.62.17.71.4.08.21.05.54.03.75l-.11.69c-.04.2-.16.8.7.44s4.63-2.73 6.32-4.67c1.16-1.28 1.96-2.58 1.96-4.42z"/>'
					. '<path fill="#06C755" d="M9.4 8.9h-.56c-.09 0-.16.07-.16.15v3.49c0 .08.07.15.16.15h.56c.09 0 .16-.07.16-.15V9.05c0-.08-.07-.15-.16-.15zm3.86 0h-.56c-.09 0-.16.07-.16.15v2.07l-1.6-2.16-.01-.02-.01-.01h-.6c-.09 0-.16.07-.16.15v3.49c0 .08.07.15.16.15h.56c.09 0 .16-.07.16-.15v-2.07l1.6 2.16.04.04h.58c.09 0 .16-.07.16-.15V9.05c0-.08-.07-.15-.16-.15zm-5.2 2.93H6.54V9.05c0-.08-.07-.15-.16-.15h-.56c-.09 0-.16.07-.16.15v3.49c0 .04.02.08.04.1.03.03.06.05.11.05h2.25c.09 0 .16-.07.16-.15v-.56c0-.08-.07-.15-.16-.15zm9.5-1.97c.09 0 .16-.07.16-.15v-.56c0-.08-.07-.15-.16-.15h-2.25c-.04 0-.08.02-.11.04-.03.03-.04.06-.04.11v3.49c0 .04.02.08.04.1.03.03.06.05.11.05h2.25c.09 0 .16-.07.16-.15v-.56c0-.08-.07-.15-.16-.15h-1.53v-.59h1.53c.09 0 .16-.07.16-.15v-.56c0-.08-.07-.15-.16-.15h-1.53v-.59z"/>'
					. '</svg>';

			case 'oidc':
				return self::lock( '#6b46c1' );

			default:
				return self::lock( 'currentColor' );
		}
	}

	/**
	 * Neutral lock icon used for generic / unknown providers.
	 *
	 * @param string $color Stroke color.
	 * @return string
	 */
	private static function lock( $color ) {
		return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="' . esc_attr( $color ) . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
			. '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>'
			. '</svg>';
	}
}
