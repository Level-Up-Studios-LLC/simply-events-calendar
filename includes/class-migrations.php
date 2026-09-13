<?php

/**
 * One-time data migrations for Simply Events Calendar.
 *
 * Runs guarded, idempotent upgrade routines keyed off a stored DB-version
 * option, so each migration runs at most once regardless of how the plugin was
 * updated (manual, auto-update, etc.).
 *
 * Migration 1 (v5.1.0): older versions of this plugin registered the custom
 * post type as `events` and the taxonomy as `events-cat`; v5.x uses
 * `simple-events` / `simple-events-cat`. Without this, events created on the
 * old version would be orphaned (invisible to the new queries) after upgrade.
 * Event field meta (`event_date` Ymd, location, etc.) is unchanged and needs
 * no migration; legacy `H:i:s` time values are handled by tolerant parsing in
 * `simple_events_parse_time_of_day()`, not rewritten here.
 *
 * @package Simple_Events_Calendar
 * @since 5.1.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Simple_Events_Migrations class
 */
class Simple_Events_Migrations {

    /**
     * Option storing the highest migration version that has run.
     */
    const OPTION = 'simple_events_db_version';

    /**
     * Current target migration version. Bump when adding a migration step.
     */
    const CURRENT_VERSION = 1;

    /**
     * Legacy and current post type / taxonomy slugs.
     */
    const LEGACY_POST_TYPE = 'events';
    const POST_TYPE        = 'simple-events';
    const LEGACY_TAXONOMY  = 'events-cat';
    const TAXONOMY         = 'simple-events-cat';

    /**
     * Constructor.
     */
    public function __construct() {
        // Early on init (before the CPT registers at priority 10) and guarded by
        // a version option, so it runs once then becomes a cheap no-op.
        add_action('init', array($this, 'maybe_migrate'), 1);

        // WXR import compatibility: the WordPress Importer rejects unknown post
        // types ("Invalid post type events") before inserting, so the in-DB
        // migration above can't help an import of an old export. Remap each
        // item to the current slugs as it is read. Only fires during an import.
        add_filter('wp_import_post_data_raw', array($this, 'remap_imported_post'));
    }

    /**
     * Remap a WXR item from the legacy `events` / `events-cat` slugs to the
     * current ones as the WordPress Importer reads it, so old exports import
     * directly as `simple-events` with `simple-events-cat` categories.
     *
     * @param array $post Raw post data from the importer.
     * @return array
     */
    public function remap_imported_post($post) {
        if (isset($post['post_type']) && self::LEGACY_POST_TYPE === $post['post_type']) {
            $post['post_type'] = self::POST_TYPE;
        }

        if (!empty($post['terms']) && is_array($post['terms'])) {
            foreach ($post['terms'] as &$term) {
                if (isset($term['domain']) && self::LEGACY_TAXONOMY === $term['domain']) {
                    $term['domain'] = self::TAXONOMY;
                }
            }
            unset($term);
        }

        return $post;
    }

    /**
     * Run any pending migrations, then record the new DB version.
     */
    public function maybe_migrate() {
        $installed = (int) get_option(self::OPTION, 0);
        if ($installed >= self::CURRENT_VERSION) {
            return;
        }

        if ($installed < 1) {
            $this->migrate_legacy_slugs();
        }

        update_option(self::OPTION, self::CURRENT_VERSION);
    }

    /**
     * Migration 1: rename the legacy `events` CPT and `events-cat` taxonomy to
     * the current `simple-events` / `simple-events-cat` slugs.
     */
    private function migrate_legacy_slugs() {
        global $wpdb;

        $changed = false;

        // Posts: only those that carry our `event_date` meta, so a different
        // plugin's unrelated `events` post type can't be swept up.
        $posts = $wpdb->query(
            "UPDATE {$wpdb->posts} p
             SET p.post_type = '" . self::POST_TYPE . "'
             WHERE p.post_type = '" . self::LEGACY_POST_TYPE . "'
               AND EXISTS (
                   SELECT 1 FROM {$wpdb->postmeta} m
                   WHERE m.post_id = p.ID AND m.meta_key = 'event_date'
               )"
        );
        if ($posts) {
            $changed = true;
        }

        // Taxonomy: term relationships key off term_taxonomy_id (unchanged), so
        // renaming the taxonomy here preserves every event↔category assignment.
        $terms = $wpdb->update(
            $wpdb->term_taxonomy,
            array('taxonomy' => self::TAXONOMY),
            array('taxonomy' => self::LEGACY_TAXONOMY)
        );
        if ($terms) {
            $changed = true;
        }

        if (!$changed) {
            return;
        }

        // Stale hierarchy cache for the old taxonomy, then drop all object cache
        // so the renamed posts/terms aren't served from a stale entry.
        delete_option(self::LEGACY_TAXONOMY . '_children');
        wp_cache_flush();

        if (function_exists('simple_events_debug_log')) {
            simple_events_debug_log('Migrated legacy events slugs', array(
                'posts' => (int) $posts,
                'terms' => (int) $terms,
            ));
        }

        // Refresh rewrite rules once the CPT/taxonomy are registered this request.
        add_action('wp_loaded', 'flush_rewrite_rules');
    }
}
