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
	const CREATE_ACTION = 'anchor_events_sheet_create';
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
		\add_action( 'admin_post_' . self::CREATE_ACTION, array( $this, 'handle_create' ) );
		// The [event_manager] front-end form: same fields, saved by save() above
		// because its save goes through wp_update_post() and fires save_post.
		\add_action( 'anchor_events_manager_form_fields', array( $this, 'render_frontend' ), 10, 1 );
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
		$old_ref = $this->sync->ref( $event_id ); // First: drops a ref copied from another event.
		$old_url = (string) \get_post_meta( $event_id, Sheets_Sync::META_URL, true );
		$has_ref = null !== $old_ref;
		if ( $url === $old_url && $has_ref && $this->provider_for_url( $url ) === (string) $old_ref['provider'] ) {
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
			// Keep what was pasted; the metabox shows the Connect message. The
			// old ref belongs to a different sheet, so stop syncing to it.
			// A pasted URL also ends a pending auto-create, or the next run would
			// create a second, orphaned spreadsheet beside the pasted one.
			\update_post_meta( $event_id, Sheets_Sync::META_URL, $url );
			\delete_post_meta( $event_id, Sheets_Sync::META_REF );
			$this->settle_pending( $event_id );
			\delete_post_meta( $event_id, Sheets_Sync::META_STATE );
			\wp_clear_scheduled_hook( Sheets_Sync::HOOK, array( $event_id ) );
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

		$ref['event_id'] = $event_id; // Binds the ref to this event; a duplicated post's copy is then rejected.
		\update_post_meta( $event_id, Sheets_Sync::META_URL, $url );
		\update_post_meta( $event_id, Sheets_Sync::META_REF, $ref );
		$this->settle_pending( $event_id );
		\delete_post_meta( $event_id, Sheets_Sync::META_STATE );
		\delete_transient( $this->notice_key( $event_id ) );
		$target = $this->sync->target_for_event( $event_id );
		if ( $target > 0 ) {
			$this->sync->schedule( $target );
		}
	}

	/** A hand-pasted or cleared URL ends any pending auto-create. */
	private function settle_pending( int $event_id ) {
		if ( \get_post_meta( $event_id, Sheets_Sync::META_AUTOCREATE, true ) === 'pending' ) {
			\update_post_meta( $event_id, Sheets_Sync::META_AUTOCREATE, 'done' );
		}
	}

	private function clear( int $event_id ) {
		// WP runs the publish transition before save_post, so an empty field on a
		// first publish must stay a no-op or it would cancel a pending auto-create.
		$had = \get_post_meta( $event_id, Sheets_Sync::META_URL, true ) !== '' || \get_post_meta( $event_id, Sheets_Sync::META_REF, true ) !== '';
		if ( ! $had ) {
			return;
		}
		$this->settle_pending( $event_id );
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
		} else {
			$this->fail( $event_id, \__( 'Nothing to sync: this event has no connected spreadsheet, or takes sign-ups on an external form.', 'anchor-schema' ) );
		}
		$this->redirect_back( $event_id );
	}

	/* ------------------------------------------------------------------
	 * Create spreadsheet
	 * ---------------------------------------------------------------- */

	public function handle_create() {
		$event_id = isset( $_REQUEST['event_id'] ) ? (int) $_REQUEST['event_id'] : 0;
		\check_admin_referer( self::CREATE_ACTION . '_' . $event_id );
		if ( $event_id <= 0 || ! $this->can_manage( $event_id ) ) {
			\wp_die( \esc_html__( 'You are not allowed to do that.', 'anchor-schema' ), '', array( 'response' => 403 ) );
		}
		$state = $this->sync->create_now( $event_id );
		if ( ! $state ) {
			$this->fail( $event_id, \__( 'Nothing to create: this event already has a spreadsheet, or takes sign-ups on an external form.', 'anchor-schema' ) );
		}
		$this->redirect_back( $event_id );
	}

	/**
	 * Back to the screen the button was pressed on: the `return` arg when it is
	 * on this site (the front-end manager form), else the wp-admin edit screen.
	 */
	private function redirect_back( int $event_id ) {
		$fallback = (string) \get_edit_post_link( $event_id, 'url' );
		$return   = isset( $_REQUEST['return'] ) ? (string) \wp_unslash( $_REQUEST['return'] ) : '';
		\wp_safe_redirect( $return !== '' ? \wp_validate_redirect( $return, $fallback ) : $fallback );
		exit;
	}

	private function action_url( string $action, int $event_id, string $return ): string {
		$url = \admin_url( 'admin-post.php?action=' . $action . '&event_id=' . $event_id );
		if ( $return !== '' ) {
			$url = \add_query_arg( 'return', \rawurlencode( $return ), $url );
		}
		return \wp_nonce_url( $url, $action . '_' . $event_id );
	}

	/* ------------------------------------------------------------------
	 * Render
	 * ---------------------------------------------------------------- */

	/** @param \WP_Post $post */
	public function render( $post ) {
		$this->render_body( (int) $post->ID, self::admin_ui(), '' );
	}

	/**
	 * The same controls inside the [event_manager] front-end form, as one more
	 * form section. Its buttons come back to this page, not to wp-admin.
	 *
	 * @param int $event_id 0 while creating a new event.
	 */
	public function render_frontend( $event_id ) {
		$event_id = (int) $event_id;
		if ( $event_id > 0 && ! $this->can_manage( $event_id ) ) {
			return;
		}
		// data-step 4 (Registration) so the step wizard shows it with sign-up settings.
		echo '<div class="anchor-event-section anchor-event-sheet" data-step="4">';
		echo '<h3>' . \esc_html__( 'Spreadsheet', 'anchor-schema' ) . '</h3>';
		$this->render_body( $event_id, self::frontend_ui(), \remove_query_arg( 'event_manager_notice' ) );
		if ( $event_id <= 0 ) {
			echo '<p class="anchor-event-hint">' . \esc_html__( 'Paste a link now, or create the event first and come back to make a new spreadsheet.', 'anchor-schema' ) . '</p>';
		}
		echo '</div>';
	}

	/** @return array<string,string> */
	private static function admin_ui(): array {
		return array( 'input' => 'widefat', 'hint' => 'description', 'button' => 'button' );
	}

	/** @return array<string,string> */
	private static function frontend_ui(): array {
		return array( 'input' => '', 'hint' => 'anchor-event-hint', 'button' => 'anchor-event-button-secondary' );
	}

	/**
	 * @param array<string,string> $ui     CSS classes for this screen.
	 * @param string               $return Where the buttons come back to; '' = the wp-admin edit screen.
	 */
	private function render_body( int $event_id, array $ui, string $return ) {
		if ( $event_id > 0 && $this->module->uses_external_signup( $event_id ) ) {
			echo '<p>' . \esc_html__( "This event takes sign-ups on an external form, so there's nothing to sync.", 'anchor-schema' ) . '</p>';
			return;
		}
		$ref     = $event_id > 0 ? $this->sync->ref( $event_id ) : null;
		$url     = $event_id > 0 ? (string) \get_post_meta( $event_id, Sheets_Sync::META_URL, true ) : '';
		$has_ref = null !== $ref;

		\wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD, false ); // The host form already sends its referer.
		echo '<p><label for="anchor_event_sheet_url"><strong>' . \esc_html__( 'Spreadsheet URL', 'anchor-schema' ) . '</strong></label>';
		echo '<input type="url" class="' . \esc_attr( $ui['input'] ) . '" id="anchor_event_sheet_url" name="' . \esc_attr( self::URL_FIELD ) . '" value="' . \esc_attr( $url ) . '" placeholder="https://docs.google.com/spreadsheets/d/..."></p>';
		echo '<p class="' . \esc_attr( $ui['hint'] ) . '">' . \esc_html__( 'Every registration field and answer is copied to this spreadsheet.', 'anchor-schema' ) . '</p>';
		if ( $event_id <= 0 ) {
			return;
		}

		$notice = \get_transient( $this->notice_key( $event_id ) );
		if ( $notice ) {
			echo '<p style="color:#b32d2e">' . \esc_html( (string) $notice ) . '</p>';
			\delete_transient( $this->notice_key( $event_id ) );
		}

		$pending = \get_post_meta( $event_id, Sheets_Sync::META_AUTOCREATE, true ) === 'pending';
		$this->render_connection( $url, $has_ref ? $ref : ( $pending ? array( 'provider' => 'google' ) : $ref ) );
		if ( $has_ref || $pending ) {
			$this->render_state( $event_id, $has_ref, $ui, $return );
		}
		$target = $this->sync->target_for_event( $event_id );
		if ( ! $has_ref && $target > 0 && $target !== $event_id ) {
			echo '<p>' . \esc_html__( "This date's registrations go to the series spreadsheet on the main event.", 'anchor-schema' ) . '</p>';
		}
		if ( $this->sync->can_create( $event_id ) ) {
			$link = $this->action_url( self::CREATE_ACTION, $event_id, $return );
			echo '<p><a class="' . \esc_attr( $ui['button'] ) . '" href="' . \esc_url( $link ) . '">' . \esc_html__( 'Create spreadsheet', 'anchor-schema' ) . '</a></p>';
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

	private function render_state( int $event_id, bool $has_ref, array $ui, string $return ) {
		$state = $this->sync->get_state( $event_id );
		if ( $state ) {
			$when = \wp_date( 'M j, Y g:i a', (int) ( $state['last_sync'] ?? 0 ) );
			if ( ! empty( $state['ok'] ) && $has_ref ) {
				echo '<p>' . \esc_html( \sprintf(
					/* translators: 1: time, 2: row count */
					\_n( 'Last sync: %1$s, %2$d row.', 'Last sync: %1$s, %2$d rows.', (int) ( $state['rows'] ?? 0 ), 'anchor-schema' ),
					$when,
					(int) ( $state['rows'] ?? 0 )
				) ) . '</p>';
			} elseif ( empty( $state['ok'] ) ) {
				echo '<p style="color:#b32d2e">' . \esc_html( \sprintf(
					/* translators: 1: time, 2: error */
					\__( 'Last sync failed (%1$s): %2$s', 'anchor-schema' ),
					$when,
					(string) ( $state['message'] ?? '' )
				) ) . '</p>';
			}
			if ( ! empty( $state['ok'] ) && ! empty( $state['message'] ) ) {
				echo '<p style="color:#996800">' . \esc_html( (string) $state['message'] ) . '</p>';
			}
		} elseif ( $has_ref ) {
			echo '<p>' . \esc_html__( 'Not synced yet.', 'anchor-schema' ) . '</p>';
		}
		if ( ! $has_ref ) {
			return;
		}
		$link = $this->action_url( self::SYNC_ACTION, $event_id, $return );
		echo '<p><a class="' . \esc_attr( $ui['button'] ) . '" href="' . \esc_url( $link ) . '">' . \esc_html__( 'Sync now', 'anchor-schema' ) . '</a></p>';
	}
}
