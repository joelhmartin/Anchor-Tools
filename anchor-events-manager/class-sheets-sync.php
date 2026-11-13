<?php
/**
 * Mirrors an event's registrant roster into a connected spreadsheet.
 *
 * Triggers only schedule a debounced single event; the run itself is a full,
 * idempotent reconcile (Sheets_Reconciler) so a missed trigger heals next run.
 *
 * @package Anchor\Events
 */

namespace Anchor\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sheets_Sync {

	const META_URL   = '_anchor_event_sheet_url';
	const META_REF   = '_anchor_event_sheet_ref';
	const META_STATE = '_anchor_event_sheet_state';
	const META_SHARED = '_anchor_event_sheet_shared';
	const HOOK       = 'anchor_events_sheet_sync';
	const DELAY      = 30;
	const LOCK_TTL   = 120;

	/** @var Module */
	private $module;

	public function __construct( $module ) {
		$this->module = $module;
		\add_action( 'anchor_events_seat_created', array( $this, 'on_seat' ), 10, 1 );
		\add_action( 'anchor_events_seat_status_changed', array( $this, 'on_seat' ), 10, 1 );
		\add_action( 'added_post_meta', array( $this, 'on_meta' ), 10, 2 );
		\add_action( 'updated_post_meta', array( $this, 'on_meta' ), 10, 2 );
		\add_action( self::HOOK, array( $this, 'run' ), 10, 1 );
	}

	/** @param int $seat_id */
	public function on_seat( $seat_id ) {
		$this->schedule_for_seat( (int) $seat_id );
	}

	/**
	 * @param int $meta_id
	 * @param int $object_id
	 */
	public function on_meta( $meta_id, $object_id ) {
		if ( \get_post_type( (int) $object_id ) !== Module::REG_CPT ) {
			return;
		}
		$this->schedule_for_seat( (int) $object_id );
	}

	private function schedule_for_seat( $seat_id ) {
		$event_id = (int) \get_post_meta( $seat_id, '_anchor_event_id', true );
		if ( $event_id <= 0 ) {
			return;
		}
		$target = $this->target_for_event( $event_id );
		if ( $target > 0 ) {
			$this->schedule( $target );
		}
	}

	/**
	 * The event that owns the sheet for $event_id: itself, else its group
	 * parent, else 0. External-signup events keep no roster so never sync.
	 */
	public function target_for_event( int $event_id ): int {
		if ( $event_id <= 0 ) {
			return 0;
		}
		if ( $this->has_ref( $event_id ) ) {
			return $this->module->uses_external_signup( $event_id ) ? 0 : $event_id;
		}
		$parent = $this->module->occurrences ? (int) $this->module->occurrences->parent_of( $event_id ) : 0;
		if ( $parent > 0 && $this->has_ref( $parent ) ) {
			return $parent;
		}
		return 0;
	}

	private function has_ref( int $event_id ): bool {
		$ref = \get_post_meta( $event_id, self::META_REF, true );
		return \is_array( $ref ) && ! empty( $ref['provider'] );
	}

	/** Queue one sync SELF::DELAY seconds out unless one is already queued. */
	public function schedule( int $target ): void {
		if ( $target <= 0 || \wp_next_scheduled( self::HOOK, array( $target ) ) ) {
			return;
		}
		\wp_schedule_single_event( \time() + self::DELAY, self::HOOK, array( $target ) );
	}

	/** @return array{last_sync?:int,ok?:bool,message?:string,rows?:int} */
	public function get_state( int $event_id ): array {
		$state = \get_post_meta( $event_id, self::META_STATE, true );
		return \is_array( $state ) ? $state : array();
	}

	/**
	 * Full reconcile of the target's roster into its sheet.
	 *
	 * @return array The saved state {last_sync, ok, message, rows}.
	 */
	public function run( $target ): array {
		$target = (int) $target;
		$ref    = \get_post_meta( $target, self::META_REF, true );
		if ( $target <= 0 || ! \is_array( $ref ) || empty( $ref['provider'] ) || $this->module->uses_external_signup( $target ) ) {
			return array();
		}
		$lock = 'anchor_ev_sheet_lock_' . $target;
		if ( \get_transient( $lock ) ) {
			return $this->get_state( $target );
		}
		\set_transient( $lock, 1, self::LOCK_TTL );
		try {
			$result = $this->sync_once( $target, $ref );
		} finally {
			\delete_transient( $lock );
		}
		if ( \is_wp_error( $result ) ) {
			$state = array( 'last_sync' => \time(), 'ok' => false, 'message' => $result->get_error_message(), 'rows' => 0 );
			Events_Log::error( 'sheet_sync_failed', array( 'event_id' => $target, 'code' => $result->get_error_code() ) );
		} else {
			$state = array( 'last_sync' => \time(), 'ok' => true, 'message' => $this->share_step( $target, $ref ), 'rows' => (int) $result );
		}
		\update_post_meta( $target, self::META_STATE, $state );
		return $state;
	}

	/** @return int|\WP_Error Roster row count on success. */
	private function sync_once( int $target, array $ref ) {
		$adapter = \Anchor_Sheet_Adapters::for_ref( $ref );
		if ( ! $adapter ) {
			return new \WP_Error( 'no_adapter', \__( 'Unsupported spreadsheet provider.', 'anchor-schema' ) );
		}
		$roster = $this->module->roster;
		$table  = $this->module->occurrences && $this->module->occurrences->is_group_parent( $target )
			? $roster->export_table_all_dates_public( $target, 'all' )
			: $roster->export_table_public( $target, 'all' );

		$existing = $adapter->read( $ref );
		if ( \is_wp_error( $existing ) ) {
			return $existing;
		}
		$plan = Sheets_Reconciler::reconcile( $existing, $table, \__( 'Seat ID', 'anchor-schema' ) );

		$cells = array();
		foreach ( $plan['header_append'] as $i => $label ) {
			$cells[] = array( 1, $plan['header_start_col'] + $i, $label );
		}
		$cells = \array_merge( $cells, $plan['cells'] );

		if ( $plan['header_append'] ) {
			$ok = $adapter->ensure_columns( $ref, $plan['header_start_col'] + \count( $plan['header_append'] ) );
			if ( \is_wp_error( $ok ) ) {
				return $ok;
			}
		}
		if ( $cells ) {
			$ok = $adapter->write_cells( $ref, $cells );
			if ( \is_wp_error( $ok ) ) {
				return $ok;
			}
		}
		if ( $plan['appends'] ) {
			$ok = $adapter->append_rows( $ref, $plan['appends'] );
			if ( \is_wp_error( $ok ) ) {
				return $ok;
			}
		}
		return \count( $table['rows'] );
	}

	/**
	 * Share the spreadsheet with event managers / extra emails (Google only).
	 * Runs after the row writes; any failure is returned as a warning string and never fails the sync.
	 *
	 * @return string Warning message, '' when nothing to report.
	 */
	private function share_step( int $target, array $ref ): string {
		$adapter = \Anchor_Sheet_Adapters::for_ref( $ref );
		if ( ! $adapter || ! \method_exists( $adapter, 'share' ) ) {
			return '';
		}
		$cfg = \Anchor_Sheet_Google::share_settings();
		if ( ! $cfg['managers'] && ! $cfg['emails'] ) {
			return '';
		}
		$conn = \Anchor_OAuth_Connector::get( 'google' );
		if ( ! $conn || ! $conn->has_scope( \Anchor_Sheet_Google::DRIVE_SCOPE ) ) {
			return \__( 'Reconnect to enable auto-share', 'anchor-schema' );
		}
		$emails = $cfg['emails'];
		if ( $cfg['managers'] ) {
			$emails = \array_merge( $emails, $this->manager_emails() );
		}
		$emails = \array_values( \array_unique( \array_map( 'strtolower', $emails ) ) );
		\sort( $emails );
		$hash = \sha1( \implode( ',', $emails ) . '|' . $cfg['role'] );
		if ( ! $emails || \get_post_meta( $target, self::META_SHARED, true ) === $hash ) {
			return '';
		}
		$res = $adapter->share( $ref, $emails, $cfg['role'], $cfg['notify'] );
		if ( \is_wp_error( $res ) ) {
			Events_Log::error( 'sheet_share_failed', array( 'event_id' => $target, 'code' => $res->get_error_code() ) );
			/* translators: %s error message */
			return \sprintf( \__( 'Rows synced, but sharing failed: %s', 'anchor-schema' ), $res->get_error_message() );
		}
		\update_post_meta( $target, self::META_SHARED, $hash );
		return '';
	}

	/** @return string[] Account emails of every user holding the events capability. */
	private function manager_emails(): array {
		$out = array();
		foreach ( \get_users( array( 'capability' => Module::events_capability() ) ) as $u ) {
			if ( \is_email( $u->user_email ) ) {
				$out[] = $u->user_email;
			}
		}
		return $out;
	}
}
