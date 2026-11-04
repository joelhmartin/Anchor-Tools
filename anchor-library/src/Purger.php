<?php
/**
 * Library clean-up for rows that point at a file or folder id: permission policies and the WooCommerce product
 * document references. Both are keyed by id only, so deleting the file or folder without calling this leaves orphans
 * that re-attach to whatever row is given that id next.
 *
 * @package Anchor\Library
 */

namespace Anchor\Library;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Purger {

	/** Remove permission_policies rows for entities of one type. @param int[] $ids */
	public static function delete_policies( string $entity_type, array $ids ): bool {
		global $wpdb;
		$ids = \array_values( \array_filter( \array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return true;
		}
		$ph  = \implode( ',', \array_fill( 0, \count( $ids ), '%d' ) );
		$sql = 'DELETE FROM ' . Schema::table( 'permission_policies' ) . " WHERE entity_type = %s AND entity_id IN ({$ph})";
		return false !== $wpdb->query( \call_user_func_array( [ $wpdb, 'prepare' ], \array_merge( [ $sql, $entity_type ], $ids ) ) );
	}

	/** Drop deleted files from every product's document list (D6). @param int[] $file_ids */
	public static function strip_product_docs( array $file_ids ): void {
		global $wpdb;
		$gone = \array_flip( \array_map( 'intval', $file_ids ) );
		if ( ! $gone ) {
			return;
		}
		$post_ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", Permissions::META_PRODUCT_DOCS ) );
		foreach ( (array) $post_ids as $post_id ) {
			$docs = \get_post_meta( (int) $post_id, Permissions::META_PRODUCT_DOCS, true );
			if ( ! \is_array( $docs ) ) {
				continue;
			}
			$kept = \array_values( \array_filter( $docs, static function ( $doc ) use ( $gone ) {
				return ! ( \is_array( $doc ) && isset( $doc['fileId'] ) && isset( $gone[ (int) $doc['fileId'] ] ) );
			} ) );
			if ( \count( $kept ) === \count( $docs ) ) {
				continue;
			}
			if ( $kept ) {
				\update_post_meta( (int) $post_id, Permissions::META_PRODUCT_DOCS, $kept );
			} else {
				\delete_post_meta( (int) $post_id, Permissions::META_PRODUCT_DOCS );
			}
		}
	}

	/** Remove a folder's directory including the .htaccess/index.php guards Storage writes. Never touches other content. */
	public static function remove_folder_dir( int $folder_id ): void {
		$dir = \trailingslashit( Storage::base_dir() ) . $folder_id;
		if ( ! \is_dir( $dir ) ) {
			return;
		}
		foreach ( [ '.htaccess', 'index.php' ] as $guard ) {
			if ( \is_file( $dir . '/' . $guard ) ) {
				@\unlink( $dir . '/' . $guard );
			}
		}
		@\rmdir( $dir );
	}
}
