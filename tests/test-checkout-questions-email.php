<?php
// tests/test-checkout-questions-email.php
use Anchor\CheckoutQuestions\Services\OrderEmail;
use Anchor\Events\Module as EventsModule;

/** @group questions @group email */
class Test_Checkout_Questions_Email extends WP_UnitTestCase {

	private function snapshot(): array {
		return [
			[
				'set_id'    => 11,
				'set_title' => 'Q & A: Don\'t',
				'questions' => [
					[ 'key' => 'phone', 'label' => 'Phone', 'type' => 'text', 'options' => [], 'required' => false, 'admin_email' => true ],
					[ 'key' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'options' => [], 'required' => false, 'admin_email' => true ],
					[ 'key' => 'secret', 'label' => 'Private', 'type' => 'text', 'options' => [], 'required' => false, 'admin_email' => false ],
					[ 'key' => 'blank', 'label' => 'Blank', 'type' => 'text', 'options' => [], 'required' => false, 'admin_email' => true ],
				],
				'answers'   => [ 'phone' => 'R&D <b>', 'notes' => "line1\nline2 \"q\"", 'secret' => 'hidden-answer', 'blank' => ' ' ],
			],
			[
				'set_id'    => 12,
				'set_title' => 'Nothing flagged',
				'questions' => [ [ 'key' => 'phone', 'label' => 'Other phone', 'type' => 'text', 'options' => [], 'required' => false, 'admin_email' => false ] ],
				'answers'   => [ 'phone' => 'no-leak' ],
			],
		];
	}

	private function order(): WC_Order {
		$o = wc_create_order();
		$o->update_meta_data( '_anchor_cq_answers', $this->snapshot() );
		$o->save();
		return wc_get_order( $o->get_id() );
	}

	private function render( WC_Order $o, bool $plain, string $email_id = 'new_order' ): string {
		ob_start();
		( new OrderEmail() )->render( $o, true, $plain, (object) [ 'id' => $email_id ] );
		return (string) ob_get_clean();
	}

	public function test_html_is_exact() {
		$this->assertSame(
			'<h2>Q &amp; A: Don&#039;t</h2><p style="margin:0 0 12px;">Phone: R&amp;D &lt;b&gt;<br />Notes: line1 line2 &quot;q&quot;</p>',
			$this->render( $this->order(), false )
		);
	}

	public function test_plain_is_exact_and_raw() {
		$this->assertSame( "\nQ & A: Don't\nPhone: R&D <b>\nNotes: line1 line2 \"q\"\n", $this->render( $this->order(), true ) );
	}

	public function test_unflagged_and_blank_never_appear() {
		$out = $this->render( $this->order(), false );
		foreach ( [ 'Private', 'hidden-answer', 'Blank', 'Nothing flagged', 'no-leak' ] as $s ) {
			$this->assertStringNotContainsString( $s, $out );
		}
	}

	public function test_only_the_new_order_email() {
		$o = $this->order();
		foreach ( [ 'customer_processing_order', 'customer_completed_order', 'customer_on_hold_order', 'cancelled_order' ] as $id ) {
			$this->assertSame( '', $this->render( $o, false, $id ), $id );
			$this->assertSame( '', $this->render( $o, true, $id ), $id );
		}
	}

	public function test_order_without_answers_prints_nothing() {
		$o = wc_get_order( wc_create_order()->get_id() );
		$this->assertSame( '', $this->render( $o, false ) );
	}

	public function test_event_group_prints_before_set_group() {
		// Bare post: Anchor_Events_TestCase::make_event() creates access roles that leak into Test_Inertness.
		$event_id = (int) self::factory()->post->create( [ 'post_type' => EventsModule::CPT, 'post_status' => 'publish', 'post_title' => 'Combo Event' ] );
		update_post_meta( $event_id, EventsModule::QUESTIONS_META, [
			[ 'key' => 'heard', 'label' => 'Heard?', 'type' => 'text', 'options' => [], 'required' => false, 'admin_email' => true ],
		] );
		$order = wc_create_order();
		$item  = new WC_Order_Item_Product();
		$item->set_name( 'Ticket' );
		$item->set_quantity( 1 );
		$item->update_meta_data( '_anchor_event_id', $event_id );
		$item->update_meta_data( '_anchor_attendees', [ 1 => [ 'name' => 'Ann', 'fields' => [ 'heard' => 'A friend' ] ] ] );
		$order->add_item( $item );
		$order->update_meta_data( '_anchor_cq_answers', $this->snapshot() );
		$order->save();
		$order = wc_get_order( $order->get_id() );

		ob_start();
		do_action( 'woocommerce_email_order_meta', $order, true, false, (object) [ 'id' => 'new_order' ] );
		$out = (string) ob_get_clean();

		$event_pos = strpos( $out, '<h2>Combo Event</h2>' );
		$set_pos   = strpos( $out, '<h2>Q &amp; A: Don&#039;t</h2>' );
		$this->assertNotFalse( $event_pos );
		$this->assertNotFalse( $set_pos );
		$this->assertLessThan( $set_pos, $event_pos );
		$this->assertSame( 1, substr_count( $out, 'Phone: R&amp;D' ), 'Set printed once.' );
	}
}
