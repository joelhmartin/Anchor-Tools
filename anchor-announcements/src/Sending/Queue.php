<?php
declare(strict_types=1);

namespace Anchor\Announcements\Sending;

use Anchor\Announcements\Content\AnnouncementPostType as PT;
use Anchor\Announcements\Database\Migrations;
use Anchor\Announcements\Module;
use Anchor\Announcements\Support\Settings;
use Anchor\Announcements\Suppression\Suppressions;
use Anchor\Announcements\Tracking\LinkRewriter;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Snapshot the audience into send rows, then a one-minute WP-Cron tick sends a batch.
 * GET_LOCK keeps ticks from overlapping across connections; claim() keeps a row from
 * ever being sent twice even when the lock cannot (GET_LOCK is re-entrant for one
 * connection).
 *
 * Delivery is at-most-once: a row whose send was interrupted (crash, timeout) stays in
 * 'sending' and is later marked failed, never requeued, because we cannot know whether
 * wp_mail() already delivered it and a duplicate is worse than a missed email.
 */
final class Queue {

	public const HOOK            = 'anchor_announcements_tick';
	public const SCHEDULE        = 'anchor_announcements_minute';
	public const LAST_RUN_OPTION = 'anchor_announcements_last_tick';
	public const MAX_ATTEMPTS    = 3;
	private const STALE_CLAIM    = 1800; // seconds before an interrupted claim is marked failed

	public static function register(): void {
		\add_filter(
			'cron_schedules',
			static function ( $s ) {
				$s[ self::SCHEDULE ] = [ 'interval' => 60, 'display' => \__( 'Every minute (Anchor Announcements)', 'anchor-schema' ) ];
				return $s;
			}
		);
		\add_action( self::HOOK, [ self::class, 'tick' ] );
		// The tick exists only while something is scheduled, sending or paused (ensure_scheduled /
		// clear_if_idle). This admin-side self-heal covers a hook cleared while the module was off.
		\add_action( 'admin_init', [ self::class, 'heal' ] );
		// Trashing or deleting an announcement must not leave rows queued for a post nobody can see.
		\add_action( 'wp_trash_post', [ self::class, 'on_trash' ] );
		\add_action( 'before_delete_post', [ self::class, 'on_delete' ] );
	}

	public static function on_trash( $post_id ): void {
		if ( PT::CPT === \get_post_type( (int) $post_id ) ) {
			self::cancel( (int) $post_id );
		}
	}

	/** Cancel what is still queued, then remove the announcement's send and event rows for good. */
	public static function on_delete( $post_id ): void {
		$id = (int) $post_id;
		if ( PT::CPT !== \get_post_type( $id ) ) {
			return;
		}
		self::cancel( $id );
		global $wpdb;
		$events = Migrations::table( 'events' );
		$wpdb->query( $wpdb->prepare( "DELETE e FROM {$events} e INNER JOIN " . self::table() . ' s ON s.id = e.send_id WHERE s.announcement_id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->delete( self::table(), [ 'announcement_id' => $id ] );
	}

	private const TIME_BUDGET = 45; // seconds of wall time per tick before we stop taking new rows

	private static function table(): string {
		return Migrations::table( 'sends' );
	}

	/** Schedule the one-minute tick if it is not already. */
	public static function ensure_scheduled(): void {
		if ( ! \wp_next_scheduled( self::HOOK ) ) {
			\wp_schedule_event( \time() + 60, self::SCHEDULE, self::HOOK );
		}
	}

	/** True while any announcement is scheduled, sending or paused. */
	public static function has_active(): bool {
		return (bool) \get_posts(
			[
				'post_type'   => PT::CPT,
				'post_status' => 'any',
				'numberposts' => 1,
				'fields'      => 'ids',
				'meta_query'  => [ [ 'key' => PT::META_STATE, 'value' => [ PT::STATE_SCHEDULED, PT::STATE_SENDING, PT::STATE_PAUSED ], 'compare' => 'IN' ] ], // phpcs:ignore WordPress.DB.SlowDBQuery
			]
		);
	}

	/** Remove the tick when nothing needs it. */
	public static function clear_if_idle(): void {
		if ( ! self::has_active() ) {
			\wp_clear_scheduled_hook( self::HOOK );
		}
	}

	/** Admin self-heal, at most every five minutes: re-arm the tick if work is waiting and the event is gone. */
	public static function heal(): void {
		if ( \wp_next_scheduled( self::HOOK ) || \get_transient( 'anchor_announcements_heal' ) ) {
			return;
		}
		\set_transient( 'anchor_announcements_heal', 1, 5 * MINUTE_IN_SECONDS );
		if ( self::has_active() ) {
			self::ensure_scheduled();
		}
	}

	/**
	 * The checks shared by sending and scheduling: mailing address, subject and body, and an
	 * audience that is not empty once unsubscribed addresses are removed.
	 *
	 * @return true|\WP_Error
	 */
	public static function preflight( int $id ) {
		$res = self::checked_recipients( $id );
		return \is_wp_error( $res ) ? $res : true;
	}

	/** @return array{recipients:list<array>,suppressed:array<string,true>}|\WP_Error Resolved recipients (suppressed ones included) and the suppressed set, or the first problem. */
	private static function checked_recipients( int $id ) {
		if ( '' === \trim( (string) Settings::get()['footer_address'] ) ) {
			return new \WP_Error( 'missing_address', \__( 'Add your mailing address in Announcements > Settings before sending.', 'anchor-schema' ) );
		}
		$body = (string) \get_post_meta( $id, PT::META_BODY, true );
		if ( '' === \trim( (string) \get_post_meta( $id, PT::META_SUBJECT, true ) ) || '' === \trim( \wp_strip_all_tags( $body ) ) ) {
			return new \WP_Error( 'empty_content', \__( 'Add a subject and a message before sending.', 'anchor-schema' ) );
		}
		$rules    = \json_decode( (string) \get_post_meta( $id, PT::META_AUDIENCE, true ), true );
		$rules    = \is_array( $rules ) ? $rules : [];
		$resolver = Module::instance()->resolver();
		$problems = $resolver->problems( $rules );
		if ( $problems ) {
			// Fail closed: a rule that cannot be evaluated must never quietly widen the audience.
			return new \WP_Error( 'audience_problem', \implode( ' ', $problems ) );
		}
		$recipients = $resolver->resolve( $rules )->all();
		$suppressed = Suppressions::suppressed_among( \array_column( $recipients, 'email' ) );
		$sendable   = \array_filter( $recipients, static fn( $r ) => ! isset( $suppressed[ \strtolower( $r['email'] ) ] ) );
		if ( ! $sendable ) {
			return new \WP_Error( 'empty_audience', \__( 'Nobody matches this audience (after removing unsubscribed addresses).', 'anchor-schema' ) );
		}
		return [ 'recipients' => $recipients, 'suppressed' => $suppressed ];
	}

	/** @return int|\WP_Error */
	public static function start( int $id ) {
		$state = PT::state( $id );
		if ( ! \in_array( $state, [ PT::STATE_DRAFT, PT::STATE_SCHEDULED ], true ) ) {
			return new \WP_Error( 'wrong_state', \__( 'This announcement has already been sent.', 'anchor-schema' ) );
		}
		$checked = self::checked_recipients( $id );
		if ( \is_wp_error( $checked ) ) {
			return $checked;
		}
		$recipients = $checked['recipients'];
		$body = (string) \get_post_meta( $id, PT::META_BODY, true );

		global $wpdb;
		$now    = \current_time( 'mysql', true );
		$queued = 0;
		foreach ( $recipients as $r ) {
			$suppressed = isset( $checked['suppressed'][ \strtolower( $r['email'] ) ] );
			// user_id is a literal int or NULL: prepare() would turn a null into '' for a BIGINT column.
			$user_sql = $r['user_id'] > 0 ? (string) (int) $r['user_id'] : 'NULL';
			$wpdb->query(
				$wpdb->prepare(
					'INSERT IGNORE INTO ' . self::table() . " (announcement_id, email, user_id, name, token, status, skip_reason, queued_at) VALUES (%d, %s, {$user_sql}, %s, %s, %s, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$id,
					$r['email'],
					$r['name'],
					\bin2hex( \random_bytes( 16 ) ),
					$suppressed ? 'skipped' : 'queued',
					$suppressed ? 'suppressed' : '',
					$now
				)
			);
			$queued += $suppressed ? 0 : 1;
		}
		\update_post_meta( $id, PT::META_LINKS, LinkRewriter::links( \Anchor_Email_Sanitizer::body( $body ) ) );
		\update_post_meta( $id, PT::META_STATE, PT::STATE_SENDING );
		\delete_post_meta( $id, PT::META_SCHEDULED );
		self::ensure_scheduled();
		return $queued;
	}

	/** @return true|\WP_Error */
	public static function schedule( int $id, int $timestamp ) {
		if ( $timestamp <= \time() ) {
			return new \WP_Error( 'past_time', \__( 'Pick a time in the future.', 'anchor-schema' ) );
		}
		if ( PT::STATE_DRAFT !== PT::state( $id ) && PT::STATE_SCHEDULED !== PT::state( $id ) ) {
			return new \WP_Error( 'wrong_state', \__( 'This announcement has already been sent.', 'anchor-schema' ) );
		}
		$ok = self::preflight( $id );
		if ( \is_wp_error( $ok ) ) {
			return $ok;
		}
		\update_post_meta( $id, PT::META_SCHEDULED, $timestamp );
		\update_post_meta( $id, PT::META_STATE, PT::STATE_SCHEDULED );
		self::ensure_scheduled();
		return true;
	}

	public static function unschedule( int $id ): void {
		if ( PT::STATE_SCHEDULED === PT::state( $id ) ) {
			\delete_post_meta( $id, PT::META_SCHEDULED );
			\update_post_meta( $id, PT::META_STATE, PT::STATE_DRAFT );
		}
		self::clear_if_idle();
	}

	public static function pause( int $id ): void {
		if ( PT::STATE_SENDING === PT::state( $id ) ) {
			\update_post_meta( $id, PT::META_STATE, PT::STATE_PAUSED );
		}
	}

	public static function resume( int $id ): void {
		if ( PT::STATE_PAUSED === PT::state( $id ) ) {
			\update_post_meta( $id, PT::META_STATE, PT::STATE_SENDING );
			self::ensure_scheduled();
		}
	}

	public static function cancel( int $id ): void {
		if ( ! \in_array( PT::state( $id ), [ PT::STATE_SENDING, PT::STATE_PAUSED, PT::STATE_SCHEDULED ], true ) ) {
			return;
		}
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status = 'skipped', skip_reason = 'cancelled' WHERE announcement_id = %d AND status = 'queued'", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		\update_post_meta( $id, PT::META_STATE, PT::STATE_CANCELLED );
		self::clear_if_idle();
	}

	public static function claim( int $send_id ): bool {
		global $wpdb;
		return 1 === (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status = 'sending', claimed_at = %s WHERE id = %d AND status = 'queued'", \current_time( 'mysql', true ), $send_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function tick(): void {
		global $wpdb;
		if ( 1 !== (int) $wpdb->get_var( "SELECT GET_LOCK('anchor_announcements_tick', 0)" ) ) {
			return;
		}
		try {
			\update_option( self::LAST_RUN_OPTION, \time(), false );
			self::release_scheduled();
			self::recover_stale_claims();
			self::send_batch( \microtime( true ) );
			self::finish_done();
			self::clear_if_idle();
		} finally {
			$wpdb->query( "SELECT RELEASE_LOCK('anchor_announcements_tick')" );
		}
	}

	private static function release_scheduled(): void {
		$due = \get_posts(
			[
				'post_type'   => PT::CPT,
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_query'  => [ // phpcs:ignore WordPress.DB.SlowDBQuery
					[ 'key' => PT::META_STATE, 'value' => PT::STATE_SCHEDULED ],
					[ 'key' => PT::META_SCHEDULED, 'value' => \time(), 'compare' => '<=', 'type' => 'NUMERIC' ],
				],
			]
		);
		foreach ( $due as $id ) {
			$res = self::start( (int) $id );
			if ( \is_wp_error( $res ) ) {
				\update_post_meta( (int) $id, PT::META_STATE, PT::STATE_DRAFT );
				\update_post_meta( (int) $id, '_aa_last_error', \wp_slash( $res->get_error_message() ) );
				self::notify_release_failed( (int) $id, $res );
			}
		}
	}

	/** Tell the author a scheduled send did not go out (plain text, through wp_mail). */
	private static function notify_release_failed( int $id, \WP_Error $error ): void {
		$author = \get_userdata( (int) \get_post_field( 'post_author', $id ) );
		$to     = $author && \is_email( $author->user_email ) ? $author->user_email : (string) \get_option( 'admin_email' );
		$title  = \get_the_title( $id );
		\wp_mail(
			$to,
			\sprintf( \__( 'Announcement not sent: %s', 'anchor-schema' ), $title ),
			\sprintf(
				/* translators: 1: announcement title, 2: error, 3: edit link */
				\__( "The scheduled announcement \"%1\$s\" was not sent.\n\nReason: %2\$s\n\nIt is back in draft. Fix the problem and schedule it again:\n%3\$s", 'anchor-schema' ),
				$title,
				$error->get_error_message(),
				\admin_url( 'post.php?post=' . $id . '&action=edit' )
			)
		);
	}

	private static function recover_stale_claims(): void {
		global $wpdb;
		$cutoff = \gmdate( 'Y-m-d H:i:s', \time() - self::STALE_CLAIM );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status = 'failed', error = %s WHERE status = 'sending' AND claimed_at < %s", \__( 'Sending was interrupted and was not retried, to avoid sending a duplicate.', 'anchor-schema' ), $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function send_batch( float $started ): void {
		global $wpdb;
		$sending = \get_posts(
			[
				'post_type'   => PT::CPT,
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_key'    => PT::META_STATE, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => PT::STATE_SENDING, // phpcs:ignore WordPress.DB.SlowDBQuery
			]
		);
		if ( ! $sending ) {
			return;
		}
		$in   = \implode( ',', \array_map( 'intval', $sending ) );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE status = 'queued' AND announcement_id IN ({$in}) ORDER BY id ASC LIMIT %d", (int) Settings::get()['batch_size'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		foreach ( $rows as $row ) {
			if ( \microtime( true ) - $started > self::TIME_BUDGET ) {
				break; // Remaining rows stay queued for the next tick.
			}
			if ( Suppressions::is_suppressed( (string) $row->email ) ) {
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status = 'skipped', skip_reason = 'suppressed' WHERE id = %d AND status = 'queued'", (int) $row->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				continue;
			}
			if ( ! self::claim( (int) $row->id ) ) {
				continue;
			}
			$result = Mailer::send( (int) $row->announcement_id, [ 'email' => (string) $row->email, 'user_id' => (int) $row->user_id, 'name' => (string) $row->name ], (string) $row->token, true );
			$tries  = (int) $row->attempts + 1;
			if ( true === $result ) {
				$wpdb->update( self::table(), [ 'status' => 'sent', 'sent_at' => \current_time( 'mysql', true ), 'attempts' => $tries, 'error' => null ], [ 'id' => (int) $row->id, 'status' => 'sending' ] );
			} else {
				$wpdb->update( self::table(), [ 'status' => $tries >= self::MAX_ATTEMPTS ? 'failed' : 'queued', 'attempts' => $tries, 'error' => $result->get_error_message() ], [ 'id' => (int) $row->id, 'status' => 'sending' ] );
			}
		}
	}

	private static function finish_done(): void {
		global $wpdb;
		foreach ( \get_posts( [ 'post_type' => PT::CPT, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => PT::META_STATE, 'meta_value' => PT::STATE_SENDING ] ) as $id ) { // phpcs:ignore WordPress.DB.SlowDBQuery
			$left = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE announcement_id = %d AND status IN ('queued','sending')", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( 0 === $left ) {
				\update_post_meta( (int) $id, PT::META_STATE, PT::STATE_SENT );
				\update_post_meta( (int) $id, PT::META_SENT_AT, \time() );
			}
		}
	}

	/** @return true|\WP_Error */
	public static function test_send( int $id, string $email, ?array $override ) {
		$email = \sanitize_email( $email );
		if ( ! \is_email( $email ) ) {
			return new \WP_Error( 'bad_email', \__( 'Enter a valid email address for the test.', 'anchor-schema' ) );
		}
		$user      = \get_user_by( 'email', $email );
		$recipient = $user ? [ 'email' => $email, 'user_id' => (int) $user->ID, 'name' => (string) $user->display_name ] : [ 'email' => $email, 'user_id' => 0, 'name' => '' ];
		if ( null !== $override ) {
			$mail = \Anchor\Announcements\Rendering\Renderer::render( $id, $recipient, '', false, $override );
			$ok   = \wp_mail( $email, '[Test] ' . $mail['subject'], $mail['html'], Mailer::headers( '' ) );
			return $ok ? true : new \WP_Error( 'mail_failed', \__( 'The test email could not be sent. Check the site\'s mail settings.', 'anchor-schema' ) );
		}
		return Mailer::send( $id, $recipient, '', false, '[Test] ' );
	}
}
