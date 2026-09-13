<?php

/**
 * Recurrence engine for Simply Events Calendar.
 *
 * @package Simple_Events_Calendar
 * @since 4.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Simple_Events_Recurrence
{
    const META_PARENT        = '_sec_series_parent';
    const META_INDEX         = '_sec_series_occurrence_index';
    const META_OVERRIDES     = '_sec_field_overrides';
    const META_RULE_FREQ     = '_sec_recur_freq';
    const META_RULE_INTERVAL = '_sec_recur_interval';
    const META_RULE_END_TYPE = '_sec_recur_end_type';
    const META_RULE_COUNT    = '_sec_recur_count';
    const META_RULE_UNTIL    = '_sec_recur_until';
    const META_RULE_BYDAY    = '_sec_recur_byday';
    const META_RULE_HORIZON  = '_sec_recur_horizon';
    const META_RULE_SKIPPED  = '_sec_recur_skipped_indexes';
    const META_CASCADED_TRASH = '_sec_recur_cascaded_trash';
    const META_CHILD_COUNT    = '_sec_recur_child_count';
    const META_FUTURE_SEGMENTS = '_sec_recur_future_segments';

    const CRON_EXTEND_HOOK   = 'sec_recur_extend_horizon';
    const CRON_CONTINUE_HOOK = 'sec_recur_continue_generation';

    const NONCE_ACTION_SCOPE = 'sec_recur_edit_scope';
    const NONCE_FIELD_SCOPE  = 'sec_recur_edit_scope_nonce';

    /**
     * Snapshots of OLD post/meta values captured at post_updated time,
     * keyed by post_id. Used to compute the change diff on child saves
     * after the meta box has rewritten the meta at save_post priority 10.
     *
     * @var array
     */
    private static $pre_save_snapshots = array();

    /**
     * Map of child_id => parent_id captured in before_delete_post so we can
     * recount on the parent after deleted_post fires (by which time
     * get_post_meta on the child returns nothing because the post is gone).
     *
     * @var array
     */
    private static $pending_delete_parents = array();

    public function __construct()
    {
        $this->init_hooks();
    }

    private function init_hooks()
    {
        add_action('post_updated', array($this, 'snapshot_pre_save'), 10, 3);
        // save_post_{type} fires before save_post, and the meta box persists
        // field meta on save_post_simple-events at priority 10 — so we hook the
        // general save_post action at 30 to read meta values it has already
        // written, then filter on post_type.
        add_action('save_post', array($this, 'handle_save_post'), 30, 3);
        add_action('before_delete_post', array($this, 'handle_before_delete'));
        add_action('deleted_post', array($this, 'handle_deleted_post'));
        add_action('wp_trash_post', array($this, 'handle_trash'));
        add_action('trashed_post', array($this, 'handle_trashed_post'));
        add_action('untrashed_post', array($this, 'handle_untrash'));
        add_action('add_meta_boxes_simple-events', array($this, 'register_edit_scope_metabox'));
        add_action(self::CRON_EXTEND_HOOK, array($this, 'cron_extend_horizon'));
        add_action(self::CRON_CONTINUE_HOOK, array($this, 'continue_background_generation'), 10, 2);
        add_action('init', array($this, 'maybe_reschedule_cron'), 20);
        add_action('admin_notices', array($this, 'render_admin_notices'));
    }

    public static function schedule_cron()
    {
        if (!wp_next_scheduled(self::CRON_EXTEND_HOOK)) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', self::CRON_EXTEND_HOOK);
        }
    }

    public static function unschedule_cron()
    {
        // wp_unschedule_hook (not wp_clear_scheduled_hook) — the continuation
        // events are scheduled with per-parent args, and wp_clear_scheduled_hook
        // only matches events whose args exactly equal the (empty) array passed.
        wp_unschedule_hook(self::CRON_EXTEND_HOOK);
        wp_unschedule_hook(self::CRON_CONTINUE_HOOK);
    }

    public function maybe_reschedule_cron()
    {
        if (!wp_next_scheduled(self::CRON_EXTEND_HOOK)) {
            self::schedule_cron();
        }
    }

    // ---------------------------------------------------------------------
    // Hook handlers
    // ---------------------------------------------------------------------

    public function snapshot_pre_save($post_id, $post_after, $post_before)
    {
        unset($post_after);

        if (self::is_generating()) {
            return;
        }
        if (!$post_before || $post_before->post_type !== 'simple-events') {
            return;
        }
        if (!get_post_meta($post_id, self::META_PARENT, true)) {
            return;
        }

        self::$pre_save_snapshots[(int) $post_id] = array(
            'post_title'       => (string) $post_before->post_title,
            'post_content'     => (string) $post_before->post_content,
            'post_excerpt'     => (string) $post_before->post_excerpt,
            '_thumbnail_id'    => (string) get_post_meta($post_id, '_thumbnail_id', true),
            'event_date'       => (string) get_post_meta($post_id, 'event_date', true),
            'event_start_time' => (string) get_post_meta($post_id, 'event_start_time', true),
            'event_end_time'   => (string) get_post_meta($post_id, 'event_end_time', true),
            'event_location'   => (string) get_post_meta($post_id, 'event_location', true),
        );
    }

    public function handle_save_post($post_id, $post, $update)
    {
        unset($update);

        if (self::is_generating()) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (wp_is_post_revision($post_id)) {
            return;
        }
        if (!$post || $post->post_type !== 'simple-events') {
            return;
        }
        if ($post->post_status === 'auto-draft') {
            return;
        }

        $parent_id = (int) get_post_meta($post_id, self::META_PARENT, true);
        if ($parent_id) {
            $this->handle_child_save((int) $post_id, $parent_id);
            return;
        }

        if ((int) get_post_meta($post_id, 'event_repeats', true) !== 1) {
            // event_repeats just went from on -> off. Detect by the lingering
            // rule snapshot meta we wrote during the last regenerate_series.
            if (get_post_meta($post_id, self::META_RULE_FREQ, true)) {
                $this->handle_toggle_off((int) $post_id);
            }
            return;
        }

        $this->regenerate_series((int) $post_id, 'parent_save');
    }

    public function handle_before_delete($post_id)
    {
        if (self::is_generating()) {
            return;
        }
        if (get_post_type($post_id) !== 'simple-events') {
            return;
        }

        $parent_id = (int) get_post_meta($post_id, self::META_PARENT, true);
        if ($parent_id) {
            // Force-deleting a child: record its index as skipped (must be
            // done pre-delete because META_INDEX is gone after the post is
            // removed). The cached child-count refresh is deferred to
            // handle_deleted_post so it runs AFTER the row is actually
            // removed — recount here would still count this post as live.
            $this->record_skipped_index($parent_id, (int) get_post_meta($post_id, self::META_INDEX, true));
            self::$pending_delete_parents[(int) $post_id] = $parent_id;
            return;
        }

        // Parent being force-deleted — cascade. Includes trashed children in
        // case the parent was trashed first and is now being purged.
        $this->cascade_children(
            (int) $post_id,
            array('publish', 'pending', 'draft', 'future', 'private', 'trash'),
            'delete'
        );
    }

    public function handle_trash($post_id)
    {
        if (self::is_generating()) {
            return;
        }
        if (get_post_type($post_id) !== 'simple-events') {
            return;
        }

        $parent_of_child = (int) get_post_meta($post_id, self::META_PARENT, true);
        if ($parent_of_child) {
            // Child being trashed individually: do nothing to the series
            // meta — keep the child restorable back into the series. The
            // cached child-count refresh is deferred to handle_trashed_post
            // so it runs AFTER the status flips to 'trash' — recount here
            // would still count this post as live.
            return;
        }

        $this->cascade_children(
            (int) $post_id,
            array('publish', 'pending', 'draft', 'future', 'private'),
            'trash'
        );
    }

    public function handle_untrash($post_id)
    {
        if (self::is_generating()) {
            return;
        }
        if (get_post_type($post_id) !== 'simple-events') {
            return;
        }

        $parent_of_child = (int) get_post_meta($post_id, self::META_PARENT, true);
        if ($parent_of_child) {
            // Individually restoring a child — no cascade, but the live
            // (non-trash) count just went up.
            $this->recount_children($parent_of_child);
            return;
        }

        // Parent restored from trash — untrash any of its children that were
        // cascade-trashed alongside it. Identified by the
        // META_CASCADED_TRASH marker set in cascade_children('trash'), so
        // children the user had individually trashed before the parent
        // operation are NOT inadvertently restored.
        $children = get_posts(array(
            'post_type'      => 'simple-events',
            'post_status'    => 'trash',
            'posts_per_page' => -1,
            'meta_query'     => array(
                'relation' => 'AND',
                array(
                    'key'     => self::META_PARENT,
                    'value'   => (int) $post_id,
                    'compare' => '=',
                    'type'    => 'NUMERIC',
                ),
                array(
                    'key'     => self::META_CASCADED_TRASH,
                    'compare' => 'EXISTS',
                ),
            ),
            'no_found_rows'  => true,
            'fields'         => 'ids',
        ));

        if (empty($children)) {
            $this->recount_children((int) $post_id);
            return;
        }

        self::lock_generation((int) $post_id);
        try {
            foreach ($children as $child_id) {
                wp_untrash_post((int) $child_id);
                delete_post_meta((int) $child_id, self::META_CASCADED_TRASH);
            }
        } finally {
            self::unlock_generation();
            $this->recount_children((int) $post_id);
        }
    }

    /**
     * Runs AFTER an individual child is trashed — at this point the post
     * status has flipped to 'trash' so a recount excludes the just-trashed
     * child. Skipped during our own cascade (lock_generation set).
     *
     * @param int $post_id
     */
    public function handle_trashed_post($post_id)
    {
        if (self::is_generating()) {
            return;
        }
        if (get_post_type($post_id) !== 'simple-events') {
            return;
        }

        $parent_id = (int) get_post_meta($post_id, self::META_PARENT, true);
        if ($parent_id) {
            $this->recount_children($parent_id);
        }
    }

    /**
     * Runs AFTER WP removes the post row. By this point get_post_meta on
     * the deleted ID returns nothing, so we look up the parent ID from the
     * map captured in handle_before_delete.
     *
     * @param int $post_id
     */
    public function handle_deleted_post($post_id)
    {
        if (self::is_generating()) {
            return;
        }
        $post_id = (int) $post_id;
        if (!isset(self::$pending_delete_parents[$post_id])) {
            return;
        }
        $parent_id = (int) self::$pending_delete_parents[$post_id];
        unset(self::$pending_delete_parents[$post_id]);

        if ($parent_id) {
            $this->recount_children($parent_id);
        }
    }

    private function record_skipped_index($parent_id, $index)
    {
        if ($index <= 0) {
            return;
        }
        $skipped = get_post_meta($parent_id, self::META_RULE_SKIPPED, true);
        $skipped = is_array($skipped) ? array_map('intval', $skipped) : array();
        if (in_array($index, $skipped, true)) {
            return;
        }
        $skipped[] = $index;
        sort($skipped);
        update_post_meta($parent_id, self::META_RULE_SKIPPED, $skipped);
    }

    /**
     * Refresh the cached child count on a parent. Called whenever a series
     * mutation (regen / cascade / trash / delete / toggle-off) might have
     * changed the live (non-trash) child count. The admin Series column reads
     * this meta instead of running a per-row count query.
     *
     * @param int $parent_id
     */
    private function recount_children($parent_id)
    {
        $parent_id = (int) $parent_id;
        if (!$parent_id) {
            return;
        }

        $query = new WP_Query(array(
            'post_type'      => 'simple-events',
            'post_status'    => array('publish', 'pending', 'draft', 'future', 'private'),
            'posts_per_page' => 1,
            'meta_query'     => array(
                array(
                    'key'     => self::META_PARENT,
                    'value'   => $parent_id,
                    'compare' => '=',
                    'type'    => 'NUMERIC',
                ),
            ),
            'fields'         => 'ids',
            'no_found_rows'  => false,
        ));
        $count = (int) $query->found_posts;
        wp_reset_postdata();

        update_post_meta($parent_id, self::META_CHILD_COUNT, $count);
    }

    private function cascade_children($parent_id, array $statuses, $action)
    {
        $query = new WP_Query(array(
            'post_type'      => 'simple-events',
            'post_status'    => $statuses,
            'posts_per_page' => -1,
            'meta_query'     => array(
                array(
                    'key'     => self::META_PARENT,
                    'value'   => (int) $parent_id,
                    'compare' => '=',
                    'type'    => 'NUMERIC',
                ),
            ),
            'no_found_rows'  => true,
            'suppress_filters' => false,
        ));

        if (empty($query->posts)) {
            wp_reset_postdata();
            return;
        }

        self::lock_generation($parent_id);
        try {
            foreach ($query->posts as $child) {
                $overrides = get_post_meta($child->ID, self::META_OVERRIDES, true);
                $has_overrides = is_array($overrides) && !empty($overrides);

                if ($has_overrides) {
                    // Don't destroy user-edited data — detach into a standalone event.
                    delete_post_meta($child->ID, self::META_PARENT);
                    delete_post_meta($child->ID, self::META_INDEX);
                    delete_post_meta($child->ID, self::META_OVERRIDES);
                    continue;
                }

                if ($action === 'trash') {
                    // Mark before trashing so handle_untrash can distinguish
                    // cascade-trashed children from ones the user trashed
                    // individually before the parent.
                    update_post_meta($child->ID, self::META_CASCADED_TRASH, 1);
                    wp_trash_post($child->ID);
                } else {
                    wp_delete_post($child->ID, true);
                }
            }
        } finally {
            wp_reset_postdata();
            self::unlock_generation();
            $this->recount_children((int) $parent_id);
        }
    }

    private function handle_toggle_off($parent_id)
    {
        self::lock_generation($parent_id);
        $deleted  = 0;
        $detached = 0;

        $today_ymd = current_time('Ymd');

        try {
            $children = $this->get_existing_children($parent_id);
            foreach ($children as $child) {
                $overrides   = get_post_meta($child->ID, self::META_OVERRIDES, true);
                $is_trash    = $child->post_status === 'trash';
                $is_modified = is_array($overrides) && !empty($overrides);

                // Past occurrences are preserved as standalone events so
                // history isn't destroyed by disabling the rule. Trashed
                // and per-occurrence-edited children are likewise
                // detached. Only FUTURE unmodified live children get
                // force-deleted, matching the warning shown on the
                // "This event repeats" toggle.
                $child_date = (string) get_post_meta($child->ID, 'event_date', true);
                $is_past    = strlen($child_date) === 8 && $child_date < $today_ymd;

                if ($is_trash || $is_modified || $is_past) {
                    delete_post_meta($child->ID, self::META_PARENT);
                    delete_post_meta($child->ID, self::META_INDEX);
                    delete_post_meta($child->ID, self::META_OVERRIDES);
                    delete_post_meta($child->ID, self::META_CASCADED_TRASH);
                    $detached++;
                } else {
                    wp_delete_post($child->ID, true);
                    $deleted++;
                }
            }

            delete_post_meta($parent_id, self::META_RULE_FREQ);
            delete_post_meta($parent_id, self::META_RULE_INTERVAL);
            delete_post_meta($parent_id, self::META_RULE_END_TYPE);
            delete_post_meta($parent_id, self::META_RULE_COUNT);
            delete_post_meta($parent_id, self::META_RULE_UNTIL);
            delete_post_meta($parent_id, self::META_RULE_HORIZON);
            delete_post_meta($parent_id, self::META_RULE_SKIPPED);
            delete_post_meta($parent_id, self::META_CHILD_COUNT);
            delete_post_meta($parent_id, self::META_FUTURE_SEGMENTS);
        } finally {
            self::unlock_generation();
        }

        $this->enqueue_admin_notice(
            $parent_id,
            sprintf(
                /* translators: 1: sentence about deleted occurrences, 2: sentence about kept occurrences */
                __('Recurrence disabled. %1$s %2$s', 'simply-events-calendar'),
                sprintf(
                    /* translators: %d is the number of future unmodified occurrences deleted */
                    _n(
                        '%d future unmodified occurrence deleted.',
                        '%d future unmodified occurrences deleted.',
                        $deleted,
                        'simply-events-calendar'
                    ),
                    $deleted
                ),
                sprintf(
                    /* translators: %d is the number of past, edited, or trashed occurrences kept as standalone events */
                    _n(
                        '%d occurrence (past, edited, or trashed) kept as a standalone event.',
                        '%d occurrences (past, edited, or trashed) kept as standalone events.',
                        $detached,
                        'simply-events-calendar'
                    ),
                    $detached
                )
            ),
            'warning'
        );
    }

    private function enqueue_admin_notice($post_id, $message, $type = 'info')
    {
        $key     = 'sec_recur_notice_' . (int) $post_id;
        $notices = get_transient($key);
        $notices = is_array($notices) ? $notices : array();
        $notices[] = array('message' => $message, 'type' => $type);
        set_transient($key, $notices, HOUR_IN_SECONDS);
    }

    public function register_edit_scope_metabox($post)
    {
        if (!$post || !get_post_meta($post->ID, self::META_PARENT, true)) {
            return;
        }

        add_meta_box(
            'sec_recur_edit_scope',
            __('Series Edit Scope', 'simply-events-calendar'),
            array($this, 'render_edit_scope_metabox'),
            'simple-events',
            'side',
            'high'
        );
    }

    public function render_edit_scope_metabox($post)
    {
        $parent_id = (int) get_post_meta($post->ID, self::META_PARENT, true);
        $index     = (int) get_post_meta($post->ID, self::META_INDEX, true);

        wp_nonce_field(self::NONCE_ACTION_SCOPE, self::NONCE_FIELD_SCOPE);

        $options = array(
            'only'   => __('Only this occurrence', 'simply-events-calendar'),
            'future' => __('This and future occurrences', 'simply-events-calendar'),
            'series' => __('Entire series', 'simply-events-calendar'),
        );

        $parent_edit_url = get_edit_post_link($parent_id);

        echo '<p>';
        printf(
            /* translators: %d is the occurrence number within the series */
            esc_html__('This event is occurrence #%d in a recurring series.', 'simply-events-calendar'),
            (int) $index
        );
        if ($parent_edit_url) {
            echo ' <a href="' . esc_url($parent_edit_url) . '">' . esc_html__('Edit parent', 'simply-events-calendar') . '</a>';
        }
        echo '</p>';

        echo '<p><label for="sec_edit_scope"><strong>' . esc_html__('Apply changes to:', 'simply-events-calendar') . '</strong></label></p>';
        echo '<select name="sec_edit_scope" id="sec_edit_scope" class="widefat">';
        foreach ($options as $value => $label) {
            printf(
                '<option value="%s">%s</option>',
                esc_attr($value),
                esc_html($label)
            );
        }
        echo '</select>';

        echo '<p class="description">';
        esc_html_e('Only this occurrence: changes stay here. This and future: changes propagate to later siblings (date excluded). Entire series: propagates to the parent and every sibling, and a date change shifts the whole series.', 'simply-events-calendar');
        echo '</p>';
    }

    public function cron_extend_horizon()
    {
        if (self::is_generating()) {
            return;
        }

        $threshold_months = max(1, (int) apply_filters('sec_recur_horizon_refill_threshold_months', 6));
        $extend_months    = max(1, (int) apply_filters('sec_recur_horizon_extend_months', 18));
        $max_months       = max(1, (int) apply_filters('sec_recur_max_horizon_months', 60));

        $tz    = wp_timezone();
        $today = new DateTimeImmutable('today', $tz);

        try {
            $threshold_dt = $today->add(new DateInterval('P' . $threshold_months . 'M'));
        } catch (Exception $e) {
            return;
        }
        $threshold_ymd = $threshold_dt->format('Ymd');

        $never_series = get_posts(array(
            'post_type'      => 'simple-events',
            'post_status'    => array('publish', 'pending', 'draft', 'future', 'private'),
            'posts_per_page' => -1,
            'meta_query'     => array(
                'relation' => 'AND',
                array(
                    'key'     => self::META_RULE_END_TYPE,
                    'value'   => 'never',
                    'compare' => '=',
                ),
                array(
                    'key'     => self::META_RULE_HORIZON,
                    'compare' => 'EXISTS',
                ),
            ),
            'no_found_rows'  => true,
            'fields'         => 'ids',
        ));

        if (empty($never_series)) {
            return;
        }

        foreach ($never_series as $parent_id) {
            $parent_id = (int) $parent_id;

            $horizon = (string) get_post_meta($parent_id, self::META_RULE_HORIZON, true);
            if (strlen($horizon) !== 8) {
                continue;
            }
            if ($horizon > $threshold_ymd) {
                // Plenty of runway left, no refill needed.
                continue;
            }

            $start_ymd = (string) get_post_meta($parent_id, 'event_date', true);
            if (strlen($start_ymd) !== 8) {
                continue;
            }
            $start_dt = DateTimeImmutable::createFromFormat('!Ymd', $start_ymd, $tz);
            if (!$start_dt instanceof DateTimeImmutable) {
                continue;
            }

            try {
                $hard_cap_ymd = $start_dt->add(new DateInterval('P' . $max_months . 'M'))->format('Ymd');
            } catch (Exception $e) {
                continue;
            }

            if ($horizon >= $hard_cap_ymd) {
                // Already at the per-series hard cap; nothing more to extend.
                continue;
            }

            try {
                $current_horizon_dt = DateTimeImmutable::createFromFormat('!Ymd', $horizon, $tz);
                if (!$current_horizon_dt instanceof DateTimeImmutable) {
                    continue;
                }
                $new_horizon_dt = $current_horizon_dt->add(new DateInterval('P' . $extend_months . 'M'));
            } catch (Exception $e) {
                continue;
            }

            $new_horizon = $new_horizon_dt->format('Ymd');
            if ($new_horizon > $hard_cap_ymd) {
                $new_horizon = $hard_cap_ymd;
            }
            if ($new_horizon <= $horizon) {
                continue;
            }

            update_post_meta($parent_id, self::META_RULE_HORIZON, $new_horizon);

            if (!wp_next_scheduled(self::CRON_CONTINUE_HOOK, array($parent_id, 0))) {
                wp_schedule_single_event(time() + 5, self::CRON_CONTINUE_HOOK, array($parent_id, 0));
            }
        }
    }

    public function continue_background_generation($parent_id, $next_index)
    {
        unset($next_index);
        $this->regenerate_series((int) $parent_id, 'background_batch');
    }

    public function render_admin_notices()
    {
        if (!is_admin() || !function_exists('get_current_screen')) {
            return;
        }
        $screen = get_current_screen();
        if (!$screen || $screen->base !== 'post' || $screen->post_type !== 'simple-events') {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display lookup, no state change.
        $post_id = isset($_GET['post']) ? (int) wp_unslash($_GET['post']) : 0;
        if (!$post_id) {
            return;
        }

        $key     = 'sec_recur_notice_' . $post_id;
        $notices = get_transient($key);
        if (!is_array($notices) || empty($notices)) {
            return;
        }
        delete_transient($key);

        foreach ($notices as $notice) {
            $type = isset($notice['type']) && in_array($notice['type'], array('success', 'warning', 'error', 'info'), true)
                ? $notice['type']
                : 'info';
            $message = isset($notice['message']) ? (string) $notice['message'] : '';
            printf(
                '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
                esc_attr($type),
                esc_html($message)
            );
        }
    }

    // ---------------------------------------------------------------------
    // Child save handling
    // ---------------------------------------------------------------------

    private function handle_child_save($child_id, $parent_id)
    {
        if (!isset($_POST[self::NONCE_FIELD_SCOPE])) {
            return;
        }
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_FIELD_SCOPE])), self::NONCE_ACTION_SCOPE)) {
            return;
        }
        if (!current_user_can('edit_post', $child_id)) {
            return;
        }

        $scope = isset($_POST['sec_edit_scope'])
            ? sanitize_text_field(wp_unslash($_POST['sec_edit_scope']))
            : 'only';
        if (!in_array($scope, array('only', 'future', 'series'), true)) {
            $scope = 'only';
        }

        $changed = $this->compute_changed_fields($child_id);
        if (empty($changed)) {
            return;
        }

        switch ($scope) {
            case 'only':
                $this->apply_only_scope($child_id, $changed);
                break;
            case 'future':
                $this->apply_future_scope($child_id, $parent_id, $changed);
                break;
            case 'series':
                $this->apply_series_scope($child_id, $parent_id, $changed);
                break;
        }
    }

    private function compute_changed_fields($child_id)
    {
        if (!isset(self::$pre_save_snapshots[$child_id])) {
            return array();
        }
        $snapshot = self::$pre_save_snapshots[$child_id];

        $current = array(
            'post_title'       => (string) get_post_field('post_title', $child_id),
            'post_content'     => (string) get_post_field('post_content', $child_id),
            'post_excerpt'     => (string) get_post_field('post_excerpt', $child_id),
            '_thumbnail_id'    => (string) get_post_meta($child_id, '_thumbnail_id', true),
            'event_date'       => (string) get_post_meta($child_id, 'event_date', true),
            'event_start_time' => (string) get_post_meta($child_id, 'event_start_time', true),
            'event_end_time'   => (string) get_post_meta($child_id, 'event_end_time', true),
            'event_location'   => (string) get_post_meta($child_id, 'event_location', true),
        );

        $changed = array();
        foreach ($current as $key => $value) {
            if (!array_key_exists($key, $snapshot)) {
                continue;
            }
            if ($value !== (string) $snapshot[$key]) {
                $changed[$key] = $value;
            }
        }

        return $changed;
    }

    private function apply_only_scope($child_id, array $changed)
    {
        $existing = get_post_meta($child_id, self::META_OVERRIDES, true);
        $existing = is_array($existing) ? $existing : array();
        $merged   = array_values(array_unique(array_merge($existing, array_keys($changed))));
        update_post_meta($child_id, self::META_OVERRIDES, $merged);
    }

    private function apply_future_scope($child_id, $parent_id, array $changed)
    {
        $this_index = (int) get_post_meta($child_id, self::META_INDEX, true);
        if (!$this_index) {
            return;
        }

        // Mark all changed keys as overrides on this child, so a later
        // "series" edit won't silently blow them away.
        $this->apply_only_scope($child_id, $changed);

        // event_date is not propagated under "future" scope — date changes
        // affect only the edited occurrence. Use "series" to shift everything.
        $propagate = $changed;
        unset($propagate['event_date']);
        if (empty($propagate)) {
            return;
        }

        // Persist the propagation as a "segment" on the parent so that
        // children created LATER (via async continuation, horizon
        // extension, or count increase) also pick up the future-scoped
        // edit. Without this, only the already-existing siblings updated
        // below would carry the change, and newly-generated occurrences
        // would silently revert to the parent's untouched values.
        $this->append_future_segment($parent_id, $this_index, $propagate);

        $children = $this->get_existing_children($parent_id);
        foreach ($children as $idx => $sibling) {
            if ($idx <= $this_index) {
                continue;
            }
            if ($sibling->post_status === 'trash') {
                continue;
            }

            $sibling_overrides = get_post_meta($sibling->ID, self::META_OVERRIDES, true);
            $sibling_overrides = is_array($sibling_overrides) ? $sibling_overrides : array();

            foreach ($propagate as $key => $value) {
                if (in_array($key, $sibling_overrides, true)) {
                    continue;
                }
                $this->write_field_to_post($sibling->ID, $key, $value);
                $sibling_overrides[] = $key;
            }

            $sibling_overrides = array_values(array_unique($sibling_overrides));
            update_post_meta($sibling->ID, self::META_OVERRIDES, $sibling_overrides);
        }
    }

    /**
     * Append a future-scope segment to the parent's META_FUTURE_SEGMENTS
     * record so newly-generated children at index >= from_index inherit
     * the edited field values.
     *
     * @param int   $parent_id
     * @param int   $from_index
     * @param array $fields associative key => value
     */
    private function append_future_segment($parent_id, $from_index, array $fields)
    {
        if ($from_index <= 0 || empty($fields)) {
            return;
        }

        $segments = get_post_meta($parent_id, self::META_FUTURE_SEGMENTS, true);
        $segments = is_array($segments) ? $segments : array();
        $segments[] = array(
            'from_index' => (int) $from_index,
            'fields'     => $fields,
        );
        update_post_meta($parent_id, self::META_FUTURE_SEGMENTS, $segments);
    }

    /**
     * Compute the effective overlay of fields for a given occurrence index
     * by replaying all segments whose from_index <= $index in order (later
     * segments win on key collision).
     *
     * @param int $parent_id
     * @param int $index
     * @return array<string,mixed>
     */
    private function get_segment_overlay_for_index($parent_id, $index)
    {
        $segments = get_post_meta($parent_id, self::META_FUTURE_SEGMENTS, true);
        if (!is_array($segments) || empty($segments)) {
            return array();
        }

        $overlay = array();
        foreach ($segments as $seg) {
            if (!isset($seg['from_index'], $seg['fields'])) {
                continue;
            }
            if (!is_array($seg['fields'])) {
                continue;
            }
            if ((int) $seg['from_index'] > $index) {
                continue;
            }
            $overlay = array_merge($overlay, $seg['fields']);
        }

        return $overlay;
    }

    /**
     * After a series-scope edit propagates a set of keys series-wide,
     * remove those keys from every existing future segment. Otherwise the
     * series-scope value would be overridden by a stale segment when new
     * children are created at indexes inside that segment's range.
     *
     * @param int   $parent_id
     * @param array $keys keys just propagated under "series" scope
     */
    private function strip_future_segment_keys($parent_id, array $keys)
    {
        if (empty($keys)) {
            return;
        }

        $segments = get_post_meta($parent_id, self::META_FUTURE_SEGMENTS, true);
        if (!is_array($segments) || empty($segments)) {
            return;
        }

        $cleaned = array();
        foreach ($segments as $seg) {
            if (!isset($seg['from_index'], $seg['fields']) || !is_array($seg['fields'])) {
                continue;
            }
            $remaining_fields = $seg['fields'];
            foreach ($keys as $key) {
                unset($remaining_fields[$key]);
            }
            if (!empty($remaining_fields)) {
                $cleaned[] = array(
                    'from_index' => (int) $seg['from_index'],
                    'fields'     => $remaining_fields,
                );
            }
        }

        if (empty($cleaned)) {
            delete_post_meta($parent_id, self::META_FUTURE_SEGMENTS);
        } else {
            update_post_meta($parent_id, self::META_FUTURE_SEGMENTS, $cleaned);
        }
    }

    private function apply_series_scope($child_id, $parent_id, array $changed)
    {
        // Capture the full set of keys the user just edited BEFORE we strip
        // event_date out, so the override-cleanup below also clears
        // event_date from this child's overrides — otherwise a prior
        // "only this occurrence" date override would leak past the
        // series-wide shift and future regens would skip the child's date.
        $keys_to_clear = array_keys($changed);

        // event_date changes are interpreted as a series-wide shift: compute
        // delta and move the parent's anchor date. The regenerate at the end
        // will then re-key every unmodified child to its new date.
        if (isset($changed['event_date'])) {
            $this->shift_series_by_child_date_delta($child_id, $parent_id, $changed['event_date']);
            unset($changed['event_date']);
        }

        // Propagate the remaining (non-date) changes to the parent and any
        // siblings that haven't overridden the same key locally.
        foreach ($changed as $key => $value) {
            $this->write_field_to_post($parent_id, $key, $value);
        }

        $children = $this->get_existing_children($parent_id);
        foreach ($children as $sibling) {
            if ((int) $sibling->ID === (int) $child_id) {
                continue;
            }
            $sibling_overrides = get_post_meta($sibling->ID, self::META_OVERRIDES, true);
            $sibling_overrides = is_array($sibling_overrides) ? $sibling_overrides : array();
            foreach ($changed as $key => $value) {
                if (in_array($key, $sibling_overrides, true)) {
                    continue;
                }
                $this->write_field_to_post($sibling->ID, $key, $value);
            }
        }

        // The child whose edit triggered this scope now matches the rest of
        // the series for any propagated key — clear those keys (including
        // event_date when it was part of the edit, via $keys_to_clear) from
        // its override list so future series-scope edits can update them
        // again.
        $this_overrides = get_post_meta($child_id, self::META_OVERRIDES, true);
        if (is_array($this_overrides) && !empty($this_overrides)) {
            $remaining = array_values(array_diff($this_overrides, $keys_to_clear));
            if (empty($remaining)) {
                delete_post_meta($child_id, self::META_OVERRIDES);
            } else {
                update_post_meta($child_id, self::META_OVERRIDES, $remaining);
            }
        }

        // Series-wide values for these keys now win — strip them from any
        // existing future-scope segments so newly-generated children pick
        // up the parent's value instead of an older future-scoped overlay.
        $this->strip_future_segment_keys($parent_id, $keys_to_clear);

        $this->regenerate_series($parent_id, 'cascade');
    }

    private function shift_series_by_child_date_delta($child_id, $parent_id, $new_child_date)
    {
        if (!isset(self::$pre_save_snapshots[$child_id]['event_date'])) {
            return;
        }
        $old_child_date = (string) self::$pre_save_snapshots[$child_id]['event_date'];
        if (strlen($old_child_date) !== 8 || strlen($new_child_date) !== 8) {
            return;
        }

        $tz     = wp_timezone();
        $old_dt = DateTimeImmutable::createFromFormat('!Ymd', $old_child_date, $tz);
        $new_dt = DateTimeImmutable::createFromFormat('!Ymd', $new_child_date, $tz);
        if (!$old_dt instanceof DateTimeImmutable || !$new_dt instanceof DateTimeImmutable) {
            return;
        }

        $delta_days = (int) $old_dt->diff($new_dt)->format('%r%a');
        if ($delta_days === 0) {
            return;
        }

        $parent_old_ymd = (string) get_post_meta($parent_id, 'event_date', true);
        $parent_old_dt  = DateTimeImmutable::createFromFormat('!Ymd', $parent_old_ymd, $tz);
        if (!$parent_old_dt instanceof DateTimeImmutable) {
            return;
        }

        try {
            $modifier      = ($delta_days >= 0 ? '+' : '') . $delta_days . ' days';
            $parent_new_dt = $parent_old_dt->modify($modifier);
        } catch (Exception $e) {
            return;
        }
        if (!$parent_new_dt instanceof DateTimeImmutable) {
            return;
        }

        update_post_meta($parent_id, 'event_date', $parent_new_dt->format('Ymd'));
    }

    private function write_field_to_post($post_id, $key, $value)
    {
        if (in_array($key, array('post_title', 'post_content', 'post_excerpt'), true)) {
            global $wpdb;
            // Direct posts-table write: wp_update_post would re-fire save_post
            // (and the meta box's save handler) on every propagation target,
            // overwriting their meta with $_POST values from the OTHER post
            // being edited. Cache is invalidated explicitly below.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update(
                $wpdb->posts,
                array($key => $value),
                array('ID' => (int) $post_id),
                array('%s'),
                array('%d')
            );
            clean_post_cache((int) $post_id);
            return;
        }

        update_post_meta($post_id, $key, $value);
    }

    // ---------------------------------------------------------------------
    // Core generation
    // ---------------------------------------------------------------------

    public function regenerate_series($parent_id, $context)
    {
        $parent_id = (int) $parent_id;
        if (!$parent_id) {
            return;
        }

        // Atomic acquire via the options table. add_option uses INSERT
        // IGNORE under the hood and returns true only for the request that
        // actually inserted the row, so two concurrent saves can't both
        // claim the lock the way a get_transient+set_transient pair would.
        $lock_key = 'sec_recur_lock_' . $parent_id;
        $lock_ttl = MINUTE_IN_SECONDS;
        $now      = time();
        $acquired = (bool) add_option($lock_key, (string) $now, '', 'no');
        if (!$acquired) {
            $existing = (int) get_option($lock_key, 0);
            if ($existing > 0 && ($now - $existing) > $lock_ttl) {
                // Stale lock from a request that died mid-flight. Clear and
                // race once more — at worst we lose to another live caller.
                delete_option($lock_key);
                $acquired = (bool) add_option($lock_key, (string) $now, '', 'no');
            }
        }
        if (!$acquired) {
            simple_events_debug_log('Recurrence lock held; deferring regeneration to background', array('parent_id' => $parent_id));
            if (!wp_next_scheduled(self::CRON_CONTINUE_HOOK, array($parent_id, 0))) {
                wp_schedule_single_event(time() + 90, self::CRON_CONTINUE_HOOK, array($parent_id, 0));
            }
            return;
        }

        self::lock_generation($parent_id);

        $revisions_filter = static function ($num, $post) {
            if ($post && isset($post->post_type) && $post->post_type === 'simple-events') {
                return 0;
            }
            return $num;
        };
        add_filter('wp_revisions_to_keep', $revisions_filter, 10, 2);

        try {
            $rule = $this->read_rule($parent_id);
            if (!$rule) {
                return;
            }

            $start_ymd = (string) get_post_meta($parent_id, 'event_date', true);
            if (strlen($start_ymd) !== 8) {
                return;
            }

            $stored_horizon = (string) get_post_meta($parent_id, self::META_RULE_HORIZON, true);
            $computed       = $this->compute_occurrence_dates($start_ymd, $rule, $stored_horizon);

            // An empty $computed (e.g., the user set until earlier than the
            // event_date, or the start string didn't parse) still has to run
            // through diff_and_apply — otherwise any children created by a
            // previous rule remain orphaned in queries while the user thinks
            // the save succeeded.
            $existing = $this->get_existing_children($parent_id);
            $result   = $this->diff_and_apply($parent_id, $computed, $existing);

            if ($rule['end_type'] === 'never' && !empty($computed)) {
                // The target horizon is the LAST date that compute returned
                // — i.e. the planned stop_date. $result['last_date'] is the
                // date of the last child actually CREATED in this pass and
                // gets truncated to ~sync_batch_size for large series, so
                // persisting it would shrink the horizon to the first batch
                // and the continuation would stop right there.
                $target_horizon = (string) end($computed);
                $current_stored = (string) get_post_meta($parent_id, self::META_RULE_HORIZON, true);
                if ($target_horizon !== '' && (strlen($current_stored) !== 8 || $target_horizon > $current_stored)) {
                    update_post_meta($parent_id, self::META_RULE_HORIZON, $target_horizon);
                }
            } else {
                delete_post_meta($parent_id, self::META_RULE_HORIZON);
            }

            // Persist rule snapshot so cron / background workers can read the
            // rule without loading the admin-only meta box.
            update_post_meta($parent_id, self::META_RULE_FREQ, $rule['freq']);
            update_post_meta($parent_id, self::META_RULE_INTERVAL, $rule['interval']);
            update_post_meta($parent_id, self::META_RULE_END_TYPE, $rule['end_type']);
            if ($rule['end_type'] === 'count') {
                update_post_meta($parent_id, self::META_RULE_COUNT, $rule['count']);
            } else {
                delete_post_meta($parent_id, self::META_RULE_COUNT);
            }
            if ($rule['end_type'] === 'until') {
                update_post_meta($parent_id, self::META_RULE_UNTIL, $rule['until']);
            } else {
                delete_post_meta($parent_id, self::META_RULE_UNTIL);
            }
            if (!empty($rule['byday'])) {
                update_post_meta($parent_id, self::META_RULE_BYDAY, implode(',', $rule['byday']));
            } else {
                delete_post_meta($parent_id, self::META_RULE_BYDAY);
            }

            if (!empty($result['more_remaining'])) {
                $this->schedule_continuation($parent_id, (int) $result['created'], (string) $context);
            }
        } finally {
            remove_filter('wp_revisions_to_keep', $revisions_filter, 10);
            self::unlock_generation();
            delete_option($lock_key);
            $this->recount_children($parent_id);
        }
    }

    private function schedule_continuation($parent_id, $created_this_pass, $context)
    {
        if (wp_next_scheduled(self::CRON_CONTINUE_HOOK, array($parent_id, 0))) {
            return;
        }
        wp_schedule_single_event(time() + 5, self::CRON_CONTINUE_HOOK, array($parent_id, 0));

        if ($context === 'parent_save' || $context === 'cascade') {
            $this->enqueue_admin_notice(
                $parent_id,
                sprintf(
                    /* translators: %d is the number of occurrences created in the foreground pass */
                    _n(
                        'Created %d occurrence so far; the remaining occurrences will be generated in the background within a few minutes.',
                        'Created %d occurrences so far; the remaining occurrences will be generated in the background within a few minutes.',
                        $created_this_pass,
                        'simply-events-calendar'
                    ),
                    $created_this_pass
                ),
                'info'
            );
        }
    }

    private function read_rule($parent_id)
    {
        if ((int) get_post_meta($parent_id, 'event_repeats', true) !== 1) {
            return null;
        }

        $freq     = (string) get_post_meta($parent_id, 'event_repeat_frequency', true);
        $interval = max(1, (int) get_post_meta($parent_id, 'event_repeat_interval', true));
        $end_type = (string) get_post_meta($parent_id, 'event_repeat_end_type', true);
        $count    = max(1, (int) get_post_meta($parent_id, 'event_repeat_count', true));
        $until    = (string) get_post_meta($parent_id, 'event_repeat_until', true);

        if (!in_array($freq, array('daily', 'weekly', 'monthly', 'yearly'), true)) {
            return null;
        }
        if (!in_array($end_type, array('never', 'count', 'until'), true)) {
            return null;
        }
        if ($end_type === 'until' && strlen($until) !== 8) {
            return null;
        }

        $byday = array();
        if ($freq === 'weekly') {
            $byday_raw = (string) get_post_meta($parent_id, 'event_repeat_byday', true);
            if ($byday_raw !== '') {
                foreach (explode(',', $byday_raw) as $piece) {
                    $day = (int) trim($piece);
                    if ($day >= 0 && $day <= 6) {
                        $byday[$day] = $day;
                    }
                }
                $byday = array_values($byday);
                sort($byday);
            }
        }

        return array(
            'freq'     => $freq,
            'interval' => $interval,
            'end_type' => $end_type,
            'count'    => $count,
            'until'    => $until,
            'byday'    => $byday,
        );
    }

    private function compute_occurrence_dates($start_ymd, array $rule, $stored_horizon = '')
    {
        $max_occurrences    = max(1, (int) apply_filters('sec_recur_max_occurrences', 1000));
        $max_horizon_months = max(1, (int) apply_filters('sec_recur_max_horizon_months', 60));

        $tz    = wp_timezone();
        $start = DateTimeImmutable::createFromFormat('!Ymd', $start_ymd, $tz);
        if (!$start instanceof DateTimeImmutable) {
            return array();
        }

        if ($rule['end_type'] === 'count') {
            $stop_count = min($max_occurrences, $rule['count']);
            $stop_date  = null;
        } elseif ($rule['end_type'] === 'until') {
            $stop_count = $max_occurrences;
            $stop_date  = $rule['until'];
        } else {
            $stop_count = $max_occurrences;

            try {
                $hard_cap_dt = $start->add(new DateInterval('P' . $max_horizon_months . 'M'));
            } catch (Exception $e) {
                return array();
            }
            $hard_cap_ymd = $hard_cap_dt->format('Ymd');

            if (strlen($stored_horizon) === 8) {
                // Subsequent passes (incl. cron-extended) use the persisted
                // horizon so the series doesn't shrink back to the initial
                // ~18-month range every time the parent gets re-saved.
                $stop_date = $stored_horizon > $hard_cap_ymd ? $hard_cap_ymd : $stored_horizon;
            } else {
                $months = (int) apply_filters('sec_recur_horizon_extend_months', 18)
                    + (int) apply_filters('sec_recur_horizon_refill_threshold_months', 6);
                $months = max(1, min($months, $max_horizon_months));

                try {
                    $horizon_dt = $start->add(new DateInterval('P' . $months . 'M'));
                } catch (Exception $e) {
                    return array();
                }
                if ($horizon_dt > $hard_cap_dt) {
                    $horizon_dt = $hard_cap_dt;
                }
                $stop_date = $horizon_dt->format('Ymd');
            }
        }

        if ($rule['freq'] === 'weekly' && !empty($rule['byday'])) {
            return $this->compute_weekly_byday_dates($start, $rule, $stop_count, $stop_date, $max_horizon_months);
        }

        $dates = array();
        for ($index = 0; $index < $stop_count; $index++) {
            $date = $this->advance_date($start, $rule['freq'], $rule['interval'] * $index);
            if (!$date instanceof DateTimeImmutable) {
                break;
            }

            $ymd = $date->format('Ymd');
            if ($stop_date !== null && $ymd > $stop_date) {
                break;
            }

            $dates[$index] = $ymd;
        }

        return $dates;
    }

    /**
     * Generate weekly occurrences restricted to specific weekdays.
     *
     * Index 0 is always the parent's own date (the engine contract). Children
     * (index 1+) are emitted in chronological order on each selected weekday
     * after the start, but only in "active" weeks — every $interval-th calendar
     * week measured from the week containing the start date (week start follows
     * the site's start_of_week setting).
     *
     * @param DateTimeImmutable $start              Series start.
     * @param array             $rule               Rule incl. 'interval' and 'byday' (ints 0-6).
     * @param int               $stop_count         Max total occurrences (incl. parent).
     * @param string|null       $stop_date          Inclusive Ymd ceiling, or null.
     * @param int               $max_horizon_months Absolute safety ceiling in months.
     * @return array<int,string> index => Ymd
     */
    private function compute_weekly_byday_dates(DateTimeImmutable $start, array $rule, $stop_count, $stop_date, $max_horizon_months)
    {
        $byday    = $rule['byday'];
        $interval = max(1, (int) $rule['interval']);
        $sow      = (int) get_option('start_of_week', 0);

        $dates    = array($start->format('Ymd')); // index 0 = parent.
        $start_wk = $this->week_start_anchor($start, $sow);

        // Absolute scan ceiling so 'count' mode (no stop_date) can never loop
        // away if byday is unexpectedly unsatisfiable. Note: for a very sparse
        // 'count' rule (e.g. one weekday every 4 weeks) this ceiling can also
        // cap the series below the requested count — the same bounded behavior
        // as the plugin's other horizon limits.
        try {
            $ceiling_ymd = $start->add(new DateInterval('P' . max(1, (int) $max_horizon_months) . 'M'))->format('Ymd');
        } catch (Exception $e) {
            return $dates;
        }

        $cursor = $start->modify('+1 day');
        $index  = 1;
        while ($index < $stop_count && $cursor instanceof DateTimeImmutable) {
            $ymd = $cursor->format('Ymd');
            if ($ymd > $ceiling_ymd) {
                break;
            }
            if ($stop_date !== null && $ymd > $stop_date) {
                break;
            }

            $dow = (int) $cursor->format('w');
            if (in_array($dow, $byday, true)) {
                $cur_wk = $this->week_start_anchor($cursor, $sow);
                // Count whole CALENDAR days between the two week-starts, not the
                // raw timestamp delta: a DST week is 604800±3600s, so dividing
                // the timestamp difference by WEEK_IN_SECONDS would floor a
                // genuine N-week gap to N-1 across spring-forward and drift the
                // whole series a week early. diff()->days is DST-immune.
                $days  = (int) $start_wk->diff($cur_wk)->days;
                $weeks = intdiv($days, 7);
                if ($weeks % $interval === 0) {
                    $dates[$index] = $ymd;
                    $index++;
                }
            }

            $cursor = $cursor->modify('+1 day');
        }

        return $dates;
    }

    /**
     * Return the date shifted back to the start of its week, per the site's
     * start_of_week (0=Sunday … 6=Saturday). Used to measure whole-week
     * intervals between two dates independent of weekday.
     *
     * @param DateTimeImmutable $date Date.
     * @param int               $sow  start_of_week (0-6).
     * @return DateTimeImmutable
     */
    private function week_start_anchor(DateTimeImmutable $date, $sow)
    {
        $dow  = (int) $date->format('w');
        $diff = (($dow - $sow) + 7) % 7;
        return $diff > 0 ? $date->modify('-' . $diff . ' days') : $date;
    }

    private function advance_date(DateTimeImmutable $start, $freq, $offset_units)
    {
        if ($offset_units === 0) {
            return $start;
        }

        try {
            switch ($freq) {
                case 'daily':
                    return $start->add(new DateInterval('P' . $offset_units . 'D'));
                case 'weekly':
                    return $start->add(new DateInterval('P' . ($offset_units * 7) . 'D'));
                case 'monthly':
                    return $this->add_months_clamped($start, $offset_units);
                case 'yearly':
                    return $this->add_years_clamped($start, $offset_units);
            }
        } catch (Exception $e) {
            return null;
        }
        return null;
    }

    private function add_months_clamped(DateTimeImmutable $start, $months)
    {
        $year  = (int) $start->format('Y');
        $month = (int) $start->format('n');
        $day   = (int) $start->format('j');

        $total     = (($month - 1) + $months);
        $new_year  = $year + intdiv($total, 12);
        $new_month = ($total % 12) + 1;
        if ($new_month <= 0) {
            $new_month += 12;
            $new_year  -= 1;
        }

        $last_day = (int) $start->setDate($new_year, $new_month, 1)->format('t');
        $new_day  = min($day, $last_day);

        return $start->setDate($new_year, $new_month, $new_day);
    }

    private function add_years_clamped(DateTimeImmutable $start, $years)
    {
        $year  = (int) $start->format('Y');
        $month = (int) $start->format('n');
        $day   = (int) $start->format('j');

        $new_year = $year + $years;

        if ($month === 2 && $day === 29) {
            $last_day = (int) $start->setDate($new_year, 2, 1)->format('t');
            $day      = min($day, $last_day);
        }

        return $start->setDate($new_year, $month, $day);
    }

    private function get_existing_children($parent_id)
    {
        $query = new WP_Query(array(
            'post_type'        => 'simple-events',
            // 'any' excludes 'trash' by WP convention, but we need trashed
            // children too — otherwise a child the user individually trashed
            // would be invisible to the diff pass, treated as a missing
            // index, and a duplicate live child created at the same index.
            'post_status'      => array('publish', 'pending', 'draft', 'future', 'private', 'trash'),
            'posts_per_page'   => -1,
            'meta_query'       => array(
                array(
                    'key'     => self::META_PARENT,
                    'value'   => (int) $parent_id,
                    'compare' => '=',
                    'type'    => 'NUMERIC',
                ),
            ),
            'no_found_rows'    => true,
            'suppress_filters' => false,
            'orderby'          => 'ID',
            'order'            => 'ASC',
        ));

        $by_index = array();
        foreach ($query->posts as $child) {
            $idx = (int) get_post_meta($child->ID, self::META_INDEX, true);
            $by_index[$idx] = $child;
        }
        wp_reset_postdata();

        return $by_index;
    }

    private function diff_and_apply($parent_id, array $computed_dates, array $existing_children)
    {
        $skipped = get_post_meta($parent_id, self::META_RULE_SKIPPED, true);
        $skipped = is_array($skipped) ? array_map('intval', $skipped) : array();

        $parent        = get_post($parent_id);
        $parent_status = $parent ? $parent->post_status : 'publish';
        $copyable      = $this->get_copyable_field_keys();
        $sync_limit    = max(1, (int) apply_filters('sec_recur_sync_batch_size', 50));

        // Snapshot the parent's current copyable values once so each
        // existing-child diff below doesn't re-read them per child.
        $parent_values = $parent ? $this->snapshot_parent_field_values($parent_id, $parent) : array();

        $created        = 0;
        $updated        = 0;
        $deleted        = 0;
        $detached       = 0;
        $last_date      = '';
        $more_remaining = false;

        foreach ($computed_dates as $index => $ymd) {
            if ($index === 0) {
                $last_date = $ymd;
                continue;
            }

            if (in_array($index, $skipped, true)) {
                continue;
            }

            if (isset($existing_children[$index])) {
                $child = $existing_children[$index];

                // A child the user individually trashed: leave its meta and
                // date alone so a future untrash restores it to whatever
                // state the user trashed it in. Importantly, just consume
                // the index so we don't create a duplicate replacement at
                // this slot below.
                if ($child->post_status === 'trash') {
                    $last_date = $ymd;
                    unset($existing_children[$index]);
                    continue;
                }

                $overrides = get_post_meta($child->ID, self::META_OVERRIDES, true);
                $overrides = is_array($overrides) ? $overrides : array();

                if (!in_array('event_date', $overrides, true)) {
                    update_post_meta($child->ID, 'event_date', $ymd);
                    $updated++;
                }

                // Also sync each copyable field from the parent's current
                // values when the child hasn't locally overridden it —
                // otherwise edits to the parent's title / content /
                // excerpt / thumbnail / time / location stay invisible on
                // already-generated children.
                if ($this->propagate_parent_fields_to_child($child->ID, $parent_values, $copyable, $overrides)) {
                    $updated++;
                }

                $last_date = $ymd;
                unset($existing_children[$index]);
                continue;
            }

            if ($created >= $sync_limit) {
                $more_remaining = true;
                break;
            }

            $segment_overlay = $this->get_segment_overlay_for_index($parent_id, $index);
            $child_id = $this->create_child(
                $parent_id,
                $parent,
                $parent_status,
                $index,
                $ymd,
                $copyable,
                $parent_values,
                $segment_overlay
            );
            if ($child_id) {
                $created++;
                $last_date = $ymd;
            }
        }

        // Deletion / detachment of out-of-range children only runs on a
        // completing pass — on partial passes (more_remaining = true) the
        // remaining $existing_children may still be in range, waiting for a
        // later continuation pass to touch them.
        if (!$more_remaining) {
            foreach ($existing_children as $child) {
                if ($child->post_status === 'trash') {
                    // Already trashed children that are now out of range:
                    // detach so they don't reattach if the user restores
                    // them later, but never force-delete (the user moved
                    // them to trash deliberately).
                    delete_post_meta($child->ID, self::META_PARENT);
                    delete_post_meta($child->ID, self::META_INDEX);
                    delete_post_meta($child->ID, self::META_OVERRIDES);
                    delete_post_meta($child->ID, self::META_CASCADED_TRASH);
                    $detached++;
                    continue;
                }

                $overrides = get_post_meta($child->ID, self::META_OVERRIDES, true);
                if (is_array($overrides) && !empty($overrides)) {
                    delete_post_meta($child->ID, self::META_PARENT);
                    delete_post_meta($child->ID, self::META_INDEX);
                    delete_post_meta($child->ID, self::META_OVERRIDES);
                    $detached++;
                } else {
                    wp_delete_post($child->ID, true);
                    $deleted++;
                }
            }
        }

        return array(
            'created'        => $created,
            'updated'        => $updated,
            'deleted'        => $deleted,
            'detached'       => $detached,
            'last_date'      => $last_date,
            'more_remaining' => $more_remaining,
        );
    }

    private function create_child(
        $parent_id,
        $parent,
        $parent_status,
        $index,
        $ymd,
        array $copyable,
        array $parent_values = array(),
        array $segment_overlay = array()
    ) {
        if (!$parent) {
            return 0;
        }

        // Resolve effective copyable values: parent's current snapshot
        // overlaid with any future-scope segments matching this index. The
        // overlaid keys also become local overrides on the new child so a
        // later "series" edit respects the explicit future-scoped intent.
        if (empty($parent_values)) {
            $parent_values = $this->snapshot_parent_field_values($parent_id, $parent);
        }
        $effective = $parent_values;
        foreach ($segment_overlay as $k => $v) {
            $effective[$k] = $v;
        }

        $insert_data = array(
            'post_type'   => 'simple-events',
            'post_status' => $parent_status,
            'post_author' => $parent->post_author,
        );
        if (in_array('post_title', $copyable, true)) {
            $insert_data['post_title'] = isset($effective['post_title']) ? $effective['post_title'] : $parent->post_title;
        }
        if (in_array('post_content', $copyable, true)) {
            $insert_data['post_content'] = isset($effective['post_content']) ? $effective['post_content'] : $parent->post_content;
        }
        if (in_array('post_excerpt', $copyable, true)) {
            $insert_data['post_excerpt'] = isset($effective['post_excerpt']) ? $effective['post_excerpt'] : $parent->post_excerpt;
        }

        $child_id = wp_insert_post($insert_data, true);
        if (is_wp_error($child_id) || !$child_id) {
            return 0;
        }

        // Defensive: ensure no recurrence-rule meta lingers on a generated
        // occurrence so each child is a leaf, not a phantom series-parent.
        // (The native meta box bails during generation, so it won't write
        // these — this guards against meta leaking in via other paths.)
        foreach (array(
            'event_repeats',
            'event_repeat_frequency',
            'event_repeat_interval',
            'event_repeat_end_type',
            'event_repeat_count',
            'event_repeat_until',
            'event_repeat_byday',
        ) as $recur_key) {
            delete_post_meta($child_id, $recur_key);
        }

        if (in_array('_thumbnail_id', $copyable, true)) {
            $thumbnail_id = isset($effective['_thumbnail_id'])
                ? $effective['_thumbnail_id']
                : get_post_meta($parent_id, '_thumbnail_id', true);
            if ($thumbnail_id) {
                update_post_meta($child_id, '_thumbnail_id', $thumbnail_id);
            }
        }

        foreach (array('event_start_time', 'event_end_time', 'event_location') as $meta_key) {
            if (!in_array($meta_key, $copyable, true)) {
                continue;
            }
            $value = isset($effective[$meta_key])
                ? $effective[$meta_key]
                : get_post_meta($parent_id, $meta_key, true);
            update_post_meta($child_id, $meta_key, $value);
        }

        update_post_meta($child_id, 'event_date', $ymd);

        $terms = wp_get_object_terms($parent_id, 'simple-events-cat', array('fields' => 'ids'));
        if (!is_wp_error($terms) && !empty($terms)) {
            wp_set_object_terms($child_id, $terms, 'simple-events-cat');
        }

        update_post_meta($child_id, self::META_PARENT, (int) $parent_id);
        update_post_meta($child_id, self::META_INDEX, (int) $index);

        // Mark every overlay key as a local override so that a later
        // "Entire series" edit respects the explicit future-scoped value
        // instead of blowing it away. Without this, the segment-applied
        // value would survive ONLY until the next series-scope edit.
        if (!empty($segment_overlay)) {
            update_post_meta($child_id, self::META_OVERRIDES, array_values(array_keys($segment_overlay)));
        }

        return $child_id;
    }

    /**
     * Build a current snapshot of the parent's copyable field values, used
     * both to seed new children and to propagate parent edits to existing
     * children during regeneration.
     *
     * @param int     $parent_id
     * @param WP_Post $parent
     * @return array<string,string>
     */
    private function snapshot_parent_field_values($parent_id, $parent)
    {
        return array(
            'post_title'       => (string) $parent->post_title,
            'post_content'     => (string) $parent->post_content,
            'post_excerpt'     => (string) $parent->post_excerpt,
            '_thumbnail_id'    => (string) get_post_meta($parent_id, '_thumbnail_id', true),
            'event_start_time' => (string) get_post_meta($parent_id, 'event_start_time', true),
            'event_end_time'   => (string) get_post_meta($parent_id, 'event_end_time', true),
            'event_location'   => (string) get_post_meta($parent_id, 'event_location', true),
        );
    }

    /**
     * For an existing child, copy each copyable field from the parent's
     * snapshotted values UNLESS the child has overridden that field
     * locally. Returns true if anything changed.
     *
     * @param int   $child_id
     * @param array $parent_values
     * @param array $copyable
     * @param array $overrides
     * @return bool
     */
    private function propagate_parent_fields_to_child($child_id, array $parent_values, array $copyable, array $overrides)
    {
        $changed = false;

        foreach ($copyable as $key) {
            if (!array_key_exists($key, $parent_values)) {
                continue;
            }
            if (in_array($key, $overrides, true)) {
                continue;
            }

            $parent_value = $parent_values[$key];

            if (in_array($key, array('post_title', 'post_content', 'post_excerpt'), true)) {
                $current = (string) get_post_field($key, $child_id);
            } else {
                $current = (string) get_post_meta($child_id, $key, true);
            }

            if ($current === $parent_value) {
                continue;
            }

            $this->write_field_to_post($child_id, $key, $parent_value);
            $changed = true;
        }

        return $changed;
    }

    private function get_copyable_field_keys()
    {
        $default = array(
            'post_title',
            'post_content',
            'post_excerpt',
            '_thumbnail_id',
            'event_start_time',
            'event_end_time',
            'event_location',
        );

        $filtered = apply_filters('sec_recur_copyable_field_keys', $default);
        return is_array($filtered) && !empty($filtered) ? $filtered : $default;
    }

    // ---------------------------------------------------------------------
    // Recursion guard
    // ---------------------------------------------------------------------

    private static function is_generating()
    {
        return !empty($GLOBALS['sec_generating_series']);
    }

    private static function lock_generation($parent_id)
    {
        $GLOBALS['sec_generating_series'] = (int) $parent_id;
    }

    private static function unlock_generation()
    {
        unset($GLOBALS['sec_generating_series']);
    }
}
