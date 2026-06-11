<?php
/**
 * Stub for \Authorizenter\Core\authorizenter_core() used in UI unit tests.
 *
 * Must be required AFTER core classes are loaded (it references Settings).
 *
 * @package Authorizenter\Core\Tests
 */

namespace Authorizenter\Core;

function authorizenter_core() {
	return $GLOBALS['__core'] ?? null;
}

// No-op debug logger for tests (the real one lives in the plugin bootstrap file,
// which is not loaded under PHPUnit).
if ( ! function_exists( __NAMESPACE__ . '\\authorizenter_log' ) ) {
	function authorizenter_log( $message, array $context = array() ) {} // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
}

// Builder-preview detection stub (no Elementor under PHPUnit).
if ( ! function_exists( __NAMESPACE__ . '\\authorizenter_is_builder_preview' ) ) {
	function authorizenter_is_builder_preview() {
		return ! empty( $GLOBALS['__builder_preview'] );
	}
}
