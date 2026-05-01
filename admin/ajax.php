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

	// Will call the appropriate importer once import modules are built (Phase 4/5).
	// For now, report that no importer is active.
	$source = ce_get_option( 'source_type', 'churchsuite' );

	do_action( 'ce_manual_sync', $source );

	// If no handler responded, return a placeholder message
	wp_send_json_success( array(
		'message' => sprintf(
			/* translators: %s: source name */
			__( 'Sync triggered for source: %s. Import module not yet active.', 'church-events' ),
			esc_html( $source )
		),
	) );
}
add_action( 'wp_ajax_ce_manual_sync', 'ce_ajax_manual_sync' );
