<?php
/**
 * Attendee answers for the admin / staff registration emails.
 *
 * An event author can tick "Include in admin email" on an attendee question
 * (the `admin_email` flag on the question row, stored in QUESTIONS_META beside
 * `required`, so child dates inherit it exactly as they inherit the question).
 * This class is the one place that turns a seat's stored answers into the
 * `Question label: answer` lines those emails print, so the free-registration
 * notice (plain text) and the WooCommerce organizer notice (HTML detail rows)
 * cannot disagree about what is shown.
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
	 * Rows for the HTML email's detail table: a heading row naming the
	 * attendee, then one `Label: answer` row per line. The text sits in the
	 * label cell (left aligned, wraps) with an empty value cell.
	 *
	 * @param Module                        $module
	 * @param int                           $event_id
	 * @param array<int,array{id:int,name:string}> $seats
	 * @return array<int,array{label:string,value:string}>
	 */
	public static function detail_rows( Module $module, $event_id, array $seats ) {
		$out = [];
		foreach ( $seats as $seat ) {
			$lines = self::for_seat( $module, $event_id, (int) $seat['id'] );
			if ( empty( $lines ) ) {
				continue;
			}
			$name  = ( $seat['name'] ?? '' ) !== '' ? (string) $seat['name'] : \__( 'Guest', 'anchor-schema' );
			$out[] = [ 'label' => $name, 'value' => '' ];
			foreach ( $lines as $row ) {
				$out[] = [ 'label' => $row['label'] . ': ' . $row['answer'], 'value' => '' ];
			}
		}
		return $out;
	}
}
