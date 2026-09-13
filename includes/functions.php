<?php

/**
 * Utility functions for Simply Events Calendar
 *
 * @package Simple_Events_Calendar
 * @since 3.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Render the fallback event card used when the template file is missing.
 *
 * Shared between the shortcode and AJAX handlers so both produce identical
 * markup. Expects $post_data keys: title, permalink, date, start_time,
 * end_time, location, excerpt, show_time, show_excerpt, show_location,
 * show_footer. Boolean flags may be booleans or the strings 'yes'/'true'.
 *
 * @param array $post_data Event data.
 * @return void
 */
function simple_events_render_fallback_card($post_data) {
    $as_bool = static function ($value) {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower((string) $value), array('yes', 'true', '1'), true);
    };

    $show_time     = $as_bool($post_data['show_time'] ?? false);
    $show_excerpt  = $as_bool($post_data['show_excerpt'] ?? false);
    $show_location = $as_bool($post_data['show_location'] ?? false);
    $show_footer   = $as_bool($post_data['show_footer'] ?? false);
    ?>
    <article class="simple-events-calendar__post simple-events-fallback">
        <div class="simple-events-calendar__post__description">
            <h3 class="simple-events-calendar__post__title">
                <a href="<?php echo esc_url($post_data['permalink']); ?>">
                    <?php echo esc_html($post_data['title']); ?>
                </a>
            </h3>
            <div class="simple-events-calendar__post__meta">
                <span class="simple-events-calendar__post__date">
                    <?php echo esc_html($post_data['date']); ?>
                </span>
                <?php if ($show_time && !empty($post_data['start_time'])) : ?>
                    <span class="simple-events-calendar__post__time">
                        | <?php echo esc_html($post_data['start_time']); ?>
                        <?php if (!empty($post_data['end_time'])) : ?>
                            - <?php echo esc_html($post_data['end_time']); ?>
                        <?php endif; ?>
                    </span>
                <?php endif; ?>
            </div>
            <?php if ($show_location && !empty($post_data['location'])) : ?>
                <div class="simple-events-calendar__post__location">
                    <span><?php echo esc_html($post_data['location']); ?></span>
                </div>
            <?php endif; ?>
            <?php if ($show_excerpt && !empty($post_data['excerpt'])) : ?>
                <div class="simple-events-calendar__post__excerpt">
                    <p><?php echo esc_html($post_data['excerpt']); ?></p>
                </div>
            <?php endif; ?>
            <?php if ($show_footer) : ?>
                <div class="simple-events-calendar__post__footer">
                    <a href="<?php echo esc_url($post_data['permalink']); ?>" class="simple-events-calendar__read-more">
                        <?php esc_html_e('Learn More', 'simply-events-calendar'); ?>
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </article>
    <?php
}

/**
 * Render the event card for the current post in the loop.
 *
 * Assembles the card data from the current post (using the shared display
 * helpers) and includes the card template, falling back to the inline
 * renderer. Used by the default archive/taxonomy templates so their output
 * matches the [sec_events] shortcode.
 *
 * @param array $flags Optional display flags (show_time, show_excerpt,
 *                     show_location, show_footer). Booleans; default from
 *                     settings.
 * @return void
 */
function simple_events_render_event_card($flags = array()) {
    $settings = simple_events_get_settings();
    $flags = wp_parse_args($flags, array(
        'show_time'     => 'yes' === $settings['show_time'],
        'show_excerpt'  => 'yes' === $settings['show_excerpt'],
        'show_location' => 'yes' === $settings['show_location'],
        'show_footer'   => 'yes' === $settings['show_footer'],
    ));

    $id = get_the_ID();
    $post_data = array(
        'title'         => get_the_title(),
        'permalink'     => get_permalink(),
        'thumbnail'     => get_the_post_thumbnail_url($id, 'medium_large'),
        'excerpt'       => wp_trim_words(get_the_excerpt(), 30, '...'),
        'date'          => simple_events_get_event_date($id),
        'start_time'    => simple_events_get_event_time($id, 'event_start_time'),
        'end_time'      => simple_events_get_event_time($id, 'event_end_time'),
        'location'      => get_post_meta($id, 'event_location', true),
        'show_time'     => $flags['show_time'] ? 'yes' : 'no',
        'show_excerpt'  => $flags['show_excerpt'] ? 'yes' : 'no',
        'show_location' => $flags['show_location'] ? 'yes' : 'no',
        'show_footer'   => $flags['show_footer'] ? 'yes' : 'no',
    );

    if (empty($post_data['title']) || empty($post_data['date'])) {
        return;
    }

    $template_path = SIMPLE_EVENTS_DIR . '/template-parts/content-event-card.php';
    if (file_exists($template_path)) {
        include $template_path;
    } else {
        simple_events_render_fallback_card($post_data);
    }
}

/**
 * Option name that stores all plugin settings as a single array.
 */
if (!defined('SIMPLE_EVENTS_SETTINGS_OPTION')) {
    define('SIMPLE_EVENTS_SETTINGS_OPTION', 'simple_events_settings');
}

/**
 * Default values for every plugin setting.
 *
 * Defaults are chosen so that a site upgrading from an earlier version renders
 * and behaves identically until the admin changes something.
 *
 * @return array
 */
function simple_events_get_setting_defaults() {
    $defaults = array(
        // Display formatting.
        'date_format'    => 'l, F j, Y',
        'time_format'    => '12', // '12' => g:i a, '24' => H:i.

        // Shortcode / query display defaults.
        'posts_per_page' => 6,
        'show_past'      => 'no',
        'order'          => 'ASC',
        'show_time'      => 'yes',
        'show_excerpt'   => 'yes',
        'show_location'  => 'yes',
        'show_footer'    => 'yes',

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
    );

    // Add-ons register defaults for their own setting keys here.
    return apply_filters('simple_events_setting_defaults', $defaults);
}

/**
 * Get the full settings array, merged over defaults.
 *
 * @return array
 */
function simple_events_get_settings() {
    $stored = get_option(SIMPLE_EVENTS_SETTINGS_OPTION, array());
    if (!is_array($stored)) {
        $stored = array();
    }
    return wp_parse_args($stored, simple_events_get_setting_defaults());
}

/**
 * Get a single plugin setting.
 *
 * @param string $key      Setting key.
 * @param mixed  $fallback Value to return when the key is unknown.
 * @return mixed
 */
function simple_events_get_setting($key, $fallback = null) {
    $settings = simple_events_get_settings();
    if (array_key_exists($key, $settings)) {
        return $settings[$key];
    }
    return $fallback;
}

/**
 * Resolve the event/post ID a single-element renderer or shortcode should use.
 *
 * Falls back to the current loop / queried post when no explicit ID is given.
 *
 * @param int|string $maybe_id Explicit ID, or empty/0 for the current post.
 * @return int Post ID (0 when none can be resolved).
 */
function simple_events_resolve_event_id($maybe_id = 0) {
    $id = absint($maybe_id);
    if ($id) {
        return $id;
    }

    $current = get_the_ID();
    if ($current) {
        return (int) $current;
    }

    $queried = get_queried_object_id();
    return $queried ? (int) $queried : 0;
}

/**
 * Get an event's date, reformatted from the stored `Ymd` meta.
 *
 * The raw meta value is stored in `Ymd` form (e.g. 20260529). This isolates the
 * one place the stored format differs from the display format.
 *
 * @param int    $post_id Event post ID.
 * @param string $format  PHP date format. Defaults to the `date_format` setting.
 * @return string Formatted date, or '' when unset/invalid.
 */
function simple_events_get_event_date($post_id, $format = '') {
    $raw = get_post_meta((int) $post_id, 'event_date', true);
    if (empty($raw)) {
        return '';
    }

    if ('' === $format) {
        $format = (string) simple_events_get_setting('date_format', 'l, F j, Y');
    }

    $tz = wp_timezone();
    $dt = DateTimeImmutable::createFromFormat('!Ymd', (string) $raw, $tz);

    if (!$dt) {
        // Defensive fallback for any non-Ymd legacy value.
        $timestamp = strtotime((string) $raw);
        if (!$timestamp) {
            return '';
        }
        return wp_date($format, $timestamp, $tz);
    }

    return wp_date($format, $dt->getTimestamp(), $tz);
}

/**
 * Get an event time meta value, rendered per the `time_format` setting.
 *
 * Stored times are in `g:i a` form (e.g. "2:30 pm"). When the setting is 24h
 * they are reformatted to `H:i`; otherwise returned in 12h form.
 *
 * @param int    $post_id Event post ID.
 * @param string $key     Meta key (event_start_time | event_end_time).
 * @return string Formatted time, or '' when unset.
 */
function simple_events_get_event_time($post_id, $key) {
    $raw = get_post_meta((int) $post_id, $key, true);
    if (empty($raw)) {
        return '';
    }

    $dt = simple_events_parse_time_of_day((string) $raw);
    if (!$dt) {
        // Unparseable — return the stored value unchanged.
        return (string) $raw;
    }

    $format = ('24' === (string) simple_events_get_setting('time_format', '12')) ? 'H:i' : 'g:i a';
    return $dt->format($format);
}

/**
 * Parse a stored event time-of-day string into a DateTimeImmutable.
 *
 * Tolerant of multiple stored formats: the meta box writes `g:i a`
 * (e.g. "2:30 pm"), but events created by versions <= 4.4.0 may be stored as
 * `H:i:s` (e.g. "14:30:00") or `H:i`. Only the time component is
 * meaningful (the date is reset to the epoch). Returns false when none match.
 *
 * @param string             $raw Stored time string.
 * @param DateTimeZone|null  $tz  Timezone (defaults to wp_timezone()).
 * @return DateTimeImmutable|false
 */
function simple_events_parse_time_of_day($raw, $tz = null) {
    $raw = trim((string) $raw);
    if ('' === $raw) {
        return false;
    }

    if (!$tz instanceof DateTimeZone) {
        $tz = wp_timezone();
    }

    // Order matters: the current `g:i a` format first, then legacy 24-hour.
    foreach (array('g:i a', 'g:i A', 'H:i:s', 'H:i') as $format) {
        $dt = DateTimeImmutable::createFromFormat('!' . $format, $raw, $tz);
        if ($dt instanceof DateTimeImmutable) {
            return $dt;
        }
    }

    return false;
}

/**
 * Get an ISO-8601 datetime for an event built from the stored meta.
 *
 * Combines `event_date` (Ymd) with the given time meta (g:i a). Computed from
 * the raw stored values — never from a display-formatted string — so it is
 * locale/format independent. Falls back to the date at midnight when the time
 * is absent.
 *
 * @param int    $post_id  Event post ID.
 * @param string $time_key Time meta key (event_start_time | event_end_time).
 * @return string ISO-8601 datetime, or '' when the date is unset/invalid.
 */
function simple_events_get_event_datetime_iso($post_id, $time_key = 'event_start_time') {
    $post_id  = (int) $post_id;
    $date_raw = get_post_meta($post_id, 'event_date', true);
    if (empty($date_raw)) {
        return '';
    }

    $tz       = wp_timezone();
    $time_raw = (string) get_post_meta($post_id, $time_key, true);

    // Parse the date, then layer on the time-of-day (tolerant of g:i a / H:i:s
    // / H:i) so imported legacy times still produce a valid ISO datetime.
    $date = DateTimeImmutable::createFromFormat('!Ymd', (string) $date_raw, $tz);
    $dt   = false;
    if ($date instanceof DateTimeImmutable) {
        if ('' !== $time_raw) {
            $time = simple_events_parse_time_of_day($time_raw, $tz);
            if ($time instanceof DateTimeImmutable) {
                $dt = $date->setTime(
                    (int) $time->format('H'),
                    (int) $time->format('i'),
                    (int) $time->format('s')
                );
            }
        } else {
            $dt = $date;
        }
    }

    if ($dt instanceof DateTimeImmutable) {
        return $dt->format('c');
    }

    // Defensive fallback for any non-Ymd legacy value, matching
    // simple_events_get_event_date() so a visible date never pairs with an
    // empty ISO datetime.
    $timestamp = strtotime(trim($date_raw . ' ' . $time_raw));
    return $timestamp ? wp_date('c', $timestamp, $tz) : '';
}

/**
 * Build the schema.org Event structured-data array for a single event.
 *
 * Shared by the event card and the single-event page output so the markup is
 * identical. Returns null when the JSON-LD setting is disabled or the event has
 * no date.
 *
 * @param int $post_id Event post ID.
 * @return array|null
 */
function simple_events_get_event_schema($post_id) {
    if ('yes' !== (string) simple_events_get_setting('enable_schema', 'yes')) {
        return null;
    }

    $post_id = (int) $post_id;
    $date_raw = get_post_meta($post_id, 'event_date', true);
    if (empty($date_raw)) {
        return null;
    }

    $schema = array(
        '@context' => 'https://schema.org',
        '@type'    => 'Event',
        'name'     => get_the_title($post_id),
        'url'      => get_permalink($post_id),
    );

    $start_iso = simple_events_get_event_datetime_iso($post_id, 'event_start_time');
    if ('' !== $start_iso) {
        $schema['startDate'] = $start_iso;
    }

    if (get_post_meta($post_id, 'event_end_time', true)) {
        $end_iso = simple_events_get_event_datetime_iso($post_id, 'event_end_time');
        if ('' !== $end_iso) {
            $schema['endDate'] = $end_iso;
        }
    }

    $location = get_post_meta($post_id, 'event_location', true);
    if ($location) {
        $schema['location'] = array(
            '@type' => 'Place',
            'name'  => (string) $location,
        );
    }

    $excerpt = get_the_excerpt($post_id);
    if ($excerpt) {
        $schema['description'] = wp_strip_all_tags($excerpt);
    }

    $thumb = get_the_post_thumbnail_url($post_id, 'large');
    if ($thumb) {
        $schema['image'] = $thumb;
    }

    /**
     * Filter the schema.org Event data before output.
     *
     * @param array $schema  Schema array.
     * @param int   $post_id Event post ID.
     */
    return apply_filters('simple_events_event_schema', $schema, $post_id);
}

/**
 * Format event date for display
 *
 * @param string $date_string Date string
 * @param string $format Date format (optional)
 * @return string Formatted date
 */
function simple_events_format_date($date_string, $format = null) {
    if (empty($date_string)) {
        return '';
    }

    if (!$format) {
        $format = get_option('date_format');
    }

    $timestamp = strtotime($date_string);
    if (!$timestamp) {
        return $date_string; // Return original if parsing fails
    }

    return date_i18n($format, $timestamp);
}

/**
 * Format event time for display
 *
 * @param string $start_time Start time
 * @param string $end_time End time (optional)
 * @return string Formatted time range
 */
function simple_events_format_time($start_time, $end_time = '') {
    if (empty($start_time)) {
        return '';
    }

    $formatted = esc_html($start_time);

    if (!empty($end_time)) {
        $formatted .= ' - ' . esc_html($end_time);
    }

    return $formatted;
}

/**
 * Get event status based on date
 *
 * @param string $event_date Event date
 * @return string Status: 'past', 'today', 'upcoming'
 */
function simple_events_get_event_status($event_date) {
    if (empty($event_date)) {
        return 'unknown';
    }

    $event_timestamp = strtotime($event_date);
    $today_timestamp = strtotime(current_time('Y-m-d'));

    if ($event_timestamp < $today_timestamp) {
        return 'past';
    } elseif ($event_timestamp === $today_timestamp) {
        return 'today';
    } else {
        return 'upcoming';
    }
}

/**
 * Get events by status
 *
 * @param string $status Event status: 'past', 'today', 'upcoming'
 * @param array $args Additional query arguments
 * @return WP_Query Events query
 */
function simple_events_get_events_by_status($status, $args = array()) {
    $today = current_time('Ymd');

    $meta_query = array();

    switch ($status) {
        case 'past':
            $meta_query[] = array(
                'key' => 'event_date',
                'value' => $today,
                'compare' => '<',
                'type' => 'DATE'
            );
            break;

        case 'today':
            $meta_query[] = array(
                'key' => 'event_date',
                'value' => $today,
                'compare' => '=',
                'type' => 'DATE'
            );
            break;

        case 'upcoming':
            $meta_query[] = array(
                'key' => 'event_date',
                'value' => $today,
                'compare' => '>',
                'type' => 'DATE'
            );
            break;
    }

    $defaults = array(
        'post_type' => 'simple-events',
        'post_status' => 'publish',
        'meta_key' => 'event_date',
        'orderby' => 'meta_value',
        'meta_type' => 'DATE',
        'order' => ($status === 'past') ? 'DESC' : 'ASC',
        'meta_query' => $meta_query
    );

    $query_args = wp_parse_args($args, $defaults);

    return new WP_Query($query_args);
}

/**
 * Get events in date range
 *
 * @param string $start_date Start date (Y-m-d format)
 * @param string $end_date End date (Y-m-d format)
 * @param array $args Additional query arguments
 * @return WP_Query Events query
 */
function simple_events_get_events_in_range($start_date, $end_date, $args = array()) {
    $defaults = array(
        'post_type' => 'simple-events',
        'post_status' => 'publish',
        'meta_key' => 'event_date',
        'orderby' => 'meta_value',
        'meta_type' => 'DATE',
        'order' => 'ASC',
        'meta_query' => array(
            array(
                'key' => 'event_date',
                'value' => array($start_date, $end_date),
                'compare' => 'BETWEEN',
                'type' => 'DATE'
            )
        )
    );

    $query_args = wp_parse_args($args, $defaults);

    return new WP_Query($query_args);
}

/**
 * Clear all Simple Events transients
 *
 * @return bool True on success
 */
function simple_events_clear_all_transients() {
    global $wpdb;

    $result = $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            '_transient_simple_events_%',
            '_transient_timeout_simple_events_%'
        )
    );

    return $result !== false;
}

/**
 * Get plugin version
 *
 * @return string Plugin version
 */
function simple_events_get_version() {
    return defined('SIMPLE_EVENTS_VERSION') ? SIMPLE_EVENTS_VERSION : '3.0.0';
}

/**
 * Get plugin directory path
 *
 * @return string Plugin directory path
 */
function simple_events_get_plugin_dir() {
    return defined('SIMPLE_EVENTS_DIR') ? SIMPLE_EVENTS_DIR : plugin_dir_path(__FILE__);
}

/**
 * Get plugin directory URL
 *
 * @return string Plugin directory URL
 */
function simple_events_get_plugin_url() {
    return defined('SIMPLE_EVENTS_URL') ? SIMPLE_EVENTS_URL : plugin_dir_url(__FILE__);
}

/**
 * Get assets URL
 *
 * @return string Assets URL
 */
function simple_events_get_assets_url() {
    return defined('SIMPLE_EVENTS_ASSETS') ? SIMPLE_EVENTS_ASSETS : simple_events_get_plugin_url() . '/assets';
}

/**
 * Log debug message if WP_DEBUG is enabled
 *
 * @param string $message Debug message
 * @param array $context Additional context
 */
function simple_events_debug_log($message, $context = array()) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        $log_message = 'Simply Events Calendar: ' . $message;

        if (!empty($context)) {
            $log_message .= ' Context: ' . print_r($context, true);
        }

        error_log($log_message);
    }
}

/**
 * Sanitize and validate shortcode attributes
 *
 * @param array $atts Raw attributes
 * @return array Sanitized attributes
 */
function simple_events_sanitize_shortcode_atts($atts) {
    $defaults = array(
        'posts_per_page' => 6,
        'category' => '',
        'show_past' => 'no',
        'order' => 'ASC',
        'orderby' => 'event_date',
        'show_time' => 'yes',
        'show_excerpt' => 'yes',
        'show_location' => 'yes',
        'show_footer' => 'yes'
    );

    $atts = wp_parse_args($atts, $defaults);

    // Sanitize posts_per_page
    $posts_per_page = absint($atts['posts_per_page']);
    $atts['posts_per_page'] = ($posts_per_page > 0 && $posts_per_page <= 50) ? $posts_per_page : 6;

    // Sanitize text fields
    $atts['category'] = sanitize_text_field($atts['category']);
    $atts['orderby'] = sanitize_text_field($atts['orderby']);

    // Sanitize boolean fields
    $atts['show_past'] = ($atts['show_past'] === 'yes');
    $atts['show_time'] = ($atts['show_time'] !== 'no');
    $atts['show_excerpt'] = ($atts['show_excerpt'] !== 'no');
    $atts['show_location'] = ($atts['show_location'] !== 'no');
    $atts['show_footer'] = ($atts['show_footer'] !== 'no');

    // Validate order
    $atts['order'] = in_array(strtoupper($atts['order']), ['ASC', 'DESC']) ? strtoupper($atts['order']) : 'ASC';

    return $atts;
}

/**
 * Check if current page is an event-related page
 *
 * @return bool True if on event page
 */
function simple_events_is_event_page() {
    return is_singular('simple-events') ||
           is_post_type_archive('simple-events') ||
           is_tax('simple-events-cat');
}

/**
 * Whether the given post is the parent of a recurring series.
 *
 * Detected via the persisted rule snapshot meta, so the function works in
 * cron / background contexts where the admin-only meta box is not loaded.
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function simple_events_is_series_parent($post_id) {
    if (!class_exists('Simple_Events_Recurrence')) {
        return false;
    }
    return (bool) get_post_meta((int) $post_id, Simple_Events_Recurrence::META_RULE_FREQ, true);
}

/**
 * Whether the given post is a generated child occurrence of a series.
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function simple_events_is_series_child($post_id) {
    if (!class_exists('Simple_Events_Recurrence')) {
        return false;
    }
    return (bool) get_post_meta((int) $post_id, Simple_Events_Recurrence::META_PARENT, true);
}

/**
 * Returns the parent post ID for a child occurrence, or 0 if the post is
 * not part of a series.
 *
 * @param int $post_id Post ID.
 * @return int
 */
function simple_events_get_series_parent_id($post_id) {
    if (!class_exists('Simple_Events_Recurrence')) {
        return 0;
    }
    return (int) get_post_meta((int) $post_id, Simple_Events_Recurrence::META_PARENT, true);
}

/**
 * Get template part with fallback
 *
 * @param string $template_name Template name
 * @param array $args Template arguments
 * @return void
 */
function simple_events_get_template_part($template_name, $args = array()) {
    $template_path = simple_events_get_plugin_dir() . '/template-parts/' . $template_name . '.php';

    if (file_exists($template_path)) {
        // Extract args for use in template
        if (!empty($args)) {
            extract($args);
        }

        include $template_path;
    } else {
        simple_events_debug_log("Template not found: {$template_name}");
    }
}

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
 *
 * Defaults are widget-oriented and do not derive from the plugin settings
 * (unlike the [sec_events] shortcode). The `simple_events_grid_extra_class`
 * filter lets callers add a CSS class to the grid container.
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
        'load_more'      => false,
    );
    $args = wp_parse_args($args, $defaults);

    // Protect the caller's global $post: the loop below + wp_reset_postdata()
    // restore from the main query, which may be null/stale outside a main loop.
    global $post;
    $original_post = $post;

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
        $post = $original_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

        // Context-aware empty message, mirroring the [sec_events] shortcode.
        if (!empty($args['category'])) {
            /* translators: %s: category slug */
            $message = sprintf(esc_html__('No events found in the "%s" category.', 'simply-events-calendar'), esc_html((string) $args['category']));
        } elseif (empty($args['show_past'])) {
            $message = esc_html__('No upcoming events scheduled. Check back soon!', 'simply-events-calendar');
        } else {
            $message = esc_html__('No events have been created yet.', 'simply-events-calendar');
        }

        return '<div class="simple-events-calendar simple-events-no-events"><div class="simple-events-empty-state"><h3>'
            . esc_html__('No Events Found', 'simply-events-calendar') . '</h3><p>'
            . $message
            . '</p></div></div>';
    }

    $layout_class = ('list' === $args['layout']) ? ' sec-layout-list' : '';
    $flags = array(
        'show_time'     => !empty($args['show_time']),
        'show_excerpt'  => !empty($args['show_excerpt']),
        'show_location' => !empty($args['show_location']),
        'show_footer'   => !empty($args['show_footer']),
    );

    $extra_class = apply_filters('simple_events_grid_extra_class', '');

    $container_attrs = 'class="simple-events-calendar' . esc_attr($layout_class)
        . (('' !== $extra_class) ? ' ' . esc_attr($extra_class) : '') . '"';

    // Opt into the infinite-scroll JS only when load_more is enabled: emit the
    // marker + the query context the AJAX handler needs to continue this grid.
    if (!empty($args['load_more'])) {
        $container_attrs .= sprintf(
            ' data-sec-loadmore="1" data-show-time="%s" data-show-excerpt="%s" data-show-location="%s" data-show-footer="%s" data-show-past="%s" data-order="%s" data-category="%s" data-offset="%d"',
            $flags['show_time'] ? 'true' : 'false',
            $flags['show_excerpt'] ? 'true' : 'false',
            $flags['show_location'] ? 'true' : 'false',
            $flags['show_footer'] ? 'true' : 'false',
            !empty($args['show_past']) ? 'true' : 'false',
            esc_attr($order),
            esc_attr((string) $args['category']),
            (int) $query->post_count
        );
    }

    ob_start();
    echo '<div ' . $container_attrs . '>';
    while ($query->have_posts()) {
        $query->the_post();
        simple_events_render_event_card($flags);
    }
    echo '</div>';
    $html = (string) ob_get_clean();

    wp_reset_postdata();
    $post = $original_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

    return $html;
}

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
    $event   = $post_id ? get_post($post_id) : null;

    if (!$event || 'simple-events' !== $event->post_type || 'publish' !== $event->post_status) {
        return '';
    }

    // Don't expose a password-protected event's details when it is embedded by
    // ID via [sec_event] or the Single Event widget without the password.
    if (post_password_required($event)) {
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
    $post = $event; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
    setup_postdata($post);

    ob_start();
    echo '<div class="simple-events-calendar simple-events-embed' . esc_attr($layout_class) . '">';
    simple_events_render_event_card($flags);
    echo '</div>';
    $html = (string) ob_get_clean();

    // wp_reset_postdata() resets $wp_query state; we also restore $post directly
    // because the manual assignment above is not undone by reset alone outside a loop.
    wp_reset_postdata();
    $post = $original; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

    return $html;
}
