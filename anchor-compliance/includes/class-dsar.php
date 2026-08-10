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
 * core's request lifecycle. Intake requests get their own lightweight
 * confirm-by-email step (verify_key/verified_at) so the queue can tell an
 * admin whether the requester actually controls the address they named.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Anchor_Compliance_Dsar {

	const DB_VERSION_OPTION = 'anchor_compliance_dsar_db_version';
	const DB_VERSION        = '2';
	const ACTION            = 'anchor_compliance_dsar';
	const VERIFY_ACTION     = 'anchor_compliance_dsar_verify';
	const NONCE_FIELD       = '_wpnonce';
	const HONEYPOT          = 'anchor_cmp_hp';
	const MENU_SLUG         = 'anchor-compliance-dsar';

	/** Server-side cap on the free-text details field (also the form's maxlength). */
	const DETAILS_MAX_LENGTH = 2000;

	/** How long a verification link stays valid, measured from created_at. */
	const VERIFY_TTL_DAYS = 7;

	const TYPES         = [ 'access', 'delete', 'correct', 'optout' ];
	const STATUSES      = [ 'new', 'in_progress', 'completed', 'rejected' ];
	const OPEN_STATUSES = [ 'new', 'in_progress' ];

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

		// NOTE: dbDelta requires exactly two spaces between "PRIMARY KEY" and
		// the column list — with one space a later re-run can mis-parse the
		// line and emit a duplicate-key ALTER.
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
			verified_at DATETIME NULL DEFAULT NULL,
			verify_key CHAR(64) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
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
	 * Delete closed (completed/rejected) requests older than the configured
	 * dsar.retention_days window. 0 (or negative) means keep forever. Open
	 * requests are never touched regardless of age — a statutory clock that
	 * hasn't been answered must not silently vanish.
	 *
	 * Wired to the module's existing daily cron
	 * (Anchor_Compliance_Consent_Log::CRON_HOOK) by anchor-compliance.php,
	 * mirroring the consent log's own purge.
	 *
	 * @return int Rows deleted.
	 */
	public static function purge_expired() {
		$days = (int) Anchor_Compliance_Settings::get()['dsar']['retention_days'];
		if ( $days <= 0 ) {
			return 0;
		}

		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		return (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . self::table() . " WHERE status IN ('completed','rejected') AND created_at < %s",
				$cutoff
			)
		);
	}

	/** @return int UTC timestamp for a stored 'Y-m-d H:i:s' UTC datetime. */
	private static function utc_ts( $datetime ) {
		return (int) strtotime( (string) $datetime . ' +0000' );
	}

	/** @return string Stored UTC datetime rendered in the site timezone + format. */
	private static function format_datetime( $datetime ) {
		return get_date_from_gmt( (string) $datetime, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
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
	 * Every notice the front-end form can show, keyed by the code carried in
	 * the ?anchor_cmp= redirect parameter. create() builds its WP_Errors from
	 * this same map so the message a requester eventually sees is exactly the
	 * message the failure produced — the four errors used to collapse to one
	 * generic "check the form" string, which told a rate-limited genuine
	 * requester to do precisely the wrong thing.
	 */
	public static function notice_messages() {
		return [
			'ok'            => __( 'Thank you — your request has been received. We will respond within the required timeframe. Please check your email for a confirmation link to verify your request.', 'anchor-schema' ),
			'invalid_type'  => __( 'Please choose a valid request type.', 'anchor-schema' ),
			'invalid_email' => __( 'Please enter a valid email address.', 'anchor-schema' ),
			'rate_limited'  => __( 'A request from this email or device was already submitted recently. Please wait a while and try again.', 'anchor-schema' ),
			'db_error'      => __( 'Your request could not be saved. Please try again.', 'anchor-schema' ),
			'expired'       => __( 'This page had expired — please reload the page and resubmit your request.', 'anchor-schema' ),
			'disabled'      => __( 'Privacy requests cannot be submitted online right now. Please contact us directly to make a request about your personal data.', 'anchor-schema' ),
			'error'         => __( 'Sorry, we could not process your request. Please check the form and try again.', 'anchor-schema' ),
		];
	}

	/** @return WP_Error An error whose message matches its front-end notice. */
	private static function form_error( $code ) {
		return new WP_Error( $code, self::notice_messages()[ $code ] );
	}

	/**
	 * Create a privacy request.
	 *
	 * @param array $args type, email, name, details.
	 * @return int|WP_Error Insert ID, or WP_Error on validation/rate-limit failure.
	 */
	public function create( array $args ) {
		global $wpdb;

		// The intake handler is admin_post_nopriv — on a fresh deploy nothing
		// guarantees an admin page load (where the admin_init install hook
		// lives) happened first, so gate on the schema version here. This is
		// a single autoloaded-option compare on the happy path; dbDelta only
		// runs when the version actually moved.
		self::maybe_install();

		$type = isset( $args['type'] ) ? sanitize_key( (string) $args['type'] ) : '';
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return self::form_error( 'invalid_type' );
		}

		$email = isset( $args['email'] ) ? sanitize_email( (string) $args['email'] ) : '';
		if ( ! is_email( $email ) ) {
			return self::form_error( 'invalid_email' );
		}

		// Rate limit tier 1: a transient keyed by a salted hash of email+IP,
		// never the raw address itself. The key only ever lives in wp_options
		// for the 15-minute window and is never associated with the stored
		// request row.
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
		$raw_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		$rl_key = 'anchor_cmp_dsar_rl_' . hash( 'sha256', strtolower( $email ) . '|' . $raw_ip . wp_salt( 'auth' ) );
		if ( false !== get_transient( $rl_key ) ) {
			return self::form_error( 'rate_limited' );
		}

		// Rate limit tier 2: IP-only. The pair key above is trivially reset by
		// varying the email (plus-addressing, throwaway local parts), so an
		// attacker holding one IP must also fit under an hourly per-IP cap.
		// Skipped when REMOTE_ADDR is missing/invalid — there is nothing
		// stable to key on.
		$ip_limit  = (int) apply_filters( 'anchor_compliance_dsar_ip_hourly_limit', 5 );
		$ip_rl_key = '';
		if ( '' !== $raw_ip && filter_var( $raw_ip, FILTER_VALIDATE_IP ) ) {
			$ip_rl_key = 'anchor_cmp_dsar_rlip_' . hash( 'sha256', $raw_ip . '|' . wp_salt( 'auth' ) );
			if ( (int) get_transient( $ip_rl_key ) >= $ip_limit ) {
				return self::form_error( 'rate_limited' );
			}
		}

		// Rate limit tier 3: a global hourly cap, keyed on nothing the attacker
		// can vary. IP rotation defeats both tiers above; this bounds total DB
		// growth and wp_mail() volume no matter how distributed the abuse is.
		$global_limit = (int) apply_filters( 'anchor_compliance_dsar_global_hourly_limit', 100 );
		$global_key   = 'anchor_cmp_dsar_rl_global';
		if ( (int) get_transient( $global_key ) >= $global_limit ) {
			return self::form_error( 'rate_limited' );
		}

		$name    = isset( $args['name'] ) ? sanitize_text_field( (string) $args['name'] ) : '';
		$details = isset( $args['details'] ) ? wp_strip_all_tags( (string) $args['details'] ) : '';
		// Server-side cap regardless of what the form's maxlength attribute
		// claimed — a hand-rolled POST can send the full TEXT column's 64KB.
		$details = mb_substr( $details, 0, self::DETAILS_MAX_LENGTH );

		$response_days = (int) Anchor_Compliance_Settings::get()['dsar']['response_days'];
		$deadline      = gmdate( 'Y-m-d H:i:s', strtotime( "+{$response_days} days" ) );

		// Identity verification: only the salted hash of the single-use token
		// is stored; the raw token exists solely inside the confirmation email.
		// verified_at stays NULL until the link is clicked.
		$verify_token = wp_generate_password( 32, false, false );
		$verify_key   = hash( 'sha256', $verify_token . wp_salt( 'auth' ) );

		$ok = $wpdb->insert(
			self::table(),
			[
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
				'type'        => $type,
				'email'       => $email,
				'name'        => $name,
				'details'     => $details,
				'status'      => 'new',
				'deadline'    => $deadline,
				'ip_hash'     => self::hash_ip(),
				'notes'       => '',
				'verified_at' => null,
				'verify_key'  => $verify_key,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		if ( ! $ok ) {
			return self::form_error( 'db_error' );
		}

		$id = (int) $wpdb->insert_id;

		// Only start the rate-limit windows once the request is actually
		// stored, so a DB failure never blocks a genuine retry.
		set_transient( $rl_key, 1, 15 * MINUTE_IN_SECONDS );
		if ( '' !== $ip_rl_key ) {
			set_transient( $ip_rl_key, (int) get_transient( $ip_rl_key ) + 1, HOUR_IN_SECONDS );
		}
		set_transient( $global_key, (int) get_transient( $global_key ) + 1, HOUR_IN_SECONDS );

		$this->notify( $id, $type, $email, $deadline, $verify_token );

		return $id;
	}

	/**
	 * Notify the site owner and confirm receipt to the requester (including
	 * the verification link). Both sends are best-effort: a mail outage must
	 * never lose an already-persisted DSAR record, so wp_mail()'s return value
	 * is intentionally ignored on both calls rather than surfaced as part of
	 * create()'s result — the row simply stays Unverified in the queue.
	 */
	private function notify( $id, $type, $email, $deadline, $verify_token ) {
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
				__( "A new \"%1\$s\" privacy request was submitted by %2\$s.\nResponse deadline: %3\$s\nRequest ID: #%4\$d\n\nA confirmation link was emailed to the requester — the queue shows whether they have verified the address yet.", 'anchor-schema' ),
				$type,
				$email,
				$deadline,
				$id
			)
		);

		$verify_url = add_query_arg(
			[
				'action' => self::VERIFY_ACTION,
				'rid'    => $id,
				'token'  => $verify_token,
			],
			admin_url( 'admin-post.php' )
		);

		wp_mail(
			$email,
			__( 'We received your privacy request', 'anchor-schema' ),
			sprintf(
				/* translators: 1: request type, 2: statutory deadline, 3: verification URL */
				__( "Thanks — we received your \"%1\$s\" request and will respond by %2\$s.\n\nPlease confirm this request came from you by opening this link:\n%3\$s\n\nIf you did not make this request, you can safely ignore this email.", 'anchor-schema' ),
				$type,
				$deadline,
				$verify_url
			)
		);
	}

	/**
	 * Consume a verification token for a request.
	 *
	 * Pulled out of handle_verify() (which wp_die()s) for the same
	 * testability reason process_submission() exists apart from
	 * handle_submit().
	 *
	 * @param int    $id
	 * @param string $token The raw token from the emailed link.
	 * @return string 'verified' | 'already' | 'expired' | 'invalid'.
	 */
	public function verify( $id, $token ) {
		$row = $this->get( (int) $id );
		if ( ! $row ) {
			return 'invalid';
		}
		if ( ! empty( $row->verified_at ) ) {
			return 'already';
		}
		if ( ! is_string( $token ) || '' === $token || '' === (string) $row->verify_key ) {
			return 'invalid';
		}
		if ( ! hash_equals( (string) $row->verify_key, hash( 'sha256', $token . wp_salt( 'auth' ) ) ) ) {
			return 'invalid';
		}
		if ( self::utc_ts( $row->created_at ) + self::VERIFY_TTL_DAYS * DAY_IN_SECONDS < time() ) {
			return 'expired';
		}

		global $wpdb;
		$updated = $wpdb->update(
			self::table(),
			[
				'verified_at' => gmdate( 'Y-m-d H:i:s' ),
				'verify_key'  => '', // Single-use: the hash is gone the moment it succeeds.
			],
			[ 'id' => (int) $row->id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		return false === $updated ? 'invalid' : 'verified';
	}

	/**
	 * `admin_post_anchor_compliance_dsar_verify` /
	 * `admin_post_nopriv_anchor_compliance_dsar_verify` — the target of the
	 * emailed confirmation link.
	 */
	public function handle_verify() {
		$id    = isset( $_GET['rid'] ) ? (int) $_GET['rid'] : 0;
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

		$result = $this->verify( $id, $token );

		if ( 'verified' === $result || 'already' === $result ) {
			wp_die(
				esc_html__( 'Thank you — your email address is confirmed and your privacy request is now verified. We will respond within the required timeframe.', 'anchor-schema' ),
				esc_html__( 'Request verified', 'anchor-schema' ),
				[ 'response' => 200 ]
			);
		}

		wp_die(
			esc_html__( 'This verification link is invalid or has expired. If you recently submitted a privacy request, please submit it again to receive a fresh link.', 'anchor-schema' ),
			esc_html__( 'Verification failed', 'anchor-schema' ),
			[ 'response' => 403 ]
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

		// Both interpolated ORDER BY tokens come only from these whitelists —
		// arbitrary caller input never reaches the SQL string.
		$orderby_map = [
			'created'    => 'created_at',
			'created_at' => 'created_at',
			'deadline'   => 'deadline',
		];
		$orderby = $orderby_map[ (string) ( $args['orderby'] ?? '' ) ] ?? 'created_at';
		$order   = 'ASC' === strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) ? 'ASC' : 'DESC';

		list( $where_sql, $where_prep ) = $this->where( $args );

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . " WHERE {$where_sql} ORDER BY {$orderby} {$order}, id {$order} LIMIT %d OFFSET %d",
				array_merge( $where_prep, [ $limit, $offset ] )
			)
		);
	}

	/** @return int Total rows matching the same filter args query() takes. */
	public function count( array $args = [] ) {
		global $wpdb;

		list( $where_sql, $where_prep ) = $this->where( $args );
		$sql = 'SELECT COUNT(*) FROM ' . self::table() . " WHERE {$where_sql}";

		return (int) ( $where_prep
			? $wpdb->get_var( $wpdb->prepare( $sql, $where_prep ) )
			: $wpdb->get_var( $sql ) );
	}

	/** @return int Requests still awaiting action (status new/in_progress). */
	public function open_count() {
		self::maybe_install();
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE status IN ('new','in_progress')" );
	}

	/** @return int Open requests within $days of their deadline, or already past it. */
	public function due_soon_count( $days = 5 ) {
		self::maybe_install();
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() + max( 0, (int) $days ) * DAY_IN_SECONDS );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::table() . " WHERE status IN ('new','in_progress') AND deadline < %s",
				$cutoff
			)
		);
	}

	/**
	 * @return string 'updated'   — a row changed;
	 *                'unchanged' — the row already had that status;
	 *                'not_found' — no row with that id;
	 *                'invalid'   — $status isn't one of the four valid values;
	 *                'db_error'  — the UPDATE itself failed.
	 */
	public function set_status( $id, $status ) {
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return 'invalid';
		}

		global $wpdb;
		$result = $wpdb->update(
			self::table(),
			[ 'status' => $status ],
			[ 'id' => (int) $id ],
			[ '%s' ],
			[ '%d' ]
		);

		if ( false === $result ) {
			return 'db_error';
		}
		if ( $result > 0 ) {
			return 'updated';
		}

		// 0 rows: either the row doesn't exist or it already had this status —
		// $wpdb->update() cannot tell the two apart, so look.
		$row = $this->get( $id );
		if ( ! $row ) {
			return 'not_found';
		}
		return $row->status === $status ? 'unchanged' : 'db_error';
	}

	/** @return bool True unless the UPDATE itself failed. */
	public function set_notes( $id, $notes ) {
		global $wpdb;
		$result = $wpdb->update(
			self::table(),
			[ 'notes' => sanitize_textarea_field( (string) $notes ) ],
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

	private static function status_labels() {
		return [
			'new'         => __( 'New', 'anchor-schema' ),
			'in_progress' => __( 'In progress', 'anchor-schema' ),
			'completed'   => __( 'Completed', 'anchor-schema' ),
			'rejected'    => __( 'Rejected', 'anchor-schema' ),
		];
	}

	/** `[anchor_privacy_request]` */
	public function shortcode( $atts = [] ) {
		$opts = Anchor_Compliance_Settings::get();

		if ( empty( $opts['dsar']['enabled'] ) ) {
			return '<p class="anchor-cmp-dsar-disabled">' . esc_html( self::notice_messages()['disabled'] ) . '</p>';
		}

		$notice   = isset( $_GET['anchor_cmp'] ) ? sanitize_key( wp_unslash( $_GET['anchor_cmp'] ) ) : '';
		$messages = self::notice_messages();

		ob_start();

		if ( 'ok' === $notice ) {
			echo '<p class="anchor-cmp-dsar-notice anchor-cmp-dsar-success">' . esc_html( $messages['ok'] ) . '</p>';
		} elseif ( '' !== $notice ) {
			// Every failure code carries its own honest message ('expired' for
			// a stale cached nonce, 'rate_limited', ...); anything unknown
			// falls back to the generic string.
			$message = isset( $messages[ $notice ] ) ? $messages[ $notice ] : $messages['error'];
			echo '<p class="anchor-cmp-dsar-notice anchor-cmp-dsar-error">' . esc_html( $message ) . '</p>';
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
				<textarea id="anchor_cmp_details" name="anchor_cmp_details" rows="4" maxlength="<?php echo esc_attr( self::DETAILS_MAX_LENGTH ); ?>"></textarea>
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
	 * needs to decide the outcome code, minus the wp_safe_redirect()+exit that
	 * makes the real entry point unreachable from PHPUnit (see e.g.
	 * tests/test-event-manager-save.php for the same constraint elsewhere in
	 * this plugin: a raw exit() terminates the test process). Pulled out so
	 * the honeypot path — which must be provably indistinguishable from a
	 * real success and must provably create no row — can be exercised
	 * directly.
	 *
	 * @param array $post Superglobal-shaped, already wp_unslash()'d.
	 * @return string 'ok', or a failure code from notice_messages().
	 */
	public function process_submission( array $post ) {
		// The enabled toggle must gate the endpoint, not just the form's
		// visibility — a nonce harvested while intake was on stays valid for
		// up to ~24h after the site owner turns it off.
		$opts = Anchor_Compliance_Settings::get();
		if ( empty( $opts['dsar']['enabled'] ) ) {
			return 'disabled';
		}

		// A failed nonce on this public form is almost always a full-page
		// cache serving a stale nonce, not an attack — report it as its own
		// code so the requester is told to reload rather than left retrying
		// against a generic error forever.
		if ( ! isset( $post[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( $post[ self::NONCE_FIELD ], self::ACTION ) ) {
			return 'expired';
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

		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_code();
			return isset( self::notice_messages()[ $code ] ) ? $code : 'error';
		}

		return 'ok';
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
		$open  = $this->open_count();
		$label = __( 'Privacy Requests', 'anchor-schema' );
		$menu  = $label;
		if ( $open > 0 ) {
			// Same bubble markup core uses for pending comments/plugin updates.
			$menu .= sprintf(
				' <span class="awaiting-mod count-%1$d"><span class="pending-count">%2$s</span></span>',
				(int) $open,
				esc_html( number_format_i18n( $open ) )
			);
		}

		add_submenu_page(
			'options-general.php',
			$label,
			$menu,
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render_admin_page' ]
		);

		// admin_menu fires before admin_notices on every wp-admin request, and
		// register_menu is this class's only admin-wide entry point — hooking
		// the deadline warning here keeps the module bootstrap untouched.
		add_action( 'admin_notices', [ $this, 'admin_notice_deadlines' ] );
	}

	/**
	 * Site-wide admin warning whenever any open request is within 5 days of
	 * its statutory deadline (or past it). The one email at creation is
	 * best-effort and easily missed; this is the in-dashboard backstop.
	 */
	public function admin_notice_deadlines() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$due = $this->due_soon_count( 5 );
		if ( $due < 1 ) {
			return;
		}

		$url = admin_url( 'options-general.php?page=' . self::MENU_SLUG );

		echo '<div class="notice notice-warning"><p>';
		printf(
			/* translators: 1: number of requests, 2: opening link tag, 3: closing link tag */
			esc_html( _n(
				'%1$s open privacy request is within 5 days of its response deadline or already past it. %2$sReview the queue%3$s.',
				'%1$s open privacy requests are within 5 days of their response deadline or already past it. %2$sReview the queue%3$s.',
				$due,
				'anchor-schema'
			) ),
			esc_html( number_format_i18n( $due ) ),
			'<a href="' . esc_url( $url ) . '">',
			'</a>'
		);
		echo '</p></div>';
	}

	/**
	 * Handle the queue screen's two POST actions (status change, notes save)
	 * and echo an honest notice for each outcome — including the outcomes
	 * that changed nothing.
	 */
	private function handle_queue_actions() {
		if (
			isset( $_POST['anchor_cmp_dsar_id'], $_POST['anchor_cmp_dsar_status'], $_POST['anchor_cmp_dsar_status_nonce'] )
			&& wp_verify_nonce(
				wp_unslash( $_POST['anchor_cmp_dsar_status_nonce'] ),
				'anchor_cmp_dsar_status_' . (int) $_POST['anchor_cmp_dsar_id']
			)
		) {
			$result  = $this->set_status( (int) $_POST['anchor_cmp_dsar_id'], sanitize_key( wp_unslash( $_POST['anchor_cmp_dsar_status'] ) ) );
			$notices = [
				'updated'   => [ 'notice-success', __( 'Status updated.', 'anchor-schema' ) ],
				'unchanged' => [ 'notice-info', __( 'No change — the request already had that status.', 'anchor-schema' ) ],
				'not_found' => [ 'notice-error', __( 'That request no longer exists.', 'anchor-schema' ) ],
				'invalid'   => [ 'notice-error', __( 'That is not a valid status.', 'anchor-schema' ) ],
				'db_error'  => [ 'notice-error', __( 'The status could not be updated. Please try again.', 'anchor-schema' ) ],
			];
			if ( isset( $notices[ $result ] ) ) {
				echo '<div class="notice ' . esc_attr( $notices[ $result ][0] ) . ' is-dismissible"><p>' . esc_html( $notices[ $result ][1] ) . '</p></div>';
			}
		}

		if (
			isset( $_POST['anchor_cmp_dsar_notes_id'], $_POST['anchor_cmp_dsar_notes'], $_POST['anchor_cmp_dsar_notes_nonce'] )
			&& wp_verify_nonce(
				wp_unslash( $_POST['anchor_cmp_dsar_notes_nonce'] ),
				'anchor_cmp_dsar_notes_' . (int) $_POST['anchor_cmp_dsar_notes_id']
			)
		) {
			$saved = $this->set_notes( (int) $_POST['anchor_cmp_dsar_notes_id'], wp_unslash( $_POST['anchor_cmp_dsar_notes'] ) );
			echo $saved
				? '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Notes saved.', 'anchor-schema' ) . '</p></div>'
				: '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'The notes could not be saved. Please try again.', 'anchor-schema' ) . '</p></div>';
		}
	}

	/** @return string Sortable column header link, toggling asc/desc. */
	private function sort_header_link( $key, $label, $status_filter, $current_orderby, $current_order ) {
		$is_current = $current_orderby === $key;
		$next_order = ( $is_current && 'asc' === $current_order ) ? 'desc' : 'asc';

		$url = add_query_arg(
			array_filter( [
				'page'          => self::MENU_SLUG,
				'status_filter' => $status_filter,
				'orderby'       => $key,
				'order'         => $next_order,
			] ),
			admin_url( 'options-general.php' )
		);

		$arrow = $is_current ? ( 'asc' === $current_order ? ' &uarr;' : ' &darr;' ) : '';

		return '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . $arrow . '</a>';
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'anchor-schema' ) );
		}

		$this->handle_queue_actions();

		$status_filter = isset( $_GET['status_filter'] ) ? sanitize_key( wp_unslash( $_GET['status_filter'] ) ) : '';
		if ( ! in_array( $status_filter, self::STATUSES, true ) ) {
			$status_filter = '';
		}

		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'deadline';
		if ( ! in_array( $orderby, [ 'deadline', 'created' ], true ) ) {
			$orderby = 'deadline';
		}
		// Default deadline-ascending: the most overdue request is always row 1.
		$order = isset( $_GET['order'] ) && 'desc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ? 'desc' : 'asc';

		$paged    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$per_page = 50;

		$query_args = [
			'limit'   => $per_page,
			'offset'  => ( $paged - 1 ) * $per_page,
			'orderby' => $orderby,
			'order'   => $order,
		];
		$count_args = [];
		if ( '' !== $status_filter ) {
			$query_args['status'] = $status_filter;
			$count_args['status'] = $status_filter;
		}

		$rows        = $this->query( $query_args );
		$total       = $this->count( $count_args );
		$total_pages = (int) ceil( $total / $per_page );

		$base_url = add_query_arg(
			array_filter( [
				'page'          => self::MENU_SLUG,
				'status_filter' => $status_filter,
				'orderby'       => $orderby,
				'order'         => $order,
			] ),
			admin_url( 'options-general.php' )
		);

		$type_labels   = self::type_labels();
		$status_labels = self::status_labels();
		?>
		<div class="wrap anchor-cmp-dsar-queue">
			<h1><?php esc_html_e( 'Privacy Requests', 'anchor-schema' ); ?></h1>

			<form method="get" action="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
				<input type="hidden" name="orderby" value="<?php echo esc_attr( $orderby ); ?>" />
				<input type="hidden" name="order" value="<?php echo esc_attr( $order ); ?>" />
				<label for="anchor_cmp_dsar_status_filter"><?php esc_html_e( 'Status', 'anchor-schema' ); ?></label>
				<select id="anchor_cmp_dsar_status_filter" name="status_filter">
					<option value=""><?php esc_html_e( 'All statuses', 'anchor-schema' ); ?></option>
					<?php foreach ( $status_labels as $status => $label ) : ?>
						<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $status_filter, $status ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'anchor-schema' ); ?></button>
			</form>

			<p>
				<?php
				printf(
					/* translators: %s: total number of matching privacy requests */
					esc_html( _n( '%s request', '%s requests', $total, 'anchor-schema' ) ),
					esc_html( number_format_i18n( $total ) )
				);
				?>
			</p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php echo wp_kses_post( $this->sort_header_link( 'created', __( 'Submitted', 'anchor-schema' ), $status_filter, $orderby, $order ) ); ?></th>
						<th><?php esc_html_e( 'Type', 'anchor-schema' ); ?></th>
						<th><?php esc_html_e( 'Email', 'anchor-schema' ); ?></th>
						<th><?php esc_html_e( 'Name', 'anchor-schema' ); ?></th>
						<th><?php esc_html_e( 'Verified', 'anchor-schema' ); ?></th>
						<th><?php esc_html_e( 'Status', 'anchor-schema' ); ?></th>
						<th><?php echo wp_kses_post( $this->sort_header_link( 'deadline', __( 'Deadline', 'anchor-schema' ), $status_filter, $orderby, $order ) ); ?></th>
						<th><?php esc_html_e( 'Days remaining', 'anchor-schema' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="8"><?php esc_html_e( 'No privacy requests yet.', 'anchor-schema' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$days_remaining = (int) ceil( ( self::utc_ts( $row->deadline ) - time() ) / DAY_IN_SECONDS );
						$is_verified    = ! empty( $row->verified_at );
						?>
						<tr<?php echo $is_verified ? '' : ' style="background:#fcf9e8;"'; ?>>
							<td><?php echo esc_html( self::format_datetime( $row->created_at ) ); ?></td>
							<td><?php echo esc_html( isset( $type_labels[ $row->type ] ) ? $type_labels[ $row->type ] : $row->type ); ?></td>
							<td><?php echo esc_html( $row->email ); ?></td>
							<td><?php echo esc_html( $row->name ); ?></td>
							<td>
								<?php if ( $is_verified ) : ?>
									<span style="color:#008a20;font-weight:600;"><?php esc_html_e( 'Verified', 'anchor-schema' ); ?></span>
								<?php else : ?>
									<span style="color:#996800;font-weight:600;"><?php esc_html_e( 'Unverified', 'anchor-schema' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<form method="post">
									<?php wp_nonce_field( 'anchor_cmp_dsar_status_' . (int) $row->id, 'anchor_cmp_dsar_status_nonce' ); ?>
									<input type="hidden" name="anchor_cmp_dsar_id" value="<?php echo esc_attr( $row->id ); ?>" />
									<label class="screen-reader-text" for="anchor_cmp_dsar_status_<?php echo esc_attr( $row->id ); ?>"><?php esc_html_e( 'Status', 'anchor-schema' ); ?></label>
									<select id="anchor_cmp_dsar_status_<?php echo esc_attr( $row->id ); ?>" name="anchor_cmp_dsar_status">
										<?php foreach ( $status_labels as $status => $label ) : ?>
											<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $row->status, $status ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
									<button type="submit" class="button button-small"><?php esc_html_e( 'Apply', 'anchor-schema' ); ?></button>
								</form>
							</td>
							<td><?php echo esc_html( self::format_datetime( $row->deadline ) ); ?></td>
							<td<?php echo $days_remaining < 0 ? ' style="color:#b32d2e;font-weight:600;"' : ''; ?>>
								<?php echo esc_html( $days_remaining ); ?>
							</td>
						</tr>
						<tr class="anchor-cmp-dsar-details-row">
							<td colspan="8">
								<strong><?php esc_html_e( 'Details:', 'anchor-schema' ); ?></strong>
								<div style="white-space:pre-wrap;margin:4px 0 8px;"><?php echo '' !== trim( (string) $row->details ) ? esc_html( $row->details ) : esc_html__( '(none provided)', 'anchor-schema' ); ?></div>
								<form method="post">
									<?php wp_nonce_field( 'anchor_cmp_dsar_notes_' . (int) $row->id, 'anchor_cmp_dsar_notes_nonce' ); ?>
									<input type="hidden" name="anchor_cmp_dsar_notes_id" value="<?php echo esc_attr( $row->id ); ?>" />
									<label for="anchor_cmp_dsar_notes_<?php echo esc_attr( $row->id ); ?>"><strong><?php esc_html_e( 'Internal notes', 'anchor-schema' ); ?></strong></label><br />
									<textarea id="anchor_cmp_dsar_notes_<?php echo esc_attr( $row->id ); ?>" name="anchor_cmp_dsar_notes" rows="2" class="large-text"><?php echo esc_textarea( $row->notes ); ?></textarea>
									<button type="submit" class="button button-small"><?php esc_html_e( 'Save notes', 'anchor-schema' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
					<?php
					echo wp_kses_post( (string) paginate_links( [
						'base'    => add_query_arg( 'paged', '%#%', $base_url ),
						'format'  => '',
						'current' => $paged,
						'total'   => $total_pages,
					] ) );
					?>
				</div></div>
			<?php endif; ?>
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
	 * Eraser: delete this module's own DSAR rows for the requested email —
	 * except opt-out requests, which are anonymized in place instead. An
	 * honored do-not-sell/share request is itself compliance evidence: wiping
	 * the row would destroy the only record that the opt-out was received and
	 * acted on. The anonymized row keeps only type/status/dates; email, name,
	 * details, notes, ip_hash, and the verification key are all blanked, so
	 * nothing personal remains. Core handles confirming the requester owns
	 * the address before this ever runs; we only ever act on the email core
	 * hands us.
	 *
	 * @param string $email_address
	 * @param int    $page 1-based (unused — a single pass clears every
	 *                     matching row, so there is never a second page).
	 * @return array{items_removed:bool,items_retained:bool,messages:array,done:bool}
	 */
	public function privacy_erase( $email_address, $page = 1 ) {
		global $wpdb;

		$email = sanitize_email( $email_address );

		$deleted = (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . self::table() . " WHERE email = %s AND type != 'optout'",
				$email
			)
		);

		$anonymized = (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET email = '', name = '', details = '', notes = '', ip_hash = '', verify_key = '' WHERE email = %s AND type = 'optout'",
				$email
			)
		);

		$messages = [];
		if ( $anonymized > 0 ) {
			$messages[] = __( 'Opt-out (do-not-sell/share) requests were anonymized rather than deleted: the dated record that an opt-out was received and honored is retained as compliance evidence, with all personal information (email, name, details) removed from it.', 'anchor-schema' );
		}

		return [
			'items_removed'  => $deleted > 0 || $anonymized > 0,
			'items_retained' => $anonymized > 0,
			'messages'       => $messages,
			'done'           => true,
		];
	}
}
