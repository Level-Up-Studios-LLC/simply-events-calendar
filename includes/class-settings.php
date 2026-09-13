<?php

/**
 * Settings page for Simply Events Calendar
 *
 * Registers a single option array (simple_events_settings) via the WordPress
 * Settings API and renders an admin page under the Events menu. Also wires the
 * relevant settings into the plugin's public recurrence filters and provides a
 * "Clear cache now" action.
 *
 * @package Simple_Events_Calendar
 * @since 5.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Simple_Events_Settings class
 */
class Simple_Events_Settings {

    /**
     * Settings option name. Must match SIMPLE_EVENTS_SETTINGS_OPTION.
     */
    const OPTION = 'simple_events_settings';

    /**
     * Settings page slug.
     */
    const PAGE = 'simple-events-settings';

    /**
     * Constructor.
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_post_simple_events_clear_cache', array($this, 'handle_clear_cache'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));

        // Wire the recurrence-limit settings into the public filters.
        add_filter('sec_recur_max_occurrences', array($this, 'filter_max_occurrences'));
        add_filter('sec_recur_max_horizon_months', array($this, 'filter_max_horizon_months'));
    }

    /**
     * Register the settings submenu page under the Events menu.
     */
    public function add_menu() {
        add_submenu_page(
            'edit.php?post_type=simple-events',
            __('Events Settings', 'simply-events-calendar'),
            __('Settings', 'simply-events-calendar'),
            'manage_options',
            self::PAGE,
            array($this, 'render_page')
        );
    }

    /**
     * Register the option and its sanitize callback.
     */
    public function register_settings() {
        register_setting(
            self::PAGE,
            self::OPTION,
            array(
                'type'              => 'array',
                'sanitize_callback' => array($this, 'sanitize'),
                'default'           => simple_events_get_setting_defaults(),
            )
        );
    }

    /**
     * Sanitize the full settings array on save and flush rendered caches.
     *
     * @param array $input Raw submitted values.
     * @return array Sanitized values merged over defaults.
     */
    public function sanitize($input) {
        $defaults = simple_events_get_setting_defaults();
        $input    = is_array($input) ? $input : array();
        $clean    = array();

        // Date format: a known preset, or a custom PHP format when "custom" is chosen.
        $allowed_presets = array('l, F j, Y', 'F j, Y', 'm/d/Y', 'M j, Y');
        $preset = isset($input['date_format_preset']) ? (string) $input['date_format_preset'] : '';
        if ('custom' === $preset) {
            $custom = isset($input['date_format_custom']) ? trim((string) $input['date_format_custom']) : '';
            $clean['date_format'] = ('' !== $custom) ? sanitize_text_field($custom) : $defaults['date_format'];
        } elseif (in_array($preset, $allowed_presets, true)) {
            $clean['date_format'] = $preset;
        } else {
            $clean['date_format'] = $defaults['date_format'];
        }

        // Time format: 12 or 24.
        $clean['time_format'] = (isset($input['time_format']) && '24' === (string) $input['time_format']) ? '24' : '12';

        // Per-page count clamped 1-50 (mirrors the shortcode clamp).
        $ppp = isset($input['posts_per_page']) ? absint($input['posts_per_page']) : $defaults['posts_per_page'];
        $clean['posts_per_page'] = ($ppp >= 1 && $ppp <= 50) ? $ppp : $defaults['posts_per_page'];

        // Order.
        $clean['order'] = (isset($input['order']) && 'DESC' === strtoupper((string) $input['order'])) ? 'DESC' : 'ASC';

        // Yes/no toggles.
        foreach (array('show_past', 'show_time', 'show_excerpt', 'show_location', 'show_footer', 'enable_schema', 'delete_data_on_uninstall') as $flag) {
            $clean[$flag] = (isset($input[$flag]) && 'yes' === (string) $input[$flag]) ? 'yes' : 'no';
        }

        // Cache TTL in minutes (1-1440).
        $ttl = isset($input['cache_ttl']) ? absint($input['cache_ttl']) : $defaults['cache_ttl'];
        $clean['cache_ttl'] = ($ttl >= 1 && $ttl <= 1440) ? $ttl : $defaults['cache_ttl'];

        // Load-more batch size (1-50).
        $inc = isset($input['load_increment']) ? absint($input['load_increment']) : $defaults['load_increment'];
        $clean['load_increment'] = ($inc >= 1 && $inc <= 50) ? $inc : $defaults['load_increment'];

        // Recurrence limits.
        $max_occ = isset($input['recur_max_occurrences']) ? absint($input['recur_max_occurrences']) : $defaults['recur_max_occurrences'];
        $clean['recur_max_occurrences'] = max(1, $max_occ);
        $max_hor = isset($input['recur_max_horizon_months']) ? absint($input['recur_max_horizon_months']) : $defaults['recur_max_horizon_months'];
        $clean['recur_max_horizon_months'] = max(1, $max_hor);

        // Output now depends on settings — flush the rendered shortcode caches.
        if (function_exists('simple_events_clear_all_transients')) {
            simple_events_clear_all_transients();
        }

        /**
         * Filter the sanitized settings before they are saved. Add-ons hook this to
         * sanitize and persist their own setting keys — the logic above only keeps
         * keys the free plugin knows about, so unknown keys are dropped otherwise.
         * `$previous` is the currently stored option (array, or false if unset), so
         * add-ons can detect a changed value (e.g. to flag a rewrite-rule flush).
         *
         * @param array $clean    Sanitized known settings.
         * @param array $input    Raw submitted settings.
         * @param mixed $previous Previously stored option value.
         */
        return apply_filters('simple_events_sanitize_settings', $clean, $input, get_option(self::OPTION));
    }

    /**
     * Filter callback: cap on total occurrences per series.
     *
     * @param int $value Default value.
     * @return int
     */
    public function filter_max_occurrences($value) {
        return (int) simple_events_get_setting('recur_max_occurrences', $value);
    }

    /**
     * Filter callback: cap on the "never"-series horizon in months.
     *
     * @param int $value Default value.
     * @return int
     */
    public function filter_max_horizon_months($value) {
        return (int) simple_events_get_setting('recur_max_horizon_months', $value);
    }

    /**
     * Handle the "Clear cache now" admin-post action.
     */
    public function handle_clear_cache() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do that.', 'simply-events-calendar'));
        }

        check_admin_referer('simple_events_clear_cache');

        if (function_exists('simple_events_clear_all_transients')) {
            simple_events_clear_all_transients();
        }

        wp_safe_redirect(add_query_arg(
            array('page' => self::PAGE, 'sec_cache_cleared' => '1'),
            admin_url('edit.php?post_type=simple-events')
        ));
        exit;
    }

    /**
     * Enqueue the settings-page script (date-format preset toggle + preview).
     *
     * @param string $hook Current admin page hook suffix.
     */
    public function enqueue_assets($hook) {
        // Submenu pages under a CPT use the "<post_type>_page_<slug>" hook form.
        if ('simple-events_page_' . self::PAGE !== $hook) {
            return;
        }

        wp_enqueue_script(
            'simple-events-settings',
            SIMPLE_EVENTS_ASSETS . '/js/simple-events-settings.js',
            array(),
            SIMPLE_EVENTS_VERSION,
            true
        );
    }

    /**
     * Render the settings page.
     */
    public function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = simple_events_get_settings();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Events Settings', 'simply-events-calendar'); ?></h1>

            <?php Simple_Events_Pro_Upsell::banner(); ?>

            <?php if (isset($_GET['sec_cache_cleared'])) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html__('Event cache cleared.', 'simply-events-calendar'); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields(self::PAGE); ?>

                <h2><?php echo esc_html__('Display formatting', 'simply-events-calendar'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__('Date format', 'simply-events-calendar'); ?></th>
                        <td>
                            <?php
                            $presets = array(
                                'l, F j, Y' => __('Weekday, Month Day, Year', 'simply-events-calendar'),
                                'F j, Y'    => __('Month Day, Year', 'simply-events-calendar'),
                                'm/d/Y'     => __('MM/DD/YYYY', 'simply-events-calendar'),
                                'M j, Y'    => __('Abbreviated Month Day, Year', 'simply-events-calendar'),
                            );
                            $current   = (string) $settings['date_format'];
                            $is_preset = array_key_exists($current, $presets);
                            ?>
                            <fieldset id="sec-date-format" class="sec-date-format">
                                <legend class="screen-reader-text"><?php echo esc_html__('Date format', 'simply-events-calendar'); ?></legend>
                                <?php foreach ($presets as $fmt => $label) : ?>
                                    <label>
                                        <input type="radio" name="<?php echo esc_attr(self::OPTION); ?>[date_format_preset]" value="<?php echo esc_attr($fmt); ?>" <?php checked($is_preset && $current === $fmt); ?> />
                                        <span class="date-time-text format-i18n"><?php echo esc_html(wp_date($fmt)); ?></span>
                                        <code><?php echo esc_html($fmt); ?></code>
                                    </label><br />
                                <?php endforeach; ?>
                                <label>
                                    <input type="radio" id="sec-date-format-custom-radio" name="<?php echo esc_attr(self::OPTION); ?>[date_format_preset]" value="custom" <?php checked(!$is_preset); ?> />
                                    <span class="date-time-text"><?php echo esc_html__('Custom:', 'simply-events-calendar'); ?></span>
                                </label>
                                <input type="text" id="sec-date-format-custom" class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[date_format_custom]" value="<?php echo esc_attr(!$is_preset ? $current : ''); ?>" placeholder="l, F j, Y" aria-label="<?php esc_attr_e('Custom date format', 'simply-events-calendar'); ?>" />
                                <p class="description">
                                    <?php echo esc_html__('Choose a preset, or pick "Custom" to enter a PHP date format.', 'simply-events-calendar'); ?>
                                    <a href="https://wordpress.org/documentation/article/customize-date-and-time-format/" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Format reference', 'simply-events-calendar'); ?></a>
                                </p>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Time format', 'simply-events-calendar'); ?></th>
                        <td>
                            <label><input type="radio" name="<?php echo esc_attr(self::OPTION); ?>[time_format]" value="12" <?php checked($settings['time_format'], '12'); ?> /> <?php echo esc_html__('12-hour (2:30 pm)', 'simply-events-calendar'); ?></label><br />
                            <label><input type="radio" name="<?php echo esc_attr(self::OPTION); ?>[time_format]" value="24" <?php checked($settings['time_format'], '24'); ?> /> <?php echo esc_html__('24-hour (14:30)', 'simply-events-calendar'); ?></label>
                        </td>
                    </tr>
                </table>

                <h2><?php echo esc_html__('Display defaults', 'simply-events-calendar'); ?></h2>
                <p class="description"><?php echo esc_html__('Defaults for the [sec_events] shortcode and the event archives; shortcode attributes override them per instance. On archive pages the page size uses the "Load more" batch size below (so infinite-scroll offsets line up), not "Events per page".', 'simply-events-calendar'); ?></p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__('Events per page', 'simply-events-calendar'); ?></th>
                        <td><input type="number" min="1" max="50" name="<?php echo esc_attr(self::OPTION); ?>[posts_per_page]" value="<?php echo esc_attr($settings['posts_per_page']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Default sort order', 'simply-events-calendar'); ?></th>
                        <td>
                            <label><input type="radio" name="<?php echo esc_attr(self::OPTION); ?>[order]" value="ASC" <?php checked($settings['order'], 'ASC'); ?> /> <?php echo esc_html__('Ascending (soonest first)', 'simply-events-calendar'); ?></label><br />
                            <label><input type="radio" name="<?php echo esc_attr(self::OPTION); ?>[order]" value="DESC" <?php checked($settings['order'], 'DESC'); ?> /> <?php echo esc_html__('Descending (latest first)', 'simply-events-calendar'); ?></label>
                        </td>
                    </tr>
                    <?php
                    $toggles = array(
                        'show_past'     => __('Show past events by default', 'simply-events-calendar'),
                        'show_time'     => __('Show event time', 'simply-events-calendar'),
                        'show_excerpt'  => __('Show excerpt', 'simply-events-calendar'),
                        'show_location' => __('Show location', 'simply-events-calendar'),
                        'show_footer'   => __('Show card footer / read-more', 'simply-events-calendar'),
                    );
                    foreach ($toggles as $key => $label) :
                        ?>
                        <tr>
                            <th scope="row"><?php echo esc_html($label); ?></th>
                            <td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[<?php echo esc_attr($key); ?>]" value="yes" <?php checked($settings[$key], 'yes'); ?> /> <?php echo esc_html__('Enabled', 'simply-events-calendar'); ?></label></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <th scope="row"><?php echo esc_html__('"Load more" batch size', 'simply-events-calendar'); ?></th>
                        <td>
                            <input type="number" min="1" max="50" name="<?php echo esc_attr(self::OPTION); ?>[load_increment]" value="<?php echo esc_attr($settings['load_increment']); ?>" />
                            <p class="description"><?php echo esc_html__('How many events to load per "load more" / infinite-scroll request.', 'simply-events-calendar'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php echo esc_html__('SEO', 'simply-events-calendar'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__('schema.org JSON-LD', 'simply-events-calendar'); ?></th>
                        <td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[enable_schema]" value="yes" <?php checked($settings['enable_schema'], 'yes'); ?> /> <?php echo esc_html__('Output Event structured data on cards and single event pages', 'simply-events-calendar'); ?></label></td>
                    </tr>
                </table>

                <h2><?php echo esc_html__('Recurrence limits', 'simply-events-calendar'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__('Max occurrences per series', 'simply-events-calendar'); ?></th>
                        <td><input type="number" min="1" name="<?php echo esc_attr(self::OPTION); ?>[recur_max_occurrences]" value="<?php echo esc_attr($settings['recur_max_occurrences']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Max horizon (months) for "never" series', 'simply-events-calendar'); ?></th>
                        <td><input type="number" min="1" name="<?php echo esc_attr(self::OPTION); ?>[recur_max_horizon_months]" value="<?php echo esc_attr($settings['recur_max_horizon_months']); ?>" /></td>
                    </tr>
                </table>

                <h2><?php echo esc_html__('Cache', 'simply-events-calendar'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__('Cache lifetime (minutes)', 'simply-events-calendar'); ?></th>
                        <td><input type="number" min="1" max="1440" name="<?php echo esc_attr(self::OPTION); ?>[cache_ttl]" value="<?php echo esc_attr($settings['cache_ttl']); ?>" /></td>
                    </tr>
                </table>

                <h2><?php echo esc_html__('Data', 'simply-events-calendar'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__('When the plugin is deleted', 'simply-events-calendar'); ?></th>
                        <td>
                            <label><input type="radio" name="<?php echo esc_attr(self::OPTION); ?>[delete_data_on_uninstall]" value="no" <?php checked($settings['delete_data_on_uninstall'], 'no'); ?> /> <?php echo esc_html__('Keep all events and settings (recommended)', 'simply-events-calendar'); ?></label><br />
                            <label><input type="radio" name="<?php echo esc_attr(self::OPTION); ?>[delete_data_on_uninstall]" value="yes" <?php checked($settings['delete_data_on_uninstall'], 'yes'); ?> /> <?php echo esc_html__('Permanently delete all events, categories, and settings', 'simply-events-calendar'); ?></label>
                            <p class="description"><?php echo esc_html__('Deletion only happens when you delete the plugin from the Plugins screen — never on deactivation. Leave this set to "Keep all events and settings" if you may reinstall later.', 'simply-events-calendar'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php
                /**
                 * Fires inside the settings form, after the built-in sections and
                 * before the submit button. Add-ons echo their own settings sections
                 * (form-table markup) here; persist their keys via the
                 * `simple_events_sanitize_settings` filter.
                 *
                 * @param array $settings Current settings values.
                 */
                do_action('simple_events_settings_after_sections', $settings);
                ?>

                <?php submit_button(); ?>
            </form>

            <hr />
            <?php Simple_Events_Pro_Upsell::locked_section(); ?>

            <hr />
            <h2><?php echo esc_html__('Maintenance', 'simply-events-calendar'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="simple_events_clear_cache" />
                <?php wp_nonce_field('simple_events_clear_cache'); ?>
                <?php submit_button(__('Clear cache now', 'simply-events-calendar'), 'secondary', 'submit', false); ?>
                <p class="description"><?php echo esc_html__('Clears all cached event listings immediately.', 'simply-events-calendar'); ?></p>
            </form>
        </div>
        <?php
    }
}
