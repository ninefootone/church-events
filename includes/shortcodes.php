<?php
/**
 * Shortcode registration and asset enqueuing for Church Events.
 *
 * Shortcodes:
 *   [church_events_calendar] — FullCalendar month grid
 *   [church_events_list]     — List/grid view with filtering (Phase 3)
 *
 * Both shortcodes share the same toolbar and filtering JS;
 * the calendar shortcode also loads FullCalendar.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// FullCalendar version to load from CDN
define( 'CE_FULLCALENDAR_VERSION', '6.1.11' );

/**
 * Register shortcodes.
 */
function ce_register_shortcodes() {
	add_shortcode( 'church_events_calendar', 'ce_shortcode_calendar' );
	add_shortcode( 'church_events_list',     'ce_shortcode_list' );
}
add_action( 'init', 'ce_register_shortcodes' );

/**
 * Enqueue frontend assets.
 * Only loads on pages that actually contain a shortcode.
 */
function ce_enqueue_frontend_assets() {
	global $post;

	if ( ! is_a( $post, 'WP_Post' ) ) return;

	$has_calendar = has_shortcode( $post->post_content, 'church_events_calendar' );
	$has_list     = has_shortcode( $post->post_content, 'church_events_list' );

	if ( ! $has_calendar && ! $has_list ) return;

	// Core plugin CSS
	wp_enqueue_style(
		'church-events',
		CE_PLUGIN_URL . 'assets/css/church-events.css',
		array(),
		CE_VERSION
	);

	// Output CSS custom properties from settings, scoped to .church-events
	wp_add_inline_style( 'church-events', ce_build_css_variables() );

	// Output any custom CSS from settings
	$custom_css = ce_get_option( 'custom_css', '' );
	if ( $custom_css ) {
		wp_add_inline_style( 'church-events', $custom_css );
	}

	// FullCalendar from CDN — only if calendar shortcode is present
	if ( $has_calendar ) {
		wp_enqueue_script(
			'fullcalendar',
			'https://cdn.jsdelivr.net/npm/fullcalendar@' . CE_FULLCALENDAR_VERSION . '/index.global.min.js',
			array(),
			CE_FULLCALENDAR_VERSION,
			true
		);
	}

	// Plugin JS
	wp_enqueue_script(
		'church-events',
		CE_PLUGIN_URL . 'assets/js/church-events.js',
		$has_calendar ? array( 'fullcalendar' ) : array(),
		CE_VERSION,
		true
	);

	// Pass config to JS
	wp_localize_script( 'church-events', 'ceConfig', ce_build_js_config() );
}
add_action( 'wp_enqueue_scripts', 'ce_enqueue_frontend_assets' );

/**
 * Build the CSS custom properties block from plugin settings.
 * Checks for Elementor global variables first; falls back to settings colours.
 *
 * @return string
 */
function ce_build_css_variables() {
	$elementor_active = defined( 'ELEMENTOR_VERSION' );

	if ( $elementor_active ) {
		// Map plugin tokens to Elementor globals; settings colours used as fallbacks
		$primary   = 'var(--e-global-color-primary, '   . ce_get_option( 'color_primary',   '#083C5E' ) . ')';
		$secondary = 'var(--e-global-color-secondary, ' . ce_get_option( 'color_secondary', '#35878C' ) . ')';
		$text      = 'var(--e-global-color-text, '      . ce_get_option( 'color_text',      '#333333' ) . ')';
		$accent    = 'var(--e-global-color-accent, '    . ce_get_option( 'color_accent',     '#C1789C' ) . ')';
	} else {
		$primary   = ce_get_option( 'color_primary',   '#083C5E' );
		$secondary = ce_get_option( 'color_secondary', '#35878C' );
		$text      = ce_get_option( 'color_text',      '#333333' );
		$accent    = ce_get_option( 'color_accent',    '#C1789C' );
	}

	// Image ratio to CSS padding-top percentage
	$ratio     = ce_get_option( 'image_ratio', '16:9' );
	$ratio_css = ce_ratio_to_padding( $ratio );

	return ".church-events {
		--ce-primary: {$primary};
		--ce-secondary: {$secondary};
		--ce-text: {$text};
		--ce-accent: {$accent};
		--ce-image-ratio: {$ratio_css};
	}";
}

/**
 * Convert image ratio string to CSS padding-top percentage.
 *
 * @param string $ratio e.g. '16:9'
 * @return string e.g. '56.25%'
 */
function ce_ratio_to_padding( $ratio ) {
	$map = array(
		'1:1'  => '100%',
		'4:3'  => '75%',
		'16:9' => '56.25%',
		'4:5'  => '125%',
	);
	return isset( $map[ $ratio ] ) ? $map[ $ratio ] : '56.25%';
}

/**
 * Build the JS config object passed to church-events.js.
 *
 * @return array
 */
function ce_build_js_config() {
	$archive_fields = ce_get_option( 'archive_fields', array() );
	$detail_fields  = ce_get_option( 'detail_fields', array() );

	// Sort fields by order, return as ordered arrays for JS
	uasort( $archive_fields, fn( $a, $b ) => $a['order'] <=> $b['order'] );
	uasort( $detail_fields,  fn( $a, $b ) => $a['order'] <=> $b['order'] );

	return array(
		'restUrl'         => esc_url_raw( rest_url( 'wp/v2/events' ) ),
		'restNonce'       => wp_create_nonce( 'wp_rest' ),
		'defaultView'     => ce_get_option( 'default_view', 'toggle' ),
		'interaction'     => ce_get_option( 'card_interaction', 'modal' ),
		'hoverPreview'    => (bool) ce_get_option( 'hover_preview', false ),
		'gridColumns'     => (int) ce_get_option( 'grid_columns', 3 ),
		'imageRatio'      => ce_get_option( 'image_ratio', '16:9' ),
		'archiveFields'   => $archive_fields,
		'detailFields'    => $detail_fields,
		'i18n'            => array(
			'allDay'          => __( 'All day', 'church-events' ),
			'noEvents'        => __( 'No events found.', 'church-events' ),
			'loading'         => __( 'Loading events…', 'church-events' ),
			'bookNow'         => __( 'Book Now', 'church-events' ),
			'viewDetails'     => __( 'View Details', 'church-events' ),
			'close'           => __( 'Close', 'church-events' ),
			'searchPlaceholder' => __( 'Search events…', 'church-events' ),
			'filterAll'       => __( 'All categories', 'church-events' ),
			'calendarView'    => __( 'Calendar', 'church-events' ),
			'listView'        => __( 'List', 'church-events' ),
		),
	);
}

// ---------------------------------------------------------------------------
// Shortcode output
// ---------------------------------------------------------------------------

/**
 * [church_events_calendar] shortcode.
 *
 * @param array $atts
 * @return string
 */
function ce_shortcode_calendar( $atts ) {
	$atts = shortcode_atts( array(
		'default_view' => ce_get_option( 'default_view', 'toggle' ),
	), $atts, 'church_events_calendar' );

	ob_start();
	?>
	<div class="church-events" data-ce-root data-default-view="<?php echo esc_attr( $atts['default_view'] ); ?>">

		<?php ce_render_toolbar( $atts['default_view'] ); ?>

		<div class="ce-views">

			<div class="ce-view ce-view--calendar" aria-label="<?php esc_attr_e( 'Calendar view', 'church-events' ); ?>">
				<div id="ce-calendar" class="ce-calendar-container"></div>
			</div>

			<div class="ce-view ce-view--list" aria-label="<?php esc_attr_e( 'List view', 'church-events' ); ?>">
				<div class="ce-events-output"></div>
			</div>

		</div>

		<?php ce_render_modal(); ?>
		<?php ce_render_hover_preview(); ?>

	</div>
	<?php
	return ob_get_clean();
}

/**
 * [church_events_list] shortcode — Phase 3.
 * Placeholder output until Phase 3 is built.
 *
 * @param array $atts
 * @return string
 */
function ce_shortcode_list( $atts ) {
	$atts = shortcode_atts( array(
		'layout'       => 'grid',
		'columns'      => ce_get_option( 'grid_columns', 3 ),
		'default_view' => 'list',
	), $atts, 'church_events_list' );

	ob_start();
	?>
	<div class="church-events" data-ce-root data-default-view="list" data-layout="<?php echo esc_attr( $atts['layout'] ); ?>" data-columns="<?php echo esc_attr( $atts['columns'] ); ?>">

		<?php ce_render_toolbar( 'list' ); ?>

		<div class="ce-view ce-view--list is-active" aria-label="<?php esc_attr_e( 'List view', 'church-events' ); ?>">
			<div class="ce-events-output"></div>
		</div>

		<?php ce_render_modal(); ?>
		<?php ce_render_hover_preview(); ?>

	</div>
	<?php
	return ob_get_clean();
}

// ---------------------------------------------------------------------------
// Shared HTML partials
// ---------------------------------------------------------------------------

/**
 * Render the toolbar — view toggle buttons + filter bar.
 *
 * @param string $default_view  'calendar' | 'list' | 'toggle'
 */
function ce_render_toolbar( $default_view ) {
	$show_toggle = ( $default_view === 'toggle' );
	?>
	<div class="ce-toolbar" role="toolbar">

		<?php if ( $show_toggle ) : ?>
		<div class="ce-view-toggle" role="group" aria-label="<?php esc_attr_e( 'View options', 'church-events' ); ?>">
			<button class="ce-btn ce-btn--calendar is-active" data-ce-view="calendar" aria-pressed="true">
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
					<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
				</svg>
				<?php esc_html_e( 'Calendar', 'church-events' ); ?>
			</button>
			<button class="ce-btn ce-btn--list" data-ce-view="list" aria-pressed="false">
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
					<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><circle cx="3" cy="6" r="1" fill="currentColor"/><circle cx="3" cy="12" r="1" fill="currentColor"/><circle cx="3" cy="18" r="1" fill="currentColor"/>
				</svg>
				<?php esc_html_e( 'List', 'church-events' ); ?>
			</button>
		</div>
		<?php endif; ?>

		<div class="ce-filters">
			<input
				type="search"
				class="ce-filter-search"
				placeholder="<?php esc_attr_e( 'Search events…', 'church-events' ); ?>"
				aria-label="<?php esc_attr_e( 'Search events', 'church-events' ); ?>"
			/>
			<select class="ce-filter-category" aria-label="<?php esc_attr_e( 'Filter by category', 'church-events' ); ?>">
				<option value=""><?php esc_html_e( 'All categories', 'church-events' ); ?></option>
			</select>
		</div>

	</div>
	<?php
}

/**
 * Render the event detail modal shell.
 * Content is injected by JS when an event is clicked.
 */
function ce_render_modal() {
	if ( ce_get_option( 'card_interaction', 'modal' ) !== 'modal' ) return;
	?>
	<div class="ce-modal-overlay" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Event details', 'church-events' ); ?>" hidden>
		<div class="ce-modal">
			<button class="ce-modal-close" aria-label="<?php esc_attr_e( 'Close', 'church-events' ); ?>">
				<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
					<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
				</svg>
			</button>
			<div class="ce-modal-content"></div>
		</div>
	</div>
	<?php
}

/**
 * Render the hover preview popover shell.
 * Content is injected by JS on mouseenter.
 */
function ce_render_hover_preview() {
	if ( ! ce_get_option( 'hover_preview', false ) ) return;
	?>
	<div class="ce-hover-preview" aria-hidden="true" hidden>
		<div class="ce-hover-preview-title"></div>
		<div class="ce-hover-preview-meta"></div>
	</div>
	<?php
}
