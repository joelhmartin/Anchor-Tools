<?php
/**
 * The tri-state result every dispatch reports back (audit WOO-D14, WOO-D15,
 * REG-D6, REG-D37, REG-D41).
 *
 * A boolean cannot tell the three things apart that a caller has to act on
 * differently, so it kept answering the wrong question:
 *
 *  - `sent`    — the thing actually happened. Mark the gate, log the send.
 *  - `skipped` — nothing happened, ON PURPOSE. An organizer unticked the
 *                email, the seat is already in the status asked for, there is
 *                nothing to describe. NOT a failure: it must never raise a
 *                needs-review flag, never burn a retry attempt, and never be
 *                logged as a send.
 *  - `failed`  — it was attempted and did not work. This is the only state
 *                that flags review and enqueues a retry.
 *
 * Returning `true` for a deliberate switch-off (or for a no-op) reported sends
 * that never happened; returning `false` for one flagged an organizer's own
 * setting as a permanent order defect. One value object, three answers.
 *
 * `reason()` carries the detail for logs and for the few callers that treat two
 * skips differently. The vocabulary in use:
 *
 *   disabled          this email type is switched off for the event
 *   notifications_off the site-wide toggle for this email is off
 *   nothing_to_send   there is nothing to describe (no active seats)
 *   already_sent      the marker says this was emailed about already
 *   no_address        no recipient to send to
 *   seat_gone         the record the send describes no longer exists
 *   invalid_event     the target is not an event
 *   wp_mail           wp_mail() rejected the message
 *   same_status       a status write that asked for the status already held
 *   invalid_status    an unknown status value
 *   illegal_transition the transition table forbids from → to
 *   not_a_seat        the id is not a registration post
 *
 * @package Anchor\Events
 */

namespace Anchor\Events;

if ( ! \defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Immutable {status, reason} pair. Build one with sent()/skipped()/failed().
 */
final class Outcome {

    const SENT    = 'sent';
    const SKIPPED = 'skipped';
    const FAILED  = 'failed';

    /** @var string One of SENT|SKIPPED|FAILED. */
    private $status;

    /** @var string Machine-readable detail; '' when there is nothing to add. */
    private $reason;

    private function __construct( $status, $reason ) {
        $this->status = (string) $status;
        $this->reason = (string) $reason;
    }

    /** The action happened. */
    public static function sent( $reason = '' ) {
        return new self( self::SENT, $reason );
    }

    /** The action was deliberately not performed. Never a defect. */
    public static function skipped( $reason = '' ) {
        return new self( self::SKIPPED, $reason );
    }

    /** The action was attempted and did not work. */
    public static function failed( $reason = '' ) {
        return new self( self::FAILED, $reason );
    }

    /**
     * Lift a bare boolean (e.g. wp_mail()'s answer) into the tri-state. Only
     * for values that genuinely mean "worked / did not work" — never for one
     * that folds a skip into either side.
     *
     * @param bool   $ok
     * @param string $failure_reason
     * @return self
     */
    public static function from_bool( $ok, $failure_reason = '' ) {
        return $ok ? self::sent() : self::failed( $failure_reason );
    }

    /** @return string */
    public function status() {
        return $this->status;
    }

    /** @return string */
    public function reason() {
        return $this->reason;
    }

    /** @return bool */
    public function is_sent() {
        return self::SENT === $this->status;
    }

    /** @return bool */
    public function is_skipped() {
        return self::SKIPPED === $this->status;
    }

    /** @return bool */
    public function is_failed() {
        return self::FAILED === $this->status;
    }

    /** `sent`, or `skipped:disabled` — the form that goes in a log line. */
    public function __toString() {
        return '' === $this->reason ? $this->status : $this->status . ':' . $this->reason;
    }
}
