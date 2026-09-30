<?php
declare(strict_types=1);

namespace Anchor\Announcements\Audience\Conditions;

use Anchor\Announcements\Audience\Condition;
use Anchor\Announcements\Audience\RecipientSet;
use Anchor\Announcements\Support\Dates;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class CourseEnrolled implements Condition {
	public function key(): string { return 'course_enrolled'; }
	public function label(): string { return \__( 'Enrolled in a course', 'anchor-schema' ); }
	public function group(): string { return \__( 'Courses', 'anchor-schema' ); }
	public function available(): bool { return \class_exists( '\Anchor\Courses\Module' ); }
	public function fields(): array {
		return [
			[ 'key' => 'courses', 'type' => 'search', 'search' => 'courses', 'label' => \__( 'Any of these courses (empty: any course)', 'anchor-schema' ) ],
			[ 'key' => 'status', 'type' => 'select', 'label' => \__( 'Enrolment', 'anchor-schema' ), 'options' => [ 'active' => 'active', 'completed' => 'completed', 'any' => 'any status' ], 'default' => 'active' ],
			[ 'key' => 'from', 'type' => 'date', 'label' => \__( 'Enrolled from', 'anchor-schema' ) ],
			[ 'key' => 'to', 'type' => 'date', 'label' => \__( 'Enrolled to', 'anchor-schema' ) ],
		];
	}
	public function match( array $params ): RecipientSet {
		$statuses = [
			'active'    => [ 'enrolled', 'in_progress' ],
			'completed' => [ 'completed' ],
			'any'       => [ 'enrolled', 'in_progress', 'completed', 'expired', 'cancelled' ],
		][ (string) ( $params['status'] ?? 'active' ) ] ?? [ 'enrolled', 'in_progress' ];
		return self::query( $statuses, 'enrolled_at', $params );
	}

	/** Shared with CourseCompleted. */
	public static function query( array $statuses, string $date_col, array $params ): RecipientSet {
		global $wpdb;
		$table         = \Anchor\Courses\Database\Migrations::table( 'enrollments' );
		[ $from, $to ] = Dates::gmt_range( (string) ( $params['from'] ?? '' ), (string) ( $params['to'] ?? '' ) );
		$courses       = \array_values( \array_filter( \array_map( 'absint', (array) ( $params['courses'] ?? [] ) ) ) );
		$date_col      = 'completed_at' === $date_col ? 'completed_at' : 'enrolled_at';

		$sql  = "SELECT DISTINCT e.user_id FROM {$table} e WHERE e.status IN (" . \implode( ',', \array_fill( 0, \count( $statuses ), '%s' ) ) . ')';
		$args = $statuses;
		if ( $courses ) { $sql .= ' AND e.course_id IN (' . \implode( ',', \array_fill( 0, \count( $courses ), '%d' ) ) . ')'; $args = \array_merge( $args, $courses ); }
		if ( $from ) { $sql .= " AND e.{$date_col} >= %s"; $args[] = $from; }
		if ( $to ) { $sql .= " AND e.{$date_col} <= %s"; $args[] = $to; }

		$set = new RecipientSet();
		foreach ( $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) as $user_id ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders only.
			$u = \get_userdata( (int) $user_id );
			if ( $u ) {
				$set->add( (string) $u->user_email, (int) $u->ID, (string) $u->display_name );
			}
		}
		return $set;
	}
}
