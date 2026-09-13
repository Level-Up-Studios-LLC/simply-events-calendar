<?php

/**
 * "Add to Calendar" iCalendar (.ics) generator for Simply Events Calendar.
 *
 * Streams a standards-compliant .ics file for a single event so visitors can
 * add it to Apple Calendar, Outlook, Google Calendar (import), etc. The button
 * on the single-event template links to Simple_Events_ICS::url($event_id),
 * which is home_url() with a `sec_ical` query var; the request is intercepted
 * on template_redirect, the file is streamed, and execution stops.
 *
 * @package Simple_Events_Calendar
 * @since 5.1.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Simple_Events_ICS class
 */
class Simple_Events_ICS {

    /**
     * Query var that triggers an .ics download.
     */
    const QUERY_VAR = 'sec_ical';

    /**
     * Constructor.
     */
    public function __construct() {
        add_action('template_redirect', array($this, 'maybe_output'));
    }

    /**
     * Build the download URL for an event's .ics file.
     *
     * @param int $event_id Event post ID.
     * @return string
     */
    public static function url($event_id) {
        return add_query_arg(self::QUERY_VAR, (int) $event_id, home_url('/'));
    }

    /**
     * Intercept a `?sec_ical=<id>` request and stream the .ics file.
     */
    public function maybe_output() {
        if (!isset($_GET[self::QUERY_VAR])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        $event_id = absint($_GET[self::QUERY_VAR]); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!$event_id) {
            return;
        }

        $event = get_post($event_id);
        if (!$event || 'simple-events' !== $event->post_type || 'publish' !== $event->post_status) {
            return;
        }

        // Honor WordPress password protection — never stream a protected
        // event's title/location/description via the .ics download.
        if (post_password_required($event)) {
            return;
        }

        if (!$this->output_ics($event_id)) {
            // No valid calendar data (e.g. unparseable event_date) — don't emit
            // a bogus file; let WordPress serve the request normally.
            return;
        }
        exit;
    }

    /**
     * Send headers and echo the .ics document for an event.
     *
     * @param int $event_id Event post ID.
     * @return bool True if a calendar file was streamed, false if there was
     *              nothing valid to output.
     */
    private function output_ics($event_id) {
        $ics = $this->build_ics($event_id);
        if ('' === $ics) {
            return false;
        }

        nocache_headers();
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="event-' . $event_id . '.ics"');

        echo $ics; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        return true;
    }

    /**
     * Build the VCALENDAR/VEVENT document for an event.
     *
     * @param int $event_id Event post ID.
     * @return string
     */
    private function build_ics($event_id) {
        $tz       = wp_timezone();
        $utc      = new DateTimeZone('UTC');
        $date_raw = (string) get_post_meta($event_id, 'event_date', true);        // Ymd
        $start_raw = (string) get_post_meta($event_id, 'event_start_time', true); // g:i a (or legacy H:i:s)
        $end_raw   = (string) get_post_meta($event_id, 'event_end_time', true);   // g:i a (or legacy H:i:s)

        // A valid event_date is required; refuse rather than emit a bogus
        // "today" event. The caller skips streaming when this returns ''.
        $date = DateTimeImmutable::createFromFormat('!Ymd', $date_raw, $tz);
        if (!$date instanceof DateTimeImmutable) {
            return '';
        }

        $dtstart_line = '';
        $dtend_line   = '';

        // Tolerant time parsing (g:i a native, plus legacy H:i:s / H:i imports).
        $start_time = ('' !== $start_raw) ? simple_events_parse_time_of_day($start_raw, $tz) : false;

        if ($start_time instanceof DateTimeImmutable) {
            // Timed event — emit UTC instants.
            $start = $date->setTime((int) $start_time->format('H'), (int) $start_time->format('i'), (int) $start_time->format('s'));

            $end_time = ('' !== $end_raw) ? simple_events_parse_time_of_day($end_raw, $tz) : false;
            $end      = ($end_time instanceof DateTimeImmutable)
                ? $date->setTime((int) $end_time->format('H'), (int) $end_time->format('i'), (int) $end_time->format('s'))
                : false;
            if (!$end instanceof DateTimeImmutable || $end <= $start) {
                $end = $start->add(new DateInterval('PT1H'));
            }
            $dtstart_line = 'DTSTART:' . $start->setTimezone($utc)->format('Ymd\THis\Z');
            $dtend_line   = 'DTEND:' . $end->setTimezone($utc)->format('Ymd\THis\Z');
        } else {
            // No (parseable) start time — all-day event (DTEND is exclusive).
            $dtstart_line = 'DTSTART;VALUE=DATE:' . $date->format('Ymd');
            $dtend_line   = 'DTEND;VALUE=DATE:' . $date->add(new DateInterval('P1D'))->format('Ymd');
        }

        $summary     = $this->escape_text(get_the_title($event_id));
        $location    = $this->escape_text((string) get_post_meta($event_id, 'event_location', true));
        $description = $this->escape_text(wp_strip_all_tags((string) get_the_excerpt($event_id)));
        $url         = $this->escape_text(get_permalink($event_id));
        $host        = wp_parse_url(home_url(), PHP_URL_HOST);
        $uid         = 'sec-event-' . $event_id . '@' . ($host ? $host : 'simple-events');

        $lines = array(
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Simply Events Calendar//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            $dtstart_line,
            $dtend_line,
            'SUMMARY:' . $summary,
        );

        if ('' !== $location) {
            $lines[] = 'LOCATION:' . $location;
        }
        if ('' !== $description) {
            $lines[] = 'DESCRIPTION:' . $description;
        }
        if ('' !== $url) {
            $lines[] = 'URL:' . $url;
        }

        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        // Fold any content line longer than 75 octets (RFC 5545) so strict
        // clients accept long titles/locations/URLs/descriptions, then join
        // with the required CRLF line endings.
        $lines = array_map(array($this, 'fold_line'), $lines);
        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * Fold a content line to <= 75 octets per line (RFC 5545 section 3.1).
     *
     * Continuation lines begin with a single space. Splitting happens on UTF-8
     * character boundaries so multi-byte characters are never cut in half.
     *
     * @param string $line Unfolded content line.
     * @return string
     */
    private function fold_line($line) {
        if (strlen($line) <= 75) {
            return $line;
        }

        $chunks = array();
        $buffer = '';
        foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) as $char) {
            // Continuation lines reserve one octet for the leading space.
            $cap = empty($chunks) ? 75 : 74;
            if ('' !== $buffer && strlen($buffer . $char) > $cap) {
                $chunks[] = $buffer;
                $buffer   = '';
            }
            $buffer .= $char;
        }
        if ('' !== $buffer) {
            $chunks[] = $buffer;
        }

        return implode("\r\n ", $chunks);
    }

    /**
     * Escape a value for an iCalendar text field (RFC 5545).
     *
     * @param string $text Raw value.
     * @return string
     */
    private function escape_text($text) {
        $text = (string) $text;
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace(array(';', ','), array('\\;', '\\,'), $text);
        $text = str_replace(array("\r\n", "\r", "\n"), '\\n', $text);
        return $text;
    }
}
