<?php
if (!defined('ABSPATH') && !defined('AFM_TEST')) {
    if (php_sapi_name() !== 'cli') { exit; }
}

/**
 * Read/write of per-user playback progress, shared by Vimeo videos and
 * uploaded video files.
 *
 * The table name is injected rather than resolved here, because table() is a
 * private static on the main plugin class. Permission checks stay in the AJAX
 * handlers, which is where the capability helpers live.
 */
class Anchor_FM_Media_Progress {

    const SOURCE_VIMEO = 'vimeo';
    const SOURCE_FILE  = 'file';

    /**
     * Most segments one heartbeat may carry. The browser sends a handful; a
     * hand-rolled POST can send hundreds of thousands (an 8MB body holds
     * roughly 800,000), and mark_segments() walks every one of them.
     */
    const MAX_SEGMENTS_PER_BEAT = 500;

    public static function valid_source($source) {
        return in_array($source, [self::SOURCE_VIMEO, self::SOURCE_FILE], true);
    }

    /**
     * Clamp every segment's end to a known duration.
     *
     * Without this a client can claim it played [0, 86400) of a two-minute
     * video, and total_seconds — which the admin report renders as mm:ss —
     * reads 24:00:00. The pre-coverage code held this invariant with
     * min($total, $duration); the bitset has to hold it at the input instead.
     * Malformed entries are passed through untouched for mark_segments() to
     * reject, so this stays a clamp and not a second validator.
     *
     * @param mixed $segments expected array of [from, to]
     * @param int   $max      known duration in seconds; ignored when <= 0
     */
    public static function clamp_segments($segments, $max) {
        $max = (int) $max;
        if (!is_array($segments) || $max <= 0) return $segments;

        foreach ($segments as $i => $seg) {
            if (!is_array($seg) || !isset($seg[0], $seg[1])) continue;
            if (!is_numeric($seg[0]) || !is_numeric($seg[1])) continue;
            if ((int) $seg[1] > $max) $segments[$i][1] = $max;
        }
        return $segments;
    }

    /**
     * Fold one heartbeat into the user's row, creating it if needed.
     *
     * @param array  $segments [[from, to], ...] half-open second ranges played
     *                         since the last beat
     * @param string $now      MySQL datetime from current_time('mysql')
     * @return bool false only when the underlying $wpdb call returned exactly
     *              false. See the note on the return of the write below.
     */
    public static function record($table, $source, $item_id, $user_id, $segments, $point, $duration, $ended, $is_new_session, $now, $reset = false) {
        global $wpdb;

        $point    = max(0, (int) $point);
        $duration = max(0, (int) $duration);
        $segments = is_array($segments) ? $segments : [];

        // Everything below arrives from the browser, so cap it before it can
        // do damage.
        //
        // duration_seconds is INT(10) UNSIGNED and sticky (it only ever
        // grows): a value past 4294967295 either fails the write in strict
        // mode -- a 500 on every heartbeat for that row, forever -- or
        // saturates and pins the user's percent near 0 with no way back.
        $duration = min($duration, Anchor_FM_Coverage::MAX_SECONDS);
        // Same ceiling for the playhead: it feeds furthest_seconds, which is
        // the same unsigned column.
        $point    = min($point, Anchor_FM_Coverage::MAX_SECONDS);
        // mark_segments() loops the whole list; 200 maximum-length segments
        // already cost ~1s of CPU. Repeated, an unbounded list is a site-wide
        // outage on shared hosting. Real beats carry a handful.
        if (count($segments) > self::MAX_SEGMENTS_PER_BEAT) {
            $segments = array_slice($segments, 0, self::MAX_SEGMENTS_PER_BEAT);
        }

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT furthest_seconds, sessions, watched_bits, duration_seconds FROM {$table}
             WHERE source = %s AND video_id = %d AND user_id = %d",
            $source, $item_id, $user_id
        ), ARRAY_A);

        // Keep the longest duration we have ever been told. A beat sent before
        // metadata loads reports 0, and that must not erase a known duration.
        $known_duration = $existing ? (int) $existing['duration_seconds'] : 0;
        if ($duration > $known_duration) {
            $known_duration = $duration;
        }

        // No segment may claim time past the end of the video.
        $segments = self::clamp_segments($segments, $known_duration);

        $bits = $existing && $existing['watched_bits'] !== null ? (string) $existing['watched_bits'] : '';
        $before = $bits;
        $bits = Anchor_FM_Coverage::mark_segments($bits, $segments);

        // A heartbeat that changed nothing is not a save. This is the shape
        // produced by opening and closing a video before metadata arrives --
        // and by a malformed-but-non-empty segment list, which an
        // empty($segments) test would wave through. Writing it would clobber a
        // real position and percentage with zeros, because resume_point(0, ...)
        // returns 0. "Start over" ($reset) and finishing ($ended) are real
        // events and still write, as does any beat that actually marked a bit.
        if ($point <= 0 && $bits === $before && !$ended && !$reset) {
            return true; // nothing to record, and not a failure
        }

        $covered  = Anchor_FM_Coverage::count_set($bits);
        $percent  = Anchor_FM_Coverage::percent($covered, $known_duration);
        $furthest = Anchor_FM_Watch_Math::furthest_point(
            $existing ? (int) $existing['furthest_seconds'] : 0,
            $point,
            $known_duration
        );
        $resume = Anchor_FM_Watch_Math::resume_point($point, $known_duration, $ended);

        if ($existing) {
            $sessions = (int) $existing['sessions'] + ($is_new_session ? 1 : 0);
            $result = $wpdb->update(
                $table,
                [
                    'furthest_seconds' => $furthest,
                    'total_seconds'    => $covered,
                    'resume_seconds'   => $resume,
                    'percent'          => $percent,
                    'sessions'         => $sessions,
                    'watched_bits'     => $bits,
                    'duration_seconds' => $known_duration,
                    'last_viewed_at'   => $now,
                ],
                ['source' => $source, 'video_id' => $item_id, 'user_id' => $user_id],
                ['%d','%d','%d','%d','%d','%s','%d','%s'],
                ['%s','%d','%d']
            );
        } else {
            $result = $wpdb->insert(
                $table,
                [
                    'source'           => $source,
                    'video_id'         => $item_id,
                    'user_id'          => $user_id,
                    'furthest_seconds' => $furthest,
                    'total_seconds'    => $covered,
                    'resume_seconds'   => $resume,
                    'percent'          => $percent,
                    'sessions'         => 1,
                    'watched_bits'     => $bits,
                    'duration_seconds' => $known_duration,
                    'first_viewed_at'  => $now,
                    'last_viewed_at'   => $now,
                ],
                ['%s','%d','%d','%d','%d','%d','%d','%d','%s','%d','%s','%s']
            );
        }

        // $wpdb->update() returns int 0 when the WHERE matched but no column
        // changed — a routine no-op heartbeat. That 0 is SUCCESS, and must be
        // compared with === false, never a loose/falsy check: 0 == false in
        // PHP, so !$result would wrongly flag every no-op as a failed save.
        return $result !== false;
    }

    /**
     * The user's saved position, or 0 when there is none or it has aged out.
     *
     * The staleness check here — not the cron job — is what actually enforces
     * the 30-day rule. WP-Cron only fires on page loads, and a private portal
     * can sit idle for weeks.
     *
     * @param int $now_ts current_time('timestamp'), matching how rows are written
     */
    public static function read_resume($table, $source, $item_id, $user_id, $now_ts) {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT resume_seconds, last_viewed_at FROM {$table}
             WHERE source = %s AND video_id = %d AND user_id = %d",
            $source, $item_id, $user_id
        ));

        if (!$row) return 0;
        if (Anchor_FM_Watch_Math::is_resume_stale((string) $row->last_viewed_at, $now_ts)) return 0;

        return (int) $row->resume_seconds;
    }
}
