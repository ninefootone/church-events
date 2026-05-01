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

	// Also expose featured image URL directly
	register_rest_field( 'event', 'featured_image_url', array(
		'get_callback' => function( $post ) {
			if ( has_post_thumbnail( $post['id'] ) ) {
				$ratio   = ce_get_option( 'image_ratio', '16:9' );
				$size    = ce_image_ratio_to_size( $ratio );
				$img_src = wp_get_attachment_image_src( get_post_thumbnail_id( $post['id'] ), $size );
				return $img_src ? $img_src[0] : null;
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
