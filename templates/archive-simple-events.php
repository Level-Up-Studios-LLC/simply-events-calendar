<?php

/**
 * Default events archive template.
 *
 * Theme-overridable: copy to your theme as archive-simple-events.php.
 * Reuses the [sec_events] card grid and the "load more" infinite scroll.
 *
 * @package Simple_Events_Calendar
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header();

$sec_settings = simple_events_get_settings();
?>

<main id="primary" class="simple-events-archive">

    <header class="simple-events-archive__header">
        <h1 class="simple-events-archive__title"><?php post_type_archive_title(); ?></h1>
    </header>

    <?php if (have_posts()) : ?>
        <div class="simple-events-calendar"
            data-archive="true"
            data-sec-loadmore="1"
            data-category=""
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
