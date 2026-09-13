<?php

/**
 * AJAX functionality class for Simply Events Calendar
 *
 * @package Simple_Events_Calendar
 * @since 3.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Simple Events AJAX class
 */
class Simple_Events_Ajax {

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
        add_action('wp_ajax_load_more_events', array($this, 'load_more_events'));
        add_action('wp_ajax_nopriv_load_more_events', array($this, 'load_more_events'));
    }

    /**
     * Handle AJAX request to load more events
     *
     * @return void
     */
    public function load_more_events() {
        if (!$this->verify_nonce()) {
            wp_send_json_error(
                array('message' => __('Security check failed.', 'simply-events-calendar')),
                403
            );
        }

        $request_data = $this->sanitize_request_data();
        if (!$request_data) {
            wp_send_json_error(
                array('message' => __('Invalid request data.', 'simply-events-calendar')),
                400
            );
        }

        $args  = $this->build_query_args($request_data);
        $query = new WP_Query($args);

        if (!$query->have_posts()) {
            wp_send_json_success(array(
                'html'     => '',
                'has_more' => false,
            ));
        }

        ob_start();
        $this->render_events($query, $request_data['display_options']);
        $html = ob_get_clean();
        wp_reset_postdata();

        $html = (string) $html;

        wp_send_json_success(array(
            'html'     => $html,
            'has_more' => trim($html) !== '',
        ));
    }

    /**
     * Verify AJAX nonce
     *
     * @return bool
     */
    private function verify_nonce() {
        if (!isset($_POST['nonce'])) {
            return false;
        }
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce']));
        return (bool) wp_verify_nonce($nonce, SIMPLE_EVENTS_NONCE_ACTION);
    }

    /**
     * Sanitize and validate request data
     *
     * @return array|false Sanitized data or false on failure
     */
    private function sanitize_request_data() {
        $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;

        if ($offset < 0 || $offset > 10000) {
            return false;
        }

        $display_options = array(
            'show_time'     => isset($_POST['show_time']) ? ($_POST['show_time'] === 'true') : true,
            'show_excerpt'  => isset($_POST['show_excerpt']) ? ($_POST['show_excerpt'] === 'true') : true,
            'show_location' => isset($_POST['show_location']) ? ($_POST['show_location'] === 'true') : true,
            'show_footer'   => isset($_POST['show_footer']) ? ($_POST['show_footer'] === 'true') : true
        );

        $order = isset($_POST['order']) ? strtoupper(sanitize_text_field(wp_unslash($_POST['order']))) : 'ASC';
        if (!in_array($order, array('ASC', 'DESC'), true)) {
            $order = 'ASC';
        }

        return array(
            'offset'          => $offset,
            'category'        => isset($_POST['category']) ? sanitize_text_field(wp_unslash($_POST['category'])) : '',
            'show_past'       => isset($_POST['show_past']) && $_POST['show_past'] === 'true',
            'order'           => $order,
            'display_options' => $display_options
        );
    }

    /**
     * Build query arguments for AJAX request
     *
     * @param array $request_data Sanitized request data
     * @return array Query arguments
     */
    private function build_query_args($request_data) {
        $per_page = max(1, (int) simple_events_get_setting('load_increment', 6));

        $args = array(
            'post_type'       => 'simple-events',
            'post_status'     => 'publish',
            'posts_per_page'  => $per_page,
            'offset'          => $request_data['offset'],
            'orderby'         => 'meta_value',
            'order'           => isset($request_data['order']) ? $request_data['order'] : 'ASC',
            'meta_key'        => 'event_date',
            'meta_type'       => 'DATE',
            'no_found_rows'   => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
            'suppress_filters' => false,
        );

        // Hide past events unless the originating listing asked to show them.
        if (empty($request_data['show_past'])) {
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

        // Preserve the category context of the originating listing.
        if (!empty($request_data['category'])) {
            $args['tax_query'] = array(
                array(
                    'taxonomy' => 'simple-events-cat',
                    'field'    => 'slug',
                    'terms'    => $request_data['category'],
                ),
            );
        }

        return $args;
    }

    /**
     * Render events for AJAX response
     *
     * @param WP_Query $query The query object
     * @param array $display_options Display options
     */
    private function render_events($query, $display_options) {
        while ($query->have_posts()) {
            $query->the_post();

            $post_data = $this->prepare_post_data($display_options);

            if (empty($post_data['title']) || empty($post_data['date'])) {
                continue;
            }

            $this->render_event_card($post_data);
        }
    }

    /**
     * Prepare post data for rendering
     *
     * @param array $display_options Display options
     * @return array Post data
     */
    private function prepare_post_data($display_options) {
        $id = get_the_ID();
        return array(
            'title'      => get_the_title(),
            'permalink'  => get_permalink(),
            'thumbnail'  => get_the_post_thumbnail_url($id, 'medium_large'),
            'excerpt'    => wp_trim_words(get_the_excerpt(), 30, '...'),
            'date'       => simple_events_get_event_date($id),
            'start_time' => simple_events_get_event_time($id, 'event_start_time'),
            'end_time'   => simple_events_get_event_time($id, 'event_end_time'),
            'location'   => get_post_meta($id, 'event_location', true),
            // The card template gates details on the string 'yes' (matching the
            // shortcode/archive producers); $display_options are booleans, so
            // convert here — otherwise AJAX-loaded cards lose time/excerpt/footer.
            'show_time'     => !empty($display_options['show_time']) ? 'yes' : 'no',
            'show_excerpt'  => !empty($display_options['show_excerpt']) ? 'yes' : 'no',
            'show_location' => !empty($display_options['show_location']) ? 'yes' : 'no',
            'show_footer'   => !empty($display_options['show_footer']) ? 'yes' : 'no'
        );
    }

    /**
     * Render individual event card
     *
     * @param array $post_data Event data
     */
    private function render_event_card($post_data) {
        $template_path = SIMPLE_EVENTS_DIR . '/template-parts/content-event-card.php';
        if (file_exists($template_path)) {
            include $template_path;
        } else {
            $this->render_fallback_card($post_data);
        }
    }

    /**
     * Render fallback event card if template is missing
     *
     * @param array $post_data Event data
     */
    private function render_fallback_card($post_data) {
        simple_events_render_fallback_card($post_data);
    }

    /**
     * Handle AJAX errors gracefully
     *
     * @param string $message Error message
     * @param int $code Error code
     */
    private function handle_error($message, $code = 500) {
        if (defined('DOING_AJAX') && DOING_AJAX) {
            error_log('Simply Events Calendar AJAX Error: ' . $message);
            wp_die($message, 'Loading Error', array('response' => $code));
        }
    }

    /**
     * Get AJAX URL for frontend use
     *
     * @return string AJAX URL
     */
    public static function get_ajax_url() {
        return admin_url('admin-ajax.php');
    }

    /**
     * Get nonce for AJAX requests
     *
     * @return string Nonce
     */
    public static function get_nonce() {
        return wp_create_nonce(SIMPLE_EVENTS_NONCE_ACTION);
    }

    /**
     * Get AJAX parameters for frontend scripts
     *
     * @return array AJAX parameters
     */
    public static function get_ajax_params() {
        $increment = max(1, (int) simple_events_get_setting('load_increment', 6));
        return array(
            'ajaxurl' => self::get_ajax_url(),
            'nonce'   => self::get_nonce(),
            'initial_offset' => $increment,
            'load_increment' => $increment,
            'loading_text' => __('Loading more events...', 'simply-events-calendar'),
            'error_text'   => __('Error loading events. Please try again.', 'simply-events-calendar'),
            'no_more_text' => __('No more events to load.', 'simply-events-calendar')
        );
    }
}