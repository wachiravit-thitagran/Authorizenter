<?php
/**
 * Uninstall cleanup for Autorizenter Core.
 *
 * Removes the settings option, transients, and per-user meta created by the plugin.
 *
 * @package Autorizenter\Core
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Settings option.
delete_option( 'autorizenter_settings' );

// User meta: provider links + answers.
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ( 'autorizenter_answers', 'autorizenter_last_provider' ) OR meta_key LIKE 'autorizenter_link_%' OR meta_key LIKE 'autorizenter_answer_%'"
);

// Transients (flow state, JWKS, discovery caches).
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_autorizenter\_%' OR option_name LIKE '\_transient\_timeout\_autorizenter\_%'"
);
