<?php

/**
 * Elementor display widgets for Simply Events Calendar.
 *
 * Standalone listing widgets (not gated): an Events Grid (grid or image-left
 * list) and a Single Event (chosen via a searchable picker). Both reuse the
 * shared render helpers so output matches the [sec_events] / [sec_event]
 * shortcodes and the default templates.
 *
 * Required only from Simple_Events_Elementor::register_widgets(), so the
 * Elementor base class is guaranteed to exist here.
 *
 * @package Simple_Events_Calendar
 * @since 5.1.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Events Grid widget — grid or list of events.
 */
class Simple_Events_Widget_Grid extends \Elementor\Widget_Base {

    /**
     * Cached category options (built once per request).
     *
     * @var array|null
     */
    private static $category_options_cache = null;

    public function get_name() { return 'sec-events-grid'; }
    public function get_title() { return __('Events Grid', 'simply-events-calendar'); }
    public function get_icon() { return 'eicon-gallery-grid'; }
    public function get_categories() { return array('simple-events'); }
    public function get_keywords() { return array('event', 'events', 'calendar', 'grid', 'list'); }
    public function get_style_depends() { return array('simple-events-style'); }
    public function get_script_depends() { return array('simple-events-script'); }

    protected function register_controls() {
        $this->start_controls_section('sec_grid_content', array(
            'label' => __('Events', 'simply-events-calendar'),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ));

        $this->add_control('layout', array(
            'label'        => __('Layout', 'simply-events-calendar'),
            'type'         => \Elementor\Controls_Manager::SELECT,
            'options'      => array(
                'grid' => __('Grid', 'simply-events-calendar'),
                'list' => __('List (image left)', 'simply-events-calendar'),
            ),
            'default'      => 'grid',
            // Re-render server-side on change so the PHP-set layout class on the
            // inner container updates live in the editor. (prefix_class alone only
            // updated the wrapper, leaving the preview stale until a refresh.)
            'render_type'  => 'template',
        ));

        // Non-responsive on purpose: this sets the desktop column count, and the
        // stylesheet stacks to 2 columns on tablet and 1 on mobile (matching the
        // default event grid). A responsive control would imply per-breakpoint
        // values the CSS deliberately overrides.
        $this->add_control('columns', array(
            'label'     => __('Columns', 'simply-events-calendar'),
            'type'      => \Elementor\Controls_Manager::NUMBER,
            'min'       => 1,
            'max'       => 6,
            'default'   => 3,
            'condition' => array('layout' => 'grid'),
            'selectors' => array(
                '{{WRAPPER}} .simple-events-calendar' => '--sec-columns: {{VALUE}};',
            ),
        ));

        $this->add_control('posts_per_page', array(
            'label'   => __('Number of events', 'simply-events-calendar'),
            'type'    => \Elementor\Controls_Manager::NUMBER,
            'min'     => 1,
            'max'     => 50,
            'default' => 6,
        ));

        $this->add_control('category', array(
            'label'   => __('Category', 'simply-events-calendar'),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'options' => self::category_options(),
            'default' => '',
        ));

        $this->add_control('order', array(
            'label'   => __('Order', 'simply-events-calendar'),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'options' => array(
                'ASC'  => __('Soonest first', 'simply-events-calendar'),
                'DESC' => __('Latest first', 'simply-events-calendar'),
            ),
            'default' => 'ASC',
        ));

        $this->add_control('show_past', array(
            'label'   => __('Show past events', 'simply-events-calendar'),
            'type'    => \Elementor\Controls_Manager::SWITCHER,
            'default' => '',
        ));

        foreach (array(
            'show_time'     => __('Show time', 'simply-events-calendar'),
            'show_excerpt'  => __('Show excerpt', 'simply-events-calendar'),
            'show_location' => __('Show location', 'simply-events-calendar'),
            'show_footer'   => __('Show footer / read-more', 'simply-events-calendar'),
        ) as $key => $label) {
            $this->add_control($key, array(
                'label'   => $label,
                'type'    => \Elementor\Controls_Manager::SWITCHER,
                'default' => 'yes',
            ));
        }

        $this->add_control('load_more', array(
            'label'       => __('Load more on scroll', 'simply-events-calendar'),
            'description' => __('Loads additional events automatically as the visitor scrolls (infinite scroll), starting from "Number of events". Off shows a fixed number.', 'simply-events-calendar'),
            'type'        => \Elementor\Controls_Manager::SWITCHER,
            'default'     => '',
            'separator'   => 'before',
        ));

        $this->end_controls_section();
    }

    /**
     * Build the category dropdown options (slug => name).
     *
     * @return array
     */
    private static function category_options() {
        if (null !== self::$category_options_cache) {
            return self::$category_options_cache;
        }

        $options = array('' => __('All categories', 'simply-events-calendar'));
        $terms = get_terms(array(
            'taxonomy'   => 'simple-events-cat',
            'hide_empty' => false,
        ));
        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                $options[$term->slug] = $term->name;
            }
        }

        self::$category_options_cache = $options;
        return $options;
    }

    protected function render() {
        $s = $this->get_settings_for_display();

        // The grid container needs the columns custom-property hook class.
        add_filter('simple_events_grid_extra_class', array($this, 'grid_extra_class'));
        $html = simple_events_render_events_grid(array(
            'posts_per_page' => isset($s['posts_per_page']) ? (int) $s['posts_per_page'] : 6,
            'category'       => isset($s['category']) ? $s['category'] : '',
            'show_past'      => 'yes' === ($s['show_past'] ?? ''),
            'order'          => $s['order'] ?? 'ASC',
            'layout'         => $s['layout'] ?? 'grid',
            'show_time'      => 'yes' === ($s['show_time'] ?? 'yes'),
            'show_excerpt'   => 'yes' === ($s['show_excerpt'] ?? 'yes'),
            'show_location'  => 'yes' === ($s['show_location'] ?? 'yes'),
            'show_footer'    => 'yes' === ($s['show_footer'] ?? 'yes'),
            'load_more'      => 'yes' === ($s['load_more'] ?? ''),
        ));
        remove_filter('simple_events_grid_extra_class', array($this, 'grid_extra_class'));

        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Add the columns-hook class to the grid container.
     *
     * @param string $class Extra container class.
     * @return string
     */
    public function grid_extra_class($class) {
        return trim($class . ' sec-grid-columns');
    }
}

/**
 * Single Event widget — render one chosen event as a card or list row.
 */
class Simple_Events_Widget_Single extends \Elementor\Widget_Base {

    public function get_name() { return 'sec-single-event'; }
    public function get_title() { return __('Single Event', 'simply-events-calendar'); }
    public function get_icon() { return 'eicon-single-post'; }
    public function get_categories() { return array('simple-events'); }
    public function get_keywords() { return array('event', 'single', 'calendar'); }
    public function get_style_depends() { return array('simple-events-style'); }

    protected function register_controls() {
        $this->start_controls_section('sec_single_content', array(
            'label' => __('Event', 'simply-events-calendar'),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ));

        $this->add_control('event_id', array(
            'label'       => __('Event', 'simply-events-calendar'),
            'description' => __('Search by title and select the event to display.', 'simply-events-calendar'),
            'type'        => \Elementor\Controls_Manager::SELECT2,
            'label_block' => true,
            'options'     => Simple_Events_Elementor::event_options(),
            'default'     => '',
        ));

        $this->add_control('layout', array(
            'label'        => __('Layout', 'simply-events-calendar'),
            'type'         => \Elementor\Controls_Manager::SELECT,
            'options'      => array(
                'card' => __('Card', 'simply-events-calendar'),
                'list' => __('List (image left)', 'simply-events-calendar'),
            ),
            'default'      => 'card',
            // Re-render server-side on change so the PHP-set layout class updates
            // live in the editor (matches the Events Grid layout control).
            'render_type'  => 'template',
        ));

        foreach (array(
            'show_time'     => __('Show time', 'simply-events-calendar'),
            'show_excerpt'  => __('Show excerpt', 'simply-events-calendar'),
            'show_location' => __('Show location', 'simply-events-calendar'),
            'show_footer'   => __('Show footer / read-more', 'simply-events-calendar'),
        ) as $key => $label) {
            $this->add_control($key, array(
                'label'   => $label,
                'type'    => \Elementor\Controls_Manager::SWITCHER,
                'default' => 'yes',
            ));
        }

        $this->end_controls_section();
    }

    protected function render() {
        $s = $this->get_settings_for_display();
        $event_id = isset($s['event_id']) ? absint($s['event_id']) : 0;

        if (!$event_id) {
            if (Simple_Events_Elementor::is_edit_hint_allowed()) {
                echo '<span class="sec-elementor-hint" style="display:block;padding:10px 12px;border:1px dashed #c3c4c7;border-radius:4px;color:#646970;font-size:12px;">'
                    . esc_html__('Select an event to display.', 'simply-events-calendar')
                    . '</span>';
            }
            return;
        }

        $flags = array(
            'show_time'     => 'yes' === ($s['show_time'] ?? 'yes'),
            'show_excerpt'  => 'yes' === ($s['show_excerpt'] ?? 'yes'),
            'show_location' => 'yes' === ($s['show_location'] ?? 'yes'),
            'show_footer'   => 'yes' === ($s['show_footer'] ?? 'yes'),
        );
        $layout = ('list' === ($s['layout'] ?? 'card')) ? 'list' : 'card';

        echo simple_events_render_single_event($event_id, $flags, $layout); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
}
