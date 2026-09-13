<?php

/**
 * Documentation page for Simply Events Calendar.
 *
 * Adds a read-only "Documentation" screen under the Events menu that lists the
 * available shortcodes (and their attributes) and the Elementor widgets (and
 * where each can be used). Static reference content only — no options.
 *
 * @package Simple_Events_Calendar
 * @since 5.1.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Simple_Events_Docs class
 */
class Simple_Events_Docs {

    /**
     * Documentation page slug.
     */
    const PAGE = 'simple-events-docs';

    /**
     * Constructor.
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_menu'));
    }

    /**
     * Register the Documentation submenu page under the Events menu.
     */
    public function add_menu() {
        add_submenu_page(
            'edit.php?post_type=simple-events',
            __('Simple Events Documentation', 'simply-events-calendar'),
            __('Documentation', 'simply-events-calendar'),
            'edit_posts',
            self::PAGE,
            array($this, 'render_page')
        );
    }

    /**
     * Render a single shortcode reference block.
     *
     * @param string $usage       Example usage (rendered inside <code>, escaped).
     * @param string $description  What the shortcode does.
     * @param array  $atts         Map of attribute name => description.
     * @return void
     */
    private function render_shortcode_block($usage, $description, $atts = array()) {
        ?>
        <div class="sec-docs__item">
            <p class="sec-docs__code"><code><?php echo esc_html($usage); ?></code></p>
            <p class="sec-docs__desc"><?php echo esc_html($description); ?></p>
            <?php if (!empty($atts)) : ?>
                <table class="widefat striped sec-docs__atts">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Attribute', 'simply-events-calendar'); ?></th>
                            <th><?php echo esc_html__('Description', 'simply-events-calendar'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($atts as $name => $desc) : ?>
                            <tr>
                                <td><code><?php echo esc_html($name); ?></code></td>
                                <td><?php echo esc_html($desc); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render the Documentation page.
     */
    public function render_page() {
        if (!current_user_can('edit_posts')) {
            return;
        }
        ?>
        <div class="wrap sec-docs">
            <h1><?php echo esc_html__('Simple Events Documentation', 'simply-events-calendar'); ?></h1>

            <?php Simple_Events_Pro_Upsell::banner(); ?>

            <p class="sec-docs__intro"><?php echo esc_html__('Reference for the shortcodes and Elementor widgets this plugin provides. Use shortcodes in the block/classic editor or widgets; use the Elementor widgets when building with Elementor.', 'simply-events-calendar'); ?></p>

            <h2><?php echo esc_html__('Shortcodes', 'simply-events-calendar'); ?></h2>

            <h3><?php echo esc_html__('Events grid — [sec_events]', 'simply-events-calendar'); ?></h3>
            <?php
            $this->render_shortcode_block(
                '[sec_events posts_per_page="6" category="" order="ASC"]',
                __('Displays a responsive grid of events. Past events are hidden by default and more events load as the visitor scrolls. All attributes are optional and default to your settings on Events → Settings.', 'simply-events-calendar'),
                array(
                    'posts_per_page' => __('How many events to show initially (1–50).', 'simply-events-calendar'),
                    'category'       => __('Limit to one category by its slug. Empty shows all.', 'simply-events-calendar'),
                    'show_past'      => __('"yes" to include past events; "no" (default) shows upcoming only.', 'simply-events-calendar'),
                    'order'          => __('"ASC" (soonest first) or "DESC" (latest first).', 'simply-events-calendar'),
                    'show_time'      => __('"yes"/"no" — show the event time on each card.', 'simply-events-calendar'),
                    'show_excerpt'   => __('"yes"/"no" — show the excerpt on each card.', 'simply-events-calendar'),
                    'show_location'  => __('"yes"/"no" — show the location on each card.', 'simply-events-calendar'),
                    'show_footer'    => __('"yes"/"no" — show the "Learn More" footer link.', 'simply-events-calendar'),
                )
            );
            ?>

            <h3><?php echo esc_html__('Single event — [sec_event]', 'simply-events-calendar'); ?></h3>
            <?php
            $this->render_shortcode_block(
                '[sec_event id="123" layout="card"]',
                __('Displays one specific event by its ID, as a card or an image-left list row. The "id" attribute is required — find the ID in the URL when editing the event (post=123).', 'simply-events-calendar'),
                array(
                    'id'            => __('Required. The event\'s post ID.', 'simply-events-calendar'),
                    'layout'        => __('"card" (default) or "list" (image left, details right).', 'simply-events-calendar'),
                    'show_time'     => __('"yes"/"no" — show the event time.', 'simply-events-calendar'),
                    'show_excerpt'  => __('"yes"/"no" — show the excerpt.', 'simply-events-calendar'),
                    'show_location' => __('"yes"/"no" — show the location.', 'simply-events-calendar'),
                    'show_footer'   => __('"yes"/"no" — show the "Learn More" footer link.', 'simply-events-calendar'),
                )
            );
            ?>

            <h3><?php echo esc_html__('Single element shortcodes', 'simply-events-calendar'); ?></h3>
            <p><?php echo esc_html__('Render one piece of an event — handy for custom layouts. Inside an event (single page or loop) they use the current event automatically; elsewhere, pass an "id" attribute to target a specific event. All of them also accept "class", "before", and "after".', 'simply-events-calendar'); ?></p>
            <?php
            $this->render_shortcode_block(
                '[sec_event_title id="123" tag="h2" link="yes"]',
                __('Available element shortcodes and their main attributes:', 'simply-events-calendar'),
                array(
                    '[sec_event_title]'      => __('Event title. Attributes: tag (h1–h6/span), link (yes/no).', 'simply-events-calendar'),
                    '[sec_event_image]'      => __('Featured image. Attributes: size, link (yes/no).', 'simply-events-calendar'),
                    '[sec_event_date]'       => __('Event date. Attribute: format (PHP date format override).', 'simply-events-calendar'),
                    '[sec_event_time]'       => __('Start (and end) time. Attribute: separator.', 'simply-events-calendar'),
                    '[sec_event_location]'   => __('Location. Attribute: icon (yes/no).', 'simply-events-calendar'),
                    '[sec_event_excerpt]'    => __('Excerpt. Attribute: words (word limit).', 'simply-events-calendar'),
                    '[sec_event_content]'    => __('Full event content.', 'simply-events-calendar'),
                    '[sec_event_categories]' => __('Category links. Attributes: link (yes/no), separator.', 'simply-events-calendar'),
                    '[sec_event_button]'     => __('A link/button to the event. Attribute: text.', 'simply-events-calendar'),
                )
            );
            ?>

            <h2><?php echo esc_html__('Elementor widgets', 'simply-events-calendar'); ?></h2>
            <p><?php echo esc_html__('When Elementor is active, the widgets below appear in the editor under the "Simple Events" category (just below "Basic").', 'simply-events-calendar'); ?></p>

            <h3><?php echo esc_html__('Display widgets — use anywhere', 'simply-events-calendar'); ?></h3>
            <p><?php echo esc_html__('Drag these onto any page, post, or template. They include their own event query, so they do not need an event context.', 'simply-events-calendar'); ?></p>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('Widget', 'simply-events-calendar'); ?></th>
                        <th><?php echo esc_html__('Purpose', 'simply-events-calendar'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong><?php echo esc_html__('Events Grid', 'simply-events-calendar'); ?></strong></td>
                        <td><?php echo esc_html__('A grid (with a configurable column count) or an image-left list of events, with controls for category, order, count, the show/hide toggles, and an optional "Load more on scroll" (infinite scroll).', 'simply-events-calendar'); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php echo esc_html__('Single Event', 'simply-events-calendar'); ?></strong></td>
                        <td><?php echo esc_html__('Displays one event you pick from a searchable list, as a card or a list row.', 'simply-events-calendar'); ?></td>
                    </tr>
                </tbody>
            </table>

            <h3><?php echo esc_html__('Event element widgets — use inside an event context', 'simply-events-calendar'); ?></h3>
            <p><?php echo esc_html__('These render a single piece of "the current event": Event Title, Event Image, Event Date, Event Time, Event Location, Event Excerpt, Event Content, Event Categories, and Event Button.', 'simply-events-calendar'); ?></p>
            <p><?php echo esc_html__('Because they depend on a current event, they only output on the front end when used in an event context, namely:', 'simply-events-calendar'); ?></p>
            <ul class="sec-docs__list">
                <li><?php echo esc_html__('A single-event template (Elementor Theme Builder).', 'simply-events-calendar'); ?></li>
                <li><?php echo esc_html__('An event archive / category template.', 'simply-events-calendar'); ?></li>
                <li><?php echo esc_html__('Inside a Loop Grid whose query is set to events.', 'simply-events-calendar'); ?></li>
            </ul>
            <p><?php echo esc_html__('If you place one on an ordinary page, the Elementor editor previews it with a sample event so you can style it, but it renders nothing on the live page. To show a specific event on an ordinary page, use the Single Event widget or the [sec_event] shortcode instead.', 'simply-events-calendar'); ?></p>

            <h3><?php echo esc_html__('Dynamic Tags', 'simply-events-calendar'); ?></h3>
            <p><?php echo esc_html__('In an event template or Loop Grid, you can also bind a native Elementor widget (Heading, Text, Image, etc.) to an event field via its dynamic-tags picker, under the "Simple Events" group: Event Date, Event Time, Event Location, Event Title, Event Excerpt, Event Categories, and Event Image URL.', 'simply-events-calendar'); ?></p>

            <h3><?php echo esc_html__('Loop Grid — sort by event date', 'simply-events-calendar'); ?></h3>
            <p><?php echo esc_html__('Elementor\'s Loop Grid orders by post date by default, not by the event date. To sort a Loop Grid of events by their event date, set the widget\'s Query ID (Loop Grid → Query → Query ID) to one of these built-in IDs:', 'simply-events-calendar'); ?></p>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('Query ID', 'simply-events-calendar'); ?></th>
                        <th><?php echo esc_html__('Result', 'simply-events-calendar'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>sec_events_by_date</code></td>
                        <td><?php echo esc_html__('Orders by event date and follows the global "Show past events" setting (Events → Settings) — past events are hidden unless that setting is turned on.', 'simply-events-calendar'); ?></td>
                    </tr>
                    <tr>
                        <td><code>sec_events_by_date_all</code></td>
                        <td><?php echo esc_html__('Orders by event date and always includes past events (ignores the global setting).', 'simply-events-calendar'); ?></td>
                    </tr>
                </tbody>
            </table>
            <p><?php echo esc_html__('Set the Loop Grid\'s source to the Events post type, and use its own Order control for ascending (soonest first) or descending. No code snippet needed — the plugin wires these query IDs for you.', 'simply-events-calendar'); ?></p>

            <hr />
            <p>
                <a class="button button-secondary" href="<?php echo esc_url(admin_url('edit.php?post_type=simple-events&page=' . Simple_Events_Settings::PAGE)); ?>"><?php echo esc_html__('Go to Settings', 'simply-events-calendar'); ?></a>
            </p>
        </div>

        <style>
            .sec-docs__intro { font-size: 14px; max-width: 50em; }
            .sec-docs h3 { margin-top: 1.6em; }
            .sec-docs__item { max-width: 60em; margin-bottom: 8px; }
            .sec-docs__code code { display: inline-block; padding: 6px 10px; background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px; font-size: 13px; }
            .sec-docs__desc { max-width: 60em; }
            .sec-docs__atts { max-width: 60em; margin-top: 6px; }
            .sec-docs__atts td code { background: #f6f7f7; padding: 1px 5px; border-radius: 3px; }
            .sec-docs__list { list-style: disc; margin-left: 2em; max-width: 60em; }
        </style>
        <?php
    }
}
