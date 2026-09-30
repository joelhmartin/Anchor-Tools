<?php
declare(strict_types=1);

namespace Anchor\Announcements\Audience\Conditions;

use Anchor\Announcements\Audience\Condition;
use Anchor\Announcements\Audience\RecipientSet;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class SpecificPeople implements Condition {
	public function key(): string { return 'specific_people'; }
	public function label(): string { return \__( 'Specific people', 'anchor-schema' ); }
	public function group(): string { return \__( 'Site users', 'anchor-schema' ); }
	public function available(): bool { return true; }
	public function fields(): array {
		return [
			[ 'key' => 'users', 'type' => 'search', 'search' => 'users', 'label' => \__( 'Users', 'anchor-schema' ) ],
			[ 'key' => 'emails', 'type' => 'textarea', 'label' => \__( 'Email addresses (comma or one per line)', 'anchor-schema' ) ],
		];
	}
	/** At least one user id or one valid email, else the condition is dropped. */
	public function complete( array $params ): bool {
		if ( \array_filter( \array_map( 'absint', (array) ( $params['users'] ?? [] ) ) ) ) {
			return true;
		}
		foreach ( \preg_split( '/[\s,;]+/', (string) ( $params['emails'] ?? '' ) ) as $email ) {
			if ( \is_email( \sanitize_email( $email ) ) ) {
				return true;
			}
		}
		return false;
	}
	public function match( array $params ): RecipientSet {
		$set = new RecipientSet();
		foreach ( \array_filter( \array_map( 'absint', (array) ( $params['users'] ?? [] ) ) ) as $id ) {
			$u = \get_userdata( $id );
			if ( $u ) {
				$set->add( (string) $u->user_email, (int) $u->ID, (string) $u->display_name );
			}
		}
		foreach ( \preg_split( '/[\s,;]+/', (string) ( $params['emails'] ?? '' ) ) as $email ) {
			$user = \get_user_by( 'email', $email );
			$user ? $set->add( (string) $user->user_email, (int) $user->ID, (string) $user->display_name ) : $set->add( (string) $email );
		}
		return $set;
	}
}
