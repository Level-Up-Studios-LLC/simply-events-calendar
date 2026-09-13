<?php

/**
 * Event Card Template for Simply Events Calendar
 *
 * Outputs an event card for a single event post with improved accessibility
 * and error handling.
 *
 * @param array $post_data An associative array containing the event post data.
 *                        Required keys:
 *                        - title: The title of the event
 *                        - permalink: The permalink of the event
 *                        - date: The date of the event
 *                        Optional keys:
 *                        - thumbnail: The URL of the thumbnail image
 *                        - start_time: The start time of the event
 *                        - end_time: The end time of the event
 *                        - excerpt: The excerpt of the event
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Validate that we have the required post data
if (!isset($post_data) || !is_array($post_data)) {
    return;
}

// Validate required fields
$required_fields = ['title', 'permalink', 'date'];
foreach ($required_fields as $field) {
    if (empty($post_data[$field])) {
        return; // Skip rendering if required data is missing
    }
}

// Sanitize and prepare data
$title = esc_html($post_data['title']);
$permalink = esc_url($post_data['permalink']);
$thumbnail = !empty($post_data['thumbnail']) ? esc_url($post_data['thumbnail']) : '';
$date = esc_html($post_data['date']);
$start_time = !empty($post_data['start_time']) ? esc_html($post_data['start_time']) : '';
$end_time = !empty($post_data['end_time']) ? esc_html($post_data['end_time']) : '';
$excerpt = !empty($post_data['excerpt']) ? esc_html($post_data['excerpt']) : '';
// Accept booleans or the 'yes'/'true'/'1' strings so every producer (shortcode,
// archive helper, AJAX) gates details consistently (mirrors the fallback card).
$sec_as_bool = static function ($value) {
    if (is_bool($value)) {
        return $value;
    }
    return in_array(strtolower((string) $value), array('yes', 'true', '1'), true);
};
$show_time = $sec_as_bool($post_data['show_time'] ?? false);
$show_excerpt = $sec_as_bool($post_data['show_excerpt'] ?? false);
$show_location = $sec_as_bool($post_data['show_location'] ?? false);
$show_footer = $sec_as_bool($post_data['show_footer'] ?? false);

// Generate CSS classes
$css_classes = ['simple-events-calendar__post'];
$css_classes[] = 'post-id-' . get_the_ID();
if ($thumbnail) {
    $css_classes[] = 'has-thumbnail';
}

// Generate time display
$time_display = '';
if ($start_time) {
    $time_display = ' <span class="simple-events-calendar__post__time__separator">|</span> ' . $start_time;
    if ($end_time) {
        $time_display .= ' - ' . $end_time;
    }
}

// Generate structured data for better SEO (shared builder; null when disabled).
$event_schema = function_exists('simple_events_get_event_schema')
    ? simple_events_get_event_schema(get_the_ID())
    : null;

// ISO start used for the <time> element. Computed from stored meta (not the
// display-formatted date) so it is locale/format independent and valid even
// when schema output is disabled.
$start_iso = (is_array($event_schema) && !empty($event_schema['startDate']))
    ? $event_schema['startDate']
    : simple_events_get_event_datetime_iso(get_the_ID(), 'event_start_time');

?>

<article class="<?php echo implode(' ', $css_classes); ?>" itemscope itemtype="https://schema.org/Event">
    <!-- Structured Data -->
    <?php if (is_array($event_schema)) : ?>
        <script type="application/ld+json">
            <?php echo wp_json_encode($event_schema, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
        </script>
    <?php endif; ?>

    <?php if ($thumbnail) : ?>
        <div class="simple-events-calendar__post__thumbnail">
            <a href="<?php echo $permalink; ?>"
                class="simple-events-calendar__post__link"
                <?php /* translators: %s is the event title */ ?>
                aria-label="<?php printf(__('View event: %s', 'simply-events-calendar'), $title); ?>">
                <img src="<?php echo $thumbnail; ?>"
                    <?php /* translators: %s is the event title */ ?>
                    alt="<?php echo esc_attr(sprintf(__('Image for event: %s', 'simply-events-calendar'), $title)); ?>"
                    itemprop="image"
                    loading="lazy"
                    decoding="async" />
            </a>
        </div>
    <?php endif; ?>

    <div class="simple-events-calendar__post__description">

        <header class="simple-events-calendar__post__header">
            <a href="<?php echo $permalink; ?>" itemprop="url" rel="bookmark">
                <h3 class="simple-events-calendar__post__title" itemprop="name">
                    <?php echo $title; ?>
                </h3>
            </a>
        </header>

        <div class="simple-events-calendar__post__meta">
            <time class="simple-events-calendar__post__date"
                datetime="<?php echo esc_attr($start_iso); ?>"
                itemprop="startDate">
                <?php /* translators: %s is the event date, already formatted per the site's date-format setting */ ?>
                <span class="simple-events-calendar__date-text" aria-label="<?php printf(__('Event date: %s', 'simply-events-calendar'), $date); ?>">
                    <?php echo $date; ?>
                </span>
            </time>

            <?php if ($time_display && $show_time) : ?>
                <?php /* translators: %s is the event's start (and, if set, end) time, already formatted per the site's time-format setting */ ?>
                <span class="simple-events-calendar__post__time" aria-label="<?php printf(__('Event time: %s', 'simply-events-calendar'), trim($time_display, ' <span class="simple-events-calendar__post__time__separator">|</span>')); ?>">
                    <?php echo $time_display; ?>
                </span>
            <?php endif; ?>
        </div>

        <?php if ($excerpt && $show_excerpt) : ?>
            <div class="simple-events-calendar__post__excerpt" itemprop="description">
                <p><?php echo $excerpt; ?></p>
            </div>
        <?php endif; ?>

        <!-- Event location if available -->
        <?php
        // Use the value already prepared by the caller rather than re-querying
        // meta here, so all card data flows through the prepared $post_data.
        $location = isset($post_data['location']) ? $post_data['location'] : '';
        if ($location && $show_location) :
        ?>
            <div class="simple-events-calendar__post__location" itemprop="location" itemscope itemtype="https://schema.org/Place">
                <span class="simple-events-calendar__location-label" aria-label="<?php _e('Event location:', 'simply-events-calendar'); ?>">
                    <svg class="simple-events-calendar__location-icon" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z" />
                    </svg>
                </span>
                <span itemprop="name"><?php echo esc_html($location); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($show_footer) : ?>
            <!-- Call to action footer. Inside the description so it sits with the
                 other details (right of the image in list mode), not full-width. -->
            <footer class="simple-events-calendar__post__footer">
                <a href="<?php echo $permalink; ?>"
                    class="simple-events-calendar__read-more"
                    <?php /* translators: %s is the event title */ ?>
                    aria-label="<?php printf(__('Read more about %s', 'simply-events-calendar'), $title); ?>">
                    <?php _e('Learn More', 'simply-events-calendar'); ?>
                    <svg class="simple-events-calendar__arrow-icon" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6-1.41-1.41z" />
                    </svg>
                </a>
            </footer>
        <?php endif; ?>

    </div>

</article>