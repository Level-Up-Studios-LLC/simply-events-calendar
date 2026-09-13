<?php

/**
 * Shared element renderer for Simply Events Calendar
 *
 * Single source of truth for rendering individual pieces of an event (title,
 * image, date, time, location, excerpt, content, categories, button). Consumed
 * by the [sec_event_*] element shortcodes, the Elementor widgets, the event
 * card, and the default templates so output is identical everywhere.
 *
 * Also registers the [sec_event_*] element shortcodes.
 *
 * @package Simple_Events_Calendar
 * @since 5.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Simple_Events_Renderer class
 */
class Simple_Events_Renderer {

    /**
     * Constructor — register element shortcodes.
     */
    public function __construct() {
        add_shortcode('sec_event_title', array($this, 'shortcode_title'));
        add_shortcode('sec_event_image', array($this, 'shortcode_image'));
        add_shortcode('sec_event_date', array($this, 'shortcode_date'));
        add_shortcode('sec_event_time', array($this, 'shortcode_time'));
        add_shortcode('sec_event_location', array($this, 'shortcode_location'));
        add_shortcode('sec_event_excerpt', array($this, 'shortcode_excerpt'));
        add_shortcode('sec_event_content', array($this, 'shortcode_content'));
        add_shortcode('sec_event_categories', array($this, 'shortcode_categories'));
        add_shortcode('sec_event_button', array($this, 'shortcode_button'));
    }

    /* ---------------------------------------------------------------------
     * Render methods (return HTML strings). Used by shortcodes + Elementor.
     * ------------------------------------------------------------------- */

    /**
     * Build a CSS class attribute from a base class and an optional extra.
     *
     * @param string $base  Base class.
     * @param string $extra Extra class(es).
     * @return string
     */
    private static function class_attr($base, $extra = '') {
        $classes = trim($base . ' ' . (string) $extra);
        return ' class="' . esc_attr($classes) . '"';
    }

    /**
     * Render the event title.
     *
     * @param int   $post_id Event ID.
     * @param array $args    link (bool), tag (h1-h4|span), class.
     * @return string
     */
    public static function title($post_id, $args = array()) {
        $title = get_the_title($post_id);
        if ('' === trim((string) $title)) {
            return '';
        }

        $link = !empty($args['link']);
        $tag  = isset($args['tag']) ? preg_replace('/[^a-z0-9]/', '', strtolower((string) $args['tag'])) : 'h3';
        $allowed_tags = array('h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'span', 'div', 'p');
        if (!in_array($tag, $allowed_tags, true)) {
            $tag = 'h3';
        }

        $inner = esc_html($title);
        if ($link) {
            $inner = '<a href="' . esc_url(get_permalink($post_id)) . '">' . $inner . '</a>';
        }

        return '<' . $tag . self::class_attr('sec-event-title', isset($args['class']) ? $args['class'] : '') . '>' . $inner . '</' . $tag . '>';
    }

    /**
     * Render the featured image.
     *
     * @param int   $post_id Event ID.
     * @param array $args    size, link (bool), class.
     * @return string
     */
    public static function image($post_id, $args = array()) {
        if (!has_post_thumbnail($post_id)) {
            return '';
        }

        $size = isset($args['size']) && $args['size'] ? $args['size'] : 'medium_large';
        $img  = get_the_post_thumbnail($post_id, $size, array('loading' => 'lazy', 'decoding' => 'async'));
        if (!$img) {
            return '';
        }

        if (!empty($args['link'])) {
            $img = '<a href="' . esc_url(get_permalink($post_id)) . '">' . $img . '</a>';
        }

        return '<div' . self::class_attr('sec-event-image', isset($args['class']) ? $args['class'] : '') . '>' . $img . '</div>';
    }

    /**
     * Render the formatted event date.
     *
     * @param int   $post_id Event ID.
     * @param array $args    format (PHP date format override), class.
     * @return string
     */
    public static function date($post_id, $args = array()) {
        $format = isset($args['format']) ? (string) $args['format'] : '';
        $date   = simple_events_get_event_date($post_id, $format);
        if ('' === $date) {
            return '';
        }
        return '<span' . self::class_attr('sec-event-date', isset($args['class']) ? $args['class'] : '') . '>' . esc_html($date) . '</span>';
    }

    /**
     * Render the event start (and optional end) time.
     *
     * @param int   $post_id Event ID.
     * @param array $args    separator, class.
     * @return string
     */
    public static function time($post_id, $args = array()) {
        $start = simple_events_get_event_time($post_id, 'event_start_time');
        if ('' === $start) {
            return '';
        }

        $end       = simple_events_get_event_time($post_id, 'event_end_time');
        $separator = isset($args['separator']) ? (string) $args['separator'] : ' - ';

        $text = esc_html($start);
        if ('' !== $end) {
            $text .= esc_html($separator) . esc_html($end);
        }

        return '<span' . self::class_attr('sec-event-time', isset($args['class']) ? $args['class'] : '') . '>' . $text . '</span>';
    }

    /**
     * Render the event location.
     *
     * @param int   $post_id Event ID.
     * @param array $args    icon (bool), class.
     * @return string
     */
    public static function location($post_id, $args = array()) {
        $location = get_post_meta((int) $post_id, 'event_location', true);
        if (empty($location)) {
            return '';
        }

        $icon = '';
        if (!empty($args['icon'])) {
            $icon = '<svg class="sec-event-location-icon" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z" /></svg>';
        }

        return '<span' . self::class_attr('sec-event-location', isset($args['class']) ? $args['class'] : '') . '>' . $icon . esc_html($location) . '</span>';
    }

    /**
     * Render the excerpt.
     *
     * @param int   $post_id Event ID.
     * @param array $args    words (int), class.
     * @return string
     */
    public static function excerpt($post_id, $args = array()) {
        $excerpt = get_the_excerpt($post_id);
        if ('' === trim((string) $excerpt)) {
            return '';
        }

        $words = isset($args['words']) ? absint($args['words']) : 30;
        if ($words > 0) {
            // Literal ellipsis (not the &hellip; entity) so it survives esc_html().
            $excerpt = wp_trim_words($excerpt, $words, '…');
        }

        return '<div' . self::class_attr('sec-event-excerpt', isset($args['class']) ? $args['class'] : '') . '><p>' . esc_html($excerpt) . '</p></div>';
    }

    /**
     * Render the full content (passed through the_content filters).
     *
     * @param int   $post_id Event ID.
     * @param array $args    class.
     * @return string
     */
    public static function content($post_id, $args = array()) {
        $post = get_post($post_id);
        if (!$post) {
            return '';
        }

        $content = apply_filters('the_content', $post->post_content);
        if ('' === trim((string) $content)) {
            return '';
        }

        return '<div' . self::class_attr('sec-event-content', isset($args['class']) ? $args['class'] : '') . '>' . $content . '</div>';
    }

    /**
     * Render the event category terms.
     *
     * @param int   $post_id Event ID.
     * @param array $args    link (bool), separator, class.
     * @return string
     */
    public static function categories($post_id, $args = array()) {
        $terms = get_the_terms($post_id, 'simple-events-cat');
        if (empty($terms) || is_wp_error($terms)) {
            return '';
        }

        $link      = !empty($args['link']);
        $separator = isset($args['separator']) ? (string) $args['separator'] : ', ';

        $items = array();
        foreach ($terms as $term) {
            if ($link) {
                $url = get_term_link($term);
                if (!is_wp_error($url)) {
                    $items[] = '<a href="' . esc_url($url) . '">' . esc_html($term->name) . '</a>';
                    continue;
                }
            }
            $items[] = esc_html($term->name);
        }

        return '<span' . self::class_attr('sec-event-categories', isset($args['class']) ? $args['class'] : '') . '>' . implode(esc_html($separator), $items) . '</span>';
    }

    /**
     * Render a "view event" button / permalink.
     *
     * @param int   $post_id Event ID.
     * @param array $args    text, class.
     * @return string
     */
    public static function button($post_id, $args = array()) {
        $text = isset($args['text']) && '' !== $args['text'] ? (string) $args['text'] : __('View Event', 'simply-events-calendar');
        return '<a href="' . esc_url(get_permalink($post_id)) . '"' . self::class_attr('sec-event-button', isset($args['class']) ? $args['class'] : '') . '>' . esc_html($text) . '</a>';
    }

    /* ---------------------------------------------------------------------
     * Shortcode handlers.
     * ------------------------------------------------------------------- */

    /**
     * Common attribute parsing + before/after wrapping for element shortcodes.
     *
     * @param array    $atts     Raw shortcode atts.
     * @param array    $defaults Element-specific defaults (merged with common).
     * @param callable $render   Receives ($post_id, $args), returns HTML.
     * @param string   $tag      Shortcode tag (for shortcode_atts context).
     * @return string
     */
    private function build($atts, $defaults, $render, $tag) {
        $common = array(
            'id'     => 0,
            'class'  => '',
            'before' => '',
            'after'  => '',
        );
        $atts = shortcode_atts(array_merge($common, $defaults), $atts, $tag);

        $post_id = simple_events_resolve_event_id($atts['id']);
        if (!$post_id) {
            return '';
        }

        $html = call_user_func($render, $post_id, $atts);
        if ('' === $html) {
            return '';
        }

        return wp_kses_post($atts['before']) . $html . wp_kses_post($atts['after']);
    }

    /** @return string */
    public function shortcode_title($atts) {
        return $this->build($atts, array('link' => '', 'tag' => 'h3'), function ($id, $a) {
            return self::title($id, array(
                'link'  => self::truthy($a['link']),
                'tag'   => $a['tag'],
                'class' => $a['class'],
            ));
        }, 'sec_event_title');
    }

    /** @return string */
    public function shortcode_image($atts) {
        return $this->build($atts, array('size' => 'medium_large', 'link' => ''), function ($id, $a) {
            return self::image($id, array(
                'size'  => $a['size'],
                'link'  => self::truthy($a['link']),
                'class' => $a['class'],
            ));
        }, 'sec_event_image');
    }

    /** @return string */
    public function shortcode_date($atts) {
        return $this->build($atts, array('format' => ''), function ($id, $a) {
            return self::date($id, array('format' => $a['format'], 'class' => $a['class']));
        }, 'sec_event_date');
    }

    /** @return string */
    public function shortcode_time($atts) {
        return $this->build($atts, array('separator' => ' - '), function ($id, $a) {
            return self::time($id, array('separator' => $a['separator'], 'class' => $a['class']));
        }, 'sec_event_time');
    }

    /** @return string */
    public function shortcode_location($atts) {
        return $this->build($atts, array('icon' => ''), function ($id, $a) {
            return self::location($id, array('icon' => self::truthy($a['icon']), 'class' => $a['class']));
        }, 'sec_event_location');
    }

    /** @return string */
    public function shortcode_excerpt($atts) {
        return $this->build($atts, array('words' => 30), function ($id, $a) {
            return self::excerpt($id, array('words' => $a['words'], 'class' => $a['class']));
        }, 'sec_event_excerpt');
    }

    /** @return string */
    public function shortcode_content($atts) {
        return $this->build($atts, array(), function ($id, $a) {
            return self::content($id, array('class' => $a['class']));
        }, 'sec_event_content');
    }

    /** @return string */
    public function shortcode_categories($atts) {
        return $this->build($atts, array('link' => 'yes', 'separator' => ', '), function ($id, $a) {
            return self::categories($id, array(
                'link'      => self::truthy($a['link']),
                'separator' => $a['separator'],
                'class'     => $a['class'],
            ));
        }, 'sec_event_categories');
    }

    /** @return string */
    public function shortcode_button($atts) {
        return $this->build($atts, array('text' => ''), function ($id, $a) {
            return self::button($id, array('text' => $a['text'], 'class' => $a['class']));
        }, 'sec_event_button');
    }

    /**
     * Interpret a shortcode attribute as a boolean.
     *
     * @param mixed $value Attribute value.
     * @return bool
     */
    private static function truthy($value) {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower((string) $value), array('yes', 'true', '1'), true);
    }
}
