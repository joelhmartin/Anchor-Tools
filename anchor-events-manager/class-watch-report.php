<?php
/**
 * Livestream watch report: e-mails the organizer list the room attendance
 * once a streamed event (or each streamed session) has ended.
 *
 * Built entirely on what already exists: the numbers are
 * Room_Attendance::report(), the CSV is Room_Attendance::export_table(), the
 * recipients are Module::resolve_organizer_email() and the e-mail is the
 * organizer digest look (build_registration_email_html(), type 'roster').
 *
 * Presence on the room page is not proof of watching, so the e-mail says
 * "recorded in the room", never "watched".
 *
 * @package Anchor\Events
 */

namespace Anchor\Events;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

class Watch_Report {

	/** Session start_ts the report was last sent about => when it was sent. */
	const META_SENT = '_anchor_event_watch_report_sent';

	/** Wait this long after a session ends: the room stays open 30 minutes after. */
	const GRACE_SECONDS = HOUR_IN_SECONDS;

	/** Never mail about a session that ended longer ago than this (switching it on later). */
	const LOOKBACK_SECONDS = 3 * DAY_IN_SECONDS;

	const TIMINGS = [ 'end', 'each' ];

	/** @var Module */
	private $module;

	public function __construct( Module $module ) {
		$this->module = $module;
		\add_action( 'anchor_events_reminder_sweep', [ $this, 'run_due' ], 20 );
		\add_action( 'admin_post_anchor_event_watch_report_send', [ $this, 'handle_send_now' ] );
	}

	/**
	 * Hourly: send every report that has come due.
	 *
	 * @param int|null $now
	 * @return int[] Event ids a report went out for.
	 */
	public function run_due( $now = null ) {
		$now      = $now === null ? \time() : (int) $now;
		$settings = $this->module->get_settings();
		if ( empty( $settings['watch_report_email'] ) ) {
			return [];
		}
		$event_ids = \get_posts( [
			'post_type'      => Module::CPT,
			'post_status'    => [ 'publish', 'future', 'private' ],
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => [
				[ 'key' => $this->module->meta_key( 'start_ts' ), 'value' => $now, 'compare' => '<=', 'type' => 'NUMERIC' ],
				[ 'key' => $this->module->meta_key( 'end_ts' ), 'value' => $now - self::LOOKBACK_SECONDS, 'compare' => '>=', 'type' => 'NUMERIC' ],
			],
		] );
		$sent = [];
		foreach ( $event_ids as $event_id ) {
			$event_id = (int) $event_id;
			if ( $this->module->occurrences->is_group_parent( $event_id ) || $this->module->room_url( $event_id ) === '' ) {
				continue; // the room, and its attendance, live on the dates
			}
			$session = $this->due_session( $event_id, $now, (string) $settings['watch_report_timing'] );
			if ( $session === null ) {
				continue;
			}
			if ( $this->send( $event_id, $session['start_ts'] )->is_sent() ) {
				$this->mark_sent( $event_id, $session['start_ts'], $now );
				$sent[] = $event_id;
			}
		}
		return $sent;
	}

	/**
	 * The streamed session a report is due about now, or null.
	 *
	 * 'end': the last streamed session, once it has ended. 'each': the latest
	 * streamed session that has ended. Either way only once, and never about a
	 * session that ended more than LOOKBACK_SECONDS ago.
	 *
	 * @return array|null One of Room_Attendance::streamed_sessions().
	 */
	public function due_session( $event_id, $now, $timing = 'end' ) {
		$sessions = $this->module->room_attendance->streamed_sessions( (int) $event_id );
		if ( empty( $sessions ) ) {
			return null;
		}
		\usort( $sessions, static function ( $a, $b ) {
			return $a['start_ts'] <=> $b['start_ts'];
		} );
		$ended = \array_values( \array_filter( $sessions, static function ( $s ) use ( $now ) {
			return $s['end_ts'] + self::GRACE_SECONDS <= $now;
		} ) );
		if ( empty( $ended ) ) {
			return null;
		}
		$latest = $ended[ \count( $ended ) - 1 ];
		if ( $timing !== 'each' && $latest['start_ts'] !== $sessions[ \count( $sessions ) - 1 ]['start_ts'] ) {
			return null; // 'end': the last streamed session has not ended yet
		}
		if ( $latest['end_ts'] + self::LOOKBACK_SECONDS < $now ) {
			return null;
		}
		// Covered already: a report about this session, or a later one.
		$markers = $this->markers( $event_id );
		if ( ! empty( $markers ) && \max( \array_keys( $markers ) ) >= $latest['start_ts'] ) {
			return null;
		}
		return $latest;
	}

	/** @return array<int,int> */
	public function markers( $event_id ) {
		$raw = \get_post_meta( (int) $event_id, self::META_SENT, true );
		return \is_array( $raw ) ? \array_map( 'intval', $raw ) : [];
	}

	private function mark_sent( $event_id, $session_start, $now ) {
		$markers                          = $this->markers( $event_id );
		$markers[ (int) $session_start ] = (int) $now;
		\update_post_meta( (int) $event_id, self::META_SENT, $markers );
	}

	/**
	 * Build and send the report to the event's organizer list.
	 *
	 * @param int $event_id
	 * @param int $through  Session start_ts the report covers up to; 0 = every session that has started.
	 * @return Outcome
	 */
	public function send( $event_id, $through = 0 ) {
		$event_id = (int) $event_id;
		$to       = $this->module->resolve_organizer_email( $event_id );
		if ( $to === '' ) {
			Events_Log::error( 'watch_report_no_address', [ 'event' => $event_id ] );
			return Outcome::failed( 'no_address' );
		}
		$email = $this->build( $event_id, $through );
		if ( $email === null ) {
			return Outcome::skipped( 'nobody' );
		}
		$csv  = $this->write_csv( $event_id );
		$sent = $this->module->send_html_email(
			$to,
			$email['subject'],
			$email['html'],
			[],
			$event_id,
			[],
			[ 'kind' => 'events.watch_report', 'audience' => 'organizer' ],
			$csv !== '' ? [ $csv ] : []
		);
		if ( $csv !== '' ) {
			\wp_delete_file( $csv );
		}
		return Outcome::from_bool( $sent, 'wp_mail' );
	}

	/**
	 * The subject and HTML, or null when nobody was registered or in the room.
	 *
	 * @return array{subject:string,html:string}|null
	 */
	public function build( $event_id, $through = 0 ) {
		$event_id = (int) $event_id;
		$report   = $this->module->room_attendance->report( $event_id );
		if ( empty( $report['people'] ) ) {
			return null;
		}
		$now      = \time();
		$sessions = \array_values( \array_filter( $report['sessions'], static function ( $s ) use ( $through, $now ) {
			return $through > 0 ? $s['start_ts'] <= $through : $s['start_ts'] <= $now;
		} ) );
		$covered  = \array_column( $sessions, 'start_ts' );
		$all_done = \count( $sessions ) === \count( $report['sessions'] );
		$title    = \get_the_title( $event_id );

		$rows    = [];
		$present = 0;
		$seats   = 0;
		foreach ( $report['people'] as $person ) {
			$seats += $person['seat'] ? 1 : 0;
		}
		foreach ( $sessions as $session ) {
			$count = 0;
			foreach ( $report['people'] as $person ) {
				$count += isset( $person['visits'][ $session['start_ts'] ] ) ? 1 : 0;
			}
			$rows[] = [
				'label' => $this->session_name( $session, $event_id ),
				'value' => \sprintf(
					/* translators: 1: people recorded in the room, 2: people registered. */
					\__( '%1$d of %2$d registered', 'anchor-schema' ),
					$count,
					$seats
				),
			];
		}

		$lines = [];
		$total = \count( $sessions );
		foreach ( $report['people'] as $person ) {
			$visits  = \array_intersect_key( $person['visits'], \array_flip( $covered ) );
			$seconds = (int) \array_sum( \array_column( $visits, 'seconds' ) );
			$present += empty( $visits ) ? 0 : 1;
			$who      = $person['name'] !== '' ? $person['name'] : $person['email'];
			if ( $person['name'] !== '' && $person['email'] !== '' ) {
				$who .= ' (' . $person['email'] . ')';
			}
			$what = empty( $visits )
				? \__( 'not recorded in the room', 'anchor-schema' )
				: \sprintf(
					/* translators: 1: sessions joined, 2: sessions streamed, 3: time such as "2h 05m". */
					\__( '%1$d of %2$d sessions, %3$s in the room', 'anchor-schema' ),
					\count( $visits ),
					$total,
					Room_Attendance::format_seconds( $seconds )
				);
			if ( ! $person['seat'] ) {
				$what .= ' ' . \__( '(access without a registration)', 'anchor-schema' );
			}
			$lines[] = [ empty( $visits ) ? 1 : 0, $who . ' — ' . $what ];
		}
		// Who came first, then who did not; the report's own order inside each.
		\usort( $lines, static function ( $a, $b ) {
			return $a[0] <=> $b[0];
		} );

		\array_unshift( $rows, [
			'label' => \__( 'In the room at least once', 'anchor-schema' ),
			'value' => \sprintf(
				/* translators: 1: people recorded in the room, 2: people listed. */
				\__( '%1$d of %2$d', 'anchor-schema' ),
				$present,
				\count( $report['people'] )
			),
		] );

		$last  = $sessions[ \count( $sessions ) - 1 ] ?? null;
		$intro = $all_done
			? \sprintf(
				/* translators: %s: event title. */
				\__( 'The livestream sessions of <strong>%s</strong> have ended. Here is who was recorded in the room.', 'anchor-schema' ),
				\esc_html( $title )
			)
			: \sprintf(
				/* translators: 1: event title, 2: session name. */
				\__( 'Livestream attendance for <strong>%1$s</strong>, through %2$s.', 'anchor-schema' ),
				\esc_html( $title ),
				\esc_html( $last ? $this->session_name( $last, $event_id ) : '' )
			);
		$intro .= '</p><p>' . \esc_html__( 'Time counts while the room page is open and visible during a session. It shows presence on the page, not proof of watching. The full table, with first and last times per session, is attached as a spreadsheet (CSV).', 'anchor-schema' );

		$subject = \sprintf(
			/* translators: %s: event title. */
			$all_done ? \__( 'Watch report: %s', 'anchor-schema' ) : \__( 'Watch report so far: %s', 'anchor-schema' ),
			$title
		);

		$html = $this->module->build_registration_email_html( [
			'event_id'      => $event_id,
			'name'          => '',
			'status'        => Registrations::STATUS_CONFIRMED,
			'intro_message' => '<p>' . $intro . '</p>',
			'detail_rows'   => $rows,
			'seat_list'     => \array_column( $lines, 1 ),
			'cta_label'     => \__( 'Open attendance', 'anchor-schema' ),
			'cta_url'       => $this->module->roster->roster_url( $event_id ),
			'type'          => 'roster',
		] );

		return [
			'subject' => (string) \apply_filters( 'anchor_events_watch_report_subject', \wp_specialchars_decode( $subject, ENT_QUOTES ), $event_id ),
			'html'    => $html,
		];
	}

	private function session_name( array $session, $event_id ) {
		$tz   = $this->module->event_timezone( $this->module->get_meta( (int) $event_id ) );
		$date = \wp_date( 'M j', (int) $session['start_ts'], $tz );
		return $session['label'] !== '' ? $session['label'] . ' · ' . $date : $date;
	}

	/** The attendance CSV in the temp dir, or '' when it could not be written. */
	private function write_csv( $event_id ) {
		$table = $this->module->room_attendance->export_table( (int) $event_id );
		$path  = \trailingslashit( \get_temp_dir() ) . 'event-attendance-' . (int) $event_id . '-' . \gmdate( 'Ymd' ) . '-' . \wp_generate_password( 6, false ) . '.csv';
		$out   = @\fopen( $path, 'w' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		if ( ! $out ) {
			return '';
		}
		$this->module->roster->write_csv( $out, $table );
		\fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $path;
	}

	/** Attendance panel button: e-mail the report now (does not mark the scheduled send). */
	public function send_now_url( $event_id ) {
		return \wp_nonce_url(
			\add_query_arg( [ 'action' => 'anchor_event_watch_report_send', 'event_id' => (int) $event_id ], \admin_url( 'admin-post.php' ) ),
			'anchor_event_watch_report_' . (int) $event_id
		);
	}

	public function handle_send_now() {
		$event_id = isset( $_GET['event_id'] ) ? (int) \wp_unslash( $_GET['event_id'] ) : 0;
		\check_admin_referer( 'anchor_event_watch_report_' . $event_id );
		if ( ! Roster::current_user_can_manage() || ! Roster::is_exportable_event( $event_id ) ) {
			\wp_die( \esc_html__( 'Unauthorized', 'anchor-schema' ) );
		}
		$result = $this->send( $event_id )->is_sent() ? 'sent' : 'failed';
		$back   = \wp_get_referer();
		\wp_safe_redirect( \add_query_arg( 'anchor_watch_report', $result, $back ? $back : $this->module->roster->roster_url( $event_id ) ) );
		exit;
	}
}
