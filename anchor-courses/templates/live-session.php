<?php
/**
 * A live_session lesson (design spec 3.3).
 *
 * This page shows the SCHEDULE and a link to the event room. It deliberately
 * does NOT embed the stream: the events module's room is the only player,
 * and duplicating it here would mean two places to get entitlement wrong.
 *
 * Variables: $lesson_id, $course_id, $event_id, $available (bool - the
 * events module can resolve this event), $sessions (array - each row also
 * carries 'timezone', the zone name Events::sessions() resolved it in),
 * $room_url
 * (string - '' unless both the event resolves AND the current learner is
 * is_enrolled() in $course_id), $state (array), $item_available (bool - the
 * same ProgressService::is_item_available() authority the plain lesson
 * template gates its "Mark complete" form on), $complete (bool),
 * $prework_notice (string - '' unless the pre-work veto,
 * Integrations\Events::prework_block(), would refuse this learner the room;
 * an escaped HTML fragment from Events::prework_notice() otherwise).
 *
 * The body goes through the_content exactly like templates/lesson.php:
 * Frontend\ContentGuard hooks that filter and substitutes the access notice
 * for anyone Access::can_view_lesson() refuses, so this template never has
 * to re-decide who sees the real content.
 *
 * Theme override: anchor-courses/live-session.php
 *
 * @package Anchor\Courses
 */

use Anchor\Courses\Frontend\Actions;

if ( ! defined( 'ABSPATH' ) ) { exit; }

$notice = Actions::notice();
?>
<div class="anchor-lesson anchor-live-session" data-lesson="<?php echo esc_attr( (string) $lesson_id ); ?>" data-event="<?php echo esc_attr( (string) $event_id ); ?>">

	<?php if ( '' !== $notice ) : ?>
		<p class="anchor-courses-notice"><?php echo esc_html( Actions::notice_text( $notice ) ); ?></p>
	<?php endif; ?>

	<nav class="anchor-lesson-breadcrumb">
		<a href="<?php echo esc_url( (string) get_permalink( $course_id ) ); ?>"><?php echo esc_html( get_the_title( $course_id ) ); ?></a>
		<span class="anchor-lesson-breadcrumb-sep">/</span>
		<span><?php echo esc_html( get_the_title( $lesson_id ) ); ?></span>
	</nav>

	<h2 class="anchor-live-session-title"><?php echo esc_html( get_the_title( $lesson_id ) ); ?></h2>

	<div class="anchor-lesson-content"><?php echo apply_filters( 'the_content', get_post_field( 'post_content', $lesson_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- the_content output, gated by ContentGuard. ?></div>

	<?php if ( ! $available ) : ?>

		<p class="anchor-courses-notice"><?php esc_html_e( 'Live session unavailable.', 'anchor-schema' ); ?></p>

	<?php else : ?>

		<?php if ( ! empty( $sessions ) ) : ?>
			<table class="anchor-live-session-schedule">
				<caption><?php esc_html_e( 'Schedule', 'anchor-schema' ); ?></caption>
				<tbody>
				<?php foreach ( $sessions as $session ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( '' === $session['label'] ? $session['date'] : $session['label'] ); ?></th>
						<td>
							<?php
							// The event's own zone (Events::sessions()' 'timezone', the
							// same Module::event_timezone() the room renders with), not
							// the site's - this is the defect the class Events.php fixes:
							// a bare wp_date() with no third argument reads the SITE zone
							// and can show a different clock time than the room for the
							// same instant when timezone_mode=event.
							$session_tz = null;
							if ( '' !== ( $session['timezone'] ?? '' ) ) {
								try {
									$session_tz = new DateTimeZone( $session['timezone'] );
								} catch ( Exception $e ) {
									$session_tz = null; // Falls back to wp_date()'s own site-zone default below.
								}
							}
							echo esc_html(
								$session['start_ts'] > 0
									? wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), $session['start_ts'], $session_tz )
									: trim( $session['date'] . ' ' . $session['start_time'] )
							);
							?>
						</td>
						<td class="anchor-live-session-modality"><?php echo esc_html( $session['modality'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( '' !== $room_url && '' !== ( $prework_notice ?? '' ) ) : ?>
			<p class="anchor-courses-notice anchor-live-session-prework"><?php echo wp_kses_post( $prework_notice ); ?></p>
		<?php elseif ( '' !== $room_url ) : ?>
			<p class="anchor-live-session-join">
				<a class="anchor-courses-button" href="<?php echo esc_url( $room_url ); ?>">
					<?php esc_html_e( 'Join the livestream', 'anchor-schema' ); ?>
				</a>
			</p>
		<?php else : ?>
			<p class="anchor-courses-notice"><?php esc_html_e( 'The livestream link will appear here before the session starts.', 'anchor-schema' ); ?></p>
		<?php endif; ?>

		<?php if ( 'unknown' !== ( $state['state'] ?? 'unknown' ) ) : ?>
			<p class="anchor-live-session-state" data-state="<?php echo esc_attr( (string) $state['state'] ); ?>">
				<?php echo esc_html( (string) $state['state'] ); ?>
			</p>
		<?php endif; ?>

	<?php endif; ?>

	<?php if ( $item_available ) : ?>
		<?php if ( ! $complete ) : ?>
			<form class="anchor-lesson-complete" method="post" action="<?php echo esc_url( Actions::complete_url( $course_id, $lesson_id ) ); ?>">
				<?php wp_nonce_field( Actions::NONCE_COMPLETE . '_' . $lesson_id ); ?>
				<input type="hidden" name="course_id" value="<?php echo esc_attr( (string) $course_id ); ?>" />
				<input type="hidden" name="lesson_id" value="<?php echo esc_attr( (string) $lesson_id ); ?>" />
				<input type="hidden" name="_redirect" value="<?php echo esc_url( (string) get_permalink( $lesson_id ) ); ?>" />
				<button type="submit" class="anchor-courses-button"><?php esc_html_e( 'Mark attended', 'anchor-schema' ); ?></button>
			</form>
		<?php else : ?>
			<p class="anchor-lesson-done"><?php esc_html_e( 'Completed', 'anchor-schema' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>

	<?php echo do_shortcode( '[anchor_course_progress course_id="' . (int) $course_id . '"]' ); ?>
</div>
