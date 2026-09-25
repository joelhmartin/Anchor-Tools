<?php
/**
 * Audit F01 (docs/audits/2026-09-25-events-lms-audit.md): seat-derived event
 * roles are kept or revoked by SEAT-OWNER identity, never by the buyer's
 * WooCommerce customer id, and orphaned seat grants can be reconciled.
 *
 * The regression-acceptance list this file covers: both refund orders, a
 * buyer who is not attending, multiple seats for the same attendee, manual
 * grants, and the reconcile report (dry run + apply).
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Registrations;

/**
 * @group entitlements
 */
class Test_Entitlements_Seat_Ownership extends Anchor_Events_TestCase {

	/** @var int[] */
	private $minted = [];

	private function ent() {
		return $this->module()->entitlements;
	}

	private function enabled_event() {
		$id             = $this->make_event( [ 'registration_mode' => 'free', 'access_role_enabled' => true ] );
		$this->minted[] = $id;
		return $id;
	}

	public function tear_down() {
		foreach ( $this->minted as $event_id ) {
			remove_role( 'anchor_event_' . $event_id );
		}
		$this->minted = [];
		$_POST        = [];
		$_REQUEST     = [];
		parent::tear_down();
	}

	/**
	 * Buyer A (logged in) buys a seat for themself and one for colleague B.
	 * Both seats carry A's customer id; each resolves to its own account.
	 *
	 * @return array{0:int,1:int,2:int,3:int,4:int} event, buyer, colleague, buyer seat, colleague seat.
	 */
	private function buyer_and_colleague() {
		$event_id  = $this->enabled_event();
		$buyer     = self::factory()->user->create( [ 'user_email' => 'f01-buyer@example.test' ] );
		$colleague = self::factory()->user->create( [ 'user_email' => 'f01-colleague@example.test' ] );
		$seat_a    = $this->make_seat( $event_id, [ 'email' => 'f01-buyer@example.test', 'customer_id' => $buyer, 'order_id' => 9101 ] );
		$seat_b    = $this->make_seat( $event_id, [ 'email' => 'f01-colleague@example.test', 'customer_id' => $buyer, 'order_id' => 9101, 'seat_index' => 2 ] );
		return [ $event_id, $buyer, $colleague, $seat_a, $seat_b ];
	}

	/** The audit's reproduction: refund the buyer's own seat first, then the colleague's. */
	public function test_refunding_the_buyers_seat_first_then_the_colleagues_leaves_no_orphaned_role() {
		[ $event_id, $buyer, $colleague, $seat_a, $seat_b ] = $this->buyer_and_colleague();
		$this->assertTrue( $this->ent()->holds_role( $event_id, $buyer ) );
		$this->assertTrue( $this->ent()->holds_role( $event_id, $colleague ) );

		$this->registrations()->update_status( $seat_a, Registrations::STATUS_REFUNDED );
		$this->assertFalse(
			$this->ent()->holds_role( $event_id, $buyer ),
			'The colleague\'s seat carries the buyer\'s customer id, but it is not the buyer\'s seat.'
		);
		$this->assertTrue( $this->ent()->holds_role( $event_id, $colleague ) );

		$this->registrations()->update_status( $seat_b, Registrations::STATUS_REFUNDED );
		$this->assertFalse( $this->ent()->holds_role( $event_id, $colleague ) );
		$this->assertFalse( $this->ent()->holds_role( $event_id, $buyer ), 'No confirmed seat remains, so no seat-derived role may remain.' );
	}

	/** The other refund order. */
	public function test_refunding_the_colleagues_seat_first_then_the_buyers_leaves_no_role() {
		[ $event_id, $buyer, $colleague, $seat_a, $seat_b ] = $this->buyer_and_colleague();

		$this->registrations()->update_status( $seat_b, Registrations::STATUS_REFUNDED );
		$this->assertFalse( $this->ent()->holds_role( $event_id, $colleague ) );
		$this->assertTrue( $this->ent()->holds_role( $event_id, $buyer ), 'The buyer\'s own seat still entitles them.' );

		$this->registrations()->update_status( $seat_a, Registrations::STATUS_REFUNDED );
		$this->assertFalse( $this->ent()->holds_role( $event_id, $buyer ) );
		$this->assertFalse( $this->ent()->holds_role( $event_id, $colleague ) );
	}

	/** A buyer who is not attending never gets the role from the seats they paid for. */
	public function test_a_non_attending_buyer_gets_no_role_and_refunds_leave_none() {
		$event_id = $this->enabled_event();
		$buyer    = self::factory()->user->create( [ 'user_email' => 'f01-payer@example.test' ] );
		$ada      = self::factory()->user->create( [ 'user_email' => 'f01-ada@example.test' ] );
		$seat_1   = $this->make_seat( $event_id, [ 'email' => 'f01-ada@example.test', 'customer_id' => $buyer, 'order_id' => 9102 ] );
		$seat_2   = $this->make_seat( $event_id, [ 'email' => 'f01-bo@example.test', 'customer_id' => $buyer, 'order_id' => 9102, 'seat_index' => 2 ] );

		$this->assertFalse( $this->ent()->holds_role( $event_id, $buyer ) );
		$this->assertFalse( $this->ent()->has_confirmed_seat( $event_id, $buyer ), 'Owner-only: paying for a seat is not holding it.' );
		$this->assertTrue( $this->ent()->buyer_resolves_to_seat( $event_id, $buyer ), 'The buyer-email question keeps its own, wider, answer.' );

		$this->registrations()->update_status( $seat_1, Registrations::STATUS_REFUNDED );
		$this->registrations()->update_status( $seat_2, Registrations::STATUS_REFUNDED );
		$this->assertFalse( $this->ent()->holds_role( $event_id, $ada ) );
		$this->assertFalse( $this->ent()->holds_role( $event_id, $buyer ) );
	}

	/**
	 * Legacy shape: a buyer holding a SEAT grant with no seat of their own
	 * (bound before the ensure_user() C1 fix). A status change on a seat of
	 * their order reconciles the buyer as well as the attendee.
	 */
	public function test_a_seat_change_also_reconciles_the_orders_buyer() {
		$event_id = $this->enabled_event();
		$buyer    = self::factory()->user->create( [ 'user_email' => 'f01-legacy-buyer@example.test' ] );
		$seat     = $this->make_seat( $event_id, [ 'email' => 'f01-legacy-att@example.test', 'customer_id' => $buyer, 'order_id' => 9103 ] );
		$this->ent()->grant( $event_id, $buyer, 'seat' );

		$this->registrations()->update_status( $seat, Registrations::STATUS_CANCELLED );

		$this->assertFalse( $this->ent()->holds_role( $event_id, $buyer ), 'The buyer\'s stale seat grant is reconciled too.' );
	}

	/**
	 * CodeRabbit PR #32 (audit F01 re-review): maybe_revoke_seat_grant() must
	 * require the buyer's OWN grant record to actually be SOURCE_SEAT before
	 * revoking. A role held with NO grant record at all (minted outside
	 * Entitlements::grant() - e.g. by another plugin, or data older than
	 * grant-record tracking) is left alone by a colleague's refund, exactly
	 * like reconcile() leaves a record-less role untouched.
	 */
	public function test_a_buyer_role_with_no_grant_record_survives_a_colleagues_refund() {
		$event_id  = $this->enabled_event();
		$buyer     = self::factory()->user->create( [ 'user_email' => 'f01-recordless-buyer@example.test' ] );
		$colleague = self::factory()->user->create( [ 'user_email' => 'f01-recordless-colleague@example.test' ] );

		// The buyer holds the role with NO grant record - bypassing grant() entirely.
		$slug = $this->ent()->role_for( $event_id );
		get_userdata( $buyer )->add_role( $slug );
		$this->assertSame( [], $this->ent()->grant_record( $event_id, $buyer ), 'Precondition: no grant record for the buyer.' );
		$this->assertTrue( $this->ent()->holds_role( $event_id, $buyer ) );

		$seat = $this->make_seat( $event_id, [ 'email' => 'f01-recordless-colleague@example.test', 'customer_id' => $buyer, 'order_id' => 9199 ] );
		$this->registrations()->update_status( $seat, Registrations::STATUS_REFUNDED );

		$this->assertTrue(
			$this->ent()->holds_role( $event_id, $buyer ),
			'A record-less role is not a seat grant, so a colleague\'s refund must not touch it.'
		);
	}

	/** Multiple seats for the same attendee: the role survives until the LAST one goes. */
	public function test_multiple_seats_for_one_attendee_keep_the_role_until_the_last_goes() {
		$event_id = $this->enabled_event();
		$buyer    = self::factory()->user->create( [ 'user_email' => 'f01-multi@example.test' ] );
		$first    = $this->make_seat( $event_id, [ 'email' => 'f01-multi@example.test', 'customer_id' => $buyer, 'order_id' => 9104 ] );
		$second   = $this->make_seat( $event_id, [ 'email' => 'f01-multi@example.test', 'customer_id' => $buyer, 'order_id' => 9104, 'seat_index' => 2 ] );

		$this->registrations()->update_status( $first, Registrations::STATUS_REFUNDED );
		$this->assertTrue( $this->ent()->holds_role( $event_id, $buyer ) );

		$this->registrations()->update_status( $second, Registrations::STATUS_REFUNDED );
		$this->assertFalse( $this->ent()->holds_role( $event_id, $buyer ) );
	}

	/** Only a genuinely manual entitlement may remain after the last seat goes. */
	public function test_a_manual_grant_survives_every_refund() {
		[ $event_id, $buyer, $colleague, $seat_a, $seat_b ] = $this->buyer_and_colleague();
		$this->ent()->grant( $event_id, $buyer, 'manual' );

		$this->registrations()->update_status( $seat_a, Registrations::STATUS_REFUNDED );
		$this->registrations()->update_status( $seat_b, Registrations::STATUS_REFUNDED );

		$this->assertTrue( $this->ent()->holds_role( $event_id, $buyer ) );
		$this->assertSame( 'manual', $this->ent()->grant_record( $event_id, $buyer )['source'] );
		$this->assertFalse( $this->ent()->holds_role( $event_id, $colleague ) );
	}

	/**
	 * CodeRabbit PR #32 (audit F01 re-review): the seat's resolved account
	 * (`_anchor_event_user_id`) is AUTHORITATIVE for ownership. A seat bound
	 * to account A never counts as account B's, even when the seat's stored
	 * email happens to equal B's - email is an ownership fallback only for a
	 * seat with no bound account at all.
	 */
	public function test_a_seat_bound_to_one_account_never_matches_another_by_email() {
		$event_id = $this->enabled_event();
		$owner    = self::factory()->user->create( [ 'user_email' => 'f01-owner@example.test' ] );
		$other    = self::factory()->user->create( [ 'user_email' => 'f01-other@example.test' ] );
		// Bound to $owner, but its stored email now equals $other's - e.g. an
		// account that changed its email address to one another user later
		// registered with.
		$this->make_seat( $event_id, [ 'email' => 'f01-other@example.test', 'user_id' => $owner ] );

		$this->assertTrue( $this->ent()->has_confirmed_seat( $event_id, $owner ), 'The bound account owns the seat.' );
		$this->assertFalse( $this->ent()->has_confirmed_seat( $event_id, $other ), 'A matching email does not override a DIFFERENT bound account.' );
		$this->assertTrue( $this->ent()->holds_role( $event_id, $owner ), 'The seat grants the bound account, not the email match.' );
		$this->assertFalse( $this->ent()->holds_role( $event_id, $other ) );

		// A pre-fix orphaned grant on $other (the shape a stale role from
		// before this fix, or a manual mistake, would leave behind) is
		// reconciled away: $other's email does not resurrect it.
		$this->ent()->grant( $event_id, $other, 'seat' );
		$report = $this->ent()->reconcile( $event_id, false );
		$this->assertSame( [ $other ], array_column( $report['orphaned'], 'user_id' ) );
		$this->assertSame( 1, $report['revoked'] );
		$this->assertFalse( $this->ent()->holds_role( $event_id, $other ) );
		$this->assertTrue( $this->ent()->holds_role( $event_id, $owner ), 'The real owner is untouched by reconciling the other account.' );
	}

	/**
	 * reconcile(): a dry run reports without changing anything; apply
	 * revokes exactly the orphaned seat grants; manual grants and holders
	 * with a confirmed seat are untouched; a second apply is a no-op.
	 */
	public function test_reconcile_reports_then_repairs_orphaned_seat_grants() {
		$event_id = $this->enabled_event();
		$keeper   = self::factory()->user->create( [ 'user_email' => 'f01-keeper@example.test' ] );
		$this->make_seat( $event_id, [ 'email' => 'f01-keeper@example.test' ] );
		$orphan   = self::factory()->user->create( [ 'user_email' => 'f01-orphan@example.test' ] );
		$comp     = self::factory()->user->create( [ 'user_email' => 'f01-comp@example.test' ] );
		// The pre-fix end state: a seat grant with no confirmed seat behind it.
		$this->ent()->grant( $event_id, $orphan, 'seat' );
		$this->ent()->grant( $event_id, $comp, 'manual' );

		$report = $this->ent()->reconcile( $event_id, true );
		$this->assertTrue( $report['dry_run'] );
		$this->assertSame( 3, $report['checked'] );
		$this->assertSame( [ $orphan ], array_column( $report['orphaned'], 'user_id' ) );
		$this->assertSame( 'no_confirmed_seat', $report['orphaned'][0]['reason'] );
		$this->assertSame( 0, $report['revoked'] );
		$this->assertTrue( $this->ent()->holds_role( $event_id, $orphan ), 'A dry run changes nothing.' );

		$report = $this->ent()->reconcile( $event_id, false );
		$this->assertFalse( $report['dry_run'] );
		$this->assertSame( 1, $report['revoked'] );
		$this->assertFalse( $this->ent()->holds_role( $event_id, $orphan ) );
		$this->assertTrue( $this->ent()->holds_role( $event_id, $keeper ) );
		$this->assertTrue( $this->ent()->holds_role( $event_id, $comp ) );

		$again = $this->ent()->reconcile( $event_id, false );
		$this->assertSame( 0, $again['revoked'], 'Idempotent.' );
		$this->assertSame( [], $again['orphaned'] );
	}

	/** reconcile() on a switched-off event does nothing (turning access off looks forward only). */
	public function test_reconcile_refuses_when_the_switch_is_off() {
		$event_id = $this->enabled_event();
		$orphan   = self::factory()->user->create();
		$this->ent()->grant( $event_id, $orphan, 'seat' );
		update_post_meta( $event_id, '_anchor_event_access_role_enabled', false );

		$report = $this->ent()->reconcile( $event_id, false );
		$this->assertFalse( $report['enabled'] );
		$this->assertSame( 0, $report['revoked'] );
		$this->assertTrue( $this->ent()->holds_role( $event_id, $orphan ) );
	}

	/* --- The Basics panel's "Reconcile roles" button ----------------------- */

	public function trap_redirect( $location ) {
		throw new Anchor_Entitlements_Redirect_Signal( (string) $location );
	}

	public function test_the_role_panel_offers_reconcile_once_a_role_exists() {
		$event_id = $this->enabled_event();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$html = $this->module()->render_event_role_panel( $event_id );
		$this->assertStringNotContainsString( 'anchor_events_reconcile_role', $html, 'No role, nothing to reconcile.' );

		$this->ent()->grant( $event_id, self::factory()->user->create(), 'seat' );
		$html = $this->module()->render_event_role_panel( $event_id );
		$this->assertStringContainsString( 'anchor_events_reconcile_role', $html );
		$this->assertStringContainsString( 'Check roles', $html );
		$this->assertStringContainsString( 'Reconcile roles', $html );
	}

	public function test_handle_reconcile_role_dies_on_invalid_nonce() {
		$event_id = $this->enabled_event();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST    = [ 'event_id' => $event_id, '_wpnonce' => 'invalid-nonce' ];
		$_REQUEST = $_POST;
		$this->expectException( WPDieException::class );
		$this->module()->handle_reconcile_role();
	}

	public function test_handle_reconcile_role_dies_for_a_non_manager() {
		$event_id = $this->enabled_event();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$_POST    = [ 'event_id' => $event_id, '_wpnonce' => wp_create_nonce( 'anchor_events_reconcile_role_' . $event_id ) ];
		$_REQUEST = $_POST;
		$this->expectException( WPDieException::class );
		$this->module()->handle_reconcile_role();
	}

	/** @dataProvider reconcile_modes */
	public function test_handle_reconcile_role_reports_the_count( $mode, $expect_revoked ) {
		$event_id = $this->enabled_event();
		$orphan   = self::factory()->user->create();
		$this->ent()->grant( $event_id, $orphan, 'seat' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST    = [
			'event_id'    => $event_id,
			'mode'        => $mode,
			'redirect_to' => 'https://example.org/manager/',
			'_wpnonce'    => wp_create_nonce( 'anchor_events_reconcile_role_' . $event_id ),
		];
		$_REQUEST = $_POST;

		add_filter( 'wp_redirect', [ $this, 'trap_redirect' ] );
		try {
			$this->module()->handle_reconcile_role();
			$this->fail( 'handle_reconcile_role() did not redirect.' );
		} catch ( Anchor_Entitlements_Redirect_Signal $e ) {
			$this->assertStringContainsString( 'anchor_events_role_reconciled=1', $e->getMessage() );
			$this->assertStringContainsString( 'anchor_events_role_reconcile_mode=' . $mode, $e->getMessage() );
		} finally {
			remove_filter( 'wp_redirect', [ $this, 'trap_redirect' ] );
		}
		$this->assertSame( ! $expect_revoked, $this->ent()->holds_role( $event_id, $orphan ) );
		$this->assertNotSame( '', $this->module()->reconcile_notice_message( 1, $mode === 'check', true ) );
	}

	public function reconcile_modes() {
		return [
			'check (dry run)' => [ 'check', false ],
			'apply'           => [ 'apply', true ],
		];
	}
}
