<?php
/**
 * Anchor Translate — daily character budget.
 *
 * A backstop, not a cost model. Correct caching keeps real usage far below any
 * sane cap; this exists so that a future regression in the cache layer cannot
 * quietly bill thousands of dollars before anyone notices. The Cloud
 * Translation API charges per character, so characters are the unit we meter.
 *
 * When the cap trips, translated URLs 302 to their English equivalent for the
 * rest of the day. That degrades gracefully: visitors still get the page, and
 * unlike serving English HTML at the /es/ URL it creates no duplicate content,
 * and unlike a 404 it does not invite Google to deindex the translated set.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Translate_Budget {

    const OPTION_KEY = 'anchor_translate_budget';

    /**
     * Steady-state ceiling. Sized against what a cached site actually spends,
     * not against what a cold one costs: an edit re-bills only the sentences
     * that changed (~200 characters, measured), and a new page 2k–12k. 20k/day
     * is ~100 edits or a couple of new pages, and keeps a month inside Google's
     * 500k free tier — so a correctly cached site never pays at all.
     */
    const DEFAULT_LIMIT = 20000;

    /**
     * One-time pool for first translating an entire site, which the daily cap
     * cannot absorb — a full 72-URL warm measured 145,377 characters. Granted
     * when translation is switched on, drawn down only once the daily cap is
     * already spent, and never refilled until translation is toggled again.
     *
     * This is the difference between a spend guard and an obstacle: without it
     * every new site would spend its first week serving redirects instead of
     * Spanish, which reads as a broken feature rather than a working cap.
     */
    const WARM_ALLOWANCE = 300000;

    /**
     * Characters already spent today. Rolls over automatically at UTC midnight.
     */
    public static function spent_today() {
        $state = get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $state ) || ( $state['date'] ?? '' ) !== self::today() ) {
            return 0;
        }
        return (int) ( $state['chars'] ?? 0 );
    }

    public static function limit() {
        $limit = (int) apply_filters( 'anchor_translate_daily_character_limit', self::DEFAULT_LIMIT );
        return $limit > 0 ? $limit : self::DEFAULT_LIMIT;
    }

    /** Characters left in the one-time initial-warm pool. */
    public static function warm_remaining() {
        $state = get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $state ) || ! isset( $state['warm'] ) ) {
            return 0;
        }
        return max( 0, (int) $state['warm'] );
    }

    /**
     * Refill the one-time warm pool. Called when translation is switched on, so
     * standing up a new site works without anyone having to know a cap exists.
     */
    public static function grant_warm_allowance() {
        $state = get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $state ) ) {
            $state = [];
        }
        $state['date'] = $state['date'] ?? self::today();
        $state['warm'] = (int) apply_filters( 'anchor_translate_warm_allowance', self::WARM_ALLOWANCE );
        update_option( self::OPTION_KEY, $state, false );
    }

    /** Today's daily headroom, plus whatever is left of the warm pool. */
    public static function remaining() {
        return max( 0, self::limit() - self::spent_today() ) + self::warm_remaining();
    }

    public static function exceeded() {
        return self::remaining() <= 0;
    }

    /**
     * Add to today's tally. Called immediately before each billable request so
     * the counter is conservative — if the API call then fails we have
     * over-counted, which is the safe direction for a spend guard.
     */
    public static function record( $characters ) {
        $characters = (int) $characters;
        if ( $characters <= 0 ) {
            return;
        }

        $today = self::today();
        $state = get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $state ) ) {
            $state = [];
        }

        // The warm pool is one-time, so it must survive the daily rollover that
        // resets $chars — otherwise a warm spanning midnight would silently
        // refill itself and stop being a ceiling at all.
        $warm = max( 0, (int) ( $state['warm'] ?? 0 ) );

        if ( ( $state['date'] ?? '' ) !== $today ) {
            $state = [ 'date' => $today, 'chars' => 0 ];
        }

        // Spend the daily allowance first; only what overflows draws down the
        // one-time pool, so ordinary days never erode it.
        $limit    = self::limit();
        $chars    = (int) ( $state['chars'] ?? 0 );
        $headroom = max( 0, $limit - $chars );
        $overflow = max( 0, $characters - $headroom );

        $state['date']  = $today;
        $state['chars'] = $chars + min( $characters, $headroom );
        $state['warm']  = max( 0, $warm - $overflow );

        // autoload=false: this is written on translation misses only and is
        // never needed on the hot path of an untranslated pageview.
        update_option( self::OPTION_KEY, $state, false );

        if ( self::exceeded() && class_exists( 'Anchor_Schema_Logger' ) ) {
            Anchor_Schema_Logger::log( 'translate:budget-exceeded', [
                'spent' => $state['chars'],
                'limit' => $limit,
                'warm'  => $state['warm'],
            ] );
        }
    }

    private static function today() {
        return gmdate( 'Y-m-d' );
    }
}
