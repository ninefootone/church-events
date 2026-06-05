# Church Events

A standalone WordPress plugin for church event management. Displays events via calendar and list/grid views using shortcodes, with no dependency on ACF, WPGridBuilder, WPAllImport, or Elementor.

## Features

- Custom Post Type (`event`) with native WordPress meta fields
- ChurchSuite or Google Calendar import (WP-Cron or server cron)
- FullCalendar.js month grid view
- List/grid view with filtering (category, text search, date)
- Hover preview and modal or single-page detail interaction
- Configurable field display with drag-and-drop ordering
- Scoped CSS with custom properties — no `!important` overrides needed
- Elementor global colour variable integration (auto-detected)
- Settings page with Import, Display, Interactions, Fields, and Styling tabs

## Requirements

- WordPress 6.0+
- PHP 8.0+
- No other plugins required

## Installation

### Via Git Updater (recommended for multi-site deployment)

1. Install the [Git Updater](https://git-updater.com/) plugin on your WordPress site
2. Add your GitHub personal access token in Git Updater → Settings
3. Install this plugin via Git Updater → Install Plugin, using the repo URL

### Manual

1. Download the latest release zip from the [Releases](../../releases) page
2. Upload via WordPress → Plugins → Add New → Upload Plugin

## Shortcodes

```
[church_events_calendar]
[church_events_list]
[church_events_list layout="grid" columns="3"]
```

## Configuration

After activation, go to **Events → Settings** to configure:

- **Import** — Connect ChurchSuite or Google Calendar, set sync frequency, trigger manual sync
- **Display** — Image aspect ratio, default view (calendar / list / toggle), grid columns
- **Interactions** — Modal vs single page, hover preview on/off
- **Fields** — Choose and reorder which fields appear on archive and detail views
- **Styling** — Brand colours, custom CSS with full list of available CSS custom properties

## CSS Customisation

All plugin output is scoped under `.church-events`. Override defaults using CSS custom properties:

```css
.church-events {
  --ce-primary: #083C5E;
  --ce-secondary: #35878C;
  --ce-card-radius: 8px;
  --ce-card-gap: 2rem;
}
```

On Elementor sites, the plugin maps to Elementor global colour variables automatically. Individual overrides can be added in **Events → Settings → Styling** or via the WordPress Customiser additional CSS panel.

## Development

```bash
git clone https://github.com/ninefootone/church-events.git
cd church-events
```

### Releasing an update

```bash
git tag v1.0.1
git push origin v1.0.1
```

GitHub Actions will build a release zip automatically. Sites running Git Updater will see the update in their WordPress dashboard.

## File Structure

```
church-events/
├── .github/
│   └── workflows/
│       └── release.yml       # Auto-build zip on version tag
├── admin/
│   ├── css/
│   │   └── admin.css
│   ├── js/
│   │   └── admin.js
│   ├── ajax.php              # Manual sync AJAX handler
│   └── settings.php          # Settings page (tabbed)
├── assets/
│   ├── css/
│   │   └── church-events.css # Frontend styles
│   └── js/
│       └── church-calendar.js # FullCalendar implementation
├── includes/
│   ├── class-church-events.php # Main plugin bootstrap class
│   ├── cpt.php               # CPT + taxonomy registration
│   ├── meta.php              # Post meta registration + meta box
│   └── rest-api.php          # REST API filters and field exposure
├── languages/                # Translation files
├── church-events.php         # Plugin entry point
└── README.md
```

## Phases

- [x] Phase 1 — Plugin scaffold, CPT/meta, REST API, settings page
- [ ] Phase 2 — Calendar shortcode (FullCalendar)
- [ ] Phase 3 — List/grid shortcode with filtering
- [ ] Phase 4 — ChurchSuite cron importer
- [ ] Phase 5 — Google Calendar cron importer

## License

[GPL-2.0+](LICENSE)
