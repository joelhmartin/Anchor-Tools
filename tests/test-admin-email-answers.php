<?php
/**
 * "Include in admin email" on attendee questions: the free-registration admin
 * notice and WooCommerce's "New order" email list ticked questions as
 * `Question label: answer`.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;
use Anchor\Events\Registrations;

/**
 * @group email
 * @group questions
 * @group registration
 */
class Test_Admin_Email_Answers extends Anchor_Events_TestCase {

	/** @var array wp_mail() atts captured by pre_wp_mail. */
	private $mails = [];

	public function set_up() {
		parent::set_up();
		$this->mails = [];
		add_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10, 2 );
	}

	public function tear_down() {
		remove_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10 );
		delete_option( Module::OPTION_KEY );
		parent::tear_down();
	}

	public function capture_mail( $short_circuit, $atts ) {
		$this->mails[] = $atts;
		return true;
	}

	private function questions() {
		return [
			[ 'key' => 'heard', 'label' => 'How did you hear about us?', 'type' => 'text', 'options' => [], 'required' => false, 'admin_email' => true ],
			[ 'key' => 'diet', 'label' => 'Dietary restrictions?', 'type' => 'multiselect', 'options' => [ 'Vegan', 'Gluten-free', 'Other' ], 'required' => false, 'admin_email' => true ],
			[ 'key' => 'secret', 'label' => 'Private note', 'type' => 'text', 'options' => [], 'required' => false, 'admin_email' => false ],
			[ 'key' => 'legacy', 'label' => 'Legacy question', 'type' => 'text', 'options' => [], 'required' => false ],
			[ 'key' => 'empty_q', 'label' => 'Left blank', 'type' => 'text', 'options' => [], 'required' => false, 'admin_email' => true ],
		];
	}

	private function event_with_questions() {
		$event_id = $this->make_event( [ 'title' => 'Admin Mail Event', 'start_date' => '2030-10-23' ] );
		update_post_meta( $event_id, Module::QUESTIONS_META, $this->questions() );
		return $event_id;
	}

	private function answers() {
		return [
			'heard'   => 'A friend',
			'diet'    => 'Vegan, Gluten-free, Other: shellfish',
			'secret'  => 'do not leak',
			'legacy'  => 'old answer',
			'empty_q' => '  ',
		];
	}

	/** Free registration path: plain-text admin notice. */
	private function send_free_notice( $event_id, $seat_id ) {
		$this->module()->send_registration_emails( $event_id, 'Pat Doe', 'pat@example.test', Registrations::STATUS_CONFIRMED, 0, $seat_id );
		foreach ( $this->mails as $mail ) {
			if ( false !== strpos( (string) $mail['subject'], 'New registration' ) ) {
				return (string) $mail['message'];
			}
		}
		$this->fail( 'No admin notice was sent.' );
	}

	public function test_ticked_questions_appear_in_the_free_admin_notice() {
		$event_id = $this->event_with_questions();
		$seat_id  = $this->make_seat( $event_id, [ 'reg_fields' => $this->answers() ] );
		update_option( Module::OPTION_KEY, array_merge( $this->module()->get_settings(), [ 'notify_admin' => 1 ] ) );

		$body = $this->send_free_notice( $event_id, $seat_id );

		$this->assertStringContainsString( "How did you hear about us?: A friend", $body );
	}

	public function test_plain_text_notice_is_not_html_encoded() {
		$event_id = $this->event_with_questions();
		$seat_id  = $this->make_seat( $event_id, [ 'reg_fields' => [ 'heard' => 'R&D <b>' ] ] );
		update_option( Module::OPTION_KEY, array_merge( $this->module()->get_settings(), [ 'notify_admin' => 1 ] ) );

		$body = $this->send_free_notice( $event_id, $seat_id );

		$this->assertStringContainsString( 'How did you hear about us?: R&D <b>', $body );
		$this->assertStringNotContainsString( '&amp;', $body );
		$this->assertStringNotContainsString( '&lt;', $body );
	}

	public function test_unticked_questions_never_appear() {
		$event_id = $this->event_with_questions();
		$seat_id  = $this->make_seat( $event_id, [ 'reg_fields' => $this->answers() ] );
		update_option( Module::OPTION_KEY, array_merge( $this->module()->get_settings(), [ 'notify_admin' => 1 ] ) );

		$body = $this->send_free_notice( $event_id, $seat_id );

		$this->assertStringNotContainsString( 'Private note', $body );
		$this->assertStringNotContainsString( 'do not leak', $body );
		$this->assertStringNotContainsString( 'Legacy question', $body, 'A question with no flag defaults to off.' );
	}

	public function test_multi_choice_answers_are_joined_including_other() {
		$event_id = $this->event_with_questions();
		$seat_id  = $this->make_seat( $event_id, [ 'reg_fields' => $this->answers() ] );
		update_option( Module::OPTION_KEY, array_merge( $this->module()->get_settings(), [ 'notify_admin' => 1 ] ) );

		$body = $this->send_free_notice( $event_id, $seat_id );

		$this->assertStringContainsString( 'Dietary restrictions?: Vegan, Gluten-free, Other: shellfish', $body );
	}

	public function test_an_empty_answer_is_skipped() {
		$event_id = $this->event_with_questions();
		$seat_id  = $this->make_seat( $event_id, [ 'reg_fields' => $this->answers() ] );
		update_option( Module::OPTION_KEY, array_merge( $this->module()->get_settings(), [ 'notify_admin' => 1 ] ) );

		$body = $this->send_free_notice( $event_id, $seat_id );

		$this->assertStringNotContainsString( 'Left blank', $body );
	}

	public function test_no_answers_means_no_extra_lines() {
		$event_id = $this->event_with_questions();
		$seat_id  = $this->make_seat( $event_id, [ 'reg_fields' => [] ] );
		$lines    = \Anchor\Events\Admin_Email_Answers::text_lines( $this->module(), $event_id, $seat_id );
		$this->assertSame( [], $lines );
	}

	public function test_the_attendee_confirmation_never_carries_the_admin_lines() {
		$event_id = $this->event_with_questions();
		$seat_id  = $this->make_seat( $event_id, [ 'reg_fields' => $this->answers() ] );
		update_option( Module::OPTION_KEY, array_merge( $this->module()->get_settings(), [ 'notify_admin' => 1, 'notify_user' => 1 ] ) );

		$this->module()->send_registration_emails( $event_id, 'Pat Doe', 'pat@example.test', Registrations::STATUS_CONFIRMED, 0, $seat_id );

		foreach ( $this->mails as $mail ) {
			if ( 'pat@example.test' === $mail['to'] || ( is_array( $mail['to'] ) && in_array( 'pat@example.test', $mail['to'], true ) ) ) {
				$this->assertStringNotContainsString( 'A friend', (string) $mail['message'] );
			}
		}
		$this->assertNotEmpty( $this->mails );
	}

	public function test_the_setting_saves_and_loads() {
		$event_id = $this->make_event();
		$method   = new ReflectionMethod( get_class( $this->module() ), 'save_registration_questions' );
		$method->setAccessible( true );
		$method->invoke( $this->module(), $event_id, [
			'anchor_event_questions_present' => '1',
			'anchor_event_questions'         => [
				[ 'label' => 'Ticked', 'type' => 'text', 'admin_email' => '1' ],
				[ 'label' => 'Unticked', 'type' => 'text' ],
			],
		] );

		$loaded = $this->module()->get_registration_questions( $event_id );
		$this->assertTrue( $loaded[0]['admin_email'] );
		$this->assertFalse( $loaded[1]['admin_email'] );
	}

	public function test_questions_saved_before_the_setting_existed_load_as_off() {
		$event_id = $this->make_event();
		update_post_meta( $event_id, Module::QUESTIONS_META, [
			[ 'key' => 'old', 'label' => 'Old question', 'type' => 'text', 'options' => [], 'required' => true ],
		] );
		$this->assertFalse( $this->module()->get_registration_questions( $event_id )[0]['admin_email'] );
	}

	public function test_the_row_editor_renders_the_checkbox() {
		$method = new ReflectionMethod( get_class( $this->module() ), 'event_question_row_html' );
		$method->setAccessible( true );
		$on  = $method->invoke( $this->module(), 0, [ 'label' => 'Q', 'admin_email' => true ] );
		$off = $method->invoke( $this->module(), 0, [ 'label' => 'Q' ] );
		$this->assertStringContainsString( 'Include in admin email', $on );
		$this->assertMatchesRegularExpression( '/name="anchor_event_questions\[0\]\[admin_email\]"[^>]*checked/', $on );
		$this->assertDoesNotMatchRegularExpression( '/\[admin_email\]"[^>]*checked/', $off );
	}

	public function test_a_child_date_inherits_the_setting_with_the_questions() {
		$parent_id = $this->make_event( [ 'title' => 'Workshop' ] );
		update_post_meta( $parent_id, '_anchor_event_offering_dates', [
			[ 'date' => '2027-05-04', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Session A', 'capacity' => 10 ],
		] );
		update_post_meta( $parent_id, Module::QUESTIONS_META, $this->questions() );
		$live = $this->module()->occurrences->reconcile( $parent_id );
		$this->assertCount( 1, $live, 'Precondition: a child date exists.' );
		$kids = [ (int) $live[0] ];

		$child_questions = $this->module()->get_registration_questions( (int) $kids[0] );
		$this->assertTrue( $child_questions[0]['admin_email'] );
		$this->assertFalse( $child_questions[2]['admin_email'] );
	}

	/** An order whose line carries the checkout's attendee snapshot (no seats yet). */
	private function order_with_attendees( $event_id, array $attendees ) {
		$order = wc_create_order();
		$item  = new WC_Order_Item_Product();
		$item->set_name( 'Ticket' );
		$item->set_quantity( count( $attendees ) );
		$item->update_meta_data( '_anchor_event_id', $event_id );
		$item->update_meta_data( '_anchor_attendees', $attendees );
		$order->add_item( $item );
		$order->save();
		return wc_get_order( $order->get_id() );
	}

	private function render_wc_email( $order, $email_id, $plain_text = false ) {
		$email = (object) [ 'id' => $email_id ];
		ob_start();
		$this->woocommerce()->render_new_order_email_answers( $order, 'new_order' === $email_id, $plain_text, $email );
		return (string) ob_get_clean();
	}

	public function test_the_woocommerce_new_order_email_lists_ticked_answers_per_attendee() {
		$this->require_wc();
		$event_id = $this->event_with_questions();
		$order    = $this->order_with_attendees( $event_id, [
			1 => [ 'name' => 'Ann', 'fields' => $this->answers() ],
			2 => [ 'name' => 'Bob', 'fields' => [ 'heard' => 'Radio & TV <b>', 'diet' => '' ] ],
		] );

		$html = $this->render_wc_email( $order, 'new_order' );

		$this->assertStringContainsString( 'Admin Mail Event', $html );
		$this->assertStringContainsString( 'How did you hear about us?: A friend', $html );
		$this->assertStringContainsString( 'Dietary restrictions?: Vegan, Gluten-free, Other: shellfish', $html );
		$this->assertStringContainsString( 'How did you hear about us?: Radio &amp; TV &lt;b&gt;', $html, 'Answers are escaped.' );
		$this->assertStringNotContainsString( 'Private note', $html );
		$this->assertStringNotContainsString( 'Left blank', $html );
		$this->assertMatchesRegularExpression(
			'/Ann.*How did you hear about us\?: A friend.*Dietary restrictions\?: Vegan.*Bob.*How did you hear about us\?: Radio &amp; TV &lt;b&gt;/s',
			$html,
			'Ann heading, Ann lines, Bob heading, Bob lines, in that order.'
		);
		$this->assertSame( 1, substr_count( $html, 'Dietary restrictions?' ), 'Bob left the multi-choice blank, so only Ann has that line.' );
	}

	public function test_the_plain_text_new_order_email_is_not_html_encoded() {
		$this->require_wc();
		$event_id = $this->event_with_questions();
		$order    = $this->order_with_attendees( $event_id, [ 1 => [ 'name' => 'Ann', 'fields' => [ 'heard' => 'R&D <b>' ] ] ] );

		$text = $this->render_wc_email( $order, 'new_order', true );

		$this->assertStringContainsString( "Ann\nHow did you hear about us?: R&D <b>", $text );
		$this->assertStringNotContainsString( '<p', $text );
	}

	public function test_customer_woocommerce_emails_never_carry_the_answers() {
		$this->require_wc();
		$event_id = $this->event_with_questions();
		$order    = $this->order_with_attendees( $event_id, [ 1 => [ 'name' => 'Ann', 'fields' => $this->answers() ] ] );

		foreach ( [ 'customer_processing_order', 'customer_completed_order', 'customer_on_hold_order', 'cancelled_order' ] as $id ) {
			$this->assertSame( '', $this->render_wc_email( $order, $id ), $id . ' gets no answers.' );
		}
	}

	public function test_the_organizer_notice_no_longer_carries_the_answers() {
		$this->require_wc();
		$event_id = $this->event_with_questions();
		$order    = wc_create_order();
		$order->set_billing_email( 'buyer@example.test' );
		$order->save();
		$this->make_seat( $event_id, [ 'order_id' => $order->get_id(), 'name' => 'Ann', 'reg_fields' => $this->answers() ] );

		$method = new ReflectionMethod( get_class( $this->woocommerce() ), 'send_organizer_notice' );
		$method->setAccessible( true );
		$method->invokeArgs( $this->woocommerce(), [ $order, $this->module()->get_settings(), $event_id, [ 'confirmed' => 1, 'waitlist' => 0, 'released' => 0 ], 'confirmed' ] );

		$this->assertNotEmpty( $this->mails, 'The organizer notice was sent.' );
		$this->assertStringNotContainsString( 'A friend', (string) $this->mails[0]['message'] );
	}
}
