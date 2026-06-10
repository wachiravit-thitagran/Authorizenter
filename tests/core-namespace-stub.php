<?php
/**
 * Stub for \Autorizenter\Core\autorizenter_core() used in UI unit tests.
 *
 * Must be required AFTER core classes are loaded (it references Settings).
 *
 * @package Autorizenter\Core\Tests
 */

namespace Autorizenter\Core;

function autorizenter_core() {
	return $GLOBALS['__core'] ?? null;
}

// No-op debug logger for tests (the real one lives in the plugin bootstrap file,
// which is not loaded under PHPUnit).
if ( ! function_exists( __NAMESPACE__ . '\\autorizenter_log' ) ) {
	function autorizenter_log( $message, array $context = array() ) {} // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
}
