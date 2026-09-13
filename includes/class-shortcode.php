<?php

/**
 * Shortcode functionality class for Simply Events Calendar
 *
 * @package Simple_Events_Calendar
 * @since 3.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Simple Events Shortcode class
 */
class Simple_Events_Shortcode
{

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks()
    {
        add_shortcode('sec_events', array($this, 'render_shortcode'));
        add_shortcode('sec_event', array($this, 'render_single_shortcode'));
        add_action('save_post', array($this, 'clear_cache'));
        add_action('delete_post', array($this, 'clear_cache'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('transition_post_status', array($this, 'clear_cache_on_status_change'), 10, 3);
    }

    /**
     * Render the shortcode
     *
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public function render_shortcode($atts)
    {
        // Defaults come from the settings page so site-wide preferences apply,
        // while any explicit shortcode attribute still overrides per instance.
        $settings = simple_events_get_settings();
        $atts = shortcode_atts(array(
            'posts_per_page' => (int) $settings['posts_per_page'],
            'category'       => '',
            'show_past'      => $settings['show_past'],
            'order'          => $settings['order'],
            'orderby'        => 'event_date',
            'show_time'      => $settings['show_time'],
            'show_excerpt'   => $settings['show_excerpt'],
            'show_location'  => $settings['show_location'],
            'show_footer'    => $settings['show_footer']
        ), $atts, 'sec_events');

        $sanitized_atts = $this->sanitize_attributes($atts);

        // Include plugin version + login state so cache is naturally invalidated
        // on upgrade, and admin-visible variations don't leak into anon output.
        $cache_key = 'simple_events_shortcode_' . md5(
            serialize($sanitized_atts) . '|' . SIMPLE_EVENTS_VERSION . '|' . (is_user_logged_in() ? '1' : '0')
        );
        $cached_result = get_transient($cache_key);

        if (!is_admin() && $cached_result !== false) {
            return $cached_result;
        }

        $output = $this->generate_output($sanitized_atts);

        if (!is_admin() && !empty($output) && strpos($output, 'simple-events-no-events') === false) {
            $ttl = max(1, (int) simple_events_get_setting('cache_ttl', 15));
            set_transient($cache_key, $output, $ttl * MINUTE_IN_SECONDS);
        }

        return $output;
    }

    /**
     * Sanitize shortcode attributes
     *
     * @param array $atts Raw attributes
     * @return array Sanitized attributes
     */
    private function sanitize_attributes($atts)
    {
        $posts_per_page = absint($atts['posts_per_page']);
        $posts_per_page = ($posts_per_page > 0 && $posts_per_page <= 50) ? $posts_per_page : 6;

        return array(
            'posts_per_page' => $posts_per_page,
            'category'       => sanitize_text_field($atts['category']),
            'show_past'      => ($atts['show_past'] === 'yes'),
            'order'          => in_array(strtoupper($atts['order']), ['ASC', 'DESC']) ? strtoupper($atts['order']) : 'ASC',
            'orderby'        => sanitize_text_field($atts['orderby']),
            'show_time'      => ($atts['show_time'] === 'yes'),
            'show_excerpt'   => ($atts['show_excerpt'] === 'yes'),
            'show_location'  => ($atts['show_location'] === 'yes'),
            'show_footer'    => ($atts['show_footer'] === 'yes')
        );
    }

    /**
     * Generate shortcode output
     *
     * @param array $atts Sanitized attributes
     * @return string HTML output
     */
    private function generate_output($atts)
    {
        $args = $this->build_query_args($atts);
        $the_query = new WP_Query($args);

        ob_start();

        if ($the_query->have_posts()) {
            $this->render_events_container($the_query, $atts);
        } else {
            $this->render_no_events_message($atts);
        }

        wp_reset_postdata();

        return ob_get_clean();
    }

    /**
     * Build WP_Query arguments
     *
     * @param array $atts Sanitized attributes
     * @return array Query arguments
     */
    private function build_query_args($atts)
    {
        $args = array(
            'post_type'         => 'simple-events',
            'post_status'       => 'publish',
            'posts_per_page'    => $atts['posts_per_page'],
            'orderby'           => 'meta_value',
            'order'             => $atts['order'],
            'meta_key'          => 'event_date',
            'meta_type'         => 'DATE',
            'no_found_rows'     => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
            'suppress_filters'  => false,
        );

        if (!$atts['show_past']) {
            $args['meta_query'] = array(
                'relation' => 'AND',
                array(
                    'key'       => 'event_date',
                    'compare'   => '>=',
                    'value'     => current_time('Ymd'),
                    'type'      => 'DATE'
                )
            );
        }

        if (!empty($atts['category'])) {
            $args['tax_query'] = array(
                array(
                    'taxonomy' => 'simple-events-cat',
                    'field'    => 'slug',
                    'terms'    => $atts['category'],
                ),
            );
        }

        return $args;
    }

    /**
     * Render events container
     *
     * @param WP_Query $query The query object
     * @param array $atts Sanitized attributes
     */
    private function render_events_container($query, $atts)
    {
        $data_attrs = sprintf(
            'data-show-time="%s" data-show-excerpt="%s" data-show-location="%s" data-show-footer="%s" data-show-past="%s" data-order="%s" data-category="%s" data-offset="%d"',
            $atts['show_time'] ? 'true' : 'false',
            $atts['show_excerpt'] ? 'true' : 'false',
            $atts['show_location'] ? 'true' : 'false',
            $atts['show_footer'] ? 'true' : 'false',
            $atts['show_past'] ? 'true' : 'false',
            esc_attr($atts['order']),
            esc_attr($atts['category']),
            (int) $query->post_count
        );

        echo '<div class="simple-events-calendar" data-shortcode="true" data-sec-loadmore="1" ' . $data_attrs . '>';

        while ($query->have_posts()) {
            $query->the_post();
            $this->render_event_card($atts);
        }

        echo '</div>';
    }

    /**
     * Render individual event card
     *
     * @param array $atts Display options
     */
    private function render_event_card($atts)
    {
        $post_data = array(
            'title'        => get_the_title(),
            'permalink'    => get_permalink(),
            'thumbnail'    => get_the_post_thumbnail_url(get_the_ID(), 'medium_large'),
            'excerpt'      => wp_trim_words(get_the_excerpt(), 30, '...'),
            'date'         => simple_events_get_event_date(get_the_ID()),
            'start_time'   => simple_events_get_event_time(get_the_ID(), 'event_start_time'),
            'end_time'     => simple_events_get_event_time(get_the_ID(), 'event_end_time'),
            'location'     => get_post_meta(get_the_ID(), 'event_location', true),
            'show_time'    => $atts['show_time'] ? 'yes' : 'no',
            'show_excerpt' => $atts['show_excerpt'] ? 'yes' : 'no',
            'show_location' => $atts['show_location'] ? 'yes' : 'no',
            'show_footer'  => $atts['show_footer'] ? 'yes' : 'no'
        );

        if (empty($post_data['title']) || empty($post_data['date'])) {
            return;
        }

        $template_path = SIMPLE_EVENTS_DIR . '/template-parts/content-event-card.php';
        if (file_exists($template_path)) {
            include $template_path;
        } else {
            $this->render_fallback_card($post_data, $atts);
        }
    }

    /**
     * Render fallback event card
     *
     * @param array $post_data Event data
     * @param array $atts Display options (unused; flags already on $post_data)
     */
    private function render_fallback_card($post_data, $atts)
    {
        simple_events_render_fallback_card($post_data);
    }

    /**
     * Render no events message
     *
     * @param array $atts Sanitized attributes
     */
    private function render_no_events_message($atts)
    {
        $heading = __('No Events Found', 'simply-events-calendar');

        echo '<div class="simple-events-calendar simple-events-no-events">';
        echo '<div class="simple-events-empty-state">';
        echo '<h3>' . esc_html($heading) . '</h3>';

        if (!empty($atts['category'])) {
            /* translators: %s: category slug */
            echo '<p>' . sprintf(esc_html__('No events found in the "%s" category.', 'simply-events-calendar'), esc_html($atts['category'])) . '</p>';
        } elseif (!$atts['show_past']) {
            echo '<p>' . esc_html__('No upcoming events scheduled. Check back soon!', 'simply-events-calendar') . '</p>';
        } else {
            echo '<p>' . esc_html__('No events have been created yet.', 'simply-events-calendar') . '</p>';
        }

        if (current_user_can('edit_posts')) {
            $admin_url = admin_url('post-new.php?post_type=simple-events');
            echo '<p><a href="' . esc_url($admin_url) . '" class="button">' . esc_html__('Add New Event', 'simply-events-calendar') . '</a></p>';
        }

        echo '</div>';
        echo '</div>';
    }

    /**
     * Clear shortcode cache
     *
     * @param int $post_id Post ID
     */
    public function clear_cache($post_id)
    {
        if (get_post_type($post_id) !== 'simple-events') {
            return;
        }

        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '_transient_simple_events_shortcode_%'));
    }

    /**
     * Clear cache on post status change
     *
     * @param string $new_status New post status
     * @param string $old_status Old post status
     * @param WP_Post $post Post object
     */
    public function clear_cache_on_status_change($new_status, $old_status, $post)
    {
        if ($post->post_type === 'simple-events' && $new_status !== $old_status) {
            $this->clear_cache($post->ID);
        }
    }

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
                return '<p class="simple-events-notice">' . esc_html__('[sec_event] requires an "id" attribute, e.g. [sec_event id="123"].', 'simply-events-calendar') . '</p>';
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
            if (current_user_can('edit_posts')) {
                return '<p class="simple-events-notice">' . sprintf(
                    /* translators: %d: event ID */
                    esc_html__('No published event found for ID %d. Check the "id" attribute — the event may be unpublished or deleted.', 'simply-events-calendar'),
                    $post_id
                ) . '</p>';
            }
            return '';
        }

        // Enqueued here during the_content when the shortcode actually renders;
        // WordPress prints late-enqueued styles in the footer, which is fine for
        // the card. Pages can also pre-enqueue it in <head> (see enqueue_scripts()).
        wp_enqueue_style('simple-events-style');
        return $html;
    }

    /**
     * Enqueue shortcode-specific scripts
     */
    public function enqueue_scripts()
    {
        global $post;
        if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'sec_events')) {
            wp_enqueue_style('simple-events-style');
            wp_enqueue_script(
                'simple-events-shortcode',
                SIMPLE_EVENTS_ASSETS . '/js/simple-events-shortcode.js',
                array('jquery'),
                SIMPLE_EVENTS_VERSION,
                true
            );

            wp_localize_script(
                'simple-events-shortcode',
                'simple_events_shortcode_params',
                array(
                    'ajaxurl' => admin_url('admin-ajax.php'),
                    'nonce'   => wp_create_nonce(SIMPLE_EVENTS_NONCE_ACTION),
                    'loading_text' => __('Loading more events...', 'simply-events-calendar'),
                    'error_text'   => __('Error loading events. Please try again.', 'simply-events-calendar'),
                    'no_more_text' => __('No more events to load.', 'simply-events-calendar')
                )
            );
        }

        // A page using only [sec_event] (single event) still needs the stylesheet
        // in <head>; it does not need the infinite-scroll script.
        if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'sec_event')) {
            wp_enqueue_style('simple-events-style');
        }
    }
}
