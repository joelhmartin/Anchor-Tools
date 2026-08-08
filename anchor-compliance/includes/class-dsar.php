<?php
/**
 * Anchor Compliance — privacy (DSAR) requests.
 *
 * Public intake form + a database-backed admin queue for access / delete /
 * correct / opt-out requests, plus a bridge into WordPress core's own
 * Tools > Export Personal Data / Erase Personal Data screens. Core already
 * knows how to walk every plugin's exporters/erasers and manage the
 * confirm-by-email workflow — this class only ever answers "what does the
 * compliance module itself hold for this email", it never re-implements
 * core's request lifecycle.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Anchor_Compliance_Dsar {

	const DB_VERSION_OPTION = 'anchor_compliance_dsar_db_version';
	const DB_VERSION        = '1';
	const ACTION            = 'anchor_compliance_dsar';
	const NONCE_FIELD       = '_wpnonce';
	const HONEYPOT          = 'anchor_cmp_hp';
	const MENU_SLUG         = 'anchor-compliance-dsar';

	const TYPES    = [ 'access', 'delete', 'correct', 'optout' ];
	const STATUSES = [ 'new', 'in_progress', 'completed', 'rejected' ];

	/**
	 * Registers only the WordPress-core privacy bridge. Everything
	 * front-end/admin-facing (shortcode, admin-post handler, the queue
	 * submenu, the install-on-admin_init hook) is wired by
	 * Anchor_Compliance_Module::__construct(), matching every other
	 * collaborator in this module — the module is the single place that
	 * decides what's active, this class stays a plain service.
	 */
	public function __construct() {
		add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_exporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ $this, 'register_eraser' ] );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'anchor_privacy_requests';
	}

	public static function install() {
		global $wpdb;

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			type VARCHAR(16) NOT NULL DEFAULT 'access',
			email VARCHAR(255) NOT NULL DEFAULT '',
			name VARCHAR(255) NOT NULL DEFAULT '',
			details TEXT NOT NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'new',
			deadline DATETIME NOT NULL,
			ip_hash CHAR(64) NOT NULL DEFAULT '',
			notes TEXT NOT NULL,
			PRIMARY KEY (id),
			KEY email (email),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/** Run install once, and whenever the schema version moves. */
	public static function maybe_install() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * The last octet (v4) or host identifier (v6) is dropped before hashing,
	 * then salted — identical approach to Anchor_Compliance_Consent_Log::hash_ip(),
	 * duplicated here rather than shared because this is a plain, dependency-free
	 * static helper and the two classes otherwise have no relationship. See that
	 * method's docblock for why IPv6 goes through inet_pton()/inet_ntop() rather
	 * than exploding on ':'.
	 */
	private static function hash_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$parts     = explode( '.', $ip );
			$truncated = "{$parts[0]}.{$parts[1]}.{$parts[2]}.0";
		} else {
			$bin = inet_pton( $ip );
			if ( false === $bin || 16 !== strlen( $bin ) ) {
				return '';
			}
			$network   = substr( $bin, 0, 8 ) . str_repeat( "\0", 8 );
			$truncated = inet_ntop( $network );
		}

		return hash( 'sha256', $truncated . wp_salt( 'auth' ) );
	}

	/**
	 * Create a privacy request.
	 *
	 * @param array $args type, email, name, details.
	 * @return int|WP_Error Insert ID, or WP_Error on validation/rate-limit failure.
	 */
	public function create( array $args ) {
		global $wpdb;

		$type = isset( $args['type'] ) ? sanitize_key( (string) $args['type'] ) : '';
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return new WP_Error( 'invalid_type', __( 'Please choose a valid request type.', 'anchor-schema' ) );
		}

		$email = isset( $args['email'] ) ? sanitize_email( (string) $args['email'] ) : '';
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', __( 'Please enter a valid email address.', 'anchor-schema' ) );
		}

		// Rate limit: a transient keyed by a salted hash of email+IP, never the
		// raw address itself. The key only ever lives in wp_options for the
		// 15-minute window and is never associated with the stored request row.
		//
		// The email is lowercased (sanitize_email() trims but does NOT lowercase)
		// so "a@b.com" and "A@B.com" collide on the same key rather than letting
		// a public, unauthenticated requester bypass the limiter — and therefore
		// double the wp_mail() sends below — just by varying case.
		//
		// The hash is salted with wp_salt('auth') like every other hash in this
		// module: without a salt this would be an unsalted hash of two often-
		// guessable values (email, coarse IP), so anyone able to read wp_options
		// (e.g. no persistent object cache, so the transient lands there as
		// `_transient_anchor_cmp_dsar_rl_<hash>`) could confirm a *guessed*
		// email+IP pairing was rate-limited within the window.
		$raw_ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		$rl_key  = 'anchor_cmp_dsar_rl_' . hash( 'sha256', strtolower( $email ) . '|' . $raw_ip . wp_salt( 'auth' ) );
		if ( false !== get_transient( $rl_key ) ) {
			return new WP_Error( 'rate_limited', __( 'A request from this email was already submitted recently. Please wait a few minutes and try again.', 'anchor-schema' ) );
		}

		$name    = isset( $args['name'] ) ? sanitize_text_field( (string) $args['name'] ) : '';
		$details = isset( $args['details'] ) ? wp_strip_all_tags( (string) $args['details'] ) : '';

		$response_days = (int) Anchor_Compliance_Settings::get()['dsar']['response_days'];
		$deadline      = gmdate( 'Y-m-d H:i:s', strtotime( "+{$response_days} days" ) );

		$ok = $wpdb->insert(
			self::table(),
			[
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'type'       => $type,
				'email'      => $email,
				'name'       => $name,
				'details'    => $details,
				'status'     => 'new',
				'deadline'   => $deadline,
				'ip_hash'    => self::hash_ip(),
				'notes'      => '',
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		if ( ! $ok ) {
			return new WP_Error( 'db_error', __( 'Your request could not be saved. Please try again.', 'anchor-schema' ) );
		}

		$id = (int) $wpdb->insert_id;

		// Only start the rate-limit window once the request is actually stored,
		// so a DB failure never blocks a genuine retry.
		set_transient( $rl_key, 1, 15 * MINUTE_IN_SECONDS );

		$this->notify( $id, $type, $email, $deadline );

		return $id;
	}

	/**
	 * Notify the site owner and confirm receipt to the requester. Both sends
	 * are best-effort: a mail outage must never lose an already-persisted
	 * DSAR record, so wp_mail()'s return value is intentionally ignored on
	 * both calls rather than surfaced as part of create()'s result.
	 */
	private function notify( $id, $type, $email, $deadline ) {
		$opts = Anchor_Compliance_Settings::get();
		$to   = ! empty( $opts['dsar']['notify_email'] ) ? $opts['dsar']['notify_email'] : get_option( 'admin_email' );

		wp_mail(
			$to,
			sprintf(
				/* translators: %s: request type (access/delete/correct/optout) */
				__( 'New privacy request: %s', 'anchor-schema' ),
				$type
			),
			sprintf(
				/* translators: 1: request type, 2: requester email, 3: statutory deadline, 4: request ID */
				__( "A new \"%1\$s\" privacy request was submitted by %2\$s.\nResponse deadline: %3\$s\nRequest ID: #%4\$d", 'anchor-schema' ),
				$type,
				$email,
				$deadline,
				$id
			)
		);

		wp_mail(
			$email,
			__( 'We received your privacy request', 'anchor-schema' ),
			sprintf(
				/* translators: 1: request type, 2: statutory deadline */
				__( "Thanks — we received your \"%1\$s\" request and will respond by %2\$s.", 'anchor-schema' ),
				$type,
				$deadline
			)
		);
	}

	public function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ) );
	}

	/**
	 * Returns the WHERE fragment and its unsubstituted placeholders — NOT a
	 * pre-prepared fragment. class-consent-log.php's where() calls
	 * $wpdb->prepare() internally and hands back an already-substituted
	 * string, which is safe there because every caller only ever feeds it
	 * admin-selected values (a method/region dropdown). This class's query()
	 * is also reached from privacy_export(), whose $args['email'] originates
	 * as public, unauthenticated form input — and is_email() permits '%' in
	 * the local part (RFC 5322 atext; e.g. "test%40x@example.com" is a valid
	 * address). Feeding an already-prepared fragment containing a literal '%'
	 * back into a second $wpdb->prepare() call as part of its format string
	 * makes that '%' a stray conversion specifier, which WordPress 6.2+
	 * treats as a fatal format error. Returning the raw SQL + args instead and
	 * doing exactly one prepare() (in query()) avoids the double-substitution
	 * entirely.
	 *
	 * @return array{0:string,1:array} [ $sql, $prepare_args ]
	 */
	private function where( array $args ) {
		$where = [ '1=1' ];
		$prep  = [];

		if ( ! empty( $args['status'] ) ) {
			$where[] = 'status = %s';
			$prep[]  = sanitize_key( $args['status'] );
		}
		if ( ! empty( $args['email'] ) ) {
			$where[] = 'email = %s';
			$prep[]  = sanitize_email( $args['email'] );
		}

		return [ implode( ' AND ', $where ), $prep ];
	}

	public function query( array $args = [] ) {
		global $wpdb;
		$limit  = min( 500, max( 1, (int) ( $args['limit'] ?? 50 ) ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );

		list( $where_sql, $where_prep ) = $this->where( $args );

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . " WHERE {$where_sql} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
				array_merge( $where_prep, [ $limit, $offset ] )
			)
		);
	}

	/** @return bool True on success, false when $status isn't one of the four valid values. */
	public function set_status( $id, $status ) {
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}

		global $wpdb;
		$result = $wpdb->update(
			self::table(),
			[ 'status' => $status ],
			[ 'id' => (int) $id ],
			[ '%s' ],
			[ '%d' ]
		);

		return false !== $result;
	}

	/* ---------------------------------------------------------------------
	 * Front-end: shortcode + submit handler
	 * ------------------------------------------------------------------- */

	private static function type_labels() {
		return [
			'access'  => __( 'Access the data we hold about me', 'anchor-schema' ),
			'delete'  => __( 'Delete my data', 'anchor-schema' ),
			'correct' => __( 'Correct my data', 'anchor-schema' ),
			'optout'  => __( 'Opt out of sale/sharing of my data', 'anchor-schema' ),
		];
	}

	/** `[anchor_privacy_request]` */
	public function shortcode( $atts = [] ) {
		$opts = Anchor_Compliance_Settings::get();

		if ( empty( $opts['dsar']['enabled'] ) ) {
			return '<p class="anchor-cmp-dsar-disabled">' . esc_html__( 'Privacy requests cannot be submitted online right now. Please contact us directly to make a request about your personal data.', 'anchor-schema' ) . '</p>';
		}

		$notice = isset( $_GET['anchor_cmp'] ) ? sanitize_key( wp_unslash( $_GET['anchor_cmp'] ) ) : '';

		ob_start();

		if ( 'ok' === $notice ) {
			echo '<p class="anchor-cmp-dsar-notice anchor-cmp-dsar-success">' . esc_html__( 'Thank you — your request has been received. We will respond within the required timeframe.', 'anchor-schema' ) . '</p>';
		} elseif ( 'error' === $notice ) {
			echo '<p class="anchor-cmp-dsar-notice anchor-cmp-dsar-error">' . esc_html__( 'Sorry, we could not process your request. Please check the form and try again.', 'anchor-schema' ) . '</p>';
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="anchor-cmp-dsar-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
			<?php wp_nonce_field( self::ACTION, self::NONCE_FIELD ); ?>

			<p class="anchor-cmp-dsar-hp" aria-hidden="true" style="position:absolute!important;left:-9999px!important;top:-9999px!important;height:0;width:0;overflow:hidden;">
				<label for="<?php echo esc_attr( self::HONEYPOT ); ?>"><?php esc_html_e( 'Leave this field blank', 'anchor-schema' ); ?></label>
				<input type="text" id="<?php echo esc_attr( self::HONEYPOT ); ?>" name="<?php echo esc_attr( self::HONEYPOT ); ?>" value="" tabindex="-1" autocomplete="off" />
			</p>

			<p>
				<label for="anchor_cmp_email"><?php esc_html_e( 'Email address', 'anchor-schema' ); ?> <abbr title="<?php esc_attr_e( 'required', 'anchor-schema' ); ?>">*</abbr></label><br />
				<input type="email" id="anchor_cmp_email" name="anchor_cmp_email" required="required" />
			</p>

			<p>
				<label for="anchor_cmp_name"><?php esc_html_e( 'Name', 'anchor-schema' ); ?></label><br />
				<input type="text" id="anchor_cmp_name" name="anchor_cmp_name" />
			</p>

			<fieldset>
				<legend><?php esc_html_e( 'What would you like to request?', 'anchor-schema' ); ?></legend>
				<?php foreach ( self::type_labels() as $type => $label ) : ?>
					<label>
						<input type="radio" name="anchor_cmp_type" value="<?php echo esc_attr( $type ); ?>" <?php checked( 'access', $type ); ?> />
						<?php echo esc_html( $label ); ?>
					</label><br />
				<?php endforeach; ?>
			</fieldset>

			<p>
				<label for="anchor_cmp_details"><?php esc_html_e( 'Details (optional)', 'anchor-schema' ); ?></label><br />
				<textarea id="anchor_cmp_details" name="anchor_cmp_details" rows="4"></textarea>
			</p>

			<p>
				<button type="submit"><?php esc_html_e( 'Submit request', 'anchor-schema' ); ?></button>
			</p>
		</form>
		<?php
		return ob_get_clean();
	}

	/**
	 * Nonce check, honeypot check, and create() — everything handle_submit()
	 * needs to decide 'ok' vs 'error', minus the wp_safe_redirect()+exit that
	 * makes the real entry point unreachable from PHPUnit (see e.g.
	 * tests/test-event-manager-save.php for the same constraint elsewhere in
	 * this plugin: a raw exit() terminates the test process). Pulled out so
	 * the honeypot path — which must be provably indistinguishable from a
	 * real success and must provably create no row — can be exercised
	 * directly.
	 *
	 * @param array $post Superglobal-shaped, already wp_unslash()'d.
	 * @return string 'ok' or 'error'.
	 */
	public function process_submission( array $post ) {
		if ( ! isset( $post[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( $post[ self::NONCE_FIELD ], self::ACTION ) ) {
			return 'error';
		}

		// Honeypot tripped: a human never sees or fills this field, so treat it
		// as a bot and report exactly the same outcome as a real success —
		// create() is never called, so no row, no emails. Anything else here
		// (an error, a different code path) would teach the bot it was caught.
		if ( ! empty( $post[ self::HONEYPOT ] ) ) {
			return 'ok';
		}

		$result = $this->create( [
			'type'    => $post['anchor_cmp_type'] ?? '',
			'email'   => $post['anchor_cmp_email'] ?? '',
			'name'    => $post['anchor_cmp_name'] ?? '',
			'details' => $post['anchor_cmp_details'] ?? '',
		] );

		return is_wp_error( $result ) ? 'error' : 'ok';
	}

	/**
	 * `admin_post_anchor_compliance_dsar` / `admin_post_nopriv_anchor_compliance_dsar`.
	 */
	public function handle_submit() {
		$referer = wp_get_referer();
		$back_to = $referer ? $referer : home_url( '/' );

		$status = $this->process_submission( wp_unslash( $_POST ) );

		wp_safe_redirect( add_query_arg( 'anchor_cmp', $status, $back_to ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Admin: queue screen
	 * ------------------------------------------------------------------- */

	public function register_menu() {
		add_submenu_page(
			'options-general.php',
			__( 'Privacy Requests', 'anchor-schema' ),
			__( 'Privacy Requests', 'anchor-schema' ),
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render_admin_page' ]
		);
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'anchor-schema' ) );
		}

		if (
			isset( $_POST['anchor_cmp_dsar_id'], $_POST['anchor_cmp_dsar_status'], $_POST['anchor_cmp_dsar_status_nonce'] )
			&& wp_verify_nonce(
				wp_unslash( $_POST['anchor_cmp_dsar_status_nonce'] ),
				'anchor_cmp_dsar_status_' . (int) $_POST['anchor_cmp_dsar_id']
			)
		) {
			$updated = $this->set_status( (int) $_POST['anchor_cmp_dsar_id'], sanitize_key( wp_unslash( $_POST['anchor_cmp_dsar_status'] ) ) );
			if ( $updated ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Status updated.', 'anchor-schema' ) . '</p></div>';
			}
		}

		$rows = $this->query( [ 'limit' => 200 ] );
		?>
		<div class="wrap anchor-cmp-dsar-queue">
			<h1><?php esc_html_e( 'Privacy Requests', 'anchor-schema' ); ?></h1>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Submitted', 'anchor-schema' ); ?></th>
						<th><?php esc_html_e( 'Type', 'anchor-schema' ); ?></th>
						<th><?php esc_html_e( 'Email', 'anchor-schema' ); ?></th>
						<th><?php esc_html_e( 'Name', 'anchor-schema' ); ?></th>
						<th><?php esc_html_e( 'Status', 'anchor-schema' ); ?></th>
						<th><?php esc_html_e( 'Deadline', 'anchor-schema' ); ?></th>
						<th><?php esc_html_e( 'Days remaining', 'anchor-schema' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'No privacy requests yet.', 'anchor-schema' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<?php $days_remaining = (int) ceil( ( strtotime( $row->deadline ) - time() ) / DAY_IN_SECONDS ); ?>
						<tr>
							<td><?php echo esc_html( $row->created_at ); ?></td>
							<td><?php echo esc_html( $row->type ); ?></td>
							<td><?php echo esc_html( $row->email ); ?></td>
							<td><?php echo esc_html( $row->name ); ?></td>
							<td>
								<form method="post">
									<?php wp_nonce_field( 'anchor_cmp_dsar_status_' . (int) $row->id, 'anchor_cmp_dsar_status_nonce' ); ?>
									<input type="hidden" name="anchor_cmp_dsar_id" value="<?php echo esc_attr( $row->id ); ?>" />
									<select name="anchor_cmp_dsar_status" onchange="this.form.submit()">
										<?php foreach ( self::STATUSES as $status ) : ?>
											<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $row->status, $status ); ?>><?php echo esc_html( $status ); ?></option>
										<?php endforeach; ?>
									</select>
								</form>
							</td>
							<td><?php echo esc_html( $row->deadline ); ?></td>
							<td<?php echo $days_remaining < 0 ? ' style="color:#b32d2e;font-weight:600;"' : ''; ?>>
								<?php echo esc_html( $days_remaining ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * WordPress core privacy bridge
	 * ------------------------------------------------------------------- */

	public function register_exporter( $exporters ) {
		$exporters['anchor-compliance-dsar'] = [
			'exporter_friendly_name' => __( 'Anchor Compliance — privacy requests', 'anchor-schema' ),
			'callback'               => [ $this, 'privacy_export' ],
		];
		return $exporters;
	}

	public function register_eraser( $erasers ) {
		$erasers['anchor-compliance-dsar'] = [
			'eraser_friendly_name' => __( 'Anchor Compliance — privacy requests', 'anchor-schema' ),
			'callback'              => [ $this, 'privacy_erase' ],
		];
		return $erasers;
	}

	/**
	 * Exporter: this module's own DSAR rows for the requested email, plus any
	 * consent-log rows whose consent_id the requester supplies. There is no
	 * server-side link from an email address to a browser's consent cookie —
	 * the DSAR form's free-text "details" field is the only place a requester
	 * can hand us that consent_id (their cookie-preferences receipt shows it),
	 * so any UUID-shaped token found there is looked up against the consent
	 * log. Core supplies $email_address / $page; we never invent our own
	 * pagination scheme.
	 *
	 * @param string $email_address
	 * @param int    $page 1-based.
	 * @return array{data:array,done:bool}
	 */
	public function privacy_export( $email_address, $page = 1 ) {
		global $wpdb;

		$page     = max( 1, (int) $page );
		$per_page = 100;

		$rows = $this->query( [
			'email'  => $email_address,
			'limit'  => $per_page,
			'offset' => ( $page - 1 ) * $per_page,
		] );

		$items       = [];
		$consent_ids = [];

		foreach ( $rows as $row ) {
			$items[] = [
				'group_id'    => 'anchor_compliance_dsar',
				'group_label' => __( 'Privacy Requests', 'anchor-schema' ),
				'item_id'     => 'anchor-compliance-dsar-' . (int) $row->id,
				'data'        => [
					[ 'name' => __( 'Type', 'anchor-schema' ), 'value' => $row->type ],
					[ 'name' => __( 'Submitted', 'anchor-schema' ), 'value' => $row->created_at ],
					[ 'name' => __( 'Status', 'anchor-schema' ), 'value' => $row->status ],
					[ 'name' => __( 'Deadline', 'anchor-schema' ), 'value' => $row->deadline ],
					[ 'name' => __( 'Details', 'anchor-schema' ), 'value' => $row->details ],
				],
			];

			if ( preg_match_all( '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', (string) $row->details, $matches ) ) {
				$consent_ids = array_merge( $consent_ids, $matches[0] );
			}
		}

		if ( $consent_ids && class_exists( 'Anchor_Compliance_Consent_Log' ) ) {
			foreach ( array_unique( $consent_ids ) as $consent_id ) {
				$log_rows = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM ' . Anchor_Compliance_Consent_Log::table() . ' WHERE consent_id = %s',
						substr( $consent_id, 0, 36 )
					)
				);
				foreach ( (array) $log_rows as $log_row ) {
					$items[] = [
						'group_id'    => 'anchor_compliance_consent_log',
						'group_label' => __( 'Consent Records', 'anchor-schema' ),
						'item_id'     => 'anchor-compliance-consent-' . (int) $log_row->id,
						'data'        => [
							[ 'name' => __( 'Consent ID', 'anchor-schema' ), 'value' => $log_row->consent_id ],
							[ 'name' => __( 'Recorded', 'anchor-schema' ), 'value' => $log_row->created_at ],
							[ 'name' => __( 'Region', 'anchor-schema' ), 'value' => $log_row->region ],
							[ 'name' => __( 'Categories', 'anchor-schema' ), 'value' => $log_row->categories ],
						],
					];
				}
			}
		}

		return [
			'data' => $items,
			'done' => count( $rows ) < $per_page,
		];
	}

	/**
	 * Eraser: delete this module's own DSAR rows for the requested email.
	 * Core handles confirming the requester owns the address before this ever
	 * runs; we only ever act on the email core hands us.
	 *
	 * @param string $email_address
	 * @param int    $page 1-based (unused — a single DELETE clears every
	 *                     matching row, so there is never a second page).
	 * @return array{items_removed:bool,items_retained:bool,messages:array,done:bool}
	 */
	public function privacy_erase( $email_address, $page = 1 ) {
		global $wpdb;

		$deleted = $wpdb->delete( self::table(), [ 'email' => sanitize_email( $email_address ) ], [ '%s' ] );

		return [
			'items_removed'  => (int) $deleted > 0,
			'items_retained' => false,
			'messages'       => [],
			'done'           => true,
		];
	}
}
