<?php
/**
 * JSON row shapes of the anchor-library/v1 API (spec "Row shapes").
 *
 * Mapping only: which rows a user may see is Repository/Permissions' decision. Nothing personal leaves here, so
 * no uploader or creator ids and no owner ids; the Vimeo hash is folded into the embed URL, never sent alone.
 *
 * @package Anchor\Library
 */

namespace Anchor\Library\Rest;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Shapes {

	/** @param array{folders:int,files:int,videos:int,links:int} $counts */
	public static function folder( int $id, string $name, int $parent_id, array $counts ): array {
		return [ 'id' => $id, 'name' => $name, 'parent_id' => $parent_id, 'counts' => $counts ];
	}

	/** Counts of what a user can see directly inside a folder, from a Repository::children() result. */
	public static function counts( array $children ): array {
		return [
			'folders' => \count( $children['folders'] ),
			'files'   => \count( $children['files'] ),
			'videos'  => \count( $children['videos'] ),
			'links'   => \count( $children['links'] ),
		];
	}

	/** From Repository::shape_file(). Files are never edited after upload, so created_at is when they last changed. */
	public static function file( array $f ): array {
		return [
			'id'         => (int) $f['id'],
			'name'       => (string) $f['name'],
			'mime'       => (string) $f['mime'],
			'size'       => (int) $f['size'],
			'updated_at' => self::iso( (string) ( $f['createdAt'] ?? '' ) ),
		];
	}

	/** From Repository::shape_video(). */
	public static function video( array $v ): array {
		return [
			'id'            => (int) $v['id'],
			'title'         => (string) $v['title'],
			'thumbnail_url' => '' !== (string) ( $v['thumbnailUrl'] ?? '' ) ? (string) $v['thumbnailUrl'] : null,
			'embed_url'     => self::embed_url( (string) $v['vimeoId'], (string) ( $v['vimeoHash'] ?? '' ) ),
		];
	}

	/** From Repository::shape_link(). */
	public static function link( array $l ): array {
		return [ 'id' => (int) $l['id'], 'title' => (string) $l['title'], 'url' => (string) $l['url'] ];
	}

	public static function embed_url( string $vimeo_id, string $hash ): string {
		$url = 'https://player.vimeo.com/video/' . \rawurlencode( $vimeo_id );
		return '' === $hash ? $url : $url . '?h=' . \rawurlencode( $hash );
	}

	/** A stored site-local "Y-m-d H:i:s" as ISO 8601 with the site's offset; '' stays null. */
	public static function iso( string $mysql ): ?string {
		if ( '' === $mysql || 0 === \strpos( $mysql, '0000' ) ) {
			return null;
		}
		try {
			return ( new \DateTimeImmutable( $mysql, \wp_timezone() ) )->format( 'c' );
		} catch ( \Exception $e ) {
			return null;
		}
	}
}
