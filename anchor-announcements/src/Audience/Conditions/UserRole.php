<?php
declare(strict_types=1);

namespace Anchor\Announcements\Audience\Conditions;

use Anchor\Announcements\Audience\Condition;
use Anchor\Announcements\Audience\RecipientSet;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class UserRole implements Condition {
	public function key(): string { return 'user_role'; }
	public function label(): string { return \__( 'User role', 'anchor-schema' ); }
	public function group(): string { return \__( 'Site users', 'anchor-schema' ); }
	public function available(): bool { return true; }
	public function fields(): array {
		$roles = [];
		foreach ( \wp_roles()->roles as $slug => $role ) {
			$roles[ $slug ] = \translate_user_role( $role['name'] );
		}
		return [ [ 'key' => 'roles', 'type' => 'multiselect', 'label' => \__( 'Has any of these roles', 'anchor-schema' ), 'options' => $roles ] ];
	}
	/** Params keys, any one of which must be non-empty or the condition is dropped. */
	public function required(): array { return [ 'roles' ]; }
	public function match( array $params ): RecipientSet {
		$set   = new RecipientSet();
		$roles = \array_values( \array_filter( \array_map( 'sanitize_key', (array) ( $params['roles'] ?? [] ) ) ) );
		if ( ! $roles ) {
			return $set;
		}
		foreach ( \get_users( [ 'role__in' => $roles, 'fields' => [ 'ID', 'user_email', 'display_name' ] ] ) as $u ) {
			$set->add( (string) $u->user_email, (int) $u->ID, (string) $u->display_name );
		}
		return $set;
	}
}
