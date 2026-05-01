<?php
/**
 * Registers the Event custom post type and taxonomies.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the Event CPT.
 * Called directly on activation and via init hook.
 */
function ce_register_cpt() {
	$labels = array(
		'name'               => __( 'Events', 'church-events' ),
		'singular_name'      => __( 'Event', 'church-events' ),
		'menu_name'          => __( 'Events', 'church-events' ),
		'add_new'            => __( 'Add New', 'church-events' ),
		'add_new_item'       => __( 'Add New Event', 'church-events' ),
		'edit_item'          => __( 'Edit Event', 'church-events' ),
		'new_item'           => __( 'New Event', 'church-events' ),
		'view_item'          => __( 'View Event', 'church-events' ),
		'search_items'       => __( 'Search Events', 'church-events' ),
		'not_found'          => __( 'No events found', 'church-events' ),
		'not_found_in_trash' => __( 'No events found in trash', 'church-events' ),
	);

	$args = array(
		'labels'             => $labels,
		'public'             => true,
		'publicly_queryable' => true,
		'show_ui'            => true,
		'show_in_menu'       => true,
		'query_var'          => true,
		'rewrite'            => array( 'slug' => 'events' ),
		'capability_type'    => 'post',
		'has_archive'        => true,
		'hierarchical'       => false,
		'menu_position'      => 5,
		'menu_icon'          => 'dashicons-calendar-alt',
		'supports'           => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
		'show_in_rest'       => true, // Required for REST API and block editor
		'rest_base'          => 'events',
	);

	register_post_type( 'event', $args );
}
add_action( 'init', 'ce_register_cpt' );

/**
 * Register the Event Category taxonomy.
 */
function ce_register_taxonomies() {

	// Event Category
	$category_labels = array(
		'name'              => __( 'Event Categories', 'church-events' ),
		'singular_name'     => __( 'Event Category', 'church-events' ),
		'search_items'      => __( 'Search Categories', 'church-events' ),
		'all_items'         => __( 'All Categories', 'church-events' ),
		'edit_item'         => __( 'Edit Category', 'church-events' ),
		'update_item'       => __( 'Update Category', 'church-events' ),
		'add_new_item'      => __( 'Add New Category', 'church-events' ),
		'new_item_name'     => __( 'New Category Name', 'church-events' ),
		'menu_name'         => __( 'Categories', 'church-events' ),
	);

	register_taxonomy( 'event-category', 'event', array(
		'hierarchical'      => true,
		'labels'            => $category_labels,
		'show_ui'           => true,
		'show_in_rest'      => true,
		'show_admin_column' => true,
		'query_var'         => true,
		'rewrite'           => array( 'slug' => 'event-category' ),
	) );

	// Event Month — used for filtering by month
	$month_labels = array(
		'name'          => __( 'Event Months', 'church-events' ),
		'singular_name' => __( 'Event Month', 'church-events' ),
		'menu_name'     => __( 'Months', 'church-events' ),
	);

	register_taxonomy( 'event-month', 'event', array(
		'hierarchical'      => false,
		'labels'            => $month_labels,
		'show_ui'           => true,
		'show_in_rest'      => true,
		'show_admin_column' => true,
		'query_var'         => true,
		'rewrite'           => array( 'slug' => 'event-month' ),
	) );
}
add_action( 'init', 'ce_register_taxonomies' );

/**
 * Auto-assign event-month taxonomy term when an event is saved.
 * Derives the month from the event_start_date meta field (format: YYYYMMDD).
 *
 * @param int $post_id
 */
function ce_auto_assign_event_month( $post_id ) {

	// Bail on autosave, revisions, and wrong post type
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
	if ( wp_is_post_revision( $post_id ) ) return;
	if ( get_post_type( $post_id ) !== 'event' ) return;

	$start_date = get_post_meta( $post_id, 'event_start_date', true );
	if ( empty( $start_date ) || strlen( $start_date ) < 6 ) return;

	// Parse YYYYMMDD into a month term slug (e.g. "2025-03")
	$year  = substr( $start_date, 0, 4 );
	$month = substr( $start_date, 4, 2 );

	if ( ! $year || ! $month ) return;

	$term_name = date_i18n( 'F Y', mktime( 0, 0, 0, (int) $month, 1, (int) $year ) );
	$term_slug = $year . '-' . $month;

	// Create term if it doesn't exist
	if ( ! term_exists( $term_slug, 'event-month' ) ) {
		wp_insert_term( $term_name, 'event-month', array( 'slug' => $term_slug ) );
	}

	wp_set_object_terms( $post_id, $term_slug, 'event-month', false );
}
add_action( 'save_post', 'ce_auto_assign_event_month' );
