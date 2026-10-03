<?php
declare(strict_types=1);

namespace Anchor\Agreements\Database;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class SignatureRepository {

	private const LIST_COLS = 'id, token, version_id, agreement_id, order_id, product_id, user_id, signer_name, signer_email, method, font, ip, user_agent, signed_at, attached_at';

	private function t(): string {
		return Migrations::table( 'signatures' );
	}

	public function insert( array $d ): int {
		global $wpdb;
		$wpdb->insert( $this->t(), [
			'token'        => \wp_generate_uuid4(),
			'version_id'   => (int) $d['version_id'],
			'agreement_id' => (int) $d['agreement_id'],
			'product_id'   => (int) ( $d['product_id'] ?? 0 ),
			'user_id'      => $d['user_id'] ? (int) $d['user_id'] : null,
			'signer_name'  => (string) $d['signer_name'],
			'signer_email' => (string) ( $d['signer_email'] ?? '' ),
			'method'       => (string) $d['method'],
			'font'         => $d['font'] ?? null,
			'image'        => (string) $d['image'],
			'ip'           => (string) ( $d['ip'] ?? '' ),
			'user_agent'   => substr( (string) ( $d['user_agent'] ?? '' ), 0, 255 ),
			'session_key'  => (string) ( $d['session_key'] ?? '' ),
			'signed_at'    => \current_time( 'mysql', true ),
		] );
		return (int) $wpdb->insert_id;
	}

	public function get( int $id ): ?array {
		global $wpdb;
		return self::shape( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->t()} WHERE id = %d", $id ), ARRAY_A ) );
	}

	public function find_by_token( string $token ): ?array {
		global $wpdb;
		if ( ! preg_match( '/^[0-9a-f-]{36}$/', $token ) ) {
			return null;
		}
		return self::shape( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->t()} WHERE token = %s", $token ), ARRAY_A ) );
	}

	public function attach( int $id, int $order_id ): bool {
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$this->t()} SET order_id = %d, attached_at = COALESCE(attached_at, %s) WHERE id = %d AND (order_id IS NULL OR order_id = %d)",
			$order_id, \current_time( 'mysql', true ), $id, $order_id
		) );
		$row = $this->get( $id );
		return $row && $row['order_id'] === $order_id;
	}

	public function for_order( int $order_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->t()} WHERE order_id = %d ORDER BY id", $order_id ), ARRAY_A );
		return array_map( [ self::class, 'shape' ], $rows ?: [] );
	}

	public function for_signer( int $user_id, string $email ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT ' . self::LIST_COLS . " FROM {$this->t()} WHERE order_id IS NOT NULL AND (user_id = %d OR (signer_email <> '' AND signer_email = %s)) ORDER BY signed_at DESC",
			$user_id, $email
		), ARRAY_A );
		return array_map( [ self::class, 'shape' ], $rows ?: [] );
	}

	public function count_recent_for_session( string $session_key, int $seconds ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->t()} WHERE session_key = %s AND signed_at >= %s",
			$session_key, gmdate( 'Y-m-d H:i:s', time() - $seconds )
		) );
	}

	public function purge_unattached( int $older_than_seconds ): int {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$this->t()} WHERE order_id IS NULL AND signed_at < %s",
			gmdate( 'Y-m-d H:i:s', time() - $older_than_seconds )
		) );
	}

	public function search( array $args ): array {
		global $wpdb;
		$per   = max( 1, (int) ( $args['per_page'] ?? 20 ) );
		$page  = max( 1, (int) ( $args['paged'] ?? 1 ) );
		$where = [ '1=1' ];
		$vals  = [];
		if ( ! empty( $args['s'] ) ) {
			$like    = '%' . $wpdb->esc_like( (string) $args['s'] ) . '%';
			$where[] = '(signer_name LIKE %s OR signer_email LIKE %s OR order_id = %d)';
			array_push( $vals, $like, $like, (int) $args['s'] );
		}
		if ( ! empty( $args['agreement_id'] ) ) {
			$where[] = 'agreement_id = %d';
			$vals[]  = (int) $args['agreement_id'];
		}
		$w     = implode( ' AND ', $where );
		$total = (int) $wpdb->get_var( $vals ? $wpdb->prepare( "SELECT COUNT(*) FROM {$this->t()} WHERE {$w}", $vals ) : "SELECT COUNT(*) FROM {$this->t()} WHERE {$w}" );
		$sql   = 'SELECT ' . self::LIST_COLS . " FROM {$this->t()} WHERE {$w} ORDER BY id DESC LIMIT %d OFFSET %d";
		$rows  = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $vals, [ $per, ( $page - 1 ) * $per ] ) ), ARRAY_A );
		return [ 'rows' => array_map( [ self::class, 'shape' ], $rows ?: [] ), 'total' => $total ];
	}

	private static function shape( ?array $row ): ?array {
		if ( ! $row ) {
			return null;
		}
		foreach ( [ 'id', 'version_id', 'agreement_id', 'product_id' ] as $k ) {
			if ( isset( $row[ $k ] ) ) {
				$row[ $k ] = (int) $row[ $k ];
			}
		}
		foreach ( [ 'order_id', 'user_id' ] as $k ) {
			if ( array_key_exists( $k, $row ) ) {
				$row[ $k ] = null === $row[ $k ] ? null : (int) $row[ $k ];
			}
		}
		return $row;
	}
}
