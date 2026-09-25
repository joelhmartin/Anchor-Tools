<?php
declare(strict_types=1);

namespace Anchor\Courses\Admin;

use Anchor\Courses\Content\CoursePostType;
use Anchor\Courses\Database\EnrollmentRepository;
use Anchor\Courses\Services\ProgressService;
use Anchor\Courses\Support\Accounts;
use Anchor\Courses\Support\Capabilities;
use Anchor\Courses\Support\Roles;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * The Learners tab (design spec 3.1) - a metabox, because the course edit
 * screen has metaboxes and not tabs (deviation D16).
 *
 * Who is in, how far they have got, and the two controls staff need: add
 * somebody by name and email, or take their access away. Both go through
 * Support\Roles, so this screen is a caller of the one enrolment door rather
 * than a second one. Task 31 adds the credits and certificate columns.
 *
 * The table prints learner email addresses, so it is gated on the REPORTS
 * capability, not on edit_posts; the two write handlers need
 * manage_anchor_enrollments.
 */
final class LearnerReports {

	public const NONCE = 'anchor_courses_learners';

	public function __construct() {
		\add_action( 'add_meta_boxes', [ $this, 'add_metabox' ] );
		\add_action( 'admin_post_anchor_courses_add_learner', [ $this, 'handle_add_learner' ] );
		\add_action( 'admin_post_anchor_courses_revoke_access', [ $this, 'handle_revoke' ] );
	}

	public function add_metabox(): void {
		\add_meta_box(
			'anchor_courses_learners',
			\__( 'Learners', 'anchor-schema' ),
			[ $this, 'render_learners' ],
			CoursePostType::CPT,
			'normal',
			'low'
		);
	}

	/**
	 * One row per enrolled learner.
	 *
	 * `has_access` is read from the role, not the row, and the two can
	 * legitimately disagree: the default loss policy keeps an enrolment after
	 * its role goes, so "enrolled, no access" is a real and visible state.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function rows( int $course_id, int $limit = 50, int $offset = 0 ): array {
		$progress = new ProgressService();
		$slug     = Roles::access_slug( $course_id );
		$rows     = [];

		foreach ( EnrollmentRepository::for_course( $course_id, [], $limit, $offset ) as $enrollment ) {
			$user = \get_userdata( $enrollment->user_id );

			$rows[] = [
				'user_id'      => $enrollment->user_id,
				'display_name' => $user ? (string) $user->display_name : '',
				'user_email'   => $user ? (string) $user->user_email : '',
				'enrolled_at'  => $enrollment->enrolled_at,
				'percent'      => $progress->get_course_progress( $enrollment->user_id, $course_id )->percent,
				'status'       => $enrollment->status,
				'completed_at' => (string) ( $enrollment->completed_at ?? '' ),
				'has_access'   => Roles::user_has( $enrollment->user_id, $slug ),
			];
		}

		return $rows;
	}

	public function render_learners( \WP_Post $post ): void {
		if ( ! Capabilities::current_user_can( 'reports' ) ) {
			echo '<p>' . \esc_html__( 'You do not have permission to view learner reports.', 'anchor-schema' ) . '</p>';
			return;
		}

		$course_id = (int) $post->ID;
		$rows      = self::rows( $course_id );
		$may_write = Capabilities::current_user_can( 'enrollments' );

		if ( [] === $rows ) {
			echo '<p>' . \esc_html__( 'Nobody is enrolled yet.', 'anchor-schema' ) . '</p>';
		} else {
			echo '<table class="widefat striped anchor-courses-report"><thead><tr>';
			foreach ( [
				\__( 'Learner', 'anchor-schema' ),
				\__( 'Email', 'anchor-schema' ),
				\__( 'Enrolled', 'anchor-schema' ),
				\__( 'Progress', 'anchor-schema' ),
				\__( 'Status', 'anchor-schema' ),
				\__( 'Completed', 'anchor-schema' ),
				\__( 'Access', 'anchor-schema' ),
			] as $heading ) {
				echo '<th>' . \esc_html( $heading ) . '</th>';
			}
			echo '</tr></thead><tbody>';

			foreach ( $rows as $row ) {
				echo '<tr>';
				\printf( '<td>%s</td>', \esc_html( (string) $row['display_name'] ) );
				\printf( '<td>%s</td>', \esc_html( (string) $row['user_email'] ) );
				\printf( '<td>%s</td>', \esc_html( \mysql2date( 'Y-m-d', (string) $row['enrolled_at'] ) ) );
				\printf(
					'<td class="progress"><div class="anchor-courses-bar"><span style="width:%1$s%%"></span></div>%1$s%%</td>',
					\esc_html( \number_format_i18n( (float) $row['percent'], 0 ) )
				);
				\printf( '<td>%s</td>', \esc_html( (string) $row['status'] ) );
				\printf( '<td>%s</td>', \esc_html( '' === $row['completed_at'] ? '-' : \mysql2date( 'Y-m-d', (string) $row['completed_at'] ) ) );

				echo '<td>';
				if ( $row['has_access'] && $may_write ) {
					$this->revoke_button( $course_id, (int) $row['user_id'] );
				} else {
					echo \esc_html( $row['has_access'] ? \__( 'Yes', 'anchor-schema' ) : \__( 'No', 'anchor-schema' ) );
				}
				echo '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		if ( $may_write ) {
			$this->render_add_learner_form( $course_id );
		}
	}

	public function render_add_learner_form( int $course_id ): void {
		\printf(
			'<h4>%s</h4><form method="post" action="%s" class="anchor-courses-add-learner">',
			\esc_html__( 'Add a learner', 'anchor-schema' ),
			\esc_url( \admin_url( 'admin-post.php' ) )
		);
		\wp_nonce_field( self::NONCE . '_' . $course_id );
		echo '<input type="hidden" name="action" value="anchor_courses_add_learner" />';
		\printf( '<input type="hidden" name="course_id" value="%d" />', $course_id );
		\printf(
			'<p><input type="text" name="learner_name" placeholder="%s" /> '
			. '<input type="email" name="learner_email" placeholder="%s" required /> '
			. '<button type="submit" class="button">%s</button></p>',
			\esc_attr__( 'Name', 'anchor-schema' ),
			\esc_attr__( 'Email', 'anchor-schema' ),
			\esc_html__( 'Add learner', 'anchor-schema' )
		);
		echo '<p class="description">' . \esc_html__( 'Creates the account if there is none. No email is sent.', 'anchor-schema' ) . '</p>';
		echo '</form>';
	}

	public function revoke_button( int $course_id, int $user_id ): void {
		\printf( '<form method="post" action="%s">', \esc_url( \admin_url( 'admin-post.php' ) ) );
		\wp_nonce_field( self::NONCE . '_' . $course_id );
		echo '<input type="hidden" name="action" value="anchor_courses_revoke_access" />';
		\printf( '<input type="hidden" name="course_id" value="%d" />', $course_id );
		\printf( '<input type="hidden" name="user_id" value="%d" />', $user_id );
		\printf( '<button type="submit" class="button-link">%s</button>', \esc_html__( 'Revoke', 'anchor-schema' ) );
		echo '</form>';
	}

	public function handle_add_learner(): void {
		$course_id = $this->authorise();

		$name  = \sanitize_text_field( \wp_unslash( (string) ( $_POST['learner_name'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$email = \sanitize_email( \wp_unslash( (string) ( $_POST['learner_email'] ?? '' ) ) );     // phpcs:ignore WordPress.Security.NonceVerification

		$user_id = Accounts::ensure_user( $name, $email );
		if ( $user_id <= 0 ) {
			$this->redirect( $course_id, 'no_user' );
		}

		// The one door. grant_access() checks can_enroll() first, so an unmet
		// prerequisite refuses here exactly as it does at the checkout.
		$result = Roles::grant_access( $user_id, $course_id, 'manual', (string) \get_current_user_id() );

		$this->redirect(
			$course_id,
			\is_wp_error( $result ) ? (string) $result->get_error_code() : 'learner_added'
		);
	}

	public function handle_revoke(): void {
		$course_id = $this->authorise();
		$user_id   = \absint( $_POST['user_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( $user_id <= 0 ) {
			$this->redirect( $course_id, 'no_user' );
		}

		Roles::revoke_access( $user_id, $course_id, 'manual', (string) \get_current_user_id() );

		$this->redirect( $course_id, 'access_revoked' );
	}

	/** Nonce + capability, or redirect and stop. @return int The course id. */
	private function authorise(): int {
		$course_id = \absint( $_POST['course_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification

		$nonce = \sanitize_text_field( \wp_unslash( (string) ( $_REQUEST['_wpnonce'] ?? '' ) ) );
		if ( ! \wp_verify_nonce( $nonce, self::NONCE . '_' . $course_id ) ) {
			$this->redirect( $course_id, 'bad_nonce' );
		}
		if ( ! Capabilities::current_user_can( 'enrollments' ) ) {
			$this->redirect( $course_id, 'forbidden' );
		}

		return $course_id;
	}

	private function redirect( int $course_id, string $code ): void {
		$target = $course_id > 0 ? (string) \get_edit_post_link( $course_id, 'raw' ) : \admin_url();

		\wp_safe_redirect( \add_query_arg( 'anchor_courses_admin_notice', \sanitize_key( $code ), $target ) );
		exit;
	}
}
