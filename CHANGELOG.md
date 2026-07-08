# Changelog

All notable changes to Church Events are documented here.

## 1.7.31

### Added
- Elementor Loop Grid support for featured events. Set a Loop Grid or Posts widget's Query ID to `featured_events` to show upcoming featured events (today onwards) ordered by event start date rather than publish date.

## 1.7.30

### Fixed
- Cards and list views could show a different, incomplete set of events on each load. The REST ordering lacked a unique tiebreaker, so events sharing the same start date and time were returned in an undefined order, which broke pagination (missing and duplicated events). Added post ID as a final sort key for a stable, deterministic order. The calendar grid was unaffected because it fetches all events in range in a single request.

## 1.7.29

### Added
- `[church_events_list]` now accepts `limit="N"` to cap the number of cards/rows shown. A limit also suppresses the Load More button, giving a fixed block of the next N upcoming events — intended for homepage "what's on" sections.
- `[church_events_list]` now accepts `controls="off"` (also `false`/`no`/`0`) to hide the entire toolbar (search, category/site/month filters, and view toggle). Applies to both the cards/list and calendar layouts of this shortcode.

## 1.7.28

### Added
- Elementor Dynamic Tags fixes.

## 1.7.27

### Added
- Native Elementor Dynamic Tags for all event fields, grouped under "Church Events" in the Elementor dynamic tags panel. Eliminates the implicit ACF dependency for formatted date display in Elementor single event templates. Includes formatted Start Date, End Date, and Date Range tags (respecting WordPress date format setting), plus Start Time, End Time, Location, Address, Map Address, Booking URL, Booking Link Text, Signup Enabled, All Day, ChurchSuite ID, and ChurchSuite Category.

## 1.7.26

### Fixed
- Calendar loading spinner now appears immediately on page load, before FullCalendar initialises, rather than only after the grid has rendered

## 1.7.25

### Added
- Calendar grid view now shows a spinner (top-right) while events are loading

## 1.7.24

### Added
- Category pill also overlaid on modal featured image

## 1.7.23

### Added
- Cards view: first category now renders as a coloured pill overlaid on the featured image (top-left), inheriting category colour and luminance-aware text colour
- Pill also renders over the no-image placeholder when no featured image is set

### Changed
- Categories suppressed from card body to prevent double rendering

## 1.7.22

### Changed
- Location and time meta items on event cards now include an explicit icon span (`ce-meta-icon`) to allow site-level CSS

## 1.7.21

### Added
- Featured events now carry a `ce-featured` CSS class on their card and agenda/list items, allowing custom styling of featured events in those views. Exposed via a new `event_featured` REST field.

## 1.7.20

### Fixed
- Modal share button colour now uses CSS custom properties with dark fallback rather than inheriting theme button colour.

## 1.7.19

### Fixed
- Modal share button no longer inherits theme button background styles.

## 1.7.18

### Changed
- Modal share button: left-aligned, borderless, with inline "Share" label alongside the icon.

## 1.7.17

### Changed
- Share button in event modal replaced with a compact icon button; uses the native Web Share API on supported devices (mobile share sheet), falling back to clipboard copy on desktop.

## 1.7.16

### Added
- Share link in event modal: always-visible URL input with a copy-to-clipboard button, appearing alongside the existing "View Details" button when applicable.

## 1.7.15

### Added
- Sync health monitor. Shows a WP Admin notice when the last event sync failed or has gone stale (no successful run within 3× the sync interval), and emails a configured address on entering a failure state. Email is failures-only and sends once per episode, resetting when the sync recovers.

### Notes
- Set the alert address via the `CE_SYNC_ALERT_EMAIL` constant in `includes/sync-monitor.php` (empty disables email; the admin notice still shows).
- Email delivery depends on the site's mail configuration — SMTP is recommended, as the default PHP mailer is often unreliable.
- Stale alerts depend on a sync running or an admin page loading; detecting a fully stalled cron on an unattended site needs external uptime monitoring, which is outside the plugin.

## 1.7.14

### Added
- Calendar grid view now has a configurable week start day (Monday or Sunday), set under Settings → Display. Defaults to Monday, so existing sites are unaffected.

## 1.7.13

### Fixed
- List, agenda and card views now sort same-day events by start time (ascending) rather than leaving them in an undefined order. Applies to all sources.

## 1.7.12

### Changed
- Google Calendar importer now counts untitled events (no title set in the calendar) as *skipped* rather than logging them as errors on every run. They were never a fault — just a content choice at the calendar end.

### Fixed
- ChurchSuite importer now refuses to start if another ChurchSuite import is already running, matching the guard added to the Google importer. Prevents overlapping cron/manual runs from creating duplicate event posts.

## 1.7.11

### Fixed
- Google Calendar importer now refuses to run if another import is already in progress. Overlapping runs (cron firing during a manual sync, or two cron spawns racing) were each inserting the full feed, creating duplicate event posts. A self-expiring lock prevents this; the lock is always released, even if a run errors or bails early.

## 1.7.10

### Fixed
- Google Calendar importer now refuses to run if another import is already in progress. Overlapping runs (cron firing during a manual sync, or two cron spawns racing) were each inserting the full feed, creating duplicate event posts. A self-expiring lock prevents this; the lock is always released, even if a run errors or bails early.

## 1.7.9

### Added
- Per-category colours for events. Categories are seeded with their colour from the ChurchSuite feed on first import; colours can be overridden manually on the category edit screen (Events → Categories) and manual values are never overwritten by sync. Leave the field empty to use the default style.
- Category colours applied on the front end: calendar grid pills (with automatic light/dark text for readability) and category tags on card and list views.

### Changed
- Calendar month grid now renders timed events as block pills (`eventDisplay: 'block'`) so category colours apply correctly.
- Pill text colour now inherits from the event element rather than being forced white, supporting light category colours.

### Notes
- Coloured pills don't change colour on hover (default-styled pills still do).
- Known limitation: a deliberately cleared colour will be re-seeded from ChurchSuite on next sync.

## 1.7.8

### Changed
- ChurchSuite sync now retains past events for 1 month after their start date instead of trashing them as soon as they drop out of the feed — keeps the calendar month grid populated later in the month. Future events removed from ChurchSuite are still trashed promptly, and events older than 1 month are trashed as before (then purged automatically by WordPress after 30 days).

## 1.7.7

### Added
- ChurchSuite importer now reads the `featured` flag from the feed (`signup_options.public.featured`) and assigns events to a new hidden `event-featured` taxonomy.
- New `event-featured` taxonomy (public, hidden from admin UI and nav menus) — enables filtering featured events in Elementor loop grids and other taxonomy-based queries.

### Notes
- The featured term is added or removed on every sync, so featured status stays in step with ChurchSuite.

## 1.7.6 — 2026-06-12

### Fixed
- Removed focus ring on search and filter inputs

## 1.7.5 — 2026-06-12

### Fixed
- Search icon weight increased for better visibility
- Dropdown chevron replaced with heavier stroked version to match reference design
- Search field focus style moved to wrapper element to prevent double border ring

## 1.7.4 — 2026-06-12

### Added
- Search input now includes an inline search icon, injected via a wrapper div in the toolbar markup

## 1.7.3

### Fixed
- Agenda view event image now aligns to the top of the row instead of centre

## 1.7.2

### Fixed
- Dropdown arrow on category, site, and month filters now uses a custom SVG chevron with `appearance: none`, giving consistent cross-browser positioning and spacing

## 1.7.1

### Fixed
- Google Calendar importer now limits fetched events to a 6-month window (`timeMax`) to prevent PHP timeout on calendars with large numbers of recurring events

## 1.7.0

### Added
- Google Calendar importer (`includes/importer-google.php`) — fetches events server-side via the Google Calendar API, with full pagination support, upsert deduplication on Google event ID, all-day event handling, and automatic trashing of events removed from the calendar

## 1.6.9

### Fixed
- XSS: escaped `location`, `address`, `booking_url`, `booking_text`, and `excerpt` fields in front-end JS before injecting into `innerHTML`
- XSS: replaced regex strip-tags on excerpt with safe `textContent` extraction
- XSS: added `safeUrl()` helper to validate booking URL protocol before use in `href`
- Log file moved to `uploads/church-events/` subdirectory with `.htaccess` protection to prevent direct web access
- SSRF: ChurchSuite feed URL now validated against private/loopback IP ranges on save
- Sync key: added minimum length warning (32 characters) when saving a weak server cron key

## 1.6.8

### Fixed
- Calendar prev/next/today buttons moved to right side of toolbar to restore expected position after view toggle buttons were hidden on mobile

## 1.6.7

### Fixed
- Filter dropdowns stacking correctly on mobile (labels now use `flex: 0 0 100%` at 768px breakpoint)
- Cleaned up duplicate and unscoped `.ce-filter-label` CSS rules introduced in v1.6.5

## 1.6.6

### Fixed
- Filter dropdowns not filling available width after being wrapped in label elements in v1.6.5

## 1.6.5

### Added
- Labels above category, site, and month filter dropdowns in the events toolbar
- CSS styles for filter labels (`.ce-filter-label`, `.ce-filter-label-text`) with uppercase, small, weighted text

### Fixed
- Minor indentation inconsistency in site filter label markup in `shortcodes.php`

## 1.6.4

### Fixed
- View toggle buttons (calendar/cards/list) briefly flashing on mobile before being hidden by JS — toggles are now hidden via CSS at mobile breakpoint, before JS executes

## [1.6.3] - 2026-06-08

### Fixed
- Long email addresses in event descriptions no longer overflow the modal on narrow screens

## [1.6.2] - 2026-06-08

### Fixed
- Fix icon placement on event meta

## [1.6.1] - 2026-06-05

### Fixed
- Removed tracked `.DS_Store` files from repository and added macOS metadata files to `.gitignore`.

## [1.6.0] - 2026-06-05

### Added
- Plugin Update Checker v5.5 (Yahnis Elsts) vendored into `lib/` — sites running the plugin can now receive updates via WP Admin → Updates directly from GitHub Releases, with no WordPress.org dependency.

## [1.5.0] — 2026-05-20
### Added
- **Events URL Slug** setting (Display tab) — allows the CPT rewrite slug to be changed per-site (e.g. `church-events`) to avoid conflicts with existing WordPress pages at `/events/`. Defaults to `events` so all existing installs are unaffected
- Permalink rules flush automatically on save when the slug changes; no manual visit to Settings → Permalinks required

### Fixed
- Child pages under `/events/` (e.g. `/events/church-diary/`) returning 404 or redirecting to a same-named event post, caused by the CPT rewrite rules taking priority over the page hierarchy
- Month and category filter REST calls used a hardcoded `/events/` regex to derive the taxonomy base URL; now strips the last path segment dynamically so filters work regardless of slug

## [1.4.4] — 2026-05-20
### Fixed
- Site filter dropdown now hidden when only one site term exists; previously it appeared on single-site installs because the importer correctly creates a term even when ChurchSuite has only one site, leaving `get_terms()` returning a non-empty result

## [1.4.3] — 2026-05-15
### Changed
- Cards grid now responsive by default: 3 columns on desktop (>1024px), 2 on tablet (769–1024px), 1 on mobile (≤768px)
- Uses `min( 2, var(--ce-columns) )` at tablet so a 2-column desktop setting doesn't widen on tablet

## [1.4.2] — 2026-05-15
### Fixed
- `enabledViews` now derived from DOM containers actually present, not from `cfg.enabledViews` (global setting)
- Fixes blank view on mobile when shortcode restricts to a single non-calendar view and the global mobile setting points to a view that isn't rendered

## [1.4.1] — 2026-05-15
### Fixed
- Mobile responsive logic now uses intersection of `cfg.mobileView` with views actually rendered in the embed, not the global enabled views list
- `layout="list"` shortcode with global mobile view set to cards now correctly shows list on mobile

## [1.4.0] — 2026-05-15
### Added
- `layout` attribute on `[church_events_calendar]` — restricts the embed to a single view (`calendar`, `cards`, or `list`), overriding the global enabled views for that embed only
### Changed
- `[church_events_list]` simplified to a single `layout` attribute (`cards`, `list`, or `calendar`) replacing the confusing `view` + `layout` combination
- Old `view=` attribute still honoured silently for backwards compatibility
- Shortcodes reference tab updated to reflect simplified attribute model

## [1.3.9] — 2026-05-15
### Changed
- `[church_events_list]` now uses a single `layout` attribute (`cards`, `list`, `calendar`) in place of the separate `view` and `layout` attributes
- Legacy `view=` attribute still accepted for backwards compatibility

## [1.3.8] — 2026-05-15
### Added
- **Shortcodes** tab in plugin settings — reference page listing all available shortcodes, attributes, and ACF dynamic field keys; no save button, read-only

## [1.3.7] — 2026-05-15
### Changed
- Importer now saves times as `HH:MM` instead of `HH:MM:SS` — consistent with how end times were already stored and removes seconds from ACF dynamic tag output in Elementor
- All-day detection updated to match `HH:MM` format (`00:00` instead of `00:00:00`)
- Field descriptions and ACF instructions updated throughout

## [1.3.6] — 2026-05-15
### Fixed
- PHP parse error introduced in 1.3.4/1.3.5 — orphaned `*/` comment fragment left by a bad string replacement in `meta.php` caused a fatal error on all page loads

## [1.3.5] — 2026-05-15
### Fixed
- ACF field group hook changed from `acf/init` to `after_setup_theme` for reliable load order when called from a plugin
- Removed invalid `readonly` property from ACF field definitions (not a recognised ACF argument; caused fatal on some ACF versions)

## [1.3.4] — 2026-05-15
### Added
- ACF local field group for the Event CPT, registered via `acf_add_local_field_group()`
- All plugin meta fields now appear in Elementor's ACF Dynamic Tag list under "Event Details"
- `event_all_day` and `event_signup_enabled` registered as `true_false` fields — enables conditional visibility in Elementor
- Block is skipped gracefully if ACF Pro is not active

## [1.3.3] — 2026-05-15
### Changed
- Hover preview now only fires on the calendar grid; removed from Cards and List views
- Hover preview setting description updated to reflect this
### Added
- `[ce_event_time]` shortcode — outputs "All Day" or "HH:MM – HH:MM" for use in Elementor single event templates
- `[ce_event_date]` shortcode — outputs formatted start date or date range; respects WordPress date format

## [1.3.2] — 2026-05-15
### Fixed
- **Settings wipe bug**: saving any tab no longer resets fields from other tabs — absent fields fall back to stored value, not hardcoded defaults
- **Resize restores wrong view**: `handleResponsive` now uses a flag to track whether it made the mobile switch, so manual view changes aren't overridden on resize
- **Hover preview offset on calendar**: switched to `position: fixed` with viewport-relative coordinates; clamps to viewport edge horizontally
### Changed
- Fields tab column heading renamed to "Cards & List View" with a note that the same field set applies to both view types

## [1.3.1] — 2026-05-15
### Added
- **Mobile View** setting — choose whether Cards or List replaces the calendar on screens narrower than 768px
- Setting only appears when Calendar is enabled alongside at least one other view

## [1.3.0] — 2026-05-15
### Added
- Three distinct front-end views: **Calendar** (month grid), **Cards** (grid/stack), **List** (agenda rows)
- **Available Views** backend setting — checkboxes let each church choose which views to expose
- Toggle buttons hidden automatically when only one view is enabled
- **Default View** setting scoped to enabled views; self-corrects on save
- `[church_events_list]` shortcode `view` attribute to force cards or list per page
### Changed
- **List Style** setting retired; Cards and List are now first-class views
- `ceConfig.enabledViews` replaces the implicit `defaultView === 'toggle'` convention
- Migration: existing installs upgrade automatically based on previous `list_style` and `default_view` values

## [1.2.27] — 2026-05-15
### Fixed

- Shortcode site and category locking now correctly filters both calendar and list views on initial load
- CalendarView locked values no longer overwritten by setFilters calls from the main controller
- getListFilters now respects locked site and category values from the shortcode
- Version bump to force browser and WordPress cache to pick up updated JS

## [1.2.26] — 2026-05-13
### Added
- Clear Event Cache button on settings page, clears all cached REST API transients with a success notice

## [1.2.25] — 2026-05-13
### Added
- site and category attributes for both [church_events_calendar] and [church_events_list] shortcodes
- When site is set, events are pre-filtered to that church and the site dropdown is hidden
- When category is set, events are pre-filtered to that category and the category dropdown is hidden
- Both attributes can be combined (e.g. a youth page scoped to a single church)

## [v1.2.24] – 2026-05-13
### Fixed
- Site filter dropdown styled consistently with other filters
- Filter bar layout: search field full width, category/site/month dropdowns at equal thirds
### Added
- Added "Site Filter Label" setting in Display tab — choose between "Site" and "Church" for frontend labels
- Responsive filter layout updated to include site dropdown at all breakpoints

## [v1.2.23] – 2026-05-13
### Fixed
- Site filter now works on calendar view
- REST API updated to support event_site filter parameter

## [1.2.22] — 2026-05-13
### Added
- `event-site` taxonomy for filtering events by church site/campus
- ChurchSuite site data imported from `site_ids` field in JSON feed
- Site filter dropdown in toolbar — only renders if site terms exist (graceful fallback for installs not using ChurchSuite sites)

## [1.2.21] — 2026-05-13
### Fixed
- Further settings sanitization improvements to prevent data loss on plugin update

## [1.2.16–1.2.20]
### Added
- Featured image support in agenda list view
### Fixed
- Settings data loss on plugin file upload (additional fields)

## [1.2.15] — 2026-05-08
### Fixed
- Settings data loss on plugin file upload — ChurchSuite URL, cron key, and fallback image fields now preserved when not present in POST data

## [1.2.11] — 2026-05-06
### Added
- Agenda list view: date-left layout with horizontal rules, selectable globally in Events → Settings → Display

## [1.2.10] — 2026-05-06
### Changed
- Agenda list view prep and continued calendar styling improvements
### Fixed
- Defensive sanitization for colour, Google Calendar, and sync interval settings fields

## [1.2.9] — 2026-05-06
### Added
- Month filter navigation (previous/next month)
- Modal close button
### Changed
- Calendar day cell and event pill styling improvements

## [1.2.8] — 2026-05-06
### Added
- Month picker filter replacing date range inputs
### Fixed
- Filter bar layout fixes

## [1.2.7] — 2026-05-06
### Changed
- Toolbar layout: toggle buttons (Calendar/List) moved above filters
- Filters wrapped in `.ce-filters-bar` div for independent styling

## [1.2.6] — 2026-04-27
### Added
- Filter bar layout and styling
- Button and filter layout controls

## [1.2.5]
### Added
- Trash events removed from ChurchSuite feed on sync

## [1.2.4]
### Fixed
- REST API caching rewritten to use `rest_api_init` hook
### Performance
- Transient caching for event REST responses

## [1.2.3]
### Fixed
- Prevent settings wipe on plugin update

## [1.2.2]
### Fixed
- DB indexes for query performance
- Fallback image setting
- Deduplication improvements

## [1.2.1]
### Fixed
- Deduplicate against legacy ACF `event_id` field from WPAllImport

## [1.2.0]
### Added
- Phase 4: ChurchSuite importer with WP-Cron scheduling, deduplication, image handling, and sync status reporting

## [1.1.0]
### Added
- Phase 3: list view pagination, load more, date range filtering, per-page setting

## [1.0.4]
### Fixed
- Set `rest_base` to `events` on CPT registration

## [1.0.0–1.0.3]
### Added
- Phase 1: plugin scaffold, CPT, meta fields, REST API, settings page
- Phase 2: calendar shortcode (FullCalendar), list/grid shortcode, frontend JS
