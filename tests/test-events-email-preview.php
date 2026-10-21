<?php
/**
 * The Emails panel's View (Email_Preview): each catalog email rendered as it
 * would be sent to the registrant now, through the sender's own builder, with
 * no side effects.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Email_Preview;
use Anchor\Events\Module;
use Anchor\Events\Registrations;

/**
 * @group email
 * @group mail-log
 */
class Test_Events_Email_Preview extends WP_Ajax_UnitTestCase {

	use Anchor_Events_Fixtures;

	/** @var int pre_wp_mail calls. */
	private $pre_mail = 0;

	/** @var int wp_mail filter calls. */
	private $mail_filter = 0;

	/** @var array[] Messages as the senders handed them to wp_mail(). */
	private $mailed = [];

	public function set_up() {
		parent::set_up();
		remove_action( 'admin_init', '_maybe_update_core' );
		remove_action( 'admin_init', '_maybe_update_plugins' );
		remove_action( 'admin_init', '_maybe_update_themes' );
		Anchor_Mail_Log::install();
		$GLOBALS['wpdb']->query( 'DELETE FROM ' . Anchor_Mail_Log::table() ); // phpcs:ignore
		add_filter( 'pre_wp_mail', [ $this, 'count_pre_mail' ], 10, 2 );
		add_filter( 'wp_mail', [ $this, 'count_mail_filter' ], 1 );
		$this->_setRole( 'administrator' );
		$_GET  = [];
		$_POST = [];
	}

	public function tear_down() {
		remove_filter( 'pre_wp_mail', [ $this, 'count_pre_mail' ], 10 );
		remove_filter( 'wp_mail', [ $this, 'count_mail_filter' ], 1 );
		delete_option( Module::OPTION_KEY );
		$_GET  = [];
		$_POST = [];
		parent::tear_down();
	}

	public function count_pre_mail( $short, $atts ) {
		++$this->pre_mail;
		return true;
	}

	public function count_mail_filter( $atts ) {
		++$this->mail_filter;
		$this->mailed[] = $atts;
		return $atts;
	}

	private function catalog() {
		return $this->module()->email_log->catalog;
	}

	private function event() {
		$start    = time() + 10 * DAY_IN_SECONDS;
		$event_id = $this->make_event( [
			'title'            => 'Preview Event',
			'timezone'         => 'UTC',
			'start_date'       => gmdate( 'Y-m-d', $start ),
			'start_time'       => gmdate( 'H:i', $start ),
			'reminder_offsets' => '7,1',
		] );
		update_post_meta( $event_id, '_anchor_event_start_ts', $start );
		foreach ( [ 'confirmation', 'reminder', 'cancellation' ] as $type ) {
			update_post_meta( $event_id, '_anchor_event_email_tpl_' . $type, '<p>CUSTOM-' . strtoupper( $type ) . ' for {attendee_name}</p>{intro}' );
			update_post_meta( $event_id, '_anchor_event_email_intro_' . $type, 'Opening lines ' . $type );
		}
		return $event_id;
	}

	private function seat( $event_id, $status = Registrations::STATUS_CONFIRMED ) {
		$order = wc_create_order();
		$order->set_billing_first_name( 'Pat' );
		$order->set_billing_last_name( 'Quill' );
		$order->set_billing_email( 'pat@example.test' );
		$order->set_status( 'processing' );
		$order->save();
		$seat = $this->make_seat( $event_id, [ 'order_id' => $order->get_id(), 'name' => 'Pat Quill', 'email' => 'pat@example.test', 'status' => $status ] );
		return [ $order, $seat ];
	}

	private function render( $event_id, $seat_id, $key ) {
		return $this->catalog()->preview->render( $event_id, $this->module()->registrations->get_seat( $seat_id ), $key );
	}

	private function view( array $get ) {
		$_GET                 = $get;
		$this->_last_response = '';
		try {
			$this->_handleAjax( Email_Preview::AJAX_ACTION );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		} catch ( WPAjaxDieStopException $e ) {
			return $e->getMessage();
		}
		return $this->_last_response;
	}

	private function get_args( $event_id, $seat_id, $key ) {
		return [ 'event_id' => $event_id, 'seat_id' => $seat_id, 'email' => $key, '_wpnonce' => wp_create_nonce( Anchor_Mail_Log_Admin::NONCE ) ];
	}

	private function state( $order_id, $seat_id ) {
		return [
			'log'     => count( Anchor_Mail_Log::query( [] ) ),
			'notes'   => count( wc_get_order_notes( [ 'order_id' => $order_id ] ) ),
			'remind'  => get_post_meta( $seat_id, Registrations::META_REMINDERS_SENT, true ),
			'cancel'  => get_post_meta( $seat_id, Registrations::META_CANCEL_EMAILED, true ),
			'retry'   => get_post_meta( $seat_id, Registrations::META_EMAIL_RETRY, true ),
			'user'    => get_post_meta( $seat_id, '_anchor_event_user_id', true ),
			'users'   => count_users()['total_users'],
		];
	}

	public function test_each_type_renders_the_current_template_and_the_registrants_data() {
		$this->require_wc();
		$event_id               = $this->event();
		list( $order, $seat )   = $this->seat( $event_id );
		list( , $cancelled )    = $this->seat( $event_id, Registrations::STATUS_CANCELLED );

		foreach ( [ 'confirmation' => $seat, 'reminder:7' => $seat, 'reminder:1' => $seat, 'cancellation' => $cancelled ] as $key => $sid ) {
			$type  = strtok( $key, ':' );
			$built = $this->render( $event_id, $sid, $key );
			$this->assertIsArray( $built, $key );
			$this->assertStringContainsString( 'CUSTOM-' . strtoupper( $type ) . ' for Pat Quill', $built['html'], $key );
			$this->assertStringContainsString( 'Opening lines ' . $type, $built['html'], $key );
			$this->assertNotSame( '', $built['subject'], $key );
		}

		$receipt = $this->render( $event_id, $seat, 'receipt' );
		$this->assertStringContainsString( 'Pat', $receipt['html'] );
		$this->assertStringContainsString( (string) $order->get_order_number(), $receipt['html'] );
		$this->assertStringContainsString( (string) $order->get_order_number(), $receipt['subject'] . $receipt['html'] );

		$this->assertNull( $this->render( $event_id, $seat, 'reminder:3' ), 'An offset the event does not have.' );
	}

	public function test_preview_has_no_side_effects() {
		$this->require_wc();
		$event_id               = $this->event();
		list( $order, $seat )   = $this->seat( $event_id );
		list( $o2, $cancelled ) = $this->seat( $event_id, Registrations::STATUS_CANCELLED );
		update_post_meta( $cancelled, Registrations::META_CANCEL_EMAILED, 12345 );
		$this->pre_mail    = 0;
		$this->mail_filter = 0;
		$before            = [ $this->state( $order->get_id(), $seat ), $this->state( $o2->get_id(), $cancelled ) ];

		foreach ( [ 'receipt', 'confirmation', 'reminder:7', 'reminder:1' ] as $key ) {
			$this->assertNotNull( $this->render( $event_id, $seat, $key ), $key );
			$this->assertStringContainsString( '<', $this->view( $this->get_args( $event_id, $seat, $key ) ), $key );
		}
		$this->assertNotNull( $this->render( $event_id, $cancelled, 'cancellation' ) );
		$this->view( $this->get_args( $event_id, $cancelled, 'cancellation' ) );

		$this->assertSame( 0, $this->pre_mail, 'pre_wp_mail never ran' );
		$this->assertSame( 0, $this->mail_filter, 'the wp_mail filter never fired' );
		$this->assertSame( $before, [ $this->state( $order->get_id(), $seat ), $this->state( $o2->get_id(), $cancelled ) ] );
		$this->assertFalse( (bool) WC()->mailer()->get_emails()['WC_Email_Customer_Processing_Order']->sending, 'WC email left armed' );
	}

	/** A send would resolve (and here create) the seat's account to mint its sign-in link; a preview must not. */
	public function test_room_link_preview_creates_no_account_and_mints_no_token() {
		$event_id = $this->make_event( [
			'registration_mode'       => 'free',
			'access_role_enabled'     => true,
			'timezone'                => 'UTC',
			'start_ts'                => time() + DAY_IN_SECONDS,
			'end_ts'                  => time() + DAY_IN_SECONDS + 3600,
			'stream_default_modality' => 'virtual',
			'stream_embed'            => [ 'provider' => 'vimeo', 'kind' => 'iframe', 'src' => 'https://player.vimeo.com/video/9', 'raw' => '' ],
		] );
		$room = $this->module()->room_url( $event_id );
		$this->assertNotSame( '', $room, 'fixture has a room' );
		update_post_meta( $event_id, '_anchor_event_email_tpl_confirmation', '<p>Room: {room_link}</p>' );
		$seat = $this->make_seat( $event_id, [ 'name' => 'New Person', 'email' => 'brand-new@example.test' ] );
		delete_post_meta( $seat, '_anchor_event_user_id' );
		$user = get_user_by( 'email', 'brand-new@example.test' );
		if ( $user ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $user->ID ); // Unresolved, as a seat from before accounts existed.
		}
		$users = count_users()['total_users'];

		$built = $this->render( $event_id, $seat, 'confirmation' );
		$this->assertStringContainsString( esc_url( $room ), $built['html'] );
		$this->assertStringNotContainsString( 'aek=', $built['html'] );
		$this->assertSame( $users, count_users()['total_users'] );
		$this->assertSame( '', get_post_meta( $seat, '_anchor_event_user_id', true ) );
		$this->assertFalse( get_user_by( 'email', 'brand-new@example.test' ) );
	}

	public function test_endpoint_serves_the_email_with_subject_and_without_sign_in_tokens() {
		$this->require_wc();
		$event_id        = $this->event();
		list( , $seat )  = $this->seat( $event_id );
		$add_link        = static function ( $html ) {
			return str_replace( '</p>', ' <a href="https://x.test/live/?aek=secret&amp;y=2">Join</a></p>', $html );
		};
		add_filter( 'anchor_events_registration_email_html', $add_link );
		$out = $this->view( $this->get_args( $event_id, $seat, 'confirmation' ) );
		remove_filter( 'anchor_events_registration_email_html', $add_link );

		$this->assertStringContainsString( 'CUSTOM-CONFIRMATION for Pat Quill', $out );
		$this->assertStringContainsString( 'Subject:', $out );
		$this->assertStringContainsString( 'https://x.test/live/?y=2', $out );
		$this->assertStringNotContainsString( 'aek', $out );
	}

	public function test_endpoint_refusals() {
		$this->require_wc();
		$event_id       = $this->event();
		list( , $seat ) = $this->seat( $event_id );
		$other          = $this->event();

		$no_nonce = $this->get_args( $event_id, $seat, 'confirmation' );
		unset( $no_nonce['_wpnonce'] );
		$this->assertStringNotContainsString( 'CUSTOM-', $this->view( $no_nonce ), 'no nonce' );
		$this->assertStringNotContainsString( 'CUSTOM-', $this->view( $this->get_args( $other, $seat, 'confirmation' ) ), 'seat of another event' );
		$this->assertStringNotContainsString( 'CUSTOM-', $this->view( $this->get_args( $event_id, $seat, 'reminder:3' ) ), 'unknown email' );
		$this->_setRole( 'subscriber' );
		$this->assertStringNotContainsString( 'CUSTOM-', $this->view( $this->get_args( $event_id, $seat, 'confirmation' ) ), 'no capability' );
		$this->_setRole( 'administrator' );
		$this->assertStringContainsString( 'CUSTOM-CONFIRMATION', $this->view( $this->get_args( $event_id, $seat, 'confirmation' ) ), 'control' );
	}

	/** The preview and the send go through one builder, so they produce the same email. */
	public function test_send_output_equals_preview_output() {
		$this->require_wc();
		$event_id             = $this->event();
		list( , $seat )       = $this->seat( $event_id );
		list( , $cancelled )  = $this->seat( $event_id, Registrations::STATUS_CANCELLED );

		$free                 = $this->make_seat( $event_id, [ 'name' => 'Free Seat', 'email' => 'free@example.test' ] );

		foreach ( [ 'receipt' => $seat, 'confirmation' => $seat, 'confirmation ' => $free, 'reminder:7' => $seat, 'cancellation' => $cancelled ] as $key => $sid ) {
			$key          = trim( $key );
			$preview      = $this->render( $event_id, $sid, $key );
			$this->mailed = [];
			$result       = $this->catalog()->send( $event_id, $this->module()->registrations->get_seat( $sid ), $key );
			$this->assertTrue( $result->is_sent(), $key );
			$this->assertCount( 1, $this->mailed, $key );
			$sent = $this->mailed[0];
			$this->assertSame(
				Anchor_Mail_Log_Admin::strip_sign_in_tokens( $preview['html'] ),
				Anchor_Mail_Log_Admin::strip_sign_in_tokens( (string) $sent['message'] ),
				$key
			);
			$this->assertSame( html_entity_decode( $preview['subject'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ), wp_specialchars_decode( (string) $sent['subject'] ), $key );
		}
	}

	/**
	 * The confirmation row mirrors the sequence: a WooCommerce seat's is the
	 * order's buyer confirmation (to the buyer, buyer's name, the order's seat
	 * list); any other seat's is the attendee confirmation.
	 */
	public function test_wc_seat_uses_the_buyer_confirmation_and_a_free_seat_the_attendee_one() {
		$this->require_wc();
		$event_id = $this->event();
		$order    = wc_create_order();
		$order->set_billing_first_name( 'Pat' );
		$order->set_billing_last_name( 'Quill' );
		$order->set_billing_email( 'pat@example.test' );
		$order->set_status( 'processing' );
		$order->save();
		$sam  = $this->make_seat( $event_id, [ 'order_id' => $order->get_id(), 'name' => 'Sam Seat', 'email' => 'sam@example.test' ] );
		$lee  = $this->make_seat( $event_id, [ 'order_id' => $order->get_id(), 'name' => 'Lee Second', 'email' => 'lee@example.test' ] );
		$free = $this->make_seat( $event_id, [ 'name' => 'Free Seat', 'email' => 'free@example.test' ] );
		update_post_meta( $event_id, '_anchor_event_email_tpl_confirmation', '<p>CUSTOM-CONFIRMATION for {attendee_name}</p>{intro}{seat_list}' );

		$view = $this->render( $event_id, $sam, 'confirmation' );
		$this->assertStringContainsString( 'CUSTOM-CONFIRMATION for Pat Quill', $view['html'], 'buyer name' );
		$this->assertStringContainsString( 'Sam Seat', $view['html'], 'seat list' );
		$this->assertStringContainsString( 'Lee Second', $view['html'], 'seat list' );

		$this->mailed = [];
		$this->assertTrue( $this->catalog()->send( $event_id, $this->module()->registrations->get_seat( $sam ), 'confirmation' )->is_sent() );
		$this->assertSame( 'pat@example.test', $this->mailed[0]['to'] );
		$rows = Anchor_Mail_Log::query( [ 'order_id' => $order->get_id() ] );
		$this->assertSame( 'events.wc_confirmation', $rows[0]['kind'] );
		$this->assertMatchesRegularExpression( '#data-mail-row="confirmation">(?:(?!</tr>).)*>Sent #s', $this->catalog()->panel_for_seat( $event_id, $lee ), 'The order-level send counts for every seat on the order.' );

		$view = $this->render( $event_id, $free, 'confirmation' );
		$this->assertStringContainsString( 'CUSTOM-CONFIRMATION for Free Seat', $view['html'] );
		$this->mailed = [];
		$this->assertTrue( $this->catalog()->send( $event_id, $this->module()->registrations->get_seat( $free ), 'confirmation' )->is_sent() );
		$this->assertSame( 'free@example.test', $this->mailed[0]['to'] );
		$this->assertCount( 1, Anchor_Mail_Log::query( [ 'seat_id' => $free ] ) );
		$this->assertSame( 'events.confirmation', Anchor_Mail_Log::query( [ 'seat_id' => $free ] )[0]['kind'] );
	}

	/**
	 * The template builder's "Preview with real data" (wp_ajax_anchor_events_email_preview)
	 * through the real AJAX hook, as the browser calls it: the catalog View must not
	 * be registered on the same action (it was, and answered 404 first).
	 */
	public function test_builder_real_data_preview_of_a_reminder_shows_the_event_title() {
		$event_id = $this->make_event( [ 'title' => 'E2E Test Event' ] );
		$_POST    = [
			'action'       => 'anchor_events_email_preview',
			'nonce'        => wp_create_nonce( 'anchor_events_email_preview' ),
			'event_id'     => $event_id,
			'type'         => 'reminder',
			'template_b64' => base64_encode( 'E2E-MARKER-1 <h1>{event_title}</h1>{intro}' ),
		];
		$this->_last_response = '';
		try {
			$this->_handleAjax( 'anchor_events_email_preview' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		} catch ( WPAjaxDieStopException $e ) {
			// Expected.
		}
		$out = json_decode( (string) $this->_last_response, true );
		$this->assertIsArray( $out, (string) $this->_last_response );
		$this->assertTrue( $out['success'] );
		$this->assertStringContainsString( 'E2E Test Event', $out['data']['html'] );
		$this->assertStringContainsString( 'E2E-MARKER-1', $out['data']['html'] );
		$this->assertNotSame( 'anchor_events_email_preview', Email_Preview::AJAX_ACTION );
	}
}
