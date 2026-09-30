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
 */
final class Queue {

	public const HOOK            = 'anchor_announcements_tick';
	public const SCHEDULE        = 'anchor_announcements_minute';
	public const LAST_RUN_OPTION = 'anchor_announcements_last_tick';
	public const MAX_ATTEMPTS    = 3;
	private const STALE_CLAIM    = 600; // seconds before a crashed claim is retried

	public static function register(): void {
		\add_filter(
			'cron_schedules',
			static function ( $s ) {
				$s[ self::SCHEDULE ] = [ 'interval' => 60, 'display' => \__( 'Every minute (Anchor Announcements)', 'anchor-schema' ) ];
				return $s;
			}
		);
		\add_action( self::HOOK, [ self::class, 'tick' ] );
		\add_action(
			'init',
			static function () {
				if ( ! \wp_next_scheduled( self::HOOK ) ) {
					\wp_schedule_event( \time() + 60, self::SCHEDULE, self::HOOK );
				}
			}
		);
	}

	private static function table(): string {
		return Migrations::table( 'sends' );
	}

	/** @return int|\WP_Error */
	public static function start( int $id ) {
		$state = PT::state( $id );
		if ( ! \in_array( $state, [ PT::STATE_DRAFT, PT::STATE_SCHEDULED ], true ) ) {
			return new \WP_Error( 'wrong_state', \__( 'This announcement has already been sent.', 'anchor-schema' ) );
		}
		if ( '' === \trim( (string) Settings::get()['footer_address'] ) ) {
			return new \WP_Error( 'missing_address', \__( 'Add your mailing address in Announcements > Settings before sending.', 'anchor-schema' ) );
		}
		$body = (string) \get_post_meta( $id, PT::META_BODY, true );
		if ( '' === \trim( (string) \get_post_meta( $id, PT::META_SUBJECT, true ) ) || '' === \trim( \wp_strip_all_tags( $body ) ) ) {
			return new \WP_Error( 'empty_content', \__( 'Add a subject and a message before sending.', 'anchor-schema' ) );
		}

		$rules      = \json_decode( (string) \get_post_meta( $id, PT::META_AUDIENCE, true ), true );
		$recipients = Module::instance()->resolver()->resolve( \is_array( $rules ) ? $rules : [] )->all();
		$sendable   = \array_filter( $recipients, static fn( $r ) => ! Suppressions::is_suppressed( $r['email'] ) );
		if ( ! $sendable ) {
			return new \WP_Error( 'empty_audience', \__( 'Nobody matches this audience (after removing unsubscribed addresses).', 'anchor-schema' ) );
		}

		global $wpdb;
		$now    = \current_time( 'mysql', true );
		$queued = 0;
		foreach ( $recipients as $r ) {
			$suppressed = Suppressions::is_suppressed( $r['email'] );
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
		\update_post_meta( $id, PT::META_SCHEDULED, $timestamp );
		\update_post_meta( $id, PT::META_STATE, PT::STATE_SCHEDULED );
		return true;
	}

	public static function unschedule( int $id ): void {
		if ( PT::STATE_SCHEDULED === PT::state( $id ) ) {
			\delete_post_meta( $id, PT::META_SCHEDULED );
			\update_post_meta( $id, PT::META_STATE, PT::STATE_DRAFT );
		}
	}

	public static function pause( int $id ): void {
		if ( PT::STATE_SENDING === PT::state( $id ) ) {
			\update_post_meta( $id, PT::META_STATE, PT::STATE_PAUSED );
		}
	}

	public static function resume( int $id ): void {
		if ( PT::STATE_PAUSED === PT::state( $id ) ) {
			\update_post_meta( $id, PT::META_STATE, PT::STATE_SENDING );
		}
	}

	public static function cancel( int $id ): void {
		if ( ! \in_array( PT::state( $id ), [ PT::STATE_SENDING, PT::STATE_PAUSED, PT::STATE_SCHEDULED ], true ) ) {
			return;
		}
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status = 'skipped', skip_reason = 'cancelled' WHERE announcement_id = %d AND status = 'queued'", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		\update_post_meta( $id, PT::META_STATE, PT::STATE_CANCELLED );
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
			self::send_batch();
			self::finish_done();
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
				\update_post_meta( (int) $id, '_aa_last_error', $res->get_error_message() );
			}
		}
	}

	private static function recover_stale_claims(): void {
		global $wpdb;
		$cutoff = \gmdate( 'Y-m-d H:i:s', \time() - self::STALE_CLAIM );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status = 'queued' WHERE status = 'sending' AND claimed_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function send_batch(): void {
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
			if ( ! self::claim( (int) $row->id ) ) {
				continue;
			}
			$result = Mailer::send( (int) $row->announcement_id, [ 'email' => (string) $row->email, 'user_id' => (int) $row->user_id, 'name' => (string) $row->name ], (string) $row->token, true );
			$tries  = (int) $row->attempts + 1;
			if ( true === $result ) {
				$wpdb->update( self::table(), [ 'status' => 'sent', 'sent_at' => \current_time( 'mysql', true ), 'attempts' => $tries, 'error' => null ], [ 'id' => (int) $row->id ] );
			} else {
				$wpdb->update( self::table(), [ 'status' => $tries >= self::MAX_ATTEMPTS ? 'failed' : 'queued', 'attempts' => $tries, 'error' => $result->get_error_message() ], [ 'id' => (int) $row->id ] );
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
