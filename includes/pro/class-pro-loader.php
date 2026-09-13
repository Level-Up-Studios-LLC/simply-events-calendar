<?php

/**
 * Premium feature loader.
 *
 * This file and everything beside it under includes/pro/ is stripped from the
 * free build by the Freemius deployment processor (see the @fs_premium_only
 * tag in the main plugin file). Nothing here may be referenced from free code
 * except through the guarded gate in Simple_Events_Calendar::load_components().
 *
 * @package Simply_Events_Calendar
 * @since 6.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Simple_Events_Pro_Loader class
 */
class Simple_Events_Pro_Loader {

    /**
     * Whether init() has already run.
     *
     * @var bool
     */
    private static $initialized = false;

    /**
     * Instantiate and register every premium feature module.
     *
     * Gated on can_use_premium_code(): an unlicensed premium build takes this
     * path but registers nothing, so it behaves exactly like the free build.
     */
    public static function init() {
        if (self::$initialized) {
            return;
        }

        self::$initialized = true;

        if (!function_exists('sec_fs') || !sec_fs()->can_use_premium_code()) {
            return;
        }

        require_once SIMPLE_EVENTS_DIR . '/includes/pro/features/class-configurable-urls.php';

        $configurable_urls = new Sec_Pro_Configurable_Urls();
        $configurable_urls->register();
    }
}
