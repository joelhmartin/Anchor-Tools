<?php
/**
 * One timezone resolution, one clock (RENDER-D2, MODEL-D20, MODEL-D21,
 * MODEL-D11, MODEL-D12, MODEL-D13, RENDER-D30).
 *
 * Production is the shape these mostly fire on: `timezone_string` is '' and
 * `gmt_offset` is -6, so `wp_timezone_string()` answers '-06:00' — a real zone
 * for arithmetic, but not a name `get_option('timezone_string')` can see.
 * site_on_a_bare_offset() sets exactly that. Three tests deliberately set a
 * DIFFERENT site instead, and say why in their own docblocks: the all-day node
 * needs a POSITIVE offset to fail at all, and the notice needs a named zone and
 * a default '+00:00' install to prove it stays quiet.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Event_Schema;
use Anchor\Events\Module;

/**
 * @group time
 */
class Test_Timezone extends Anchor_Events_TestCase {

	/** The production shape: a raw gmt_offset and no named zone at all. */
	private function site_on_a_bare_offset() {
		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', -6 );
		$this->assertSame( '-06:00', wp_timezone_string(), 'Precondition: the site resolves to a fixed offset.' );
	}

	/** @return Event_Schema */
	private function schema() {
		return $this->module()->event_schema;
	}

	/** Reach the schema's private resolver — the point is that it delegates. */
	private function schema_timezone( array $meta ) {
		$m = new ReflectionMethod( Event_Schema::class, 'resolve_timezone' );
		$m->setAccessible( true );
		return $m->invoke( $this->schema(), $meta );
	}

	private function set_timezone_mode( $mode ) {
		$settings                   = get_option( Module::OPTION_KEY, [] );
		$settings                   = is_array( $settings ) ? $settings : [];
		$settings['timezone_mode']  = $mode;
		update_option( Module::OPTION_KEY, $settings );
		$this->module()->clear_caches();
	}

	/* ------------------------------------------------------------------
	 * RENDER-D2 / MODEL-D20 — the schema renders in the site's zone.
	 * ------------------------------------------------------------------ */

	/**
	 * The live symptom: an 08:00 local course published
	 * `"startDate":"...T14:00:00+00:00"`, because the schema resolved the zone
	 * from `timezone_string` (empty here) while the timestamp it was rendering
	 * had been computed from `wp_timezone_string()`.
	 */
	public function test_schema_start_date_carries_the_site_offset_not_utc() {
		$this->site_on_a_bare_offset();
		$event = $this->make_event(
			[
				'title'      => 'Offset Course',
				'start_date' => '2030-12-11',
				'start_time' => '08:00',
				'end_time'   => '17:00',
				'all_day'    => false,
			]
		);

		$node = $this->schema()->for_event( $event );

		$this->assertSame( '2030-12-11T08:00:00-06:00', $node['startDate'] );
		$this->assertSame( '2030-12-11T17:00:00-06:00', $node['endDate'] );
	}

	/**
	 * An all-day node collapses to `Y-m-d` IN THE RENDERED ZONE, so rendering a
	 * local-midnight instant through UTC names the wrong DAY, not merely the
	 * wrong offset.
	 *
	 * Deliberately a UTC+9 site, not the -06:00 fixture the rest of this class
	 * uses: at -06:00 local midnight is 06:00Z on the SAME date, so a UTC
	 * rendering produces the right string by luck and the test cannot fail. At
	 * +09:00 local midnight is 15:00Z on the PREVIOUS date, which is the actual
	 * defect — a UTC+X site advertising every all-day event a day early.
	 */
	public function test_all_day_schema_date_is_the_local_date_not_the_utc_one() {
		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', 9 );
		$this->assertSame( '+09:00', wp_timezone_string(), 'Precondition: a positive-offset site.' );

		$event = $this->make_event(
			[
				'title'      => 'All Day Offset',
				'start_date' => '2030-12-05',
				'all_day'    => true,
			]
		);

		$ts = $this->module()->compute_timestamps( $this->module()->get_meta( $event ) );
		$this->assertSame(
			'2030-12-04',
			gmdate( 'Y-m-d', $ts['start'] ),
			'Precondition: the instant itself falls on the previous date in UTC.'
		);

		$node = $this->schema()->for_event( $event );

		$this->assertSame( '2030-12-05', $node['startDate'] );
	}

	/**
	 * An Offer's `validFrom` is the same wall-clock -> instant question, so it
	 * goes through the same Module::to_timestamp() rather than a local
	 * createFromFormat() with its own format string and its own seconds rule.
	 */
	public function test_offer_valid_from_carries_the_site_offset() {
		$this->site_on_a_bare_offset();
		$event = $this->make_event(
			[
				'title'                => 'Opens Later',
				'start_date'           => '2030-12-11',
				'start_time'           => '08:00',
				'registration_enabled' => true,
				// Already open: a FUTURE registration_open makes the event
				// unbookable, and an unbookable event publishes no Offer at
				// all — so there would be no validFrom to inspect.
				'registration_open'    => '2020-10-01',
			]
		);

		$node = $this->schema()->for_event( $event );

		$this->assertNotEmpty( $node['offers'] ?? [], 'Precondition: a free Offer is published.' );
		$this->assertSame( '2020-10-01T00:00:00-06:00', $node['offers'][0]['validFrom'] );
	}

	/**
	 * The two halves of the same fix must not be able to diverge again: the
	 * schema's resolver and the save path's resolver are asked the same four
	 * questions and have to give the same four answers.
	 */
	public function test_schema_and_module_resolve_the_same_zone_for_every_shape() {
		$this->site_on_a_bare_offset();
		$this->set_timezone_mode( 'event' );

		foreach ( [ '', 'UTC-6', 'UTC+5.5', 'America/Chicago', 'Not/A_Zone' ] as $stored ) {
			$meta = [ 'timezone' => $stored ];
			$this->assertSame(
				$this->module()->event_timezone( $meta )->getName(),
				$this->schema_timezone( $meta )->getName(),
				sprintf( 'The two resolvers disagree about %s.', var_export( $stored, true ) )
			);
		}
	}

	/** The string normaliser itself, now that it is the one public answer. */
	public function test_normalize_timezone_translates_every_stored_shape() {
		$this->site_on_a_bare_offset();

		$this->assertSame( '-06:00', $this->module()->normalize_timezone( '' ), "'' means the site's zone." );
		$this->assertSame( '-06:00', $this->module()->normalize_timezone( 'UTC-6' ), 'WordPress mints UTC-6; DateTimeZone wants -06:00.' );
		$this->assertSame( '+05:30', $this->module()->normalize_timezone( 'UTC+5.5' ) );
		$this->assertSame( '-06:30', $this->module()->normalize_timezone( 'UTC-06:30' ) );
		$this->assertSame( 'America/Chicago', $this->module()->normalize_timezone( 'America/Chicago' ) );
	}

	/** Garbage resolves to UTC rather than throwing out of a render. */
	public function test_event_timezone_falls_back_to_utc_for_an_unusable_string() {
		$this->site_on_a_bare_offset();
		$this->set_timezone_mode( 'event' );

		$this->assertSame( 'UTC', $this->module()->event_timezone( [ 'timezone' => 'Not/A_Zone' ] )->getName() );
	}

	/* ------------------------------------------------------------------
	 * MODEL-D13 — second-exact timestamps.
	 * ------------------------------------------------------------------ */

	/**
	 * A REGRESSION GUARD, not a discriminator — and the audit entry it comes
	 * from (MODEL-D13) overstates the defect.
	 *
	 * The entry says `createFromFormat('Y-m-d H:i', …)` fills the unspecified
	 * seconds from the current clock, so an unchanged reconcile rewrites
	 * start_ts every second. Measured on PHP 8.4.4, that is false: PHP zeroes
	 * time units SMALLER than the smallest one the format names, so `H:i`
	 * already yields `:00`. (`createFromFormat('Y-m-d', …)` — no time portion
	 * at all — is the shape that really does inherit the clock.) This test
	 * therefore passed before the fix as well as after.
	 *
	 * It is kept because the `!` makes the guarantee explicit rather than a
	 * side effect of which fields the format happens to name: if the format
	 * ever loses its time portion, this fails instead of the reconcile
	 * idempotency contract silently becoming untrue.
	 */
	public function test_compute_timestamps_is_identical_across_two_different_seconds() {
		$this->site_on_a_bare_offset();
		$event = $this->make_event(
			[
				'title'      => 'Idempotent',
				'start_date' => '2030-12-11',
				'start_time' => '08:00',
				'end_time'   => '17:00',
			]
		);
		$meta = $this->module()->get_meta( $event );

		$first  = $this->module()->compute_timestamps( $meta );
		$second = $this->tick() ? $this->module()->compute_timestamps( $meta ) : [];

		$this->assertSame( $first, $second, 'Two computes in different seconds must agree byte for byte.' );
		$this->assertSame( 0, $first['start'] % 60, 'Seconds must be zeroed, not inherited from the clock.' );
		$this->assertSame( 0, $first['end'] % 60 );
	}

	/** Wait out the current second so the next call really is in a new one. */
	private function tick() {
		$second = time();
		while ( time() === $second ) {
			usleep( 20000 );
		}
		return true;
	}

	/* ------------------------------------------------------------------
	 * MODEL-D11 — [events_list] range bounds.
	 * ------------------------------------------------------------------ */

	/**
	 * `strtotime('2030-09-15 00:00')` runs in PHP's default zone (UTC under
	 * WordPress), so the lower bound landed at 2030-09-14 18:00 local and swept
	 * in the previous evening's events.
	 */
	public function test_events_list_start_date_bound_is_read_in_the_site_zone() {
		$this->site_on_a_bare_offset();
		$inside  = $this->dated_event( 'Just Inside', '2030-09-15', '00:30' );
		$outside = $this->dated_event( 'Just Outside', '2030-09-14', '23:30' );

		$this->module()->clear_caches();
		$html = do_shortcode( '[events_list start_date="2030-09-15" limit="50"]' );

		$this->assertStringContainsString( esc_url( get_permalink( $inside ) ), $html, '00:30 on the 15th local is inside the range.' );
		$this->assertStringNotContainsString( esc_url( get_permalink( $outside ) ), $html, '23:30 on the 14th local is not.' );
	}

	/**
	 * The upper bound has the mirror-image bug: 23:59 UTC on the closing day is
	 * 17:59 local, so the evening of the last requested day fell out.
	 */
	public function test_events_list_end_date_bound_covers_the_whole_closing_day() {
		$this->site_on_a_bare_offset();
		$evening = $this->dated_event( 'Closing Evening', '2030-09-20', '19:00' );

		$this->module()->clear_caches();
		$html = do_shortcode( '[events_list start_date="2030-09-15" end_date="2030-09-20" limit="50"]' );

		$this->assertStringContainsString( esc_url( get_permalink( $evening ) ), $html );
	}

	/**
	 * The open end was `strtotime('+5 years')` — a value that moves every
	 * second, so the transient cache key for an open-ended range never
	 * repeated and the listing cache could never hit.
	 */
	public function test_an_open_ended_range_produces_a_stable_bound() {
		$this->site_on_a_bare_offset();
		$clause = new ReflectionMethod( Module::class, 'build_range_clause' );
		$clause->setAccessible( true );

		$first = $clause->invoke( $this->module(), '2030-09-15', '' );
		$this->tick();
		$second = $clause->invoke( $this->module(), '2030-09-15', '' );

		$this->assertSame( $first, $second, 'An open-ended range must key the cache identically on every request.' );
	}

	/* ------------------------------------------------------------------
	 * MODEL-D12 / RENDER-D30 — the calendar window and "this month".
	 * ------------------------------------------------------------------ */

	/**
	 * The window's start was computed in the site zone and its end with raw
	 * strtotime() in UTC, so on a UTC-6 site September stopped at 19:00 local
	 * on the 30th and any event later that evening vanished from its own month.
	 */
	public function test_calendar_includes_an_event_late_on_the_last_day_of_the_month() {
		$this->site_on_a_bare_offset();
		$late = $this->dated_event( 'Halloween Night', '2030-10-31', '19:00' );

		$this->module()->clear_caches();
		$html = do_shortcode( '[event_calendar month="2030-10"]' );

		$this->assertStringContainsString( esc_url( get_permalink( $late ) ), $html );
	}

	/**
	 * RENDER-D30: "the current month" came from `date('Y-m')`, which runs in
	 * UTC, so for the last hours of every month the calendar opened on the
	 * NEXT one.
	 *
	 * `wp_date` is filtered to stand in for the clock, because the test suite
	 * cannot move it: the stub answers "now" as the same instant the assertion
	 * describes — 19:00 on 31 October, local — while `date()` (untouched by the
	 * filter) still sees whatever UTC says. Only a `wp_date()`-based derivation
	 * can pass.
	 */
	public function test_calendar_opens_on_the_site_zone_month_not_the_utc_one() {
		$this->site_on_a_bare_offset();
		$instant = ( new DateTime( '2030-10-31 19:00:00', new DateTimeZone( '-06:00' ) ) )->getTimestamp();
		$this->assertSame( '2030-11', gmdate( 'Y-m', $instant ), 'Precondition: UTC has already rolled over.' );

		$stub = function ( $date, $format ) use ( $instant ) {
			if ( 'Y-m' === $format ) {
				return ( new DateTime( '@' . $instant ) )->setTimezone( wp_timezone() )->format( 'Y-m' );
			}
			return $date;
		};
		add_filter( 'wp_date', $stub, 10, 2 );
		$html = do_shortcode( '[event_calendar]' );
		remove_filter( 'wp_date', $stub, 10 );

		$this->assertStringContainsString( 'data-month="2030-10"', $html, 'The calendar must open on the site-zone month.' );
		$this->assertStringContainsString( 'October 2030', $html );
	}

	/* ------------------------------------------------------------------
	 * MODEL-D21 — surface the lost DST.
	 * ------------------------------------------------------------------ */

	public function test_a_site_on_a_bare_offset_is_warned_that_dst_is_not_observed() {
		$this->site_on_a_bare_offset();

		$html = $this->module()->timezone_notice_html();

		$this->assertStringContainsString( 'notice', $html );
		$this->assertStringContainsString( 'daylight', strtolower( $html ) );
		$this->assertStringContainsString( 'options-general.php', $html, 'The notice must link to Settings > General.' );
	}

	public function test_a_site_with_a_named_zone_is_not_warned() {
		update_option( 'timezone_string', 'America/Chicago' );
		update_option( 'gmt_offset', -6 );

		$this->assertSame( '', $this->module()->timezone_notice_html() );
	}

	/**
	 * The shipped WordPress default is `timezone_string` '' and `gmt_offset` 0,
	 * i.e. '+00:00'. The plugin ships fleet-wide, so warning on that would put a
	 * permanent notice on every untouched install without naming a real
	 * problem — UTC is exactly what the setting says.
	 */
	public function test_a_default_install_reading_as_utc_is_not_warned() {
		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', 0 );

		$this->assertSame( '+00:00', wp_timezone_string(), 'Precondition: the default install resolves to +00:00.' );
		$this->assertSame( '', $this->module()->timezone_notice_html() );
	}

	/** The notice is scoped to the events screens, not printed site-wide. */
	public function test_the_notice_is_not_printed_outside_the_events_screens() {
		$this->site_on_a_bare_offset();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		set_current_screen( 'dashboard' );

		ob_start();
		$this->module()->maybe_render_timezone_notice();
		$printed = ob_get_clean();

		$this->assertSame( '', $printed );
	}

	public function test_the_notice_is_printed_on_the_event_editor() {
		$this->site_on_a_bare_offset();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		set_current_screen( 'edit-event' );

		ob_start();
		$this->module()->maybe_render_timezone_notice();
		$printed = ob_get_clean();

		$this->assertStringContainsString( 'daylight', strtolower( $printed ) );
	}

	/* ------------------------------------------------------------------
	 * Helpers.
	 * ------------------------------------------------------------------ */

	/**
	 * An event with its derived `_ts` rows actually written — make_event()
	 * writes raw meta only, and every listing query joins on start_ts.
	 */
	private function dated_event( $title, $date, $time ) {
		$id = $this->make_event(
			[
				'title'      => $title,
				'start_date' => $date,
				'start_time' => $time,
				'end_time'   => '',
			]
		);
		$this->module()->persist_timestamps( $id, $this->module()->get_meta( $id ) );
		return $id;
	}
}
