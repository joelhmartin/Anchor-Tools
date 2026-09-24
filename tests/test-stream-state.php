<?php
/**
 * Stream_State table tests (virtual-events spec §5.3). Pure function — no
 * WooCommerce, no posts for the decide() cases.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Stream_State;

/**
 * @group stream-state
 */
class Test_Stream_State extends Anchor_Events_TestCase {

	/** One virtual session, an embed, and the standard 15/30 windows. */
	private function sessions( array $overrides = [] ) {
		return [ array_merge( [
			'date' => '2027-05-01', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Day 1',
			'modality' => 'virtual',
			'stream_embed' => [ 'provider' => 'vimeo', 'kind' => 'iframe', 'src' => 'https://player.vimeo.com/video/1', 'raw' => '' ],
			'start_ts' => 1000000, 'end_ts' => 1007200,
		], $overrides ) ];
	}

	public function test_unavailable_when_not_stream_capable() {
		$out = Stream_State::decide( $this->sessions(), 900, 1800, 1000000, false );
		$this->assertSame( Stream_State::UNAVAILABLE, $out['state'] );
		$this->assertSame( [], $out['embed'] );
	}

	public function test_unavailable_when_every_session_is_in_person() {
		$out = Stream_State::decide( $this->sessions( [ 'modality' => 'in_person' ] ), 900, 1800, 1, true );
		$this->assertSame( Stream_State::UNAVAILABLE, $out['state'] );
	}

	public function test_pending_when_a_virtual_session_has_no_embed() {
		$out = Stream_State::decide( $this->sessions( [ 'stream_embed' => [] ] ), 900, 1800, 1, true );
		$this->assertSame( Stream_State::PENDING, $out['state'] );
	}

	public function test_countdown_targets_the_open_instant() {
		$out = Stream_State::decide( $this->sessions(), 900, 1800, 1000000 - 5000, true );
		$this->assertSame( Stream_State::COUNTDOWN, $out['state'] );
		$this->assertSame( 1000000 - 900, $out['target_ts'] );
		$this->assertSame( [], $out['embed'], 'The embed is never exposed before the window opens.' );
	}

	/** Both boundary instants are INSIDE the live window. */
	public function test_live_boundaries_inclusive() {
		foreach ( [ 1000000 - 900, 1000000, 1007200, 1007200 + 1800 ] as $now ) {
			$out = Stream_State::decide( $this->sessions(), 900, 1800, $now, true );
			$this->assertSame( Stream_State::LIVE, $out['state'], "now={$now}" );
			$this->assertSame( 'https://player.vimeo.com/video/1', $out['embed']['src'] );
			$this->assertSame( 1007200 + 1800, $out['target_ts'] );
		}
	}

	public function test_between_two_days() {
		$day1 = $this->sessions()[0];
		$day2 = array_merge( $day1, [ 'date' => '2027-05-02', 'label' => 'Day 2', 'start_ts' => 1086400, 'end_ts' => 1093600 ] );
		$out  = Stream_State::decide( [ $day1, $day2 ], 900, 1800, 1007200 + 1801, true );

		$this->assertSame( Stream_State::BETWEEN, $out['state'] );
		$this->assertSame( 1, $out['session_index'] );
		$this->assertSame( 1086400 - 900, $out['target_ts'] );
		$this->assertSame( [], $out['embed'] );
	}

	public function test_ended_after_the_last_close() {
		$out = Stream_State::decide( $this->sessions(), 900, 1800, 1007200 + 1801, true );
		$this->assertSame( Stream_State::ENDED, $out['state'] );
	}

	/** A hybrid session is streamable too. */
	public function test_hybrid_session_is_live() {
		$out = Stream_State::decide( $this->sessions( [ 'modality' => 'hybrid' ] ), 900, 1800, 1000001, true );
		$this->assertSame( Stream_State::LIVE, $out['state'] );
	}

	/**
	 * Controller ruling (carried into this task): a session whose end_ts is
	 * before its start_ts — a mis-typed end time; session rows carry one date
	 * so they cannot legitimately cross midnight — is treated as zero-length:
	 * end_ts collapses to start_ts, so the live window is still just
	 * start − open_before … start + close_after.
	 */
	public function test_end_before_start_is_treated_as_zero_length() {
		$sessions = $this->sessions( [ 'start_ts' => 1000000, 'end_ts' => 999000 ] );

		// Just past the (collapsed) close instant → ended, not still live.
		$out = Stream_State::decide( $sessions, 900, 1800, 1000000 + 1801, true );
		$this->assertSame( Stream_State::ENDED, $out['state'] );

		// At the collapsed close instant → still live (inclusive boundary).
		$out = Stream_State::decide( $sessions, 900, 1800, 1000000 + 1800, true );
		$this->assertSame( Stream_State::LIVE, $out['state'] );
	}

	/** A single event with no sessions rows resolves through its own bounds. */
	public function test_for_event_single_event_uses_implicit_session() {
		$start    = time() + DAY_IN_SECONDS;
		$event_id = $this->make_event( [
			'timezone'   => 'UTC',
			'start_date' => gmdate( 'Y-m-d', $start ),
			'start_time' => gmdate( 'H:i', $start ),
			'end_date'   => gmdate( 'Y-m-d', $start + 3600 ),
			'end_time'   => gmdate( 'H:i', $start + 3600 ),
			'start_ts'   => $start,
			'end_ts'     => $start + 3600,
			'registration_mode'       => 'free',
			'stream_default_modality' => 'virtual',
			'stream_embed'            => [ 'provider' => 'vimeo', 'kind' => 'iframe', 'src' => 'https://player.vimeo.com/video/9', 'raw' => '' ],
		] );

		$out = Stream_State::for_event( $event_id, time() );
		$this->assertSame( Stream_State::COUNTDOWN, $out['state'] );
	}

	/** An external-registration event is never stream-capable. */
	public function test_for_event_external_is_unavailable() {
		$event_id = $this->make_event( [
			'registration_mode'       => 'external',
			'stream_default_modality' => 'virtual',
			'stream_embed'            => [ 'provider' => 'vimeo', 'kind' => 'iframe', 'src' => 'https://player.vimeo.com/video/9', 'raw' => '' ],
		] );
		$this->assertSame( Stream_State::UNAVAILABLE, Stream_State::for_event( $event_id, time() )['state'] );
	}

	/** A session crossing midnight in the event zone keeps one contiguous window. */
	public function test_session_crossing_midnight() {
		$row = $this->sessions( [ 'start_ts' => 1000000, 'end_ts' => 1000000 + ( 4 * HOUR_IN_SECONDS ) ] );
		$out = Stream_State::decide( $row, 900, 1800, 1000000 + ( 3 * HOUR_IN_SECONDS ), true );
		$this->assertSame( Stream_State::LIVE, $out['state'] );
	}
}
