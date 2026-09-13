# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [v6.0.0] (2026-09-07)

### Changed

* **The plugin is now Simply Events Calendar.** One codebase now produces both
  the free build and the licensed Pro build of the same plugin, so there is one
  plugin to install and manage rather than two. Entering a license upgrades the
  plugin in place.
* **The plugin folder and main file are renamed** to `simply-events-calendar`.
  WordPress identifies a plugin by that path, so this release requires a manual
  changeover — see below. **No event data is affected.**
* Global constants `PLUGIN_DIR`, `PLUGIN_URL`, `PLUGIN_ASSETS`, and
  `PLUGIN_VERSION` are now `SIMPLE_EVENTS_*`. Anything reading them directly
  needs updating; they were unprefixed globals that could collide with any other
  plugin.

### Fixed

* **105 admin and front-end strings were untranslatable and now are not.** They
  passed the text domain as a PHP constant, and WordPress extracts translatable
  strings by reading literals out of the source, so those strings never reached
  the translation template in any locale. All 385 call sites now use a literal
  domain matching the plugin slug.
* Placeholder strings carry translator comments, "(s)" plural hedges are now
  real plural forms, and ambiguous single-word labels carry context — so
  translators can produce correct output rather than guesses.

### Added

* Licensing, updates, and optional usage analytics through Freemius. The opt-in
  is skippable; choosing "Skip" transmits nothing. See the External services
  section of readme.txt for exactly what is sent and when.

### Migration / compatibility

**Your data is safe.** Events are posts, their fields are post meta, categories
are terms, and settings are a single option. None of those names change in this
release, and deactivating a plugin never deletes them.

Because the plugin folder changed, WordPress treats this as a different plugin,
so the changeover is manual:

1. Go to **Events → Settings → Data** and confirm "Delete data on uninstall" is
   set to **No**. (No is the default.)
2. Back up your database anyway.
3. **Deactivate** the old plugin. Do not delete it yet — running both plugins at
   once fatals on duplicate class and post-type declarations.
4. Install and activate Simply Events Calendar.
5. Confirm your events, dates, categories, recurring series, and settings are
   all present.
6. Visit **Settings → Permalinks** once to flush rewrite rules.
7. Delete the old plugin.

For a zero-risk step 7, delete the old plugin's folder over SFTP or your host's
file manager instead of through the WordPress admin. Removing files directly
never fires the uninstall hook, which makes the delete-on-uninstall setting
irrelevant either way.

## [v5.3.0] (2026-06-11)

### Added (developer)

* **Extension hooks** so other plugins can extend the plugin without editing core files:
  * `simple_events_event_slug` (filter) — overrides the single-event permalink base (default `events`) in `Simple_Events_Post_Type::register_post_type()`.
  * `simple_events_settings_after_sections` (action, args: `$settings`) — fires inside the settings form before the submit button so add-ons can render their own `form-table` sections.
  * `simple_events_sanitize_settings` (filter, args: `$clean, $input, $previous`) — lets add-ons sanitize and persist their own setting keys (the core sanitizer otherwise drops unknown keys); `$previous` is the pre-save option so a changed value can be detected.
  * `simple_events_setting_defaults` (filter, args: `$defaults`) — register defaults for add-on setting keys.
  * `simple_events_pro_active` (filter, default `false`) — when a companion plugin returns `true`, every Pro upsell surface (the Settings/Documentation banner, the "Available in Pro" preview section, the "Upgrade to Pro" menu item and page, and their styles) is hidden.
* No user-facing or behavior changes — these are no-ops unless a companion plugin hooks them.

## [v5.2.0] (2026-06-11)

### Added

* **Pro upsell UI** (`includes/class-pro-upsell.php`, `Simple_Events_Pro_Upsell`) — an admin-only marketing surface advertising the forthcoming **Simple Events Calendar Pro**:
  * A dismissible CTA banner at the top of the **Events → Settings** and **Events → Documentation** pages (dismissal is per-user, stored in user meta).
  * An **"Available in Pro"** preview section on the Settings page showing disabled controls with **PRO** badges, sourced from a single filterable list (`simple_events_pro_features`).
  * An **"Upgrade to Pro"** submenu under the Events menu opening a feature/CTA landing page; the purchase URL is filterable via `simple_events_pro_url`.
  * Styles appended to the existing hand-written `assets/css/simple-events-admin.css`, enqueued only on the plugin's three admin screens (no leakage elsewhere). Writes no plugin data and never affects the front end.

### Notes

* This release adds **no** front-end behavior or data changes — it is purely the in-admin Pro upsell/marketing surface.

## [v5.1.0] (2026-06-08)

### Added

* **`[sec_event]` shortcode** — displays a single event by ID anywhere on the site. `id` (required) is the post ID; `layout` is `card` (default) or `list` (image-left). Also accepts `show_time`, `show_excerpt`, `show_location`, `show_footer`. Rendered via `simple_events_render_single_event()` in `includes/functions.php`.
* **Elementor Events Grid widget** (`sec-events-grid`) — standalone grid or image-left list of events; controls for layout, responsive column count, event count, category, order, show-past, and `show_*` toggles. An optional **"Load more on scroll"** (`data-sec-loadmore="1"`) enables infinite scroll off by default. The front-end script is registered in `register_assets()` so the widget can declare it via `get_script_depends()`.
* **Elementor Single Event widget** (`sec-single-event`) — renders one event chosen from a searchable list; `card` or `list` layout.
* **Weekly by-day recurrence** — new `event_repeat_byday` post meta (comma-separated weekday numbers, 0=Sun…6=Sat, weekly frequency only). Event Details shows a S–M–T–W–T–F–S day picker with **Weekdays / Weekend / Every day** presets. Live recurrence summary reads e.g. "Repeats every week on Mon, Wed, Fri". Implemented in `Simple_Events_Recurrence::compute_weekly_byday_dates()`.
* **Documentation page** (`includes/class-docs.php`, `Simple_Events_Docs`) — read-only Events → Documentation page listing all shortcodes and Elementor widgets and their usage contexts. Requires `edit_posts` capability; slug `simple-events-docs`.
* **Opt-in data deletion on uninstall** — new `delete_data_on_uninstall` setting (Events → Settings → Data, default `'no'`). Uninstall retains all events/categories/settings unless an admin explicitly opts in. Deletion never happens on deactivation.
* Shared render helpers `simple_events_render_events_grid( array $args )` and `simple_events_render_single_event( int $post_id, array $flags, string $layout )` added to `includes/functions.php`.
* **Redesigned single event page** (`templates/single-simple-events.php`): featured image + content with a sticky "Event Details" card (date / time / location / category pills) and an **Add to Calendar** button.
* **Add to Calendar (.ics)** — `includes/class-ics.php` (`Simple_Events_ICS`) streams a standards-compliant iCalendar file on `template_redirect` via `?sec_ical=<id>` (timed events emit UTC instants, time-less events are all-day; RFC 5545 escaped). Works with Apple Calendar, Outlook, and Google Calendar import.
* **Elementor Loop Grid query presets** — set a Loop Grid's *Query ID* to `sec_events_by_date` (orders by the `event_date` meta and follows the global "Show past events" setting) or `sec_events_by_date_all` (orders by event date, always includes past) instead of sorting by post date. Registered via `elementor/query/{id}` in `Simple_Events_Elementor`; the ASC/DESC direction follows the widget's own Order control.

### Changed

* **Event Details meta box** redesigned: sectioned layout with an accent bar, field icons, a "Recurring" pill, a live plain-English recurrence summary, and polished (padded/bordered) inputs. New admin stylesheet `assets/css/simple-events-admin.css` (hand-written, not part of the SCSS build).
* **Event cards** restyled: 5 px corner radius, softer/larger drop shadow, light-gray border, 22 px `<h3>` titles. Grid thumbnails changed from 4:3 to **3:2** aspect ratio. New **list layout** (`.sec-layout-list`) with featured image left and details right.
* **Date format setting** now uses radio-button presets + a Custom field (WordPress-General style); **Default sort order** setting now uses radio buttons. Storage unchanged — still single `date_format`/`order` strings in `simple_events_settings`.
* **Empty-state message** is now a built-in translatable string rendered by `Simple_Events_Renderer`; the editable empty-state settings fields were removed.
* **Per-element Elementor widgets** (Title/Image/Date/Time/Location/Excerpt/Content/Categories/Button) are gated to event-loop contexts (single-event templates, archives, Loop Grid bound to events). Outside those contexts they preview a sample event (`Simple_Events_Elementor::sample_event_id()`) in the editor and render nothing on the front end. The "Simple Events" widget category now sits just below "Basic".
* Front-end stylesheet/script registered for on-demand loading so the display widgets and `[sec_event]` work on any page.
* **Single & archive views now defer to Elementor Pro Theme Builder explicitly.** When a "Single" template (or "Archive" template) is assigned to events, that template displays the event(s); the plugin's default template is used only as a fallback. Detected via the Theme Builder conditions manager (`Simple_Events_Templates::elementor_has_location_template()`), not just filter priority.
* **Front-end and admin JavaScript modernized to ES2023.** The four hand-maintained scripts in `assets/js/` were rewritten with `const`/`let`, arrow functions, template literals, optional chaining / nullish coalescing, `Number.parseInt`/`Number.isNaN`, `Array.includes`, `Object.hasOwn`, and native NodeList iteration. No build/transpile step was added — the syntax stays within Baseline browser support. Behavior is unchanged; a stray debug `console.log` was removed from the shortcode script.

### Fixed

* AJAX "load more" cards were missing time, excerpt, location, and "Learn More" link — corrected a show-flag type mismatch between the AJAX path and the card template.
* Infinite scroll could fail to trigger when the user scrolled straight to the bottom and stopped (leading-edge throttle dropped the resting position) — now re-checks on the trailing edge.
* Elementor event-ID fallback guarded against term-ID collisions on taxonomy archives; weekday-picker checkboxes given full weekday names for screen readers; Events Grid columns now stack correctly to 2/1 on tablet/mobile.

### Migration / compatibility

* **Automatic legacy-slug migration.** Events created on older versions used the post type `events` and taxonomy `events-cat`; v5.x uses `simple-events` / `simple-events-cat`. On upgrade, a one-time, idempotent migration (`includes/class-migrations.php`, version-flagged via the `simple_events_db_version` option) renames them so existing events and their category assignments keep working. The post-type rename is scoped to posts carrying our `event_date` meta, so an unrelated `events` CPT from another plugin is left alone. **Importing** an old export is also handled — a `wp_import_post_data_raw` filter remaps each item's post type and category taxonomy as the WordPress Importer reads it (otherwise the importer rejects them with "Invalid post type events"). **Back up your database and test on staging before upgrading a production site.**
* **Tolerant time parsing.** Event times on installs upgraded from versions <= 4.4.0 may be stored as `H:i:s` (e.g. `14:30:00`) rather than the native `g:i a`. `simple_events_parse_time_of_day()` now reads `g:i a`, `H:i:s`, and `H:i`, so legacy times display correctly, populate the editor (no longer blanked — which previously risked erasing the time on re-save), and feed the `.ics`/schema output. New saves continue to normalize to `g:i a`. Event dates (`Ymd`) and locations need no migration.

## [v5.0.0] (2026-05-29)

### Removed

* **Third-party custom-fields plugin dependency.** The plugin is now fully self-contained. The `Requires Plugins` header, the dependency checks/auto-deactivation, the external field-group registration, and its local-JSON wiring have all been removed.

### Migration / compatibility

* **No data migration required.** Event values were always stored as plain post meta (`event_date` as `Ymd`, `event_start_time`/`event_end_time` as `g:i a`, `event_location`, and the `event_repeat_*` rule keys). Existing events created with earlier versions continue to work unchanged, and the previously required field plugin can be safely deactivated/removed.

### Added

* **Native "Event Details" meta box** (`includes/class-meta-box.php`) replacing the previous third-party editing UI. Reads/writes the identical meta keys and storage formats; recurrence inputs reproduce the previous conditional show/hide via `assets/js/simple-events-admin.js`. Saves at `save_post_simple-events` priority 10 so the recurrence engine (priority 30) still reads the persisted rule.
* **Settings page** under Events → Settings (`includes/class-settings.php`, option `simple_events_settings`): front-end date format (with live preview), 12/24-hour time, display defaults, empty-state copy, cache lifetime + "Clear cache now", "load more" batch size, recurrence limits (wired to the existing filters), and a schema.org JSON-LD toggle.
* **Shared element renderer** (`includes/class-renderer.php`) and **element shortcodes**: `[sec_event_title]`, `[sec_event_image]`, `[sec_event_date]`, `[sec_event_time]`, `[sec_event_location]`, `[sec_event_excerpt]`, `[sec_event_content]`, `[sec_event_categories]`, `[sec_event_button]`.
* **Default front-end templates** (`includes/class-templates.php` + `templates/`): single, archive, and category. Theme-overridable, and they defer to block (FSE) themes and Elementor Pro Theme Builder; disable via the `simple_events_use_default_template` filter. Archive navigation reuses the existing "load more" infinite scroll, now context-aware (category / past-events).
* **Elementor integration** (`includes/elementor/`): a "Simple Events" widget category with one widget per element, plus Dynamic Tags for binding native Elementor widgets to event fields. Loaded only when Elementor is active.
* **SEO**: schema.org `Event` JSON-LD is now emitted on every single event page (`wp_head`), in addition to the event cards.
* New display helpers in `includes/functions.php`: `simple_events_get_event_date()`, `simple_events_get_event_time()`, `simple_events_get_event_schema()`, `simple_events_get_setting()`, `simple_events_render_event_card()`.

### Changed

* All front-end/admin reads switched to native `get_post_meta()` / the new helpers. The front-end date format is now controlled by the settings page rather than the field plugin's return format.
* `[sec_events]` defaults now derive from the settings page; explicit attributes still override per instance.
* Uninstall now also deletes the `simple_events_settings` option.

## [v4.4.0] (2026-05-29)

### Added

* **Recurring events**: events can now repeat every N days, weeks, months, or years from the event edit screen. Recurrence settings live in the existing **Event Details** field group (`event_repeats`, `event_repeat_frequency`, `event_repeat_interval`, `event_repeat_end_type`, `event_repeat_count`, `event_repeat_until`) and use conditional logic to show/hide. `event_repeat_until` is **required at the field level** when "On a specific date" is selected, so a blank end date can't silently leave a stale series behind.
* End conditions: **after a number of occurrences**, **on a specific date**, or **never** (with a rolling horizon refilled daily via WP-Cron, capped at 60 months out per series).
* New `Simple_Events_Recurrence` class manages generation, edit-scope propagation, cascade hooks, and the horizon cron. Wired into `Simple_Events_Calendar::load_components()` and exposed at `simple_events_calendar()->recurrence`.
* Per-occurrence editing: when editing a child event, a sidebar **Series Edit Scope** metabox offers *only this occurrence*, *this and future occurrences*, or *entire series*. Edits are tracked per-field via `_sec_field_overrides` so a series-wide change of (e.g.) start time doesn't blow away a previously-customized title.
* **Future-scope edits are persisted as segments** on the parent (`_sec_recur_future_segments`), so children created later by async batching, horizon extension, or count increases inherit the edit instead of reverting to the parent's untouched values.
* New admin **Series** column on the events list table indicates parents (`Weekly series (+N)`) and children (`Occurrence #N (parent)`). Backed by a cached `_sec_recur_child_count` meta refreshed by `recount_children()` after every mutation, so the list table doesn't run a per-row count query.
* New helper functions in `includes/functions.php`: `simple_events_is_series_parent()`, `simple_events_is_series_child()`, `simple_events_get_series_parent_id()`.
* New public filters (the plugin's first extensibility hooks): `sec_recur_max_occurrences` (1000), `sec_recur_max_horizon_months` (60), `sec_recur_sync_batch_size` (50), `sec_recur_horizon_refill_threshold_months` (6), `sec_recur_horizon_extend_months` (18), `sec_recur_copyable_field_keys`.

### Storage model

Each occurrence is a real `simple-events` post with its own `event_date`, so the shortcode, archive, AJAX load-more, and admin filters require no changes. The parent post is occurrence #0; children carry `_sec_series_parent`, `_sec_series_occurrence_index`, and `_sec_field_overrides`. The rule itself is stored as `_sec_recur_*` post meta on the parent.

When `Simple_Events_Recurrence::regenerate_series()` updates an existing live child, it syncs **every** copyable parent field (`post_title` / `post_content` / `post_excerpt` / `_thumbnail_id` / `event_start_time` / `event_end_time` / `event_location`) that the child hasn't locally overridden — not just `event_date` — so editing the parent (e.g., changing the title) propagates to already-generated children. Generated children also explicitly strip any inherited recurrence meta (`event_repeats`, frequency, etc.) so each occurrence is a leaf, not a phantom series-parent.

### Behavioral notes

* **Disabling recurrence** on a saved series only force-deletes FUTURE unmodified live children. Past, edited (per-occurrence override), or trashed children get detached (series meta cleared) and survive as standalone events, so history isn't destroyed. An admin notice surfaces the counts on the next edit-screen load.
* **Trashing a parent** cascade-trashes its unmodified children (each marked with `_sec_recur_cascaded_trash`) and detaches modified ones; **restoring a parent from trash** restores only the children carrying that marker, so occurrences the user individually trashed before the parent operation stay in trash where they belong.
* **Force-deleting a parent** cascade-deletes unmodified children (including any sitting in trash) and detaches modified ones. **Force-deleting a child** records its index in `_sec_recur_skipped_indexes` on the parent so the next regeneration won't recreate it.
* **Trashed children survive regeneration**: if a parent regen runs while one of its children is in the trash, the diff pass leaves the trashed child alone (no event_date update, no duplicate replacement) so restoring the child later brings it back into the series at its original state.
* **Large series** (more than `sec_recur_sync_batch_size`) are generated in the foreground up to the limit, then continued in 5-second-spaced batches via `wp_schedule_single_event('sec_recur_continue_generation')`. An admin notice on the parent edit screen reports progress. The `_sec_recur_horizon` for "never" series persists the **target** stop date (write-monotonic — never shrinks) so partial passes don't truncate the series to the first batch.
* **DST and month-end math**: date arithmetic uses `DateTimeImmutable` + `wp_timezone()` so daily/weekly intervals don't drift across DST transitions. Monthly recurrences are anchored to the parent's day-of-month and clamp to the last day of the target month when the original day doesn't exist there (Jan 31 → Feb 28 → Mar 31). Feb 29 yearly recurrences clamp to Feb 28 in non-leap years.
* **Concurrency**: a per-parent **atomic option-row lock** (`sec_recur_lock_<parent_id>` acquired via `add_option(..., '', 'no')` so the options-table INSERT IGNORE provides atomicity across concurrent requests) plus a `$GLOBALS['sec_generating_series']` recursion guard prevent concurrent regenerations from double-creating occurrences and prevent each cascaded child's save / delete from re-entering the parent's handler. A stale-lock check (older than 60 s) clears and re-acquires once to recover from processes that died mid-flight.
* **Child-count cache is fresh**: refreshed via `trashed_post` / `deleted_post` (post-action) instead of `wp_trash_post` / `before_delete_post` (pre-action), so the Series admin column never shows a stale +1 after individual deletions or trashes.

### Changed

* Bumped minimum tested WordPress version metadata to track the new release.

## [v4.3.1] (2026-04-22)

### Security

* Converted AJAX `load_more_events` endpoint to `wp_send_json_success` / `wp_send_json_error` with a `{ html, has_more }` payload; JS updated in lockstep to consume the JSON shape and surface server error messages
* Added `wp_unslash` + `sanitize_text_field` to nonce and admin `$_GET` reads (`event_status`, `simple-events-cat`)
* Whitelist-validated the admin `event_status` filter value before it reaches the query
* Escaped all `<option>` output in admin filter dropdowns

### Fixed

* Fixed asset cache-busting: `$version` was hardcoded to `3.0.0` while `PLUGIN_VERSION` was `4.3.0`, so browsers served stale CSS/JS after upgrades
* Fixed `modify_archive_query` meta_query merge: existing `relation` keys and nested clauses from other plugins are now preserved by nesting under a fresh `AND` wrapper instead of flattening with `array_merge`
* Guarded `init()` against double-execution (it hooked both `plugins_loaded` and a second init action) to prevent duplicate hook registrations
* Moved `load_plugin_textdomain` to the `init` hook to silence the WordPress 6.7+ `_doing_it_wrong` notice
* AJAX-loaded event cards now render the footer "Learn More" link to match the shortcode output

### Changed

* Shortcode transient cache key now mixes in `PLUGIN_VERSION` and `is_user_logged_in()` so caches auto-invalidate on upgrade and admin/anon variations stay isolated
* Introduced `SIMPLE_EVENTS_NONCE_ACTION` constant to replace the hardcoded nonce string across call sites (existing nonce value preserved for upgrade safety)
* Extracted duplicated fallback event-card markup into `simple_events_render_fallback_card()` shared between the shortcode and AJAX handlers

### Removed

* Removed the empty `render_load_more_hint()` method stub

## [v4.3.0] (2024-09-22)

### Added

* **Multilingual Support**: Added complete translation support for Spanish (es_ES) and French (fr_FR)
* Added professional-quality translations for all plugin strings
* Added .po and .mo files for Spanish and French languages
* Added internationalization documentation and setup guide

### Changed

* Updated .pot file with current version and creation date
* Enhanced plugin description to highlight multilingual capabilities
* Updated documentation to include translation information

## [v4.2.4] (2024-09-22)

### Added

* Added SCSS build system with Sass compiler and stylelint
* Added development and production CSS build scripts (`npm run build:css`, `npm run build:css:dev`)
* Added file watching capabilities (`npm run watch`, `npm run watch:css`)
* Added CSS linting with stylelint and standard SCSS configuration
* Added automatic version synchronization between plugin header, constants, and package.json

### Changed

* **BREAKING**: Updated shortcode from `[simple_events_calendar]` to `[sec_events]` for consistency
* Improved responsive design with better media query organization
* Enhanced theme color inheritance for better integration with WordPress themes
* Refactored CSS build process from simple file copying to proper SCSS compilation
* Updated build system to generate both compressed (production) and expanded (development) CSS
* Improved accessibility features and reduced motion support
* Updated package.json version management and build scripts

### Fixed

* Fixed plugin description to clearly specify the custom-fields plugin requirement
* Improved WordPress version compatibility requirements
* Enhanced color definitions and margin adjustments for better layout consistency
* Fixed media query structure for improved readability and maintainability

### Development

* Added source maps for development builds
* Added proper CSS minification for production builds
* Added automated distribution and zip creation process
* Enhanced .claude-instructions with comprehensive version management guidelines

## [v4.1.1] (2024-09-22)

### Removed

* Removed "Showing current and upcoming events only." message from shortcode display
* Removed scroll hint bar "📜 Scroll down to see more events..." from after events
* Events now load automatically on scroll without instructional messages

## [v4.1.0] (2024-09-22)

### Improved

* Enhanced dependency error message for better user experience
* Error message now names the required plugin and states that it must be installed and activated
* Added a direct download-link button for the required plugin to the WordPress plugin installer
* Cleaner, more actionable error messages for missing dependencies

## [v4.0.3] (2024-09-22)

### Fixed

* Fixed duplicate content appearing in admin columns (thumbnail, date, time, location)
* Added static tracking to prevent `fill_columns` method from processing same column/post multiple times
* Eliminated stacked duplicate content within individual admin columns

## [v4.0.2] (2024-09-22)

### Fixed

* Fixed duplicate admin columns by completely overriding WordPress default columns
* Prevented WordPress from showing automatic thumbnail, content, and excerpt columns
* Admin columns now display only custom event-specific columns without duplicates

## [v4.0.1] (2024-09-22)

### Fixed

* Fixed duplicate thumbnail display in admin columns by removing default WordPress thumbnail column
* Admin now shows only custom event thumbnail without WordPress default thumbnail

## [v4.0.0] (2024-09-22)

### Added

* Updated WordPress minimum requirement to 6.2+ for better block editor support
* Updated PHP minimum requirement to 8.0+ (PHP 7.4 is end-of-life)
* Added build system with `npm run dist` and `npm run zip` commands
* Added compressed file exclusions to .gitignore
* Added automated semantic versioning instructions

### Changed

* **BREAKING**: Increased minimum WordPress and PHP requirements
* Refactored plugin architecture with proper class-based structure
* Improved admin columns functionality with better duplicate prevention

## [v3.0.0] (2024-08-20)

### Added

* Responsive grid layout with device-specific columns (3 cols desktop, 2 cols tablet, 1 col mobile)
* Custom gap spacing for different screen sizes (80px large displays, 40px laptops, 30px tablets/mobile)
* 4:3 aspect ratio for featured images with responsive design
* Event status filtering in admin (All Events, Upcoming, Today's Events, Past Events)
* Location field with visual indicators and proper styling
* Comprehensive dependency checking with detailed error messages
* Scroll hints and loading animations for better user experience
* Friendly "no more events" message with encouraging text and emojis
* Enhanced event card design with hover effects and modern styling
* Meta information display with styled date badges and time indicators

### Changed

* Updated initial event display from 9 to 6 events with 6-event loading increments
* Improved infinite scroll with better error handling and user feedback
* Enhanced admin interface with better column management and sorting (always ASC by date)
* Event cards now use flexbox layout for consistent heights across grid
* Date format standardized to 'Ymd' for reliable database comparisons
* Archive pages now automatically filter out past events (frontend only)
* Field registration moved to programmatic creation via PHP
* Improved responsive design with device-specific adjustments
* Better accessibility with focus states and reduced motion support

### Fixed

* Infinite scroll error handling - now shows proper "no more events" message instead of server errors
* Past events filtering - properly hides past events on frontend while keeping them accessible in admin
* Date comparison issues by using WordPress timezone (`current_time()`) instead of server time
* Field-plugin detection reliability
* Event ordering consistency - all queries now use ASC order by event date
* Template fallback handling when event card template is missing

### Removed

* All debugging code and console logging from production files
* Dependency on JSON field group files (now uses PHP registration)
* Unnecessary caching that could interfere with real-time event filtering
* Legacy bundled-dependency code and restrictive activation logic

## [v2.1.2] (2024-05-15)

### Changed

- Updated the meta WP query from the shortcode and ajax file to display future events by just their date and not including their end time.

## [v2.1.1] (2024-05-15)

### Fixed

- Fixed an issue with the TIME meta query from the shortcode ajax files that caused the events not to display.
- Removed a var_dump PHP function that was forgotten on the shortcode file.

## [v2.1.0] (2024-05-06)

### Added

- Added CHANGELOG.md

### Changed

- Updated the WP_QUERY arguments to display events by future date and before their end time, if provided.
- Made some adjustments to the core files.
- Updated the required PHP version to 7.4 from 8.1
- Updated the required WordPress version to 6.0 from 6.2

### Removed

- Removed transient cache code.

## v2.0.0 (2024-05-02)

### Added

- Added LICENSE
- Added README.md
- Added .gitignore

### Changed

- Updated CSS file
- Updated the "No more events" message from the `simple-events-shortcode.php` file.

[v5.1.0]: https://github.com/Level-Up-Studios-LLC/simple-events-calendar/compare/v5.0.0...v5.1.0
[v4.4.0]: https://github.com/Level-Up-Studios-LLC/simple-events-calendar/compare/v4.3.1...v4.4.0
[v4.3.1]: https://github.com/Level-Up-Studios-LLC/simple-events-calendar/compare/v4.3.0...v4.3.1
[v4.3.0]: https://github.com/Level-Up-Studios-LLC/simple-events-calendar/compare/v4.2.4...v4.3.0
[v4.2.4]: https://github.com/Level-Up-Studios-LLC/simple-events-calendar/compare/v4.1.1...v4.2.4
[v4.1.1]: https://github.com/Level-Up-Studios-LLC/simple-events-calendar/compare/v4.1.0...v4.1.1
[v4.1.0]: https://github.com/Level-Up-Studios-LLC/simple-events-calendar/compare/v4.0.3...v4.1.0
[v4.0.3]: https://github.com/Level-Up-Studios-LLC/simple-events-calendar/compare/v4.0.2...v4.0.3
[v4.0.2]: https://github.com/Level-Up-Studios-LLC/simple-events-calendar/compare/v4.0.1...v4.0.2
[v4.0.1]: https://github.com/Level-Up-Studios-LLC/simple-events-calendar/compare/v4.0.0...v4.0.1
[v4.0.0]: https://github.com/Level-Up-Studios-LLC/simple-events-calendar/compare/v3.0.0...v4.0.0
[v3.0.0]: https://github.com/Level-Up-Studios-LLC/simple-events-calendar/compare/v2.1.2...v3.0.0
[v2.1.2]: https://github.com/Level-Up-Studios-LLC/simple-events-calendar/compare/v2.1.1...v2.1.2
[v2.1.1]: https://github.com/Level-Up-Studios-LLC/simple-events-calendar/compare/v2.1.0...v2.1.1
[v2.1.0]: https://github.com/Level-Up-Studios-LLC/simple-events-calendar/releases/tag/v2.1.0
