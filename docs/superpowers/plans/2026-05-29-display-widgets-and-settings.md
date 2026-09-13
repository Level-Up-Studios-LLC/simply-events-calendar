# Display Widgets and Settings Polish — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Elementor display widgets (Events Grid + Single Event), a `[sec_event]` shortcode, gate the per-element Elementor widgets to loop contexts, and polish the settings page, uninstall behavior, and Event Details meta box — for plugin v5.1.0.

**Architecture:** Reuse the existing shared renderer (`Simple_Events_Renderer`) and card template (`template-parts/content-event-card.php`) so all new display paths produce identical markup. New display widgets live in a dedicated `includes/elementor/display-widgets.php`; shared render helpers live in `includes/functions.php`. Settings/meta-box/uninstall changes are localized to their existing classes. Front-end CSS is authored in `src/css/simple-events.scss` (proper Sass) and compiled; admin CSS is a separate hand-written file.

**Tech Stack:** PHP 7.4+, WordPress 6.0+, Elementor (optional), Dart Sass via npm scripts, vanilla JS (no build step for JS).

---

## Testing note

This project ships **no automated test suite** and has no PHP test harness. Verification per task uses:
- `php -l <file>` for PHP syntax (only if a PHP CLI is on PATH; otherwise skip and rely on manual load).
- `npm run lint:css` and `npm run build` for styles.
- Manual checks in the WordPress admin and front end (steps spell out exactly what to look at).

Commit after each task. Keep commits scoped to the task.

---

## File structure

**New files:**
- `includes/elementor/display-widgets.php` — `Simple_Events_Widget_Grid`, `Simple_Events_Widget_Single`.
- `assets/css/simple-events-admin.css` — Event Details meta box styling (hand-written, admin-only, not built).
- `assets/js/simple-events-settings.js` — date-preset toggle + live preview on the settings page.

**Modified files:**
- `includes/class-ajax.php` — enable post-meta cache.
- `includes/class-shortcode.php` — enable post-meta cache; hardcode empty-state message; register `[sec_event]`.
- `includes/elementor/class-elementor.php` — static-cache `event_options()`; `in_event_context()`; simplify `resolve_event_id()`; register new widgets.
- `includes/elementor/widgets.php` — drop preview picker; context-gated render + editor hint.
- `includes/class-settings.php` — date presets; Data (uninstall) section; remove empty-state section; enqueue settings JS.
- `includes/functions.php` — remove empty-state defaults; add `delete_data_on_uninstall` default; add `simple_events_render_events_grid()` and `simple_events_render_single_event()`.
- `includes/class-main.php` — opt-in uninstall; register front-end style handle.
- `includes/class-meta-box.php` — Direction B markup; enqueue admin CSS + localize summary strings.
- `assets/js/simple-events-admin.js` — live recurrence summary.
- `src/css/simple-events.scss` → rebuild `assets/css/simple-events.css` — shared card polish + list modifier + widget columns.
- `simple-events-calendar.php` — bump version to 5.1.0.
- `CLAUDE.md` — document new behavior.

---

## Task 1: Apply outstanding Copilot PR #3 fixes

**Files:**
- Modify: `includes/class-ajax.php` (build_query_args)
- Modify: `includes/class-shortcode.php` (build_query_args)
- Modify: `includes/elementor/class-elementor.php` (event_options)

- [ ] **Step 1: Enable the post-meta cache in the AJAX query**

In `includes/class-ajax.php`, in `build_query_args()`, change the meta-cache line:

```php
            'no_found_rows'   => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
            'suppress_filters' => false,
```

(Was `update_post_meta_cache => false`. Cards read several meta values per post — date, start/end time, location, schema — so preloading the batch's meta in one query avoids an N+1.)

- [ ] **Step 2: Enable the post-meta cache in the shortcode query**

In `includes/class-shortcode.php`, in `build_query_args()`, change the same line:

```php
            'no_found_rows'     => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
            'suppress_filters'  => false,
```

- [ ] **Step 3: Static-cache the Elementor event options**

In `includes/elementor/class-elementor.php`, add a static cache property to the class (just below the class brace / existing constants):

```php
    /**
     * Cached event-options list (built once per request).
     *
     * @var array|null
     */
    private static $event_options_cache = null;
```

Then change `event_options()` to memoize:

```php
    public static function event_options() {
        if (null !== self::$event_options_cache) {
            return self::$event_options_cache;
        }

        $options = array('' => __('— Current event —', 'simple_events'));
        $events = get_posts(array(
            'post_type'        => 'simple-events',
            'post_status'      => 'publish',
            'numberposts'      => 50,
            'orderby'          => 'title',
            'order'            => 'ASC',
            'suppress_filters' => false,
        ));
        foreach ($events as $event) {
            $options[$event->ID] = $event->post_title;
        }

        self::$event_options_cache = $options;
        return $options;
    }
```

- [ ] **Step 4: Syntax-check**

Run (if PHP CLI available): `php -l includes/class-ajax.php && php -l includes/class-shortcode.php && php -l includes/elementor/class-elementor.php`
Expected: `No syntax errors detected` for each.

- [ ] **Step 5: Commit**

```bash
git add includes/class-ajax.php includes/class-shortcode.php includes/elementor/class-elementor.php
git commit -m "perf: address outstanding PR #3 review (meta cache + cached event options)"
```

---

## Task 2: Remove the empty-state settings; hardcode the message

**Files:**
- Modify: `includes/functions.php` (`simple_events_get_setting_defaults`)
- Modify: `includes/class-settings.php` (`sanitize`, `render_page`)
- Modify: `includes/class-shortcode.php` (`render_no_events_message`)

- [ ] **Step 1: Drop empty-state defaults, add the uninstall default**

In `includes/functions.php`, in `simple_events_get_setting_defaults()`, remove the two empty-state lines and the comment above them, and add the new uninstall key. The returned array's relevant region becomes:

```php
        // Caching.
        'cache_ttl' => 15, // minutes.

        // Infinite scroll batch size.
        'load_increment' => 6,

        // Recurrence limits (also exposed as filters).
        'recur_max_occurrences'    => 1000,
        'recur_max_horizon_months' => 60,

        // SEO.
        'enable_schema' => 'yes',

        // Data lifecycle: 'no' keeps events on uninstall (default), 'yes' deletes.
        'delete_data_on_uninstall' => 'no',
```

(The `empty_state_heading` / `empty_state_text` keys are deleted entirely.)

- [ ] **Step 2: Update the sanitize callback**

In `includes/class-settings.php`, in `sanitize()`, delete the "Empty-state copy" block:

```php
        // Empty-state copy.
        $clean['empty_state_heading'] = isset($input['empty_state_heading'])
            ? sanitize_text_field($input['empty_state_heading'])
            : $defaults['empty_state_heading'];
        $clean['empty_state_text'] = isset($input['empty_state_text'])
            ? sanitize_text_field($input['empty_state_text'])
            : $defaults['empty_state_text'];
```

It is replaced by nothing here (the uninstall toggle is added in Task 4; this task only removes empty-state).

- [ ] **Step 3: Remove the Empty state section from the settings UI**

In `includes/class-settings.php`, in `render_page()`, delete this whole block:

```php
                <h2><?php echo esc_html__('Empty state', 'simple_events'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__('Heading', 'simple_events'); ?></th>
                        <td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[empty_state_heading]" value="<?php echo esc_attr($settings['empty_state_heading']); ?>" placeholder="<?php esc_attr_e('No Events Found', 'simple_events'); ?>" />
                            <p class="description"><?php echo esc_html__('Leave blank to use the default.', 'simple_events'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Message', 'simple_events'); ?></th>
                        <td>
                            <input type="text" class="large-text" name="<?php echo esc_attr(self::OPTION); ?>[empty_state_text]" value="<?php echo esc_attr($settings['empty_state_text']); ?>" />
                            <p class="description"><?php echo esc_html__('Leave blank to use the context-aware default message.', 'simple_events'); ?></p>
                        </td>
                    </tr>
                </table>
```

- [ ] **Step 4: Hardcode the empty message in the shortcode**

In `includes/class-shortcode.php`, replace `render_no_events_message()` with the version that no longer reads settings:

```php
    private function render_no_events_message($atts)
    {
        $heading = __('No Events Found', 'simple_events');

        echo '<div class="simple-events-calendar simple-events-no-events">';
        echo '<div class="simple-events-empty-state">';
        echo '<h3>' . esc_html($heading) . '</h3>';

        if (!empty($atts['category'])) {
            /* translators: %s: category slug */
            echo '<p>' . sprintf(esc_html__('No events found in the "%s" category.', 'simple_events'), esc_html($atts['category'])) . '</p>';
        } elseif (!$atts['show_past']) {
            echo '<p>' . esc_html__('No upcoming events scheduled. Check back soon!', 'simple_events') . '</p>';
        } else {
            echo '<p>' . esc_html__('No events have been created yet.', 'simple_events') . '</p>';
        }

        if (current_user_can('edit_posts')) {
            $admin_url = admin_url('post-new.php?post_type=simple-events');
            echo '<p><a href="' . esc_url($admin_url) . '" class="button">' . esc_html__('Add New Event', 'simple_events') . '</a></p>';
        }

        echo '</div>';
        echo '</div>';
    }
```

(Also fixes the previously un-escaped/untranslated "Add New Event" string.)

- [ ] **Step 5: Confirm nothing else references the removed keys**

Run: `grep -rn "empty_state_heading\|empty_state_text" includes templates template-parts`
Expected: no matches. If any appear (e.g., templates), update them to use the hardcoded heading like above.

- [ ] **Step 6: Syntax-check and manual check**

Run (if available): `php -l includes/functions.php && php -l includes/class-settings.php && php -l includes/class-shortcode.php`
Manual: load **Events → Settings** — the "Empty state" section is gone. Put `[sec_events category="nonexistent"]` on a page — the no-events block shows "No Events Found" + the category message.

- [ ] **Step 7: Commit**

```bash
git add includes/functions.php includes/class-settings.php includes/class-shortcode.php
git commit -m "feat: replace editable empty-state settings with a static translatable message"
```

---

## Task 3: Date-format presets + custom field

**Files:**
- Modify: `includes/class-settings.php` (`render_page`, `sanitize`, constructor, new enqueue method)
- Create: `assets/js/simple-events-settings.js`

- [ ] **Step 1: Render the preset picker + custom field**

In `includes/class-settings.php` `render_page()`, replace the entire "Date format" `<tr>` (the one with `name="...[date_format]"`) with:

```php
                    <tr>
                        <th scope="row"><?php echo esc_html__('Date format', 'simple_events'); ?></th>
                        <td>
                            <?php
                            $presets = array(
                                'l, F j, Y' => __('Weekday, Month Day, Year', 'simple_events'),
                                'F j, Y'    => __('Month Day, Year', 'simple_events'),
                                'm/d/Y'     => __('MM/DD/YYYY', 'simple_events'),
                                'M j, Y'    => __('Abbreviated Month Day, Year', 'simple_events'),
                            );
                            $current  = (string) $settings['date_format'];
                            $is_preset = array_key_exists($current, $presets);
                            ?>
                            <select id="sec-date-format-preset" name="<?php echo esc_attr(self::OPTION); ?>[date_format_preset]">
                                <?php foreach ($presets as $fmt => $label) : ?>
                                    <option value="<?php echo esc_attr($fmt); ?>" <?php selected($is_preset && $current === $fmt); ?>>
                                        <?php echo esc_html($label . ' — ' . wp_date($fmt)); ?>
                                    </option>
                                <?php endforeach; ?>
                                <option value="custom" <?php selected(!$is_preset); ?>><?php echo esc_html__('Custom…', 'simple_events'); ?></option>
                            </select>
                            <span id="sec-date-format-custom-wrap" style="<?php echo $is_preset ? 'display:none;' : ''; ?>">
                                <input type="text" id="sec-date-format-custom" class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[date_format_custom]" value="<?php echo esc_attr(!$is_preset ? $current : ''); ?>" placeholder="l, F j, Y" />
                            </span>
                            <p class="description">
                                <?php
                                printf(
                                    /* translators: %s: example formatted date */
                                    esc_html__('Preview: %s', 'simple_events'),
                                    '<strong id="sec-date-format-preview">' . esc_html(wp_date($current)) . '</strong>'
                                );
                                ?>
                                <br />
                                <?php echo esc_html__('Choose a preset, or pick "Custom…" to enter a PHP date format.', 'simple_events'); ?>
                                <a href="https://wordpress.org/documentation/article/customize-date-and-time-format/" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Format reference', 'simple_events'); ?></a>
                            </p>
                        </td>
                    </tr>
```

- [ ] **Step 2: Resolve the final format in sanitize()**

In `includes/class-settings.php` `sanitize()`, replace the existing date-format block:

```php
        // Date format: allow a non-empty PHP format string, else default.
        $date_format = isset($input['date_format']) ? trim((string) $input['date_format']) : '';
        $clean['date_format'] = ('' !== $date_format) ? sanitize_text_field($date_format) : $defaults['date_format'];
```

with:

```php
        // Date format: a preset value, or a custom PHP format when "custom" is chosen.
        $preset = isset($input['date_format_preset']) ? (string) $input['date_format_preset'] : '';
        if ('custom' === $preset) {
            $custom = isset($input['date_format_custom']) ? trim((string) $input['date_format_custom']) : '';
            $clean['date_format'] = ('' !== $custom) ? sanitize_text_field($custom) : $defaults['date_format'];
        } elseif ('' !== $preset) {
            $clean['date_format'] = sanitize_text_field($preset);
        } else {
            $clean['date_format'] = $defaults['date_format'];
        }
```

- [ ] **Step 3: Enqueue the settings script**

In `includes/class-settings.php` constructor, add:

```php
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
```

Then add this method to the class:

```php
    /**
     * Enqueue the settings-page script (date-format preset toggle + preview).
     *
     * @param string $hook Current admin page hook suffix.
     */
    public function enqueue_assets($hook) {
        // Submenu pages under a CPT use the "<post_type>_page_<slug>" hook form.
        if (false === strpos((string) $hook, self::PAGE)) {
            return;
        }

        wp_enqueue_script(
            'simple-events-settings',
            PLUGIN_ASSETS . '/js/simple-events-settings.js',
            array(),
            PLUGIN_VERSION,
            true
        );
    }
```

- [ ] **Step 4: Create the settings script**

Create `assets/js/simple-events-settings.js`:

```javascript
/**
 * Simple Events Calendar — settings page.
 *
 * Toggles the custom date-format field when "Custom…" is selected and keeps the
 * live preview roughly in sync. No build step; plain DOM APIs.
 */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    ready(function () {
        var preset = document.getElementById('sec-date-format-preset');
        var wrap = document.getElementById('sec-date-format-custom-wrap');
        var custom = document.getElementById('sec-date-format-custom');
        var preview = document.getElementById('sec-date-format-preview');
        if (!preset || !wrap) {
            return;
        }

        function activeFormat() {
            if (preset.value === 'custom') {
                return custom ? custom.value : '';
            }
            return preset.value;
        }

        // Lightweight client-side preview for the common tokens. The server
        // renders the authoritative preview on save; this is just a hint.
        function previewFor(fmt) {
            var d = new Date();
            var days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            var months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
            var pad = function (n) { return (n < 10 ? '0' : '') + n; };
            var map = {
                l: days[d.getDay()],
                D: days[d.getDay()].slice(0, 3),
                F: months[d.getMonth()],
                M: months[d.getMonth()].slice(0, 3),
                j: d.getDate(),
                d: pad(d.getDate()),
                n: d.getMonth() + 1,
                m: pad(d.getMonth() + 1),
                Y: d.getFullYear(),
                y: String(d.getFullYear()).slice(-2)
            };
            return String(fmt).replace(/\\?([a-zA-Z])/g, function (match, ch) {
                if (match.charAt(0) === '\\') { return ch; }
                return Object.prototype.hasOwnProperty.call(map, ch) ? map[ch] : ch;
            });
        }

        function sync() {
            var isCustom = preset.value === 'custom';
            wrap.style.display = isCustom ? '' : 'none';
            if (preview) {
                preview.textContent = previewFor(activeFormat());
            }
        }

        preset.addEventListener('change', sync);
        if (custom) {
            custom.addEventListener('input', sync);
        }
        sync();
    });
})();
```

- [ ] **Step 5: Syntax-check and manual check**

Run (if available): `php -l includes/class-settings.php`
Manual: **Events → Settings** → Date format shows a dropdown of 4 presets + "Custom…". Selecting "Custom…" reveals the text box; preview updates live. Save with a preset → reload keeps that preset selected. Save with a custom format (e.g. `D, M j`) → reload shows "Custom…" selected and the field populated. Front-end event dates reflect the chosen format.

- [ ] **Step 6: Commit**

```bash
git add includes/class-settings.php assets/js/simple-events-settings.js
git commit -m "feat: add date-format presets with a custom option on the settings page"
```

---

## Task 4: Opt-in delete-on-uninstall

**Files:**
- Modify: `includes/class-settings.php` (`sanitize`, `render_page`)
- Modify: `includes/class-main.php` (`uninstall`)

- [ ] **Step 1: Sanitize the new toggle**

In `includes/class-settings.php` `sanitize()`, extend the yes/no toggle loop to include the new key:

```php
        // Yes/no toggles.
        foreach (array('show_past', 'show_time', 'show_excerpt', 'show_location', 'show_footer', 'enable_schema', 'delete_data_on_uninstall') as $flag) {
            $clean[$flag] = (isset($input[$flag]) && 'yes' === (string) $input[$flag]) ? 'yes' : 'no';
        }
```

- [ ] **Step 2: Render the Data section**

In `includes/class-settings.php` `render_page()`, add this block immediately before the `<?php submit_button(); ?>` line:

```php
                <h2><?php echo esc_html__('Data', 'simple_events'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__('When the plugin is deleted', 'simple_events'); ?></th>
                        <td>
                            <label><input type="radio" name="<?php echo esc_attr(self::OPTION); ?>[delete_data_on_uninstall]" value="no" <?php checked($settings['delete_data_on_uninstall'], 'no'); ?> /> <?php echo esc_html__('Keep all events and settings (recommended)', 'simple_events'); ?></label><br />
                            <label><input type="radio" name="<?php echo esc_attr(self::OPTION); ?>[delete_data_on_uninstall]" value="yes" <?php checked($settings['delete_data_on_uninstall'], 'yes'); ?> /> <?php echo esc_html__('Permanently delete all events, categories, and settings', 'simple_events'); ?></label>
                            <p class="description"><?php echo esc_html__('Deletion only happens when you delete the plugin from the Plugins screen — never on deactivation. Keep this on "Keep" if you may reinstall later.', 'simple_events'); ?></p>
                        </td>
                    </tr>
                </table>
```

- [ ] **Step 3: Gate the uninstall routine on the setting**

In `includes/class-main.php`, replace the body of `uninstall()` so it bails unless the user opted in:

```php
    public static function uninstall() {
        // Respect the admin's data-retention choice. Default is to retain, so a
        // reinstall keeps existing events. Only an explicit opt-in deletes data.
        $settings = get_option('simple_events_settings');
        $delete = is_array($settings) && isset($settings['delete_data_on_uninstall'])
            ? ('yes' === $settings['delete_data_on_uninstall'])
            : false;

        if (!$delete) {
            return;
        }

        // Delete all simple-events posts
        $events = get_posts(array(
            'post_type' => 'simple-events',
            'numberposts' => -1,
            'post_status' => 'any'
        ));

        foreach ($events as $event) {
            wp_delete_post($event->ID, true);
        }

        // Delete taxonomies
        $terms = get_terms(array(
            'taxonomy' => 'simple-events-cat',
            'hide_empty' => false
        ));

        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                wp_delete_term($term->term_id, 'simple-events-cat');
            }
        }

        // Clean up transients and options
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '_transient_simple_events_%'));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '_transient_timeout_simple_events_%'));
        delete_option('simple_events_settings');

        flush_rewrite_rules();
    }
```

- [ ] **Step 4: Syntax-check and manual check**

Run (if available): `php -l includes/class-settings.php && php -l includes/class-main.php`
Manual (on a staging site you can afford to break): default setting → delete the plugin via the Plugins screen → reinstall/reactivate → events still present. Switch the setting to "Permanently delete…", delete the plugin → events, terms, and the option are gone.

- [ ] **Step 5: Commit**

```bash
git add includes/class-settings.php includes/class-main.php
git commit -m "feat: add opt-in delete-on-uninstall (retain event data by default)"
```

---

## Task 5: Shared card SCSS polish + list modifier + columns

**Files:**
- Modify: `src/css/simple-events.scss`
- Generated: `assets/css/simple-events.css` (via build — never hand-edited)

- [ ] **Step 1: Add Sass variables at the top of the stylesheet**

At the very top of `src/css/simple-events.scss` (before the first rule), add a variables block using proper Sass syntax:

```scss
// Shared card design tokens.
$sec-card-radius: 5px;
$sec-card-shadow: 0 1px 4px rgba(0, 0, 0, 0.08), 0 3px 10px rgba(0, 0, 0, 0.05);
$sec-title-size: 22px;
$sec-list-image-width: 240px;
```

- [ ] **Step 2: Apply the shared polish to the card**

In `src/css/simple-events.scss`, update the existing card and thumbnail rules. Change the `.simple-events-calendar__post` rule and the thumbnail rule to use nesting and the tokens (replace the existing `.simple-events-calendar__post`, `.simple-events-calendar__post__thumbnail`, and `.simple-events-calendar__post__title` rules with these):

```scss
.simple-events-calendar__post {
  display: flex;
  flex-direction: column;
  height: 100%; // Equal-height cards.
  background: #fff;
  border: 0;
  border-radius: $sec-card-radius;
  box-shadow: $sec-card-shadow;
  overflow: hidden;

  &__thumbnail {
    position: relative;
    width: 100%;
    aspect-ratio: 3 / 2; // Grid images are 3:2.
    overflow: hidden;
    margin-bottom: 16px;

    img {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      object-fit: cover;
    }
  }

  &__title {
    margin: 0;
    font-size: $sec-title-size;
    line-height: 1.25;
  }
}
```

(Note: removing the standalone `.simple-events-calendar__post__thumbnail` / `__title` rules that previously set `aspect-ratio: 4 / 3`, `margin-bottom: 30px`, and `margin: 0` — they are now nested above. Leave the other `__description`, `__meta`, etc. rules in place.)

- [ ] **Step 3: Add the list-layout modifier and widget columns at the end of the file**

Append to `src/css/simple-events.scss`:

```scss
// List layout (Elementor List mode + [sec_event layout="list"]).
.simple-events-calendar.sec-layout-list {
  display: block;

  .simple-events-calendar__post {
    flex-direction: row;
    flex-wrap: wrap;
    align-items: stretch;
    margin-bottom: 18px;
  }

  .simple-events-calendar__post__thumbnail {
    flex: 0 0 $sec-list-image-width;
    width: $sec-list-image-width;
    margin-bottom: 0;
  }

  .simple-events-calendar__post__description {
    flex: 1 1 300px;
  }

  .simple-events-calendar__post__footer {
    flex-basis: 100%;
  }

  @media screen and (width <= 600px) {
    .simple-events-calendar__post {
      flex-direction: column;
    }

    .simple-events-calendar__post__thumbnail {
      flex-basis: auto;
      width: 100%;
    }
  }
}

// Elementor Events Grid column override (scoped by Elementor's wrapper).
.simple-events-calendar.sec-grid-columns {
  grid-template-columns: repeat(var(--sec-columns, 3), 1fr);
}
```

- [ ] **Step 4: Lint and build**

Run: `npm run lint:css`
Expected: no errors (warnings about the existing file are acceptable if pre-existing; do not introduce new ones in added code).
Run: `npm run build`
Expected: `assets/css/simple-events.css` regenerated, no source map. Confirm the file's mtime updated.

- [ ] **Step 5: Manual check**

Manual: view an existing `[sec_events]` page — cards now have 5px corners, a soft shadow, no border, 22px titles, and 3:2 images.

- [ ] **Step 6: Commit**

```bash
git add src/css/simple-events.scss assets/css/simple-events.css
git commit -m "style: polish shared event card (radius, shadow, 3:2 image, 22px title) + list/columns modifiers"
```

---

## Task 6: Shared render helpers (grid + single event)

**Files:**
- Modify: `includes/functions.php` (add two functions)

These helpers are the single source for the new widgets and the `[sec_event]` shortcode. They reuse the existing card template.

- [ ] **Step 1: Add the grid render helper**

Append to `includes/functions.php` (before the final closing — these are top-level functions):

```php
/**
 * Render a grid/list of events as an HTML string.
 *
 * Used by the Elementor Events Grid widget. Builds the same kind of query the
 * [sec_events] shortcode uses (event_date / Ymd ordering, upcoming-only unless
 * show_past) and renders the shared card template for each event.
 *
 * @param array $args {
 *     @type int    $posts_per_page Number of events (1-50). Default 6.
 *     @type string $category       Category slug filter. Default ''.
 *     @type bool   $show_past      Include past events. Default false.
 *     @type string $order          ASC|DESC. Default 'ASC'.
 *     @type string $layout         'grid'|'list'. Default 'grid'.
 *     @type bool   $show_time      Default true.
 *     @type bool   $show_excerpt   Default true.
 *     @type bool   $show_location  Default true.
 *     @type bool   $show_footer    Default true.
 * }
 * @return string
 */
function simple_events_render_events_grid($args = array()) {
    $defaults = array(
        'posts_per_page' => 6,
        'category'       => '',
        'show_past'      => false,
        'order'          => 'ASC',
        'layout'         => 'grid',
        'show_time'      => true,
        'show_excerpt'   => true,
        'show_location'  => true,
        'show_footer'    => true,
    );
    $args = wp_parse_args($args, $defaults);

    $ppp   = max(1, min(50, (int) $args['posts_per_page']));
    $order = in_array(strtoupper((string) $args['order']), array('ASC', 'DESC'), true) ? strtoupper((string) $args['order']) : 'ASC';

    $query_args = array(
        'post_type'              => 'simple-events',
        'post_status'            => 'publish',
        'posts_per_page'         => $ppp,
        'orderby'                => 'meta_value',
        'order'                  => $order,
        'meta_key'               => 'event_date',
        'meta_type'              => 'DATE',
        'no_found_rows'          => true,
        'update_post_meta_cache' => true,
        'update_post_term_cache' => false,
        'suppress_filters'       => false,
    );

    if (empty($args['show_past'])) {
        $query_args['meta_query'] = array(
            'relation' => 'AND',
            array(
                'key'     => 'event_date',
                'compare' => '>=',
                'value'   => current_time('Ymd'),
                'type'    => 'DATE',
            ),
        );
    }

    if (!empty($args['category'])) {
        $query_args['tax_query'] = array(
            array(
                'taxonomy' => 'simple-events-cat',
                'field'    => 'slug',
                'terms'    => sanitize_text_field((string) $args['category']),
            ),
        );
    }

    $query = new WP_Query($query_args);

    if (!$query->have_posts()) {
        wp_reset_postdata();
        return '<div class="simple-events-calendar simple-events-no-events"><div class="simple-events-empty-state"><h3>'
            . esc_html__('No Events Found', 'simple_events') . '</h3><p>'
            . esc_html__('No upcoming events scheduled. Check back soon!', 'simple_events')
            . '</p></div></div>';
    }

    $layout_class = ('list' === $args['layout']) ? ' sec-layout-list' : '';
    $flags = array(
        'show_time'     => !empty($args['show_time']),
        'show_excerpt'  => !empty($args['show_excerpt']),
        'show_location' => !empty($args['show_location']),
        'show_footer'   => !empty($args['show_footer']),
    );

    ob_start();
    echo '<div class="simple-events-calendar' . esc_attr($layout_class) . '">';
    while ($query->have_posts()) {
        $query->the_post();
        simple_events_render_event_card($flags);
    }
    echo '</div>';
    wp_reset_postdata();

    return (string) ob_get_clean();
}
```

- [ ] **Step 2: Add the single-event render helper**

Append to `includes/functions.php`:

```php
/**
 * Render a single event (by ID) as a card or list row, returned as HTML.
 *
 * Used by the [sec_event] shortcode and the Single Event Elementor widget so
 * both produce identical markup. Returns '' when the post is missing or is not
 * a published simple-events post.
 *
 * @param int    $post_id Event post ID.
 * @param array  $flags   show_time, show_excerpt, show_location, show_footer (bools).
 * @param string $layout  'card'|'list'. Default 'card'.
 * @return string
 */
function simple_events_render_single_event($post_id, $flags = array(), $layout = 'card') {
    $post_id = (int) $post_id;
    $post = $post_id ? get_post($post_id) : null;

    if (!$post || 'simple-events' !== $post->post_type || 'publish' !== $post->post_status) {
        return '';
    }

    $flags = wp_parse_args($flags, array(
        'show_time'     => true,
        'show_excerpt'  => true,
        'show_location' => true,
        'show_footer'   => true,
    ));

    $layout_class = ('list' === $layout) ? ' sec-layout-list' : '';

    global $post;
    $original = $post;
    $post = get_post($post_id); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
    setup_postdata($post);

    ob_start();
    echo '<div class="simple-events-calendar simple-events-single' . esc_attr($layout_class) . '">';
    simple_events_render_event_card($flags);
    echo '</div>';
    $html = (string) ob_get_clean();

    wp_reset_postdata();
    $post = $original; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

    return $html;
}
```

- [ ] **Step 3: Syntax-check**

Run (if available): `php -l includes/functions.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add includes/functions.php
git commit -m "feat: add shared events-grid and single-event render helpers"
```

---

## Task 7: `[sec_event]` single-event shortcode + register the front-end style handle

**Files:**
- Modify: `includes/class-main.php` (register style handle in `enqueue_scripts` path)
- Modify: `includes/class-shortcode.php` (register `[sec_event]`, handler, enqueue style)

- [ ] **Step 1: Register the front-end stylesheet so it can be enqueued on demand**

In `includes/class-main.php`, in `enqueue_scripts()`, the style is currently only enqueued on event pages. To let shortcodes/widgets enqueue it anywhere, register it on `wp_enqueue_scripts` always (registering is cheap and does not output anything). Add a new method and hook.

In `init()` add the registration hook just after the existing `wp_enqueue_scripts` line:

```php
        add_action('wp_enqueue_scripts', array($this, 'register_assets'), 1);
```

Add the method:

```php
    /**
     * Register (but do not enqueue) the front-end stylesheet so shortcodes and
     * Elementor widgets can enqueue it on demand on non-event pages.
     */
    public function register_assets() {
        if (!wp_style_is('simple-events-style', 'registered')) {
            wp_register_style(
                'simple-events-style',
                PLUGIN_ASSETS . '/css/simple-events.css',
                array(),
                $this->version
            );
        }
    }
```

In the existing `enqueue_scripts()`, change the `wp_enqueue_style('simple-events-style', …)` call to simply enqueue the registered handle:

```php
        wp_enqueue_style('simple-events-style');
```

(Leave the `wp_enqueue_script`/`wp_localize_script` block unchanged.)

- [ ] **Step 2: Register the `[sec_event]` shortcode**

In `includes/class-shortcode.php` `init_hooks()`, add:

```php
        add_shortcode('sec_event', array($this, 'render_single_shortcode'));
```

- [ ] **Step 3: Implement the handler**

Add to `includes/class-shortcode.php`:

```php
    /**
     * Render a single event by ID: [sec_event id="123" layout="card|list"].
     *
     * @param array $atts Shortcode attributes.
     * @return string
     */
    public function render_single_shortcode($atts)
    {
        $settings = simple_events_get_settings();
        $atts = shortcode_atts(array(
            'id'            => 0,
            'layout'        => 'card',
            'show_time'     => $settings['show_time'],
            'show_excerpt'  => $settings['show_excerpt'],
            'show_location' => $settings['show_location'],
            'show_footer'   => $settings['show_footer'],
        ), $atts, 'sec_event');

        $post_id = absint($atts['id']);
        if (!$post_id) {
            if (current_user_can('edit_posts')) {
                return '<p class="simple-events-notice">' . esc_html__('[sec_event] requires an "id" attribute, e.g. [sec_event id="123"].', 'simple_events') . '</p>';
            }
            return '';
        }

        $layout = ('list' === strtolower((string) $atts['layout'])) ? 'list' : 'card';
        $flags = array(
            'show_time'     => 'no' !== (string) $atts['show_time'],
            'show_excerpt'  => 'no' !== (string) $atts['show_excerpt'],
            'show_location' => 'no' !== (string) $atts['show_location'],
            'show_footer'   => 'no' !== (string) $atts['show_footer'],
        );

        $html = simple_events_render_single_event($post_id, $flags, $layout);
        if ('' === $html) {
            return '';
        }

        wp_enqueue_style('simple-events-style');
        return $html;
    }
```

- [ ] **Step 4: Ensure the style enqueues for `[sec_events]` too on arbitrary pages**

In `includes/class-shortcode.php` `enqueue_scripts()` (the shortcode class's own method that loads `simple-events-shortcode.js`), add a `wp_enqueue_style('simple-events-style');` inside the `if (… has_shortcode(…, 'sec_events'))` block so the grid styling is present even if the main gate missed it:

```php
        if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'sec_events')) {
            wp_enqueue_style('simple-events-style');
            wp_enqueue_script(
```

- [ ] **Step 5: Syntax-check and manual check**

Run (if available): `php -l includes/class-main.php && php -l includes/class-shortcode.php`
Manual: on a normal page add `[sec_event id="<a real event id>"]` → the event renders as a card with styling. Add `layout="list"` → renders as an image-left row. `[sec_event]` with no id → editors see the notice, anonymous visitors see nothing. Invalid id → nothing.

- [ ] **Step 6: Commit**

```bash
git add includes/class-main.php includes/class-shortcode.php
git commit -m "feat: add [sec_event] single-event shortcode and on-demand style enqueue"
```

---

## Task 8: Gate the per-element Elementor widgets

**Files:**
- Modify: `includes/elementor/class-elementor.php` (`in_event_context`, simplify `resolve_event_id`)
- Modify: `includes/elementor/widgets.php` (drop picker, context-gated render + hint)

- [ ] **Step 1: Add the context check and simplify resolution**

In `includes/elementor/class-elementor.php`, add:

```php
    /**
     * Whether the current request is rendering in an event context (single
     * event, event archive/taxonomy main loop, Elementor Loop item, or a
     * Theme Builder single template previewing a simple-events post).
     *
     * @return bool
     */
    public static function in_event_context() {
        $id = (int) get_the_ID();
        if ($id && 'simple-events' === get_post_type($id)) {
            return true;
        }
        $queried = (int) get_queried_object_id();
        if ($queried && 'simple-events' === get_post_type($queried)) {
            return true;
        }
        return false;
    }
```

Replace `resolve_event_id()` with a loop/queried-only resolver (the element widgets no longer carry a picker):

```php
    /**
     * Resolve the event ID to render for an element widget. Loop/queried event
     * only — these widgets are gated to event contexts and have no picker.
     *
     * @return int
     */
    public static function resolve_event_id() {
        $id = (int) get_the_ID();
        if ($id && 'simple-events' === get_post_type($id)) {
            return $id;
        }
        $queried = (int) get_queried_object_id();
        if ($queried && 'simple-events' === get_post_type($queried)) {
            return $queried;
        }
        return 0;
    }
```

(`is_elementor_edit_mode()` is retained — it is used by the render hint below.)

- [ ] **Step 2: Drop the preview picker and gate render in the base widget**

In `includes/elementor/widgets.php`, in `Simple_Events_Widget_Base::register_controls()`, remove the `sec_preview_event` control block entirely:

```php
        $this->add_control(
            'sec_preview_event',
            array(
                'label'       => __('Preview event', 'simple_events'),
                'description' => __('Used in the editor only. On the front end the current event is used automatically.', 'simple_events'),
                'type'        => \Elementor\Controls_Manager::SELECT2,
                'options'     => Simple_Events_Elementor::event_options(),
                'default'     => '',
            )
        );
```

Replace the `render()` method with the gated version:

```php
    /**
     * Render the widget. Gated to event contexts: outside one, render nothing
     * on the front end and a hint in the Elementor editor.
     */
    protected function render() {
        $post_id = Simple_Events_Elementor::resolve_event_id();

        if (!$post_id) {
            if (Simple_Events_Elementor::is_edit_hint_allowed()) {
                echo '<span class="sec-elementor-hint" style="display:block;padding:10px 12px;border:1px dashed #c3c4c7;border-radius:4px;color:#646970;font-size:12px;">'
                    . esc_html__('Displays the current event. Use this element inside a single-event template, an event archive, or a Loop Grid.', 'simple_events')
                    . '</span>';
            }
            return;
        }

        $method = array('Simple_Events_Renderer', $this->sec_key());
        if (!is_callable($method)) {
            return;
        }

        // Renderer output is escaped within the renderer.
        echo call_user_func($method, $post_id, $this->sec_render_args()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
```

- [ ] **Step 3: Expose the editor-hint check**

In `includes/elementor/class-elementor.php`, `is_elementor_edit_mode()` is currently `private`. Add a public wrapper used by the widget hint (keep the private one):

```php
    /**
     * Public accessor: whether to show editor-only hints (edit/preview mode).
     *
     * @return bool
     */
    public static function is_edit_hint_allowed() {
        return self::is_elementor_edit_mode();
    }
```

- [ ] **Step 4: Syntax-check and manual check**

Run (if available): `php -l includes/elementor/class-elementor.php && php -l includes/elementor/widgets.php`
Manual (Elementor active): on a plain page, drag in "Event Title" → editor shows the dashed hint, front end shows nothing. Inside a Loop Grid bound to events, or on a single-event Theme Builder template, the same widget renders the event's title. The "Preview event" control is gone from all element widgets.

- [ ] **Step 5: Commit**

```bash
git add includes/elementor/class-elementor.php includes/elementor/widgets.php
git commit -m "feat: gate per-element Elementor widgets to event-loop contexts"
```

---

## Task 9: Elementor Events Grid + Single Event widgets

**Files:**
- Create: `includes/elementor/display-widgets.php`
- Modify: `includes/elementor/class-elementor.php` (`register_widgets`)

- [ ] **Step 1: Register the new widgets**

In `includes/elementor/class-elementor.php` `register_widgets()`, require the new file and register the two widgets:

```php
    public static function register_widgets($widgets_manager) {
        require_once __DIR__ . '/widgets.php';
        require_once __DIR__ . '/display-widgets.php';

        $widgets_manager->register(new Simple_Events_Widget_Title());
        $widgets_manager->register(new Simple_Events_Widget_Image());
        $widgets_manager->register(new Simple_Events_Widget_Date());
        $widgets_manager->register(new Simple_Events_Widget_Time());
        $widgets_manager->register(new Simple_Events_Widget_Location());
        $widgets_manager->register(new Simple_Events_Widget_Excerpt());
        $widgets_manager->register(new Simple_Events_Widget_Content());
        $widgets_manager->register(new Simple_Events_Widget_Categories());
        $widgets_manager->register(new Simple_Events_Widget_Button());
        $widgets_manager->register(new Simple_Events_Widget_Grid());
        $widgets_manager->register(new Simple_Events_Widget_Single());
    }
```

- [ ] **Step 2: Create the display widgets file**

Create `includes/elementor/display-widgets.php`:

```php
<?php

/**
 * Elementor display widgets for Simple Events Calendar.
 *
 * Standalone listing widgets (not gated): an Events Grid (grid or image-left
 * list) and a Single Event (chosen via a searchable picker). Both reuse the
 * shared render helpers so output matches the [sec_events] / [sec_event]
 * shortcodes and the default templates.
 *
 * Required only from Simple_Events_Elementor::register_widgets(), so the
 * Elementor base class is guaranteed to exist here.
 *
 * @package Simple_Events_Calendar
 * @since 5.1.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Events Grid widget — grid or list of events.
 */
class Simple_Events_Widget_Grid extends \Elementor\Widget_Base {

    public function get_name() { return 'sec-events-grid'; }
    public function get_title() { return __('Events Grid', 'simple_events'); }
    public function get_icon() { return 'eicon-gallery-grid'; }
    public function get_categories() { return array('simple-events'); }
    public function get_keywords() { return array('event', 'events', 'calendar', 'grid', 'list'); }
    public function get_style_depends() { return array('simple-events-style'); }

    protected function register_controls() {
        $this->start_controls_section('sec_grid_content', array(
            'label' => __('Events', 'simple_events'),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ));

        $this->add_control('layout', array(
            'label'       => __('Layout', 'simple_events'),
            'type'        => \Elementor\Controls_Manager::SELECT,
            'options'     => array(
                'grid' => __('Grid', 'simple_events'),
                'list' => __('List (image left)', 'simple_events'),
            ),
            'default'     => 'grid',
            'prefix_class' => 'sec-layout-',
        ));

        $this->add_responsive_control('columns', array(
            'label'     => __('Columns', 'simple_events'),
            'type'      => \Elementor\Controls_Manager::NUMBER,
            'min'       => 1,
            'max'       => 6,
            'default'   => 3,
            'condition' => array('layout' => 'grid'),
            'selectors' => array(
                '{{WRAPPER}} .simple-events-calendar' => '--sec-columns: {{VALUE}};',
            ),
        ));

        $this->add_control('posts_per_page', array(
            'label'   => __('Number of events', 'simple_events'),
            'type'    => \Elementor\Controls_Manager::NUMBER,
            'min'     => 1,
            'max'     => 50,
            'default' => 6,
        ));

        $this->add_control('category', array(
            'label'   => __('Category', 'simple_events'),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'options' => self::category_options(),
            'default' => '',
        ));

        $this->add_control('order', array(
            'label'   => __('Order', 'simple_events'),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'options' => array(
                'ASC'  => __('Soonest first', 'simple_events'),
                'DESC' => __('Latest first', 'simple_events'),
            ),
            'default' => 'ASC',
        ));

        $this->add_control('show_past', array(
            'label'   => __('Show past events', 'simple_events'),
            'type'    => \Elementor\Controls_Manager::SWITCHER,
            'default' => '',
        ));

        foreach (array(
            'show_time'     => __('Show time', 'simple_events'),
            'show_excerpt'  => __('Show excerpt', 'simple_events'),
            'show_location' => __('Show location', 'simple_events'),
            'show_footer'   => __('Show footer / read-more', 'simple_events'),
        ) as $key => $label) {
            $this->add_control($key, array(
                'label'   => $label,
                'type'    => \Elementor\Controls_Manager::SWITCHER,
                'default' => 'yes',
            ));
        }

        $this->end_controls_section();
    }

    /**
     * Build the category dropdown options (slug => name).
     *
     * @return array
     */
    private static function category_options() {
        $options = array('' => __('All categories', 'simple_events'));
        $terms = get_terms(array(
            'taxonomy'   => 'simple-events-cat',
            'hide_empty' => false,
        ));
        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                $options[$term->slug] = $term->name;
            }
        }
        return $options;
    }

    protected function render() {
        $s = $this->get_settings_for_display();

        // The grid container needs the columns custom-property hook class.
        add_filter('simple_events_grid_extra_class', array($this, 'grid_extra_class'));
        $html = simple_events_render_events_grid(array(
            'posts_per_page' => isset($s['posts_per_page']) ? (int) $s['posts_per_page'] : 6,
            'category'       => isset($s['category']) ? $s['category'] : '',
            'show_past'      => 'yes' === ($s['show_past'] ?? ''),
            'order'          => $s['order'] ?? 'ASC',
            'layout'         => $s['layout'] ?? 'grid',
            'show_time'      => 'yes' === ($s['show_time'] ?? 'yes'),
            'show_excerpt'   => 'yes' === ($s['show_excerpt'] ?? 'yes'),
            'show_location'  => 'yes' === ($s['show_location'] ?? 'yes'),
            'show_footer'    => 'yes' === ($s['show_footer'] ?? 'yes'),
        ));
        remove_filter('simple_events_grid_extra_class', array($this, 'grid_extra_class'));

        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Add the columns-hook class to the grid container.
     *
     * @param string $class Extra container class.
     * @return string
     */
    public function grid_extra_class($class) {
        return trim($class . ' sec-grid-columns');
    }
}

/**
 * Single Event widget — render one chosen event as a card or list row.
 */
class Simple_Events_Widget_Single extends \Elementor\Widget_Base {

    public function get_name() { return 'sec-single-event'; }
    public function get_title() { return __('Single Event', 'simple_events'); }
    public function get_icon() { return 'eicon-single-post'; }
    public function get_categories() { return array('simple-events'); }
    public function get_keywords() { return array('event', 'single', 'calendar'); }
    public function get_style_depends() { return array('simple-events-style'); }

    protected function register_controls() {
        $this->start_controls_section('sec_single_content', array(
            'label' => __('Event', 'simple_events'),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ));

        $this->add_control('event_id', array(
            'label'       => __('Event', 'simple_events'),
            'description' => __('Search by title and select the event to display.', 'simple_events'),
            'type'        => \Elementor\Controls_Manager::SELECT2,
            'label_block' => true,
            'options'     => Simple_Events_Elementor::event_options(),
            'default'     => '',
        ));

        $this->add_control('layout', array(
            'label'        => __('Layout', 'simple_events'),
            'type'         => \Elementor\Controls_Manager::SELECT,
            'options'      => array(
                'card' => __('Card', 'simple_events'),
                'list' => __('List (image left)', 'simple_events'),
            ),
            'default'      => 'card',
            'prefix_class' => 'sec-layout-',
        ));

        foreach (array(
            'show_time'     => __('Show time', 'simple_events'),
            'show_excerpt'  => __('Show excerpt', 'simple_events'),
            'show_location' => __('Show location', 'simple_events'),
            'show_footer'   => __('Show footer / read-more', 'simple_events'),
        ) as $key => $label) {
            $this->add_control($key, array(
                'label'   => $label,
                'type'    => \Elementor\Controls_Manager::SWITCHER,
                'default' => 'yes',
            ));
        }

        $this->end_controls_section();
    }

    protected function render() {
        $s = $this->get_settings_for_display();
        $event_id = isset($s['event_id']) ? absint($s['event_id']) : 0;

        if (!$event_id) {
            if (\Simple_Events_Elementor::is_edit_hint_allowed()) {
                echo '<span class="sec-elementor-hint" style="display:block;padding:10px 12px;border:1px dashed #c3c4c7;border-radius:4px;color:#646970;font-size:12px;">'
                    . esc_html__('Select an event to display.', 'simple_events')
                    . '</span>';
            }
            return;
        }

        $flags = array(
            'show_time'     => 'yes' === ($s['show_time'] ?? 'yes'),
            'show_excerpt'  => 'yes' === ($s['show_excerpt'] ?? 'yes'),
            'show_location' => 'yes' === ($s['show_location'] ?? 'yes'),
            'show_footer'   => 'yes' === ($s['show_footer'] ?? 'yes'),
        );
        $layout = ('list' === ($s['layout'] ?? 'card')) ? 'list' : 'card';

        echo simple_events_render_single_event($event_id, $flags, $layout); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
}
```

- [ ] **Step 3: Wire the grid container extra-class filter**

The grid widget adds `sec-grid-columns` via a filter. Update `simple_events_render_events_grid()` in `includes/functions.php` so the container honors that filter. Change the container `echo` line:

```php
    $extra_class = apply_filters('simple_events_grid_extra_class', '');
    ob_start();
    echo '<div class="simple-events-calendar' . esc_attr($layout_class) . (($extra_class !== '') ? ' ' . esc_attr($extra_class) : '') . '">';
```

(Leave the rest of the function unchanged.)

- [ ] **Step 4: Syntax-check and manual check**

Run (if available): `php -l includes/elementor/display-widgets.php && php -l includes/elementor/class-elementor.php && php -l includes/functions.php`
Manual (Elementor active): the "Simple Events" category now lists **Events Grid** and **Single Event**. Drop Events Grid on a page → renders a 3-column grid; switch Layout to List → image-left rows; change Columns to 2/4 → updates; set a category/order/show toggles → reflected. Drop Single Event → search a title in the picker, select it → renders; switch Card/List → updates. Styling loads on the non-event page.

- [ ] **Step 5: Commit**

```bash
git add includes/elementor/display-widgets.php includes/elementor/class-elementor.php includes/functions.php
git commit -m "feat: add Elementor Events Grid and Single Event widgets"
```

---

## Task 10: Event Details meta box polish (Direction B)

**Files:**
- Modify: `includes/class-meta-box.php` (`render`, `enqueue`)
- Create: `assets/css/simple-events-admin.css`
- Modify: `assets/js/simple-events-admin.js` (live summary)

- [ ] **Step 1: Rewrite the meta box markup**

In `includes/class-meta-box.php`, replace the `render()` method's output markup (everything between `?>` after the `$is_child` calculation and the trailing `<?php` / method end) with the Direction B structure. Replace the existing `<div class="simple-events-meta-box"> … </div>` block with:

```php
        ?>
        <div class="simple-events-meta-box sec-mb">
            <div class="sec-mb__section">
                <p class="sec-mb__section-label"><?php esc_html_e('When & where', 'simple_events'); ?></p>

                <div class="sec-mb__field">
                    <label class="sec-mb__label" for="sec_event_date">
                        <svg class="sec-mb__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                        <?php esc_html_e('Event Date', 'simple_events'); ?> <span class="sec-mb__req"><?php esc_html_e('required', 'simple_events'); ?></span>
                    </label>
                    <input type="date" id="sec_event_date" name="sec_event_date" value="<?php echo esc_attr($date_input); ?>" required />
                </div>

                <div class="sec-mb__grid2">
                    <div class="sec-mb__field">
                        <label class="sec-mb__label" for="sec_event_start_time">
                            <svg class="sec-mb__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                            <?php esc_html_e('Start Time', 'simple_events'); ?>
                        </label>
                        <input type="time" id="sec_event_start_time" name="sec_event_start_time" value="<?php echo esc_attr($start_input); ?>" />
                    </div>
                    <div class="sec-mb__field">
                        <label class="sec-mb__label" for="sec_event_end_time"><?php esc_html_e('End Time', 'simple_events'); ?> <span class="sec-mb__opt"><?php esc_html_e('optional', 'simple_events'); ?></span></label>
                        <input type="time" id="sec_event_end_time" name="sec_event_end_time" value="<?php echo esc_attr($end_input); ?>" />
                    </div>
                </div>

                <div class="sec-mb__field">
                    <label class="sec-mb__label" for="sec_event_location">
                        <svg class="sec-mb__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 21s7-7.5 7-12a7 7 0 1 0-14 0c0 4.5 7 12 7 12z"/><circle cx="12" cy="9" r="2.5"/></svg>
                        <?php esc_html_e('Location', 'simple_events'); ?> <span class="sec-mb__opt"><?php esc_html_e('optional', 'simple_events'); ?></span>
                    </label>
                    <input type="text" id="sec_event_location" name="sec_event_location" class="widefat" maxlength="255" value="<?php echo esc_attr($location); ?>" placeholder="<?php esc_attr_e('e.g., Conference Room A, 123 Main St, or Online', 'simple_events'); ?>" />
                </div>
            </div>

            <?php if ($is_child) : ?>
                <p class="sec-mb__child-note description"><?php esc_html_e('This event is part of a recurring series. Recurrence settings are managed on the series parent.', 'simple_events'); ?></p>
            <?php else : ?>
                <div class="sec-mb__section">
                    <label class="sec-mb__check">
                        <input type="checkbox" id="sec_event_repeats" name="sec_event_repeats" value="1" <?php checked($repeats); ?> data-sec-toggle="recur" />
                        <span class="sec-mb__pill"><?php esc_html_e('Recurring', 'simple_events'); ?></span>
                        <?php esc_html_e('This is a recurring event', 'simple_events'); ?>
                    </label>

                    <div class="sec-mb__recur" data-sec-recur-group>
                        <p class="sec-mb__summary" data-sec-summary></p>

                        <div class="sec-mb__field">
                            <label class="sec-mb__label" for="sec_event_repeat_frequency"><?php esc_html_e('Repeats', 'simple_events'); ?></label>
                            <div class="sec-mb__row">
                                <?php esc_html_e('Every', 'simple_events'); ?>
                                <input type="number" id="sec_event_repeat_interval" name="sec_event_repeat_interval" min="1" step="1" value="<?php echo esc_attr($interval); ?>" style="width:5em;" data-sec-summary-input />
                                <select id="sec_event_repeat_frequency" name="sec_event_repeat_frequency" data-sec-summary-input>
                                    <?php
                                    $freqs = array(
                                        'daily'   => __('day(s)', 'simple_events'),
                                        'weekly'  => __('week(s)', 'simple_events'),
                                        'monthly' => __('month(s)', 'simple_events'),
                                        'yearly'  => __('year(s)', 'simple_events'),
                                    );
                                    foreach ($freqs as $value => $label) {
                                        printf('<option value="%s" %s>%s</option>', esc_attr($value), selected($frequency, $value, false), esc_html($label));
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>

                        <div class="sec-mb__field">
                            <label class="sec-mb__label" for="sec_event_repeat_end_type"><?php esc_html_e('Ends', 'simple_events'); ?></label>
                            <select id="sec_event_repeat_end_type" name="sec_event_repeat_end_type" data-sec-toggle="end-type" data-sec-summary-input>
                                <?php
                                $end_types = array(
                                    'never' => __('Never', 'simple_events'),
                                    'count' => __('After a number of occurrences', 'simple_events'),
                                    'until' => __('On a date', 'simple_events'),
                                );
                                foreach ($end_types as $value => $label) {
                                    printf('<option value="%s" %s>%s</option>', esc_attr($value), selected($end_type, $value, false), esc_html($label));
                                }
                                ?>
                            </select>
                        </div>

                        <div class="sec-mb__field" data-sec-end="count">
                            <label class="sec-mb__label" for="sec_event_repeat_count"><?php esc_html_e('Number of occurrences', 'simple_events'); ?></label>
                            <input type="number" id="sec_event_repeat_count" name="sec_event_repeat_count" min="1" step="1" value="<?php echo esc_attr($count); ?>" data-sec-summary-input />
                        </div>

                        <div class="sec-mb__field" data-sec-end="until">
                            <label class="sec-mb__label" for="sec_event_repeat_until"><?php esc_html_e('Repeat until', 'simple_events'); ?></label>
                            <input type="date" id="sec_event_repeat_until" name="sec_event_repeat_until" value="<?php echo esc_attr($until_input); ?>" data-sec-summary-input />
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
```

(All `name`/`id` attributes are unchanged from the original, so `save()` is unaffected.)

- [ ] **Step 2: Enqueue the admin CSS and localize the summary strings**

In `includes/class-meta-box.php` `enqueue()`, after the existing `wp_enqueue_script('simple-events-admin', …)` call, add the stylesheet and the localized strings:

```php
        wp_enqueue_style(
            'simple-events-admin',
            PLUGIN_ASSETS . '/css/simple-events-admin.css',
            array(),
            PLUGIN_VERSION
        );

        wp_localize_script('simple-events-admin', 'secMetaBox', array(
            'every'    => __('Repeats every', 'simple_events'),
            'units'    => array(
                'daily'   => __('day(s)', 'simple_events'),
                'weekly'  => __('week(s)', 'simple_events'),
                'monthly' => __('month(s)', 'simple_events'),
                'yearly'  => __('year(s)', 'simple_events'),
            ),
            'countOne' => __('%d occurrence', 'simple_events'),
            'countMany'=> __('%d occurrences', 'simple_events'),
            'never'    => __('repeats indefinitely', 'simple_events'),
            'until'    => __('until %s', 'simple_events'),
            'sep'      => ' · ',
        ));
```

- [ ] **Step 3: Create the admin stylesheet**

Create `assets/css/simple-events-admin.css`:

```css
/* Simple Events Calendar — Event Details meta box (admin only; hand-written, not built). */
.sec-mb {
    --sec-wp: #2271b1;
    --sec-bd: #dcdcde;
    --sec-mut: #646970;
    --sec-soft: #f0f6fc;
    border-left: 4px solid var(--sec-wp);
    padding-left: 16px;
}

.sec-mb__section {
    padding: 4px 0 8px;
}

.sec-mb__section + .sec-mb__section {
    border-top: 1px solid var(--sec-bd);
    margin-top: 14px;
    padding-top: 16px;
}

.sec-mb__section-label {
    margin: 0 0 12px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--sec-mut);
}

.sec-mb__field {
    margin-bottom: 14px;
}

.sec-mb__field:last-child {
    margin-bottom: 0;
}

.sec-mb__grid2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
}

.sec-mb__label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-weight: 600;
    margin-bottom: 5px;
}

.sec-mb__icon {
    width: 16px;
    height: 16px;
    color: var(--sec-wp);
    flex: 0 0 auto;
}

.sec-mb__req,
.sec-mb__opt {
    font-size: 11px;
    font-weight: 400;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

.sec-mb__req { color: #b32d2e; }
.sec-mb__opt { color: var(--sec-mut); }

.sec-mb input[type="date"],
.sec-mb input[type="time"],
.sec-mb input[type="number"],
.sec-mb select {
    max-width: 100%;
}

.sec-mb__check {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
}

.sec-mb__pill {
    display: inline-block;
    background: #e7f0f8;
    color: var(--sec-wp);
    border-radius: 999px;
    padding: 2px 9px;
    font-size: 11px;
    font-weight: 600;
}

.sec-mb__recur {
    border: 1px solid #c5d9ed;
    border-radius: 6px;
    padding: 14px;
    margin-top: 12px;
    background: var(--sec-soft);
}

.sec-mb__summary {
    margin: 0 0 12px;
    font-size: 12px;
    color: var(--sec-wp);
    background: #fff;
    border: 1px solid #c5d9ed;
    border-radius: 4px;
    padding: 6px 10px;
}

.sec-mb__summary:empty {
    display: none;
}

.sec-mb__row {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.sec-mb__child-note {
    margin-top: 14px;
}
```

- [ ] **Step 4: Add the live summary to the admin JS**

Replace `assets/js/simple-events-admin.js` with the version that keeps the existing show/hide and adds the summary builder:

```javascript
/**
 * Simple Events Calendar — admin edit screen.
 *
 * Conditional show/hide of the recurrence fields, plus a live plain-English
 * summary of the recurrence rule. No build step; plain DOM APIs.
 */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    ready(function () {
        var box = document.querySelector('.simple-events-meta-box');
        if (!box) {
            return;
        }

        var repeats = box.querySelector('[data-sec-toggle="recur"]');
        var group = box.querySelector('[data-sec-recur-group]');
        var endType = box.querySelector('[data-sec-toggle="end-type"]');
        var countRow = box.querySelector('[data-sec-end="count"]');
        var untilRow = box.querySelector('[data-sec-end="until"]');
        var summary = box.querySelector('[data-sec-summary]');
        var interval = box.querySelector('#sec_event_repeat_interval');
        var frequency = box.querySelector('#sec_event_repeat_frequency');
        var count = box.querySelector('#sec_event_repeat_count');
        var until = box.querySelector('#sec_event_repeat_until');
        var L = (typeof secMetaBox !== 'undefined') ? secMetaBox : null;

        function show(el, visible) {
            if (el) {
                el.style.display = visible ? '' : 'none';
            }
        }

        function fmt(str, val) {
            return String(str).replace('%d', val).replace('%s', val);
        }

        function buildSummary() {
            if (!summary || !L) {
                return;
            }
            var parts = [];
            var n = Math.max(1, parseInt(interval && interval.value, 10) || 1);
            var freqKey = frequency ? frequency.value : 'weekly';
            var unit = (L.units && L.units[freqKey]) ? L.units[freqKey] : freqKey;
            parts.push(L.every + ' ' + (n > 1 ? n + ' ' : '') + unit);

            var et = endType ? endType.value : 'count';
            if (et === 'count') {
                var c = Math.max(1, parseInt(count && count.value, 10) || 1);
                parts.push(fmt(c === 1 ? L.countOne : L.countMany, c));
            } else if (et === 'until' && until && until.value) {
                parts.push(fmt(L.until, until.value));
            } else if (et === 'never') {
                parts.push(L.never);
            }

            summary.textContent = parts.join(L.sep);
        }

        function sync() {
            var on = repeats && repeats.checked;
            show(group, on);

            if (on && endType) {
                var value = endType.value;
                show(countRow, value === 'count');
                show(untilRow, value === 'until');
            }
            if (on) {
                buildSummary();
            }
        }

        if (repeats) { repeats.addEventListener('change', sync); }
        if (endType) { endType.addEventListener('change', sync); }
        box.querySelectorAll('[data-sec-summary-input]').forEach(function (el) {
            el.addEventListener('input', buildSummary);
            el.addEventListener('change', buildSummary);
        });

        sync();
    });
})();
```

- [ ] **Step 5: Syntax-check and manual check**

Run (if available): `php -l includes/class-meta-box.php`
Manual: edit an event → meta box shows the accent bar, sectioned layout, icons, and the "Recurring" pill. Toggle recurring on → the panel reveals with a live summary that updates as you change interval/frequency/end-type/count/until. Save → reload → all values intact (no behavior change). Edit a series child → shows the "managed on the series parent" note, no recurrence fields.

- [ ] **Step 6: Commit**

```bash
git add includes/class-meta-box.php assets/css/simple-events-admin.css assets/js/simple-events-admin.js
git commit -m "feat: polish Event Details meta box (accent, sections, icons, live recurrence summary)"
```

---

## Task 11: Version bump + docs

**Files:**
- Modify: `simple-events-calendar.php` (version constant + header)
- Modify: `CLAUDE.md`

- [ ] **Step 1: Bump the version to 5.1.0**

In `simple-events-calendar.php`, update the plugin header `Version:` line and the `PLUGIN_VERSION` constant from `5.0.0` to `5.1.0`. (Find both with: `grep -n "5\.0\.0" simple-events-calendar.php`.)

- [ ] **Step 2: Update CLAUDE.md**

Make these edits in `CLAUDE.md`:

- In the **Settings** section, note that the date format supports presets + a custom value, and that the empty-state strings are now hardcoded/translatable (no longer settings).
- Replace the **"Uninstall is destructive"** section's first sentence so it documents the opt-in: uninstall now **only** deletes data when `delete_data_on_uninstall` is `'yes'` (default `'no'` retains everything); deletion never happens on deactivation.
- In the **Shortcode** section, add `[sec_event id="…" layout="card|list"]` (single event by ID; `id` required).
- In the **Element shortcodes, templates, Elementor** section, add: the **Events Grid** and **Single Event** Elementor widgets (standalone, reuse the shared render helpers); and note the per-element widgets are now **gated to event-loop contexts** (no preview picker — they render the loop/queried event, show an editor hint otherwise).
- Note the new files: `includes/elementor/display-widgets.php`, `assets/css/simple-events-admin.css`, `assets/js/simple-events-settings.js`, and the shared helpers `simple_events_render_events_grid()` / `simple_events_render_single_event()` in `includes/functions.php`.

- [ ] **Step 3: Commit**

```bash
git add simple-events-calendar.php CLAUDE.md
git commit -m "chore: bump to 5.1.0 and document new widgets, shortcode, and settings"
```

---

## Final verification (after all tasks)

- [ ] Run `npm run build` once more; confirm `assets/css/simple-events.css` is current and committed.
- [ ] `grep -rn "empty_state" includes templates template-parts` → no matches.
- [ ] With Elementor **deactivated**: the plugin loads with no errors; shortcodes (`[sec_events]`, `[sec_event]`) work; settings/meta box work.
- [ ] With Elementor **active**: all 9 element widgets + Events Grid + Single Event appear; element widgets gated; display widgets render grid/list.
- [ ] Push the branch and open a PR against `main` (or the v5.0.0 self-contained-fields branch if PR #3 is the integration target). Summarize the 8 changes and reference the resolved Copilot comments.
```
