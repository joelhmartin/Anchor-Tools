<?php
// Standalone logic test for Anchor_UP_Schedule.
// Run: php tests/up-schedule-test.php
// No WordPress needed — the class is pure and takes its timezone as a parameter.

define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../anchor-universal-popups/includes/class-up-schedule.php';

$fail = 0;
function check( $cond, $msg ) {
    global $fail;
    if ( $cond ) { echo "PASS: $msg\n"; } else { echo "FAIL: $msg\n"; $fail++; }
}

$utc     = new DateTimeZone( 'UTC' );
$chicago = new DateTimeZone( 'America/Chicago' );
$DAY     = 86400;
$NOW     = 1000000;   // arbitrary fixed "now"

// --- sanitize_local -------------------------------------------------------
check( Anchor_UP_Schedule::sanitize_local( '' ) === '', 'empty string stays empty' );
check( Anchor_UP_Schedule::sanitize_local( '   ' ) === '', 'whitespace stays empty' );
check( Anchor_UP_Schedule::sanitize_local( '2026-07-15T09:00' ) === '2026-07-15T09:00', 'minute precision round-trips' );
check( Anchor_UP_Schedule::sanitize_local( '2026-07-15T09:00:30' ) === '2026-07-15T09:00', 'seconds are truncated' );
check( Anchor_UP_Schedule::sanitize_local( 'garbage' ) === '', 'garbage rejected' );
check( Anchor_UP_Schedule::sanitize_local( '2026-13-45T99:99' ) === '', 'impossible date rejected' );
check( Anchor_UP_Schedule::sanitize_local( '<script>' ) === '', 'markup rejected' );

// --- to_epoch -------------------------------------------------------------
check( Anchor_UP_Schedule::to_epoch( '', $utc ) === null, 'empty -> null' );
check( Anchor_UP_Schedule::to_epoch( 'garbage', $utc ) === null, 'garbage -> null' );
check( Anchor_UP_Schedule::to_epoch( '1970-01-01T00:00', $utc ) === 0, 'epoch zero parses' );
check( Anchor_UP_Schedule::to_epoch( '2026-07-15T09:00', $utc ) === 1784106000, 'known UTC epoch' );

// Seconds must be zeroed, not inherited from the current clock.
check( Anchor_UP_Schedule::to_epoch( '2026-07-15T09:00', $utc ) % 60 === 0, 'seconds zeroed' );

// --- DST correctness ------------------------------------------------------
// US spring-forward is 2026-03-08. 09:00 local on Mar 7 -> 09:00 local on Mar 8
// is only 23 real hours.
$mar7 = Anchor_UP_Schedule::to_epoch( '2026-03-07T09:00', $chicago );
$mar8 = Anchor_UP_Schedule::to_epoch( '2026-03-08T09:00', $chicago );
check( $mar8 - $mar7 === 23 * 3600, 'spring-forward day is 23h' );

// US fall-back is 2026-11-01. Oct 31 09:00 -> Nov 1 09:00 is 25 real hours.
$oct31 = Anchor_UP_Schedule::to_epoch( '2026-10-31T09:00', $chicago );
$nov1  = Anchor_UP_Schedule::to_epoch( '2026-11-01T09:00', $chicago );
check( $nov1 - $oct31 === 25 * 3600, 'fall-back day is 25h' );

// --- state ----------------------------------------------------------------
check( Anchor_UP_Schedule::state( null, null, $NOW ) === 'unscheduled', 'no bounds -> unscheduled' );
check( Anchor_UP_Schedule::state( $NOW + 10, null, $NOW ) === 'pending', 'before start -> pending' );
check( Anchor_UP_Schedule::state( $NOW - 10, null, $NOW ) === 'active', 'after open-ended start -> active' );
check( Anchor_UP_Schedule::state( null, $NOW + 10, $NOW ) === 'active', 'before end, no start -> active' );
check( Anchor_UP_Schedule::state( null, $NOW - 10, $NOW ) === 'expired', 'after end -> expired' );
check( Anchor_UP_Schedule::state( $NOW - 10, $NOW + 10, $NOW ) === 'active', 'inside both bounds -> active' );
check( Anchor_UP_Schedule::state( $NOW + 10, $NOW + 20, $NOW ) === 'pending', 'before both bounds -> pending' );
check( Anchor_UP_Schedule::state( $NOW - 20, $NOW - 10, $NOW ) === 'expired', 'after both bounds -> expired' );

// Boundaries: start is inclusive, end is exclusive.
check( Anchor_UP_Schedule::state( $NOW, null, $NOW ) === 'active', 'exactly at start -> active' );
check( Anchor_UP_Schedule::state( null, $NOW, $NOW ) === 'expired', 'exactly at end -> expired' );

// invalid wins over expired.
check( Anchor_UP_Schedule::state( $NOW + 10, $NOW - 10, $NOW ) === 'invalid', 'reversed range -> invalid' );
check( Anchor_UP_Schedule::state( $NOW, $NOW, $NOW ) === 'invalid', 'zero-length range -> invalid' );
check( Anchor_UP_Schedule::state( 500, 100, 9999 ) === 'invalid', 'reversed range not mistaken for expired' );

// --- is_active ------------------------------------------------------------
check( Anchor_UP_Schedule::is_active( null, null, $NOW ) === true, 'unscheduled is active' );
check( Anchor_UP_Schedule::is_active( $NOW - 10, $NOW + 10, $NOW ) === true, 'in-window is active' );
check( Anchor_UP_Schedule::is_active( $NOW + 10, null, $NOW ) === false, 'pending is not active' );
check( Anchor_UP_Schedule::is_active( null, $NOW - 10, $NOW ) === false, 'expired is not active' );
check( Anchor_UP_Schedule::is_active( $NOW + 10, $NOW - 10, $NOW ) === false, 'invalid is not active' );

// --- should_ship (the cache envelope) -------------------------------------
check( Anchor_UP_Schedule::should_ship( null, null, $NOW, $DAY ) === true, 'unscheduled always ships' );
check( Anchor_UP_Schedule::should_ship( $NOW - 10, $NOW + 10, $NOW, $DAY ) === true, 'active ships' );
check( Anchor_UP_Schedule::should_ship( $NOW + 10, $NOW - 10, $NOW, $DAY ) === false, 'invalid never ships' );

// Recently expired still ships, so a stale cached page can close its window.
check( Anchor_UP_Schedule::should_ship( null, $NOW - 10, $NOW, $DAY ) === true, 'just-expired still ships' );
check( Anchor_UP_Schedule::should_ship( null, $NOW - $DAY, $NOW, $DAY ) === true, 'expired exactly at grace ships' );
check( Anchor_UP_Schedule::should_ship( null, $NOW - $DAY - 1, $NOW, $DAY ) === false, 'expired one second past grace does not ship' );

// Near-future still ships, so a stale cached page can open its window.
check( Anchor_UP_Schedule::should_ship( $NOW + 10, null, $NOW, $DAY ) === true, 'imminent start ships' );
check( Anchor_UP_Schedule::should_ship( $NOW + $DAY, null, $NOW, $DAY ) === true, 'start exactly at grace ships' );
check( Anchor_UP_Schedule::should_ship( $NOW + $DAY + 1, null, $NOW, $DAY ) === false, 'start one second beyond grace does not ship' );

// --- property: the ship-set is a strict superset of the fire-set -----------
// Anything active must ship, at every grace value. If this ever fails, a live
// popup could be dropped from the payload and silently never render.
$violations = 0;
foreach ( [ 0, 1, 3600, 86400 ] as $g ) {
    foreach ( [ null, 100, 500, 900 ] as $s ) {
        foreach ( [ null, 100, 500, 900 ] as $e ) {
            foreach ( [ 0, 99, 100, 101, 499, 500, 501, 899, 900, 901, 5000 ] as $n ) {
                if ( Anchor_UP_Schedule::is_active( $s, $e, $n )
                    && ! Anchor_UP_Schedule::should_ship( $s, $e, $n, $g ) ) {
                    $violations++;
                }
            }
        }
    }
}
check( $violations === 0, 'is_active implies should_ship for all grace values' );

echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
exit( $fail ? 1 : 0 );
