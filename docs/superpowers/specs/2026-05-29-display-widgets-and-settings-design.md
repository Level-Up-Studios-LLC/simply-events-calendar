# Design: Display widgets, single-event display, and settings polish

**Date:** 2026-05-29
**Branch:** `feature/display-widgets-and-settings` (off the v5.0.0 self-contained-fields branch)
**Plugin version target:** 5.1.0

## Summary

A set of net-new display features plus admin/settings polish for the Simple Events
Calendar plugin, building on the v5.0.0 self-contained-fields work in PR #3:

1. Replace the editable "Empty state" settings with a hardcoded, translatable static message.
2. Date-format setting becomes a preset picker (4 presets) + a custom format field.
3. Add a "retain (default) / delete event data on uninstall" setting.
4. Polish the Event Details meta box UI/UX ("accent + live summary" direction).
5. New Elementor **Events Grid** widget (grid with configurable columns, or image-left list).
6. New **single-event** display: `[sec_event id=…]` shortcode + an Elementor widget with a
   searchable event picker; both support card or list layout.
7. Gate the per-element Elementor widgets (Title, Image, …) so they only work inside an event
   loop / single-event template context.
8. Apply the two outstanding Copilot review comments on PR #3.

The two PR fixes (#8) land first as a small foundational commit; the rest follow.

## Non-goals

- No infinite scroll / "load more" on the new Elementor Events Grid widget (fixed count in v1).
- No data migration (event meta is already native post meta).
- No changes to the recurrence engine, AJAX load-more contract, or the `[sec_events]` attribute set.
- No automated test suite is added (the project has none; manual verification only).

---

## Item 1 — Empty-state static message

**Current:** `empty_state_heading` / `empty_state_text` settings feed
`Simple_Events_Shortcode::render_no_events_message()`, which falls back to translatable,
context-aware strings when they are blank.

**Change:**
- Remove the **Empty state** section (heading + message fields) from `Simple_Events_Settings::render_page()`.
- Remove `empty_state_heading` / `empty_state_text` from `simple_events_get_setting_defaults()`
  and from `Simple_Events_Settings::sanitize()`.
- `render_no_events_message()` always uses the hardcoded translatable heading
  `__('No Events Found', 'simple_events')` plus the existing context-aware body messages
  (category-specific / upcoming-only / none-yet). The `simple-events-no-events` class is
  preserved (the shortcode cache intentionally skips empty-state output keyed on that class).
- Verify (grep) no other code path reads the removed keys; confirm the archive/taxonomy
  templates' empty handling still reads correctly.

**Why:** the empty message is boilerplate that rarely needs per-site editing; removing it
shrinks the settings surface and avoids persisting a locale-specific string into the option.

## Item 2 — Date format presets + custom

**Current:** a single free-text `date_format` input (PHP date format) with a live preview and
a static list of example formats.

**Change:**
- Replace the text input with a `<select>` offering four presets plus "Custom…":
  - `l, F j, Y` → "Monday, January 5, 2026" (current default)
  - `F j, Y` → "January 5, 2026"
  - `m/d/Y` → "01/05/2026"
  - `M j, Y` → "Jan 5, 2026"
  - `custom` → reveals a free-text PHP-format input
- The live **Preview:** line stays and updates from whichever value is active.
- **Storage is unchanged:** a single `date_format` string holding a PHP format. The preset
  `<option>` values are the format strings themselves; the `custom` option's text field
  supplies the format when chosen.
- **Load logic:** if the stored `date_format` exactly matches a preset value, select that
  preset; otherwise select "Custom…" and populate the text field with the stored value.
- **Save logic (`sanitize()`):** the form submits a preset key and a custom string. Final
  `date_format` = the preset value, or the custom text when `custom` is selected; if the
  custom text is blank/whitespace, fall back to the default (`l, F j, Y`). Result is run
  through `sanitize_text_field()` as today.
- New `assets/js/simple-events-settings.js` (vanilla DOM, no build step), enqueued only on the
  settings page via a new `admin_enqueue_scripts` handler in `Simple_Events_Settings`, toggles
  the custom field's visibility and refreshes the preview client-side. The server-rendered
  preview remains correct without JS (progressive enhancement).

## Item 3 — Retain / delete data on uninstall

**Change:**
- New **Data** section in the settings page with one control:
  "When the plugin is deleted" → **Retain events (default)** / **Delete all events, categories, and settings**.
- New setting key `delete_data_on_uninstall`, default `'no'`. Sanitized like the other yes/no toggles.
- `Simple_Events_Calendar::uninstall()` (static, runs in its own request) reads
  `get_option(SIMPLE_EVENTS_SETTINGS_OPTION)` first. If `delete_data_on_uninstall !== 'yes'`,
  it returns immediately and deletes nothing (events, terms, transients, and the option all
  survive a reinstall). When `'yes'`, it runs the existing destructive cleanup, including
  `delete_option()`.
- Deactivation behavior is unchanged (WordPress never deletes data on deactivate; only on delete).
- Update the CLAUDE.md "Uninstall is destructive" note to document the opt-in (default retain).

**Why:** lets users reinstall without recreating events; destruction becomes a deliberate choice.

## Item 4 — Event Details meta box polish (Direction B)

**Constraint:** field `name`/`id` attributes and submitted values stay identical, so
`Simple_Events_Meta_Box::save()` and the recurrence engine are unaffected. Only presentation
markup, labels, CSS, and the summary JS change.

**Change:**
- Rewrite `Simple_Events_Meta_Box::render()` markup as a card with:
  - a left accent bar,
  - a "When & where" group (Event Date required; Start/End time in a two-column row; Location)
    with small inline SVG field icons,
  - a divider, then the Recurrence group: the "recurring event" checkbox with a "Recurring"
    pill, and the existing interval/frequency/end-type/count/until controls inside a tinted panel,
  - a **live summary line** ("↻ Repeats every week · 10 occurrences · ends Jul 31, 2026").
- New hand-written `assets/css/simple-events-admin.css` (admin-only; **not** part of the SCSS
  build pipeline), enqueued alongside the existing `simple-events-admin` script on the event
  edit screen (`post.php` / `post-new.php`, post type `simple-events`).
- Extend `assets/js/simple-events-admin.js`: keep the existing show/hide conditional logic and
  add a `sync`-driven builder that composes the summary text from the interval, frequency,
  end-type, count, and until inputs (and hides it when "recurring" is off). All summary label
  fragments are passed in from PHP via `wp_localize_script` so they remain translatable.

## Item 5 — Elementor Events Grid widget

- New file `includes/elementor/display-widgets.php`, required from
  `Simple_Events_Elementor::register_widgets()`; registers `Simple_Events_Widget_Grid`.
- **Not gated** — it is a standalone listing widget, analogous to the `[sec_events]` shortcode.
- **Content controls:** Layout (`grid` | `list`), Columns (responsive; grid only, 1–6),
  Number of events, Category (SELECT of `simple-events-cat` terms, "All" default), Order
  (ASC/DESC), Show past (switch), and show_time / show_excerpt / show_location / show_footer
  switches, plus image size. Defaults derive from the plugin settings.
- **Rendering:** a shared helper `simple_events_render_events_grid(array $args): string` builds
  the `WP_Query` using the same pattern as the shortcode (`orderby=meta_value`,
  `meta_key=event_date`, `meta_type=DATE`, upcoming-only filter unless show_past) and renders
  the `.simple-events-calendar` container + cards via the existing card template. The shortcode
  `[sec_events]` is refactored to call this same helper (no behavior change; load-more data
  attributes preserved for the shortcode path).
- **Grid vs list** is a container modifier class (`.simple-events-calendar--list`). Column count
  is applied via a CSS custom property set by the responsive Columns control
  (`{{WRAPPER}} .simple-events-calendar { --sec-columns: {{VALUE}} }`); the SCSS uses
  `grid-template-columns: repeat(var(--sec-columns, 3), 1fr)` for the widget grid, while the
  default responsive breakpoints remain for the non-widget shortcode/archive output.
- No infinite scroll (fixed count). The widget enqueues the front-end stylesheet via
  `get_style_depends()`.

## Item 6 — Single-event shortcode + widget

- **Shortcode** `[sec_event id="123" layout="card|list" show_time show_excerpt show_location show_footer]`:
  - `id` is **required**; missing/invalid → renders nothing on the front end (logged-in editors
    see a small inline notice). Registered in `Simple_Events_Shortcode`.
- **Widget** `Simple_Events_Widget_Single` (in `display-widgets.php`), **not gated**:
  - Event picker: `SELECT2` preloaded with published events (search-by-title client-side),
    required; Layout (card/list); show_* toggles; image size.
  - In the editor with no event selected → inline "Select an event" hint.
- Both call one shared helper `simple_events_render_single_event(int $post_id, array $flags, string $layout): string`
  that sets up post data, renders the card template (card layout) or the list-row variant
  (list layout via the `--list` modifier), and returns HTML. Output is identical across the two
  entry points. The shortcode enqueues the front-end stylesheet on render; the widget declares
  it via `get_style_depends()`.

## Item 7 — Gate the per-element widgets

- In `Simple_Events_Widget_Base`:
  - Remove the `sec_preview_event` SELECT2 control entirely (users can no longer pick an event
    on these elements).
  - `render()` resolves the event via loop/queried context only. When there is no valid event
    context: render nothing on the front end; in the Elementor editor/preview render a small
    inline hint ("Displays the current event — use inside a single-event template, archive, or
    Loop Grid.").
- New `Simple_Events_Elementor::in_event_context(): bool` — true when `get_the_ID()` or the
  queried object is a `simple-events` post. This is true on single event pages, the post-type
  archive/taxonomy main loop, Elementor Loop Grid items, and Theme Builder single templates
  (where Elementor sets up the previewed post), and false on an ordinary page/post.
- `Simple_Events_Elementor::resolve_event_id()` is simplified accordingly (no preview-id
  fallback path for the element widgets). The editor-mode helper is retained only where the
  hint needs it.
- **Dynamic Tags are unchanged** — they already resolve from the loop/queried post and have no
  event picker, so they are inherently loop-bound.
- `Simple_Events_Elementor::event_options()` is retained for the new Single Event widget's
  picker (and gets the static cache from item 8).

## Item 8 — Outstanding Copilot PR #3 fixes

Both are valid and applied first, as a foundational commit:

- **`includes/class-ajax.php` meta-cache N+1:** the load-more query sets
  `update_post_meta_cache => false`, but each rendered card reads several meta values
  (`event_date`, start/end time, location, schema helpers). Enable the post-meta cache so WP
  preloads the batch's meta in one query. Apply the same change to the `[sec_events]` shortcode
  query (`Simple_Events_Shortcode::build_query_args()`) for consistency — it has the identical
  per-card read pattern. (The earlier "reuse prepared location" fix stays; this complements it.)
- **`includes/elementor/class-elementor.php` repeated query:** `event_options()` runs a
  `get_posts()` every call, and each widget's control registration calls it. Cache the built
  array in a class static property so the query runs at most once per request.

---

## Cross-cutting concerns

- **Front-end asset loading off event pages.** The existing `enqueue_scripts()` gate only covers
  event archives/singulars, `[sec_events]` pages, and text widgets. The new Grid/Single widgets
  and `[sec_event]` can appear on any page. Register the stylesheet handle (`wp_register_style`)
  so: (a) the Elementor widgets declare it via `get_style_depends()`, and (b) the new shortcodes
  call `wp_enqueue_style()` on render. No always-on enqueue — the gate is preserved.
- **SCSS (proper Sass syntax).** All new rules go in `src/css/simple-events.scss` using Sass
  features (variables for the shared radius/shadow/title size, nesting, `&` parent selectors),
  per the Sass documentation. Shared card polish applies globally (item: "everywhere"): 5px
  border-radius, soft drop-shadow, no border, 22px `<h3>` title, and the grid thumbnail aspect
  ratio changes 4:3 → 3:2. A `--list` container modifier lays the card out horizontally (image
  left ~240px, details right; stacks on mobile). `assets/css/simple-events.css` is regenerated
  via `npm run build` — the compiled file is never hand-edited.
- **i18n.** All new user-facing strings use the `simple_events` text domain.
- **Coding standards.** PHP follows `phpcs.xml` (WordPress Coding Standards); output is escaped
  at the point of echo as in the existing renderer.

## New / modified files

**New:**
- `includes/elementor/display-widgets.php` — Events Grid + Single Event widgets.
- `assets/css/simple-events-admin.css` — meta box styling (hand-written, admin-only).
- `assets/js/simple-events-settings.js` — date preset toggle + preview.

**Modified:**
- `includes/class-settings.php` — date preset UI, Data section, remove empty-state, settings JS enqueue.
- `includes/functions.php` — remove empty-state defaults; add `simple_events_render_events_grid()`
  and `simple_events_render_single_event()`.
- `includes/class-shortcode.php` — hardcoded empty message; refactor to shared grid helper;
  register `[sec_event]`; enable meta cache.
- `includes/class-ajax.php` — enable post-meta cache.
- `includes/class-meta-box.php` — Direction B markup + admin CSS enqueue.
- `assets/js/simple-events-admin.js` — live recurrence summary.
- `includes/class-main.php` — opt-in uninstall; register front-end style handle.
- `includes/elementor/class-elementor.php` — register new widgets; `in_event_context()`;
  cache `event_options()`; simplify `resolve_event_id()`.
- `includes/elementor/widgets.php` — drop preview picker; context-gated render + editor hint.
- `src/css/simple-events.scss` → rebuild `assets/css/simple-events.css`.
- `CLAUDE.md` — document opt-in uninstall, new widgets/shortcode, settings changes.

## Manual verification plan

- Settings: presets switch correctly; custom field round-trips a stored non-preset format;
  empty-state section gone; Data toggle persists.
- Uninstall (staging): with default → reinstall keeps events; with "delete" → all gone.
- Meta box: create/edit single + recurring events; summary line tracks edits; saved values
  unchanged vs. before.
- Elementor: Grid widget grid+list, column counts, category/order/show toggles; Single widget
  picker search + card/list; element widgets show the hint on a plain page and render correctly
  inside a Loop Grid / single-event template; styling loads on a non-event page.
- Shortcodes: `[sec_event id=…]` card/list; invalid id renders nothing; `[sec_events]` unchanged.
- Cards: shared polish visible on shortcode + archive; AJAX load-more still works.
