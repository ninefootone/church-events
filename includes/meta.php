<?php
/**
 * Registers all event post meta fields.
 * Uses native WordPress register_post_meta() — no ACF dependency.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register all event meta fields.
 * show_in_rest exposes them via the REST API and Elementor dynamic tags.
 */
function ce_register_meta() {

	$string_field = array(
		'object_subtype'    => 'event',
		'type'              => 'string',
		'single'            => true,
		'show_in_rest'      => true,
		'sanitize_callback' => 'sanitize_text_field',
		'auth_callback'     => '__return_true',
	);

	$fields = array(

		// --- Dates & Times ---
		'event_start_date'  => array( 'description' => 'Start date (YYYYMMDD)' ),
		'event_start_time'  => array( 'description' => 'Start time (HH:MM)' ),
		'event_end_date'    => array( 'description' => 'End date (YYYYMMDD)' ),
		'event_end_time'    => array( 'description' => 'End time (HH:MM)' ),
		'event_all_day'     => array(
			'description' => 'All day flag',
			'type'        => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
		),

		// --- Location ---
		'event_location'    => array( 'description' => 'Venue name' ),
		'event_address'     => array( 'description' => 'Full address string' ),

		// --- Booking ---
		'event_booking_url' => array(
			'description'       => 'Booking/ticket URL',
			'sanitize_callback' => 'esc_url_raw',
		),
		'event_booking_text' => array( 'description' => 'Booking link label, e.g. Book Now' ),

		// --- Signup ---
		'event_signup_enabled'       => array( 'description' => 'Signup enabled: 1 or 0' ),

		// --- Map ---
		'event_map_address'          => array( 'description' => 'Full address string for Google Maps embed' ),

		// --- Source IDs (for import de-duplication) ---
		'event_source'      => array( 'description' => 'Import source: churchsuite or google' ),
		'event_source_id'   => array( 'description' => 'Unique ID from the source system' ),

		// --- ChurchSuite specific ---
		'event_churchsuite_id'       => array( 'description' => 'ChurchSuite event ID' ),
		'event_churchsuite_category' => array( 'description' => 'ChurchSuite category name' ),

		// --- Google Calendar specific ---
		'event_google_id'   => array( 'description' => 'Google Calendar event ID' ),
	);

	foreach ( $fields as $key => $overrides ) {
		$args = array_merge( $string_field, $overrides );
		// Allow per-field type override
		if ( ! isset( $overrides['type'] ) ) {
			$args['type'] = 'string';
		}
		register_post_meta( 'event', $key, $args );
	}
}
add_action( 'init', 'ce_register_meta' );

/**
 * Register an ACF local field group for the Event CPT.
 *
 * Uses acf_add_local_field_group() so all plugin meta fields appear in
 * Elementor's ACF Dynamic Tag list (including event_all_day as a true/false
 * field for conditional visibility). No ACF database entries are created;
 * the group exists only in code. ACF Pro is required; the block is skipped
 * gracefully if ACF is not active.
 */
function ce_register_acf_field_group() {
	if ( ! function_exists( 'acf_add_local_field_group' ) ) {
		return;
	}

	acf_add_local_field_group( array(
		'key'      => 'group_church_events',
		'title'    => __( 'Event Details', 'church-events' ),
		'fields'   => array(

			// ---- Dates & Times ----
			array(
				'key'          => 'field_event_start_date',
				'label'        => __( 'Start Date', 'church-events' ),
				'name'         => 'event_start_date',
				'type'         => 'text',
				'instructions' => __( 'YYYYMMDD — managed by importer', 'church-events' ),
			),
			array(
				'key'          => 'field_event_start_time',
				'label'        => __( 'Start Time', 'church-events' ),
				'name'         => 'event_start_time',
				'type'         => 'text',
				'instructions' => __( 'HH:MM — managed by importer', 'church-events' ),
			),
			array(
				'key'          => 'field_event_end_date',
				'label'        => __( 'End Date', 'church-events' ),
				'name'         => 'event_end_date',
				'type'         => 'text',
				'instructions' => __( 'YYYYMMDD — managed by importer', 'church-events' ),
			),
			array(
				'key'          => 'field_event_end_time',
				'label'        => __( 'End Time', 'church-events' ),
				'name'         => 'event_end_time',
				'type'         => 'text',
				'instructions' => __( 'HH:MM — managed by importer', 'church-events' ),
			),
			array(
				'key'          => 'field_event_all_day',
				'label'        => __( 'All Day Event', 'church-events' ),
				'name'         => 'event_all_day',
				'type'         => 'true_false',
				'ui'           => 1,
				'instructions' => __( 'Set by importer when no time is specified.', 'church-events' ),
			),

			// ---- Location ----
			array(
				'key'          => 'field_event_location',
				'label'        => __( 'Location', 'church-events' ),
				'name'         => 'event_location',
				'type'         => 'text',
				'instructions' => __( 'Venue name — managed by importer', 'church-events' ),
			),
			array(
				'key'          => 'field_event_address',
				'label'        => __( 'Address', 'church-events' ),
				'name'         => 'event_address',
				'type'         => 'text',
				'instructions' => __( 'Full address — managed by importer', 'church-events' ),
			),
			array(
				'key'          => 'field_event_map_address',
				'label'        => __( 'Map Address', 'church-events' ),
				'name'         => 'event_map_address',
				'type'         => 'text',
				'instructions' => __( 'Google Maps address — managed by importer', 'church-events' ),
			),

			// ---- Booking ----
			array(
				'key'          => 'field_event_booking_url',
				'label'        => __( 'Booking URL', 'church-events' ),
				'name'         => 'event_booking_url',
				'type'         => 'url',
				'instructions' => __( 'Managed by importer', 'church-events' ),
			),
			array(
				'key'          => 'field_event_booking_text',
				'label'        => __( 'Booking Link Text', 'church-events' ),
				'name'         => 'event_booking_text',
				'type'         => 'text',
				'instructions' => __( 'Managed by importer', 'church-events' ),
			),

			// ---- Signup ----
			array(
				'key'          => 'field_event_signup_enabled',
				'label'        => __( 'Signup Enabled', 'church-events' ),
				'name'         => 'event_signup_enabled',
				'type'         => 'true_false',
				'ui'           => 1,
				'instructions' => __( 'Whether ChurchSuite signup is active for this event.', 'church-events' ),
			),

			// ---- Source info ----
			array(
				'key'          => 'field_event_churchsuite_id',
				'label'        => __( 'ChurchSuite ID', 'church-events' ),
				'name'         => 'event_churchsuite_id',
				'type'         => 'text',
				'instructions' => __( 'Managed by importer', 'church-events' ),
			),
			array(
				'key'          => 'field_event_churchsuite_category',
				'label'        => __( 'ChurchSuite Category', 'church-events' ),
				'name'         => 'event_churchsuite_category',
				'type'         => 'text',
				'instructions' => __( 'Managed by importer', 'church-events' ),
			),
		),
		'location' => array(
			array(
				array(
					'param'    => 'post_type',
					'operator' => '==',
					'value'    => 'event',
				),
			),
		),
		'active'   => true,
	) );
}
// after_setup_theme fires reliably after all plugins are loaded and is the
// recommended hook for acf_add_local_field_group() calls from plugins.
add_action( 'after_setup_theme', 'ce_register_acf_field_group' );

/**
 * Add a meta box to the event edit screen for manual entry/review.
 * This gives a basic admin UI without requiring ACF.
 */
function ce_add_meta_box() {
	add_meta_box(
		'ce_event_details',
		__( 'Event Details', 'church-events' ),
		'ce_render_meta_box',
		'event',
		'normal',
		'high'
	);
}
add_action( 'add_meta_boxes', 'ce_add_meta_box' );

/**
 * Render the event details meta box.
 *
 * @param WP_Post $post
 */
function ce_render_meta_box( $post ) {
	wp_nonce_field( 'ce_save_meta', 'ce_meta_nonce' );

	$fields = array(
		'event_start_date'   => __( 'Start Date (YYYYMMDD)', 'church-events' ),
		'event_start_time'   => __( 'Start Time (HH:MM)', 'church-events' ),
		'event_end_date'     => __( 'End Date (YYYYMMDD)', 'church-events' ),
		'event_end_time'     => __( 'End Time (HH:MM)', 'church-events' ),
		'event_location'     => __( 'Venue Name', 'church-events' ),
		'event_address'      => __( 'Address', 'church-events' ),
		'event_booking_url'  => __( 'Booking URL', 'church-events' ),
		'event_booking_text' => __( 'Booking Link Text', 'church-events' ),
	);

	echo '<table class="form-table"><tbody>';

	foreach ( $fields as $key => $label ) {
		$value = get_post_meta( $post->ID, $key, true );
		echo '<tr>';
		echo '<th><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th>';
		echo '<td><input type="text" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" class="regular-text" /></td>';
		echo '</tr>';
	}

	// All day checkbox
	$all_day = get_post_meta( $post->ID, 'event_all_day', true );
	echo '<tr>';
	echo '<th><label for="event_all_day">' . esc_html__( 'All Day Event', 'church-events' ) . '</label></th>';
	echo '<td><input type="checkbox" id="event_all_day" name="event_all_day" value="1" ' . checked( $all_day, true, false ) . ' /></td>';
	echo '</tr>';

	echo '</tbody></table>';
}

/**
 * Save meta box values.
 *
 * @param int $post_id
 */
function ce_save_meta_box( $post_id ) {

	if ( ! isset( $_POST['ce_meta_nonce'] ) ) return;
	if ( ! wp_verify_nonce( $_POST['ce_meta_nonce'], 'ce_save_meta' ) ) return;
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
	if ( ! current_user_can( 'edit_post', $post_id ) ) return;

	$text_fields = array(
		'event_start_date',
		'event_start_time',
		'event_end_date',
		'event_end_time',
		'event_location',
		'event_address',
		'event_booking_url',
		'event_booking_text',
	);

	foreach ( $text_fields as $field ) {
		if ( isset( $_POST[ $field ] ) ) {
			update_post_meta( $post_id, $field, sanitize_text_field( $_POST[ $field ] ) );
		}
	}

	// All day — checkbox, save as boolean string
	$all_day = isset( $_POST['event_all_day'] ) ? true : false;
	update_post_meta( $post_id, 'event_all_day', $all_day );
}
add_action( 'save_post', 'ce_save_meta_box' );
