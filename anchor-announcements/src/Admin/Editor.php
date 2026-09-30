<?php
declare(strict_types=1);

namespace Anchor\Announcements\Admin;

use Anchor\Announcements\Content\AnnouncementPostType as PT;
use Anchor\Announcements\Module;
use Anchor\Announcements\Rendering\Renderer;
use Anchor\Announcements\Sending\Queue;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** The announcement edit screen: builder, audience, actions, and the save/act handler. */
final class Editor {

	public function __construct() {
		\add_action( 'add_meta_boxes_' . PT::CPT, [ $this, 'boxes' ] );
		\add_action( 'save_post_' . PT::CPT, [ $this, 'save' ] );
		\add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		\add_action( 'admin_notices', [ $this, 'show_notice' ] );
	}

	public static function editable( int $id ): bool {
		return \in_array( PT::state( $id ), [ PT::STATE_DRAFT, PT::STATE_SCHEDULED ], true );
	}

	public static function notice( string $type, string $message ): void {
		\set_transient( 'aa_notice_' . \get_current_user_id(), [ 'type' => $type, 'message' => $message ], 120 );
	}

	public function show_notice(): void {
		$key    = 'aa_notice_' . \get_current_user_id();
		$notice = \get_transient( $key );
		if ( \is_array( $notice ) ) {
			\delete_transient( $key );
			\printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', \esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ), \esc_html( (string) $notice['message'] ) );
		}
	}

	public function boxes( \WP_Post $post ): void {
		\remove_meta_box( 'submitdiv', PT::CPT, 'side' );
		\add_meta_box( 'aa-actions', \__( 'Send', 'anchor-schema' ), [ $this, 'render_actions' ], PT::CPT, 'side', 'high' );
		\add_meta_box( 'aa-email', \__( 'Email', 'anchor-schema' ), [ $this, 'render_email' ], PT::CPT, 'normal', 'high' );
		\add_meta_box( 'aa-audience', \__( 'Audience', 'anchor-schema' ), [ $this, 'render_audience' ], PT::CPT, 'normal', 'default' );
	}

	public function assets( string $hook ): void {
		$screen = \get_current_screen();
		if ( ! $screen || PT::CPT !== $screen->post_type || ! \in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}
		$id       = (int) ( $_GET['post'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$editable = 0 === $id || self::editable( $id );
		if ( $editable ) {
			\Anchor_Email_Kit::enqueue(
				[
					'ajaxUrl'       => \admin_url( 'admin-ajax.php' ),
					'nonce'         => \wp_create_nonce( 'anchor_announcements' ),
					'previewAction' => 'anchor_announcements_preview',
					'tokens'        => \Anchor_Email_Tokens::registered(),
					'emptyTokens'   => [ 'username', 'last_name' ],
				],
				PT::CPT
			);
		}
		$conditions = [];
		foreach ( Module::instance()->conditions->all() as $key => $c ) {
			$conditions[ $key ] = [ 'label' => $c->label(), 'group' => $c->group(), 'fields' => $c->fields() ];
		}
		$base = 'anchor-announcements/assets/';
		\wp_enqueue_style( 'anchor-announcements-admin', \Anchor_Asset_Loader::url( $base . 'admin.css' ), [], Module::VERSION );
		\wp_enqueue_script( 'anchor-announcements-admin', \Anchor_Asset_Loader::url( $base . 'admin.js' ), [ 'jquery' ], Module::VERSION, true );
		\wp_localize_script(
			'anchor-announcements-admin',
			'ANCHOR_AA',
			[
				'ajaxUrl'    => \admin_url( 'admin-ajax.php' ),
				'nonce'      => \wp_create_nonce( 'anchor_announcements' ),
				'postId'     => $id,
				'editable'   => $editable,
				'conditions' => $conditions,
				'labels'     => $id ? Ajax::labels_for( (string) \get_post_meta( $id, PT::META_AUDIENCE, true ) ) : new \stdClass(),
				'i18n'       => [
					'addCondition' => \__( '+ Add condition', 'anchor-schema' ),
					'addGroup'     => \__( '+ Add OR group', 'anchor-schema' ),
					'matchAll'     => \__( 'Match ALL of these', 'anchor-schema' ),
					'or'           => \__( 'OR', 'anchor-schema' ),
					'is'           => \__( 'is', 'anchor-schema' ),
					'isNot'        => \__( 'is not', 'anchor-schema' ),
					'remove'       => \__( 'Remove', 'anchor-schema' ),
					'onlyNegated'  => \__( 'This group only excludes people, so it starts from everyone the site knows.', 'anchor-schema' ),
					'recipients'   => \__( '%1$d recipients (%2$d unsubscribed left out)', 'anchor-schema' ),
					'confirmSend'  => \__( 'Send this announcement to %d people now?', 'anchor-schema' ),
					'nobody'       => \__( 'Nobody matches this audience yet.', 'anchor-schema' ),
					'search'       => \__( 'Search…', 'anchor-schema' ),
				],
			]
		);
	}

	public function render_email( \WP_Post $post ): void {
		\wp_nonce_field( 'aa_editor', 'aa_editor_nonce' );
		if ( self::editable( (int) $post->ID ) || 'auto-draft' === $post->post_status ) {
			echo \Anchor_Email_Kit::builder_markup( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapes its own fields.
				[
					'id'        => 'aa-email',
					'name'      => 'anchor_announcement',
					'subject'   => (string) \get_post_meta( $post->ID, PT::META_SUBJECT, true ),
					'preheader' => (string) \get_post_meta( $post->ID, PT::META_PREHEADER, true ),
					'body'      => (string) \get_post_meta( $post->ID, PT::META_BODY, true ),
				]
			);
			return;
		}
		$mail = Renderer::render( (int) $post->ID, Renderer::sample_recipient(), '', false );
		echo '<p><strong>' . \esc_html__( 'Subject:', 'anchor-schema' ) . '</strong> ' . \esc_html( $mail['subject'] ) . '</p>';
		echo '<iframe class="aa-sent-preview" sandbox="" srcdoc="' . \esc_attr( $mail['html'] ) . '"></iframe>';
	}

	public function render_audience( \WP_Post $post ): void {
		$raw = (string) \get_post_meta( $post->ID, PT::META_AUDIENCE, true );
		if ( ! self::editable( (int) $post->ID ) && 'auto-draft' !== $post->post_status ) {
			$rules = \json_decode( $raw, true );
			echo '<ul class="aa-audience-summary">';
			foreach ( self::describe( \is_array( $rules ) ? $rules : [] ) as $line ) {
				echo '<li>' . \esc_html( $line ) . '</li>';
			}
			echo '</ul>';
			return;
		}
		?>
		<input type="hidden" id="aa-audience-input" name="anchor_announcement[audience]" value="<?php echo \esc_attr( '' !== $raw ? $raw : '{"groups":[]}' ); ?>" />
		<div id="aa-audience-builder"></div>
		<p><button type="button" class="button" id="aa-audience-preview"><?php \esc_html_e( 'Preview audience', 'anchor-schema' ); ?></button> <span id="aa-audience-count" aria-live="polite"></span></p>
		<ol id="aa-audience-sample"></ol>
		<?php
	}

	public function render_actions( \WP_Post $post ): void {
		$id    = (int) $post->ID;
		$state = PT::state( $id );
		$me    = \wp_get_current_user();
		echo '<input type="hidden" name="post_status" value="publish" />';
		echo '<p class="aa-state aa-state--' . \esc_attr( $state ) . '">' . \esc_html( \ucfirst( $state ) ) . '</p>';
		$error = (string) \get_post_meta( $id, '_aa_last_error', true );
		if ( '' !== $error ) {
			echo '<p class="aa-error">' . \esc_html( $error ) . '</p>';
		}

		if ( self::editable( $id ) || 'auto-draft' === $post->post_status ) {
			?>
			<p><button type="submit" class="button button-secondary widefat" name="aa_action" value="save"><?php \esc_html_e( 'Save draft', 'anchor-schema' ); ?></button></p>
			<hr />
			<p><label for="aa-test-email"><?php \esc_html_e( 'Send a test to', 'anchor-schema' ); ?></label>
			<input type="email" class="widefat" id="aa-test-email" value="<?php echo \esc_attr( $me->user_email ); ?>" /></p>
			<p><button type="button" class="button widefat" id="aa-test-send"><?php \esc_html_e( 'Send test', 'anchor-schema' ); ?></button> <span id="aa-test-result" aria-live="polite"></span></p>
			<hr />
			<?php if ( PT::STATE_SCHEDULED === $state ) : ?>
				<p><?php echo \esc_html( \sprintf( \__( 'Scheduled for %s', 'anchor-schema' ), \wp_date( \get_option( 'date_format' ) . ' ' . \get_option( 'time_format' ), (int) \get_post_meta( $id, PT::META_SCHEDULED, true ) ) ) ); ?></p>
				<p><button type="submit" class="button widefat" name="aa_action" value="unschedule"><?php \esc_html_e( 'Unschedule', 'anchor-schema' ); ?></button></p>
			<?php else : ?>
				<p><label for="aa-schedule-at"><?php \esc_html_e( 'Send later', 'anchor-schema' ); ?></label>
				<input type="datetime-local" class="widefat" id="aa-schedule-at" name="aa_schedule_at" /></p>
				<p><button type="submit" class="button widefat" name="aa_action" value="schedule"><?php \esc_html_e( 'Schedule', 'anchor-schema' ); ?></button></p>
			<?php endif; ?>
			<p><button type="submit" class="button button-primary widefat" name="aa_action" value="send_now" id="aa-send-now"><?php \esc_html_e( 'Send now', 'anchor-schema' ); ?></button></p>
			<?php
			return;
		}
		if ( PT::STATE_SENDING === $state ) {
			echo '<p><button type="submit" class="button widefat" name="aa_action" value="pause">' . \esc_html__( 'Pause', 'anchor-schema' ) . '</button></p>';
		}
		if ( PT::STATE_PAUSED === $state ) {
			echo '<p><button type="submit" class="button button-primary widefat" name="aa_action" value="resume">' . \esc_html__( 'Resume', 'anchor-schema' ) . '</button></p>';
		}
		if ( \in_array( $state, [ PT::STATE_SENDING, PT::STATE_PAUSED ], true ) ) {
			echo '<p><button type="submit" class="button widefat" name="aa_action" value="cancel" onclick="return confirm(\'' . \esc_js( \__( 'Stop sending? People not yet emailed will not get it.', 'anchor-schema' ) ) . '\')">' . \esc_html__( 'Cancel sending', 'anchor-schema' ) . '</button></p>';
		}
		echo '<p><a href="#aa-report">' . \esc_html__( 'See the report', 'anchor-schema' ) . '</a></p>';
	}

	public function save( int $post_id ): void {
		if ( ( \defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || \wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['aa_editor_nonce'] ) || ! \wp_verify_nonce( \sanitize_key( \wp_unslash( $_POST['aa_editor_nonce'] ) ), 'aa_editor' ) || ! \current_user_can( Module::CAP ) ) {
			return;
		}
		$fields = (array) \wp_unslash( $_POST['anchor_announcement'] ?? [] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per key below.
		if ( self::editable( $post_id ) ) {
			// $fields is already unslashed and update_post_meta() unslashes again, so wp_slash() every value.
			\update_post_meta( $post_id, PT::META_SUBJECT, \wp_slash( \sanitize_text_field( (string) ( $fields['subject'] ?? '' ) ) ) );
			\update_post_meta( $post_id, PT::META_PREHEADER, \wp_slash( \sanitize_text_field( (string) ( $fields['preheader'] ?? '' ) ) ) );
			\update_post_meta( $post_id, PT::META_BODY, \wp_slash( \Anchor_Email_Sanitizer::body( (string) ( $fields['body'] ?? '' ) ) ) );
			\update_post_meta( $post_id, PT::META_AUDIENCE, \wp_slash( \wp_json_encode( self::encodable( Module::instance()->resolver()->sanitize( (string) ( $fields['audience'] ?? '' ) ) ) ) ) );
			\delete_post_meta( $post_id, '_aa_last_error' );
		}

		$action = \sanitize_key( \wp_unslash( $_POST['aa_action'] ?? 'save' ) );
		switch ( $action ) {
			case 'send_now':
				$res = Queue::start( $post_id );
				\is_wp_error( $res ) ? self::notice( 'error', $res->get_error_message() ) : self::notice( 'success', \sprintf( \__( 'Sending to %d people. Emails go out in batches every minute.', 'anchor-schema' ), $res ) );
				break;
			case 'schedule':
				$raw = \sanitize_text_field( \wp_unslash( $_POST['aa_schedule_at'] ?? '' ) );
				$dt  = \date_create_immutable_from_format( 'Y-m-d\TH:i', $raw, \wp_timezone() );
				$res = $dt ? Queue::schedule( $post_id, $dt->getTimestamp() ) : new \WP_Error( 'bad_time', \__( 'Pick a date and time to schedule.', 'anchor-schema' ) );
				\is_wp_error( $res ) ? self::notice( 'error', $res->get_error_message() ) : self::notice( 'success', \__( 'Scheduled.', 'anchor-schema' ) );
				break;
			case 'unschedule':
				Queue::unschedule( $post_id );
				break;
			case 'pause':
				Queue::pause( $post_id );
				break;
			case 'resume':
				Queue::resume( $post_id );
				break;
			case 'cancel':
				Queue::cancel( $post_id );
				break;
		}
	}

	/** Cast each condition's params to an object so empty params serialize as {} (not []). */
	private static function encodable( array $rules ): array {
		foreach ( $rules['groups'] as $gi => $group ) {
			foreach ( $group['conditions'] as $ci => $c ) {
				$rules['groups'][ $gi ]['conditions'][ $ci ]['params'] = (object) $c['params'];
			}
		}
		return $rules;
	}

	/** @return list<string> */
	public static function describe( array $rules ): array {
		$all   = Module::instance()->conditions->all();
		$lines = [];
		foreach ( (array) ( $rules['groups'] ?? [] ) as $gi => $group ) {
			$parts = [];
			foreach ( (array) ( $group['conditions'] ?? [] ) as $c ) {
				$cond   = $all[ $c['type'] ?? '' ] ?? null;
				$label  = $cond ? $cond->label() : (string) ( $c['type'] ?? '' );
				$values = [];
				foreach ( (array) ( $c['params'] ?? [] ) as $k => $v ) {
					if ( '' === $v || [] === $v ) {
						continue;
					}
					$v        = \is_array( $v ) ? \implode( ', ', \array_map( 'strval', $v ) ) : (string) $v;
					$values[] = \in_array( $k, [ 'from', 'to' ], true ) ? $k . ' ' . $v : $v;
				}
				$dated   = \array_key_exists( 'from', (array) ( $c['params'] ?? [] ) ) || \array_key_exists( 'to', (array) ( $c['params'] ?? [] ) );
				$phrase  = $label . ( $dated ? ' ' : ' is ' ) . \implode( ' ', $values );
				$parts[] = ( ! empty( $c['negate'] ) ? 'NOT ' : '' ) . $phrase;
			}
			$lines[] = ( $gi > 0 ? 'OR ' : '' ) . \implode( ' AND ', $parts );
		}
		return $lines;
	}
}
