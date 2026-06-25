<?php
/**
 * Seat status-transition + anonymize tests (no WooCommerce required).
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Registrations;

/**
 * @group status
 */
class Test_Status_Transitions extends Anchor_Events_TestCase {

	/** A legal transition succeeds, persists the new status, and appends history. */
	public function test_legal_transition_records_history() {
		$event_id = $this->make_event();
		$seat_id  = $this->make_seat( $event_id, [ 'status' => Registrations::STATUS_PENDING ] );
		$reg      = $this->registrations();

		$this->assertTrue(
			$reg->update_status( $seat_id, Registrations::STATUS_CONFIRMED, 'paid', 'tester' )
		);
		$this->assertSame(
			Registrations::STATUS_CONFIRMED,
			get_post_meta( $seat_id, '_anchor_event_reg_status', true )
		);

		$history = get_post_meta( $seat_id, '_anchor_event_history', true );
		$this->assertIsArray( $history );
		$last = end( $history );
		$this->assertSame( Registrations::STATUS_CONFIRMED, $last['status'] );
		$this->assertSame( 'paid', $last['note'] );
		$this->assertSame( 'tester', $last['actor'] );
	}

	/** An illegal transition returns false and leaves the status unchanged. */
	public function test_illegal_transition_rejected() {
		$event_id = $this->make_event();
		$seat_id  = $this->make_seat( $event_id, [ 'status' => Registrations::STATUS_CONFIRMED ] );
		$reg      = $this->registrations();

		// confirmed → pending is not in the transition table.
		$this->assertFalse( $reg->update_status( $seat_id, Registrations::STATUS_PENDING ) );
		$this->assertSame(
			Registrations::STATUS_CONFIRMED,
			get_post_meta( $seat_id, '_anchor_event_reg_status', true )
		);
	}

	/** Refunded is terminal: no transition out of it is allowed. */
	public function test_refunded_is_terminal() {
		$event_id = $this->make_event();
		$seat_id  = $this->make_seat( $event_id, [ 'status' => Registrations::STATUS_CONFIRMED ] );
		$reg      = $this->registrations();

		$this->assertTrue( $reg->update_status( $seat_id, Registrations::STATUS_REFUNDED ) );
		// refunded → confirmed must be rejected.
		$this->assertFalse( $reg->update_status( $seat_id, Registrations::STATUS_CONFIRMED ) );
		$this->assertSame(
			Registrations::STATUS_REFUNDED,
			get_post_meta( $seat_id, '_anchor_event_reg_status', true )
		);
	}

	/** An unknown status value is rejected. */
	public function test_invalid_status_rejected() {
		$event_id = $this->make_event();
		$seat_id  = $this->make_seat( $event_id );
		$this->assertFalse( $this->registrations()->update_status( $seat_id, 'bogus' ) );
	}

	/** anonymize_seat scrubs name/email/phone + custom reg fields, keeps status. */
	public function test_anonymize_seat_scrubs_pii() {
		$event_id = $this->make_event();
		$seat_id  = $this->make_seat(
			$event_id,
			[
				'name'       => 'Jane Doe',
				'email'      => 'jane@example.test',
				'phone'      => '555-1234',
				'status'     => Registrations::STATUS_CONFIRMED,
				'reg_fields' => [ 'company' => 'ACME', 'notes' => 'VIP guest' ],
			]
		);

		$this->assertTrue( $this->registrations()->anonymize_seat( $seat_id ) );

		$this->assertSame( '', get_post_meta( $seat_id, '_anchor_event_email', true ) );
		$this->assertSame( '', get_post_meta( $seat_id, '_anchor_event_phone', true ) );
		$this->assertNotSame( 'Jane Doe', get_post_meta( $seat_id, '_anchor_event_name', true ) );
		$this->assertSame( [], get_post_meta( $seat_id, '_anchor_event_reg_fields', true ) );
		$this->assertNotSame( 'Jane Doe', get_post( $seat_id )->post_title );

		// Status (and thus capacity accounting) is preserved.
		$this->assertSame(
			Registrations::STATUS_CONFIRMED,
			get_post_meta( $seat_id, '_anchor_event_reg_status', true )
		);
	}

	/** seats_by_email finds a seat, anonymize then removes it from the match set. */
	public function test_seats_by_email_then_anonymize() {
		$event_id = $this->make_event();
		$seat_id  = $this->make_seat( $event_id, [ 'email' => 'find-me@example.test' ] );

		$found = $this->registrations()->seats_by_email( 'find-me@example.test' );
		$this->assertContains( $seat_id, $found );

		$this->registrations()->anonymize_seat( $seat_id );
		$this->assertSame( [], $this->registrations()->seats_by_email( 'find-me@example.test' ) );
	}
}
