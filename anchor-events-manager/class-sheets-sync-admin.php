<?php
/**
 * Event edit screen for the spreadsheet mirror: the side metabox, its save
 * handler and the "Sync now" action. The sync itself lives in Sheets_Sync.
 *
 * @package Anchor\Events
 */

namespace Anchor\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sheets_Sync_Admin {

	const NONCE_FIELD  = 'anchor_event_sheet_nonce';
	const NONCE_ACTION = 'anchor_event_sheet_save';
	const URL_FIELD    = 'anchor_event_sheet_url';
	const SYNC_ACTION  = 'anchor_events_sheet_sync_now';
	const NOTICE_TTL   = 300;

	/** @var Module */
	private $module;

	/** @var Sheets_Sync */
	private $sync;

	public function __construct( $module, $sync ) {
		$this->module = $module;
		$this->sync   = $sync;
		\add_action( 'add_meta_boxes', array( $this, 'add_metabox' ) );
		\add_action( 'save_post_' . Module::CPT, array( $this, 'save' ), 20, 1 );
		\add_action( 'admin_post_' . self::SYNC_ACTION, array( $this, 'handle_sync_now' ) );
	}

	public function add_metabox() {
		\add_meta_box(
			'anchor_event_spreadsheet',
			\__( 'Spreadsheet sync', 'anchor-schema' ),
			array( $this, 'render' ),
			Module::CPT,
			'side',
			'default'
		);
	}

	private function can_manage( int $event_id ): bool {
		return Roster::current_user_can_manage() && \current_user_can( 'edit_post', $event_id );
	}

	/* ------------------------------------------------------------------
	 * Provider helpers
	 * ---------------------------------------------------------------- */

	/** @return \Anchor_Sheet_Adapter|null */
	private function adapter_for_url( string $url ) {
		// Test seam: inject an in-memory adapter without touching the network.
		$adapter = \apply_filters( 'anchor_events_sheet_adapter_for_url', null, $url );
		return $adapter ? $adapter : \Anchor_Sheet_Adapters::for_url( $url );
	}

	private function provider_of( $adapter ): string {
		if ( $adapter instanceof \Anchor_Sheet_Excel ) {
			return 'microsoft';
		}
		return $adapter instanceof \Anchor_Sheet_Google ? 'google' : '';
	}

	private function provider_for_url( string $url ): string {
		$adapter = $url !== '' ? $this->adapter_for_url( $url ) : null;
		return $adapter ? $this->provider_of( $adapter ) : '';
	}

	/** @return \Anchor_OAuth_Connector|null */
	private function connector( string $provider ) {
		return $provider !== '' ? \Anchor_OAuth_Connector::get( $provider ) : null;
	}

	private function connectors_url(): string {
		return \admin_url( 'options-general.php?page=anchor-schema&tab=connectors' );
	}

	/* ------------------------------------------------------------------
	 * Save
	 * ---------------------------------------------------------------- */

	private function notice_key( int $event_id ): string {
		return 'anchor_ev_sheet_notice_' . $event_id . '_' . \get_current_user_id();
	}

	/** @param int $event_id */
	public function save( $event_id ) {
		$event_id = (int) $event_id;
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! isset( $_POST[ self::URL_FIELD ] ) ) {
			return;
		}
		if ( ( \defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || \wp_is_post_revision( $event_id ) ) {
			return;
		}
		if ( ! \wp_verify_nonce( \sanitize_text_field( \wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! $this->can_manage( $event_id ) ) {
			return;
		}

		$url = \trim( (string) \esc_url_raw( \wp_unslash( $_POST[ self::URL_FIELD ] ) ) );
		if ( $url === '' ) {
			$this->clear( $event_id );
			return;
		}
		$old_url = (string) \get_post_meta( $event_id, Sheets_Sync::META_URL, true );
		$old_ref = \get_post_meta( $event_id, Sheets_Sync::META_REF, true );
		$has_ref = \is_array( $old_ref ) && ! empty( $old_ref['provider'] );
		if ( $url === $old_url && $has_ref ) {
			return; // Unchanged and already resolved.
		}

		$adapter = $this->adapter_for_url( $url );
		if ( ! $adapter ) {
			$this->fail( $event_id, \__( 'That link is not a Google Sheets or OneDrive/SharePoint Excel link.', 'anchor-schema' ) );
			return;
		}
		$provider = $this->provider_of( $adapter );
		$conn     = $this->connector( $provider );
		if ( ! $conn || ! $conn->is_connected() || $conn->needs_reconnect() ) {
			// Keep what was pasted; the metabox shows the Connect message.
			\update_post_meta( $event_id, Sheets_Sync::META_URL, $url );
			return;
		}

		$ref = $adapter->resolve( $url );
		if ( \is_wp_error( $ref ) ) {
			$message = $ref->get_error_message();
			if ( 'anchor_sheet_forbidden' === $ref->get_error_code() ) {
				$message = \sprintf(
					/* translators: %s: connected account */
					\__( "The connected account (%s) can't open this spreadsheet. Check the URL, or share it with that account.", 'anchor-schema' ),
					$conn->account()
				);
			}
			$this->fail( $event_id, $message );
			return;
		}

		\update_post_meta( $event_id, Sheets_Sync::META_URL, $url );
		\update_post_meta( $event_id, Sheets_Sync::META_REF, $ref );
		\delete_post_meta( $event_id, Sheets_Sync::META_STATE );
		\delete_transient( $this->notice_key( $event_id ) );
		$target = $this->sync->target_for_event( $event_id );
		if ( $target > 0 ) {
			$this->sync->schedule( $target );
		}
	}

	private function clear( int $event_id ) {
		\delete_post_meta( $event_id, Sheets_Sync::META_URL );
		\delete_post_meta( $event_id, Sheets_Sync::META_REF );
		\delete_post_meta( $event_id, Sheets_Sync::META_STATE );
		\delete_transient( $this->notice_key( $event_id ) );
		\wp_clear_scheduled_hook( Sheets_Sync::HOOK, array( $event_id ) );
	}

	/** Remember a resolve failure for the next metabox render; the old ref stays. */
	private function fail( int $event_id, string $message ) {
		\set_transient( $this->notice_key( $event_id ), $message, self::NOTICE_TTL );
	}

	/* ------------------------------------------------------------------
	 * Sync now
	 * ---------------------------------------------------------------- */

	public function handle_sync_now() {
		$event_id = isset( $_REQUEST['event_id'] ) ? (int) $_REQUEST['event_id'] : 0;
		\check_admin_referer( self::SYNC_ACTION . '_' . $event_id );
		if ( $event_id <= 0 || ! $this->can_manage( $event_id ) ) {
			\wp_die( \esc_html__( 'You are not allowed to do that.', 'anchor-schema' ), '', array( 'response' => 403 ) );
		}
		$target = $this->sync->target_for_event( $event_id );
		if ( $target > 0 ) {
			$this->sync->run( $target );
		}
		\wp_safe_redirect( \add_query_arg( 'anchor_sheet_synced', 1, \get_edit_post_link( $event_id, 'url' ) ) );
		exit;
	}

	/* ------------------------------------------------------------------
	 * Render
	 * ---------------------------------------------------------------- */

	/** @param \WP_Post $post */
	public function render( $post ) {
		$event_id = (int) $post->ID;
		if ( $this->module->uses_external_signup( $event_id ) ) {
			echo '<p>' . \esc_html__( "This event takes sign-ups on an external form, so there's nothing to sync.", 'anchor-schema' ) . '</p>';
			return;
		}
		$url = (string) \get_post_meta( $event_id, Sheets_Sync::META_URL, true );
		$ref = \get_post_meta( $event_id, Sheets_Sync::META_REF, true );
		$has_ref = \is_array( $ref ) && ! empty( $ref['provider'] );

		\wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		echo '<p><label for="anchor_event_sheet_url"><strong>' . \esc_html__( 'Spreadsheet URL', 'anchor-schema' ) . '</strong></label>';
		echo '<input type="url" class="widefat" id="anchor_event_sheet_url" name="' . \esc_attr( self::URL_FIELD ) . '" value="' . \esc_attr( $url ) . '" placeholder="https://docs.google.com/spreadsheets/d/..."></p>';
		echo '<p class="description">' . \esc_html__( 'Every registration field and answer is copied to this spreadsheet.', 'anchor-schema' ) . '</p>';

		$notice = \get_transient( $this->notice_key( $event_id ) );
		if ( $notice ) {
			echo '<p style="color:#b32d2e">' . \esc_html( (string) $notice ) . '</p>';
			\delete_transient( $this->notice_key( $event_id ) );
		}

		$this->render_connection( $url, $ref );
		if ( $has_ref ) {
			$this->render_state( $event_id );
		}
	}

	private function render_connection( string $url, $ref ) {
		$provider = \is_array( $ref ) && ! empty( $ref['provider'] ) ? (string) $ref['provider'] : $this->provider_for_url( $url );
		$conn     = $this->connector( $provider );
		if ( ! $conn ) {
			return;
		}
		if ( $conn->is_connected() && ! $conn->needs_reconnect() ) {
			echo '<p>' . \esc_html( \sprintf(
				/* translators: 1: provider, 2: account */
				\__( 'Connected %1$s account: %2$s', 'anchor-schema' ),
				$conn->label(),
				$conn->account()
			) ) . '</p>';
			return;
		}
		$verb = $conn->needs_reconnect() ? \__( 'Reconnect', 'anchor-schema' ) : \__( 'Connect', 'anchor-schema' );
		echo '<p>' . \esc_html( $verb . ' ' . $conn->label() ) . ' ';
		echo \esc_html__( 'under', 'anchor-schema' ) . ' <a href="' . \esc_url( $this->connectors_url() ) . '">'
			. \esc_html__( 'Settings → Anchor Tools → Connectors', 'anchor-schema' ) . '</a>.</p>';
	}

	private function render_state( int $event_id ) {
		$state = $this->sync->get_state( $event_id );
		if ( $state ) {
			$when = \wp_date( 'M j, Y g:i a', (int) ( $state['last_sync'] ?? 0 ) );
			if ( ! empty( $state['ok'] ) ) {
				echo '<p>' . \esc_html( \sprintf(
					/* translators: 1: time, 2: row count */
					\_n( 'Last sync: %1$s, %2$d row.', 'Last sync: %1$s, %2$d rows.', (int) ( $state['rows'] ?? 0 ), 'anchor-schema' ),
					$when,
					(int) ( $state['rows'] ?? 0 )
				) ) . '</p>';
			} else {
				echo '<p style="color:#b32d2e">' . \esc_html( \sprintf(
					/* translators: 1: time, 2: error */
					\__( 'Last sync failed (%1$s): %2$s', 'anchor-schema' ),
					$when,
					(string) ( $state['message'] ?? '' )
				) ) . '</p>';
			}
		} else {
			echo '<p>' . \esc_html__( 'Not synced yet.', 'anchor-schema' ) . '</p>';
		}
		$link = \wp_nonce_url(
			\admin_url( 'admin-post.php?action=' . self::SYNC_ACTION . '&event_id=' . $event_id ),
			self::SYNC_ACTION . '_' . $event_id
		);
		echo '<p><a class="button" href="' . \esc_url( $link ) . '">' . \esc_html__( 'Sync now', 'anchor-schema' ) . '</a></p>';
	}
}
