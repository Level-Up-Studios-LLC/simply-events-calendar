<?php

/**
 * Elementor integration loader for Simply Events Calendar
 *
 * Registers a "Simple Events" widget category, one drag-and-drop widget per
 * event element, and Dynamic Tags for binding native Elementor widgets to
 * event fields. Everything is gated on Elementor being active, so Elementor is
 * an optional enhancement, never a dependency.
 *
 * This file is safe to require unconditionally: it references no Elementor
 * classes at load time. The widget and tag class files (which extend Elementor
 * base classes) are required only from inside Elementor's own hooks, by which
 * point the base classes exist.
 *
 * @package Simple_Events_Calendar
 * @since 5.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Simple_Events_Elementor class
 */
class Simple_Events_Elementor {

    /**
     * Cached event-options list (built once per request).
     *
     * @var array|null
     */
    private static $event_options_cache = null;

    /**
     * Cached sample event ID for editor previews (built once per request).
     *
     * @var int|null
     */
    private static $sample_event_cache = null;

    /**
     * Wire Elementor hooks if Elementor is present.
     */
    public static function init() {
        // 'elementor/loaded' fires when Elementor's core has loaded; if it has
        // not fired yet we still register the listeners below, which only run
        // when Elementor invokes them.
        add_action('elementor/elements/categories_registered', array(__CLASS__, 'register_category'));
        add_action('elementor/widgets/register', array(__CLASS__, 'register_widgets'));
        add_action('elementor/dynamic_tags/register', array(__CLASS__, 'register_tags'));

        // Loop Grid query presets — set the Loop Grid's "Query ID" to one of
        // these to order results by the event date (event_date meta) instead of
        // the post date. `sec_events_by_date` follows the global "Show past
        // events" setting; the `_all` variant always includes past events. The
        // ASC/DESC direction follows the widget's own Order control.
        add_action('elementor/query/sec_events_by_date', array(__CLASS__, 'query_events_by_date'));
        add_action('elementor/query/sec_events_by_date_all', array(__CLASS__, 'query_events_by_date_all'));
    }

    /**
     * Loop Grid query: order by event date, honoring the global "Show past
     * events" setting (Events → Settings) — the single source of truth for past
     * visibility across the plugin.
     *
     * @param WP_Query $query Elementor's loop query.
     */
    public static function query_events_by_date($query) {
        $show_past = ('yes' === (string) simple_events_get_setting('show_past', 'no'));
        self::apply_event_date_order($query, $show_past);
    }

    /**
     * Loop Grid query: order by event date, including past events.
     *
     * @param WP_Query $query Elementor's loop query.
     */
    public static function query_events_by_date_all($query) {
        self::apply_event_date_order($query, true);
    }

    /**
     * Apply event-date ordering (and optional upcoming-only filter) to a loop
     * query. `event_date` is stored as Ymd, so it is sorted as a DATE. The
     * ASC/DESC direction is left to the widget's Order control when set.
     *
     * @param WP_Query $query        Elementor's loop query.
     * @param bool     $include_past Whether to keep past events.
     */
    private static function apply_event_date_order($query, $include_past) {
        if (!($query instanceof WP_Query)) {
            return;
        }

        $query->set('meta_key', 'event_date');
        $query->set('orderby', 'meta_value');
        $query->set('meta_type', 'DATE');
        if (!$query->get('order')) {
            $query->set('order', 'ASC');
        }

        if ($include_past) {
            return;
        }

        $date_clause = array(
            'key'     => 'event_date',
            'compare' => '>=',
            'value'   => current_time('Ymd'),
            'type'    => 'DATE',
        );

        // Nest under AND so any existing meta_query (e.g. from the widget) is
        // preserved rather than overwritten.
        $existing = $query->get('meta_query');
        if (!empty($existing) && is_array($existing)) {
            $query->set('meta_query', array('relation' => 'AND', $existing, $date_clause));
        } else {
            $query->set('meta_query', array($date_clause));
        }
    }

    /**
     * Register the "Simple Events" widget category and move it just below the
     * "Basic" category so users don't have to scroll to the event widgets.
     *
     * @param \Elementor\Elements_Manager $elements_manager Elementor manager.
     */
    public static function register_category($elements_manager) {
        $elements_manager->add_category(
            'simple-events',
            array(
                'title' => __('Simple Events', 'simply-events-calendar'),
                'icon'  => 'eicon-calendar',
            )
        );

        self::move_category_after($elements_manager, 'simple-events', 'basic');
    }

    /**
     * Reorder the registered categories so $key sits immediately after $after.
     *
     * Elementor has no public position API, so this reorders the manager's
     * private categories array via reflection. It is best-effort and fully
     * guarded: if Elementor's internals ever change, it silently no-ops rather
     * than breaking the editor. Reordering only moves keys — values (titles,
     * icons) are untouched. When $after is missing, $key is placed first.
     *
     * @param \Elementor\Elements_Manager $elements_manager Elementor manager.
     * @param string                      $key              Category key to move.
     * @param string                      $after            Category key to place it after.
     */
    private static function move_category_after($elements_manager, $key, $after) {
        try {
            if (!method_exists($elements_manager, 'get_categories')) {
                return;
            }

            $categories = $elements_manager->get_categories();
            if (!is_array($categories) || !isset($categories[$key])) {
                return;
            }

            $entry = $categories[$key];
            unset($categories[$key]);

            $reordered = array();
            $inserted  = false;
            foreach ($categories as $cat_key => $cat_value) {
                $reordered[$cat_key] = $cat_value;
                if ($cat_key === $after) {
                    $reordered[$key] = $entry;
                    $inserted        = true;
                }
            }
            if (!$inserted) {
                // $after not present — fall back to placing it first.
                $reordered = array($key => $entry) + $reordered;
            }

            $ref = new ReflectionObject($elements_manager);
            if (!$ref->hasProperty('categories')) {
                return;
            }

            $prop = $ref->getProperty('categories');
            $prop->setAccessible(true);
            $prop->setValue($elements_manager, $reordered);
        } catch (\Throwable $e) {
            // Best-effort ordering only — never break the editor over panel order.
            unset($e);
        }
    }

    /**
     * Register the element widgets.
     *
     * @param \Elementor\Widgets_Manager $widgets_manager Elementor manager.
     */
    public static function register_widgets($widgets_manager) {
        require_once __DIR__ . '/widgets.php';
        require_once __DIR__ . '/display-widgets.php';

        $widgets_manager->register(new Simple_Events_Widget_Title());
        $widgets_manager->register(new Simple_Events_Widget_Image());
        $widgets_manager->register(new Simple_Events_Widget_Date());
        $widgets_manager->register(new Simple_Events_Widget_Time());
        $widgets_manager->register(new Simple_Events_Widget_Location());
        $widgets_manager->register(new Simple_Events_Widget_Excerpt());
        $widgets_manager->register(new Simple_Events_Widget_Content());
        $widgets_manager->register(new Simple_Events_Widget_Categories());
        $widgets_manager->register(new Simple_Events_Widget_Button());
        $widgets_manager->register(new Simple_Events_Widget_Grid());
        $widgets_manager->register(new Simple_Events_Widget_Single());
    }

    /**
     * Register the Dynamic Tags.
     *
     * @param \Elementor\Core\DynamicTags\Manager $dynamic_tags Tags manager.
     */
    public static function register_tags($dynamic_tags) {
        require_once __DIR__ . '/dynamic-tags.php';

        // Ensure our group exists.
        $dynamic_tags->register_group(
            'simple-events',
            array('title' => __('Simple Events', 'simply-events-calendar'))
        );

        $dynamic_tags->register(new Simple_Events_Tag_Date());
        $dynamic_tags->register(new Simple_Events_Tag_Time());
        $dynamic_tags->register(new Simple_Events_Tag_Location());
        $dynamic_tags->register(new Simple_Events_Tag_Title());
        $dynamic_tags->register(new Simple_Events_Tag_Excerpt());
        $dynamic_tags->register(new Simple_Events_Tag_Categories());
        $dynamic_tags->register(new Simple_Events_Tag_Image_Url());
    }

    /**
     * Resolve the event ID to render for an element widget. Loop/queried event
     * only — these widgets are gated to event contexts and have no picker.
     *
     * @return int
     */
    public static function resolve_event_id() {
        $id = (int) get_the_ID();
        if ($id && 'simple-events' === get_post_type($id)) {
            return $id;
        }
        // get_queried_object_id() can be a term/user ID on non-singular views,
        // so only trust it when the queried object is an actual post — otherwise
        // a term ID could collide with an unrelated simple-events post ID.
        $queried = get_queried_object();
        if ($queried instanceof WP_Post && 'simple-events' === $queried->post_type) {
            return (int) $queried->ID;
        }
        return 0;
    }

    /**
     * Public accessor: whether to show editor-only hints (edit/preview mode).
     *
     * @return bool
     */
    public static function is_edit_hint_allowed() {
        return self::is_elementor_edit_mode();
    }

    /**
     * Whether Elementor is currently in editor or preview mode.
     *
     * @return bool
     */
    private static function is_elementor_edit_mode() {
        if (!class_exists('\Elementor\Plugin')) {
            return false;
        }
        $instance = \Elementor\Plugin::$instance;
        if (!$instance) {
            return false;
        }
        if (isset($instance->editor) && method_exists($instance->editor, 'is_edit_mode') && $instance->editor->is_edit_mode()) {
            return true;
        }
        if (isset($instance->preview) && method_exists($instance->preview, 'is_preview_mode') && $instance->preview->is_preview_mode()) {
            return true;
        }
        return false;
    }

    /**
     * Options list of recent events for the editor preview pickers.
     *
     * @return array id => title
     */
    public static function event_options() {
        if (null !== self::$event_options_cache) {
            return self::$event_options_cache;
        }

        $options = array('' => __('— Select an event —', 'simply-events-calendar'));
        $events = get_posts(array(
            'post_type'        => 'simple-events',
            'post_status'      => 'publish',
            'numberposts'      => 50,
            'orderby'          => 'title',
            'order'            => 'ASC',
            'suppress_filters' => false,
        ));
        foreach ($events as $event) {
            $options[$event->ID] = $event->post_title;
        }

        self::$event_options_cache = $options;
        return $options;
    }

    /**
     * A representative published event ID, used to preview the element widgets
     * in the Elementor editor when they are placed outside an event context.
     * Returns 0 when no published events exist.
     *
     * @return int
     */
    public static function sample_event_id() {
        if (null !== self::$sample_event_cache) {
            return self::$sample_event_cache;
        }

        $events = get_posts(array(
            'post_type'        => 'simple-events',
            'post_status'      => 'publish',
            'numberposts'      => 1,
            'orderby'          => 'date',
            'order'            => 'DESC',
            'fields'           => 'ids',
            'suppress_filters' => false,
        ));

        self::$sample_event_cache = !empty($events) ? (int) $events[0] : 0;
        return self::$sample_event_cache;
    }
}
