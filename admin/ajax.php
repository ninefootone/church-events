<?php
/**
 * AJAX handler for the manual sync button.
 * Nonce localisation is also handled here.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Localise nonce for admin JS.
 *
 * @param string $hook
 */
function ce_admin_localize( $hook ) {
	if ( strpos( $hook, 'church-events-settings' ) === false ) return;

	wp_localize_script( 'ce-admin', 'ceAdmin', array(
		'nonce' => wp_create_nonce( 'ce_manual_sync' ),
	) );
}
add_action( 'admin_enqueue_scripts', 'ce_admin_localize', 20 );

/**
 * AJAX: trigger a manual sync.
 */
function ce_ajax_manual_sync() {
	check_ajax_referer( 'ce_manual_sync', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'church-events' ) ) );
	}

	$source = ce_get_option( 'source_type', 'churchsuite' );

	do_action( 'ce_manual_sync', $source );

	$last    = get_option( 'ce_last_sync_status', null );
	$message = $last ? $last['message'] : __( 'Sync triggered.', 'church-events' );
	$status  = $last && $last['status'] === 'error' ? 'error' : 'success';

	if ( $status === 'error' ) {
		wp_send_json_error( array( 'message' => $message ) );
	} else {
		wp_send_json_success( array( 'message' => $message ) );
	}
}
add_action( 'wp_ajax_ce_manual_sync', 'ce_ajax_manual_sync' );
