<?php
/**
 * Anchor Compliance — consent records.
 *
 * GDPR Art. 7(1) requires being able to demonstrate that consent was given.
 * We store enough to do that and nothing more: the IP is truncated and salted-
 * hashed before storage, and the raw value is never persisted.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Anchor_Compliance_Consent_Log {

	const DB_VERSION_OPTION = 'anchor_compliance_log_db_version';
	const DB_VERSION        = '1';
	const CRON_HOOK         = 'anchor_compliance_purge_log';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'anchor_consent_log';
	}

	public static function install() {
		global $wpdb;

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			consent_id CHAR(36) NOT NULL,
			created_at DATETIME NOT NULL,
			ip_hash CHAR(64) NOT NULL DEFAULT '',
			region VARCHAR(8) NOT NULL DEFAULT '',
			posture VARCHAR(16) NOT NULL DEFAULT '',
			categories TEXT NOT NULL,
			policy_version VARCHAR(16) NOT NULL DEFAULT '1',
			method VARCHAR(32) NOT NULL DEFAULT 'banner',
			ua_hash CHAR(64) NOT NULL DEFAULT '',
			PRIMARY KEY (id),
			KEY consent_id (consent_id),
			KEY created_at (created_at),
			KEY method (method)
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
	 * @param array $args consent_id, categories (string[]), region, posture, method
	 * @return int|false Insert ID, or false when logging is off or the insert failed.
	 */
	public function record( array $args ) {
		global $wpdb;

		$opts = Anchor_Compliance_Settings::get();
		if ( empty( $opts['log']['enabled'] ) ) {
			return false;
		}

		$ok = $wpdb->insert(
			self::table(),
			[
				'consent_id'     => substr( (string) ( $args['consent_id'] ?? '' ), 0, 36 ),
				'created_at'     => gmdate( 'Y-m-d H:i:s' ),
				'ip_hash'        => self::hash_ip(),
				'region'         => substr( (string) ( $args['region'] ?? '' ), 0, 8 ),
				'posture'        => substr( (string) ( $args['posture'] ?? '' ), 0, 16 ),
				'categories'     => wp_json_encode( array_values( (array) ( $args['categories'] ?? [] ) ) ),
				'policy_version' => (string) $opts['general']['policy_version'],
				'method'         => substr( sanitize_key( (string) ( $args['method'] ?? 'banner' ) ), 0, 32 ),
				'ua_hash'        => self::hash_ua(),
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * The last octet (v4) or host identifier (v6) is dropped before hashing,
	 * so the stored digest cannot be brute-forced back to a single household.
	 *
	 * IPv6 truncation goes through the binary form (inet_pton/inet_ntop)
	 * rather than exploding the address on ':' — most real-world IPv6
	 * addresses are written in compressed form (e.g. "2001:db8::1"), where
	 * the elided run collapses to a single empty token. String-splitting on
	 * ':' then grabs far fewer than 4 real hextets and bakes the host's
	 * interface identifier straight into the "truncated" value, defeating
	 * the truncation silently (no PHP warning). This is the same bug and
	 * fix as Anchor_Compliance_Geo::ip_block().
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
				// Malformed despite passing FILTER_VALIDATE_IP — never let it
				// fall through to hashing the raw, untruncated value.
				return '';
			}
			// Zero the low 8 bytes (the /64 host identifier), keep the network prefix.
			$network   = substr( $bin, 0, 8 ) . str_repeat( "\0", 8 );
			$truncated = inet_ntop( $network );
		}

		return hash( 'sha256', $truncated . wp_salt( 'auth' ) );
	}

	private static function hash_ua() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
		return '' === $ua ? '' : hash( 'sha256', $ua . wp_salt( 'auth' ) );
	}

	private function where( array $args ) {
		global $wpdb;
		$where = [ '1=1' ];
		$prep  = [];

		if ( ! empty( $args['method'] ) ) {
			$where[] = 'method = %s';
			$prep[]  = sanitize_key( $args['method'] );
		}
		if ( ! empty( $args['region'] ) ) {
			$where[] = 'region = %s';
			$prep[]  = strtoupper( sanitize_text_field( $args['region'] ) );
		}
		if ( ! empty( $args['since'] ) ) {
			$where[] = 'created_at >= %s';
			$prep[]  = gmdate( 'Y-m-d H:i:s', strtotime( $args['since'] ) );
		}

		$sql = implode( ' AND ', $where );
		// prepare() with an empty args array triggers a PHP notice on some WP
		// versions, so only call it when there are actual parameters.
		return $prep ? $wpdb->prepare( $sql, $prep ) : $sql;
	}

	public function query( array $args = [] ) {
		global $wpdb;
		$limit  = min( 500, max( 1, (int) ( $args['limit'] ?? 50 ) ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$where  = $this->where( $args );

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . " WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);
	}

	public function count( array $args = [] ) {
		global $wpdb;
		$where = $this->where( $args );
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE {$where}" );
	}

	/** @return int Rows deleted. */
	public function purge() {
		global $wpdb;
		$days = (int) Anchor_Compliance_Settings::get()['log']['retention_days'];
		return (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . self::table() . ' WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)',
				$days
			)
		);
	}

	/**
	 * Streams every row as CSV, then exits.
	 *
	 * This method does NOT check capabilities itself — it is the caller's
	 * responsibility (the admin viewer added in Task 15) to verify the
	 * current user may export consent records before invoking this.
	 */
	public function export_csv() {
		global $wpdb;

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=anchor-consent-log-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, [ 'consent_id', 'created_at_utc', 'region', 'posture', 'categories', 'policy_version', 'method', 'ip_hash' ] );

		$offset = 0;
		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY id ASC LIMIT %d OFFSET %d', 1000, $offset )
			);
			foreach ( $rows as $r ) {
				fputcsv( $out, [ $r->consent_id, $r->created_at, $r->region, $r->posture, $r->categories, $r->policy_version, $r->method, $r->ip_hash ] );
			}
			$offset += 1000;
		} while ( count( $rows ) === 1000 );

		fclose( $out );
		exit;
	}
}
