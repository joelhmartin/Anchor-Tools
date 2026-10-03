<?php
declare(strict_types=1);

namespace Anchor\Agreements\Database;

use Anchor\Agreements\Content\AgreementPostType;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Lazy, immutable snapshots: a version is created the first time a given
 * title+content is signed. Editing a document never rewrites what anyone signed.
 */
final class VersionRepository {

	public function current_for( int $agreement_id ): ?array {
		global $wpdb;
		if ( ! AgreementPostType::is_usable( $agreement_id ) ) {
			return null;
		}
		$post    = \get_post( $agreement_id );
		$title   = (string) $post->post_title;
		$content = (string) $post->post_content;
		$hash    = hash( 'sha256', $title . "\n" . $content );
		$table   = Migrations::table( 'versions' );

		$wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$table} (agreement_id, content_hash, title, content, created_at) VALUES (%d, %s, %s, %s, %s)",
			$agreement_id, $hash, $title, $content, \current_time( 'mysql', true )
		) );
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE agreement_id = %d AND content_hash = %s", $agreement_id, $hash
		), ARRAY_A );
		return $row ? self::shape( $row ) : null;
	}

	public function get( int $version_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Migrations::table( 'versions' ) . ' WHERE id = %d', $version_id ), ARRAY_A );
		return $row ? self::shape( $row ) : null;
	}

	private static function shape( array $row ): array {
		return [
			'id'           => (int) $row['id'],
			'agreement_id' => (int) $row['agreement_id'],
			'title'        => (string) $row['title'],
			'content'      => (string) $row['content'],
			'created_at'   => (string) $row['created_at'],
		];
	}
}
