# Simply Events Calendar

A clean, responsive WordPress plugin for displaying events with infinite scroll, recurring-event support, and modern design. Built with simplicity, performance, and developer experience in mind.

## Description

Simply Events Calendar provides an elegant way to create and display events on your WordPress website. The plugin features a responsive grid layout that adapts to all screen sizes, infinite scroll loading, and automatic filtering to show only current and upcoming events. As of **v4.4.0** it also supports recurring events that repeat every N days, weeks, months, or years — each occurrence is a real post, so it Just Works with the existing shortcode, archive, and admin UI. **v5.1.0** adds the `[sec_event]` single-event shortcode, Elementor Events Grid and Single Event display widgets (usable on any page), and weekly by-day recurrence (repeat on specific weekdays, weekdays only, or weekends). **v5.2.0** adds an in-admin "Upgrade to Pro" area (banner, feature preview, and menu link).

## Features

### 🎨 **Modern Design**

- Responsive grid layout (3 columns desktop, 2 tablet, 1 mobile)
- 3:2 aspect ratio featured images (grid); new image-left list layout with light-gray bordered cards
- Hover effects and smooth animations
- Clean, professional card-based design

### 📱 **Fully Responsive**

- Custom gap spacing for different screen sizes
- Device-optimized layouts
- Touch-friendly interactions
- Mobile-first approach

### ⚡ **Performance Optimized**

- Infinite scroll with AJAX loading
- Loads 6 events initially, then 6 more on scroll
- Optimized queries with proper caching
- Minimal resource usage
- SCSS compilation with minified production builds
- Source maps for development

### 🎯 **Smart Filtering**

- Automatically hides past events on frontend
- Admin can view all events (past, present, future)
- Chronological ordering (upcoming events first)
- Category-based filtering

### 🔁 **Recurring Events** (v4.4.0+)

- Repeat every N **days / weeks / months / years**
- **Weekly by-day recurrence** (v5.1.0+): choose specific weekdays, **Weekdays** (Mon–Fri), **Weekend** (Sat–Sun), or **Every day** via a S–M–T–W–T–F–S day picker with a live plain-English summary
- End conditions: after a number of occurrences, on a specific date, or **never** (with a rolling 24-month horizon that's refilled daily by WP-Cron, capped at 60 months out per series)
- Per-occurrence editing with a **Series Edit Scope** sidebar metabox: changes apply to *this occurrence only*, *this and future*, or *the entire series*
- Per-field overrides — customising one occurrence's location won't be overwritten by a later series-wide title change
- Each occurrence is a real `simple-events` post, so the shortcode, archive, AJAX loader, admin filters, and the **Series** admin column all work with recurring events with no extra configuration
- Large series generate the first 50 occurrences synchronously and finish in the background via `wp_schedule_single_event`
- DST-safe and month-end-safe date math (Jan 31 → Feb 28 → Mar 31; Feb 29 → Feb 28 in non-leap years)

### 🧩 **Display Widgets & Shortcodes (v5.1.0+)**

- **`[sec_events]`** — listing shortcode with infinite scroll; attributes control count, category, order, show-past, and show_* toggles
- **`[sec_event id="123"]`** — embed a single event anywhere in card or image-left list layout; also accepts `show_time`, `show_excerpt`, `show_location`, `show_footer`
- **Element shortcodes** (`[sec_event_title]`, `[sec_event_date]`, `[sec_event_time]`, etc.) — one per field, for fully custom layouts in any page builder
- **Elementor Events Grid widget** (`sec-events-grid`) — grid or image-left list of events, configurable column count, optional "Load more on scroll" infinite scroll; usable on any page
- **Elementor Single Event widget** (`sec-single-event`) — render one event chosen from a searchable list; card or list layout; usable on any page
- **Per-element Elementor widgets** — Event Title, Image, Date, Time, Location, Excerpt, Content, Categories, Button; designed for use inside a single-event template, event archive, or Elementor Loop Grid bound to events (previews a sample event in the editor; renders nothing on ordinary pages)
- **Elementor Loop Grid query presets** — set a Loop Grid's *Query ID* to `sec_events_by_date` (orders by event date, follows the global "Show past events" setting) or `sec_events_by_date_all` (orders by event date, always includes past) instead of sorting by post date
- **In-plugin Documentation page** (Events → Documentation) — lists all shortcodes and Elementor widgets with usage context

### 🛠 **Easy to Use**

- Simple shortcode: `[sec_events]`
- Custom post type with intuitive fields
- Built-in event categories
- No complex configuration needed
- Complete build system with npm scripts
- CSS linting and file watching for development

## Requirements

- **WordPress**: 6.0 or higher
- **PHP**: 7.4 or higher
- **No plugin dependencies** (Elementor is optional, for the bundled widgets/dynamic tags)

## Installation

1. Download the plugin files
2. Upload to `/wp-content/plugins/simple-events-calendar/`
3. Activate the **Simply Events Calendar** plugin
4. Start creating events! (Event date/time/location are entered in the "Event Details" box; adjust formatting under Events → Settings.)

> **Upgrading from 4.x?** No action needed. v5.0.0 removed the third-party custom-fields dependency; existing event data is preserved with no migration, and that plugin can be deactivated.

## Usage

### Creating Events

1. Go to **Events > Add New** in your WordPress admin
2. Fill in the event details:
   - **Event Date** (required)
   - **Event Start Time** (required)
   - **Event End Time** (optional)
   - **Event Location** (optional)
3. (Optional) Tick **This event repeats** to turn the event into a series:
   - Choose how often: every N **days / weeks / months / years**
   - Choose when it stops: **never** (rolling horizon), **after N occurrences**, or **on a specific date**
4. Add a featured image for best results
5. Publish your event

When you save a recurring event, each occurrence is created as its own post linked back to the parent. Editing any occurrence shows a **Series Edit Scope** sidebar that lets you choose whether the change applies to this occurrence only, this and all future occurrences, or the entire series.

### Displaying Events

**Using the Shortcode:**

```text
[sec_events]
```

**Shortcode Parameters:**

- `posts_per_page` - Number of events to display initially (default: 6)
- `category` - Filter by event category slug
- `show_past` - Show past events (default: 'no')
- `order` - Sort order (default: 'ASC')
- `orderby` - Sort by field (default: 'event_date')
- `show_time` - Display event times (default: 'yes')
- `show_excerpt` - Display event excerpts (default: 'yes')
- `show_location` - Display event locations (default: 'yes')
- `show_footer` - Display read more links (default: 'yes')

**Examples:**

```text
[sec_events posts_per_page="9"]
[sec_events category="workshops"]
[sec_events show_past="yes"]
[sec_events show_time="no" show_location="no"]
```

### Archive Pages

Events automatically appear on:

- `/events/` (main events archive)
- `/events/category/category-name/` (category archives)

## Event Fields

Each event includes these fields, edited in the native **Event Details** meta box (`includes/class-meta-box.php`) and stored as plain post meta:

| Field                  | Input       | Required        | Description                                                                |
| ---------------------- | ----------- | --------------- | -------------------------------------------------------------------------- |
| Event Date             | Date        | Yes             | When the event takes place                                                 |
| Event Start Time       | Time        | Recommended     | Event start time                                                           |
| Event End Time         | Time        | No              | Event end time                                                             |
| Event Location         | Text        | No              | Where the event takes place                                                |
| This event repeats     | Checkbox    | No              | Turns the event into a recurring series                                    |
| Repeat Every           | Number      | Conditional     | Repeat interval (e.g., every **2** weeks); shown when *repeats* is checked |
| Frequency              | Select      | Conditional     | `daily` / `weekly` / `monthly` / `yearly`                                  |
| Ends                   | Select      | Conditional     | `never` / `count` / `until`                                                |
| Number of Occurrences  | Number      | Conditional     | Total occurrences (including the first event); shown when *Ends = count*   |
| End Date               | Date        | When *until*    | Final date a recurrence may fall on; if left blank, *Ends* falls back to *never* |

## Admin Features

- **Event Status Filtering**: View All, Upcoming, Today's, or Past events
- **Smart Columns**: Event thumbnail, date, time, location, categories, and a **Series** indicator (parents show *e.g. "Weekly series (+12)"*; children show *"Occurrence #N (parent)"*)
- **Sortable Interface**: Click column headers to sort by date, time, or location
- **Quick Edit**: Fast editing of event details
- **Category Management**: Organize events with categories
- **Series Edit Scope**: When editing an occurrence in a recurring series, a sidebar metabox lets you choose whether changes apply to just this occurrence, this and future occurrences, or the whole series
- **Trash-safe Cascade**: Trashing a parent cascades to its children; restoring the parent restores the children that were cascade-trashed with it (children individually trashed by the user before the parent operation stay in trash)
- **Duplicate Prevention**: Admin columns prevent duplicate content display
- **Native Event Details**: Date/time/location and recurrence edited in a built-in meta box — no external field plugin

## Responsive Breakpoints

| Screen Size           | Columns | Gap Size |
| --------------------- | ------- | -------- |
| Large (1367px+)       | 3       | 80px     |
| Laptop (769px-1366px) | 3       | 40px     |
| Tablet (481px-768px)  | 2       | 30px     |
| Mobile (≤480px)       | 1       | 30px     |

## Browser Support

- ✅ Chrome (latest 2 versions)
- ✅ Firefox (latest 2 versions)
- ✅ Safari (latest 2 versions)
- ✅ Edge (latest 2 versions)
- ✅ Mobile browsers (iOS Safari, Chrome Mobile)

## Accessibility

- WCAG 2.1 AA compliant
- Keyboard navigation support
- Screen reader friendly
- High contrast mode support
- Reduced motion respect

## Internationalization

The plugin supports multiple languages out of the box:

- **English** (default)
- **Spanish** (es_ES)
- **French** (fr_FR)

Translation files are located in the `languages/` directory. The plugin uses WordPress's standard translation system and is ready for additional translations.

## Development

### Build System

The plugin includes a modern build system for SCSS compilation and development workflow:

**Available npm scripts:**

```bash
# Development builds (expanded CSS with source maps)
npm run dev
npm run build:css:dev
npm run watch          # Watch SCSS files for changes
npm run watch:css      # Alternative watcher using Sass

# Production builds (minified CSS)
npm run build
npm run build:css

# Linting
npm run lint:css       # Lint SCSS files
npm run lint           # Lint both JS and CSS

# Distribution
npm run dist           # Create distribution folder
npm run zip            # Create plugin zip file
```

**SCSS Development:**

- Source files: `src/css/`
- Compiled output: `assets/css/`
- Supports source maps for debugging
- Automatic vendor prefixing
- CSS linting with stylelint

**JavaScript Development:**

- JavaScript files are maintained directly in `assets/js/`
- Written in modern **ES2023** syntax that runs natively in current browsers — no build, bundle, or transpile step
- Files are ready for production use

### File Structure

```text
simple-events-calendar/
├── simple-events-calendar.php         # Main plugin file
├── package.json                       # npm dependencies and scripts
├── .stylelintrc.json                  # CSS linting configuration
├── readme.txt                         # WordPress.org readme
├── CHANGELOG.md                       # Version history (Keep a Changelog format)
├── CLAUDE.md                          # Architectural guide for AI assistants
├── phpcs.xml                          # WordPress Coding Standards ruleset
├── assets/
│   ├── css/
│   │   ├── simple-events.css          # Compiled styles (production)
│   │   └── simple-events.css.map      # Source map (development)
│   └── js/
│       ├── simple-events.js           # Main JavaScript (infinite scroll)
│       ├── simple-events-admin.js     # Edit-screen conditional logic
│       ├── simple-events-settings.js  # Settings page date-format toggle
│       └── simple-events-shortcode.js # Shortcode-specific JS
├── includes/
│   ├── class-admin-columns.php        # Admin list-table columns + Series indicator
│   ├── class-ajax.php                 # AJAX infinite-scroll handler
│   ├── class-main.php                 # Main plugin class + bootstrap + cron registration
│   ├── class-meta-box.php             # Native Event Details meta box
│   ├── class-post-type.php            # Post type + taxonomy registration + single-page schema
│   ├── class-renderer.php             # Shared element renderer + [sec_event_*] shortcodes
│   ├── class-settings.php             # Settings page (simple_events_settings)
│   ├── class-shortcode.php            # [sec_events] shortcode + transient cache
│   ├── class-templates.php            # Default single/archive/taxonomy templates
│   ├── class-recurrence.php           # Recurring-events engine (v4.4.0+)
│   ├── elementor/                     # Elementor widgets + dynamic tags (optional)
│   └── functions.php                  # Utility + display/schema + series helpers
├── languages/                         # Translation files
├── src/                               # Source files for build system
│   └── css/                           # SCSS source files
├── templates/                         # Default front-end templates (theme-overridable)
├── template-parts/
│   └── content-event-card.php         # Event card template
├── dist/                              # Distribution folder (generated)
└── node_modules/                      # npm dependencies (generated)
```

### Hooks & Filters

The recurrence engine (v4.4.0+) is the first subsystem in the plugin to expose public extensibility filters. Apply them like any other WordPress filter — for example, in your theme's `functions.php` or a small mu-plugin.

**Filters (all applied in `includes/class-recurrence.php`):**

| Filter                                         | Default | Purpose                                                                                                                       |
| ---------------------------------------------- | ------- | ----------------------------------------------------------------------------------------------------------------------------- |
| `sec_recur_max_occurrences`                    | `1000`  | Hard cap on per-series occurrence count.                                                                                      |
| `sec_recur_max_horizon_months`                 | `60`    | Hard cap on rolling-horizon generation depth (months past the parent's event date).                                           |
| `sec_recur_sync_batch_size`                    | `50`    | Occurrences created synchronously per save before the rest are queued via `wp_schedule_single_event`.                          |
| `sec_recur_horizon_refill_threshold_months`    | `6`     | Trigger window: if a "never" series' horizon is within this many months of today, the daily cron refills it.                  |
| `sec_recur_horizon_extend_months`              | `18`    | How far each refill advances the horizon (initial generation = `extend + refill_threshold` = 24 months by default).            |
| `sec_recur_copyable_field_keys`                | array   | Fields copied from parent → child on create and propagated on "future"/"series" edits. Default: title, content, excerpt, thumbnail, start/end time, location. |

**WP-Cron events scheduled:**

- `sec_recur_extend_horizon` (daily) — extends the horizon of any "never" series within the refill threshold
- `sec_recur_continue_generation` (one-shot) — resumes background creation of large series

Helper functions in `includes/functions.php`:

- `simple_events_is_series_parent( $post_id )`
- `simple_events_is_series_child( $post_id )`
- `simple_events_get_series_parent_id( $post_id )`

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for detailed version history.

## Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## Support

For support and bug reports, please:

1. Check the [Issues](https://github.com/Level-Up-Studios-LLC/simple-events-calendar/issues) page
2. Create a new issue with detailed information
3. Include WordPress and plugin version numbers

## License

This project is licensed under the GPL v2 or later - see the [LICENSE](LICENSE) file for details.

## Credits

Created by [Level Up Studios, LLC](https://www.levelupstudios.com/)

---

**Simply Events Calendar** - Making event management simple and beautiful. 🎉
