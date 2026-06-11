<?php
/**
 * Uninstall cleanup for Authorizenter UI.
 *
 * Removes the auto-created pages and their option references.
 *
 * @package Authorizenter\UI
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'authorizenter_login_page_id', 'authorizenter_questions_page_id' ) as $option ) {
	$page_id = (int) get_option( $option, 0 );
	if ( $page_id ) {
		wp_delete_post( $page_id, true );
	}
	delete_option( $option );
}

// Per-context login pages.
$context_pages = get_option( 'authorizenter_context_pages', array() );
if ( is_array( $context_pages ) ) {
	foreach ( $context_pages as $page_id ) {
		$page_id = (int) $page_id;
		if ( $page_id ) {
			wp_delete_post( $page_id, true );
		}
	}
}
delete_option( 'authorizenter_context_pages' );
