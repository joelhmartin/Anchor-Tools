<?php
/**
 * Per-event attendee questions on every registration path (REG-D9/D10/D11).
 *
 * Three defects, one model:
 *
 *   REG-D9  The free registration form never asked the event's own questions.
 *           render_registration_form() iterated get_registration_fields() — an
 *           empty filter with no shipped consumer — so an editor who added
 *           "Practice name (required)" got a blank roster column for every free
 *           registration and no enforcement anywhere.
 *   REG-D10 The two paths disagreed on the answer key: the free handler stored
 *           sanitize_key($posted_key), the WooCommerce checkout stored
 *           $q['label']. Every reader looked up by label, so a free answer was
 *           invisible on the roster and the CSV grew both spellings as separate
 *           columns.
 *   REG-D11 Because the stored key WAS the label, renaming a question orphaned
 *           every answer already collected.
 *
 * The fix is one rule: answers are keyed by the question's stable key on every
 * write path, and the label is resolved at read time from the current question
 * set (Module::resolve_registration_answers() migrates a legacy label-keyed row
 * lazily, and keeps the stored key when the question is gone).
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;
use Anchor\Events\Registrations;

/** Thrown from the wp_redirect filter so handle_registration()'s exit never runs. */
if ( ! class_exists( 'Anchor_Questions_Redirected' ) ) {
	class Anchor_Questions_Redirected extends \Exception {}
}

/**
 * @group registration
 * @group questions
 */
class Test_Registration_Questions extends Anchor_Events_TestCase {

	/** The question set every fixture event asks. */
	private function questions() {
		return [
			[ 'key' => 'practice_name', 'label' => 'Practice name', 'type' => 'text', 'options' => [], 'required' => true ],
			[ 'key' => 'dietary', 'label' => 'Dietary needs', 'type' => 'textarea', 'options' => [], 'required' => false ],
			[ 'key' => 'experience', 'label' => 'Experience', 'type' => 'select', 'options' => [ 'New', 'Seasoned' ], 'required' => false ],
			[ 'key' => 'parking', 'label' => 'Needs parking', 'type' => 'checkbox', 'options' => [], 'required' => false ],
		];
	}

	/** Event asking the four questions above. */
	private function event_with_questions( array $meta = [], array $tiers = [] ) {
		$event_id = $this->make_event(
			array_merge( [ 'title' => 'Questions Event', 'start_date' => '2030-10-23' ], $meta ),
			$tiers
		);
		update_post_meta( $event_id, Module::QUESTIONS_META, $this->questions() );
		return $event_id;
	}

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
		throw new Anchor_Questions_Redirected( (string) $location );
	}

	/** POST the free form and return where it redirected. */
	private function submit_registration( $event_id, array $fields = [] ) {
		$_POST = [
			Module::REG_NONCE    => wp_create_nonce( Module::REG_NONCE ),
			'event_id'           => $event_id,
			'redirect_to'        => 'https://example.org/events/',
			'anchor_event_name'  => 'Jane Doe',
			'anchor_event_email' => 'jane@example.org',
		];
		if ( ! empty( $fields ) ) {
			$_POST['anchor_event_field'] = $fields;
		}
		$_REQUEST = $_POST;

		try {
			$this->module()->handle_registration();
		} catch ( Anchor_Questions_Redirected $e ) {
			return $e->getMessage();
		}

		$this->fail( 'handle_registration() returned without redirecting.' );
	}

	/** The one seat on an event, as the roster/export DTO sees it. */
	private function only_seat( $event_id ) {
		$items = $this->registrations()->query_seats( [ 'event_id' => $event_id, 'status' => 'all', 'per_page' => 50 ] );
		$this->assertCount( 1, $items['items'], 'Expected exactly one seat.' );
		return $items['items'][0];
	}

	/* ------------------------------------------------------------------
	 * REG-D9 — the free form asks the questions and enforces "required".
	 * ---------------------------------------------------------------- */

	public function test_free_form_renders_the_events_own_questions() {
		$event_id = $this->event_with_questions();
		$html     = $this->module()->render_registration_form( $event_id );

		$this->assertStringContainsString( 'anchor_event_reg_nonce', $html, 'Fixture invalid: the free form did not render.' );

		// Every question, by label and by id-keyed input name.
		$this->assertStringContainsString( 'Practice name', $html );
		$this->assertStringContainsString( 'anchor_event_field[practice_name]', $html );
		$this->assertStringContainsString( 'Dietary needs', $html );
		$this->assertStringContainsString( 'anchor_event_field[dietary]', $html );
		$this->assertStringContainsString( 'anchor_event_field[experience]', $html );
		$this->assertStringContainsString( 'anchor_event_field[parking]', $html );

		// Type-aware controls, as the question model defines them.
		$this->assertMatchesRegularExpression( '/<textarea[^>]*name="anchor_event_field\[dietary\]"/', $html );
		$this->assertMatchesRegularExpression( '/<select[^>]*name="anchor_event_field\[experience\]"/', $html );
		$this->assertStringContainsString( '<option value="Seasoned"', $html );
		$this->assertMatchesRegularExpression( '/<input type="checkbox"[^>]*name="anchor_event_field\[parking\]"/', $html );
	}

	public function test_required_question_is_marked_required_and_optional_ones_are_not() {
		$event_id = $this->event_with_questions();
		$html     = $this->module()->render_registration_form( $event_id );

		$this->assertMatchesRegularExpression(
			'/<input[^>]*name="anchor_event_field\[practice_name\]"[^>]*\srequired/',
			$html,
			'The required question must carry the required attribute.'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/<textarea[^>]*name="anchor_event_field\[dietary\]"[^>]*\srequired/',
			$html,
			'An optional question must not be marked required.'
		);
	}

	public function test_post_without_a_required_answer_is_rejected() {
		$event_id = $this->event_with_questions();

		$location = $this->submit_registration( $event_id, [ 'dietary' => 'Vegetarian' ] );

		$this->assertStringContainsString( 'registration_invalid', $location );
		$this->assertSame( 0, $this->module()->get_registration_count( $event_id ), 'A rejected registration must claim no seat.' );
	}

	public function test_post_with_a_whitespace_only_required_answer_is_rejected() {
		$event_id = $this->event_with_questions();

		$location = $this->submit_registration( $event_id, [ 'practice_name' => "   \t " ] );

		$this->assertStringContainsString( 'registration_invalid', $location );
		$this->assertSame( 0, $this->module()->get_registration_count( $event_id ) );
	}

	/* ------------------------------------------------------------------
	 * REG-D10 — one key on both write paths.
	 * ---------------------------------------------------------------- */

	public function test_free_path_stores_answers_keyed_by_question_id() {
		$event_id = $this->event_with_questions();

		$location = $this->submit_registration(
			$event_id,
			[
				'practice_name' => 'Anchor Dental',
				'dietary'       => 'Vegetarian',
				'experience'    => 'Seasoned',
				'parking'       => 'yes',
			]
		);
		$this->assertStringContainsString( 'registration_success', $location );

		$seat   = $this->only_seat( $event_id );
		$stored = get_post_meta( (int) $seat['id'], '_anchor_event_reg_fields', true );

		$this->assertSame(
			[
				'practice_name' => 'Anchor Dental',
				'dietary'       => 'Vegetarian',
				'experience'    => 'Seasoned',
				'parking'       => 'yes',
			],
			$stored,
			'The free path must store answers keyed by the question id, not the label.'
		);
	}

	/**
	 * REG-D35 — the free path reads the question set, never the POST's own key
	 * list. An unasked key used to be sanitize_key()'d and stored, which put an
	 * attacker-chosen column header into the CSV an organizer opens; a
	 * non-scalar value used to reach sanitize_text_field() and raise a notice.
	 */
	public function test_free_path_discards_answers_to_questions_the_event_never_asked() {
		$event_id = $this->event_with_questions();

		$this->submit_registration(
			$event_id,
			[
				'practice_name'    => 'Anchor Dental',
				'whatever_i_like'  => 'attacker column',
				'nested'           => [ 'an', 'array' ],
			]
		);

		$stored = get_post_meta( (int) $this->only_seat( $event_id )['id'], '_anchor_event_reg_fields', true );

		$this->assertArrayNotHasKey( 'whatever_i_like', $stored );
		$this->assertArrayNotHasKey( 'nested', $stored );
		$this->assertSame( 'Anchor Dental', $stored['practice_name'] );
	}

	public function test_free_path_rejects_a_select_answer_that_is_not_an_option() {
		$event_id = $this->event_with_questions();

		$this->submit_registration( $event_id, [ 'practice_name' => 'Anchor Dental', 'experience' => 'Wizard' ] );

		$seat   = $this->only_seat( $event_id );
		$stored = get_post_meta( (int) $seat['id'], '_anchor_event_reg_fields', true );
		$this->assertSame( '', $stored['experience'] );
	}

	/**
	 * The WooCommerce checkout stores the same shape as the free path — the
	 * whole point of D10. Drives the real hook callback
	 * (persist_attendees_to_line_item) with a checkout-shaped $_POST, then the
	 * real reconcile, and reads the seat meta.
	 */
	public function test_woocommerce_checkout_stores_the_same_shape() {
		$this->require_wc();

		$event_id = $this->event_with_questions(
			[ 'capacity' => 0, 'registration_mode' => 'wc' ],
			[ [ 'label' => 'General', 'price' => '10', 'active' => 1 ] ]
		);
		$this->product_sync()->sync_event( $event_id );
		$tiers        = $this->ticket_types()->get( $event_id );
		$variation_id = $this->product_sync()->variation_for_tier( $event_id, $tiers[0]['id'] );
		$this->assertGreaterThan( 0, $variation_id );

		$item = new WC_Order_Item_Product();
		$item->set_product( wc_get_product( $variation_id ) );
		$item->set_quantity( 1 );
		$item->set_subtotal( 10 );
		$item->set_total( 10 );

		$order = new WC_Order();
		$order->add_item( $item );
		$order->set_billing_email( 'buyer@example.test' );

		// The checkout POST shape the attendee fieldset renders.
		$_POST = [
			'anchor_attendees' => [
				'cartkey' => [
					1 => [
						'name'   => 'Jane Doe',
						'email'  => 'jane@example.org',
						'phone'  => '555-0100',
						'fields' => [
							'practice_name' => 'Anchor Dental',
							'dietary'       => 'Vegetarian',
							'experience'    => 'Seasoned',
							'parking'       => 'yes',
						],
					],
				],
			],
		];
		$this->woocommerce()->persist_attendees_to_line_item( $item, 'cartkey', [], $order );

		$order->calculate_totals( false );
		$order->save();
		$order->set_status( 'processing' );
		$order->save();

		$this->woocommerce()->reconcile_order( wc_get_order( $order->get_id() ), 'test' );

		$seat   = $this->only_seat( $event_id );
		$stored = get_post_meta( (int) $seat['id'], '_anchor_event_reg_fields', true );

		$this->assertSame(
			[
				'practice_name' => 'Anchor Dental',
				'dietary'       => 'Vegetarian',
				'experience'    => 'Seasoned',
				'parking'       => 'yes',
			],
			$stored,
			'The checkout path must store the same id-keyed shape as the free path.'
		);
	}

	/* ------------------------------------------------------------------
	 * REG-D10 read side — labels resolved at read time, legacy rows migrated.
	 * ---------------------------------------------------------------- */

	public function test_roster_shows_id_keyed_and_legacy_label_keyed_answers_in_one_column() {
		$event_id = $this->event_with_questions();

		$modern = $this->make_seat( $event_id, [ 'name' => 'Modern Attendee', 'reg_fields' => [ 'practice_name' => 'New Practice' ] ] );
		$legacy = $this->make_seat( $event_id, [ 'name' => 'Legacy Attendee', 'reg_fields' => [ 'Practice name' => 'Old Practice' ] ] );

		// The stored bytes really are the two different spellings.
		$this->assertSame( [ 'Practice name' => 'Old Practice' ], get_post_meta( $legacy, '_anchor_event_reg_fields', true ) );

		$seats = $this->registrations()->query_seats( [ 'event_id' => $event_id, 'status' => 'all', 'per_page' => 50 ] );
		$by_id = [];
		foreach ( $seats['items'] as $row ) {
			$by_id[ (int) $row['id'] ] = $row['reg_fields'];
		}

		$this->assertSame( 'New Practice', $by_id[ $modern ]['practice_name'] ?? null );
		$this->assertSame(
			'Old Practice',
			$by_id[ $legacy ]['practice_name'] ?? null,
			'A legacy label-keyed answer must resolve onto its question at read time.'
		);

		// The roster screen renders both under the one "Practice name" column.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$html = $this->module()->roster->render_frontend( $event_id, 'https://example.org/manage/' );
		$this->assertStringContainsString( 'New Practice', $html );
		$this->assertStringContainsString( 'Old Practice', $html );
		$this->assertSame( 1, substr_count( $html, '<th scope="col">Practice name</th>' ), 'One question, one column.' );
	}

	public function test_export_collapses_both_spellings_into_one_column() {
		$event_id = $this->event_with_questions();
		$this->make_seat( $event_id, [ 'name' => 'Modern', 'reg_fields' => [ 'practice_name' => 'New Practice' ] ] );
		$this->make_seat( $event_id, [ 'name' => 'Legacy', 'reg_fields' => [ 'Practice name' => 'Old Practice' ] ] );

		$data = $this->registrations()->get_export_rows( $event_id, 'all' );

		$this->assertSame( [ 'practice_name' ], $data['field_keys'], 'The export must not grow a second column for the legacy spelling.' );
		foreach ( $data['rows'] as $row ) {
			$this->assertNotSame( '', (string) ( $row['fields']['practice_name'] ?? '' ) );
		}
	}

	public function test_an_answer_to_a_deleted_question_keeps_its_stored_key() {
		$event_id = $this->event_with_questions();
		$seat_id  = $this->make_seat( $event_id, [ 'reg_fields' => [ 'practice_name' => 'Anchor Dental', 'retired_q' => 'kept' ] ] );

		$resolved = $this->module()->resolve_registration_answers( $event_id, get_post_meta( $seat_id, '_anchor_event_reg_fields', true ) );

		$this->assertSame( 'Anchor Dental', $resolved['practice_name'] );
		$this->assertSame( 'kept', $resolved['retired_q'], 'An orphaned answer must survive, keyed by what was stored.' );
		$this->assertSame( 'retired_q', $this->module()->registration_answer_label( $event_id, 'retired_q' ), 'A gone question falls back to the stored key as its heading.' );
		$this->assertSame( 'Practice name', $this->module()->registration_answer_label( $event_id, 'practice_name' ) );
	}

	/* ------------------------------------------------------------------
	 * REG-D11 — renaming a question keeps its id, so answers follow it.
	 * ---------------------------------------------------------------- */

	public function test_renaming_a_question_keeps_its_key() {
		$event_id = $this->event_with_questions();

		// The repeater posts the existing key back in a hidden input.
		$posted = [
			'anchor_event_questions' => [
				0 => [ 'key' => 'practice_name', 'label' => 'Practice or organization', 'type' => 'text', 'required' => '1' ],
			],
		];
		$method = new ReflectionMethod( Module::class, 'save_registration_questions' );
		$method->setAccessible( true );
		$method->invoke( $this->module(), $event_id, $posted );

		$saved = $this->module()->get_registration_questions( $event_id );
		$this->assertCount( 1, $saved );
		$this->assertSame( 'practice_name', $saved[0]['key'], 'A rename must not regenerate the question id.' );
		$this->assertSame( 'Practice or organization', $saved[0]['label'] );
	}

	public function test_a_new_question_gets_a_new_key() {
		$event_id = $this->event_with_questions();

		$posted = [
			'anchor_event_questions' => [
				0 => [ 'key' => 'practice_name', 'label' => 'Practice name', 'type' => 'text' ],
				1 => [ 'key' => '', 'label' => 'Referring dentist', 'type' => 'text' ],
			],
		];
		$method = new ReflectionMethod( Module::class, 'save_registration_questions' );
		$method->setAccessible( true );
		$method->invoke( $this->module(), $event_id, $posted );

		$saved = $this->module()->get_registration_questions( $event_id );
		$this->assertSame( [ 'practice_name', 'referring-dentist' ], wp_list_pluck( $saved, 'key' ) );
	}

	public function test_answers_survive_a_rename() {
		$event_id = $this->event_with_questions();
		$seat_id  = $this->make_seat( $event_id, [ 'reg_fields' => [ 'practice_name' => 'Anchor Dental' ] ] );

		$questions             = $this->questions();
		$questions[0]['label'] = 'Practice or organization';
		update_post_meta( $event_id, Module::QUESTIONS_META, $questions );

		$seat = $this->registrations()->get_seat( $seat_id );
		$this->assertSame( 'Anchor Dental', $seat['reg_fields']['practice_name'] ?? null );
		$this->assertSame( 'Practice or organization', $this->module()->registration_answer_label( $event_id, 'practice_name' ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$html = $this->module()->roster->render_frontend( $event_id, 'https://example.org/manage/' );
		$this->assertStringContainsString( '<th scope="col">Practice or organization</th>', $html );
		$this->assertStringContainsString( 'Anchor Dental', $html );
	}

	/* ------------------------------------------------------------------
	 * REG-D9 — the dead filter is gone.
	 * ---------------------------------------------------------------- */

	public function test_the_empty_registration_fields_filter_is_retired() {
		$this->assertFalse(
			method_exists( $this->module(), 'get_registration_fields' ),
			'get_registration_fields() and its consumer-less filter are replaced by the question model.'
		);
	}
}
