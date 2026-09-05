<?php
/**
 * handle_registration() must refuse a cancelled or postponed event.
 *
 * The free-registration form has not rendered for either status since
 * bookability() started answering 'closed' for both (THEME-D25 /
 * finding-16), but REG_NONCE is a bare action nonce — not bound to
 * event_id — so a POST can still arrive from a stale page. This guard had
 * no direct test coverage before this file: it existed only for
 * 'cancelled', and finding-16's postponed fix to bookability() and the
 * Roster::handle_add() guard was not mirrored here until Module::
 * status_is_closed() unified all three call sites.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;

/** Thrown from the wp_redirect filter so handle_registration()'s exit never runs. */
class Anchor_Registration_Status_Guard_Redirected extends \Exception {}

/**
 * @group registration
 */
class Test_Registration_Status_Guard extends Anchor_Events_TestCase {

	public function set_up() {
		parent::set_up();
		add_filter( 'wp_redirect', [ $this, 'trap_redirect' ] );
	}

	public function tear_down() {
		remove_filter( 'wp_redirect', [ $this, 'trap_redirect' ] );
		$_POST    = [];
		$_REQUEST = [];
		parent::tear_down();
	}

	public function trap_redirect( $location ) {
		throw new Anchor_Registration_Status_Guard_Redirected( (string) $location );
	}

	/** Post a well-formed internal registration and return where it redirected. */
	private function submit_registration( $event_id ) {
		$_POST = [
			Module::REG_NONCE    => wp_create_nonce( Module::REG_NONCE ),
			'event_id'           => $event_id,
			'redirect_to'        => 'https://example.org/events/',
			'anchor_event_name'  => 'Jane Doe',
			'anchor_event_email' => 'jane@example.org',
		];
		$_REQUEST = $_POST;

		try {
			$this->module()->handle_registration();
		} catch ( Anchor_Registration_Status_Guard_Redirected $e ) {
			return $e->getMessage();
		}

		$this->fail( 'handle_registration() returned without redirecting.' );
	}

	public function test_stale_post_to_a_cancelled_event_is_refused() {
		$event_id = $this->make_event(
			[
				'title'       => 'Cancelled Event',
				'status_mode' => 'manual',
				'status'      => 'cancelled',
			]
		);

		$location = $this->submit_registration( $event_id );

		$this->assertStringContainsString( 'registration_closed', $location );
		$this->assertSame( 0, $this->module()->get_registration_count( $event_id ) );
	}

	/**
	 * finding-16 — the guard used to check only `=== 'cancelled'`, so a
	 * stale-page POST to a postponed event still minted a seat.
	 */
	public function test_stale_post_to_a_postponed_event_is_refused() {
		$event_id = $this->make_event(
			[
				'title'       => 'Postponed Event',
				'status_mode' => 'manual',
				'status'      => 'postponed',
			]
		);

		$location = $this->submit_registration( $event_id );

		$this->assertStringContainsString( 'registration_closed', $location );
		$this->assertSame( 0, $this->module()->get_registration_count( $event_id ) );
	}

	/**
	 * The control. 'moved_online' is deliberately excluded from
	 * status_is_closed() — the event still happens, on the same date, just
	 * virtually — so it must stay bookable through this same guard.
	 */
	public function test_stale_post_to_a_moved_online_event_still_registers() {
		$event_id = $this->make_event(
			[
				'title'       => 'Moved Online Event',
				'status_mode' => 'manual',
				'status'      => 'moved_online',
			]
		);

		$location = $this->submit_registration( $event_id );

		$this->assertStringNotContainsString( 'registration_closed', $location );
		$this->assertSame( 1, $this->module()->get_registration_count( $event_id ) );
	}
}
