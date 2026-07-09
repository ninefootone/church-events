<?php
/**
 * Elementor Loop Grid — Featured Events query.
 *
 * Provides a custom query for an Elementor Loop Grid (or Posts widget) that
 * shows only upcoming featured events, ordered by event start date ascending.
 *
 * Usage: in the Elementor widget's Query settings, set the Query ID to
 * 'featured_events'. No other query configuration is needed — this hook
 * overrides ordering and filtering.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filter an Elementor query to upcoming featured events, sorted by event date.
 *
 * @param WP_Query $query
 */
function ce_elementor_featured_events_query( $query ) {

	// Restrict to the event post type (in case the widget was left broad).
	$query->set( 'post_type', 'event' );

	// posts_per_page must be set explicitly — if omitted, the value leaks
	// from the main query and the grid shows the wrong count.
	$existing = (int) $query->get( 'posts_per_page' );
	if ( $existing === 0 ) {
		$query->set( 'posts_per_page', 6 );
	}

	// Only events tagged featured.
	$query->set( 'tax_query', array(
		array(
			'taxonomy' => 'event-featured',
			'field'    => 'slug',
			'terms'    => 'featured',
		),
	) );

	// Upcoming only: start date >= today. Named clauses so the orderby
	// can target the correct one.
	$today = date( 'Ymd' );

	$query->set( 'meta_query', array(
		'relation' => 'AND',
		'ce_start' => array(
			'key'     => 'event_start_date',
			'value'   => $today,
			'compare' => '>=',
			'type'    => 'NUMERIC',
		),
	) );

	// Order by the event start date, not the WordPress publish date.
	$query->set( 'orderby', array( 'ce_start' => 'ASC' ) );

	// Tiebreaker for events sharing a date, so pagination stays stable.
	$query->set( 'order', 'ASC' );
}
add_action( 'elementor/query/featured_events', 'ce_elementor_featured_events_query' );

/**
 * Elementor Loop Grid — Site Events query.
 *
 * Orders an Elementor query by event start date (ascending) and limits it
 * to upcoming events. Does NOT set the site itself — the site term is chosen
 * in the widget's own Query > Include > Terms control, and this hook preserves
 * it. Set the widget's Query ID to 'site_events'.
 *
 * @param WP_Query $query
 */
function ce_elementor_site_events_query( $query ) {

	$query->set( 'post_type', 'event' );

	// posts_per_page must be explicit or it leaks from the main query.
	if ( (int) $query->get( 'posts_per_page' ) === 0 ) {
		$query->set( 'posts_per_page', 6 );
	}

	// Add an upcoming-only date clause WITHOUT discarding any meta_query
	// the widget may already have set.
	$today    = date( 'Ymd' );
	$existing = $query->get( 'meta_query' );
	if ( ! is_array( $existing ) ) {
		$existing = array();
	}

	$existing['ce_start'] = array(
		'key'     => 'event_start_date',
		'value'   => $today,
		'compare' => '>=',
		'type'    => 'NUMERIC',
	);

	$query->set( 'meta_query', $existing );

	// Order by event start date, not publish date.
	$query->set( 'orderby', array( 'ce_start' => 'ASC' ) );
	$query->set( 'order', 'ASC' );

	// Deliberately no tax_query here — the site term comes from the widget.
}
add_action( 'elementor/query/site_events', 'ce_elementor_site_events_query' );