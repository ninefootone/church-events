<?php
/**
 * Google Calendar Importer
 *
 * Fetches events from the Google Calendar API (server-side only — the API
 * key never reaches the browser) and upserts them as WordPress 'event' CPT posts.
 *
 * Deduplication key: Google Calendar event ID stored in meta as
 * 'event_google_id' (and 'event_source_id'). On each import, existing posts
 * are updated; new posts are created. Google Calendar is canonical — all
 * fields are overwritten on every import.
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
 * Schedule or reschedule the Google Calendar cron job.
 * Called on plugin activation and when sync interval setting changes.
 */
function ce_schedule_google_cron() {
	if ( ce_get_option( 'source_type' ) !== 'google' ) return;

	$interval = ce_get_option( 'sync_interval', 'hourly' );
	$hook     = 'ce_google_sync';

	// Clear existing schedule and reschedule
	$timestamp = wp_next_scheduled( $hook );
	if ( $timestamp ) wp_unschedule_event( $timestamp, $hook );

	wp_schedule_event( time(), $interval, $hook );
}
add_action( 'ce_settings_saved', 'ce_schedule_google_cron' );

/**
 * Clear cron on deactivation.
 */
function ce_clear_google_cron() {
	wp_clear_scheduled_hook( 'ce_google_sync' );
}
register_deactivation_hook( CE_PLUGIN_FILE, 'ce_clear_google_cron' );

/**
 * Hook the importer to the cron event.
 */
add_action( 'ce_google_sync', 'ce_run_google_import' );

/**
 * Also fire when the manual sync AJAX action triggers.
 */
add_action( 'ce_manual_sync', function( $source ) {
	if ( $source === 'google' ) {
		ce_run_google_import();
	}
} );

// ---------------------------------------------------------------------------
// Main importer
// ---------------------------------------------------------------------------

/**
 * Run the full Google Calendar import.
 *
 * @return array { imported: int, updated: int, skipped: int, errors: int, trashed: int }
 */
function ce_run_google_import() {
	$calendar_id = ce_get_option( 'google_cal_id', '' );
	$api_key     = ce_get_option( 'google_api_key', '' );

	if ( empty( $calendar_id ) || empty( $api_key ) ) {
		ce_log( 'Google Calendar import skipped: Calendar ID or API key not configured.' );
		return array( 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'trashed' => 0 );
	}

	// Concurrency guard — bail if another import is already running, so overlapping
	// cron + manual "Sync Now" runs can't each insert the full feed (the cause of the
	// duplicate posts). The transient self-expires as a safety net.
	if ( get_transient( 'ce_google_import_lock' ) ) {
		ce_log( 'Google Calendar import skipped: another import is already running.' );
		return array( 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'trashed' => 0 );
	}
	set_transient( 'ce_google_import_lock', time(), 15 * MINUTE_IN_SECONDS );

	try {
		ce_log( 'Google Calendar import started. Calendar: ' . $calendar_id );

	$events = ce_fetch_google_events( $calendar_id, $api_key );

	if ( is_wp_error( $events ) ) {
		ce_log( 'Google Calendar fetch error: ' . $events->get_error_message() );
		update_option( 'ce_last_sync_status', array(
			'time'    => current_time( 'mysql' ),
			'status'  => 'error',
			'message' => $events->get_error_message(),
		) );
		return array( 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 1, 'trashed' => 0 );
	}

	$counts           = array( 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'trashed' => 0 );
	$feed_identifiers = array();

	foreach ( $events as $event ) {
		if ( ! empty( $event['id'] ) ) {
			$feed_identifiers[] = $event['id'];
		}
		$result = ce_upsert_google_event( $event );
		if ( is_wp_error( $result ) ) {
			$counts['errors']++;
			ce_log( 'Error importing event "' . ( $event['summary'] ?? '' ) . '": ' . $result->get_error_message() );
		} elseif ( $result === 'imported' ) {
			$counts['imported']++;
		} elseif ( $result === 'updated' ) {
			$counts['updated']++;
		} elseif ( $result === 'skipped' ) {
			$counts['skipped']++;
		}
	}

	// Trash any WordPress events from Google Calendar that are no longer in the feed
	$counts['trashed'] = ce_trash_removed_google_events( $feed_identifiers );

	$summary = sprintf(
		'Google Calendar import complete. Imported: %d, Updated: %d, Skipped: %d, Trashed: %d, Errors: %d',
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
		delete_transient( 'ce_google_import_lock' );
	}
}

// ---------------------------------------------------------------------------
// Feed fetching (paginated)
// ---------------------------------------------------------------------------

/**
 * Fetch all events from the Google Calendar API, handling pagination.
 *
 * Fetches events from now onwards (timeMin = current UTC time).
 * Uses pageToken to walk through all result pages.
 *
 * @param string $calendar_id  URL-encoded calendar ID
 * @param string $api_key
 * @return array|WP_Error      Flat array of Google event objects
 */
function ce_fetch_google_events( $calendar_id, $api_key ) {
	$all_events = array();
	$page_token = null;

	// Only fetch events from today onwards, up to 6 months ahead
	$time_min = gmdate( 'Y-m-d\TH:i:s\Z' );
	$time_max = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( '+6 months' ) );

	do {
		$args = array(
			'key'          => $api_key,
			'singleEvents' => 'true',       // Expand recurring events into individual instances
			'orderBy'      => 'startTime',
			'timeMin'      => $time_min,
			'timeMax'      => $time_max,
			'maxResults'   => 250,          // API maximum per page
		);

		if ( $page_token ) {
			$args['pageToken'] = $page_token;
		}

		$encoded_id = rawurlencode( $calendar_id );
		$url        = add_query_arg(
			$args,
			'https://www.googleapis.com/calendar/v3/calendars/' . $encoded_id . '/events'
		);

		$response = wp_remote_get( $url, array(
			'timeout'    => 30,
			'user-agent' => 'WordPress/Church-Events-Plugin',
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			$body  = wp_remote_retrieve_body( $response );
			$error = json_decode( $body, true );
			$msg   = isset( $error['error']['message'] ) ? $error['error']['message'] : 'HTTP ' . $code;
			return new WP_Error( 'google_api_error', $msg );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error( 'json_error', 'Failed to parse Google Calendar response: ' . json_last_error_msg() );
		}

		if ( ! empty( $data['items'] ) && is_array( $data['items'] ) ) {
			// Skip cancelled events (deleted instances of recurring events)
			foreach ( $data['items'] as $item ) {
				if ( isset( $item['status'] ) && $item['status'] === 'cancelled' ) {
					continue;
				}
				$all_events[] = $item;
			}
		}

		$page_token = $data['nextPageToken'] ?? null;

	} while ( $page_token );

	return $all_events;
}

// ---------------------------------------------------------------------------
// Upsert a single event
// ---------------------------------------------------------------------------

/**
 * Create or update a WordPress event post from a Google Calendar event array.
 *
 * @param array $gc  Google Calendar event data
 * @return string|WP_Error  'imported' | 'updated' | 'skipped' | WP_Error
 */
function ce_upsert_google_event( $gc ) {

	// Require minimum fields
	if ( empty( $gc['id'] ) || empty( $gc['summary'] ) ) {
		return new WP_Error( 'invalid_event', 'Google event missing id or summary.' );
	}

	$google_id = sanitize_text_field( $gc['id'] );
	$action    = 'imported';

	// Check if post already exists by Google event ID
	$existing = ce_get_post_by_google_id( $google_id );
	$post_id  = $existing ? $existing->ID : 0;

	// ---------------------------------------------------------------------------
	// Parse dates and times
	// ---------------------------------------------------------------------------

	$start_date = '';
	$start_time = '';
	$end_date   = '';
	$end_time   = '';
	$all_day    = false;

	// Google returns either 'date' (all-day) or 'dateTime' (timed)
	if ( ! empty( $gc['start']['date'] ) ) {
		// All-day event — Google gives YYYY-MM-DD
		$all_day    = true;
		$dt         = date_create( $gc['start']['date'] );
		$start_date = $dt ? $dt->format( 'Ymd' ) : '';
		$start_time = '';
	} elseif ( ! empty( $gc['start']['dateTime'] ) ) {
		$dt         = date_create( $gc['start']['dateTime'] );
		$start_date = $dt ? $dt->format( 'Ymd' ) : '';
		$start_time = $dt ? $dt->format( 'H:i' )  : '';
	}

	if ( ! empty( $gc['end']['date'] ) ) {
		// All-day end: Google uses the day *after* the last day — subtract one day
		$dt       = date_create( $gc['end']['date'] );
		if ( $dt ) {
			$dt->modify( '-1 day' );
			$end_date = $dt->format( 'Ymd' );
		}
		$end_time = '';
	} elseif ( ! empty( $gc['end']['dateTime'] ) ) {
		$dt       = date_create( $gc['end']['dateTime'] );
		$end_date = $dt ? $dt->format( 'Ymd' ) : '';
		$end_time = $dt ? $dt->format( 'H:i' )  : '';
	}

	// ---------------------------------------------------------------------------
	// Parse location
	// ---------------------------------------------------------------------------

	$location_name = '';
	$location_addr = '';

	if ( ! empty( $gc['location'] ) ) {
		// Google Calendar gives a single location string; use it for both name and address
		$location_name = sanitize_text_field( $gc['location'] );
		$location_addr = sanitize_text_field( $gc['location'] );
	}

	// ---------------------------------------------------------------------------
	// Parse description — strip HTML Google sometimes includes
	// ---------------------------------------------------------------------------

	$description = '';
	if ( ! empty( $gc['description'] ) ) {
		$description = wp_kses_post( $gc['description'] );
	}

	// ---------------------------------------------------------------------------
	// Build post array
	// ---------------------------------------------------------------------------

	$post_data = array(
		'post_title'   => sanitize_text_field( $gc['summary'] ),
		'post_content' => $description,
		'post_status'  => 'publish',
		'post_type'    => 'event',
	);

	if ( $post_id ) {
		$post_data['ID'] = $post_id;
		$result = wp_update_post( $post_data, true );
		$action = 'updated';
	} else {
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
		'event_start_date'  => $start_date,
		'event_start_time'  => $start_time,
		'event_end_date'    => $end_date,
		'event_end_time'    => $end_time,
		'event_all_day'     => $all_day ? '1' : '0',
		'event_location'    => $location_name,
		'event_address'     => $location_addr,
		'event_booking_url' => '',
		'event_booking_text'=> '',
		'event_source'      => 'google',
		'event_source_id'   => $google_id,
		'event_google_id'   => $google_id,
	);

	foreach ( $meta as $key => $value ) {
		update_post_meta( $post_id, $key, $value );
	}

	// ---------------------------------------------------------------------------
	// Assign event-month taxonomy term (auto from start date)
	// ---------------------------------------------------------------------------

	if ( $start_date ) {
		ce_auto_assign_event_month( $post_id );
	}

	// ---------------------------------------------------------------------------
	// Handle featured image (Google Calendar attachments, if present)
	// ---------------------------------------------------------------------------

	if ( ! empty( $gc['attachments'] ) && is_array( $gc['attachments'] ) ) {
		foreach ( $gc['attachments'] as $attachment ) {
			$mime = $attachment['mimeType'] ?? '';
			if ( strpos( $mime, 'image/' ) === 0 && ! empty( $attachment['fileUrl'] ) ) {
				ce_set_featured_image( $post_id, $attachment['fileUrl'], $google_id );
				break; // Use first image attachment only
			}
		}
	}

	return $action;
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Find an existing event post by Google Calendar event ID.
 *
 * @param string $google_id
 * @return WP_Post|null
 */
function ce_get_post_by_google_id( $google_id ) {
	$posts = get_posts( array(
		'post_type'      => 'event',
		'post_status'    => 'any',
		'posts_per_page' => 1,
		'meta_query'     => array(
			array(
				'key'   => 'event_google_id',
				'value' => $google_id,
			),
		),
	) );

	return ! empty( $posts ) ? $posts[0] : null;
}

/**
 * Trash WordPress event posts that are no longer in the Google Calendar feed.
 * Only affects posts with event_source = 'google'.
 *
 * @param array $feed_identifiers  List of Google event IDs present in the feed
 * @return int  Number of posts trashed
 */
function ce_trash_removed_google_events( $feed_identifiers ) {
	if ( empty( $feed_identifiers ) ) return 0;

	$existing_posts = get_posts( array(
		'post_type'      => 'event',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'meta_query'     => array(
			array(
				'key'   => 'event_source',
				'value' => 'google',
			),
		),
		'fields' => 'ids',
	) );

	if ( empty( $existing_posts ) ) return 0;

	$trashed = 0;

	foreach ( $existing_posts as $post_id ) {
		$google_id = get_post_meta( $post_id, 'event_google_id', true );

		if ( empty( $google_id ) ) continue;

		if ( ! in_array( $google_id, $feed_identifiers, true ) ) {
			wp_trash_post( $post_id );
			ce_log( 'Trashed event no longer in Google Calendar feed: ' . get_the_title( $post_id ) . ' (' . $google_id . ')' );
			$trashed++;
		}
	}

	return $trashed;
}