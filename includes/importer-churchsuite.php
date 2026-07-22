<?php
/**
 * ChurchSuite Importer
 *
 * Fetches events from the ChurchSuite public JSON feed and upserts
 * them as WordPress 'event' CPT posts.
 *
 * Deduplication key: ChurchSuite identifier (e.g. 'flkbwvjz')
 * stored in meta as 'event_churchsuite_id'. On each import, existing
 * posts are updated; new posts are created. ChurchSuite is canonical —
 * all fields are overwritten on every import.
 *
 * Triggered by:
 *  - WP-Cron on the schedule set in plugin settings
 *  - Manual 'Sync Now' button in settings
 *  - Server cron hitting the REST endpoint /wp-json/church-events/v1/sync
 *  - WP-CLI: wp church-events sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schedule or reschedule the ChurchSuite cron job.
 * Called on plugin activation and when sync interval setting changes.
 */
function ce_schedule_churchsuite_cron() {
	if ( ce_get_option( 'source_type' ) !== 'churchsuite' ) return;

	$interval = ce_get_option( 'sync_interval', 'hourly' );
	$hook     = 'ce_churchsuite_sync';

	// Clear existing schedule and reschedule
	$timestamp = wp_next_scheduled( $hook );
	if ( $timestamp ) wp_unschedule_event( $timestamp, $hook );

	wp_schedule_event( time(), $interval, $hook );
}
add_action( 'ce_settings_saved', 'ce_schedule_churchsuite_cron' );

/**
 * Clear cron on deactivation.
 */
function ce_clear_churchsuite_cron() {
	wp_clear_scheduled_hook( 'ce_churchsuite_sync' );
}
register_deactivation_hook( CE_PLUGIN_FILE, 'ce_clear_churchsuite_cron' );

/**
 * Hook the importer to the cron event.
 */
add_action( 'ce_churchsuite_sync', 'ce_run_churchsuite_import' );

/**
 * Also fire when the manual sync AJAX action triggers.
 */
add_action( 'ce_manual_sync', function( $source ) {
	if ( $source === 'churchsuite' ) {
		ce_run_churchsuite_import();
	}
} );

// ---------------------------------------------------------------------------
// REST endpoint for server cron
// ---------------------------------------------------------------------------

/**
 * Register a secured REST endpoint to trigger import from a server cron job.
 * Usage: curl -X POST https://yoursite.com/wp-json/church-events/v1/sync
 *             -H "X-CE-Sync-Key: YOUR_SYNC_KEY"
 */
function ce_register_sync_endpoint() {
	register_rest_route( 'church-events/v1', '/sync', array(
		'methods'             => 'POST',
		'callback'            => 'ce_rest_sync_callback',
		'permission_callback' => 'ce_rest_sync_permission',
	) );
}
add_action( 'rest_api_init', 'ce_register_sync_endpoint' );

function ce_rest_sync_permission( $request ) {
	$key            = $request->get_header( 'X-CE-Sync-Key' );
	$expected       = ce_get_option( 'sync_key', '' );
	if ( empty( $expected ) ) return false;
	return hash_equals( $expected, (string) $key );
}

function ce_rest_sync_callback() {
	$source = ce_get_option( 'source_type', 'churchsuite' );
	do_action( 'ce_manual_sync', $source );
	return rest_ensure_response( array( 'success' => true, 'message' => 'Sync triggered.' ) );
}

// ---------------------------------------------------------------------------
// Main importer
// ---------------------------------------------------------------------------

/**
 * Run the full ChurchSuite import.
 *
 * @return array { imported: int, updated: int, skipped: int, errors: int }
 */
function ce_run_churchsuite_import() {
	$url = ce_get_option( 'churchsuite_url', '' );

	if ( empty( $url ) ) {
		ce_log( 'ChurchSuite import skipped: no feed URL configured.' );
		return array( 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0 );
	}

	// Concurrency guard — bail if another ChurchSuite import is already running, so
	// overlapping cron + manual "Sync Now" runs can't each insert the full feed. The
	// transient self-expires as a safety net.
	if ( get_transient( 'ce_churchsuite_import_lock' ) ) {
		ce_log( 'ChurchSuite import skipped: another import is already running.' );
		return array( 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0 );
	}
	set_transient( 'ce_churchsuite_import_lock', time(), 15 * MINUTE_IN_SECONDS );

	try {
		ce_log( 'ChurchSuite import started. Feed: ' . $url );

	$events = ce_fetch_churchsuite_feed( $url );

	if ( is_wp_error( $events ) ) {
		ce_log( 'ChurchSuite feed error: ' . $events->get_error_message() );
		update_option( 'ce_last_sync_status', array(
			'time'    => current_time( 'mysql' ),
			'status'  => 'error',
			'message' => $events->get_error_message(),
		) );
		return array( 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 1 );
	}

	$counts = array( 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'trashed' => 0 );

	// Build a list of identifiers present in the feed
	$feed_identifiers = array();

	// Build site lookup from top-level feed data (id → name).
	// Re-fetch the raw feed to extract the sites array — it's discarded by ce_fetch_churchsuite_feed().
	$site_lookup = ce_build_site_lookup_from_events( $events );
	ce_log( 'Site lookup: ' . wp_json_encode( $site_lookup ) );

	foreach ( $events as $event ) {
		if ( ! empty( $event['identifier'] ) ) {
			$feed_identifiers[] = $event['identifier'];
		}
		$result = ce_upsert_churchsuite_event( $event, $site_lookup );
		if ( is_wp_error( $result ) ) {
			$counts['errors']++;
			ce_log( 'Error importing event "' . ( $event['name'] ?? '' ) . '": ' . $result->get_error_message() );
		} elseif ( $result === 'imported' ) {
			$counts['imported']++;
		} elseif ( $result === 'updated' ) {
			$counts['updated']++;
		} elseif ( $result === 'skipped' ) {
			$counts['skipped']++;
		}
	}

	// Trash any WordPress events from ChurchSuite that are no longer in the feed
	$trashed = ce_trash_removed_events( $feed_identifiers );
	$counts['trashed'] = $trashed;

	$summary = sprintf(
		'ChurchSuite import complete. Imported: %d, Updated: %d, Skipped: %d, Trashed: %d, Errors: %d',
		$counts['imported'], $counts['updated'], $counts['skipped'], $counts['trashed'], $counts['errors']
	);

	ce_log( $summary );

	update_option( 'ce_last_sync_status', array(
		'time'    => current_time( 'mysql' ),
		'status'  => 'success',
		'message' => $summary,
		'counts'  => $counts,
	) );

	return $counts;
	} finally {
		// Always release the lock, even if the import bailed early or errored.
		delete_transient( 'ce_churchsuite_import_lock' );
	}
}

// ---------------------------------------------------------------------------
// Feed fetching
// ---------------------------------------------------------------------------

/**
 * Fetch and decode the ChurchSuite JSON feed.
 *
 * @param string $url
 * @return array|WP_Error
 */
function ce_fetch_churchsuite_feed( $url ) {
	$response = wp_remote_get( $url, array(
		'timeout'    => 30,
		'user-agent' => 'WordPress/Church-Events-Plugin',
	) );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( $code !== 200 ) {
		return new WP_Error( 'http_error', 'Feed returned HTTP ' . $code );
	}

	$body = wp_remote_retrieve_body( $response );
	$data = json_decode( $body, true );

	if ( json_last_error() !== JSON_ERROR_NONE ) {
		return new WP_Error( 'json_error', 'Failed to parse feed JSON: ' . json_last_error_msg() );
	}

	// ChurchSuite feed can be a bare array or wrapped in a 'events' key
	if ( isset( $data['events'] ) && is_array( $data['events'] ) ) {
		return $data['events'];
	}

	if ( is_array( $data ) ) {
		return $data;
	}

	return new WP_Error( 'empty_feed', 'Feed returned no events.' );
}

// ---------------------------------------------------------------------------
// Upsert a single event
// ---------------------------------------------------------------------------

/**
 * Create or update a WordPress event post from a ChurchSuite event array.
 *
 * @param array $cs  ChurchSuite event data
 * @return string|WP_Error  'imported' | 'updated' | 'skipped' | WP_Error
 */
function ce_upsert_churchsuite_event( $cs, $site_lookup = array() ) {

	// Require minimum fields
	if ( empty( $cs['identifier'] ) || empty( $cs['name'] ) ) {
		return new WP_Error( 'invalid_event', 'Event missing identifier or name.' );
	}

	$identifier = sanitize_text_field( $cs['identifier'] );
	$action     = 'imported';

	// Check if post already exists by ChurchSuite identifier
	$existing = ce_get_post_by_churchsuite_id( $identifier, $cs['id'] ?? null );
	$post_id  = $existing ? $existing->ID : 0;

	// ---------------------------------------------------------------------------
	// Parse dates and times
	// ---------------------------------------------------------------------------

	$datetime_start = $cs['datetime_start'] ?? '';
	$datetime_end   = $cs['datetime_end']   ?? '';

	$start_date = '';
	$start_time = '';
	$end_date   = '';
	$end_time   = '';

	if ( $datetime_start ) {
		$dt         = date_create( $datetime_start );
		$start_date = $dt ? $dt->format( 'Ymd' )    : '';
		$start_time = $dt ? $dt->format( 'H:i' )  : '';
	}

	if ( $datetime_end ) {
		$dt       = date_create( $datetime_end );
		$end_date = $dt ? $dt->format( 'Ymd' )   : '';
		$end_time = $dt ? $dt->format( 'H:i' ) : '';
	}

	// All-day: treat as all-day if time is midnight
	$all_day = ( $start_time === '00:00' || empty( $start_time ) );

	// ---------------------------------------------------------------------------
	// Parse location
	// ---------------------------------------------------------------------------

	$location_name = '';
	$location_addr = '';
	$map_address   = '';

	if ( ! empty( $cs['location']['name'] ) ) {
		$location_name = sanitize_text_field( $cs['location']['name'] );
	}

	// Build address from site.address if location.address is empty
	if ( ! empty( $cs['site']['address'] ) ) {
		$addr = $cs['site']['address'];
		$parts = array_filter( array(
			$addr['line1']    ?? '',
			$addr['line2']    ?? '',
			$addr['line3']    ?? '',
			$addr['city']     ?? '',
			$addr['county']   ?? '',
			$addr['postcode'] ?? '',
		) );
		$location_addr = implode( ', ', $parts );
		$map_address   = $location_addr;
	}

	// ---------------------------------------------------------------------------
	// Parse signup / booking
	// ---------------------------------------------------------------------------

	$signup_enabled = '0';
	$booking_url    = '';
	$booking_text   = '';

	if ( ! empty( $cs['signup_options'] ) ) {
		$signup = $cs['signup_options'];
		$signup_enabled = isset( $signup['signup_enabled'] ) ? (string) $signup['signup_enabled'] : '0';

		if ( ! empty( $signup['tickets']['url'] ) ) {
			$booking_url  = esc_url_raw( $signup['tickets']['url'] );
			$booking_text = __( 'Sign Up', 'church-events' );
		}
	}

	// ---------------------------------------------------------------------------
	// Build post array
	// ---------------------------------------------------------------------------

	$post_data = array(
		'post_title'   => sanitize_text_field( $cs['name'] ),
		'post_content' => ! empty( $cs['description'] ) ? wp_kses_post( $cs['description'] ) : '',
		'post_status'  => 'publish',
		'post_type'    => 'event',
	);

	if ( $post_id ) {
		// Update existing
		$post_data['ID'] = $post_id;
		$result = wp_update_post( $post_data, true );
		$action = 'updated';
	} else {
		// Insert new
		$result = wp_insert_post( $post_data, true );
		$action = 'imported';
	}

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$post_id = $result;

	// ---------------------------------------------------------------------------
	// Save meta fields
	// ---------------------------------------------------------------------------

	$meta = array(
		'event_start_date'           => $start_date,
		'event_start_time'           => $start_time,
		'event_end_date'             => $end_date,
		'event_end_time'             => $end_time,
		'event_all_day'              => $all_day ? '1' : '0',
		'event_location'             => $location_name,
		'event_address'              => $location_addr,
		'event_map_address'          => $map_address,
		'event_booking_url'          => $signup_enabled === '1' ? $booking_url : '',
        'event_booking_text'         => $signup_enabled === '1' ? $booking_text : '',
        'event_signup_link'          => $signup_enabled === '1' ? $booking_url : '', // Legacy ACF field compat
		'event_signup_enabled'       => $signup_enabled,
		'event_source'               => 'churchsuite',
		'event_source_id'            => $identifier,
		'event_churchsuite_id'       => $identifier,
		'event_churchsuite_category' => ! empty( $cs['category']['name'] ) ? sanitize_text_field( $cs['category']['name'] ) : '',
	);

	foreach ( $meta as $key => $value ) {
		update_post_meta( $post_id, $key, $value );
	}

	// ---------------------------------------------------------------------------
	// Assign event-category taxonomy term
	// ---------------------------------------------------------------------------

	if ( ! empty( $cs['category']['name'] ) ) {
		ce_assign_category( $post_id, $cs['category']['name'], $cs['category']['color'] ?? '' );
	}

	// ---------------------------------------------------------------------------
	// Assign event-site taxonomy terms
	// ---------------------------------------------------------------------------

	ce_assign_sites( $post_id, $cs['site_ids'] ?? array(), $site_lookup );

	// ---------------------------------------------------------------------------
	// Assign event-featured taxonomy term
	// ---------------------------------------------------------------------------

	if ( ! empty( $cs['signup_options']['public']['featured'] ) ) {
		if ( ! term_exists( 'featured', 'event-featured' ) ) {
			wp_insert_term( 'Featured', 'event-featured', array( 'slug' => 'featured' ) );
		}
		wp_set_object_terms( $post_id, 'featured', 'event-featured', false );
	} else {
		wp_set_object_terms( $post_id, array(), 'event-featured', false );
	}

	// ---------------------------------------------------------------------------
	// Assign event-month taxonomy term (auto from start date)
	// ---------------------------------------------------------------------------

	if ( $start_date ) {
		ce_auto_assign_event_month( $post_id );
	}

	// ---------------------------------------------------------------------------
	// Handle featured image
	// ---------------------------------------------------------------------------

	if ( ! empty( $cs['images']['md']['url'] ) ) {
		ce_set_featured_image( $post_id, $cs['images']['md']['url'], $identifier );
	} elseif ( ! empty( $cs['images']['lg']['url'] ) ) {
		ce_set_featured_image( $post_id, $cs['images']['lg']['url'], $identifier );
	}

	return $action;
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Find an existing event post by ChurchSuite identifier.
 *
 * @param string $identifier
 * @return WP_Post|null
 */
function ce_get_post_by_churchsuite_id( $identifier, $numeric_id = null ) {

	// First check new-style meta key (plugin imports)
	$posts = get_posts( array(
		'post_type'      => 'event',
		'post_status'    => 'any',
		'posts_per_page' => 1,
		'meta_query'     => array(
			array(
				'key'   => 'event_churchsuite_id',
				'value' => $identifier,
			),
		),
	) );

	if ( ! empty( $posts ) ) return $posts[0];

	// Fall back to old ACF-style numeric ID (WPAllImport legacy posts)
	if ( $numeric_id ) {
		$posts = get_posts( array(
			'post_type'      => 'event',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'meta_query'     => array(
				array(
					'key'   => 'event_id',
					'value' => $numeric_id,
				),
			),
		) );

		if ( ! empty( $posts ) ) return $posts[0];
	}

	return null;
}

/**
 * Assign an event-category term to a post, creating it if needed.
 *
 * @param int    $post_id
 * @param string $category_name
 */
function ce_assign_category( $post_id, $category_name, $category_color = '' ) {
	$category_name = sanitize_text_field( $category_name );
	$slug          = sanitize_title( $category_name );

	// Create term if it doesn't exist
	if ( ! term_exists( $slug, 'event-category' ) ) {
		wp_insert_term( $category_name, 'event-category', array( 'slug' => $slug ) );
	}

	wp_set_object_terms( $post_id, $slug, 'event-category', false );

	// Seed colour from ChurchSuite only if none set — manual edits win
	$term = get_term_by( 'slug', $slug, 'event-category' );
	if ( $term && ! is_wp_error( $term ) && empty( get_term_meta( $term->term_id, 'ce_category_color', true ) ) ) {
		$color = sanitize_hex_color( $category_color );
		if ( $color ) {
			update_term_meta( $term->term_id, 'ce_category_color', $color );
		}
	}
}

/**
 * Build a site lookup array (id => name) from the events array itself.
 * Uses the per-event 'site' object rather than the top-level 'sites' key,
 * which isn't reliably present in all ChurchSuite feed configurations.
 *
 * @param array $events  Array of ChurchSuite event arrays.
 * @return array         e.g. [ 1 => 'City Centre', 2 => 'Southside' ]
 */
function ce_build_site_lookup_from_events( $events ) {
    $lookup = array();
    foreach ( $events as $event ) {
        if ( ! empty( $event['site']['id'] ) && ! empty( $event['site']['name'] ) ) {
            $id = (int) $event['site']['id'];
            if ( ! isset( $lookup[ $id ] ) ) {
                $lookup[ $id ] = sanitize_text_field( $event['site']['name'] );
            }
        }
    }
    return $lookup;
}

/**
 * Assign event-site taxonomy terms to a post.
 * Silently skips if site_ids is empty (sites not used on this install).
 *
 * @param int   $post_id
 * @param array $site_ids    e.g. [1, 3]
 * @param array $site_lookup e.g. [ 1 => 'City Centre', 3 => 'Eastville' ]
 */
function ce_assign_sites( $post_id, $site_ids, $site_lookup ) {
	if ( empty( $site_ids ) || empty( $site_lookup ) ) {
		return;
	}

	$site_names = array();
	foreach ( $site_ids as $id ) {
		$id = (int) $id;
		if ( isset( $site_lookup[ $id ] ) ) {
			$site_names[] = $site_lookup[ $id ];
		}
	}

	if ( empty( $site_names ) ) return;

	// Create any terms that don't exist yet, then assign
	foreach ( $site_names as $name ) {
		$slug = sanitize_title( $name );
		if ( ! term_exists( $slug, 'event-site' ) ) {
			wp_insert_term( $name, 'event-site', array( 'slug' => $slug ) );
		}
	}

	$slugs = array_map( 'sanitize_title', $site_names );
	wp_set_object_terms( $post_id, $slugs, 'event-site', false );
}

/**
 * Download a remote image and set it as the post featured image.
 * Skips download if the image hasn't changed (checks stored URL against new URL).
 *
 * @param int    $post_id
 * @param string $image_url
 * @param string $identifier  Used as filename base
 */
function ce_set_featured_image( $post_id, $image_url, $identifier ) {
	// Check if image URL has changed since last import
	$stored_url = get_post_meta( $post_id, '_ce_image_url', true );
	if ( $stored_url === $image_url && has_post_thumbnail( $post_id ) ) {
		return; // Image unchanged — skip download
	}

	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	// Download to temp file
	$tmp = download_url( $image_url );
	if ( is_wp_error( $tmp ) ) {
		ce_log( 'Image download failed for ' . $identifier . ': ' . $tmp->get_error_message() );
		return;
	}

	// Get file extension from URL
	$ext      = pathinfo( parse_url( $image_url, PHP_URL_PATH ), PATHINFO_EXTENSION ) ?: 'jpg';
	$filename = 'event-' . $identifier . '.' . $ext;

	$file_array = array(
		'name'     => $filename,
		'tmp_name' => $tmp,
	);

	// Remove old thumbnail if exists
	$old_thumb = get_post_thumbnail_id( $post_id );

	// Sideload into media library
	$attachment_id = media_handle_sideload( $file_array, $post_id );

	if ( is_wp_error( $attachment_id ) ) {
		@unlink( $tmp );
		ce_log( 'Image sideload failed for ' . $identifier . ': ' . $attachment_id->get_error_message() );
		return;
	}

	// Set as featured image
	set_post_thumbnail( $post_id, $attachment_id );

	// Store URL so we can skip unchanged images on next import
	update_post_meta( $post_id, '_ce_image_url', $image_url );

	// Delete old attachment to avoid media library bloat
	if ( $old_thumb && $old_thumb !== $attachment_id ) {
		wp_delete_attachment( $old_thumb, true );
	}
}

/**
 * Trash WordPress event posts that are no longer in the ChurchSuite feed.
 * Only affects posts with event_source = 'churchsuite'.
 *
 * @param array $feed_identifiers  List of identifier slugs present in the feed
 * @return int  Number of posts trashed
 */
function ce_trash_removed_events( $feed_identifiers ) {
	if ( empty( $feed_identifiers ) ) return 0;

	// Get all WordPress events sourced from ChurchSuite
	$existing_posts = get_posts( array(
		'post_type'      => 'event',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'meta_query'     => array(
			array(
				'key'   => 'event_source',
				'value' => 'churchsuite',
			),
		),
		'fields' => 'ids',
	) );

	if ( empty( $existing_posts ) ) return 0;

	$trashed = 0;

	$today  = date( 'Ymd' );
	$months = (int) ce_get_option( 'past_retention_months', 1 );
	if ( ! in_array( $months, array( 1, 3, 6 ), true ) ) $months = 1;
	$cutoff = date( 'Ymd', strtotime( '-' . $months . ' months' ) );

	foreach ( $existing_posts as $post_id ) {
		$identifier = get_post_meta( $post_id, 'event_churchsuite_id', true );

		// Also check legacy numeric ID posts that haven't been re-imported yet
		if ( empty( $identifier ) ) continue;

		if ( ! in_array( $identifier, $feed_identifiers, true ) ) {

			// Past events naturally drop out of the feed — keep them for the
			// configured retention window so the calendar grid stays populated.
			$start_date = get_post_meta( $post_id, 'event_start_date', true );
			if ( ! empty( $start_date ) && $start_date < $today && $start_date >= $cutoff ) {
				continue;
			}

			wp_trash_post( $post_id );
			ce_log( 'Trashed event no longer in ChurchSuite feed: ' . get_the_title( $post_id ) . ' (' . $identifier . ')' );
			$trashed++;
		}
	}

	return $trashed;
}

/**
 * Write a timestamped message to the plugin log.
 * Log is stored in wp-content/uploads/church-events-log.txt
 *
 * @param string $message
 */
function ce_log( $message ) {
	$upload_dir = wp_upload_dir();
	$log_dir    = $upload_dir['basedir'] . '/church-events/';

	wp_mkdir_p( $log_dir );

	// Block direct web access to this directory
	$htaccess = $log_dir . '.htaccess';
	if ( ! file_exists( $htaccess ) ) {
		file_put_contents( $htaccess, "Deny from all\n" );
	}

	$log_file = $log_dir . 'church-events-log.txt';
	$line     = '[' . current_time( 'Y-m-d H:i:s' ) . '] ' . $message . PHP_EOL;

	// Keep log to last 500 lines
	if ( file_exists( $log_file ) ) {
		$lines = file( $log_file );
		if ( count( $lines ) > 490 ) {
			$lines = array_slice( $lines, -490 );
			file_put_contents( $log_file, implode( '', $lines ) );
		}
	}

	file_put_contents( $log_file, $line, FILE_APPEND | LOCK_EX );
}