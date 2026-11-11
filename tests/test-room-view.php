<?php
/**
 * The entitled room's layout (Room_View), the chat embed, and the Vimeo event URL fix.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Embed;

/**
 * @group room
 */
class Test_Room_View extends Anchor_Events_TestCase {

	private $minted = [];

	public function tear_down() {
		foreach ( $this->minted as $id ) {
			remove_role( 'anchor_event_' . $id );
		}
		$this->minted = [];
		remove_all_filters( 'anchor_events_stream_now' );
		remove_all_filters( 'anchor_events_room_login_form' );
		parent::tear_down();
	}

	/** Two online sessions around one in-person day with no time, like a hybrid course. */
	private function course( array $extra = [] ) {
		$event_id = $this->make_event( array_merge( [
			'type'                => 'multisession',
			'registration_mode'   => 'free',
			'access_role_enabled' => true,
			'timezone'            => 'UTC',
			'address_city'        => 'Cleveland',
			'stream_embed'        => [ 'provider' => 'vimeo', 'kind' => 'iframe', 'src' => 'https://vimeo.com/event/9/embed', 'raw' => '' ],
			'sessions'            => [
				[ 'date' => '2027-02-05', 'start_time' => '08:00', 'end_time' => '12:00', 'label' => 'Session 1', 'modality' => 'virtual' ],
				[ 'date' => '2027-02-10', 'start_time' => '', 'end_time' => '', 'label' => 'Bootcamp', 'modality' => 'in_person' ],
				[ 'date' => '2027-02-19', 'start_time' => '08:00', 'end_time' => '12:00', 'label' => 'Session 2', 'modality' => 'virtual' ],
			],
		], $extra ) );
		$this->minted[] = $event_id;
		$user_id = self::factory()->user->create( [ 'user_email' => 'view' . $event_id . '@example.test' ] );
		$this->make_seat( $event_id, [ 'email' => 'view' . $event_id . '@example.test' ] );
		wp_set_current_user( $user_id );
		return $event_id;
	}

	private function at( $when ) {
		$now = strtotime( $when . ' UTC' );
		add_filter( 'anchor_events_stream_now', function () use ( $now ) { return $now; } );
	}

	public function test_between_sessions_marks_done_and_next() {
		$event_id = $this->course();
		$this->at( '2027-02-12 09:00:00' );
		$html = $this->module()->render_room( $event_id );

		$this->assertStringContainsString( 'data-state="between"', $html );
		$this->assertMatchesRegularExpression( '/is-done[^"]*"[^>]*>.*?Session 1/s', $html );
		$this->assertMatchesRegularExpression( '/is-next[^"]*"[^>]*>.*?Session 2/s', $html );
		$this->assertStringContainsString( '1 of 2 online sessions done', $html );
		$this->assertStringContainsString( 'Next: Session 2 of 2', $html );
		$this->assertStringContainsString( 'Up next: Session 2', $html );
		$this->assertStringContainsString( 'data:text/calendar', $html );
	}

	/** A session with no start time is a date, never midnight. */
	public function test_untimed_in_person_day_prints_no_time() {
		$event_id = $this->course();
		$this->at( '2027-02-01 09:00:00' );
		$html = $this->module()->render_room( $event_id );

		$this->assertMatchesRegularExpression( '/Bootcamp<\/span><span class="anchor-room-session-meta">In person · Cleveland<\/span>/', $html );
		$this->assertStringNotContainsString( '12:00 AM', $html );
	}

	public function test_live_without_chat_has_one_column_player() {
		$event_id = $this->course();
		$this->at( '2027-02-05 09:00:00' );
		$html = $this->module()->render_room( $event_id );

		$this->assertStringContainsString( 'data-state="live"', $html );
		$this->assertStringContainsString( '<div class="anchor-room-live">', $html );
		$this->assertStringNotContainsString( 'anchor-room-chat', $html );
		$this->assertStringContainsString( 'Session 1, live now', $html );
	}

	public function test_live_with_chat_renders_both_iframes() {
		$event_id = $this->course( [ 'stream_chat' => Embed::normalize_chat( 'https://vimeo.com/event/9/embed/interaction' ) ] );
		$this->at( '2027-02-05 09:00:00' );
		$html = $this->module()->render_room( $event_id );

		$this->assertStringContainsString( 'anchor-room-live has-chat', $html );
		$this->assertStringContainsString( 'https://vimeo.com/event/9/embed"', $html );
		$this->assertStringContainsString( 'https://vimeo.com/live/interaction_tools/live_event/9', $html );
	}

	public function test_chat_is_hidden_before_the_session() {
		$event_id = $this->course( [ 'stream_chat' => Embed::normalize_chat( 'https://vimeo.com/event/9/chat/' ) ] );
		$this->at( '2027-02-01 09:00:00' );
		$this->assertStringNotContainsString( 'interaction_tools', $this->module()->render_room( $event_id ) );
	}

	public function test_login_form_filter_replaces_wp_login_form() {
		$event_id = $this->course();
		wp_set_current_user( 0 );
		add_filter( 'anchor_events_room_login_form', function ( $html, $id, $redirect ) use ( $event_id ) {
			return $id === $event_id && $redirect !== '' ? '<form class="theme-login"></form>' : $html;
		}, 10, 3 );
		$html = $this->module()->render_room( $event_id );

		$this->assertStringContainsString( 'theme-login', $html );
		$this->assertStringNotContainsString( 'loginform', $html );
	}

	public function test_staff_view_has_no_dead_preview_link() {
		$event_id = $this->course();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertStringNotContainsString( 'anchor_room_preview', $this->module()->render_room( $event_id ) );
	}

	public function test_normalize_chat_variants() {
		$want = 'https://vimeo.com/live/interaction_tools/live_event/6348439';
		foreach ( [
			'https://vimeo.com/event/6348439/chat/',
			'https://vimeo.com/event/6348439/embed/interaction',
			'<iframe src="https://vimeo.com/event/6348439/embed/interaction" frameborder="0"></iframe>',
			'https://vimeo.com/live/interaction_tools/live_event/6348439',
		] as $in ) {
			$this->assertSame( $want, Embed::normalize_chat( $in )['src'], $in );
		}
		$yt = Embed::normalize_chat( 'https://www.youtube.com/live_chat?v=abcdef12345' );
		$this->assertStringStartsWith( 'https://www.youtube.com/live_chat?v=abcdef12345&embed_domain=', $yt['src'] );
		$this->assertWPError( Embed::normalize_chat( 'https://evil.example/chat' ) );
		$this->assertWPError( Embed::normalize_chat( 'http://vimeo.com/event/1/chat' ) );
	}

	/** The combined video+chat code becomes the video-only player; old player.vimeo.com srcs are upgraded. */
	public function test_vimeo_event_embed_urls() {
		$this->assertSame(
			'https://vimeo.com/event/6348439/embed',
			Embed::normalize( '<iframe src="https://vimeo.com/event/6348439/embed/interaction"></iframe>' )['src']
		);
		$this->assertSame( 'https://vimeo.com/event/5/embed/ab12cd', Embed::normalize( 'https://vimeo.com/event/5/embed/ab12cd' )['src'] );
		$this->assertSame( 'https://vimeo.com/event/5843680/embed', Embed::current_src( 'https://player.vimeo.com/event/5843680/embed' ) );
		$html = Embed::render( [ 'provider' => 'vimeo', 'kind' => 'iframe', 'src' => 'https://player.vimeo.com/event/7/embed' ], 'T' );
		$this->assertStringContainsString( 'src="https://vimeo.com/event/7/embed"', $html );
	}
}
