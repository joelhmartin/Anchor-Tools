<?php
/**
 * WooCommerce::ajax_add_to_cart() asks the bookability authority before it
 * touches the cart (WOO-D37).
 *
 * The endpoint used to check tier active / on sale / capacity and nothing
 * else — not `registration_enabled`, not the event's own end date, not "is
 * this a group PARENT", not "is this occurrence soft-closed". It leaned on
 * WC_Cart::add_to_cart() internally calling is_purchasable() and reported
 * every such rejection as the generic "Could not add %s to the cart"; a
 * logged-in admin, for whom WooCommerce treats a draft product as
 * purchasable, sailed past it entirely.
 *
 * @package Anchor\Events\Tests
 */

/**
 * @group woocommerce
 * @group ajax
 * @group bookability
 */
class Test_Add_To_Cart_Ajax extends WP_Ajax_UnitTestCase {

	use Anchor_Events_Fixtures;

	const ACTION = 'anchor_events_add_to_cart';

	public function set_up() {
		parent::set_up();
		$this->require_wc();

		// The endpoint's first guard is "is there a cart at all". WooCommerce
		// only builds one on a front-end request, so load it explicitly the way
		// WC's own AJAX endpoints do — otherwise every test here would assert
		// against "The cart is currently unavailable."
		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		$_POST = [];
	}

	public function tear_down() {
		$_POST = [];
		parent::tear_down();
	}

	/**
	 * POST to the handler and return the decoded JSON envelope.
	 *
	 * @param int   $event_id
	 * @param array $tiers    tier_id => qty.
	 * @return array Decoded { success: bool, data: {...} }.
	 */
	private function post( $event_id, array $tiers ) {
		$this->_last_response = '';
		$_GET                 = [];

		$_POST = [
			'action'   => self::ACTION,
			'nonce'    => wp_create_nonce( self::ACTION ),
			'event_id' => (int) $event_id,
			'tiers'    => $tiers,
		];

		try {
			$this->_handleAjax( self::ACTION );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected: wp_send_json_* dies once the payload is emitted.
		} catch ( WPAjaxDieStopException $e ) {
			// Also expected on the error paths that set a status code.
		}

		// The success path sets the WooCommerce customer-session cookie, and
		// with WP_DEBUG on wc_setcookie() emits an E_USER_NOTICE straight into
		// the output buffer because the test bootstrap has already sent
		// headers. That is test-harness noise, not a defect in the endpoint —
		// on a real request the headers are not yet sent — so read the JSON
		// document out of whatever precedes it rather than asserting on a
		// buffer PHP itself polluted.
		$raw     = strstr( $this->_last_response, '{' );
		$decoded = json_decode( (string) $raw, true );
		$this->assertNotNull(
			$decoded,
			'The endpoint returned no valid JSON. Raw: ' . substr( $this->_last_response, 0, 800 )
		);
		return $decoded;
	}

	/** All messages from an error envelope, joined. */
	private function messages( array $decoded ) {
		return implode( ' | ', (array) ( $decoded['data']['messages'] ?? [] ) );
	}

	/** A paid, synced event. @return array{0:int,1:array} [ event_id, tiers ] */
	private function make_ticketed_event( array $meta = [] ) {
		$event = $this->make_event(
			array_merge( [
				'title'                => 'Ticketed Course',
				'registration_enabled' => true,
				'registration_mode'    => 'wc',
				'start_date'           => '2030-01-01',
				'timezone'             => 'UTC',
			], $meta ),
			[ [ 'label' => 'General', 'price' => '25', 'active' => 1 ] ]
		);
		$this->product_sync()->sync_event( $event );
		return [ $event, $this->ticket_types()->get( $event ) ];
	}

	/**
	 * A reconciled offering parent and its live children.
	 *
	 * @return array{0:int,1:int[]}
	 */
	private function make_offering() {
		$parent = $this->make_event( [
			'title'                => 'Workshop',
			'type'                 => 'offering',
			'registration_enabled' => true,
			'registration_mode'    => 'wc',
			'timezone'             => 'UTC',
		], [ [ 'label' => 'General', 'price' => '25', 'active' => 1 ] ] );
		update_post_meta( $parent, '_anchor_event_offering_dates', [
			[ 'date' => '2030-10-23', 'start_time' => '08:00', 'end_time' => '18:00', 'label' => 'October', 'capacity' => 0 ],
			[ 'date' => '2030-11-13', 'start_time' => '08:00', 'end_time' => '18:00', 'label' => 'November', 'capacity' => 0 ],
		] );
		return [ $parent, $this->module()->occurrences->reconcile( $parent ) ];
	}

	/* ------------------------------------------------------------------ */

	/** A container is never a seat — and the refusal says what to do instead. */
	public function test_group_parent_is_refused_with_a_choose_a_date_message() {
		list( $parent ) = $this->make_offering();

		$decoded = $this->post( $parent, [ 'primary' => 1 ] );

		$this->assertFalse( $decoded['success'] );
		$this->assertStringContainsString( 'choose a date', $this->messages( $decoded ) );
	}

	/** A soft-closed occurrence is still reachable by URL; the cart must refuse it. */
	public function test_soft_closed_child_is_refused() {
		list( $parent, $live ) = $this->make_offering();
		$closed = $live[0];
		$this->make_seat( $closed ); // Seated → soft-closed rather than trashed.

		update_post_meta( $parent, '_anchor_event_offering_dates', [
			[ 'date' => '2030-11-13', 'start_time' => '08:00', 'end_time' => '18:00', 'label' => 'November', 'capacity' => 0 ],
		] );
		$this->module()->occurrences->reconcile( $parent );
		$this->assertSame( 'closed', $this->module()->bookability( $closed ) );

		$decoded = $this->post( $closed, [ 'primary' => 1 ] );

		$this->assertFalse( $decoded['success'] );
		$this->assertStringContainsString( 'closed', strtolower( $this->messages( $decoded ) ) );
	}

	/** "Enable registration" unticked means no sale, by any route. */
	public function test_registration_disabled_event_is_refused() {
		list( $event, $tiers ) = $this->make_ticketed_event();
		update_post_meta( $event, '_anchor_event_registration_enabled', false );

		$decoded = $this->post( $event, [ $tiers[0]['id'] => 1 ] );

		$this->assertFalse( $decoded['success'] );
		$this->assertStringContainsString( 'closed', strtolower( $this->messages( $decoded ) ) );
		$this->assertSame( 0, WC()->cart->get_cart_contents_count() );
	}

	/** An event that already finished is refused even with room left. */
	public function test_finished_event_is_refused() {
		list( $event, $tiers ) = $this->make_ticketed_event( [
			'start_date' => '2020-01-01',
			'end_date'   => '2020-01-01',
		] );
		$ts = $this->module()->compute_timestamps( $this->module()->get_meta( $event ) );
		update_post_meta( $event, '_anchor_event_start_ts', $ts['start'] );
		update_post_meta( $event, '_anchor_event_end_ts', $ts['end'] );

		$decoded = $this->post( $event, [ $tiers[0]['id'] => 1 ] );

		$this->assertFalse( $decoded['success'] );
		$this->assertSame( 0, WC()->cart->get_cart_contents_count() );
	}

	/** The gate is not a blanket no: a bookable occurrence reaches the cart. */
	public function test_bookable_event_reaches_the_cart() {
		list( $event, $tiers ) = $this->make_ticketed_event();

		$decoded = $this->post( $event, [ $tiers[0]['id'] => 2 ] );

		$this->assertTrue( $decoded['success'], 'Response: ' . wp_json_encode( $decoded ) );
		$this->assertSame( 2, (int) $decoded['data']['added'] );
		$this->assertSame( 2, WC()->cart->get_cart_contents_count() );
	}
}
