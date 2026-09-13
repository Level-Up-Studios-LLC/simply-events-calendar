<?php

/**
 * Post type registration class for Simply Events Calendar
 *
 * @package Simple_Events_Calendar
 * @since 3.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Simple Events Post Type class
 */
class Simple_Events_Post_Type {

    /**
     * Constructor
     */
    public function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action('init', array($this, 'register_post_type'), 10);
        add_action('init', array($this, 'register_taxonomies'), 10);
        add_action('wp_head', array($this, 'output_single_schema'));
    }

    /**
     * Emit schema.org Event JSON-LD on single event pages.
     *
     * Guarantees structured data on every event permalink regardless of the
     * active theme/template. Cards emit their own inline schema, so this is
     * gated to the singular view to avoid duplicate emission on archives or
     * shortcode listings.
     *
     * @return void
     */
    public function output_single_schema() {
        if (!is_singular('simple-events') || !function_exists('simple_events_get_event_schema')) {
            return;
        }

        $schema = simple_events_get_event_schema(get_queried_object_id());
        if (!is_array($schema)) {
            return;
        }

        echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>' . "\n";
    }

    /**
     * Register the 'simple-events' post type
     *
     * @return void
     */
    public function register_post_type() {
        $labels = array(
            'name'                  => _x('Events', 'Post Type General Name', 'simply-events-calendar'),
            'singular_name'         => _x('Event', 'Post Type Singular Name', 'simply-events-calendar'),
            'menu_name'             => __('Events', 'simply-events-calendar'),
            'name_admin_bar'        => __('Event', 'simply-events-calendar'),
            'archives'              => __('Event Archives', 'simply-events-calendar'),
            'attributes'            => __('Event Attributes', 'simply-events-calendar'),
            'parent_item_colon'     => __('Parent Event:', 'simply-events-calendar'),
            'all_items'             => __('All Events', 'simply-events-calendar'),
            'add_new_item'          => __('Add New Event', 'simply-events-calendar'),
            'add_new'               => __('Add New', 'simply-events-calendar'),
            'new_item'              => __('New Event', 'simply-events-calendar'),
            'edit_item'             => __('Edit Event', 'simply-events-calendar'),
            'update_item'           => __('Update Event', 'simply-events-calendar'),
            'view_item'             => __('View Event', 'simply-events-calendar'),
            'view_items'            => __('View Events', 'simply-events-calendar'),
            'search_items'          => __('Search Events', 'simply-events-calendar'),
            'not_found'             => __('Not found', 'simply-events-calendar'),
            'not_found_in_trash'    => __('Not found in Trash', 'simply-events-calendar'),
            'featured_image'        => __('Featured Image', 'simply-events-calendar'),
            'set_featured_image'    => __('Set featured image', 'simply-events-calendar'),
            'remove_featured_image' => __('Remove featured image', 'simply-events-calendar'),
            'use_featured_image'    => __('Use as featured image', 'simply-events-calendar'),
            'insert_into_item'      => __('Insert into event', 'simply-events-calendar'),
            'uploaded_to_this_item' => __('Uploaded to this event', 'simply-events-calendar'),
            'items_list'            => __('Events list', 'simply-events-calendar'),
            'items_list_navigation' => __('Events list navigation', 'simply-events-calendar'),
            'filter_items_list'     => __('Filter events list', 'simply-events-calendar'),
        );

        $args = array(
            'label'                 => __('Event', 'simply-events-calendar'),
            'description'           => __('Events for the Simply Events Calendar', 'simply-events-calendar'),
            'labels'                => $labels,
            'supports'              => array('title', 'editor', 'thumbnail', 'revisions', 'excerpt'),
            'taxonomies'            => array('simple-events-cat'),
            'hierarchical'          => false,
            'public'                => true,
            'show_ui'               => true,
            'show_in_menu'          => true,
            'menu_position'         => 20,
            'menu_icon'             => 'dashicons-calendar-alt',
            'show_in_admin_bar'     => true,
            'show_in_nav_menus'     => true,
            'can_export'            => true,
            'has_archive'           => true,
            'exclude_from_search'   => false,
            'publicly_queryable'    => true,
            'capability_type'       => 'page',
            'show_in_rest'          => true,
            'rest_base'             => 'simple-events',
            'rest_controller_class' => 'WP_REST_Posts_Controller',
            'rewrite'               => array(
                // Add-ons (e.g. Pro's configurable URLs) can override the single-event base.
                'slug'       => apply_filters('simple_events_event_slug', 'events'),
                'with_front' => false,
                'pages'      => true,
                'feeds'      => true,
            ),
        );

        register_post_type('simple-events', $args);
    }

    /**
     * Register taxonomies for events
     *
     * @return void
     */
    public function register_taxonomies() {
        $this->register_category_taxonomy();
    }

    /**
     * Register the event category taxonomy
     *
     * @return void
     */
    private function register_category_taxonomy() {
        $labels = array(
            'name'                       => _x('Event Categories', 'Taxonomy General Name', 'simply-events-calendar'),
            'singular_name'              => _x('Event Category', 'Taxonomy Singular Name', 'simply-events-calendar'),
            'menu_name'                  => __('Event Categories', 'simply-events-calendar'),
            'all_items'                  => __('All Event Categories', 'simply-events-calendar'),
            'parent_item'                => __('Parent Event Category', 'simply-events-calendar'),
            'parent_item_colon'          => __('Parent Event Category:', 'simply-events-calendar'),
            'new_item_name'              => __('New Event Category Name', 'simply-events-calendar'),
            'add_new_item'               => __('Add New Event Category', 'simply-events-calendar'),
            'edit_item'                  => __('Edit Event Category', 'simply-events-calendar'),
            'update_item'                => __('Update Event Category', 'simply-events-calendar'),
            'view_item'                  => __('View Event Category', 'simply-events-calendar'),
            'separate_items_with_commas' => __('Separate event categories with commas', 'simply-events-calendar'),
            'add_or_remove_items'        => __('Add or remove event categories', 'simply-events-calendar'),
            'choose_from_most_used'      => __('Choose from the most used', 'simply-events-calendar'),
            'popular_items'              => __('Popular Event Categories', 'simply-events-calendar'),
            'search_items'               => __('Search Event Categories', 'simply-events-calendar'),
            'not_found'                  => __('Not Found', 'simply-events-calendar'),
            'no_terms'                   => __('No event categories', 'simply-events-calendar'),
            'items_list'                 => __('Event categories list', 'simply-events-calendar'),
            'items_list_navigation'      => __('Event categories list navigation', 'simply-events-calendar'),
        );

        $args = array(
            'labels'                => $labels,
            'hierarchical'          => true,
            'public'                => true,
            'show_ui'               => true,
            'show_admin_column'     => true,
            'show_in_nav_menus'     => true,
            'show_tagcloud'         => true,
            'show_in_rest'          => true,
            'rest_base'             => 'simple-events-categories',
            'rest_controller_class' => 'WP_REST_Terms_Controller',
            'rewrite'               => array(
                'slug'         => 'event-category',
                'with_front'   => false,
                'hierarchical' => true,
            ),
        );

        register_taxonomy('simple-events-cat', array('simple-events'), $args);
    }

    /**
     * Get post type slug
     *
     * @return string
     */
    public static function get_post_type() {
        return 'simple-events';
    }

    /**
     * Get category taxonomy slug
     *
     * @return string
     */
    public static function get_category_taxonomy() {
        return 'simple-events-cat';
    }

    /**
     * Check if current page is an event page
     *
     * @return bool
     */
    public static function is_event_page() {
        return is_singular('simple-events') ||
               is_post_type_archive('simple-events') ||
               is_tax('simple-events-cat');
    }

    /**
     * Get events query args
     *
     * @param array $args Additional query arguments
     * @return array
     */
    public static function get_events_query_args($args = array()) {
        $defaults = array(
            'post_type'      => 'simple-events',
            'post_status'    => 'publish',
            'posts_per_page' => 6,
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
            'meta_key'       => 'event_date',
            'meta_type'      => 'DATE',
            'meta_query'     => array(
                array(
                    'key'     => 'event_date',
                    'compare' => '>=',
                    'value'   => current_time('Ymd'),
                    'type'    => 'DATE'
                )
            )
        );

        return wp_parse_args($args, $defaults);
    }
}