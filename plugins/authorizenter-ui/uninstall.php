<?php
/**
 * Remove only unchanged pages explicitly created by this plugin.
 * Reused pages and pages customized after creation remain user content.
 *
 * @package Authorizenter\UI
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$options      = array( 'authorizenter_login_page_id', 'authorizenter_questions_page_id', 'authorizenter_pending_page_id' );
$azr_page_ids = array_values( (array) get_option( 'authorizenter_context_pages', array() ) );
foreach ( $options as $option ) {
	$azr_page_ids[] = (int) get_option( $option, 0 );
	delete_option( $option );
}
foreach ( array_unique( array_map( 'intval', $azr_page_ids ) ) as $page_id ) {
	$azr_post        = $page_id ? get_post( $page_id ) : null;
	$created_content = $azr_post ? get_post_meta( $page_id, '_authorizenter_created_content', true ) : '';
	if ( $azr_post && 'page' === $azr_post->post_type && '' !== $created_content && $created_content === $azr_post->post_content ) {
		wp_delete_post( $page_id, true );
	}
	if ( $page_id ) {
		delete_post_meta( $page_id, '_authorizenter_created_content' );
	}
}
delete_option( 'authorizenter_context_pages' );
