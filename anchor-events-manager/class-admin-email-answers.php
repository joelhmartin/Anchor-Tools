<?php
/**
 * Attendee answers for the admin / staff registration emails.
 *
 * An event author can tick "Include in admin email" on an attendee question
 * (the `admin_email` flag on the question row, stored in QUESTIONS_META beside
 * `required`, so child dates inherit it exactly as they inherit the question).
 * This class is the one place that turns a seat's stored answers into the
 * `Question label: answer` lines those emails print, so the free-registration
 * notice (plain text) and WooCommerce's own "New order" admin email cannot
 * disagree about what is shown.
 *
 * Only ticked questions, only answered ones. Multi-choice answers are already
 * one ", "-joined string (including "Other: <text>") by the time they are
 * stored, so they pass through unchanged.
 *
 * @package Anchor\Events
 */

namespace Anchor\Events;

defined( 'ABSPATH' ) || exit;

class Admin_Email_Answers {

	/**
	 * `Label: answer` lines for one seat, in question order.
	 *
	 * Values are plain text (whitespace collapsed to one line). The HTML sender
	 * escapes them through the email shell; the plain-text sender needs no escape.
	 *
	 * @param Module $module
	 * @param int    $event_id Event the seat belongs to (a child date reads its own, inherited, questions).
	 * @param int    $seat_id
	 * @return array<int,array{label:string,answer:string}>
	 */
	public static function for_seat( Module $module, $event_id, $seat_id ) {
		$stored = \get_post_meta( (int) $seat_id, '_anchor_event_reg_fields', true );
		return self::for_answers( $module, (int) $event_id, \is_array( $stored ) ? $stored : [] );
	}

	/**
	 * @param Module $module
	 * @param int    $event_id
	 * @param array  $stored   Raw stored answers (keyed by question key or legacy label).
	 * @return array<int,array{label:string,answer:string}>
	 */
	public static function for_answers( Module $module, $event_id, array $stored ) {
		if ( empty( $stored ) ) {
			return [];
		}
		$questions = $module->get_registration_questions( (int) $event_id );
		$answers   = $module->resolve_registration_answers( (int) $event_id, $stored, $questions );
		$rows      = [];
		foreach ( $questions as $q ) {
			if ( empty( $q['admin_email'] ) || ! isset( $answers[ $q['key'] ] ) || ! \is_scalar( $answers[ $q['key'] ] ) ) {
				continue;
			}
			$answer = \trim( (string) \preg_replace( '/\s+/', ' ', (string) $answers[ $q['key'] ] ) );
			if ( $answer === '' ) {
				continue; // unanswered: never printed
			}
			$rows[] = [ 'label' => (string) $q['label'], 'answer' => $answer ];
		}
		return $rows;
	}

	/**
	 * The same rows as plain-text lines.
	 *
	 * @return string[]
	 */
	public static function text_lines( Module $module, $event_id, $seat_id ) {
		$lines = [];
		foreach ( self::for_seat( $module, $event_id, $seat_id ) as $row ) {
			$lines[] = $row['label'] . ': ' . $row['answer'];
		}
		return $lines;
	}

	/**
	 * Grouped per attendee, for an email that lists several (a WooCommerce
	 * order). Attendees with nothing to show are left out.
	 *
	 * @param Module                                     $module
	 * @param int                                        $event_id
	 * @param array<int,array{name?:string,fields?:array}> $attendees Stored answers per attendee.
	 * @return array<int,array{name:string,rows:array<int,array{label:string,answer:string}>}>
	 */
	public static function for_attendees( Module $module, $event_id, array $attendees ) {
		$out = [];
		foreach ( $attendees as $att ) {
			if ( ! \is_array( $att ) ) {
				continue;
			}
			$rows = self::for_answers( $module, (int) $event_id, isset( $att['fields'] ) && \is_array( $att['fields'] ) ? $att['fields'] : [] );
			if ( empty( $rows ) ) {
				continue;
			}
			$name  = (string) ( $att['name'] ?? '' );
			$out[] = [ 'name' => $name !== '' ? $name : \__( 'Guest', 'anchor-schema' ), 'rows' => $rows ];
		}
		return $out;
	}

	/**
	 * Print an order's answers into a WooCommerce email body, one heading per
	 * event and one block per attendee.
	 *
	 * Read from the line items' `_anchor_attendees` snapshot, not the seats:
	 * WooCommerce sends "New order" on the status transition, before the
	 * order's seats are created.
	 *
	 * @param Module    $module
	 * @param \WC_Order $order
	 * @param bool      $plain_text
	 */
	public static function render_order( Module $module, $order, $plain_text ) {
		$by_event = [];
		foreach ( $order->get_items() as $item ) {
			$event_id  = (int) $item->get_meta( '_anchor_event_id', true );
			$attendees = $item->get_meta( '_anchor_attendees', true );
			if ( $event_id <= 0 || ! \is_array( $attendees ) ) {
				continue;
			}
			$grouped = self::for_attendees( $module, $event_id, $attendees );
			if ( $grouped ) {
				$by_event[ $event_id ] = \array_merge( $by_event[ $event_id ] ?? [], $grouped );
			}
		}

		foreach ( $by_event as $event_id => $attendees ) {
			$title = \wp_strip_all_tags( \get_the_title( $event_id ) );
			if ( $plain_text ) {
				echo "\n" . $title . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- plain-text email.
				foreach ( $attendees as $att ) {
					echo "\n" . $att['name'] . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- plain-text email.
					foreach ( $att['rows'] as $row ) {
						echo $row['label'] . ': ' . $row['answer'] . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- plain-text email.
					}
				}
				continue;
			}
			echo '<h2>' . \esc_html( $title ) . '</h2>';
			foreach ( $attendees as $att ) {
				echo '<p style="margin:0 0 12px;"><strong>' . \esc_html( $att['name'] ) . '</strong>';
				foreach ( $att['rows'] as $row ) {
					echo '<br />' . \esc_html( $row['label'] ) . ': ' . \esc_html( $row['answer'] );
				}
				echo '</p>';
			}
		}
	}
}
