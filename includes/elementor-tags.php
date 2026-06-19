<?php
/**
 * Registers native Elementor Dynamic Tags for the Church Events plugin.
 *
 * All tags appear under the "Church Events" group in the Elementor
 * dynamic tags panel. No ACF dependency.
 *
 * Loaded conditionally from class-church-events.php only when Elementor
 * is active, via the elementor/dynamic_tags/register hook.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the Church Events dynamic tag group and all tag classes.
 *
 * @param \Elementor\Core\DynamicTags\Manager $dynamic_tags
 */
function ce_register_elementor_tags( $dynamic_tags ) {

	// Register the group that all our tags belong to.
	$dynamic_tags->register_group(
		'church-events',
		array( 'title' => __( 'Church Events', 'church-events' ) )
	);

	// Register each tag class.
	$tags = array(
		'CE_Tag_Start_Date',
		'CE_Tag_End_Date',
		'CE_Tag_Date_Range',
		'CE_Tag_Start_Time',
		'CE_Tag_End_Time',
		'CE_Tag_Location',
		'CE_Tag_Address',
		'CE_Tag_Map_Address',
		'CE_Tag_Booking_URL',
		'CE_Tag_Booking_Text',
		'CE_Tag_Signup_Enabled',
		'CE_Tag_All_Day',
		'CE_Tag_ChurchSuite_ID',
		'CE_Tag_ChurchSuite_Category',
	);

	foreach ( $tags as $tag ) {
		$dynamic_tags->register( new $tag() );
	}
}
add_action( 'elementor/dynamic_tags/register', 'ce_register_elementor_tags' );


// ---------------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------------

/**
 * Format a raw YYYYMMDD string using the site's date format setting.
 *
 * @param string $raw      YYYYMMDD string.
 * @param string $format   Optional override; defaults to WP date_format option.
 * @return string          Formatted date string, or empty string on failure.
 */
function ce_format_date( $raw, $format = '' ) {
	if ( ! $raw || strlen( $raw ) < 8 ) {
		return '';
	}
	if ( ! $format ) {
		$format = get_option( 'date_format', 'j F Y' );
	}
	$ts = mktime(
		0, 0, 0,
		(int) substr( $raw, 4, 2 ),
		(int) substr( $raw, 6, 2 ),
		(int) substr( $raw, 0, 4 )
	);
	return date_i18n( $format, $ts );
}


// ---------------------------------------------------------------------------
// Base class
// ---------------------------------------------------------------------------

/**
 * Abstract base for all Church Events text dynamic tags.
 */
abstract class CE_Dynamic_Tag_Base extends \Elementor\Core\DynamicTags\Tag {

	public function get_group() {
		return 'church-events';
	}

	public function get_categories() {
		return array( \Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY );
	}
}


// ---------------------------------------------------------------------------
// Date & Time tags
// ---------------------------------------------------------------------------

class CE_Tag_Start_Date extends CE_Dynamic_Tag_Base {

	public function get_name() {
		return 'ce-start-date';
	}

	public function get_title() {
		return __( 'Event Start Date', 'church-events' );
	}

	public function render() {
		$raw = get_post_meta( get_the_ID(), 'event_start_date', true );
		echo esc_html( ce_format_date( $raw ) );
	}
}

class CE_Tag_End_Date extends CE_Dynamic_Tag_Base {

	public function get_name() {
		return 'ce-end-date';
	}

	public function get_title() {
		return __( 'Event End Date', 'church-events' );
	}

	public function render() {
		$raw = get_post_meta( get_the_ID(), 'event_end_date', true );
		echo esc_html( ce_format_date( $raw ) );
	}
}

class CE_Tag_Date_Range extends CE_Dynamic_Tag_Base {

	public function get_name() {
		return 'ce-date-range';
	}

	public function get_title() {
		return __( 'Event Date Range', 'church-events' );
	}

	public function render() {
		$post_id   = get_the_ID();
		$start_raw = get_post_meta( $post_id, 'event_start_date', true );
		$end_raw   = get_post_meta( $post_id, 'event_end_date',   true );

		$start_str = ce_format_date( $start_raw );
		if ( ! $start_str ) {
			return;
		}

		if ( $end_raw && $end_raw !== $start_raw ) {
			$end_str = ce_format_date( $end_raw );
			echo esc_html( $start_str . ' – ' . $end_str );
		} else {
			echo esc_html( $start_str );
		}
	}
}

class CE_Tag_Start_Time extends CE_Dynamic_Tag_Base {

	public function get_name() {
		return 'ce-start-time';
	}

	public function get_title() {
		return __( 'Event Start Time', 'church-events' );
	}

	public function render() {
		echo esc_html( get_post_meta( get_the_ID(), 'event_start_time', true ) );
	}
}

class CE_Tag_End_Time extends CE_Dynamic_Tag_Base {

	public function get_name() {
		return 'ce-end-time';
	}

	public function get_title() {
		return __( 'Event End Time', 'church-events' );
	}

	public function render() {
		echo esc_html( get_post_meta( get_the_ID(), 'event_end_time', true ) );
	}
}


// ---------------------------------------------------------------------------
// Location tags
// ---------------------------------------------------------------------------

class CE_Tag_Location extends CE_Dynamic_Tag_Base {

	public function get_name() {
		return 'ce-location';
	}

	public function get_title() {
		return __( 'Event Location', 'church-events' );
	}

	public function render() {
		echo esc_html( get_post_meta( get_the_ID(), 'event_location', true ) );
	}
}

class CE_Tag_Address extends CE_Dynamic_Tag_Base {

	public function get_name() {
		return 'ce-address';
	}

	public function get_title() {
		return __( 'Event Address', 'church-events' );
	}

	public function render() {
		echo esc_html( get_post_meta( get_the_ID(), 'event_address', true ) );
	}
}

class CE_Tag_Map_Address extends CE_Dynamic_Tag_Base {

	public function get_name() {
		return 'ce-map-address';
	}

	public function get_title() {
		return __( 'Event Map Address', 'church-events' );
	}

	public function render() {
		echo esc_html( get_post_meta( get_the_ID(), 'event_map_address', true ) );
	}
}


// ---------------------------------------------------------------------------
// Booking tags
// ---------------------------------------------------------------------------

/**
 * Booking URL outputs both TEXT and URL categories so Elementor can use it
 * as a link href as well as plain text.
 */
class CE_Tag_Booking_URL extends \Elementor\Core\DynamicTags\Tag {

	public function get_name() {
		return 'ce-booking-url';
	}

	public function get_title() {
		return __( 'Event Booking URL', 'church-events' );
	}

	public function get_group() {
		return 'church-events';
	}

	public function get_categories() {
		return array(
			\Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY,
			\Elementor\Modules\DynamicTags\Module::URL_CATEGORY,
		);
	}

	public function render() {
		echo esc_url( get_post_meta( get_the_ID(), 'event_booking_url', true ) );
	}
}

class CE_Tag_Booking_Text extends CE_Dynamic_Tag_Base {

	public function get_name() {
		return 'ce-booking-text';
	}

	public function get_title() {
		return __( 'Event Booking Link Text', 'church-events' );
	}

	public function render() {
		echo esc_html( get_post_meta( get_the_ID(), 'event_booking_text', true ) );
	}
}


// ---------------------------------------------------------------------------
// Boolean tags
// ---------------------------------------------------------------------------

class CE_Tag_Signup_Enabled extends CE_Dynamic_Tag_Base {

	public function get_name() {
		return 'ce-signup-enabled';
	}

	public function get_title() {
		return __( 'Event Signup Enabled', 'church-events' );
	}

	public function render() {
		$val = get_post_meta( get_the_ID(), 'event_signup_enabled', true );
		echo esc_html( $val ? __( 'Yes', 'church-events' ) : __( 'No', 'church-events' ) );
	}
}

class CE_Tag_All_Day extends CE_Dynamic_Tag_Base {

	public function get_name() {
		return 'ce-all-day';
	}

	public function get_title() {
		return __( 'Event All Day', 'church-events' );
	}

	public function render() {
		$val = get_post_meta( get_the_ID(), 'event_all_day', true );
		echo esc_html( $val ? __( 'Yes', 'church-events' ) : __( 'No', 'church-events' ) );
	}
}


// ---------------------------------------------------------------------------
// Source / ID tags
// ---------------------------------------------------------------------------

class CE_Tag_ChurchSuite_ID extends CE_Dynamic_Tag_Base {

	public function get_name() {
		return 'ce-churchsuite-id';
	}

	public function get_title() {
		return __( 'ChurchSuite ID', 'church-events' );
	}

	public function render() {
		echo esc_html( get_post_meta( get_the_ID(), 'event_churchsuite_id', true ) );
	}
}

class CE_Tag_ChurchSuite_Category extends CE_Dynamic_Tag_Base {

	public function get_name() {
		return 'ce-churchsuite-category';
	}

	public function get_title() {
		return __( 'ChurchSuite Category', 'church-events' );
	}

	public function render() {
		echo esc_html( get_post_meta( get_the_ID(), 'event_churchsuite_category', true ) );
	}
}