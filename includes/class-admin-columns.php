<?php

/**
 * Admin columns functionality class for Simply Events Calendar
 *
 * @package Simple_Events_Calendar
 * @since 3.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Simple Events Admin Columns class
 */
class Simple_Events_Admin_Columns {

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
        add_filter('manage_simple-events_posts_columns', array($this, 'register_columns'));
        add_action('manage_simple-events_posts_custom_column', array($this, 'fill_columns'), 10, 2);
        add_filter('manage_edit-simple-events_sortable_columns', array($this, 'sortable_columns'));
        add_action('pre_get_posts', array($this, 'handle_column_sorting'));
        add_action('restrict_manage_posts', array($this, 'add_filter_dropdowns'));
        add_action('parse_query', array($this, 'handle_status_filter'));
        add_action('admin_head', array($this, 'add_column_styles'));
        add_action('quick_edit_custom_box', array($this, 'add_quick_edit_fields'), 10, 2);
    }

    /**
     * Register custom columns for the events admin list
     *
     * @param array $columns Existing columns
     * @return array Modified columns
     */
    public function register_columns($columns) {
        return array(
            'cb' => isset($columns['cb']) ? $columns['cb'] : '<input type="checkbox" />',
            'title' => __('Event Title', 'simply-events-calendar'),
            'event_thumbnail' => _x('Image', 'admin list table column heading', 'simply-events-calendar'),
            'event_date' => __('Event Date', 'simply-events-calendar'),
            'event_time' => _x('Time', 'admin list table column heading', 'simply-events-calendar'),
            'event_location' => _x('Location', 'admin list table column heading', 'simply-events-calendar'),
            'taxonomy-simple-events-cat' => _x('Categories', 'admin list table column heading', 'simply-events-calendar'),
            'event_series' => __('Series', 'simply-events-calendar'),
            'date' => isset($columns['date']) ? $columns['date'] : __('Published', 'simply-events-calendar')
        );
    }

    /**
     * Fill custom columns with content
     *
     * @param string $column Column name
     * @param int $post_id Post ID
     */
    public function fill_columns($column, $post_id) {
        static $processed_columns = array();

        $column_key = $column . '_' . $post_id;
        if (isset($processed_columns[$column_key])) {
            return;
        }
        $processed_columns[$column_key] = true;

        switch ($column) {
            case 'event_thumbnail':
                $this->render_thumbnail_column($post_id);
                break;

            case 'event_date':
                $this->render_date_column($post_id);
                break;

            case 'event_time':
                $this->render_time_column($post_id);
                break;

            case 'event_location':
                $this->render_location_column($post_id);
                break;

            case 'taxonomy-simple-events-cat':
                $this->render_categories_column($post_id);
                break;

            case 'event_series':
                $this->render_series_column($post_id);
                break;
        }
    }

    /**
     * Render series column: identifies parents vs child occurrences.
     *
     * @param int $post_id Post ID
     */
    private function render_series_column($post_id) {
        if (!class_exists('Simple_Events_Recurrence')) {
            echo '<span class="simple-events-missing-data">—</span>';
            return;
        }

        $parent_id = (int) get_post_meta($post_id, Simple_Events_Recurrence::META_PARENT, true);
        if ($parent_id) {
            $index      = (int) get_post_meta($post_id, Simple_Events_Recurrence::META_INDEX, true);
            $parent_url = get_edit_post_link($parent_id);

            echo '<span class="simple-events-series simple-events-series-child">';
            printf(
                /* translators: %d is the occurrence number within the series */
                esc_html__('Occurrence #%d', 'simply-events-calendar'),
                $index
            );
            if ($parent_url) {
                echo ' (<a href="' . esc_url($parent_url) . '">' . esc_html__('parent', 'simply-events-calendar') . '</a>)';
            }
            echo '</span>';
            return;
        }

        $freq = (string) get_post_meta($post_id, Simple_Events_Recurrence::META_RULE_FREQ, true);
        if (!$freq) {
            echo '<span class="simple-events-missing-data">—</span>';
            return;
        }

        // Cached count maintained by Simple_Events_Recurrence::recount_children()
        // after every series mutation, so the list table doesn't run an N+1
        // query for every parent row.
        $children_count = (int) get_post_meta($post_id, Simple_Events_Recurrence::META_CHILD_COUNT, true);

        $labels = array(
            'daily'   => __('Daily', 'simply-events-calendar'),
            'weekly'  => __('Weekly', 'simply-events-calendar'),
            'monthly' => __('Monthly', 'simply-events-calendar'),
            'yearly'  => __('Yearly', 'simply-events-calendar'),
        );
        $label = isset($labels[$freq]) ? $labels[$freq] : ucfirst($freq);

        echo '<span class="simple-events-series simple-events-series-parent">';
        printf(
            /* translators: 1: frequency label (Daily/Weekly/etc), 2: number of child occurrences */
            esc_html__('%1$s series (+%2$d)', 'simply-events-calendar'),
            esc_html($label),
            (int) $children_count
        );
        echo '</span>';
    }

    /**
     * Render thumbnail column
     *
     * @param int $post_id Post ID
     */
    private function render_thumbnail_column($post_id) {
        $thumbnail = get_the_post_thumbnail($post_id, array(60, 60));
        if ($thumbnail) {
            echo '<div class="simple-events-admin-thumbnail">' . $thumbnail . '</div>';
        } else {
            echo '<div class="simple-events-admin-no-thumbnail">—</div>';
        }
    }

    /**
     * Render date column
     *
     * @param int $post_id Post ID
     */
    private function render_date_column($post_id) {
        $raw = get_post_meta($post_id, 'event_date', true);

        // Resolve a valid ISO date; treat unparseable values as missing so we
        // never emit 1970-01-01 (which would mislabel the event as Past).
        $iso_date = '';
        if ($raw) {
            $dt = (8 === strlen((string) $raw))
                ? DateTimeImmutable::createFromFormat('!Ymd', (string) $raw, wp_timezone())
                : false;
            if ($dt) {
                $iso_date = $dt->format('Y-m-d');
            } else {
                $timestamp = strtotime((string) $raw);
                if ($timestamp) {
                    $iso_date = wp_date('Y-m-d', $timestamp);
                }
            }
        }

        if ('' !== $iso_date) {
            $formatted_date = simple_events_get_event_date($post_id);

            echo '<time datetime="' . esc_attr($iso_date) . '">' . esc_html($formatted_date) . '</time>';

            $status_indicator = $this->get_date_status_indicator($iso_date);
            if ($status_indicator) {
                echo ' ' . $status_indicator;
            }
        } else {
            echo '<span class="simple-events-missing-data">—</span>';
        }
    }

    /**
     * Get date status indicator
     *
     * @param string $iso_date ISO formatted date
     * @return string Status indicator HTML
     */
    private function get_date_status_indicator($iso_date) {
        $today = date('Y-m-d');

        if ($iso_date < $today) {
            return '<span class="simple-events-status simple-events-past">(' . __('Past', 'simply-events-calendar') . ')</span>';
        } elseif ($iso_date === $today) {
            return '<span class="simple-events-status simple-events-today">(' . __('Today', 'simply-events-calendar') . ')</span>';
        } else {
            return '<span class="simple-events-status simple-events-upcoming">(' . __('Upcoming', 'simply-events-calendar') . ')</span>';
        }
    }

    /**
     * Render time column
     *
     * @param int $post_id Post ID
     */
    private function render_time_column($post_id) {
        $start_time = simple_events_get_event_time($post_id, 'event_start_time');
        $end_time = simple_events_get_event_time($post_id, 'event_end_time');

        if ($start_time) {
            echo '<div class="simple-events-time-display">';
            echo '<span class="simple-events-start-time">' . esc_html($start_time) . '</span>';

            if ($end_time) {
                echo '<span class="simple-events-time-separator"> - </span>';
                echo '<span class="simple-events-end-time">' . esc_html($end_time) . '</span>';
            }
            echo '</div>';
        } else {
            echo '<span class="simple-events-missing-data">—</span>';
        }
    }

    /**
     * Render location column
     *
     * @param int $post_id Post ID
     */
    private function render_location_column($post_id) {
        $location = get_post_meta($post_id, 'event_location', true);
        if ($location) {
            echo '<div class="simple-events-location" title="' . esc_attr($location) . '">' . esc_html($location) . '</div>';
        } else {
            echo '<span class="simple-events-missing-data">—</span>';
        }
    }

    /**
     * Render categories column
     *
     * @param int $post_id Post ID
     */
    private function render_categories_column($post_id) {
        $terms = get_the_terms($post_id, 'simple-events-cat');
        if ($terms && !is_wp_error($terms)) {
            $term_links = array();
            foreach ($terms as $term) {
                $term_links[] = '<a href="' . esc_url(add_query_arg(array('simple-events-cat' => $term->slug), admin_url('edit.php?post_type=simple-events'))) . '">' . esc_html($term->name) . '</a>';
            }
            echo implode(', ', $term_links);
        } else {
            echo '<span class="simple-events-missing-data">—</span>';
        }
    }

    /**
     * Make columns sortable
     *
     * @param array $columns Sortable columns
     * @return array Modified sortable columns
     */
    public function sortable_columns($columns) {
        $columns['event_date'] = 'event_date';
        $columns['event_time'] = 'event_start_time';
        $columns['event_location'] = 'event_location';

        return $columns;
    }

    /**
     * Handle sorting for custom columns
     *
     * @param WP_Query $query Current query
     */
    public function handle_column_sorting($query) {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        $orderby = $query->get('orderby');

        switch ($orderby) {
            case 'event_date':
                $query->set('meta_key', 'event_date');
                $query->set('orderby', 'meta_value');
                $query->set('meta_type', 'DATE');
                break;

            case 'event_start_time':
                $query->set('meta_key', 'event_start_time');
                $query->set('orderby', 'meta_value');
                break;

            case 'event_location':
                $query->set('meta_key', 'event_location');
                $query->set('orderby', 'meta_value');
                break;
        }
    }

    /**
     * Add filter dropdowns to admin list
     */
    public function add_filter_dropdowns() {
        global $typenow;

        if ($typenow === 'simple-events') {
            $this->render_category_filter();
            $this->render_status_filter();
        }
    }

    /**
     * Render category filter dropdown
     */
    private function render_category_filter() {
        $taxonomy = 'simple-events-cat';
        $selected_cat = isset($_GET[$taxonomy])
            ? sanitize_text_field(wp_unslash($_GET[$taxonomy]))
            : '';

        $terms = get_terms(array(
            'taxonomy' => $taxonomy,
            'hide_empty' => true
        ));

        if (!empty($terms) && !is_wp_error($terms)) {
            printf(
                '<select name="%1$s" id="%1$s" class="postform">',
                esc_attr($taxonomy)
            );
            echo '<option value="">' . esc_html__('All Categories', 'simply-events-calendar') . '</option>';

            foreach ($terms as $term) {
                printf(
                    '<option value="%s"%s>%s (%d)</option>',
                    esc_attr($term->slug),
                    selected($selected_cat, $term->slug, false),
                    esc_html($term->name),
                    (int) $term->count
                );
            }

            echo '</select>';
        }
    }

    /**
     * Render status filter dropdown
     */
    private function render_status_filter() {
        $selected_status = isset($_GET['event_status'])
            ? sanitize_text_field(wp_unslash($_GET['event_status']))
            : '';

        $options = array(
            ''         => __('All Events', 'simply-events-calendar'),
            'upcoming' => __('Upcoming Events', 'simply-events-calendar'),
            'today'    => __('Today\'s Events', 'simply-events-calendar'),
            'past'     => __('Past Events', 'simply-events-calendar'),
        );

        echo '<select name="event_status" id="event_status" class="postform">';
        foreach ($options as $value => $label) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($value),
                selected($selected_status, $value, false),
                esc_html($label)
            );
        }
        echo '</select>';
    }

    /**
     * Handle status filter
     *
     * @param WP_Query $query Current query
     */
    public function handle_status_filter($query) {
        global $pagenow, $typenow;

        if ($pagenow !== 'edit.php' || $typenow !== 'simple-events' || empty($_GET['event_status'])) {
            return;
        }

        $status = sanitize_text_field(wp_unslash($_GET['event_status']));
        if (!in_array($status, array('upcoming', 'today', 'past'), true)) {
            return;
        }

        $meta_query = $this->get_status_meta_query($status, date('Ymd'));

        if (!empty($meta_query)) {
            $query->set('meta_query', $meta_query);
            $query->set('meta_key', 'event_date');
            $query->set('orderby', 'meta_value');
            $query->set('meta_type', 'DATE');
            $query->set('order', $status === 'past' ? 'DESC' : 'ASC');
        }
    }

    /**
     * Get meta query for status filter
     *
     * @param string $status Filter status
     * @param string $today Today's date
     * @return array Meta query
     */
    private function get_status_meta_query($status, $today) {
        switch ($status) {
            case 'upcoming':
                return array(
                    array(
                        'key' => 'event_date',
                        'value' => $today,
                        'compare' => '>',
                        'type' => 'DATE'
                    )
                );

            case 'today':
                return array(
                    array(
                        'key' => 'event_date',
                        'value' => $today,
                        'compare' => '=',
                        'type' => 'DATE'
                    )
                );

            case 'past':
                return array(
                    array(
                        'key' => 'event_date',
                        'value' => $today,
                        'compare' => '<',
                        'type' => 'DATE'
                    )
                );

            default:
                return array();
        }
    }

    /**
     * Add custom CSS for admin columns
     */
    public function add_column_styles() {
        global $typenow;

        if ($typenow === 'simple-events') {
            ?>
            <style>
                .wp-list-table .column-event_thumbnail {
                    width: 80px;
                    text-align: center;
                }

                .wp-list-table .column-event_date {
                    width: 140px;
                }

                .wp-list-table .column-event_time {
                    width: 120px;
                }

                .wp-list-table .column-event_location {
                    width: 150px;
                }

                .wp-list-table .column-event_series {
                    width: 160px;
                }

                .simple-events-series-parent {
                    color: #2271b1;
                    font-weight: 500;
                }

                .simple-events-series-child {
                    color: #50575e;
                    font-size: 12px;
                }

                .simple-events-admin-thumbnail img {
                    border-radius: 4px;
                    display: block;
                    margin: 0 auto;
                }

                .simple-events-admin-no-thumbnail {
                    color: #666;
                    font-style: italic;
                }

                .simple-events-missing-data {
                    color: #999;
                }

                .simple-events-status {
                    font-size: 11px;
                    font-weight: bold;
                }

                .simple-events-past {
                    color: #999;
                }

                .simple-events-today {
                    color: #d63384;
                }

                .simple-events-upcoming {
                    color: #198754;
                }

                .simple-events-time-display {
                    white-space: nowrap;
                }

                .simple-events-time-separator {
                    color: #666;
                }

                .simple-events-location {
                    max-width: 150px;
                    overflow: hidden;
                    text-overflow: ellipsis;
                    white-space: nowrap;
                }
            </style>
            <?php
        }
    }

    /**
     * Add quick edit fields
     *
     * @param string $column_name Column name
     * @param string $post_type Post type
     */
    public function add_quick_edit_fields($column_name, $post_type) {
        if ($post_type !== 'simple-events') {
            return;
        }

        switch ($column_name) {
            case 'event_date':
                ?>
                <fieldset class="inline-edit-col-left">
                    <div class="inline-edit-col">
                        <label>
                            <span class="title"><?php _e('Event Date', 'simply-events-calendar'); ?></span>
                            <input type="date" name="event_date" value="" />
                        </label>
                    </div>
                </fieldset>
                <?php
                break;
        }
    }

    /**
     * Get column configuration
     *
     * @return array Column configuration
     */
    public static function get_column_config() {
        return array(
            'event_thumbnail' => array(
                'label' => _x('Image', 'admin list table column heading', 'simply-events-calendar'),
                'width' => '80px',
                'sortable' => false
            ),
            'event_date' => array(
                'label' => __('Event Date', 'simply-events-calendar'),
                'width' => '140px',
                'sortable' => true
            ),
            'event_time' => array(
                'label' => _x('Time', 'admin list table column heading', 'simply-events-calendar'),
                'width' => '120px',
                'sortable' => true
            ),
            'event_location' => array(
                'label' => _x('Location', 'admin list table column heading', 'simply-events-calendar'),
                'width' => '150px',
                'sortable' => true
            )
        );
    }
}