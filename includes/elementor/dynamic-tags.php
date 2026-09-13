<?php

/**
 * Elementor Dynamic Tags for Simply Events Calendar fields.
 *
 * Lets users bind native Elementor widgets (Heading, Text, Image, Button, …) to
 * event fields. Required only from Simple_Events_Elementor::register_tags(), so
 * the Elementor base classes are guaranteed to exist here.
 *
 * @package Simple_Events_Calendar
 * @since 5.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Base text dynamic tag.
 */
abstract class Simple_Events_Tag_Base extends \Elementor\Core\DynamicTags\Tag {

    /**
     * Group key.
     *
     * @return string
     */
    public function get_group() {
        return 'simple-events';
    }

    /**
     * Categories — plain text.
     *
     * @return array
     */
    public function get_categories() {
        return array(\Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY);
    }

    /**
     * Resolve the current event ID.
     *
     * @return int
     */
    protected function sec_event_id() {
        $id = (int) get_the_ID();
        if ($id) {
            return $id;
        }
        // Fall back to the queried object only when it is an actual post;
        // get_queried_object_id() can be a term/user ID on archive views.
        $queried = get_queried_object();
        return ($queried instanceof WP_Post) ? (int) $queried->ID : 0;
    }

    /**
     * Plain-text value for the tag.
     *
     * @param int $post_id Event ID.
     * @return string
     */
    abstract protected function sec_value($post_id);

    /**
     * Render the tag value.
     *
     * These tags declare the plain-text category, so strip any markup and
     * escape — keeps output consistent with widgets that expect text.
     */
    public function render() {
        $id = $this->sec_event_id();
        if (!$id) {
            return;
        }
        echo esc_html(wp_strip_all_tags((string) $this->sec_value($id)));
    }
}

/**
 * Event Date tag.
 */
class Simple_Events_Tag_Date extends Simple_Events_Tag_Base {
    public function get_name() { return 'sec-event-date'; }
    public function get_title() { return __('Event Date', 'simply-events-calendar'); }
    protected function sec_value($post_id) {
        return simple_events_get_event_date($post_id);
    }
}

/**
 * Event Time tag (start, optionally with end).
 */
class Simple_Events_Tag_Time extends Simple_Events_Tag_Base {
    public function get_name() { return 'sec-event-time'; }
    public function get_title() { return __('Event Time', 'simply-events-calendar'); }
    protected function sec_value($post_id) {
        $start = simple_events_get_event_time($post_id, 'event_start_time');
        if ('' === $start) {
            return '';
        }
        $end = simple_events_get_event_time($post_id, 'event_end_time');
        return ('' !== $end) ? $start . ' - ' . $end : $start;
    }
}

/**
 * Event Location tag.
 */
class Simple_Events_Tag_Location extends Simple_Events_Tag_Base {
    public function get_name() { return 'sec-event-location'; }
    public function get_title() { return __('Event Location', 'simply-events-calendar'); }
    protected function sec_value($post_id) {
        return (string) get_post_meta($post_id, 'event_location', true);
    }
}

/**
 * Event Title tag.
 */
class Simple_Events_Tag_Title extends Simple_Events_Tag_Base {
    public function get_name() { return 'sec-event-title'; }
    public function get_title() { return __('Event Title', 'simply-events-calendar'); }
    protected function sec_value($post_id) {
        return get_the_title($post_id);
    }
}

/**
 * Event Excerpt tag.
 */
class Simple_Events_Tag_Excerpt extends Simple_Events_Tag_Base {
    public function get_name() { return 'sec-event-excerpt'; }
    public function get_title() { return __('Event Excerpt', 'simply-events-calendar'); }
    protected function sec_value($post_id) {
        return get_the_excerpt($post_id);
    }
}

/**
 * Event Categories tag (comma-separated names).
 */
class Simple_Events_Tag_Categories extends Simple_Events_Tag_Base {
    public function get_name() { return 'sec-event-categories'; }
    public function get_title() { return __('Event Categories', 'simply-events-calendar'); }
    protected function sec_value($post_id) {
        $terms = get_the_terms($post_id, 'simple-events-cat');
        if (empty($terms) || is_wp_error($terms)) {
            return '';
        }
        return implode(', ', wp_list_pluck($terms, 'name'));
    }
}

/**
 * Event featured-image URL tag (URL category).
 */
class Simple_Events_Tag_Image_Url extends \Elementor\Core\DynamicTags\Data_Tag {
    public function get_name() { return 'sec-event-image-url'; }
    public function get_title() { return __('Event Image URL', 'simply-events-calendar'); }
    public function get_group() { return 'simple-events'; }
    public function get_categories() {
        return array(\Elementor\Modules\DynamicTags\Module::URL_CATEGORY);
    }
    protected function get_value(array $options = array()) {
        $id = (int) get_the_ID();
        if (!$id) {
            // Only fall back to the queried object when it is an actual post;
            // get_queried_object_id() can be a term/user ID on archive views.
            $queried = get_queried_object();
            $id = ($queried instanceof WP_Post) ? (int) $queried->ID : 0;
        }
        if (!$id) {
            return '';
        }
        $url = get_the_post_thumbnail_url($id, 'full');
        return $url ? $url : '';
    }
}
