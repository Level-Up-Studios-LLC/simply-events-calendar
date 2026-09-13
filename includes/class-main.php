<?php

/**
 * Main plugin class for Simply Events Calendar
 *
 * @package Simple_Events_Calendar
 * @since 3.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main Simply Events Calendar class
 */
class Simple_Events_Calendar {

    /**
     * Plugin version (set from SIMPLE_EVENTS_VERSION in constructor)
     *
     * @var string
     */
    public $version = '';

    /**
     * Plugin instance
     *
     * @var Simple_Events_Calendar
     */
    private static $instance = null;

    /**
     * Post type handler
     *
     * @var Simple_Events_Post_Type
     */
    public $post_type;

    /**
     * Shortcode handler
     *
     * @var Simple_Events_Shortcode
     */
    public $shortcode;

    /**
     * AJAX handler
     *
     * @var Simple_Events_Ajax
     */
    public $ajax;

    /**
     * Admin columns handler
     *
     * @var Simple_Events_Admin_Columns
     */
    public $admin_columns;

    /**
     * Recurrence engine
     *
     * @var Simple_Events_Recurrence
     */
    public $recurrence;

    /**
     * Settings handler
     *
     * @var Simple_Events_Settings
     */
    public $settings;

    /**
     * Documentation page
     *
     * @var Simple_Events_Docs
     */
    public $docs;

    /**
     * Pro upsell UI
     *
     * @var Simple_Events_Pro_Upsell
     */
    public $pro_upsell;

    /**
     * "Add to Calendar" .ics generator
     *
     * @var Simple_Events_ICS
     */
    public $ics;

    /**
     * One-time data migrations
     *
     * @var Simple_Events_Migrations
     */
    public $migrations;

    /**
     * Element renderer / element shortcodes
     *
     * @var Simple_Events_Renderer
     */
    public $renderer;

    /**
     * Edit-screen meta box
     *
     * @var Simple_Events_Meta_Box
     */
    public $meta_box;

    /**
     * Front-end template loader
     *
     * @var Simple_Events_Templates
     */
    public $templates;

    /**
     * Guard to prevent init() from running twice
     *
     * @var bool
     */
    private $initialized = false;

    /**
     * Get plugin instance
     *
     * @return Simple_Events_Calendar
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->version = defined('SIMPLE_EVENTS_VERSION') ? SIMPLE_EVENTS_VERSION : '0.0.0';
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action('plugins_loaded', array($this, 'init'), 20);
        add_action('init', array($this, 'load_textdomain'));

        // Activation/Deactivation hooks
        register_activation_hook(SIMPLE_EVENTS_PLUGIN_FILE, array($this, 'activation_check'));
        register_deactivation_hook(SIMPLE_EVENTS_PLUGIN_FILE, array($this, 'deactivation'));
        register_uninstall_hook(SIMPLE_EVENTS_PLUGIN_FILE, array('Simple_Events_Calendar', 'uninstall'));
    }

    /**
     * Initialize plugin
     *
     * Hooked on `plugins_loaded` (priority 20). Guarded to only run once.
     */
    public function init() {
        if ($this->initialized) {
            return;
        }

        $this->initialized = true;

        // Load components
        $this->load_components();

        // Initialize query modifications
        add_action('pre_get_posts', array($this, 'modify_archive_query'));
        add_action('init', array($this, 'ensure_public_access'));

        // Enqueue scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'register_assets'), 1);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

        // Add action links
        add_filter('plugin_action_links_' . plugin_basename(SIMPLE_EVENTS_PLUGIN_FILE), array($this, 'action_links'));
    }

    /**
     * Load plugin components
     */
    private function load_components() {
        // Load utility functions first
        require_once SIMPLE_EVENTS_DIR . '/includes/functions.php';

        // Load class files
        require_once SIMPLE_EVENTS_DIR . '/includes/class-migrations.php';
        require_once SIMPLE_EVENTS_DIR . '/includes/class-post-type.php';
        require_once SIMPLE_EVENTS_DIR . '/includes/class-renderer.php';
        require_once SIMPLE_EVENTS_DIR . '/includes/class-shortcode.php';
        require_once SIMPLE_EVENTS_DIR . '/includes/class-ajax.php';
        require_once SIMPLE_EVENTS_DIR . '/includes/class-admin-columns.php';
        require_once SIMPLE_EVENTS_DIR . '/includes/class-meta-box.php';
        require_once SIMPLE_EVENTS_DIR . '/includes/class-settings.php';
        require_once SIMPLE_EVENTS_DIR . '/includes/class-docs.php';
        require_once SIMPLE_EVENTS_DIR . '/includes/class-pro-upsell.php';
        require_once SIMPLE_EVENTS_DIR . '/includes/class-ics.php';
        require_once SIMPLE_EVENTS_DIR . '/includes/class-templates.php';
        require_once SIMPLE_EVENTS_DIR . '/includes/class-recurrence.php';

        // Initialize component classes
        $this->migrations = new Simple_Events_Migrations();
        $this->post_type = new Simple_Events_Post_Type();
        $this->renderer = new Simple_Events_Renderer();
        $this->shortcode = new Simple_Events_Shortcode();
        $this->ajax = new Simple_Events_Ajax();
        $this->admin_columns = new Simple_Events_Admin_Columns();
        $this->meta_box = new Simple_Events_Meta_Box();
        $this->settings = new Simple_Events_Settings();
        $this->docs = new Simple_Events_Docs();
        $this->pro_upsell = new Simple_Events_Pro_Upsell();
        $this->ics = new Simple_Events_ICS();
        $this->templates = new Simple_Events_Templates();
        $this->recurrence = new Simple_Events_Recurrence();

        // Elementor integration (no-op unless Elementor is active).
        require_once SIMPLE_EVENTS_DIR . '/includes/elementor/class-elementor.php';
        Simple_Events_Elementor::init();

        // Premium features. includes/pro/ is stripped from the free build, so
        // this block is a no-op there even if the processor leaves it behind.
        if (sec_fs()->is__premium_only()) {
            $pro = SIMPLE_EVENTS_DIR . '/includes/pro/class-pro-loader.php';
            if (file_exists($pro)) {
                require_once $pro;
                Simple_Events_Pro_Loader::init();
            }
        }
    }

    /**
     * Load plugin text domain for translations
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'simply-events-calendar',
            false,
            dirname(plugin_basename(SIMPLE_EVENTS_PLUGIN_FILE)) . '/languages/'
        );
    }

    /**
     * Plugin activation check
     */
    public function activation_check() {
        if (function_exists('wp_clean_plugins_cache')) {
            wp_clean_plugins_cache();
        }

        $this->init();

        if (class_exists('Simple_Events_Recurrence')) {
            Simple_Events_Recurrence::schedule_cron();
        }

        flush_rewrite_rules();
        wp_cache_flush();
    }

    /**
     * Plugin deactivation
     */
    public function deactivation() {
        // Inlined to avoid loading class-recurrence.php on the deactivation
        // path; deactivation can run before init() has loaded components.
        // wp_unschedule_hook (not wp_clear_scheduled_hook) — the continuation
        // events are scheduled with per-parent args, and wp_clear_scheduled_hook
        // with no-arg call only matches no-arg events.
        wp_unschedule_hook('sec_recur_extend_horizon');
        wp_unschedule_hook('sec_recur_continue_generation');
        flush_rewrite_rules();
    }

    /**
     * Plugin uninstall
     */
    public static function uninstall() {
        // Respect the admin's data-retention choice. Default is to retain, so a
        // reinstall keeps existing events. Only an explicit opt-in deletes data.
        // The option name is a bare string on purpose: uninstall runs in an
        // isolated request where functions.php / the settings class are not
        // loaded, so the SIMPLE_EVENTS_SETTINGS_OPTION constant is unavailable.
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

    /**
     * Ensure the post type is publicly queryable on the front end.
     */
    public function ensure_public_access() {
        global $wp_post_types;
        if (isset($wp_post_types['simple-events'])) {
            $wp_post_types['simple-events']->public = true;
            $wp_post_types['simple-events']->publicly_queryable = true;
            $wp_post_types['simple-events']->show_in_nav_menus = true;
        }
    }

    /**
     * Modify archive query
     *
     * @param WP_Query $query
     */
    public function modify_archive_query($query) {
        if (is_admin() || !$query->is_main_query()) {
            return;
        }

        if (!is_post_type_archive('simple-events') && !is_tax('simple-events-cat')) {
            return;
        }

        $today = current_time('Ymd');

        // Honor the display-default settings so archives match the documented
        // behavior (and the [sec_events] defaults).
        $order = strtoupper((string) simple_events_get_setting('order', 'ASC'));
        $order = in_array($order, array('ASC', 'DESC'), true) ? $order : 'ASC';
        $show_past = ('yes' === (string) simple_events_get_setting('show_past', 'no'));

        $query->set('orderby', 'meta_value');
        $query->set('order', $order);
        $query->set('meta_key', 'event_date');
        $query->set('meta_type', 'DATE');
        $query->set('suppress_filters', false);

        // Match the first batch size to the "load more" increment so infinite
        // scroll offsets line up with what the archive template rendered.
        $query->set('posts_per_page', max(1, (int) simple_events_get_setting('load_increment', 6)));

        // Only hide past events when the setting says so.
        if (!$show_past) {
            $date_clause = array(
                'key'     => 'event_date',
                'compare' => '>=',
                'value'   => $today,
                'type'    => 'DATE',
            );

            $existing_meta_query = $query->get('meta_query');

            if (!empty($existing_meta_query) && is_array($existing_meta_query)) {
                // Nest rather than merge, so existing relation/clauses are preserved intact.
                $meta_query = array(
                    'relation' => 'AND',
                    $existing_meta_query,
                    $date_clause,
                );
            } else {
                $meta_query = array($date_clause);
            }

            $query->set('meta_query', $meta_query);
        }
    }

    /**
     * Register (but do not enqueue) the front-end stylesheet so shortcodes and
     * Elementor widgets can enqueue it on demand on non-event pages.
     */
    public function register_assets() {
        if (!wp_style_is('simple-events-style', 'registered')) {
            wp_register_style(
                'simple-events-style',
                SIMPLE_EVENTS_ASSETS . '/css/simple-events.css',
                array(),
                $this->version
            );
        }

        // Register + localize the infinite-scroll script once so the gated
        // page path AND Elementor widgets (via get_script_depends) share the
        // same handle and ajax_params payload.
        if (!wp_script_is('simple-events-script', 'registered')) {
            wp_register_script(
                'simple-events-script',
                SIMPLE_EVENTS_ASSETS . '/js/simple-events.js',
                array('jquery'),
                $this->version,
                true
            );

            $increment = max(1, (int) simple_events_get_setting('load_increment', 6));
            wp_localize_script(
                'simple-events-script',
                'ajax_params',
                array(
                    'ajaxurl'        => admin_url('admin-ajax.php'),
                    'nonce'          => wp_create_nonce(SIMPLE_EVENTS_NONCE_ACTION),
                    'initial_offset' => $increment,
                    'load_increment' => $increment,
                    'loading_text'   => __('Loading more events...', 'simply-events-calendar'),
                    'retry_text'     => __('Try Again', 'simply-events-calendar'),
                    'no_more_text'   => __('No more events to load.', 'simply-events-calendar'),
                )
            );
        }
    }

    /**
     * Enqueue scripts and styles
     */
    public function enqueue_scripts() {
        global $post;
        $should_load = false;

        if (is_post_type_archive('simple-events') ||
            is_singular('simple-events') ||
            is_tax('simple-events-cat')) {
            $should_load = true;
        }

        if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'sec_events')) {
            $should_load = true;
        }

        if (!$should_load && is_active_widget(false, false, 'text')) {
            $should_load = true;
        }

        if (!$should_load) {
            return;
        }

        // Enqueue the handles registered in register_assets() (priority 1).
        wp_enqueue_style('simple-events-style');
        wp_enqueue_script('simple-events-script');
    }

    /**
     * Add action links to plugin page
     *
     * @param array $links
     * @return array
     */
    public function action_links($links) {
        $plugin_links = array(
            '<a href="' . esc_url(admin_url('edit.php?post_type=simple-events')) . '">' . esc_html__('Events', 'simply-events-calendar') . '</a>',
            '<a href="' . esc_url(admin_url('edit.php?post_type=simple-events&page=' . Simple_Events_Settings::PAGE)) . '">' . esc_html__('Settings', 'simply-events-calendar') . '</a>',
        );

        return array_merge($plugin_links, $links);
    }
}