<?php

/**
 * Default single event template.
 *
 * Theme-overridable: copy to your theme as single-simple-events.php.
 * schema.org Event JSON-LD is emitted on wp_head (see Simple_Events_Post_Type).
 *
 * Layout: featured image + content on the left, a sticky "Event Details" card
 * (date / time / location / categories + Add to Calendar) on the right.
 *
 * @package Simple_Events_Calendar
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header();
?>

<main id="primary" class="simple-events-single">
    <?php
    while (have_posts()) :
        the_post();
        $sec_id = get_the_ID();

        $sec_date     = simple_events_get_event_date($sec_id);
        $sec_start    = simple_events_get_event_time($sec_id, 'event_start_time');
        $sec_end      = simple_events_get_event_time($sec_id, 'event_end_time');
        $sec_time     = ('' !== $sec_end) ? $sec_start . ' – ' . $sec_end : $sec_start;
        $sec_location = (string) get_post_meta($sec_id, 'event_location', true);
        $sec_terms    = get_the_terms($sec_id, 'simple-events-cat');
        $sec_archive  = get_post_type_archive_link('simple-events');
        if (!$sec_archive) {
            $sec_archive = home_url('/');
        }
        ?>
        <article <?php post_class('simple-events-single__event'); ?>>

            <a class="simple-events-single__back" href="<?php echo esc_url($sec_archive); ?>">
                <svg class="simple-events-single__back-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 18l-6-6 6-6" /></svg>
                <?php esc_html_e('All Events', 'simply-events-calendar'); ?>
            </a>

            <h1 class="simple-events-single__title"><?php the_title(); ?></h1>

            <div class="simple-events-single__layout">

                <?php echo Simple_Events_Renderer::image($sec_id, array('size' => 'large', 'class' => 'simple-events-single__image')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

                <aside class="simple-events-single__details">
                    <div class="simple-events-single__card">
                        <p class="simple-events-single__card-title"><?php esc_html_e('Event Details', 'simply-events-calendar'); ?></p>

                        <?php if ('' !== $sec_date) : ?>
                            <div class="simple-events-single__row">
                                <svg class="simple-events-single__row-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" /><path d="M16 2v4M8 2v4M3 10h18" /></svg>
                                <div>
                                    <span class="simple-events-single__row-label"><?php esc_html_e('Date', 'simply-events-calendar'); ?></span>
                                    <span class="simple-events-single__row-value"><?php echo esc_html($sec_date); ?></span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ('' !== $sec_start) : ?>
                            <div class="simple-events-single__row">
                                <svg class="simple-events-single__row-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></svg>
                                <div>
                                    <span class="simple-events-single__row-label"><?php esc_html_e('Time', 'simply-events-calendar'); ?></span>
                                    <span class="simple-events-single__row-value"><?php echo esc_html($sec_time); ?></span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ('' !== $sec_location) : ?>
                            <div class="simple-events-single__row">
                                <svg class="simple-events-single__row-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 21s7-7.5 7-12a7 7 0 1 0-14 0c0 4.5 7 12 7 12z" /><circle cx="12" cy="9" r="2.5" /></svg>
                                <div>
                                    <span class="simple-events-single__row-label"><?php esc_html_e('Location', 'simply-events-calendar'); ?></span>
                                    <span class="simple-events-single__row-value"><?php echo esc_html($sec_location); ?></span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($sec_terms) && !is_wp_error($sec_terms)) : ?>
                            <div class="simple-events-single__row">
                                <svg class="simple-events-single__row-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 7h18M3 12h18M3 17h18" /></svg>
                                <div>
                                    <span class="simple-events-single__row-label"><?php esc_html_e('Categories', 'simply-events-calendar'); ?></span>
                                    <span class="simple-events-single__pills">
                                        <?php
                                        foreach ($sec_terms as $sec_term) {
                                            $sec_term_link = get_term_link($sec_term);
                                            if (is_wp_error($sec_term_link)) {
                                                continue;
                                            }
                                            printf(
                                                '<a class="simple-events-single__pill" href="%s">%s</a>',
                                                esc_url($sec_term_link),
                                                esc_html($sec_term->name)
                                            );
                                        }
                                        ?>
                                    </span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <a class="simple-events-single__add-to-cal" href="<?php echo esc_url(Simple_Events_ICS::url($sec_id)); ?>">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" /><path d="M16 2v4M8 2v4M3 10h18M12 14v4M10 16h4" /></svg>
                            <?php esc_html_e('Add to Calendar', 'simply-events-calendar'); ?>
                        </a>
                    </div>
                </aside>

                <div class="simple-events-single__content">
                    <?php the_content(); ?>
                </div>

            </div>

        </article>
    <?php endwhile; ?>
</main>

<?php
get_footer();
