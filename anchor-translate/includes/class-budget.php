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

    const OPTION_KEY    = 'anchor_translate_budget';
    const DEFAULT_LIMIT = 150000; // characters/day

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

    public static function remaining() {
        return max( 0, self::limit() - self::spent_today() );
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

        if ( ! is_array( $state ) || ( $state['date'] ?? '' ) !== $today ) {
            $state = [ 'date' => $today, 'chars' => 0 ];
        }

        $state['chars'] = (int) ( $state['chars'] ?? 0 ) + $characters;

        // autoload=false: this is written on translation misses only and is
        // never needed on the hot path of an untranslated pageview.
        update_option( self::OPTION_KEY, $state, false );

        if ( $state['chars'] >= self::limit() && class_exists( 'Anchor_Schema_Logger' ) ) {
            Anchor_Schema_Logger::log( 'translate:budget-exceeded', [
                'spent' => $state['chars'],
                'limit' => self::limit(),
            ] );
        }
    }

    private static function today() {
        return gmdate( 'Y-m-d' );
    }
}
