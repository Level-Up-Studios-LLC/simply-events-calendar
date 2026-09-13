<?php

/**
 * Plugin Name: Simply Events Calendar
 * Plugin URI: https://www.levelupstudios.com/simply-events-calendar/
 * Description: A simple, responsive events calendar for WordPress. Easily create one-time or recurring events and display them anywhere with shortcodes, Elementor widgets, and one-click Add to Calendar.
 * Version: 6.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Level Up Studios, LLC
 * Author URI: https://www.levelupstudios.com/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: simply-events-calendar
 * Domain Path: /languages
 * @fs_premium_only /includes/pro/
 *
 * @copyright Copyright (C) 2026 Level Up Studios, LLC
 *
 * Simply Events Calendar is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * Simply Events Calendar is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see https://www.gnu.org/licenses/.
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/*
 * Freemius product credentials. The product ID and public key are not secrets
 * and ship inside the plugin by design. The SECRET key is never placed in this
 * repo — set it in wp-config.php on a sandbox while testing, then remove it.
 */
if (!defined('SIMPLE_EVENTS_FS_ID')) {
    define('SIMPLE_EVENTS_FS_ID', '31777');
}
if (!defined('SIMPLE_EVENTS_FS_PUBLIC_KEY')) {
    define('SIMPLE_EVENTS_FS_PUBLIC_KEY', 'pk_4757bcafb4cc8ca98d6f7b6fdac2c');
}

if (!function_exists('sec_fs')) {
    /**
     * Freemius SDK singleton accessor.
     *
     * @return Freemius
     */
    function sec_fs()
    {
        global $sec_fs;

        if (!isset($sec_fs)) {
            require_once __DIR__ . '/freemius/start.php';

            $sec_fs = fs_dynamic_init(array(
                'id'                  => SIMPLE_EVENTS_FS_ID,
                'slug'                => 'simply-events-calendar',
                'premium_slug'        => 'simply-events-calendar-premium',
                'type'                => 'plugin',
                'public_key'          => SIMPLE_EVENTS_FS_PUBLIC_KEY,
                'is_premium'          => true,
                'premium_suffix'      => '(Pro)',
                'has_premium_version' => true,
                'has_paid_plans'      => true,
                'has_addons'          => false,
                'is_org_compliant'    => true,
                'anonymous_mode'      => true,
                'menu'                => array(
                    'slug'       => 'edit.php?post_type=simple-events',
                    'account'    => true,
                    'pricing'    => false,
                    'contact'    => false,
                    'support'    => false,
                    'first-path' => 'edit.php?post_type=simple-events&page=simple-events-settings',
                ),
            ));
        }

        return $sec_fs;
    }

    sec_fs();
    do_action('sec_fs_loaded');
}

// Define plugin constants
define('SIMPLE_EVENTS_DIR', __DIR__);
define('SIMPLE_EVENTS_URL', untrailingslashit(plugin_dir_url(__FILE__)));
define('SIMPLE_EVENTS_ASSETS', SIMPLE_EVENTS_URL . '/assets');
define('SIMPLE_EVENTS_VERSION', '6.0.0');
define('SIMPLE_EVENTS_PLUGIN_FILE', __FILE__);
define('SIMPLE_EVENTS_NONCE_ACTION', 'load_more_events_nonce');

// Load the main plugin class
require_once SIMPLE_EVENTS_DIR . '/includes/class-main.php';

/**
 * Initialize the plugin
 *
 * @return Simple_Events_Calendar|null
 */
function simple_events_calendar()
{
    return Simple_Events_Calendar::get_instance();
}

// Initialize the plugin
simple_events_calendar();
