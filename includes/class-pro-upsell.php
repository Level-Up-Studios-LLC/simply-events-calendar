<?php

/**
 * Pro upsell UI for Simply Events Calendar (free version).
 *
 * Pure admin-only marketing surface: a dismissible CTA banner on the Settings and
 * Documentation pages, an "Available in Pro" preview section (disabled controls with
 * PRO badges) on the Settings page, and an "Upgrade to Pro" submenu + landing page
 * under the Events menu. Writes no plugin data and never affects the front end.
 *
 * @package Simple_Events_Calendar
 * @since 5.2.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Simple_Events_Pro_Upsell class
 */
class Simple_Events_Pro_Upsell {

    /**
     * Upgrade page slug.
     */
    const PAGE = 'simple-events-upgrade';

    /**
     * Per-user meta flag set when the CTA banner is dismissed.
     */
    const DISMISS_META = 'sec_pro_banner_dismissed';

    /**
     * Constructor.
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue'));
        add_action('admin_init', array($this, 'maybe_dismiss_banner'));
    }

    /**
     * Whether the Pro version is installed and active.
     *
     * The free plugin has no knowledge of Pro's internals; the Pro plugin simply
     * hooks this filter (e.g. `add_filter('simple_events_pro_active', '__return_true')`).
     * When it returns true, every upsell surface in this class — banner, locked
     * preview section, Upgrade menu/page, and their styles — is suppressed.
     *
     * @return bool
     */
    public static function is_pro_active() {
        $active = function_exists('sec_fs') && sec_fs()->can_use_premium_code();

        return (bool) apply_filters('simple_events_pro_active', $active);
    }

    /**
     * Purchase / marketing URL for the Pro version.
     *
     * @return string
     */
    public static function pro_url() {
        $url = function_exists('sec_fs')
            ? sec_fs()->get_upgrade_url()
            : 'https://www.levelupstudios.com/simply-events-calendar/';

        return apply_filters('simple_events_pro_url', $url);
    }

    /**
     * Pro feature teasers — the single source of truth for the locked Settings rows
     * and the upgrade page.
     *
     * @return array<int, array{title:string, desc:string}>
     */
    public static function pro_features() {
        $features = array(
            array(
                'title' => __('Configurable event URLs', 'simply-events-calendar'),
                'desc'  => __('Rename the /events/ permalink base to anything you like — or remove it entirely for top-level event URLs.', 'simply-events-calendar'),
            ),
            array(
                'title' => __('Calendar & month view', 'simply-events-calendar'),
                'desc'  => __('Show your events in a full month-grid calendar your visitors can browse, in addition to the list layout.', 'simply-events-calendar'),
            ),
            array(
                'title' => __('Ticketing & RSVPs', 'simply-events-calendar'),
                'desc'  => __('Let visitors RSVP or buy tickets to your events, with attendee tracking and check-in.', 'simply-events-calendar'),
            ),
            array(
                'title' => __('Priority support', 'simply-events-calendar'),
                'desc'  => __('Direct help from the team that builds the plugin, with priority response times.', 'simply-events-calendar'),
            ),
        );

        return apply_filters('simple_events_pro_features', $features);
    }

    /**
     * Register the "Upgrade to Pro" submenu page under the Events menu.
     */
    public function add_menu() {
        if (self::is_pro_active()) {
            return;
        }

        add_submenu_page(
            'edit.php?post_type=simple-events',
            __('Upgrade to Pro', 'simply-events-calendar'),
            __('Upgrade to Pro', 'simply-events-calendar'),
            'manage_options',
            self::PAGE,
            array($this, 'render_upgrade_page')
        );
    }

    /**
     * Enqueue the admin stylesheet on the plugin's own admin screens only.
     *
     * @param string $hook Current admin page hook suffix.
     */
    public function enqueue($hook) {
        if (self::is_pro_active()) {
            return;
        }

        $screens = array(
            'simple-events_page_' . Simple_Events_Settings::PAGE,
            'simple-events_page_' . Simple_Events_Docs::PAGE,
            'simple-events_page_' . self::PAGE,
        );

        if (!in_array($hook, $screens, true)) {
            return;
        }

        wp_enqueue_style(
            'simple-events-admin',
            SIMPLE_EVENTS_ASSETS . '/css/simple-events-admin.css',
            array(),
            SIMPLE_EVENTS_VERSION
        );
    }

    /**
     * Handle the per-user banner dismissal link.
     */
    public function maybe_dismiss_banner() {
        if (!isset($_GET['sec_dismiss_pro'])) {
            return;
        }

        $nonce = isset($_GET['sec_pro_nonce']) ? sanitize_text_field(wp_unslash($_GET['sec_pro_nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'sec_dismiss_pro')) {
            return;
        }

        update_user_meta(get_current_user_id(), self::DISMISS_META, 1);

        wp_safe_redirect(remove_query_arg(array('sec_dismiss_pro', 'sec_pro_nonce')));
        exit;
    }

    /**
     * Echo the dismissible Pro CTA banner. No-op if the current user dismissed it.
     */
    public static function banner() {
        if (self::is_pro_active()) {
            return;
        }

        if (get_user_meta(get_current_user_id(), self::DISMISS_META, true)) {
            return;
        }

        $dismiss_url = wp_nonce_url(
            add_query_arg('sec_dismiss_pro', '1'),
            'sec_dismiss_pro',
            'sec_pro_nonce'
        );
        ?>
        <div class="sec-pro-banner">
            <div class="sec-pro-banner__body">
                <span class="sec-pro-badge"><?php echo esc_html__('PRO', 'simply-events-calendar'); ?></span>
                <p class="sec-pro-banner__text">
                    <strong><?php echo esc_html__('Do more with Simply Events Calendar Pro.', 'simply-events-calendar'); ?></strong>
                    <?php echo esc_html__('Configurable URLs, a month-view calendar, ticketing, and priority support.', 'simply-events-calendar'); ?>
                </p>
            </div>
            <div class="sec-pro-banner__actions">
                <a class="button button-primary" href="<?php echo esc_url(self::pro_url()); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Upgrade to Pro', 'simply-events-calendar'); ?></a>
                <a class="sec-pro-banner__dismiss" href="<?php echo esc_url($dismiss_url); ?>" aria-label="<?php echo esc_attr__('Dismiss this notice', 'simply-events-calendar'); ?>"><?php echo esc_html__('Dismiss', 'simply-events-calendar'); ?></a>
            </div>
        </div>
        <?php
    }

    /**
     * Echo the "Available in Pro" preview section (disabled rows with PRO badges).
     */
    public static function locked_section() {
        if (self::is_pro_active()) {
            return;
        }
        ?>
        <h2 class="sec-pro-locked__heading"><?php echo esc_html__('Available in Pro', 'simply-events-calendar'); ?></h2>
        <p class="description"><?php echo esc_html__('These features are unlocked in Simply Events Calendar Pro.', 'simply-events-calendar'); ?></p>
        <table class="form-table sec-pro-locked" role="presentation">
            <?php foreach (self::pro_features() as $feature) : ?>
                <tr>
                    <th scope="row">
                        <?php echo esc_html($feature['title']); ?>
                        <span class="sec-pro-badge"><?php echo esc_html__('PRO', 'simply-events-calendar'); ?></span>
                    </th>
                    <td>
                        <label class="sec-pro-locked__control">
                            <input type="checkbox" disabled />
                            <?php echo esc_html__('Disabled — upgrade to enable', 'simply-events-calendar'); ?>
                        </label>
                        <p class="description"><?php echo esc_html($feature['desc']); ?></p>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
        <p>
            <a class="button button-primary" href="<?php echo esc_url(self::pro_url()); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('See everything in Pro', 'simply-events-calendar'); ?></a>
        </p>
        <?php
    }

    /**
     * Render the "Upgrade to Pro" landing page.
     */
    public function render_upgrade_page() {
        if (self::is_pro_active() || !current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap sec-pro-upgrade">
            <h1><?php echo esc_html__('Upgrade to Simply Events Calendar Pro', 'simply-events-calendar'); ?></h1>
            <p class="sec-pro-upgrade__intro"><?php echo esc_html__('Everything in the free plugin, plus powerful tools to run events at scale.', 'simply-events-calendar'); ?></p>

            <div class="sec-pro-upgrade__grid">
                <?php foreach (self::pro_features() as $feature) : ?>
                    <div class="sec-pro-upgrade__card">
                        <h2 class="sec-pro-upgrade__card-title">
                            <?php echo esc_html($feature['title']); ?>
                            <span class="sec-pro-badge"><?php echo esc_html__('PRO', 'simply-events-calendar'); ?></span>
                        </h2>
                        <p><?php echo esc_html($feature['desc']); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <p class="sec-pro-upgrade__cta">
                <a class="button button-primary button-hero" href="<?php echo esc_url(self::pro_url()); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Get Simply Events Calendar Pro', 'simply-events-calendar'); ?></a>
            </p>
        </div>
        <?php
    }
}
