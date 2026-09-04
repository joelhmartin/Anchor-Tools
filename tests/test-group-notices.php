<?php
/**
 * Group-authoring save notices (audit MODEL-D14 + the notice half of MODEL-D8).
 *
 * Two things are proven here.
 *
 * 1. A save that produces ZERO offering rows on a parent that already has rows
 *    (or live children) is treated as an accident: the stored rows are kept,
 *    reconcile() is skipped and the author is told. Clearing an offering for
 *    real is an explicit action — switch the event's type away from "offering"
 *    — and that path still clears everything.
 *
 * 2. One notice vocabulary reaches the author on every save path. The codes
 *    live in a single map (Module::group_notice_map()) and are queued into a
 *    short-lived per-user, per-post transient, which the classic admin notice,
 *    the front-end manager form's redirect and the REST response all read —
 *    the old redirect_post_location filter only ever fired on the classic
 *    post.php redirect, so the block editor and the front-end manager form
 *    both showed a bare "saved".
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;

/**
 * @group event-save
 * @group occurrences
 */
class Test_Group_Notices extends Anchor_Events_TestCase {

	/** @var int */
	private $admin_id;

	public function set_up() {
		parent::set_up();
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );
	}

	public function tear_down() {
		unset( $_POST, $_GET, $_REQUEST );
		parent::tear_down();
	}

	/** @return \Anchor\Events\Occurrences */
	private function occurrences() {
		return $this->module()->occurrences;
	}

	/** A valid two-date offering payload for the classic metabox save. */
	private function offering_payload( array $overrides = [] ) {
		return array_merge(
			[
				Module::NONCE => wp_create_nonce( Module::NONCE ),
				'anchor_event_start_date' => '2027-04-01',
				'anchor_event_type' => 'offering',
				'anchor_event_registration_mode' => 'free',
				'anchor_event_offering_dates' => [
					[ 'date' => '2027-04-01', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Session A', 'capacity' => 10 ],
					[ 'date' => '2027-04-08', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Session B', 'capacity' => 10 ],
				],
			],
			$overrides
		);
	}

	/** Save an offering parent with two dates and return its id. */
	private function make_offering_parent() {
		$event_id = $this->make_event();
		$_POST    = $this->offering_payload();
		$this->module()->save_meta( $event_id );
		$this->assertCount( 2, $this->occurrences()->children( $event_id ), 'fixture: two children expected.' );
		return $event_id;
	}

	/** The queued codes for a post, without consuming them. */
	private function queued( $event_id ) {
		return wp_list_pluck( $this->module()->queued_group_notices( $event_id ), 'code' );
	}

	/* ------------------------------------------------------------------
	 * MODEL-D14: an empty row list never destroys the authored dates.
	 * ------------------------------------------------------------------ */

	/** Saving a parent with every row deleted keeps the stored rows AND the children, and tells the author. */
	public function test_empty_rows_on_existing_parent_keeps_rows_and_children() {
		$event_id = $this->make_offering_parent();

		$_POST = $this->offering_payload( [ 'anchor_event_offering_dates' => [] ] );
		$this->module()->save_meta( $event_id );

		$stored = get_post_meta( $event_id, '_anchor_event_offering_dates', true );
		$this->assertCount( 2, $stored, 'The previously authored rows must survive an empty save.' );
		$this->assertSame( '2027-04-01', $stored[0]['date'] );
		$this->assertCount( 2, $this->occurrences()->children( $event_id ), 'reconcile() must be skipped, leaving the children alone.' );
		$this->assertSame( 'parent', get_post_meta( $event_id, '_anchor_event_group_role', true ) );
		$this->assertContains( 'offering_incomplete', $this->queued( $event_id ) );
	}

	/** The same on the front-end manager path — the rows are kept there too. */
	public function test_empty_rows_on_front_end_save_keeps_rows() {
		$event_id = $this->make_offering_parent();

		$_POST  = [
			'anchor_event_type' => 'offering',
			'anchor_event_registration_mode' => 'free',
			'anchor_event_offering_dates' => [],
		];
		$method = new ReflectionMethod( Module::class, 'save_event_manager_fields' );
		$method->setAccessible( true );
		$method->invoke( $this->module(), $event_id, '2027-04-01', $this->module()->registration_mode( $event_id ) );

		$this->assertCount( 2, get_post_meta( $event_id, '_anchor_event_offering_dates', true ) );
		$this->assertCount( 2, $this->occurrences()->children( $event_id ) );
		$this->assertContains( 'offering_incomplete', $this->queued( $event_id ) );
	}

	/** A brand-new offering with no rows has nothing to protect: the empty list still persists. */
	public function test_empty_rows_on_new_event_still_persists_the_empty_list() {
		$event_id = $this->make_event();

		$_POST = $this->offering_payload( [ 'anchor_event_offering_dates' => [ [ 'date' => '' ] ] ] );
		$this->module()->save_meta( $event_id );

		$this->assertSame( [], get_post_meta( $event_id, '_anchor_event_offering_dates', true ) );
		$this->assertCount( 0, $this->occurrences()->children( $event_id ) );
		$this->assertContains( 'offering_incomplete', $this->queued( $event_id ) );
	}

	/** The documented escape hatch: switching the type away from offering DOES clear the rows. */
	public function test_type_change_away_from_offering_clears_the_kept_rows() {
		$event_id = $this->make_offering_parent();

		// An accidental empty save first — the rows are kept.
		$_POST = $this->offering_payload( [ 'anchor_event_offering_dates' => [] ] );
		$this->module()->save_meta( $event_id );
		$this->assertCount( 2, get_post_meta( $event_id, '_anchor_event_offering_dates', true ) );

		// The explicit action: type -> single.
		$_POST = $this->offering_payload( [ 'anchor_event_type' => 'single' ] );
		$this->module()->save_meta( $event_id );

		$this->assertSame( [], get_post_meta( $event_id, '_anchor_event_offering_dates', true ), 'Changing the type away is the explicit way to clear an offering.' );
		$this->assertSame( '', get_post_meta( $event_id, '_anchor_event_group_role', true ) );
		$this->assertCount( 0, $this->occurrences()->children( $event_id ) );
	}

	/* ------------------------------------------------------------------
	 * MODEL-D8's notice half: the duplicate row is reported everywhere.
	 * ------------------------------------------------------------------ */

	/** A duplicated date+start_time keeps one row and queues offering_duplicate_date. */
	public function test_duplicate_row_queues_notice_and_persists_one_row() {
		$event_id = $this->make_event();

		$_POST = $this->offering_payload( [
			'anchor_event_offering_dates' => [
				[ 'date' => '2027-04-01', 'start_time' => '09:00', 'label' => 'Session A', 'capacity' => 10 ],
				[ 'date' => '2027-04-01', 'start_time' => '09:00', 'label' => 'Session A again', 'capacity' => 4 ],
			],
		] );
		$this->module()->save_meta( $event_id );

		$stored = get_post_meta( $event_id, '_anchor_event_offering_dates', true );
		$this->assertCount( 1, $stored );
		$this->assertSame( 'Session A', $stored[0]['label'] );
		$this->assertContains( 'offering_duplicate_date', $this->queued( $event_id ) );
	}

	/* ------------------------------------------------------------------
	 * The three consumers.
	 * ------------------------------------------------------------------ */

	/** admin_notices() prints the queued message once, and the next request is clean. */
	public function test_admin_notices_prints_once_then_clears() {
		$event_id = $this->make_offering_parent();

		$_POST = $this->offering_payload( [ 'anchor_event_offering_dates' => [] ] );
		$this->module()->save_meta( $event_id );

		$_GET['post'] = $event_id;

		ob_start();
		$this->module()->admin_notices();
		$first = ob_get_clean();
		$this->assertStringContainsString( 'Add at least one offering date', $first );

		ob_start();
		$this->module()->admin_notices();
		$second = ob_get_clean();
		$this->assertSame( '', $second, 'A consumed notice must not repeat on the next admin page load.' );
	}

	/** The block editor's metabox iframe must NOT eat the notice before a real page load can print it. */
	public function test_metabox_iframe_request_does_not_consume_the_notice() {
		$event_id = $this->make_offering_parent();

		$_POST = $this->offering_payload( [ 'anchor_event_offering_dates' => [] ] );
		$this->module()->save_meta( $event_id );

		$_GET['post'] = $event_id;
		$_REQUEST['meta-box-loader'] = '1';
		ob_start();
		$this->module()->admin_notices();
		ob_get_clean();
		unset( $_REQUEST['meta-box-loader'] );

		$this->assertContains( 'offering_incomplete', $this->queued( $event_id ), 'The hidden metabox iframe render must leave the notice queued.' );
	}

	/**
	 * The Event Details metabox renders the queued notice. This is the block
	 * editor's real channel: Gutenberg re-POSTs the metaboxes to
	 * post.php?meta-box-loader=1 after its REST save, and that request is both
	 * where save_meta() queues the notice and where this markup is shown.
	 */
	public function test_metabox_render_shows_the_queued_notice() {
		$event_id = $this->make_offering_parent();

		$_POST = $this->offering_payload( [ 'anchor_event_offering_dates' => [] ] );
		$this->module()->save_meta( $event_id );

		ob_start();
		$this->module()->render_meta_box( get_post( $event_id ) );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'anchor-event-save-notice', $html );
		$this->assertStringContainsString( 'change the event type away from', $html, 'The queued offering_incomplete copy must be rendered next to the repeater.' );
	}

	/** A clean save leaves the metabox with no save notice at all. */
	public function test_metabox_render_is_clean_after_a_good_save() {
		$event_id = $this->make_offering_parent();

		ob_start();
		$this->module()->render_meta_box( get_post( $event_id ) );
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'anchor-event-save-notice', $html );
	}

	/** The peek does not rob admin_notices(): the classic editor still gets its one delivery. */
	public function test_metabox_render_does_not_consume_the_queue() {
		$event_id = $this->make_offering_parent();

		$_POST = $this->offering_payload( [ 'anchor_event_offering_dates' => [] ] );
		$this->module()->save_meta( $event_id );

		ob_start();
		$this->module()->render_meta_box( get_post( $event_id ) );
		ob_get_clean();

		$this->assertContains( 'offering_incomplete', $this->queued( $event_id ) );
	}

	/** A bulk-action URL sends post[] — admin_notices() must treat it as no post, not cast an array. */
	public function test_admin_notices_ignores_a_bulk_action_post_array() {
		$event_id = $this->make_offering_parent();

		$_POST = $this->offering_payload( [ 'anchor_event_offering_dates' => [] ] );
		$this->module()->save_meta( $event_id );

		$_GET['post'] = [ $event_id ];
		ob_start();
		$this->module()->admin_notices();
		$this->assertSame( '', ob_get_clean() );
		$this->assertContains( 'offering_incomplete', $this->queued( $event_id ), 'A list screen must not consume the notice either.' );
	}

	/** The front-end save's redirect carries the base code AND the queued group codes. */
	public function test_front_end_redirect_arg_carries_the_queued_codes() {
		$event_id = $this->make_offering_parent();

		$_POST = $this->offering_payload( [ 'anchor_event_offering_dates' => [] ] );
		$this->module()->save_meta( $event_id );

		$method = new ReflectionMethod( Module::class, 'event_manager_notice_arg' );
		$method->setAccessible( true );
		$arg = $method->invoke( $this->module(), 'saved', $event_id );

		$this->assertSame( 'saved,offering_incomplete', $arg );
		$this->assertSame( [], $this->queued( $event_id ), 'The redirect consumes the queue — it is delivered exactly once.' );
	}

	/** The front-end notice renderer understands a comma list and renders both messages. */
	public function test_front_end_renderer_renders_every_code_in_the_arg() {
		$_GET['event_manager_notice'] = 'saved,offering_incomplete';

		$method = new ReflectionMethod( Module::class, 'render_event_manager_notice' );
		$method->setAccessible( true );
		$html = $method->invoke( $this->module() );

		$this->assertStringContainsString( 'Event saved.', $html );
		$this->assertStringContainsString( 'Add at least one offering date', $html );
	}

	/** The REST write response exposes the queued codes for a block-editor consumer. */
	public function test_rest_write_response_exposes_the_queued_codes() {
		$event_id = $this->make_offering_parent();

		$_POST = $this->offering_payload( [ 'anchor_event_offering_dates' => [] ] );
		$this->module()->save_meta( $event_id );

		$request = new WP_REST_Request( 'POST', '/wp/v2/events/' . $event_id );
		$response = new WP_REST_Response( [ 'id' => $event_id ] );
		$response = $this->module()->attach_notices_to_rest_response( $response, get_post( $event_id ), $request );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'anchor_event_notices', $data );
		$this->assertSame( 'offering_incomplete', $data['anchor_event_notices'][0]['code'] );
		$this->assertNotSame( '', $data['anchor_event_notices'][0]['message'] );
	}

	/** A plain GET of an event carries no notice payload at all. */
	public function test_rest_read_response_is_untouched() {
		$event_id = $this->make_offering_parent();

		$request  = new WP_REST_Request( 'GET', '/wp/v2/events/' . $event_id );
		$response = new WP_REST_Response( [ 'id' => $event_id ] );
		$response = $this->module()->attach_notices_to_rest_response( $response, get_post( $event_id ), $request );

		$this->assertArrayNotHasKey( 'anchor_event_notices', $response->get_data() );
	}

	/** Notices are per user: another editor's save is not shown to this one. */
	public function test_queued_notices_are_scoped_to_the_author_who_saved() {
		$event_id = $this->make_offering_parent();

		$_POST = $this->offering_payload( [ 'anchor_event_offering_dates' => [] ] );
		$this->module()->save_meta( $event_id );
		$this->assertContains( 'offering_incomplete', $this->queued( $event_id ) );

		$other = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $other );
		$this->assertSame( [], $this->queued( $event_id ) );
	}
}
