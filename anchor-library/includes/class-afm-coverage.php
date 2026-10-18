<?php
if (!defined('ABSPATH') && !defined('AFM_TEST')) {
    if (php_sapi_name() !== 'cli') { exit; }
}

/**
 * Which seconds of a video a user has actually played, as a bitset.
 *
 * One bit per second: bit N set means second N was played. Marking the same
 * range twice sets the same bits, so re-watching contributes nothing — the
 * "you can't count those seconds again" rule is a property of the structure
 * rather than logic layered on top.
 *
 * Ranges are HALF-OPEN [from, to): mark(0, 5) covers seconds 0,1,2,3,4. That
 * is what makes two disjoint five-second stretches total exactly ten, and
 * what stops 0-5 followed by 5-10 from counting second 5 twice.
 *
 * Size is bounded by duration no matter how erratically someone scrubs:
 * ceil(duration / 8) bytes. A 15-minute video is 113 bytes.
 */
class Anchor_FM_Coverage {

    /** Longest video we will track, in seconds. Matches RESUME_MAX_SECONDS. */
    const MAX_SECONDS = 86400;

    /**
     * Set the bits for [from, to). Out-of-range input is clamped; a reversed
     * or empty range leaves the bitset untouched.
     *
     * @param string $bits existing bitset (binary string; '' for none)
     * @return string the updated bitset
     */
    public static function mark($bits, $from, $to) {
        $bits = is_string($bits) ? $bits : '';
        $from = (int) $from;
        $to   = (int) $to;

        if ($from < 0) $from = 0;
        if ($to > self::MAX_SECONDS) $to = self::MAX_SECONDS;
        if ($to <= $from) return $bits;

        $needed = intdiv($to - 1, 8) + 1;
        if (strlen($bits) < $needed) {
            $bits = str_pad($bits, $needed, "\0");
        }

        for ($n = $from; $n < $to; $n++) {
            $i = intdiv($n, 8);
            $bits[$i] = chr(ord($bits[$i]) | (1 << ($n % 8)));
        }
        return $bits;
    }

    /**
     * Apply a list of [from, to] pairs. Anything that is not a pair of
     * numbers is skipped rather than trusted — this input arrives from the
     * browser.
     *
     * @param mixed $segments expected array of [from, to]
     */
    public static function mark_segments($bits, $segments) {
        $bits = is_string($bits) ? $bits : '';
        if (!is_array($segments)) return $bits;

        foreach ($segments as $seg) {
            if (!is_array($seg) || count($seg) < 2) continue;
            if (!isset($seg[0]) || !isset($seg[1])) continue;
            if (!is_numeric($seg[0]) || !is_numeric($seg[1])) continue;
            $bits = self::mark($bits, (int) $seg[0], (int) $seg[1]);
        }
        return $bits;
    }

    /** How many distinct seconds are covered. */
    public static function count_set($bits) {
        if (!is_string($bits) || $bits === '') return 0;

        $count = 0;
        $len = strlen($bits);
        for ($i = 0; $i < $len; $i++) {
            $b = ord($bits[$i]);
            // Brian Kernighan: clears the lowest set bit each pass.
            while ($b) { $b &= $b - 1; $count++; }
        }
        return $count;
    }

    /** Whole-percent coverage, 0-100. Unknown duration yields 0, not a divide by zero. */
    public static function percent($covered_seconds, $duration_seconds) {
        $covered  = (int) $covered_seconds;
        $duration = (int) $duration_seconds;
        if ($duration <= 0 || $covered <= 0) return 0;

        $pct = (int) floor(($covered / $duration) * 100);
        if ($pct > 100) return 100;
        if ($pct < 0) return 0;
        return $pct;
    }
}
