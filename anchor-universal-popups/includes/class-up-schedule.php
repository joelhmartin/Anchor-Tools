<?php
/**
 * Pure schedule logic for Anchor Universal Popups.
 *
 * No database access, no global state. The timezone is passed in rather than
 * read from wp_timezone() so every method stays pure and unit-testable with no
 * WordPress bootstrap. See tests/up-schedule-test.php.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_UP_Schedule {

    /** Storage + display format: what <input type="datetime-local"> produces. */
    const FMT = 'Y-m-d\TH:i';

    /** Parse format. The leading "!" zeroes all unspecified fields, including seconds. */
    const FMT_PARSE = '!Y-m-d\TH:i';

    /**
     * Normalize a local datetime string. Returns '' for empty or malformed
     * input, which the rest of the module reads as "unbounded on this side".
     */
    public static function sanitize_local( $raw ) {
        $raw = trim( (string) $raw );
        if ( $raw === '' ) return '';

        // Browsers may or may not include seconds depending on step attribute.
        foreach ( [ '!Y-m-d\TH:i', '!Y-m-d\TH:i:s' ] as $fmt ) {
            $d   = DateTimeImmutable::createFromFormat( $fmt, $raw, new DateTimeZone( 'UTC' ) );
            $err = DateTimeImmutable::getLastErrors();
            $ok  = $d instanceof DateTimeImmutable
                && ( ! $err || ( empty( $err['warning_count'] ) && empty( $err['error_count'] ) ) );
            if ( $ok ) return $d->format( self::FMT );
        }
        return '';
    }

    /** Local wall-clock string -> absolute UTC epoch, or null when unbounded. */
    public static function to_epoch( $local, DateTimeZone $tz ) {
        $local = self::sanitize_local( $local );
        if ( $local === '' ) return null;

        $d = DateTimeImmutable::createFromFormat( self::FMT_PARSE, $local, $tz );
        return $d instanceof DateTimeImmutable ? $d->getTimestamp() : null;
    }

    /**
     * Exactly one of: invalid, unscheduled, pending, expired, active.
     * Order matters — a reversed range must not read as "expired".
     * Start is inclusive, end is exclusive.
     */
    public static function state( $start, $end, $now ) {
        if ( $start !== null && $end !== null && $start >= $end ) return 'invalid';
        if ( $start === null && $end === null )                   return 'unscheduled';
        if ( $start !== null && $now <  $start )                   return 'pending';
        if ( $end   !== null && $now >= $end )                     return 'expired';
        return 'active';
    }

    /** An unscheduled popup is active. */
    public static function is_active( $start, $end, $now ) {
        return in_array( self::state( $start, $end, $now ), [ 'unscheduled', 'active' ], true );
    }

    /**
     * Should this popup be shipped into the UP_SNIPPETS payload?
     *
     * Wider than is_active() on purpose. Popups reach the browser inlined in
     * HTML that may be served from a full-page cache, so the payload must also
     * carry popups whose window is about to open or has just closed — otherwise
     * the JS gate has nothing to reveal or hide. $grace should be >= the max
     * page-cache TTL. Boundaries are inclusive.
     */
    public static function should_ship( $start, $end, $now, $grace ) {
        $state = self::state( $start, $end, $now );
        if ( $state === 'invalid' )     return false;
        if ( $state === 'unscheduled' ) return true;

        if ( $end   !== null && $now   > $end + $grace )   return false; // long dead
        if ( $start !== null && $start > $now + $grace )   return false; // far future
        return true;
    }
}
