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

	// Featured badge colours
	$fb_bg = ce_get_option( 'featured_badge_bg', '#000000' );
	$fb_fg = ce_get_option( 'featured_badge_fg', '#ffffff' );

	return ".church-events {
		--ce-primary: {$primary};
		--ce-secondary: {$secondary};
		--ce-text: {$text};
		--ce-accent: {$accent};
		--ce-image-ratio: {$ratio_css};
		--ce-featured-bg: {$fb_bg};
		--ce-featured-fg: {$fb_fg};
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
		'restUrl'         => esc_url_raw( rest_url( 'wp/v2/' . ( sanitize_title( ce_get_option( 'cpt_slug', 'events' ) ) ?: 'events' ) ) ),
		'restNonce'       => wp_create_nonce( 'wp_rest' ),
		'enabledViews'    => array_values( ce_get_option( 'enabled_views', array( 'calendar', 'cards' ) ) ),
		'defaultView'     => ce_get_option( 'default_view', 'calendar' ),
		'mobileView'      => ce_get_option( 'mobile_view', '' ),
		'interaction'     => ce_get_option( 'card_interaction', 'modal' ),
		'hoverPreview'    => (bool) ce_get_option( 'hover_preview', false ),
		'featuredBadge'   => array(
			'enabled'  => (bool) ce_get_option( 'featured_badge_enabled', true ),
			'label'    => ce_get_option( 'featured_badge_label', __( 'Featured', 'church-events' ) ),
			'position' => ce_get_option( 'featured_badge_position', 'above' ),
		),
		'categoryPill'    => array(
			'position' => ce_get_option( 'category_pill_position', 'image' ),
		),
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
			'loadMore'        => __( 'Load More', 'church-events' ),
			'dateFrom'        => __( 'From', 'church-events' ),
			'dateTo'          => __( 'To', 'church-events' ),
		),
		'perPage'         => (int) ce_get_option( 'per_page', 12 ),
		'listStyle'       => ce_get_option( 'list_style', 'cards' ),
		'calendarFirstDay' => (int) ce_get_option( 'calendar_first_day', 1 ),
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
	$enabled_views = ce_get_option( 'enabled_views', array( 'calendar', 'cards' ) );
	$atts = shortcode_atts( array(
		'default_view' => ce_get_option( 'default_view', $enabled_views[0] ),
		'layout'       => '', // optional: 'calendar' | 'cards' | 'list' — restricts to a single view
		'site'         => '',
		'category'     => '',
	), $atts, 'church_events_calendar' );

	// If layout= is set to a single valid view, restrict to that view only
	$valid_views = array( 'calendar', 'cards', 'list' );
	if ( $atts['layout'] && in_array( $atts['layout'], $valid_views, true ) ) {
		$enabled_views        = array( $atts['layout'] );
		$atts['default_view'] = $atts['layout'];
	}

	$data_site     = $atts['site']     ? ' data-locked-site="'     . esc_attr( $atts['site'] )     . '"' : '';
	$data_category = $atts['category'] ? ' data-locked-category="' . esc_attr( $atts['category'] ) . '"' : '';

	ob_start();
	?>
	<div class="church-events" data-ce-root data-default-view="<?php echo esc_attr( $atts['default_view'] ); ?>"<?php echo $data_site . $data_category; ?>>

		<?php ce_render_toolbar( $enabled_views, $atts['default_view'], $atts['site'], $atts['category'] ); ?>

		<div class="ce-views">

			<?php if ( in_array( 'calendar', $enabled_views, true ) ) : ?>
			<div class="ce-view ce-view--calendar<?php echo ( $atts['default_view'] === 'calendar' ) ? ' is-active' : ''; ?>" aria-label="<?php esc_attr_e( 'Calendar view', 'church-events' ); ?>">
				<div id="ce-calendar" class="ce-calendar-container"><div class="ce-loading"></div></div>
			</div>
			<?php endif; ?>

			<?php if ( in_array( 'cards', $enabled_views, true ) ) : ?>
			<div class="ce-view ce-view--cards" aria-label="<?php esc_attr_e( 'Cards view', 'church-events' ); ?>">
				<div class="ce-events-output"></div>
			</div>
			<?php endif; ?>

			<?php if ( in_array( 'list', $enabled_views, true ) ) : ?>
			<div class="ce-view ce-view--list" aria-label="<?php esc_attr_e( 'List view', 'church-events' ); ?>">
				<div class="ce-events-output"></div>
			</div>
			<?php endif; ?>

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
		'layout'   => 'cards',   // 'cards' | 'list' | 'calendar'
		'columns'  => ce_get_option( 'grid_columns', 3 ),
		'site'     => '',
		'category' => '',
		'limit'    => 0,         // hard cap on cards/rows; also suppresses Load More. 0 = use per_page.
		'controls' => 'on',      // 'off' | 'false' | 'no' | '0' hides the toolbar (search/filters/toggle)
		// Legacy alias — honoured so existing shortcodes keep working
		'view'     => '',
	), $atts, 'church_events_list' );

	// Resolve layout, honouring legacy view= attribute
	$valid  = array( 'cards', 'list', 'calendar' );
	$layout = in_array( $atts['layout'], $valid, true ) ? $atts['layout'] : 'cards';
	if ( $atts['view'] && in_array( $atts['view'], array( 'cards', 'list' ), true ) ) {
		$layout = $atts['view'];
	}

	$data_site     = $atts['site']     ? ' data-locked-site="' . esc_attr( $atts['site'] ) . '"' : '';
	$data_category = $atts['category'] ? ' data-locked-category="' . esc_attr( $atts['category'] ) . '"' : '';
	$limit         = max( 0, (int) $atts['limit'] );
	$data_limit    = $limit > 0 ? ' data-limit="' . esc_attr( $limit ) . '"' : '';
	$show_controls = ! in_array( strtolower( (string) $atts['controls'] ), array( 'off', 'false', 'no', '0' ), true );

	ob_start();

	if ( $layout === 'calendar' ) {
		$enabled_views = array( 'calendar' );
		?>
		<div class="church-events" data-ce-root data-default-view="calendar"<?php echo $data_site . $data_category; ?>>
			<?php if ( $show_controls ) ce_render_toolbar( $enabled_views, 'calendar', $atts['site'], $atts['category'] ); ?>
			<div class="ce-views">
				<div class="ce-view ce-view--calendar is-active" aria-label="<?php esc_attr_e( 'Calendar view', 'church-events' ); ?>">
					<div id="ce-calendar" class="ce-calendar-container"></div>
				</div>
			</div>
			<?php ce_render_modal(); ?>
			<?php ce_render_hover_preview(); ?>
		</div>
		<?php
	} else {
		?>
		<div class="church-events" data-ce-root data-default-view="<?php echo esc_attr( $layout ); ?>" data-columns="<?php echo esc_attr( $atts['columns'] ); ?>"<?php echo $data_site . $data_category . $data_limit; ?>>

			<?php if ( $show_controls ) ce_render_toolbar( array( $layout ), $layout, $atts['site'], $atts['category'] ); ?>

			<?php if ( $layout === 'cards' ) : ?>
			<div class="ce-view ce-view--cards is-active" aria-label="<?php esc_attr_e( 'Cards view', 'church-events' ); ?>">
				<div class="ce-events-output"></div>
			</div>
			<?php else : ?>
			<div class="ce-view ce-view--list is-active" aria-label="<?php esc_attr_e( 'List view', 'church-events' ); ?>">
				<div class="ce-events-output"></div>
			</div>
			<?php endif; ?>

			<?php ce_render_modal(); ?>
			<?php ce_render_hover_preview(); ?>

		</div>
		<?php
	}

	return ob_get_clean();
}

// ---------------------------------------------------------------------------
// Shared HTML partials
// ---------------------------------------------------------------------------

/**
 * Render the toolbar — view toggle buttons + filter bar.
 *
 * @param array  $enabled_views    Array of view slugs: 'calendar', 'cards', 'list'
 * @param string $default_view     Which view is active on load
 * @param string $locked_site      Slug to pre-filter by site (hides site dropdown when set)
 * @param string $locked_category  Slug to pre-filter by category (hides category dropdown when set)
 */
function ce_render_toolbar( $enabled_views, $default_view = '', $locked_site = '', $locked_category = '' ) {
	$show_toggle = count( $enabled_views ) > 1;
	$date_filter = ce_get_option( 'date_filter_type', 'month' );
	$show_month  = ( $date_filter === 'month' );

	$view_defs = array(
		'calendar' => array(
			'label' => __( 'Calendar', 'church-events' ),
			'icon'  => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
		),
		'cards'    => array(
			'label' => __( 'Cards', 'church-events' ),
			'icon'  => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>',
		),
		'list'     => array(
			'label' => __( 'List', 'church-events' ),
			'icon'  => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><circle cx="3" cy="6" r="1" fill="currentColor"/><circle cx="3" cy="12" r="1" fill="currentColor"/><circle cx="3" cy="18" r="1" fill="currentColor"/></svg>',
		),
	);
	?>
	<div class="ce-toolbar" role="toolbar">

		<?php if ( $show_toggle ) : ?>
		<div class="ce-view-toggle" role="group" aria-label="<?php esc_attr_e( 'View options', 'church-events' ); ?>">
			<?php foreach ( $enabled_views as $slug ) :
				if ( ! isset( $view_defs[ $slug ] ) ) continue;
				$is_active = ( $slug === $default_view ) || ( empty( $default_view ) && $slug === $enabled_views[0] );
			?>
			<button
				class="ce-btn ce-btn--<?php echo esc_attr( $slug ); ?><?php echo $is_active ? ' is-active' : ''; ?>"
				data-ce-view="<?php echo esc_attr( $slug ); ?>"
				aria-pressed="<?php echo $is_active ? 'true' : 'false'; ?>"
			>
				<?php echo $view_defs[ $slug ]['icon']; ?>
				<?php echo esc_html( $view_defs[ $slug ]['label'] ); ?>
			</button>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>

		<div class="ce-filters-bar">
			<div class="ce-filters">
				<div class="ce-filter-search-wrap">
				<svg class="ce-filter-search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
				<input
					type="search"
					class="ce-filter-search"
					placeholder="<?php esc_attr_e( 'Search events…', 'church-events' ); ?>"
					aria-label="<?php esc_attr_e( 'Search events', 'church-events' ); ?>"
				/>
			</div>
			<?php
			$category_terms = get_terms( array( 'taxonomy' => 'event-category', 'hide_empty' => true ) );
			if ( ! $locked_category && ! is_wp_error( $category_terms ) && count( $category_terms ) > 0 ) : ?>
			<label class="ce-filter-label">
				<span class="ce-filter-label-text"><?php esc_html_e( 'Category', 'church-events' ); ?></span>
				<select class="ce-filter-category">
					<option value=""><?php esc_html_e( 'All categories', 'church-events' ); ?></option>
				</select>
			</label>
			<?php endif; ?>
		<?php
		$site_terms = get_terms( array( 'taxonomy' => 'event-site', 'hide_empty' => true ) );
		if ( ! $locked_site && count( $site_terms ) > 1 && ! is_wp_error( $site_terms ) ) : ?>
			<?php
			$site_label = ce_get_option( 'site_label', 'site' );
			$all_label  = $site_label === 'church' ? __( 'All churches', 'church-events' ) : __( 'All sites', 'church-events' );
			$aria_label = $site_label === 'church' ? __( 'Filter by church', 'church-events' ) : __( 'Filter by site', 'church-events' );
			?>
			<label class="ce-filter-label">
			<span class="ce-filter-label-text"><?php echo esc_html( ucfirst( $site_label ) ); ?></span>
			<select class="ce-filter-site">
				<option value=""><?php echo esc_html( $all_label ); ?></option>
				<?php foreach ( $site_terms as $term ) : ?>
				<option value="<?php echo esc_attr( $term->slug ); ?>"><?php echo esc_html( $term->name ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
			<?php endif; ?>
				<?php if ( $show_month ) : ?>
				<label class="ce-filter-label">
					<span class="ce-filter-label-text"><?php esc_html_e( 'Month', 'church-events' ); ?></span>
					<select class="ce-filter-month">
						<option value=""><?php esc_html_e( 'All months', 'church-events' ); ?></option>
					</select>
				</label>
				<?php else : ?>
				<input
					type="date"
					class="ce-filter-date-from"
					aria-label="<?php esc_attr_e( 'From date', 'church-events' ); ?>"
				/>
				<input
					type="date"
					class="ce-filter-date-to"
					aria-label="<?php esc_attr_e( 'To date', 'church-events' ); ?>"
				/>
				<?php endif; ?>
			</div>
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
		<div class="ce-hover-preview-badges-above"></div>
		<div class="ce-hover-preview-title"></div>
		<div class="ce-hover-preview-badges-below"></div>
		<div class="ce-hover-preview-meta"></div>
	</div>
	<?php
}

// ---------------------------------------------------------------------------
// Single event page shortcodes
// These are for use in Elementor (or any template) on the single event CPT
// page, where dynamic tags can't natively handle the all-day logic.
// ---------------------------------------------------------------------------

/**
 * [ce_event_time] — outputs the formatted time for the current event.
 *
 * On all-day events: "All Day"
 * Otherwise: "10:00" or "10:00 – 11:30"
 *
 * Usage: [ce_event_time] or [ce_event_time post_id="123"]
 *
 * @param array $atts
 * @return string
 */
function ce_shortcode_event_time( $atts ) {
	$atts    = shortcode_atts( array( 'post_id' => 0 ), $atts, 'ce_event_time' );
	$post_id = $atts['post_id'] ? (int) $atts['post_id'] : get_the_ID();
	if ( ! $post_id ) return '';

	$all_day    = get_post_meta( $post_id, 'event_all_day',   true );
	$start_time = get_post_meta( $post_id, 'event_start_time', true );
	$end_time   = get_post_meta( $post_id, 'event_end_time',   true );

	// All-day: explicit flag, or time absent/midnight
	if ( $all_day || ! $start_time || $start_time === '00:00:00' || $start_time === '00:00' ) {
		return esc_html__( 'All Day', 'church-events' );
	}

	$start = substr( $start_time, 0, 5 ); // HH:MM
	if ( $end_time && $end_time !== '00:00:00' && $end_time !== '00:00' ) {
		return esc_html( $start . ' – ' . substr( $end_time, 0, 5 ) );
	}
	return esc_html( $start );
}
add_shortcode( 'ce_event_time', 'ce_shortcode_event_time' );

/**
 * [ce_event_date] — outputs the formatted date for the current event.
 *
 * Single date:      "Monday, 12 May 2025"
 * Date range:       "Monday, 12 May 2025 – Wednesday, 14 May 2025"
 *
 * Usage: [ce_event_date] or [ce_event_date post_id="123" format="j F Y"]
 *
 * @param array $atts
 * @return string
 */
function ce_shortcode_event_date( $atts ) {
	$atts    = shortcode_atts( array( 'post_id' => 0, 'format' => '' ), $atts, 'ce_event_date' );
	$post_id = $atts['post_id'] ? (int) $atts['post_id'] : get_the_ID();
	if ( ! $post_id ) return '';

	$start_raw = get_post_meta( $post_id, 'event_start_date', true ); // YYYYMMDD
	$end_raw   = get_post_meta( $post_id, 'event_end_date',   true );

	if ( ! $start_raw || strlen( $start_raw ) < 8 ) return '';

	$fmt = $atts['format'] ? $atts['format'] : get_option( 'date_format', 'l, j F Y' );

	$start_ts = mktime( 0, 0, 0,
		(int) substr( $start_raw, 4, 2 ),
		(int) substr( $start_raw, 6, 2 ),
		(int) substr( $start_raw, 0, 4 )
	);
	$start_str = date_i18n( $fmt, $start_ts );

	if ( $end_raw && $end_raw !== $start_raw && strlen( $end_raw ) >= 8 ) {
		$end_ts  = mktime( 0, 0, 0,
			(int) substr( $end_raw, 4, 2 ),
			(int) substr( $end_raw, 6, 2 ),
			(int) substr( $end_raw, 0, 4 )
		);
		return esc_html( $start_str . ' – ' . date_i18n( $fmt, $end_ts ) );
	}

	return esc_html( $start_str );
}
add_shortcode( 'ce_event_date', 'ce_shortcode_event_date' );
