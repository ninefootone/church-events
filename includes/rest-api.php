<?php
/**
 * REST API customisation for the Event CPT.
 *
 * Adds cal_after / cal_before query parameters so the calendar JS
 * can filter events by ACF-style date meta fields (YYYYMMDD format)
 * rather than WordPress post dates.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register custom REST query parameters for the event CPT.
 *
 * @param array  $args    WP_Query args
 * @param object $request REST request
 * @return array
 */
function ce_rest_event_query( $args, $request ) {
	// Store request params on args so we can build a cache key later
	$args['_ce_cache_key_params'] = array(
		'cal_after'      => $request->get_param( 'cal_after' ),
		'cal_before'     => $request->get_param( 'cal_before' ),
		'event_category' => $request->get_param( 'event_category' ),
		'event_search'   => $request->get_param( 'event_search' ),
	);

	$cal_after  = $request->get_param( 'cal_after' );
	$cal_before = $request->get_param( 'cal_before' );
	$category   = $request->get_param( 'event_category' );
	$search     = $request->get_param( 'event_search' );

	// Date range filtering via meta BETWEEN query
	if ( $cal_after && $cal_before ) {

		// Sanitise — expect YYYYMMDD
		$after  = preg_replace( '/[^0-9]/', '', $cal_after );
		$before = preg_replace( '/[^0-9]/', '', $cal_before );

		$args['meta_query'] = isset( $args['meta_query'] ) ? $args['meta_query'] : array();
		$args['meta_query'][] = array(
			'key'     => 'event_start_date',
			'value'   => array( $after, $before ),
			'compare' => 'BETWEEN',
			'type'    => 'NUMERIC',
		);
	}

	// Category filtering via taxonomy
	if ( $category ) {
		$args['tax_query'] = isset( $args['tax_query'] ) ? $args['tax_query'] : array();
		$args['tax_query'][] = array(
			'taxonomy' => 'event-category',
			'field'    => 'slug',
			'terms'    => sanitize_text_field( $category ),
		);
	}

	// Text search
	if ( $search ) {
		$args['s'] = sanitize_text_field( $search );
	}

	// Always order by start date ascending
	$args['meta_key'] = 'event_start_date';
	$args['orderby']  = 'meta_value_num';
	$args['order']    = 'ASC';

	return $args;
}
add_filter( 'rest_event_query', 'ce_rest_event_query', 10, 2 );

/**
 * Expose event meta fields in the REST API response.
 * Adds a top-level 'event_meta' object to each event in the response
 * containing all the fields the calendar and list JS need.
 */
function ce_rest_expose_meta() {

	register_rest_field( 'event', 'event_meta', array(
		'get_callback' => function( $post ) {
			return array(
				'start_date'   => get_post_meta( $post['id'], 'event_start_date', true ),
				'start_time'   => get_post_meta( $post['id'], 'event_start_time', true ),
				'end_date'     => get_post_meta( $post['id'], 'event_end_date', true ),
				'end_time'     => get_post_meta( $post['id'], 'event_end_time', true ),
				'all_day'      => (bool) get_post_meta( $post['id'], 'event_all_day', true ),
				'location'     => get_post_meta( $post['id'], 'event_location', true ),
				'address'      => get_post_meta( $post['id'], 'event_address', true ),
				'booking_url'  => get_post_meta( $post['id'], 'event_booking_url', true ),
				'booking_text' => get_post_meta( $post['id'], 'event_booking_text', true ),
			);
		},
		'schema' => null,
	) );

	// Also expose featured image URL directly (with fallback)
	register_rest_field( 'event', 'featured_image_url', array(
		'get_callback' => function( $post ) {
			$ratio = ce_get_option( 'image_ratio', '16:9' );
			$size  = ce_image_ratio_to_size( $ratio );

			if ( has_post_thumbnail( $post['id'] ) ) {
				$img_src = wp_get_attachment_image_src( get_post_thumbnail_id( $post['id'] ), $size );
				if ( $img_src ) return $img_src[0];
			}

			// Fallback image from settings
			$fallback_id = (int) ce_get_option( 'fallback_image_id', 0 );
			if ( $fallback_id ) {
				$img_src = wp_get_attachment_image_src( $fallback_id, $size );
				if ( $img_src ) return $img_src[0];
			}

			return null;
		},
		'schema' => null,
	) );

	// Expose permalink
	register_rest_field( 'event', 'event_url', array(
		'get_callback' => function( $post ) {
			return get_permalink( $post['id'] );
		},
		'schema' => null,
	) );

	// Expose event categories
	register_rest_field( 'event', 'event_categories', array(
		'get_callback' => function( $post ) {
			$terms = wp_get_post_terms( $post['id'], 'event-category', array( 'fields' => 'all' ) );
			if ( is_wp_error( $terms ) ) return array();
			return array_map( function( $term ) {
				return array(
					'id'   => $term->term_id,
					'name' => $term->name,
					'slug' => $term->slug,
				);
			}, $terms );
		},
		'schema' => null,
	) );
}
add_action( 'rest_api_init', 'ce_rest_expose_meta' );

/**
 * Map image ratio setting to a WordPress image size string.
 *
 * @param string $ratio e.g. '16:9'
 * @return string WordPress image size
 */
function ce_image_ratio_to_size( $ratio ) {
	$map = array(
		'1:1'  => 'thumbnail',
		'4:3'  => 'medium',
		'16:9' => 'large',
		'4:5'  => 'medium',
	);
	return isset( $map[ $ratio ] ) ? $map[ $ratio ] : 'large';
}

/**
 * Cache event REST responses as transients.
 * Hooked to rest_post_dispatch to cache after the response is built.
 */
function ce_cache_rest_response( $response, $server, $request ) {
	if ( ! $request instanceof WP_REST_Request ) return $response;
	if ( strpos( $request->get_route(), '/wp/v2/events' ) === false ) return $response;
	if ( $request->get_method() !== 'GET' ) return $response;

	$key = ce_rest_cache_key( $request );
	if ( ! $key ) return $response;

	// Only cache if not already cached
	if ( false === get_transient( $key ) ) {
		set_transient( $key, array(
			'data'    => $response->get_data(),
			'headers' => $response->get_headers(),
			'status'  => $response->get_status(),
		), 12 * HOUR_IN_SECONDS );

		// Track cache keys so we can clear them all on sync
		$keys   = get_option( 'ce_rest_cache_keys', array() );
		$keys[] = $key;
		$keys   = array_unique( array_slice( $keys, -200 ) ); // keep last 200
		update_option( 'ce_rest_cache_keys', $keys, false );
	}

	return $response;
}
add_filter( 'rest_post_dispatch', 'ce_cache_rest_response', 10, 3 );

/**
 * Serve cached REST responses before hitting the database.
 */
function ce_serve_cached_rest_response( $response, $handler, $request ) {
	if ( ! $request instanceof WP_REST_Request ) return $response;
	if ( strpos( $request->get_route(), '/wp/v2/events' ) === false ) return $response;
	if ( $request->get_method() !== 'GET' ) return $response;

	$key    = ce_rest_cache_key( $request );
	$cached = $key ? get_transient( $key ) : false;

	if ( $cached ) {
		$res = new WP_REST_Response( $cached['data'], $cached['status'] );
		foreach ( $cached['headers'] as $header => $value ) {
			$res->header( $header, $value );
		}
		$res->header( 'X-CE-Cache', 'HIT' );
		return $res;
	}

	return $response;
}
add_filter( 'rest_pre_dispatch', 'ce_serve_cached_rest_response', 10, 3 );

/**
 * Build a cache key from request parameters.
 *
 * @param WP_REST_Request $request
 * @return string|false
 */
function ce_rest_cache_key( $request ) {
	$after    = $request->get_param( 'cal_after' );
	$before   = $request->get_param( 'cal_before' );
	if ( ! $after || ! $before ) return false;

	$parts = array(
		'ce_events',
		$after,
		$before,
		$request->get_param( 'event_category' ) ?: 'all',
		$request->get_param( 'event_search' )   ?: 'none',
		$request->get_param( 'page' )            ?: '1',
		$request->get_param( 'per_page' )        ?: '100',
	);

	return 'ce_rest_' . md5( implode( '_', $parts ) );
}

/**
 * Clear all event REST cache transients.
 * Called after every sync and when settings change.
 */
function ce_clear_rest_cache() {
	$keys = get_option( 'ce_rest_cache_keys', array() );
	foreach ( $keys as $key ) {
		delete_transient( $key );
	}
	delete_option( 'ce_rest_cache_keys' );
}
add_action( 'ce_churchsuite_sync', 'ce_clear_rest_cache', 5 ); // Before sync runs
add_action( 'ce_manual_sync',      'ce_clear_rest_cache', 5 );
add_action( 'ce_settings_saved',   'ce_clear_rest_cache' );