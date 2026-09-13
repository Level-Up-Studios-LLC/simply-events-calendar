# AGENTS.md

This file provides guidance to Codex (Codex.ai/code) when working with code in this repository.

## Project Overview

Simple Events Calendar is a WordPress plugin (v5.2.0) that registers a `simple-events` custom post type and renders events via the `[sec_events]` shortcode, per-element shortcodes, post-type archives, and default templates. It is **fully self-contained** — event fields are edited through a native meta box (`includes/class-meta-box.php`) and stored as plain post meta, with no third-party plugin dependency.

- PHP 7.4+, WordPress 6.0+
- Text domain: `simple_events`
- No automated test suite exists.

## Common Commands

```bash
# CSS build (SCSS is the source of truth; CSS is compiled)
npm run dev           # expanded CSS + source maps
npm run watch         # chokidar-based watcher (runs build:css:dev on change)
npm run watch:css     # native sass --watch alternative
npm run build         # minified production CSS (no source map)

# Linting
npm run lint:css      # stylelint on src/css/**/*.scss
npm run lint          # runs lint:js (no-op) then lint:css

# Packaging
npm run dist          # builds a dist/ folder (uses Windows robocopy)
npm run zip           # produces simple-events-calendar.zip (uses python)
```

PHP code style is enforced via **phpcs.xml** (WordPress Coding Standards). Run with phpcs/phpcbf locally if installed — the ruleset prefixes are `simple_events`, `Simple_Events`, `PLUGIN_`, `SIMPLE_EVENTS_`.

`npm run dist` and `npm run zip` mix POSIX (`rm -rf`, `mkdir`) with Windows `robocopy` and a Python one-liner — they're meant to run in **Git Bash on Windows** and will not work as-is in cmd/PowerShell or on macOS/Linux.

## Architecture

### Entry point and bootstrap
`simple-events-calendar.php` defines plugin constants (`PLUGIN_DIR`, `PLUGIN_URL`, `PLUGIN_ASSETS`, `PLUGIN_VERSION`, `PLUGIN_TEXT_DOMAIN`, `SIMPLE_EVENTS_PLUGIN_FILE`) and calls `Simple_Events_Calendar::get_instance()` (singleton). All real work happens inside `includes/class-main.php`.

### Initialization order matters
`class-main.php` hooks `plugins_loaded` (priority 20) to `init()`, guarded by an `$initialized` flag so it runs once. `init()` calls `load_components()`; any new feature that depends on plugin state must not fire before `load_components()` has run.

`load_components()` is where the subsystem classes are `require_once`'d and instantiated, in this order:
1. `includes/functions.php` (shared helpers; loaded first — other classes may depend on it)
2. `class-post-type.php` → `Simple_Events_Post_Type` (registers `simple-events` CPT + `simple-events-cat` taxonomy; also emits single-page schema on `wp_head`)
3. `class-renderer.php` → `Simple_Events_Renderer` (shared element renderer + `[sec_event_*]` element shortcodes)
4. `class-shortcode.php` → `Simple_Events_Shortcode` (`[sec_events]` + transient caching)
5. `class-ajax.php` → `Simple_Events_Ajax` (infinite scroll handler)
6. `class-admin-columns.php` → `Simple_Events_Admin_Columns`
7. `class-meta-box.php` → `Simple_Events_Meta_Box` (native Event Details editing UI)
8. `class-settings.php` → `Simple_Events_Settings` (Events → Settings page + `simple_events_settings` option)
9. `class-docs.php` → `Simple_Events_Docs` (read-only Events → Documentation page; capability `edit_posts`, slug `simple-events-docs`)
10. `class-pro-upsell.php` → `Simple_Events_Pro_Upsell` (admin-only Pro upsell/marketing UI; new in v5.2.0)
11. `class-ics.php` → `Simple_Events_ICS` ("Add to Calendar" .ics generator; streams on `template_redirect` via `?sec_ical=<id>`)
12. `class-templates.php` → `Simple_Events_Templates` (default single/archive/taxonomy templates via `template_include`)
13. `class-recurrence.php` → `Simple_Events_Recurrence` (recurring-events engine)
14. `includes/elementor/class-elementor.php` → `Simple_Events_Elementor::init()` (no-op unless Elementor is active)

`includes/class-migrations.php` → `Simple_Events_Migrations` is also loaded (first) — a one-time, version-flagged DB migration runner (`init` priority 1).

All component instances hang off the main singleton (`$plugin->migrations`, `->post_type`, `->renderer`, `->shortcode`, `->ajax`, `->admin_columns`, `->meta_box`, `->settings`, `->docs`, `->pro_upsell`, `->ics`, `->templates`, `->recurrence`) — reach them via `simple_events_calendar()` rather than constructing new ones.

### Settings and the display helpers
All tunables live in one option array `simple_events_settings` (see `Simple_Events_Settings` + `simple_events_get_setting_defaults()`). Read settings via `simple_events_get_setting($key, $fallback)`. The front-end date/time format is a setting, so **never** read `event_date`/time meta raw for display — use `simple_events_get_event_date($id)` and `simple_events_get_event_time($id, $key)`, which convert the stored `Ymd` / `g:i a` values to the configured display format. Schema is built once by `simple_events_get_event_schema($id)` (returns null when the JSON-LD setting is off). Saving settings flushes the shortcode transients.

**v5.1.0 settings changes:**
- **Date format** is now chosen via a **radio-button list** — one radio per preset showing the example and its PHP format code (matching WordPress's General Settings date-format UI), plus a "Custom" radio that reveals a free-text field. Internally it is still stored as a single `date_format` PHP format string; `assets/js/simple-events-settings.js` handles toggling the custom input's visibility.
- **Default sort order** is now **radio buttons** (Ascending / Descending) instead of a dropdown. Storage is unchanged (single `order` string value in `simple_events_settings`).
- **Empty-state message** is now a hardcoded, translatable static string rendered by `Simple_Events_Renderer`. The editable empty-state settings fields were removed in v5.1.0 — no setting key exists for it.
- **`delete_data_on_uninstall`** (new in v5.1.0, default `'no'`) controls whether uninstall deletes plugin data. See the "Uninstall" section below.

**Documentation page** (`includes/class-docs.php`, `Simple_Events_Docs`): a read-only admin page at Events → Documentation that lists all shortcodes (`[sec_events]`, `[sec_event]`, element shortcodes) and Elementor widgets with their usage contexts. Registered on `admin_menu` with the `edit_posts` capability, slug `simple-events-docs`. It has no settings form and writes nothing to the database.

### Pro upsell UI and freemium model (v5.2.0)
`includes/class-pro-upsell.php` (`Simple_Events_Pro_Upsell`) is an **admin-only marketing surface** for the forthcoming commercial Pro version. It writes no plugin data and never touches the front end. **Every surface is suppressed when Pro is active** — each entry point checks `is_pro_active()`, which reads the `simple_events_pro_active` filter (see "Extension hooks"). Pieces:
- **CTA banner** — `Simple_Events_Pro_Upsell::banner()` (static) is echoed by `Simple_Events_Settings::render_page()` and `Simple_Events_Docs::render_page()` right after their `<h1>`. Dismissal is **per-user**: a nonce-protected link sets the `sec_pro_banner_dismissed` user meta via `maybe_dismiss_banner()` on `admin_init`, after which `banner()` returns early for that user.
- **"Available in Pro" preview** — `locked_section()` (static, called near the end of the Settings page) renders a `form-table` of disabled controls with `PRO` badges.
- **Upgrade page** — an Events submenu (slug `simple-events-upgrade`, `manage_options`) rendering a feature grid + CTA.
- **Single sources of truth / filters:** the teaser list comes from `pro_features()` (filter `simple_events_pro_features`); the purchase URL from `pro_url()` (filter `simple_events_pro_url`).
- **Assets:** styles live in the existing hand-written `assets/css/simple-events-admin.css` (not part of the SCSS build) and are enqueued by `enqueue()` **only** on the three plugin screens (`simple-events_page_{settings,docs,upgrade}`) — keep that gate; do not enqueue globally. The "Upgrade to Pro" menu item is highlighted purely via CSS targeting `#adminmenu a[href*="simple-events-upgrade"]`.

### Extension hooks (for companion plugins)
Generic hooks that let a separate plugin extend the core without editing these files. Treat them as a stable public API — keep their names and signatures (added v5.3.0; the recurrence `sec_recur_*` filters predate them):
- `simple_events_event_slug` (filter, default `'events'`) — the single-event permalink base, applied in `Simple_Events_Post_Type::register_post_type()`. A companion plugin returning a different slug changes the base; it owns flushing rewrite rules when its value changes (the core does not flush on its own).
- `simple_events_settings_after_sections` (action, arg `$settings`) — fires inside the settings `<form>` in `Simple_Events_Settings::render_page()`, after the built-in sections and before `submit_button()`. Echo additional `form-table` sections here.
- `simple_events_sanitize_settings` (filter, args `$clean, $input, $previous`) — the **only** way to persist a setting key the core doesn't know about: `Simple_Events_Settings::sanitize()` builds `$clean` from known keys and drops the rest, then passes it through this filter before save. `$previous` is the pre-save option (array or `false`) for change detection.
- `simple_events_setting_defaults` (filter, arg `$defaults`) — register default values for added setting keys so `simple_events_get_setting()` resolves them consistently.
- `simple_events_pro_active` (filter, default `false`) — a companion plugin returns `true` to declare itself active; `Simple_Events_Pro_Upsell::is_pro_active()` reads it and every upsell surface (banner, locked preview section, Upgrade menu/page, upsell styles) no-ops. New upsell surfaces must check `is_pro_active()` too.

### Native fields and storage formats (critical)
The meta box writes times as `g:i a`, but **reads** them tolerantly via `simple_events_parse_time_of_day()` (accepts `g:i a`, `H:i:s`, `H:i`) — versions ≤ 4.4.0 stored 24-hour `H:i:s`, and this keeps them displaying, editing (the edit field no longer blanks → no time loss on re-save), and feeding the `.ics`/schema correctly; new saves normalize to `g:i a`. The meta box reads/writes the exact keys earlier versions used, so existing data is untouched: `event_date` = `Ymd`, `event_start_time`/`event_end_time` = `g:i a` (legacy `H:i:s` tolerated), `event_location` = text, and the rule keys `event_repeats` (int 1/0), `event_repeat_interval` (int), `event_repeat_frequency` (`daily|weekly|monthly|yearly`), `event_repeat_end_type` (`never|count|until`), `event_repeat_count` (int), `event_repeat_until` (`Ymd`). The meta box hooks `save_post_simple-events` at priority 10 (which fires before any `save_post`), so the recurrence engine at `save_post`:30 still reads the persisted rule. **The meta box bails when `$GLOBALS['sec_generating_series']` is set**, so generated children are never overwritten with the parent's submitted values.

### Element shortcodes, templates, Elementor
`Simple_Events_Renderer` is the single source of truth for per-element HTML. The `[sec_event_*]` shortcodes, the Elementor widgets (`includes/elementor/widgets.php`), and the Dynamic Tags (`includes/elementor/dynamic-tags.php`) all call it — keep new element output there, not duplicated. Default templates (`templates/`) are a fallback only: `Simple_Events_Templates::template_include()` (priority 5) yields to theme files (`locate_template`), block/FSE themes (`wp_is_block_theme`), and **Elementor Pro Theme Builder** — it checks the Theme Builder conditions manager explicitly via `elementor_has_location_template('single'|'archive')` (guarded; returns false without Elementor Pro) and defers when a Single/Archive template is assigned, so that template renders the event(s); it also runs before Elementor's own `template_include` filter. The `simple_events_use_default_template` filter disables the defaults.

**v5.1.0 Elementor additions (in `includes/elementor/display-widgets.php`):**

- **Events Grid widget** (`sec-events-grid`): Standalone grid or image-left list of events. Controls include layout (grid/list, `render_type=template` so the editor re-renders on switch), column count (a single **non-responsive** value that sets the `--sec-columns` CSS custom property; the SCSS still stacks to 2 columns on tablet and 1 on mobile regardless), event count, category filter, order, show-past toggle, the `show_*` toggles, and an optional **Load more on scroll** toggle (off by default). When load-more is on the container emits `data-sec-loadmore="1"` plus the `data-*` query context and the widget pulls in the front-end script via `get_script_depends()`. Rendering is delegated to `simple_events_render_events_grid()` in `includes/functions.php`.

- **Single Event widget** (`sec-single-event`): Renders one event chosen via a searchable SELECT2 picker (searches by post title). Supports `card` (default) or `list` layout plus the standard `show_*` toggles. Rendering is delegated to `simple_events_render_single_event()` in `includes/functions.php`.

- **Per-element widgets (Title/Image/Date/Time/Location/Excerpt/Content/Categories/Button — in `includes/elementor/widgets.php`) are gated to event-loop contexts.** The per-widget "Preview event" picker was removed in v5.1.0. They render the current loop/queried event (single event page, archive, Elementor Loop Grid item, Theme Builder single template). Out of context: in the Elementor **editor** they preview the actual element using a sample event (`Simple_Events_Elementor::sample_event_id()`) so the user sees real output; on the **front end** they output nothing. `resolve_event_id()` only trusts `get_queried_object()` when it is a `WP_Post` (a term ID on a taxonomy archive must not collide with a post ID).

- **Widget category placement:** `register_category()` adds the "Simple Events" category, then `move_category_after()` reorders Elementor's private categories array (via reflection, fully guarded) so it sits just below the "Basic" category in the panel.

- **Loop Grid query presets:** `init()` registers `elementor/query/sec_events_by_date` and `elementor/query/sec_events_by_date_all` (handlers `query_events_by_date()` / `query_events_by_date_all()` → `apply_event_date_order()`). Setting a Loop Grid's *Query ID* to one of these orders the loop by the `event_date` meta (`meta_value` / `meta_type=DATE`) instead of post date. `sec_events_by_date` honors the global `show_past` setting (adds the upcoming-only `event_date >= today` clause unless `show_past` is `'yes'`) — so the one Settings toggle governs past-event visibility across the shortcode, archives, Events Grid widget, AND the Loop Grid. `sec_events_by_date_all` always includes past events (explicit override). The upcoming-only clause is nested under `AND` to preserve any existing meta_query; direction is left to the widget's Order control. These are plain WP actions, harmless when Elementor is absent (they simply never fire). Elementor's Loop Grid has no UI option to order by a meta key, which is why this is needed.

**Shared render helpers** — `simple_events_render_events_grid( array $args )` (returns the grid/list HTML string) and `simple_events_render_single_event( int $post_id, array $flags, string $layout )` (returns the single-event HTML string) live in `includes/functions.php` and are the single source for the display widgets and the `[sec_event]` shortcode. Keep rendering logic there, not duplicated in widget `render()` methods.

### CPT and archive query
The post type slug is `simple-events` and the taxonomy is `simple-events-cat`. **Front-end rewrite slugs differ from internal names**: posts live under `/events/...` and taxonomy archives under `/event-category/...` (see `class-post-type.php`). REST bases are `simple-events` and `simple-events-categories`.

**Legacy slug migration:** much older versions registered the CPT as `events` and the taxonomy as `events-cat`. `Simple_Events_Migrations` (DB version 1) renames them to `simple-events` / `simple-events-cat` on upgrade — idempotent, scoped to posts carrying `event_date` meta (so a foreign `events` CPT isn't touched), preserving category assignments (term relationships key off `term_taxonomy_id`). Bump `Simple_Events_Migrations::CURRENT_VERSION` and add a guarded step for any future data migration. Importing an old WXR export is handled separately by `remap_imported_post()` on the `wp_import_post_data_raw` filter (the importer validates `post_type_exists()` before inserting, so the in-DB migration can't help an import) — it rewrites each item's post type / category taxonomy to the current slugs as it's read.

`modify_archive_query()` in `class-main.php` hooks `pre_get_posts` on the front-end main query and forces:
- `orderby = meta_value`, `meta_key = event_date`, `meta_type = DATE`; `order` comes from the `order` setting (ASC/DESC)
- `posts_per_page` is set to the `load_increment` setting so the first batch lines up with "load more" offsets
- unless the `show_past` setting is `yes`, a `meta_query` filter hides events where `event_date < current_time('Ymd')`
- If a `meta_query` already exists, it is **nested under an `AND` relation** rather than merged — preserve this pattern when adding more filters.

Any new archive-facing query must go through the same pattern (the `event_date` post meta, `Ymd` format) or it will not sort/filter consistently with the rest of the plugin.

### Asset enqueue gating
`enqueue_scripts()` only enqueues CSS/JS when the current request is a `simple-events` archive/single/taxonomy, a post containing the `[sec_events]` shortcode, or a text widget. Test that this gate still holds when adding new rendering paths — silently enqueueing everywhere is a regression. The front-end stylesheet handle `simple-events-style` is registered on `wp_enqueue_scripts` (priority 1, in `register_assets()`) and enqueued on demand: the shortcode class also enqueues it when the post contains `[sec_event]` (single-event shortcode), and the Elementor Events Grid and Single Event widgets pull it in via `get_style_depends()`. The infinite-scroll script `simple-events-script` (with `ajax_params` localized) is registered there too, so the Events Grid widget can declare it via `get_script_depends()`. The load-more JS only builds controllers for containers carrying `data-sec-loadmore="1"` — the `[sec_events]` shortcode, the archive/taxonomy templates, and the Events Grid widget when its toggle is on — so single-event cards and fixed grids are never hijacked.

`wp_localize_script` exposes `ajax_params` (`ajaxurl`, `nonce`, `initial_offset`, `load_increment`) to the infinite-scroll JS. `initial_offset` and `load_increment` both come from the `load_increment` setting (default 6). The nonce action string lives in the `SIMPLE_EVENTS_NONCE_ACTION` constant (defined in the main plugin file as `'load_more_events_nonce'`) — use it everywhere, never hardcode the string. Changing any of these keys requires updating `assets/js/simple-events.js` in lockstep.

The infinite-scroll JS reads the listing **context from the container's data attributes** (`data-offset`, `data-category`, `data-show-past`, `data-show-*`) and sends them with each request, so "load more" continues the correct query (e.g. stays within a category archive). The shortcode container and the archive/taxonomy templates both set these.

The AJAX handler is registered under the `load_more_events` action (priv + nopriv), returns `wp_send_json_success({ html, has_more })` on success and `wp_send_json_error({ message }, status)` on failure. The JS consumes `response.data.html` / `response.data.has_more`. Don't regress this to bare-string responses. The handler's `posts_per_page` comes from the `load_increment` setting and `offset` is capped at 10000 (see `class-ajax.php`). `modify_archive_query()` also sets the archive main query's `posts_per_page` to `load_increment` so the first batch lines up with the load-more offsets.

### Shortcode and its caching
`[sec_events]` accepts these attributes (all optional, defaults shown):
`posts_per_page=6` (clamped 1–50), `category=""` (taxonomy slug), `show_past="no"`, `order="ASC"`, `orderby="event_date"`, `show_time="yes"`, `show_excerpt="yes"`, `show_location="yes"`, `show_footer="yes"`.

Attribute **defaults derive from the settings page** (`posts_per_page`, `show_past`, `order`, `show_*`); an explicit shortcode attribute still overrides per instance.

`Simple_Events_Shortcode::render_shortcode()` caches rendered output in a transient for the `cache_ttl` setting (default 15 minutes), keyed on `md5(serialize($sanitized_atts) . '|' . PLUGIN_VERSION . '|' . is_user_logged_in())`. The version segment auto-invalidates the cache on plugin upgrade; the login-state segment prevents admin-only variations from leaking to anonymous visitors. **Empty-state output (containing `simple-events-no-events`) is intentionally not cached**, so adding new "no results" markup must keep that class to avoid pinning empty results.

Cache is invalidated via `save_post`, `delete_post`, and `transition_post_status` — but **only when the post being changed is a `simple-events` post**. If new logic causes the shortcode output to depend on other post types/terms/options, extend the invalidation hooks accordingly.

**`[sec_event]` shortcode (new in v5.1.0):** Displays a single event by ID. `id` (required) is the post ID of the `simple-events` post. `layout` is `"card"` (default, renders as an event card) or `"list"` (image-left list layout). Also accepts `show_time`, `show_excerpt`, `show_location`, and `show_footer` (all default to the corresponding setting value). Rendered via `simple_events_render_single_event()` in `includes/functions.php`. This shortcode is NOT cached (single-item output is cheap and already covered by WordPress object cache).

### Event fields
Event fields are registered and persisted entirely by `Simple_Events_Meta_Box`. The fields are plain post meta:

| Meta key                 | Input    | Stored format               | Required                |
| ------------------------ | -------- | --------------------------- | ----------------------- |
| `event_date`             | date     | `Ymd`                       | Yes                     |
| `event_start_time`       | time     | `g:i a`                     | Recommended             |
| `event_end_time`         | time     | `g:i a`                     | No                      |
| `event_location`         | text     | string (≤255)               | No                      |
| `event_repeats`          | checkbox | int `1`/`0`                 | No                      |
| `event_repeat_interval`  | number   | int                         | When repeating          |
| `event_repeat_frequency` | select   | `daily/weekly/monthly/yearly` | When repeating        |
| `event_repeat_end_type`  | select   | `never/count/until`         | When repeating          |
| `event_repeat_count`     | number   | int                         | When `end_type=count`   |
| `event_repeat_until`     | date     | `Ymd`                       | When `end_type=until`   |
| `event_repeat_byday`     | checkboxes | comma-separated `w` ints (`0`=Sun … `6`=Sat) | Weekly only; absent = plain weekly |

When "Ends on a date" is selected but the date is blank/invalid, `save()` falls back to `end_type = never` so `read_rule()` never rejects the rule (recurrence keeps generating on its rolling horizon). To add a field: render an input in `Simple_Events_Meta_Box::render()`, sanitize + persist it in `save()`, add a display path via `Simple_Events_Renderer` (and optionally a `[sec_event_*]` shortcode / Elementor widget), and update `simple_events_get_event_schema()` if it should affect SEO.

### Admin columns and filters
`Simple_Events_Admin_Columns` adds Thumbnail / Event Date / Time / Location / Categories columns to the `simple-events` list table, plus two filter dropdowns: a category filter (uses the taxonomy query var `simple-events-cat`) and a status filter (custom query var `event_status` with values `upcoming`, `today`, `past`). When adding new admin filters, follow the same `parse_query` + meta_query pattern.

### Uninstall (opt-in data deletion)
`Simple_Events_Calendar::uninstall()` (registered via `register_uninstall_hook`) checks the `delete_data_on_uninstall` setting before removing anything. The default value is `'no'`, which **retains all data** — reinstalling the plugin after deletion keeps all existing events intact. Only when an admin explicitly sets the option to `'yes'` will uninstall delete every `simple-events` post, every `simple-events-cat` term, the `simple_events_settings` option, and every `_transient_simple_events_*` row.

Deletion occurs **only on plugin deletion** (via `register_uninstall_hook`), never on deactivation. Any data the plugin should always preserve must live outside that post type/taxonomy/transient prefix regardless of the setting.

### Recurring events
`Simple_Events_Recurrence` (in `includes/class-recurrence.php`) implements per-occurrence recurring events. Each occurrence is a **real** `simple-events` post with its own `event_date` linked back to a parent via `_sec_series_parent` post meta, so the shortcode, archive, AJAX, and admin filters work unchanged.

**Per-parent meta keys** (all exposed as `Simple_Events_Recurrence::META_*` constants):

| Constant            | Meta key                          | Stored on | Purpose                                                                                       |
| ------------------- | --------------------------------- | --------- | --------------------------------------------------------------------------------------------- |
| `META_PARENT`       | `_sec_series_parent`              | child     | Parent post ID                                                                                |
| `META_INDEX`        | `_sec_series_occurrence_index`    | child     | Ordinal in the series (parent is 0)                                                           |
| `META_OVERRIDES`    | `_sec_field_overrides`            | child     | Array of field keys the user has diverged on; the generator never overwrites these            |
| `META_CASCADED_TRASH` | `_sec_recur_cascaded_trash`     | child     | Marker set by `cascade_children('trash')` so `handle_untrash` only restores its own cascaded children, not children the user pre-trashed |
| `META_RULE_FREQ`    | `_sec_recur_freq`                 | parent    | Frequency snapshot (`daily`/`weekly`/`monthly`/`yearly`) — written by `regenerate_series` so cron / background workers don't depend on the admin-only meta box |
| `META_RULE_INTERVAL`| `_sec_recur_interval`             | parent    | Interval snapshot                                                                              |
| `META_RULE_END_TYPE`| `_sec_recur_end_type`             | parent    | End-type snapshot (`never`/`count`/`until`)                                                    |
| `META_RULE_COUNT`   | `_sec_recur_count`                | parent    | Count snapshot (only when end_type=count)                                                      |
| `META_RULE_UNTIL`   | `_sec_recur_until`                | parent    | Until-date snapshot (`Ymd`, only when end_type=until)                                          |
| `META_RULE_BYDAY`   | `_sec_recur_byday`                | parent    | By-day snapshot (comma-separated `w` ints) — written by `regenerate_series`; absent when not set or when frequency is not `weekly` |
| `META_RULE_HORIZON` | `_sec_recur_horizon`              | parent    | Target horizon for "never" series — written write-monotonically (`end($computed)`), never `last_date` |
| `META_RULE_SKIPPED` | `_sec_recur_skipped_indexes`      | parent    | Indexes the user force-deleted; regeneration never recreates them                              |
| `META_CHILD_COUNT`  | `_sec_recur_child_count`          | parent    | Cached live (non-trash) child count used by the admin Series column. Refreshed by `recount_children()` after every mutation — **after** the state change (i.e., `trashed_post` / `deleted_post`, not their pre-action siblings) so the cache isn't stale by ±1 |
| `META_FUTURE_SEGMENTS` | `_sec_recur_future_segments`   | parent    | Persisted "this and future" edits as `[{from_index, fields}]` segments — `create_child` overlays matching segments so children generated later by async batching / horizon extension / count increase inherit the edit |

**Invariants:**
- The **parent post is occurrence #0**. Children carry the child-side meta above.
- All date math uses `DateTimeImmutable` + `wp_timezone()` (NOT `date('t', mktime(...))` — keep month-length lookups on the DateTimeImmutable too). Monthly recurrences anchor on the parent's day-of-month and clamp to the last day of the target month (Jan 31 → Feb 28 → Mar 31). Yearly Feb 29 anchors clamp to Feb 28 in non-leap years.
- **Weekly by-day recurrence** (v5.1.0): when `event_repeat_byday` is set and `event_repeat_frequency` is `weekly`, the engine generates occurrences on the selected weekdays (PHP `w` values: `0`=Sun … `6`=Sat) every `interval`-th calendar week. Week boundaries follow the site's `start_of_week` option (`week_start_anchor()` shifts a date back to midnight on the configured first day of its week — Sunday, Monday, or whatever the site uses — NOT always Monday). The parent post remains occurrence index 0 on its own `event_date`; children fall on the chosen weekdays in subsequent qualifying weeks. Implemented in `Simple_Events_Recurrence::compute_weekly_byday_dates()`, branched from `compute_occurrence_dates()`; `read_rule()` parses `event_repeat_byday` into the rule array; `create_child()` strips it from children (it is a parent-only rule key). Absent or empty `event_repeat_byday` with `weekly` frequency behaves identically to plain weekly (one occurrence per interval-th week on the same weekday as the parent).
- The Event Details meta box shows a weekly day-of-week picker (S–M–T–W–T–F–S toggles, ordered by `start_of_week`) with quick presets **Weekdays** (Mon–Fri), **Weekend** (Sat–Sun), and **Every day**, visible only when frequency is "week(s)". A fresh event defaults to the event date's own weekday. The live recurrence summary reads e.g. "Repeats every week on Mon, Wed, Fri · 10 occurrences".
- **`save_post` is hooked at priority 30** (not the post-type-specific variant) because `save_post_simple-events` fires before `save_post`, and the native meta box persists field meta on `save_post_simple-events` (priority 10) — running at 30 guarantees the meta box has already populated the rule meta.
- The recursion guard is `$GLOBALS['sec_generating_series']`. Every hook handler (`post_updated`, `save_post`, `before_delete_post`, `deleted_post`, `wp_trash_post`, `trashed_post`, `untrashed_post`) bails when it's set; the generator and cascade routines set it via `lock_generation()` / `unlock_generation()`.
- A per-parent **atomic option-row lock** (`sec_recur_lock_<parent_id>`, acquired via `add_option(..., '', 'no')` — the options-table INSERT IGNORE provides atomic cross-request semantics, unlike a transient where the get-then-set pair would race) prevents concurrent regenerations from double-creating occurrences. A stale-lock check clears and re-acquires once if the value is older than 60 s, recovering from any process that died holding the lock. **Don't switch this back to a transient** — the round-3 review caught the original implementation race.
- Large series are generated up to `sec_recur_sync_batch_size` (default 50) synchronously, then continued via `wp_schedule_single_event('sec_recur_continue_generation', [parent_id, 0])`. The continuation re-enters `regenerate_series` — `diff_and_apply` is idempotent because already-created indexes are detected and skipped. **Don't add side effects to `diff_and_apply` that aren't safe to repeat.**
- "Never" series have their horizon stored in `_sec_recur_horizon` and refilled by the `sec_recur_extend_horizon` daily cron, capped at start + `sec_recur_max_horizon_months` (60). The persisted value is the **target** horizon (`end($computed)`), not `last_date`, and only ever **grows** — partial passes never roll a cron-extended horizon backward.
- Field propagation for "this and future" / "entire series" edits goes through `write_field_to_post()`, which uses **direct `$wpdb->update` + `clean_post_cache`** for `post_title` / `post_content` / `post_excerpt` to avoid re-firing `save_post` (and the meta box's save handler) on each propagation target — re-firing would write the original post's submitted values onto every sibling.
- **The native meta box bails during generation**: `Simple_Events_Meta_Box::save()` returns early when `$GLOBALS['sec_generating_series']` is set, so the child `wp_insert_post` calls inside the parent's save chain don't get the parent's submitted values written onto them. `create_child` still **defensively deletes the seven `event_repeat*` rule keys** (`event_repeat_interval`, `event_repeat_frequency`, `event_repeat_end_type`, `event_repeat_count`, `event_repeat_until`, `event_repeats`, and `event_repeat_byday`) on each child so a leaked value can't turn a child into a phantom series-parent. Don't remove that cleanup loop.
- `get_existing_children` **includes `'trash'` in the status list** (the WP `'any'` shorthand excludes it). Trashed children show up so the diff pass can short-circuit on their index instead of duplicating a live child at the same slot. The deletion pass detaches them rather than force-deleting.
- **Past-friendly toggle-off**: when `event_repeats` flips on → off, `handle_toggle_off` only force-deletes future unmodified live children. Past, trashed, or per-occurrence-edited children get detached and survive as standalone events. The admin notice documents this behavior; keep them aligned.

**Hook surface:**

| Hook                            | Handler                             | Purpose                                                                                |
| ------------------------------- | ----------------------------------- | -------------------------------------------------------------------------------------- |
| `post_updated` (pri. 10, 3 args)| `snapshot_pre_save`                 | Captures OLD field values into `self::$pre_save_snapshots` so the priority-30 save-post handler can diff against them after the meta box rewrites meta on `save_post_simple-events`. |
| `save_post` (pri. 30, 3 args)   | `handle_save_post`                  | Routes to `handle_child_save` (child) or `regenerate_series` / `handle_toggle_off` (parent). |
| `add_meta_boxes_simple-events`  | `register_edit_scope_metabox`       | Adds the Series Edit Scope sidebar metabox on children only.                           |
| `before_delete_post`            | `handle_before_delete`              | Parent → cascade-delete. Child → record skipped index + capture parent ID into `self::$pending_delete_parents` for the post-delete recount. |
| `deleted_post`                  | `handle_deleted_post`               | Refreshes the cached child count using the captured parent ID — runs AFTER the row is gone (when `get_post_meta` on the child returns nothing). |
| `wp_trash_post`                 | `handle_trash`                      | Parent → cascade-trash (marks each cascaded child with `META_CASCADED_TRASH`). Child trash is a documented no-op here. |
| `trashed_post`                  | `handle_trashed_post`               | Refreshes the cached child count after the post status flips to `trash` — recount in the pre-action sibling would still count the trashed-to-be child as live. |
| `untrashed_post`                | `handle_untrash`                    | Parent → restore only children carrying `META_CASCADED_TRASH` (clearing the marker on restore). |
| `sec_recur_extend_horizon` (daily cron) | `cron_extend_horizon`       | Extends `META_RULE_HORIZON` on any "never" series within the refill threshold.         |
| `sec_recur_continue_generation` (one-shot cron) | `continue_background_generation` | Re-enters `regenerate_series` for the next batch of large series.                  |
| `init` (pri. 20)                | `maybe_reschedule_cron`             | Defensively re-registers the daily cron if it disappeared (e.g., DB migration).        |
| `admin_notices`                 | `render_admin_notices`              | Surfaces transient-stored notices on the parent's edit screen.                         |

`Simple_Events_Calendar::activation_check()` calls `Simple_Events_Recurrence::schedule_cron()`; `deactivation()` inlines `wp_unschedule_hook('sec_recur_extend_horizon')` + `wp_unschedule_hook('sec_recur_continue_generation')` so the deactivation path doesn't depend on `class-recurrence.php` being loaded. `wp_unschedule_hook` (not `wp_clear_scheduled_hook`) is required because the continuation events are scheduled with per-parent args — the no-arg form of `wp_clear_scheduled_hook` only matches no-arg events and would leave queued batches behind.

Public extensibility filters (the plugin's first): `sec_recur_max_occurrences` (1000), `sec_recur_max_horizon_months` (60), `sec_recur_sync_batch_size` (50), `sec_recur_horizon_refill_threshold_months` (6), `sec_recur_horizon_extend_months` (18), `sec_recur_copyable_field_keys`. Keep these stable — they're part of the public contract.

### Templates and SEO markup
Both the shortcode and AJAX paths render each event through `template-parts/content-event-card.php` (with `simple_events_render_fallback_card()` in `includes/functions.php` as a fallback; the archive/taxonomy templates render cards via `simple_events_render_event_card()`). The card emits **schema.org Event JSON-LD** inline via `simple_events_get_event_schema()` (skipped when the JSON-LD setting is off), and single event pages emit the same schema on `wp_head` (`Simple_Events_Post_Type::output_single_schema()`). To change structured data, edit `simple_events_get_event_schema()` in `includes/functions.php` — it's the single source.

The default **single event template** (`templates/single-simple-events.php`, theme-overridable) is a two-column layout: featured image + content on the left, a sticky `.simple-events-single__card` "Event Details" card (date/time/location/category pills + **Add to Calendar**) on the right, stacking with the details above the content on small screens. The Add to Calendar button links to `Simple_Events_ICS::url($event_id)` (`home_url()` + `?sec_ical=<id>`); `Simple_Events_ICS` intercepts that on `template_redirect` and streams a `.ics` file. Single-page styles live under `.simple-events-single` in the SCSS.

### Build pipeline
- `src/css/simple-events.scss` is the source; `assets/css/simple-events.css` is generated output. **Never edit `assets/css/simple-events.css` directly** — it will be overwritten by the next build.
- JS in `assets/js/` is hand-written (no build step). `assets/js/simple-events.js` is the main script; `simple-events-shortcode.js` is shortcode-specific.
- The distribution step excludes `node_modules`, `src`, `.git`, `dist`, dotfiles, `package*.json`, `phpcs.xml`, and `*.md`.

**v5.1.0 asset changes:**
- `src/css/simple-events.scss`: The shared event card was restyled — 5 px border-radius, larger soft drop-shadow, a light-gray (`#e5e7eb`) border, 22 px h3 title, and grid thumbnail aspect ratio changed from 4:3 to **3:2**. New modifiers: `.sec-layout-list` (image-left list layout), `.sec-grid-columns` (Elementor column-count container using the `--sec-columns` CSS custom property), and `.simple-events-embed` (single-event embed renders as one full-width block).
- `assets/css/simple-events-admin.css` (new): hand-written admin styles for the Event Details meta box. This file is **not** part of the SCSS build — edit it directly.
- `assets/js/simple-events-settings.js` (new): handles the date-preset toggle on the Settings page (shows/hides the custom format input). Hand-written, no build step.
- `includes/elementor/display-widgets.php` (new): registers the Events Grid and Single Event Elementor widgets.

## Internationalization
Three shipped locales (`en`, `es_ES`, `fr_FR`) in `languages/`. Always wrap user-facing strings with `__()`/`_e()`/`_x()` using the `simple_events` / `PLUGIN_TEXT_DOMAIN` text domain — phpcs is configured to enforce this.

**v5.1.0 note:** New user-facing strings were introduced in: the Settings page (date-format preset labels, the Data section for delete-on-uninstall), the Event Details meta box (field labels, recurrence summary text), the Elementor Events Grid and Single Event display widgets, and the `[sec_event]` shortcode error states. The shipped `es_ES` and `fr_FR` `.po`/`.mo` files have **not** yet been updated with translations for these strings — WordPress falls back to the English source strings until the catalogues are refreshed.
