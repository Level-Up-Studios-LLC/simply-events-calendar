=== Simply Events Calendar ===
Contributors: levelupstudios
Tags: events, calendar, event calendar, recurring events, elementor
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 6.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A simple, responsive events calendar for WordPress. Create one-time or recurring events and display them with shortcodes or Elementor.

== Description ==

Simply Events Calendar adds an "Events" post type to your WordPress site so you can create one-time or recurring events and display them anywhere — with a shortcode, an archive page, or Elementor widgets. Everything is managed through a built-in "Event Details" editor: no separate custom-fields plugin is required.

= Key Features =

* **Native event fields**: Date, time, location, and recurrence are edited in a built-in "Event Details" box — no companion plugin needed
* **Simple shortcode**: `[sec_events]` displays an infinite-scrolling grid of upcoming events anywhere on your site
* **Single Event shortcode**: `[sec_event id="123"]` — display one specific event anywhere in card or image-left list layout
* **Modular element shortcodes**: `[sec_event_title]`, `[sec_event_image]`, `[sec_event_date]`, and more, for building custom layouts
* **Recurring events**: repeat daily, weekly, monthly, or yearly, including specific weekdays (with Weekdays/Weekend/Every day presets), ending after a count, on a date, or never
* **Per-occurrence editing**: change a single occurrence, "this and future" occurrences, or the entire series
* **Elementor widgets**: an Events Grid widget (grid or image-left list, configurable columns, optional infinite scroll), a Single Event widget, per-element widgets for event templates and archives, and Dynamic Tags
* **Default templates**: built-in single-event, archive, and category templates that your theme, block/FSE theme, or Elementor Pro Theme Builder can override
* **Add to Calendar**: single event pages include a button that downloads a universal .ics file (Apple Calendar, Outlook, Google Calendar)
* **Settings page**: choose the front-end date/time format, display defaults, cache lifetime, and more
* **SEO**: schema.org Event structured data on event cards and single event pages
* **Responsive design**: adapts automatically to desktop, tablet, and mobile
* **Opt-in data management**: choose whether to keep or delete event data when the plugin is uninstalled (default: keep)
* **Accessibility ready**: built with accessibility best practices and reduced-motion support
* **Translation ready**: shipped with English, Spanish (es_ES), and French (fr_FR) translations

= Shortcode Usage =

Display upcoming events anywhere on your site:

`[sec_events]`

= Shortcode Parameters =

* `posts_per_page` - Number of events to load initially (default: from Settings, 6)
* `category` - Filter by event-category slug (default: none)
* `show_past` - Include past events (default: no)
* `order` - Sort direction ASC/DESC (default: ASC)
* `show_time` - Display event time (default: true)
* `show_excerpt` - Display event excerpt (default: true)
* `show_location` - Display event location (default: true)
* `show_footer` - Display event footer (default: true)

Defaults for the options above come from the Events → Settings page; shortcode attributes override them per instance.

Example: `[sec_events posts_per_page="9" show_time="false"]`

= Element Shortcodes =

For building custom layouts (including inside page builders), each event element has its own shortcode. They default to the current event and accept an `id` attribute to target a specific event:

`[sec_event_title]`, `[sec_event_image]`, `[sec_event_date]`, `[sec_event_time]`, `[sec_event_location]`, `[sec_event_excerpt]`, `[sec_event_content]`, `[sec_event_categories]`, `[sec_event_button]`

= Single Event Shortcode =

Display one specific event anywhere on your site:

`[sec_event id="123"]`

* `id` — (required) the post ID of the event to display
* `layout` — `card` (default) or `list` (featured image on the left, details on the right)
* `show_time`, `show_excerpt`, `show_location`, `show_footer` — same as `[sec_events]`, defaults from Settings

Example: `[sec_event id="42" layout="list" show_excerpt="no"]`

= Elementor =

When Elementor is active, a "Simple Events" widget category (listed just below "Basic") provides:

* **Events Grid** — display a configurable grid or image-left list of events on any page. Controls include layout, column count, number of events, category filter, sort order, show-past toggle, and show_time / show_excerpt / show_location / show_footer toggles. An optional "Load more on scroll" toggle enables infinite scroll.
* **Single Event** — render one event (chosen from a searchable list) anywhere on your site; supports card or list layout.
* **Per-element widgets** (Event Title, Image, Date, Time, Location, Excerpt, Content, Categories, Button) — intended for use inside a single-event template, event archive template, or Elementor Loop Grid bound to events. They preview a sample event in the Elementor editor but render nothing on the front end outside an event context.
* **Dynamic Tags** — bind native Elementor widgets to event fields.

To sort an Elementor **Loop Grid** of events by their event date (instead of the post date), set the Loop Grid's Query ID to "sec_events_by_date" (follows the global "Show past events" setting) or "sec_events_by_date_all" (always includes past). No code snippet required.

= Recurring Events =

Mark an event as repeating and choose a frequency (daily, weekly, monthly, or yearly), an interval, and — for weekly recurrence — specific weekdays with quick "Weekdays", "Weekend", or "Every day" presets. Each occurrence is created as its own event, so shortcodes, archives, and admin filters all work with recurring events automatically. You can edit just one occurrence, "this and future" occurrences, or the whole series from a Series Edit Scope panel on the event edit screen.

= Theme Overrides =

The bundled single-event, archive, and category templates are only used when nothing else claims the page: your active theme can override them by adding matching template files, block/FSE themes are respected automatically, and an Elementor Pro Theme Builder template assigned to events takes priority over both.

= No Required Plugins =

This plugin is fully self-contained. All event fields are stored as native WordPress post meta and edited through the built-in "Event Details" box — no separate custom-fields plugin is needed.

== External services ==

This plugin connects to Freemius, a third-party licensing and update service,
to manage software licenses, deliver plugin updates, and — only if you opt in —
collect anonymous usage data.

**When it connects, and what it sends:**

* **On activation:** the plugin shows an opt-in screen. If you choose "Skip",
  no data is sent and the plugin works normally. Nothing is transmitted before
  you make that choice.
* **If you opt in:** your site URL, WordPress and PHP versions, the plugin
  version, active theme and plugin names, and the email address of the
  activating administrator are sent to Freemius. This is used for update
  delivery and anonymous usage statistics.
* **If you purchase a license:** your license key and site URL are sent to
  Freemius to activate and validate that license, and again periodically to
  confirm it remains valid.
* **On deactivation:** if you opt to share a reason, that reason is sent.

Service provider: Freemius, Inc. — https://freemius.com/
Terms of service: https://freemius.com/terms/
Privacy policy: https://freemius.com/privacy/

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/simply-events-calendar` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the Simply Events Calendar plugin through the 'Plugins' screen in WordPress.
3. Start creating events in your WordPress admin under "Events" — event date, time, and location are entered in the "Event Details" box.
4. Optionally adjust formatting and defaults under Events → Settings.
5. Use the `[sec_events]` shortcode to display events on any page or post.

== Frequently Asked Questions ==

= Does this plugin require any other plugin? =

No. It's fully self-contained — event date, time, location, and recurrence are all managed through a built-in "Event Details" meta box. Elementor is optional: if it's active, extra widgets appear automatically, but the plugin works fully without it.

= How do I display events on a page? =

Add the `[sec_events]` shortcode to any page or post for an infinite-scrolling grid of upcoming events, or use the post type's own archive page. To show a single event anywhere, use `[sec_event id="123"]`.

= Can I use this with Elementor? =

Yes. When Elementor is active, a "Simple Events" widget category provides an Events Grid widget, a Single Event widget, per-element widgets (title, image, date, time, location, and more) for use inside event templates, and Dynamic Tags for binding native Elementor widgets to event fields.

= Can events repeat on a schedule? =

Yes. An event can repeat daily, weekly, monthly, or yearly, including on specific weekdays for weekly recurrence, and can end after a number of occurrences, on a date, or never. Each occurrence is its own event, and you can edit one occurrence, future occurrences, or the entire series independently.

= Can I override the default templates with my theme? =

Yes. The bundled single-event, archive, and category templates are used only as a fallback. Copy them into your theme to customize the markup, and a block/FSE theme or an Elementor Pro Theme Builder template assigned to events will take priority automatically.

= Can I customize what information is displayed? =

Yes. Shortcode and widget parameters control whether time, excerpt, location, and the footer link are shown, and you can further style output with your theme's CSS.

= What happens to my events if I uninstall the plugin? =

Nothing, by default. Deactivating or deleting the plugin keeps all your events, categories, and settings intact unless you explicitly opt in to data deletion on the Events → Settings → Data page.

= Is the plugin translation ready? =

Yes. It ships with English, Spanish (es_ES), and French (fr_FR) translations, and additional languages can be added using standard WordPress translation tools.

== Screenshots ==

1. Event display with a responsive shortcode grid
2. WordPress admin events list with custom columns
3. Event creation screen with the built-in Event Details meta box
4. Elementor Events Grid widget settings

== Changelog ==

= 6.0.0 (2026-09-07) =

**Changed**
* The plugin is now Simply Events Calendar. One codebase now produces both the free build and the licensed Pro build of the same plugin, so there is one plugin to install and manage rather than two. Entering a license upgrades the plugin in place.
* The plugin folder and main file are renamed to `simply-events-calendar`. WordPress identifies a plugin by that path, so this release requires a manual changeover — see the Upgrade Notice below. No event data is affected.
* Global constants `PLUGIN_DIR`, `PLUGIN_URL`, `PLUGIN_ASSETS`, and `PLUGIN_VERSION` are now `SIMPLE_EVENTS_*`. Anything reading them directly needs updating; they were unprefixed globals that could collide with any other plugin.

**Fixed**
* 105 admin and front-end strings were untranslatable and now are not. They passed the text domain as a PHP constant, and WordPress extracts translatable strings by reading literals out of the source, so those strings never reached the translation template in any locale. All 385 call sites now use a literal domain matching the plugin slug.
* Placeholder strings carry translator comments, "(s)" plural hedges are now real plural forms, and ambiguous single-word labels carry context — so translators can produce correct output rather than guesses.

**Added**
* Licensing, updates, and optional usage analytics through Freemius. The opt-in is skippable; choosing "Skip" transmits nothing. See the External services section below for exactly what is sent and when.

**Migration / compatibility**
* Your data is safe. Events are posts, their fields are post meta, categories are terms, and settings are a single option. None of those names change in this release, and deactivating a plugin never deletes them. Because the plugin folder changed, WordPress treats this as a different plugin, so the changeover is manual — see the Upgrade Notice below for the step-by-step procedure.

= 5.2.0 (2026-06-11) =

**Added**
* A **Pro upsell area** in the admin: a dismissible "Upgrade to Pro" banner on the Events → Settings and Events → Documentation pages, an "Available in Pro" preview of upcoming premium features on the Settings page, and an **Upgrade to Pro** link under the Events menu.

**Notes**
* This update only adds in-admin information about the upcoming Pro version — it makes no changes to your events, settings, or anything visitors see on the front end. The banner can be dismissed per user.

= 5.1.0 (2026-06-08) =

**Added**
* Single-event shortcode `[sec_event id="123" layout="card|list"]` — display one event anywhere on your site in card or image-left list layout
* Elementor **Events Grid** widget — grid or image-left list of events usable on any page; configurable column count, category/order/show-past filters, and optional "Load more on scroll" infinite scroll
* Elementor **Single Event** widget — render one event (chosen from a searchable list) anywhere; card or list layout
* **Weekly by-day recurrence** — recurring weekly events can now target specific weekdays (S–M–T–W–T–F–S picker) with **Weekdays**, **Weekend**, and **Every day** presets and a live plain-English recurrence summary
* **Documentation page** at Events → Documentation listing all shortcodes and Elementor widgets
* **Opt-in data deletion on uninstall** (Events → Settings → Data): default keeps all events, categories, and settings on uninstall; only an explicit choice deletes them — deletion never happens on deactivation
* **Redesigned single event page** with a sticky "Event Details" card (date, time, location, categories) and a working **Add to Calendar** button that downloads a universal .ics file (Apple Calendar, Outlook, Google Calendar import)
* **Elementor Loop Grid sorting by event date** — set a Loop Grid's Query ID to "sec_events_by_date" (follows the global Show past events setting) or "sec_events_by_date_all" (always includes past) to order events by their event date instead of the post date; no code snippet required

**Changed**
* Event cards restyled: 5 px corner radius, softer drop shadow, light-gray border, 22 px event title; grid thumbnails changed from 4:3 to **3:2** aspect ratio; new image-left **list layout**
* Event Details meta box redesigned with sectioned layout, field icons, "Recurring" pill, and a live recurrence summary
* Date format setting now uses preset radio buttons + a Custom field (matching WordPress's General Settings style); default sort order setting now uses radio buttons
* Empty-state "no events" message is now a built-in translatable string; the editable empty-state settings fields were removed
* Per-element Elementor widgets (Title/Image/Date/Time/Location etc.) are now gated to event-loop contexts — they preview a sample event in the editor but render nothing on an ordinary page on the front end
* Front-end stylesheet and script are now registered for on-demand loading so the display widgets and `[sec_event]` shortcode work on any page
* Single event and archive views now use your Elementor Pro Theme Builder template when one is assigned to events (the plugin's default template is the fallback when no Elementor template exists)
* Front-end and admin JavaScript modernized to ES2023 syntax (no build step; runs natively in current browsers). No change in behavior.

**Fixed**
* AJAX "load more" event cards were missing time, excerpt, location, and "Learn More" link — fixed a show-flag type mismatch between the AJAX path and the card template
* Infinite scroll could fail to trigger when scrolling straight to the bottom and stopping — now re-checks position on the trailing edge
* Several code-review fixes: Elementor event-ID fallback guarded against term-ID collisions on taxonomy archives; weekday picker checkboxes given full names for screen readers; Events Grid columns stack correctly to 2/1 on tablet/mobile

= 5.0.0 (2026-05-29) =

**Added**
* Native "Event Details" meta box for editing date, time, location, and recurrence
* Settings page (Events → Settings): front-end date/time format, display defaults, empty-state copy, cache lifetime + clear button, load-more batch size, recurrence limits, schema.org toggle
* Element shortcodes for custom layouts: [sec_event_title], [sec_event_image], [sec_event_date], [sec_event_time], [sec_event_location], [sec_event_excerpt], [sec_event_content], [sec_event_categories], [sec_event_button]
* Default single, archive, and category templates (theme-, Elementor-, and block-theme-overridable)
* Elementor "Simple Events" widgets and Dynamic Tags (when Elementor is active)
* schema.org Event structured data on single event pages

= 4.4.0 (2026-05-28) =

**Added**
* **Recurring events**: events can now repeat every N days / weeks / months / years from the event edit screen
* End options: after a number of occurrences, on a specific date, or never (with a rolling 24-month horizon refilled daily by WP-Cron, capped at 60 months)
* Per-occurrence editing with a sidebar "Series Edit Scope" metabox: changes can apply to this occurrence only, this and future occurrences, or the entire series; per-field overrides keep individually-edited fields safe from series-wide updates
* Each occurrence is stored as a real `simple-events` post, so the shortcode, archive, AJAX loader, and admin filters work with recurring events out of the box
* New "Series" admin column on the events list table
* First public extensibility filters: `sec_recur_max_occurrences`, `sec_recur_max_horizon_months`, `sec_recur_sync_batch_size`, `sec_recur_horizon_refill_threshold_months`, `sec_recur_horizon_extend_months`, `sec_recur_copyable_field_keys`

**Behavioral notes**
* Disabling recurrence on a saved series only force-deletes FUTURE unmodified occurrences; past, edited, or trashed occurrences are detached and kept as standalone events so history isn't destroyed
* Trashing or restoring a parent cascades to its children
* Large series generate up to 50 occurrences synchronously and finish in the background; an admin notice on the parent edit screen reports progress
* DST-safe and month-end-safe date arithmetic (Jan 31 → Feb 28 → Mar 31; Feb 29 → Feb 28 in non-leap years)

= 4.3.1 (2026-04-22) =

**Security**
* Converted AJAX `load_more_events` endpoint to JSON responses (`wp_send_json_success` / `wp_send_json_error`); frontend JS updated in lockstep
* Sanitized nonce and admin `$_GET` reads; whitelist-validated the admin event status filter; escaped all admin filter dropdown output

**Fixed**
* Fixed asset cache-busting (plugin version was hardcoded to an older value, causing browsers to serve stale CSS/JS after upgrades)
* Fixed archive query meta_query merging so existing relation keys from other plugins are preserved
* Prevented duplicate `init()` execution caused by hooking both `plugins_loaded` and a second init action
* Moved translation loading to the `init` hook to silence the WordPress 6.7+ notice
* AJAX-loaded event cards now render the "Learn More" footer link, matching shortcode output

**Changed**
* Shortcode transient cache keys now include plugin version and login state so caches auto-invalidate on upgrade
* Extracted duplicated fallback event-card markup into a shared helper
* Introduced `SIMPLE_EVENTS_NONCE_ACTION` constant for the AJAX nonce action

= 4.3.0 (2024-09-22) =

**Added**
* **Multilingual Support**: Added complete translation support for Spanish (es_ES) and French (fr_FR)
* Added professional-quality translations for all plugin strings
* Added .po and .mo files for Spanish and French languages
* Added internationalization documentation and setup guide

**Changed**
* Updated .pot file with current version and creation date
* Enhanced plugin description to highlight multilingual capabilities
* Updated documentation to include translation information

= 4.2.4 (2024-09-22) =

**Added**
* Added SCSS build system with Sass compiler and stylelint
* Added development and production CSS build scripts
* Added file watching capabilities for development
* Added CSS linting with stylelint and standard SCSS configuration
* Added automatic version synchronization between plugin header, constants, and package.json

**Changed**
* **BREAKING**: Updated shortcode from `[simple_events_calendar]` to `[sec_events]` for consistency
* Improved responsive design with better media query organization
* Enhanced theme color inheritance for better integration with WordPress themes
* Refactored CSS build process from simple file copying to proper SCSS compilation
* Updated build system to generate both compressed (production) and expanded (development) CSS
* Improved accessibility features and reduced motion support

**Fixed**
* Fixed plugin description to clearly specify the custom-fields plugin requirement
* Improved WordPress version compatibility requirements
* Enhanced color definitions and margin adjustments for better layout consistency
* Fixed media query structure for improved readability and maintainability

**Development**
* Added source maps for development builds
* Added proper CSS minification for production builds
* Added automated distribution and zip creation process

= 4.1.1 (2024-09-22) =

**Removed**
* Removed "Showing current and upcoming events only." message from shortcode display
* Removed scroll hint bar from after events
* Events now load automatically on scroll without instructional messages

= 4.1.0 (2024-09-22) =

**Improved**
* Enhanced dependency error message for better user experience
* Added a direct download-link button for the required plugin to the WordPress plugin installer
* Cleaner, more actionable error messages for missing dependencies

= 4.0.3 (2024-09-22) =

**Fixed**
* Fixed duplicate content appearing in admin columns
* Added static tracking to prevent duplicate processing
* Eliminated stacked duplicate content within individual admin columns

= 4.0.0 (2024-09-22) =

**Added**
* Updated WordPress minimum requirement to 6.0+ for better compatibility
* Updated PHP minimum requirement to 7.4+
* Added build system with distribution and zip commands
* Added automated semantic versioning instructions

**Changed**
* **BREAKING**: Increased minimum WordPress and PHP requirements
* Refactored plugin architecture with proper class-based structure
* Improved admin columns functionality with better duplicate prevention

= 3.0.0 (2024-08-20) =

**Added**
* Responsive grid layout with device-specific columns
* Custom gap spacing for different screen sizes
* 4:3 aspect ratio for featured images with responsive design
* Event status filtering in admin
* Location field with visual indicators
* Comprehensive dependency checking
* Scroll hints and loading animations
* Enhanced event card design with hover effects

**Changed**
* Updated initial event display from 9 to 6 events
* Improved infinite scroll with better error handling
* Enhanced admin interface with better column management
* Event cards now use flexbox layout for consistent heights
* Improved responsive design with device-specific adjustments

**Fixed**
* Infinite scroll error handling
* Past events filtering
* Date comparison issues using WordPress timezone
* Field-plugin detection reliability
* Event ordering consistency

== Upgrade Notice ==

= 6.0.0 =
The plugin is renamed to Simply Events Calendar and its folder changes, so
WordPress sees it as a new plugin: deactivate the old one (do not delete it
yet), install this one, verify your events, then visit Settings → Permalinks.
Your events, categories, and settings are preserved — none of the data they
live in is renamed. Back up your database first.

= 5.2.0 =
Adds an in-admin "Upgrade to Pro" area (banner, feature preview, and menu link) for the upcoming Pro version. No changes to your events, settings, or the front end; the banner is dismissible per user.

= 5.1.0 =
Adds display widgets, single-event/Add-to-Calendar features, and a one-time migration that renames very old `events`/`events-cat` data to `simple-events`/`simple-events-cat` so existing events keep working. Back up your database and test on staging before upgrading a production site.

= 5.0.0 =
The separate custom-fields plugin is no longer required. Your existing events are preserved with no migration needed, and that plugin can be deactivated after upgrading. Event date/time/location are now edited in the built-in "Event Details" box.

= 4.2.4 =
BREAKING CHANGE: Shortcode changed from [simple_events_calendar] to [sec_events]. Please update your shortcodes after upgrading.

= 4.0.0 =
This version requires WordPress 6.0+ and PHP 7.4+. Please ensure your site meets these requirements before upgrading.

= 3.0.0 =
Major redesign with responsive grid layout and improved user experience. Backup your site before upgrading.
