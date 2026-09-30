<?php
declare(strict_types=1);

namespace Anchor\Announcements\Audience\Conditions;

use Anchor\Announcements\Audience\Condition;
use Anchor\Announcements\Audience\RecipientSet;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class CourseCompleted implements Condition {
	public function key(): string { return 'course_completed'; }
	public function label(): string { return \__( 'Completed a course', 'anchor-schema' ); }
	public function group(): string { return \__( 'Courses', 'anchor-schema' ); }
	public function available(): bool { return \class_exists( '\Anchor\Courses\Module' ); }
	public function fields(): array {
		return [
			[ 'key' => 'courses', 'type' => 'search', 'search' => 'courses', 'label' => \__( 'Any of these courses (empty: any course)', 'anchor-schema' ) ],
			[ 'key' => 'from', 'type' => 'date', 'label' => \__( 'Completed from', 'anchor-schema' ) ],
			[ 'key' => 'to', 'type' => 'date', 'label' => \__( 'Completed to', 'anchor-schema' ) ],
		];
	}
	public function match( array $params ): RecipientSet {
		return CourseEnrolled::query( [ 'completed' ], 'completed_at', $params );
	}
}
