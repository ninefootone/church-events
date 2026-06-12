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

	// Allow the rewrite slug to be configured per-site to avoid collisions
	// with existing /events/ pages. Defaults to 'events' for back-compat.
	$slug = sanitize_title( ce_get_option( 'cpt_slug', 'events' ) );
	if ( empty( $slug ) ) {
		$slug = 'events';
	}

	$args = array(
		'labels'             => $labels,
		'public'             => true,
		'publicly_queryable' => true,
		'show_ui'            => true,
		'show_in_menu'       => true,
		'query_var'          => true,
		'rewrite'            => array( 'slug' => $slug, 'with_front' => false ),
		'capability_type'    => 'post',
		'has_archive'        => true,
		'hierarchical'       => false,
		'menu_position'      => 5,
		'menu_icon'          => 'dashicons-calendar-alt',
		'supports'           => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
		'show_in_rest'       => true, // Required for REST API and block editor
		'rest_base'          => $slug,
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

	// Event Site — used for filtering by church site/campus
	$site_labels = array(
		'name'          => __( 'Event Sites', 'church-events' ),
		'singular_name' => __( 'Event Site', 'church-events' ),
		'menu_name'     => __( 'Sites', 'church-events' ),
	);

	register_taxonomy( 'event-site', 'event', array(
		'hierarchical'      => false,
		'labels'            => $site_labels,
		'show_ui'           => true,
		'show_in_rest'      => true,
		'show_admin_column' => true,
		'query_var'         => true,
		'rewrite'           => array( 'slug' => 'event-site' ),
	) );

	// Event Featured — binary flag from ChurchSuite feed
	$featured_labels = array(
		'name'          => __( 'Featured', 'church-events' ),
		'singular_name' => __( 'Featured', 'church-events' ),
		'menu_name'     => __( 'Featured', 'church-events' ),
	);

	register_taxonomy( 'event-featured', 'event', array(
		'hierarchical'      => false,
		'labels'            => $featured_labels,
		'public'            => true,
		'show_ui'           => false,
		'show_in_rest'      => true,
		'show_admin_column' => false,
		'show_in_nav_menus' => false,
		'query_var'         => true,
		'rewrite'           => array( 'slug' => 'event-featured' ),
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

// ---------------------------------------------------------------------------
// Category colour (term meta)
// ---------------------------------------------------------------------------

/**
 * Colour field on the Add Category screen.
 */
function ce_category_color_add_field() {
	?>
	<div class="form-field term-color-wrap">
		<label for="ce-category-color"><?php esc_html_e( 'Colour', 'church-events' ); ?></label>
		<input type="text" id="ce-category-color" name="ce_category_color" value="" placeholder="#12b886" />
		<p><?php esc_html_e( 'Hex colour for this category. Leave empty to use the default.', 'church-events' ); ?></p>
	</div>
	<?php
}
add_action( 'event-category_add_form_fields', 'ce_category_color_add_field' );

/**
 * Colour field on the Edit Category screen.
 */
function ce_category_color_edit_field( $term ) {
	$color = get_term_meta( $term->term_id, 'ce_category_color', true );
	?>
	<tr class="form-field term-color-wrap">
		<th scope="row"><label for="ce-category-color"><?php esc_html_e( 'Colour', 'church-events' ); ?></label></th>
		<td>
			<input type="text" id="ce-category-color" name="ce_category_color" value="<?php echo esc_attr( $color ); ?>" placeholder="#12b886" />
			<?php if ( $color ) : ?>
				<span style="display:inline-block;width:20px;height:20px;border-radius:3px;vertical-align:middle;margin-left:8px;background:<?php echo esc_attr( $color ); ?>;"></span>
			<?php endif; ?>
			<p class="description"><?php esc_html_e( 'Hex colour for this category. Leave empty to use the default.', 'church-events' ); ?></p>
		</td>
	</tr>
	<?php
}
add_action( 'event-category_edit_form_fields', 'ce_category_color_edit_field' );

/**
 * Save the colour on category create/edit.
 */
function ce_category_color_save( $term_id ) {
	if ( ! isset( $_POST['ce_category_color'] ) ) return;

	$color = sanitize_hex_color( trim( wp_unslash( $_POST['ce_category_color'] ) ) );

	if ( $color ) {
		update_term_meta( $term_id, 'ce_category_color', $color );
	} else {
		delete_term_meta( $term_id, 'ce_category_color' );
	}
}
add_action( 'created_event-category', 'ce_category_color_save' );
add_action( 'edited_event-category', 'ce_category_color_save' );