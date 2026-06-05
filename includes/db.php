<?php
/**
 * Database performance optimisations for Church Events.
 *
 * Adds indexes to wp_postmeta for the meta keys used in calendar
 * date range queries. Without these, every calendar month navigation
 * performs a full table scan on wp_postmeta.
 *
 * Safe to run multiple times — checks for index existence before creating.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add database indexes for event meta keys used in REST queries.
 * Called on plugin activation and can be triggered manually.
 */
function ce_add_meta_indexes() {
	global $wpdb;

	$table = $wpdb->postmeta;

	// Meta keys that benefit from indexing
	$keys = array(
		'event_start_date',
		'event_end_date',
		'event_churchsuite_id',
		'event_source',
	);

	foreach ( $keys as $meta_key ) {

		$index_name = 'ce_' . str_replace( '-', '_', $meta_key );

		// Check if index already exists
		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(1) FROM information_schema.statistics
			 WHERE table_schema = DATABASE()
			 AND table_name = %s
			 AND index_name = %s",
			$table,
			$index_name
		) );

		if ( $exists ) continue;

		// Add composite index on (meta_key, meta_value) for this key
		// We use a prefix length on meta_value since it's a longtext column
		$wpdb->query(
			"CREATE INDEX `{$index_name}`
			 ON `{$table}` (meta_key(32), meta_value(20))"
		);

		ce_log( 'Created DB index: ' . $index_name );
	}
}

/**
 * Run index creation via admin action — accessible from Settings page.
 * Also fires automatically on plugin activation.
 */
add_action( 'ce_manual_sync', function() {
	// Re-check indexes on every sync in case they were dropped
	ce_add_meta_indexes();
} );