<?php

/**
 * Native event-details meta box for Simply Events Calendar
 *
 * Registers and persists every event field. The meta keys and storage formats
 * are unchanged from earlier versions, so existing events are unaffected:
 *   - event_date          stored as Ymd      (e.g. 20260529)
 *   - event_start_time    stored as g:i a    (e.g. "2:30 pm")
 *   - event_end_time      stored as g:i a    (optional)
 *   - event_location      plain text         (optional)
 *   - event_repeats       int 1/0
 *   - event_repeat_interval   int
 *   - event_repeat_frequency  daily|weekly|monthly|yearly
 *   - event_repeat_end_type   never|count|until
 *   - event_repeat_count      int
 *   - event_repeat_until      Ymd
 *
 * Saves at save_post priority 10 so the recurrence engine (priority 30) reads
 * the persisted rule meta.
 *
 * @package Simple_Events_Calendar
 * @since 5.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Simple_Events_Meta_Box class
 */
class Simple_Events_Meta_Box {

    /**
     * Nonce action.
     */
    const NONCE_ACTION = 'simple_events_save_details';

    /**
     * Nonce field name.
     */
    const NONCE_NAME = 'simple_events_details_nonce';

    /**
     * Constructor.
     */
    public function __construct() {
        add_action('add_meta_boxes_simple-events', array($this, 'register'));
        add_action('save_post_simple-events', array($this, 'save'), 10, 2);
        add_action('admin_enqueue_scripts', array($this, 'enqueue'));
    }

    /**
     * Register the Event Details meta box.
     */
    public function register() {
        add_meta_box(
            'simple_events_details',
            __('Event Details', 'simply-events-calendar'),
            array($this, 'render'),
            'simple-events',
            'normal',
            'high'
        );
    }

    /**
     * Enqueue the conditional-logic admin script on the event edit screen.
     *
     * @param string $hook Current admin page.
     */
    public function enqueue($hook) {
        if ('post.php' !== $hook && 'post-new.php' !== $hook) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || 'simple-events' !== $screen->post_type) {
            return;
        }

        wp_enqueue_script(
            'simple-events-admin',
            SIMPLE_EVENTS_ASSETS . '/js/simple-events-admin.js',
            array(),
            SIMPLE_EVENTS_VERSION,
            true
        );

        wp_enqueue_style(
            'simple-events-admin',
            SIMPLE_EVENTS_ASSETS . '/css/simple-events-admin.css',
            array(),
            SIMPLE_EVENTS_VERSION
        );

        $wp_locale = isset($GLOBALS['wp_locale']) ? $GLOBALS['wp_locale'] : null;
        $day_names = array();
        for ($d = 0; $d < 7; $d++) {
            $day_names[$d] = $wp_locale ? $wp_locale->get_weekday_abbrev($wp_locale->get_weekday($d)) : (string) $d;
        }

        wp_localize_script('simple-events-admin', 'secMetaBox', array(
            'every'    => __('Repeats every', 'simply-events-calendar'),
            'units'    => array(
                'daily'   => __('day(s)', 'simply-events-calendar'),
                'weekly'  => __('week(s)', 'simply-events-calendar'),
                'monthly' => __('month(s)', 'simply-events-calendar'),
                'yearly'  => __('year(s)', 'simply-events-calendar'),
            ),
            // Plural pair for the JS-rendered recurrence summary. The count is
            // only known client-side, so _n() cannot apply; the JS picks one.
            // Two-form approximation - languages with more forms read wrong here.
            /* translators: %d is the number of occurrences (singular form, count = 1) */
            'countOne' => __('%d occurrence', 'simply-events-calendar'),
            /* translators: %d is the number of occurrences (plural form, count != 1) */
            'countMany'=> __('%d occurrences', 'simply-events-calendar'),
            'never'    => __('repeats indefinitely', 'simply-events-calendar'),
            /* translators: %s is the recurrence end date value from the date picker (e.g. 2026-12-31) */
            'until'    => __('until %s', 'simply-events-calendar'),
            'sep'      => ' · ',
            /* translators: %s is a comma-separated list of weekday abbreviations, e.g. "Mon, Wed, Fri" */
            'onDays'   => __('on %s', 'simply-events-calendar'),
            'dayNames' => $day_names,
        ));
    }

    /**
     * Render the meta box.
     *
     * @param WP_Post $post Current post.
     */
    public function render($post) {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);

        $date        = (string) get_post_meta($post->ID, 'event_date', true);          // Ymd
        $start       = (string) get_post_meta($post->ID, 'event_start_time', true);    // g:i a
        $end         = (string) get_post_meta($post->ID, 'event_end_time', true);      // g:i a
        $location    = (string) get_post_meta($post->ID, 'event_location', true);

        // Recurrence rule. New events get sensible defaults (weekly / after
        // 10 occurrences) so the UI is pre-filled rather than uninitialized;
        // existing events keep their stored values.
        $repeats     = (int) get_post_meta($post->ID, 'event_repeats', true) === 1;
        $interval    = max(1, (int) get_post_meta($post->ID, 'event_repeat_interval', true));
        $frequency   = (string) get_post_meta($post->ID, 'event_repeat_frequency', true);
        $frequency   = in_array($frequency, array('daily', 'weekly', 'monthly', 'yearly'), true) ? $frequency : 'weekly';
        $end_type    = (string) get_post_meta($post->ID, 'event_repeat_end_type', true);
        $end_type    = in_array($end_type, array('never', 'count', 'until'), true) ? $end_type : 'count';
        $count_raw   = get_post_meta($post->ID, 'event_repeat_count', true);
        $count       = ('' !== $count_raw) ? max(1, (int) $count_raw) : 10;
        $until       = (string) get_post_meta($post->ID, 'event_repeat_until', true);  // Ymd

        // Selected weekdays for weekly "on these days". Defaults to the event's
        // own weekday when nothing is stored yet, so the Weekly picker is never
        // empty for a fresh event.
        $byday_raw = (string) get_post_meta($post->ID, 'event_repeat_byday', true);
        $byday = array();
        if ('' !== $byday_raw) {
            foreach (explode(',', $byday_raw) as $piece) {
                $day = (int) trim($piece);
                if ($day >= 0 && $day <= 6) {
                    $byday[$day] = $day;
                }
            }
        }
        if (empty($byday) && 8 === strlen($date)) {
            $start_dt = DateTimeImmutable::createFromFormat('!Ymd', $date, wp_timezone());
            if ($start_dt) {
                $w = (int) $start_dt->format('w');
                $byday[$w] = $w;
            }
        }
        $byday = array_values($byday);

        // Convert stored formats to HTML5 input formats.
        $date_input  = self::ymd_to_input($date);
        $start_input = self::time_to_input($start);
        $end_input   = self::time_to_input($end);
        $until_input = self::ymd_to_input($until);

        $is_child = class_exists('Simple_Events_Recurrence')
            && (int) get_post_meta($post->ID, Simple_Events_Recurrence::META_PARENT, true) > 0;
        ?>
        <div class="simple-events-meta-box sec-mb">
            <div class="sec-mb__section">
                <p class="sec-mb__section-label"><?php esc_html_e('When & where', 'simply-events-calendar'); ?></p>

                <div class="sec-mb__field">
                    <label class="sec-mb__label" for="sec_event_date">
                        <svg class="sec-mb__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                        <?php esc_html_e('Event Date', 'simply-events-calendar'); ?> <span class="sec-mb__req"><?php esc_html_e('required', 'simply-events-calendar'); ?></span>
                    </label>
                    <input type="date" id="sec_event_date" name="sec_event_date" value="<?php echo esc_attr($date_input); ?>" required />
                </div>

                <div class="sec-mb__grid2">
                    <div class="sec-mb__field">
                        <label class="sec-mb__label" for="sec_event_start_time">
                            <svg class="sec-mb__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                            <?php esc_html_e('Start Time', 'simply-events-calendar'); ?>
                        </label>
                        <input type="time" id="sec_event_start_time" name="sec_event_start_time" value="<?php echo esc_attr($start_input); ?>" />
                    </div>
                    <div class="sec-mb__field">
                        <label class="sec-mb__label" for="sec_event_end_time"><?php esc_html_e('End Time', 'simply-events-calendar'); ?> <span class="sec-mb__opt"><?php esc_html_e('optional', 'simply-events-calendar'); ?></span></label>
                        <input type="time" id="sec_event_end_time" name="sec_event_end_time" value="<?php echo esc_attr($end_input); ?>" />
                    </div>
                </div>

                <div class="sec-mb__field">
                    <label class="sec-mb__label" for="sec_event_location">
                        <svg class="sec-mb__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 21s7-7.5 7-12a7 7 0 1 0-14 0c0 4.5 7 12 7 12z"/><circle cx="12" cy="9" r="2.5"/></svg>
                        <?php echo esc_html_x('Location', 'event meta box field label', 'simply-events-calendar'); ?> <span class="sec-mb__opt"><?php esc_html_e('optional', 'simply-events-calendar'); ?></span>
                    </label>
                    <input type="text" id="sec_event_location" name="sec_event_location" class="widefat" maxlength="255" value="<?php echo esc_attr($location); ?>" placeholder="<?php esc_attr_e('e.g., Conference Room A, 123 Main St, or Online', 'simply-events-calendar'); ?>" />
                </div>
            </div>

            <?php if ($is_child) : ?>
                <p class="sec-mb__child-note description"><?php esc_html_e('This event is part of a recurring series. Recurrence settings are managed on the series parent.', 'simply-events-calendar'); ?></p>
            <?php else : ?>
                <div class="sec-mb__section">
                    <label class="sec-mb__check">
                        <input type="checkbox" id="sec_event_repeats" name="sec_event_repeats" value="1" <?php checked($repeats); ?> data-sec-toggle="recur" />
                        <span class="sec-mb__pill"><?php esc_html_e('Recurring', 'simply-events-calendar'); ?></span>
                        <?php esc_html_e('This is a recurring event', 'simply-events-calendar'); ?>
                    </label>

                    <div class="sec-mb__recur" data-sec-recur-group>
                        <p class="sec-mb__summary" data-sec-summary></p>

                        <div class="sec-mb__field">
                            <label class="sec-mb__label" for="sec_event_repeat_frequency"><?php esc_html_e('Repeats', 'simply-events-calendar'); ?></label>
                            <div class="sec-mb__row">
                                <?php esc_html_e('Every', 'simply-events-calendar'); ?>
                                <input type="number" id="sec_event_repeat_interval" name="sec_event_repeat_interval" min="1" step="1" value="<?php echo esc_attr($interval); ?>" style="width:5em;" data-sec-summary-input />
                                <select id="sec_event_repeat_frequency" name="sec_event_repeat_frequency" data-sec-summary-input>
                                    <?php
                                    $freqs = array(
                                        'daily'   => __('day(s)', 'simply-events-calendar'),
                                        'weekly'  => __('week(s)', 'simply-events-calendar'),
                                        'monthly' => __('month(s)', 'simply-events-calendar'),
                                        'yearly'  => __('year(s)', 'simply-events-calendar'),
                                    );
                                    foreach ($freqs as $value => $label) {
                                        printf('<option value="%s" %s>%s</option>', esc_attr($value), selected($frequency, $value, false), esc_html($label));
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>

                        <div class="sec-mb__field" data-sec-byday>
                            <span class="sec-mb__label"><?php esc_html_e('On these days', 'simply-events-calendar'); ?></span>
                            <div class="sec-mb__days">
                                <?php
                                $wp_locale = isset($GLOBALS['wp_locale']) ? $GLOBALS['wp_locale'] : null;
                                $sow = (int) get_option('start_of_week', 0);
                                for ($i = 0; $i < 7; $i++) {
                                    $day  = ($sow + $i) % 7;
                                    $full = $wp_locale ? $wp_locale->get_weekday($day) : (string) $day;
                                    $init = $wp_locale ? $wp_locale->get_weekday_initial($full) : substr($full, 0, 1);
                                    printf(
                                        '<label class="sec-mb__day"><input type="checkbox" name="sec_event_repeat_byday[]" value="%d" %s data-sec-summary-input aria-label="%s" /><span title="%s" aria-hidden="true">%s</span></label>',
                                        (int) $day,
                                        checked(in_array($day, $byday, true), true, false),
                                        esc_attr($full),
                                        esc_attr($full),
                                        esc_html($init)
                                    );
                                }
                                ?>
                            </div>
                            <div class="sec-mb__presets">
                                <button type="button" class="button button-small sec-mb__preset" data-sec-preset="weekdays"><?php esc_html_e('Weekdays', 'simply-events-calendar'); ?></button>
                                <button type="button" class="button button-small sec-mb__preset" data-sec-preset="weekend"><?php esc_html_e('Weekend', 'simply-events-calendar'); ?></button>
                                <button type="button" class="button button-small sec-mb__preset" data-sec-preset="all"><?php esc_html_e('Every day', 'simply-events-calendar'); ?></button>
                            </div>
                        </div>

                        <div class="sec-mb__field">
                            <label class="sec-mb__label" for="sec_event_repeat_end_type"><?php esc_html_e('Ends', 'simply-events-calendar'); ?></label>
                            <select id="sec_event_repeat_end_type" name="sec_event_repeat_end_type" data-sec-toggle="end-type" data-sec-summary-input>
                                <?php
                                $end_types = array(
                                    'never' => __('Never', 'simply-events-calendar'),
                                    'count' => __('After a number of occurrences', 'simply-events-calendar'),
                                    'until' => __('On a date', 'simply-events-calendar'),
                                );
                                foreach ($end_types as $value => $label) {
                                    printf('<option value="%s" %s>%s</option>', esc_attr($value), selected($end_type, $value, false), esc_html($label));
                                }
                                ?>
                            </select>
                        </div>

                        <div class="sec-mb__field" data-sec-end="count">
                            <label class="sec-mb__label" for="sec_event_repeat_count"><?php esc_html_e('Number of occurrences', 'simply-events-calendar'); ?></label>
                            <input type="number" id="sec_event_repeat_count" name="sec_event_repeat_count" min="1" step="1" value="<?php echo esc_attr($count); ?>" data-sec-summary-input />
                        </div>

                        <div class="sec-mb__field" data-sec-end="until">
                            <label class="sec-mb__label" for="sec_event_repeat_until"><?php esc_html_e('Repeat until', 'simply-events-calendar'); ?></label>
                            <input type="date" id="sec_event_repeat_until" name="sec_event_repeat_until" value="<?php echo esc_attr($until_input); ?>" data-sec-summary-input />
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Persist the meta box fields.
     *
     * @param int     $post_id Post ID.
     * @param WP_Post $post    Post object.
     */
    public function save($post_id, $post) {
        // Bail while the recurrence engine is generating occurrences: child
        // inserts fire save_post inside the parent's request, and we must not
        // write the parent's submitted values onto generated children.
        if (!empty($GLOBALS['sec_generating_series'])) {
            return;
        }

        // Bail on autosave / revisions / missing nonce / insufficient caps.
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (wp_is_post_revision($post_id)) {
            return;
        }
        if (!isset($_POST[self::NONCE_NAME]) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_NAME])), self::NONCE_ACTION)) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // --- Core fields ---------------------------------------------------
        $date_input = isset($_POST['sec_event_date']) ? sanitize_text_field(wp_unslash($_POST['sec_event_date'])) : '';
        $ymd = self::input_to_ymd($date_input);
        if ('' !== $ymd) {
            update_post_meta($post_id, 'event_date', $ymd);
        } else {
            delete_post_meta($post_id, 'event_date');
        }

        $start_input = isset($_POST['sec_event_start_time']) ? sanitize_text_field(wp_unslash($_POST['sec_event_start_time'])) : '';
        $start = self::input_to_time($start_input);
        if ('' !== $start) {
            update_post_meta($post_id, 'event_start_time', $start);
        } else {
            delete_post_meta($post_id, 'event_start_time');
        }

        $end_input = isset($_POST['sec_event_end_time']) ? sanitize_text_field(wp_unslash($_POST['sec_event_end_time'])) : '';
        $end = self::input_to_time($end_input);
        if ('' !== $end) {
            update_post_meta($post_id, 'event_end_time', $end);
        } else {
            delete_post_meta($post_id, 'event_end_time');
        }

        $location = isset($_POST['sec_event_location']) ? sanitize_text_field(wp_unslash($_POST['sec_event_location'])) : '';
        // mbstring is not guaranteed on every host; fall back to substr().
        $location = function_exists('mb_substr') ? mb_substr($location, 0, 255) : substr($location, 0, 255);
        if ('' !== $location) {
            update_post_meta($post_id, 'event_location', $location);
        } else {
            delete_post_meta($post_id, 'event_location');
        }

        // --- Recurrence rule ----------------------------------------------
        // Children never carry the recurrence inputs (the field set is hidden
        // for them), so leave any inherited series meta untouched in that case.
        $is_child = class_exists('Simple_Events_Recurrence')
            && (int) get_post_meta($post_id, Simple_Events_Recurrence::META_PARENT, true) > 0;
        if ($is_child) {
            return;
        }

        $repeats = !empty($_POST['sec_event_repeats']);
        update_post_meta($post_id, 'event_repeats', $repeats ? 1 : 0);

        if (!$repeats) {
            // Leave the detail fields in place but they are inert without
            // event_repeats=1 (read_rule() returns null). Nothing else to do.
            return;
        }

        $interval = isset($_POST['sec_event_repeat_interval']) ? absint($_POST['sec_event_repeat_interval']) : 1;
        update_post_meta($post_id, 'event_repeat_interval', max(1, $interval));

        $frequency = isset($_POST['sec_event_repeat_frequency']) ? sanitize_text_field(wp_unslash($_POST['sec_event_repeat_frequency'])) : '';
        if (!in_array($frequency, array('daily', 'weekly', 'monthly', 'yearly'), true)) {
            $frequency = 'weekly';
        }
        update_post_meta($post_id, 'event_repeat_frequency', $frequency);

        $byday_post = (isset($_POST['sec_event_repeat_byday']) && is_array($_POST['sec_event_repeat_byday']))
            ? wp_unslash($_POST['sec_event_repeat_byday'])
            : array();
        $byday = array();
        foreach ($byday_post as $piece) {
            $piece = (int) $piece;
            if ($piece >= 0 && $piece <= 6) {
                $byday[$piece] = $piece;
            }
        }
        ksort($byday);
        if (!empty($byday)) {
            update_post_meta($post_id, 'event_repeat_byday', implode(',', array_values($byday)));
        } else {
            delete_post_meta($post_id, 'event_repeat_byday');
        }

        $end_type = isset($_POST['sec_event_repeat_end_type']) ? sanitize_text_field(wp_unslash($_POST['sec_event_repeat_end_type'])) : 'never';
        if (!in_array($end_type, array('never', 'count', 'until'), true)) {
            $end_type = 'never';
        }

        $count = isset($_POST['sec_event_repeat_count']) ? absint($_POST['sec_event_repeat_count']) : 1;
        update_post_meta($post_id, 'event_repeat_count', max(1, $count));

        $until_input = isset($_POST['sec_event_repeat_until']) ? sanitize_text_field(wp_unslash($_POST['sec_event_repeat_until'])) : '';
        $until_ymd = self::input_to_ymd($until_input);

        // "Ends on a date" with no/invalid date would persist a rule that
        // read_rule() rejects (recurrence silently stops). Fall back to "never"
        // so the series keeps generating on its rolling horizon.
        if ('until' === $end_type && 8 !== strlen($until_ymd)) {
            $end_type = 'never';
        }

        update_post_meta($post_id, 'event_repeat_end_type', $end_type);
        update_post_meta($post_id, 'event_repeat_until', $until_ymd);
    }

    /* ---------------------------------------------------------------------
     * Format conversion helpers.
     * ------------------------------------------------------------------- */

    /**
     * Stored Ymd -> HTML date input value (Y-m-d).
     *
     * @param string $ymd Stored value.
     * @return string
     */
    private static function ymd_to_input($ymd) {
        if (8 !== strlen((string) $ymd)) {
            return '';
        }
        $dt = DateTimeImmutable::createFromFormat('!Ymd', (string) $ymd);
        return $dt ? $dt->format('Y-m-d') : '';
    }

    /**
     * HTML date input value (Y-m-d) -> stored Ymd.
     *
     * @param string $input Submitted value.
     * @return string
     */
    private static function input_to_ymd($input) {
        if ('' === (string) $input) {
            return '';
        }
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $input);
        return $dt ? $dt->format('Ymd') : '';
    }

    /**
     * Stored 12h time (g:i a) -> HTML time input value (H:i).
     *
     * @param string $time Stored value.
     * @return string
     */
    private static function time_to_input($time) {
        if ('' === (string) $time) {
            return '';
        }
        // Tolerant of g:i a (current) and legacy H:i:s / H:i written by
        // versions <= 4.4.0, so editing such an event populates the time field
        // instead of blanking it (which would erase the time on the next save).
        $dt = function_exists('simple_events_parse_time_of_day')
            ? simple_events_parse_time_of_day((string) $time)
            : DateTimeImmutable::createFromFormat('g:i a', (string) $time);
        return ($dt instanceof DateTimeImmutable) ? $dt->format('H:i') : '';
    }

    /**
     * HTML time input value (H:i) -> stored 12h time (g:i a).
     *
     * @param string $input Submitted value.
     * @return string
     */
    private static function input_to_time($input) {
        if ('' === (string) $input) {
            return '';
        }
        $dt = DateTimeImmutable::createFromFormat('H:i', (string) $input);
        return $dt ? $dt->format('g:i a') : '';
    }
}
