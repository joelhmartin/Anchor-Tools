<?php
declare(strict_types=1);

namespace Anchor\Courses\Integrations;

use Anchor\Courses\Support\UserEventQueue;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Browser analytics (brief section 19): dataLayer pushes for GTM/GA4 across
 * the whole learner lifecycle.
 *
 * Server-side actions fire during a POST or REST request that has no page of
 * its own - `admin-post.php?action=anchor_courses_complete_lesson` redirects
 * before anything could print a `<script>` tag - so every event is queued
 * (`Support\UserEventQueue`, a transient) against the learner it is about,
 * and flushed exactly once, as a single `<script>` tag, on `wp_footer` of
 * whichever front-end page that learner's own browser actually loads next
 * (see record()'s docblock for why that is not always "whoever is currently
 * logged in").
 *
 * IDs ONLY. No names, no emails, no course titles, not even the WordPress
 * user id - an analytics platform must not become a second copy of the
 * roster (brief 19, the hard rule).
 */
final class Analytics {

	/** hook name => dataLayer event name (brief 19's list, in this order). */
	public const EVENTS = [
		'anchor_courses_enrolled'           => 'course_enrolled',
		'anchor_courses_course_started'     => 'course_started',
		'anchor_courses_lesson_started'     => 'lesson_started',
		'anchor_courses_lesson_completed'   => 'lesson_completed',
		'anchor_courses_quiz_started'       => 'quiz_started',
		'anchor_courses_quiz_submitted'     => 'quiz_completed',
		'anchor_courses_quiz_passed'        => 'quiz_passed',
		'anchor_courses_quiz_failed'        => 'quiz_failed',
		'anchor_courses_course_completed'   => 'course_completed',
		'anchor_courses_ce_credit_awarded'  => 'ce_credit_awarded',
		'anchor_courses_certificate_issued' => 'certificate_generated',
	];

	/** The only keys a payload may ever carry (brief 19, the hard rule). */
	private const ALLOWED_KEYS = [ 'event', 'course_id', 'lesson_id', 'quiz_id', 'attempt_id', 'score', 'credits', 'certificate_id' ];

	private const TRANSIENT_PREFIX = 'anchor_courses_dl_';

	/** 5 minutes: comfortably past a redirect, short enough not to resurrect a stale queue days later. */
	private const TTL_SECONDS = 5 * \MINUTE_IN_SECONDS;

	/** A learner who somehow racks up more than this in one queue gets the newest events, not an ever-growing transient. */
	private const MAX_QUEUED = 50;

	private static ?UserEventQueue $queue = null;

	public function __construct() {
		foreach ( self::EVENTS as $hook => $event ) {
			\add_action(
				$hook,
				static function ( ...$args ) use ( $event ): void {
					self::record( $event, $args );
				},
				20,
				4
			);
		}

		\add_action( 'wp_footer', [ $this, 'print_events' ], 30 );
	}

	/**
	 * Whether analytics is on at all. Filter, matching the module's existing
	 * boolean-toggle convention (`anchor_courses_parent_menu`,
	 * `anchor_courses_create_account`) rather than a new wp-admin settings
	 * screen - Courses has none today and this module has no reason to be
	 * the first. Defaults to on: a site that wants GTM/GA4 events from its
	 * courses gets them without extra setup, and a site that doesn't can
	 * turn them off with one line in functions.php.
	 */
	public static function enabled(): bool {
		return (bool) \apply_filters( 'anchor_courses_analytics_enabled', true );
	}

	/**
	 * Build a payload from a hook's positional arguments and queue it for
	 * the LEARNER the event is about - not necessarily whoever is making
	 * this request. Most of this module's own actions are self-service (a
	 * learner completing their own lesson posts to admin-post.php as
	 * themselves), where the two are the same person, but not all of them:
	 * `Admin\LearnerReports` enrols a learner from wp-admin as the acting
	 * admin, and `Admin\EnrollmentManager`'s `complete` action can complete
	 * a course for a learner the same way. Queuing against
	 * `get_current_user_id()` in either case would attribute the learner's
	 * `course_enrolled`/`course_completed` event to the ADMIN's session, and
	 * print it on the admin's own next page load - exactly the
	 * cross-account leak the queue must not allow. Keying by the subject id
	 * instead means an admin-initiated event simply waits for that learner's
	 * own next front-end page load (or expires unflushed after TTL_SECONDS
	 * if they don't have one soon) rather than ever showing up somewhere it
	 * doesn't belong.
	 */
	private static function record( string $event, array $args ): void {
		if ( ! self::enabled() ) {
			return;
		}

		$user_id = self::subject_user_id( $event, $args );
		if ( $user_id <= 0 ) {
			return;
		}

		$payload = self::payload_for( $event, $args );

		/**
		 * Filter (or drop) one dataLayer event before it is queued. The
		 * allow-list is re-applied to whatever this returns (store()), so a
		 * filter can remove or change allowed keys but never add others.
		 *
		 * @param array|null $payload Return null (or an empty array) to drop the event entirely.
		 * @param string     $event   The dataLayer event name (EVENTS value, not the hook name).
		 * @param array      $args    The action's positional arguments, as WordPress passed them.
		 */
		$payload = \apply_filters( 'anchor_courses_datalayer_event', $payload, $event, $args );

		if ( ! \is_array( $payload ) || [] === $payload ) {
			return;
		}

		$payload['event'] = $event;
		self::store( $user_id, $payload );
	}

	/**
	 * Which learner a hook's positional arguments are about. Mirrors
	 * payload_for()'s argument-shape comments; the id is deliberately read
	 * from the hook's own $user_id argument rather than an object property,
	 * since every one of these hooks carries it positionally.
	 */
	private static function subject_user_id( string $event, array $args ): int {
		switch ( $event ) {
			case 'course_enrolled':
				// anchor_courses_enrolled: ( Enrollment $enrollment, int $user_id, int $course_id )
				return (int) ( $args[1] ?? 0 );

			case 'quiz_started':
			case 'quiz_completed':
			case 'quiz_passed':
			case 'quiz_failed':
				// anchor_courses_quiz_*: ( QuizAttempt $attempt, int $user_id, int $quiz_id, int $course_id )
				return (int) ( $args[1] ?? 0 );

			case 'course_started':
			case 'lesson_started':
			case 'lesson_completed':
			case 'course_completed':
			case 'ce_credit_awarded':
			case 'certificate_generated':
				// every other hook: ( int $user_id, ... )
				return (int) ( $args[0] ?? 0 );

			default:
				return 0;
		}
	}

	/**
	 * Map a hook's positional arguments to an IDs-only payload. The argument
	 * shapes come straight from COURSES.md's hooks table / the services that
	 * fire them (do_action call sites, not the brief, are the source of
	 * truth - see the Task 35 report for the cross-check); anything not on
	 * the allow-list is dropped rather than renamed.
	 */
	public static function payload_for( string $event, array $args ): array {
		$payload = [ 'event' => $event ];

		switch ( $event ) {
			case 'course_enrolled':
				// anchor_courses_enrolled: ( Enrollment $enrollment, int $user_id, int $course_id )
				$payload['course_id'] = (int) ( $args[2] ?? 0 );
				break;

			case 'course_started':
				// anchor_courses_course_started: ( int $user_id, int $course_id, Enrollment $enrollment )
			case 'course_completed':
				// anchor_courses_course_completed: ( int $user_id, int $course_id, Enrollment $enrollment )
				$payload['course_id'] = (int) ( $args[1] ?? 0 );
				break;

			case 'lesson_started':
			case 'lesson_completed':
				// anchor_courses_lesson_started|lesson_completed: ( int $user_id, int $course_id, int $lesson_id, Progress $progress )
				$payload['course_id'] = (int) ( $args[1] ?? 0 );
				$payload['lesson_id'] = (int) ( $args[2] ?? 0 );
				break;

			case 'quiz_started':
				// anchor_courses_quiz_started: ( QuizAttempt $attempt, int $user_id, int $quiz_id, int $course_id )
				$payload['quiz_id']    = (int) ( $args[2] ?? 0 );
				$payload['course_id']  = (int) ( $args[3] ?? 0 );
				$payload['attempt_id'] = isset( $args[0] ) && \is_object( $args[0] ) ? (int) $args[0]->id : 0;
				break;

			case 'quiz_completed':
			case 'quiz_passed':
			case 'quiz_failed':
				// anchor_courses_quiz_submitted|quiz_passed|quiz_failed: ( QuizAttempt $attempt, int $user_id, int $quiz_id, int $course_id )
				$payload['quiz_id']   = (int) ( $args[2] ?? 0 );
				$payload['course_id'] = (int) ( $args[3] ?? 0 );
				if ( isset( $args[0] ) && \is_object( $args[0] ) ) {
					$payload['attempt_id'] = (int) $args[0]->id;
					$payload['score']      = null === $args[0]->score ? 0 : (float) $args[0]->score;
				}
				break;

			case 'ce_credit_awarded':
				// anchor_courses_ce_credit_awarded: ( int $user_id, int $course_id, Credit $credit )
				$payload['course_id'] = (int) ( $args[1] ?? 0 );
				$payload['credits']   = isset( $args[2] ) && \is_object( $args[2] ) ? (float) $args[2]->credits : 0.0;
				break;

			case 'certificate_generated':
				// anchor_courses_certificate_issued: ( int $user_id, int $course_id, Certificate $certificate )
				$payload['course_id']      = (int) ( $args[1] ?? 0 );
				$payload['certificate_id'] = isset( $args[2] ) && \is_object( $args[2] ) ? (int) $args[2]->id : 0;
				break;
		}

		return self::allowed( $payload );
	}

	/**
	 * Strip a payload to ALLOWED_KEYS. Applied in store() - the single point
	 * every event passes on its way into the queue, AFTER the
	 * `anchor_courses_datalayer_event` filter and for queue()'s own callers
	 * alike - so no filter or custom caller can add a key the hard rule
	 * forbids. (payload_for() applies it too, so its own return value is
	 * already clean for anybody reading it directly.)
	 */
	private static function allowed( array $payload ): array {
		return \array_intersect_key( $payload, \array_flip( self::ALLOWED_KEYS ) );
	}

	/**
	 * Queue one event payload for the CURRENT user. Public, general-purpose
	 * entry point (a site's own code can call this to queue a custom event
	 * the same way this module queues its own); `record()` above does not
	 * use it, since it queues by the event's subject rather than by whoever
	 * is currently logged in - see its docblock. The same allow-list applies
	 * (store()): keys outside ALLOWED_KEYS are dropped, `event` is kept.
	 */
	public static function queue( string $event, array $payload ): void {
		$payload['event'] = $event;
		self::store( \get_current_user_id(), $payload );
	}

	private static function store( int $user_id, array $payload ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		self::queue_instance()->push( $user_id, self::allowed( $payload ) );
	}

	/** @return array<int,array<string,mixed>> */
	public static function pending( int $user_id ): array {
		return self::queue_instance()->pending( $user_id );
	}

	/** Read and clear. @return array<int,array<string,mixed>> */
	public static function flush( int $user_id ): array {
		return self::queue_instance()->flush( $user_id );
	}

	/** On `wp_footer`: print the current user's queued events as one `<script>`, then clear the queue. */
	public function print_events(): void {
		if ( ! self::enabled() ) {
			return;
		}

		$user_id = \get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}

		$events = self::flush( $user_id );
		if ( [] === $events ) {
			return;
		}

		$script = 'window.dataLayer = window.dataLayer || [];';
		foreach ( $events as $payload ) {
			$script .= \sprintf(
				'window.dataLayer.push(%s);',
				(string) \wp_json_encode( $payload, \JSON_UNESCAPED_SLASHES | \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT )
			);
		}

		\wp_print_inline_script_tag( $script );
	}

	private static function queue_instance(): UserEventQueue {
		if ( null === self::$queue ) {
			self::$queue = new UserEventQueue( self::TRANSIENT_PREFIX, self::TTL_SECONDS, self::MAX_QUEUED );
		}
		return self::$queue;
	}
}
