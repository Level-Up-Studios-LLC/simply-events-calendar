<?php

/**
 * Pro feature: configurable event URL base.
 *
 * Lets the admin change the single-event permalink base (default 'events',
 * e.g. /events/your-event → /happenings/your-event) from Events → Settings.
 * Rename-only and events-only: the category base (event-category) is
 * unchanged, and there are no automatic redirects from the old base.
 *
 * Implemented entirely through the plugin's public extension hooks — no
 * base code is duplicated or overridden:
 *
 * - simple_events_setting_defaults     registers the 'event_slug' default
 * - simple_events_event_slug           feeds the slug into register_post_type
 * - simple_events_settings_after_sections renders the Permalinks section
 * - simple_events_sanitize_settings    persists the key + flags a flush
 *
 * The one-time rewrite flush runs at init:11 — after the free plugin has
 * re-registered the post type (init:10) with the new, filtered slug.
 *
 * @package Simply_Events_Calendar
 * @since 6.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sec_Pro_Configurable_Urls class
 */
class Sec_Pro_Configurable_Urls {

    /**
     * Key inside the free plugin's simple_events_settings option.
     */
    const SETTING_KEY = 'event_slug';

    /**
     * The free plugin's built-in base — also the fallback for empty/invalid input.
     */
    const DEFAULT_SLUG = 'events';

    /**
     * One-time rewrite-flush flag option (autoload off, deleted after the flush).
     */
    const FLUSH_FLAG = 'sec_flush_rewrite_rules';

    /**
     * Register all hook callbacks against the free plugin's extension points.
     */
    public function register() {
        add_filter('simple_events_setting_defaults', array($this, 'add_setting_default'));
        add_filter('simple_events_event_slug', array($this, 'filter_event_slug'));
        add_action('simple_events_settings_after_sections', array($this, 'render_settings_section'));
        add_filter('simple_events_sanitize_settings', array($this, 'sanitize_settings'), 10, 3);
        add_action('init', array($this, 'maybe_flush_rewrite_rules'), 11);
    }

    /**
     * Register the default so simple_events_get_setting() resolves the key
     * before it has ever been saved.
     *
     * @param array $defaults Free plugin's setting defaults.
     * @return array
     */
    public function add_setting_default($defaults) {
        $defaults[self::SETTING_KEY] = self::DEFAULT_SLUG;

        return $defaults;
    }

    /**
     * The configured single-event URL base, sanitized; empty/invalid values
     * fall back to the default so the post type never registers with a broken
     * rewrite base.
     *
     * @param string $slug The free plugin's default base.
     * @return string
     */
    public function filter_event_slug($slug) {
        $value = sanitize_title((string) simple_events_get_setting(self::SETTING_KEY, self::DEFAULT_SLUG));

        return ('' !== $value) ? $value : self::DEFAULT_SLUG;
    }

    /**
     * Render the Permalinks section inside the free plugin's settings form.
     *
     * The field posts as simple_events_settings[event_slug], so it flows
     * through the free plugin's sanitize() and into our sanitize_settings().
     *
     * @param array $settings Current settings merged with (filtered) defaults.
     */
    public function render_settings_section($settings) {
        $option = Simple_Events_Settings::OPTION;
        $value  = isset($settings[self::SETTING_KEY]) ? (string) $settings[self::SETTING_KEY] : self::DEFAULT_SLUG;
        ?>
        <h2><?php echo esc_html__('Permalinks', 'simply-events-calendar'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php echo esc_html__('Event URL base', 'simply-events-calendar'); ?></th>
                <td>
                    <span class="sec-permalink-preview">
                        <code><?php echo esc_html(trailingslashit(home_url())); ?></code><input type="text" class="regular-text code" name="<?php echo esc_attr($option); ?>[<?php echo esc_attr(self::SETTING_KEY); ?>]" value="<?php echo esc_attr($value); ?>" placeholder="events" aria-label="<?php esc_attr_e('Event URL base', 'simply-events-calendar'); ?>" /><code>/sample-event</code>
                    </span>
                    <p class="description">
                        <?php echo esc_html__('The base used in single event URLs — e.g. "events" gives /events/your-event, "happenings" gives /happenings/your-event. Use lowercase letters, numbers, and hyphens.', 'simply-events-calendar'); ?>
                        <br />
                        <?php echo esc_html__('Avoid a value that matches an existing page slug, or that page and your events will conflict. Saving a change refreshes permalinks automatically; old links to the previous base will stop working.', 'simply-events-calendar'); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Sanitize and persist the event_slug key, which the free plugin's
     * sanitizer would otherwise drop as unknown.
     *
     * @param array       $clean    Sanitized settings about to be saved.
     * @param array       $input    Raw submitted settings.
     * @param array|false $previous Pre-save option value, for change detection.
     * @return array
     */
    public function sanitize_settings($clean, $input, $previous) {
        $slug_in = isset($input[self::SETTING_KEY]) ? sanitize_title((string) $input[self::SETTING_KEY]) : '';

        $clean[self::SETTING_KEY] = ('' !== $slug_in) ? $slug_in : self::DEFAULT_SLUG;

        // If the base changed, flag a one-time rewrite flush. The flush can't
        // happen here: on this request init already ran with the OLD slug, so
        // flushing now would regenerate the rules from stale post-type args.
        $old_slug = (is_array($previous) && isset($previous[self::SETTING_KEY]))
            ? (string) $previous[self::SETTING_KEY]
            : self::DEFAULT_SLUG;

        if ($clean[self::SETTING_KEY] !== $old_slug) {
            update_option(self::FLUSH_FLAG, '1', false);
        }

        return $clean;
    }

    /**
     * Flush rewrite rules once after the event URL base changes.
     *
     * Runs at init:11 — after the free plugin registered the post type at
     * init:10 with the new slug — so the regenerated rules reflect the new
     * base. The flag is cleared so the flush happens only once.
     */
    public function maybe_flush_rewrite_rules() {
        if (get_option(self::FLUSH_FLAG)) {
            flush_rewrite_rules();
            delete_option(self::FLUSH_FLAG);
        }
    }
}
