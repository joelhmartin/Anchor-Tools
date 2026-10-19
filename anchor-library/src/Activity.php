<?php
/**
 * Library activity log (the plugin's log_activity), one row per notable action.
 *
 * @package Anchor\Library
 */

namespace Anchor\Library;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Activity {

	/**
	 * @param string   $action        Verb, sanitized with sanitize_key (e.g. 'upload', 'delete').
	 * @param string   $entity_type   'folder' | 'file' | 'link' | 'video'.
	 * @param int      $entity_id     Entity id.
	 * @param array    $meta          Extra context, stored as JSON when non-empty.
	 * @param int|null $actor_user_id Acting user; null means the current user (0 when signed out).
	 */
	public static function log( string $action, string $entity_type, int $entity_id, array $meta = [], ?int $actor_user_id = null ): void {
		global $wpdb;
		$actor = null === $actor_user_id ? (int) \get_current_user_id() : $actor_user_id;
		$wpdb->insert( Schema::table( 'activity' ), [
			'actor_user_id' => $actor ?: 0,
			'action'        => \sanitize_key( $action ),
			'entity_type'   => \sanitize_key( $entity_type ),
			'entity_id'     => $entity_id,
			'meta'          => $meta ? \wp_json_encode( $meta ) : null,
			'created_at'    => \current_time( 'mysql' ),
		] );
	}
}
