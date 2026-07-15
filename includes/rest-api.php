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
		'event_site'     => $request->get_param( 'event_site' ),
		'event_search'   => $request->get_param( 'event_search' ),
		'event_featured' => $request->get_param( 'event_featured' ),
	);

	$cal_after  = $request->get_param( 'cal_after' );
	$cal_before = $request->get_param( 'cal_before' );
	$category   = $request->get_param( 'event_category' );
	$site       = $request->get_param( 'event_site' );
	$search     = $request->get_param( 'event_search' );
	$featured   = $request->get_param( 'event_featured' );

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

	// Site filtering via taxonomy
	if ( $site ) {
		$args['tax_query'] = isset( $args['tax_query'] ) ? $args['tax_query'] : array();
		$args['tax_query'][] = array(
			'taxonomy' => 'event-site',
			'field'    => 'slug',
			'terms'    => sanitize_text_field( $site ),
		);
	}

	// Featured filtering via taxonomy
	if ( $featured ) {
		$args['tax_query'] = isset( $args['tax_query'] ) ? $args['tax_query'] : array();
		$args['tax_query'][] = array(
			'taxonomy' => 'event-featured',
			'field'    => 'slug',
			'terms'    => 'featured',
		);
	}

	// Text search
	if ( $search ) {
		$args['s'] = sanitize_text_field( $search );
	}

	// Order by start date, then start time, both ascending — so events on the same day
	// appear in chronological order. The date-only sort left same-day events tied and
	// MySQL returned them in an undefined order. Named meta_query clauses let us sort on
	// two meta keys; they don't filter out anything (EXISTS matches every imported event,
	// which always sets both keys — all-day events store an empty time, which sorts first).
	$args['meta_query'] = isset( $args['meta_query'] ) ? $args['meta_query'] : array();
	$args['meta_query']['ce_order_date'] = array(
		'key'     => 'event_start_date',
		'compare' => 'EXISTS',
		'type'    => 'NUMERIC',
	);
	$args['meta_query']['ce_order_time'] = array(
		'key'     => 'event_start_time',
		'compare' => 'EXISTS',
		'type'    => 'CHAR',
	);
	$args['orderby'] = array(
		'ce_order_date' => 'ASC',
		'ce_order_time' => 'ASC',
		'ID'            => 'ASC',
	);

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
					'id'    => $term->term_id,
					'name'  => $term->name,
					'slug'  => $term->slug,
					'color' => get_term_meta( $term->term_id, 'ce_category_color', true ) ?: '',
				);
			}, $terms );
		},
		'schema' => null,
	) );

	register_rest_field( 'event', 'event_featured', array(
		'get_callback' => function( $post ) {
			return has_term( 'featured', 'event-featured', $post['id'] );
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
 * Cache event REST responses using transients.
 * Hooks into rest_pre_echo_response which fires reliably after routing.
 */
function ce_maybe_serve_cached_response() {
	// Only handle event REST requests
	if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) return;

	$route = $GLOBALS['wp']->query_vars['rest_route'] ?? '';
	if ( strpos( $route, '/wp/v2/events' ) === false ) return;
	if ( $_SERVER['REQUEST_METHOD'] !== 'GET' ) return;

	// Build cache key from query string
	$params = $_GET;
	unset( $params['_wpnonce'] );
	ksort( $params );
	$key    = 'ce_rest_' . md5( http_build_query( $params ) );

	// Store key for later cache clearing
	$keys   = get_option( 'ce_rest_cache_keys', array() );
	if ( ! in_array( $key, $keys ) ) {
		$keys[] = $key;
		$keys   = array_slice( $keys, -200 );
		update_option( 'ce_rest_cache_keys', $keys, false );
	}

	// Serve from cache if available
	$cached = get_transient( $key );
	if ( $cached !== false ) {
		header( 'X-CE-Cache: HIT' );
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'X-WP-Total: ' . $cached['total'] );
		header( 'X-WP-TotalPages: ' . $cached['total_pages'] );
		echo wp_json_encode( $cached['data'] );
		exit;
	}

	// Not cached — store after response is sent
	add_filter( 'rest_post_dispatch', function( $response ) use ( $key ) {
		if ( is_wp_error( $response ) ) return $response;
		set_transient( $key, array(
			'data'        => $response->get_data(),
			'total'       => $response->get_headers()['X-WP-Total'] ?? 0,
			'total_pages' => $response->get_headers()['X-WP-TotalPages'] ?? 0,
		), 12 * HOUR_IN_SECONDS );
		return $response;
	}, 10, 1 );
}
// add_action( 'rest_api_init', 'ce_maybe_serve_cached_response', 5 );

/**
 * Clear all event REST cache transients.
 */
function ce_clear_rest_cache() {
	$keys = get_option( 'ce_rest_cache_keys', array() );
	foreach ( $keys as $key ) {
		delete_transient( $key );
	}
	delete_option( 'ce_rest_cache_keys' );
}
add_action( 'ce_churchsuite_sync', 'ce_clear_rest_cache', 5 );
add_action( 'ce_manual_sync',      'ce_clear_rest_cache', 5 );
add_action( 'ce_settings_saved',   'ce_clear_rest_cache' );