<?php
/**
 * Anchor_Questions_Email: one renderer for question answers in WooCommerce's
 * "New order" admin email, shared by every module that asks questions.
 *
 * The caller resolves stored answers for its own storage, then passes
 * questions + answers to rows_for() and groups of rows to render_order_groups().
 *
 * @package Anchor_Tools
 */

defined( 'ABSPATH' ) || exit;

class Anchor_Questions_Email {

	/**
	 * Flagged (`admin_email`), answered rows only, in question order. Answers are
	 * whitespace-collapsed to one line; callers pass already-resolved answers.
	 *
	 * @param array $questions Normalized question rows.
	 * @param array $stored    Resolved answers keyed by question key.
	 * @return array<int,array{label:string,answer:string}>
	 */
	public static function rows_for( array $questions, array $stored ) {
		$rows = [];
		foreach ( $questions as $q ) {
			if ( empty( $q['admin_email'] ) || ! isset( $stored[ $q['key'] ] ) || ! is_scalar( $stored[ $q['key'] ] ) ) {
				continue;
			}
			$answer = trim( (string) preg_replace( '/\s+/', ' ', (string) $stored[ $q['key'] ] ) );
			if ( $answer === '' ) {
				continue; // unanswered: never printed
			}
			$rows[] = [ 'label' => (string) $q['label'], 'answer' => $answer ];
		}
		return $rows;
	}

	/**
	 * @param array<int,array{heading:string,entries:array<int,array{name:?string,rows:array}>}> $groups
	 * @param bool $plain_text
	 * @return string Markup for the email hook to echo (HTML escaped; plain text raw).
	 */
	public static function render_order_groups( array $groups, $plain_text ) {
		$out = '';
		foreach ( $groups as $group ) {
			$out .= $plain_text ? "\n" . $group['heading'] . "\n" : '<h2>' . esc_html( $group['heading'] ) . '</h2>';
			foreach ( $group['entries'] as $entry ) {
				$named = isset( $entry['name'] ) && $entry['name'] !== null;
				if ( $plain_text ) {
					$out .= $named ? "\n" . $entry['name'] . "\n" : '';
					foreach ( $entry['rows'] as $row ) {
						$out .= $row['label'] . ': ' . $row['answer'] . "\n";
					}
					continue;
				}
				$out .= '<p style="margin:0 0 12px;">';
				if ( $named ) {
					$out .= '<strong>' . esc_html( $entry['name'] ) . '</strong>';
				}
				foreach ( array_values( $entry['rows'] ) as $i => $row ) {
					$out .= ( $named || $i > 0 ? '<br />' : '' ) . esc_html( $row['label'] ) . ': ' . esc_html( $row['answer'] );
				}
				$out .= '</p>';
			}
		}
		return $out;
	}
}
