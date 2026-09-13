<?php

/**
 * Default event-category archive template.
 *
 * Theme-overridable: copy to your theme as taxonomy-simple-events-cat.php.
 * Reuses the [sec_events] card grid and the "load more" infinite scroll, scoped
 * to the current category so "load more" continues within the same term.
 *
 * @package Simple_Events_Calendar
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header();

$sec_settings = simple_events_get_settings();
$sec_term     = get_queried_object();
$sec_slug     = ($sec_term && isset($sec_term->slug)) ? $sec_term->slug : '';
?>

<main id="primary" class="simple-events-archive simple-events-archive--taxonomy">

    <header class="simple-events-archive__header">
        <h1 class="simple-events-archive__title"><?php single_term_title(); ?></h1>
        <?php
        $sec_desc = term_description();
        if (!empty($sec_desc)) {
            echo '<div class="simple-events-archive__description">' . wp_kses_post($sec_desc) . '</div>';
        }
        ?>
    </header>

    <?php if (have_posts()) : ?>
        <div class="simple-events-calendar"
            data-archive="true"
            data-sec-loadmore="1"
            data-category="<?php echo esc_attr($sec_slug); ?>"
            data-show-past="<?php echo 'yes' === $sec_settings['show_past'] ? 'true' : 'false'; ?>"
            data-order="<?php echo 'DESC' === strtoupper((string) $sec_settings['order']) ? 'DESC' : 'ASC'; ?>"
            data-show-time="<?php echo 'yes' === $sec_settings['show_time'] ? 'true' : 'false'; ?>"
            data-show-excerpt="<?php echo 'yes' === $sec_settings['show_excerpt'] ? 'true' : 'false'; ?>"
            data-show-location="<?php echo 'yes' === $sec_settings['show_location'] ? 'true' : 'false'; ?>"
            data-show-footer="<?php echo 'yes' === $sec_settings['show_footer'] ? 'true' : 'false'; ?>"
            data-offset="<?php echo (int) $GLOBALS['wp_query']->post_count; ?>">
            <?php
            while (have_posts()) :
                the_post();
                simple_events_render_event_card();
            endwhile;
            ?>
        </div>
    <?php else : ?>
        <div class="simple-events-calendar simple-events-no-events">
            <div class="simple-events-empty-state">
                <h3><?php echo esc_html__('No Events Found', 'simply-events-calendar'); ?></h3>
                <p><?php echo esc_html__('No upcoming events scheduled. Check back soon!', 'simply-events-calendar'); ?></p>
            </div>
        </div>
    <?php endif; ?>

</main>

<?php
get_footer();
