<?php
/**
 * Uninstall cleanup for Authorizenter Core.
 *
 * Removes the settings option, transients, and per-user meta created by the plugin.
 *
 * @package Authorizenter\Core
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Settings option.
delete_option( 'authorizenter_settings' );

// User meta: provider links + answers.
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ( 'authorizenter_answers', 'authorizenter_last_provider' ) OR meta_key LIKE 'authorizenter_link_%' OR meta_key LIKE 'authorizenter_answer_%'"
);

// Transients (flow state, JWKS, discovery caches).
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_authorizenter\_%' OR option_name LIKE '\_transient\_timeout\_authorizenter\_%'"
);
