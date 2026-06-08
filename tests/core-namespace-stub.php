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
