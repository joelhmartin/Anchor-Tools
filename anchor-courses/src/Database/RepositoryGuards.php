<?php
declare(strict_types=1);

namespace Anchor\Courses\Database;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Shared write-path helpers for the learner-table repositories.
 *
 * SQL shapes and table() stay in each repository; what lives here is the part
 * that must behave identically everywhere - most importantly that a PHP null
 * reaches MySQL as SQL NULL, never as '' (which wpdb's relaxed sql_mode turns
 * into the '0000-00-00 00:00:00' zero-date on a DATETIME column).
 */
trait RepositoryGuards {

	/**
	 * A prepared single-row INSERT whose null values are written as a literal
	 * NULL; ints go through %d and everything else through %s.
	 *
	 * @param array<string,mixed> $values       Column => value, in column order.
	 * @param bool                $ignore       INSERT IGNORE (unique key as the concurrency guard).
	 * @param string              $on_duplicate Raw ON DUPLICATE KEY UPDATE assignment list, or ''.
	 *                                          Must hold no caller data - it is not prepared.
	 */
	protected static function insert_sql( string $table, array $values, bool $ignore = false, string $on_duplicate = '' ): string {
		global $wpdb;

		$placeholders = [];
		$params       = [];
		foreach ( $values as $value ) {
			if ( null === $value ) {
				$placeholders[] = 'NULL';
				continue;
			}
			$placeholders[] = \is_int( $value ) ? '%d' : '%s';
			$params[]       = $value;
		}

		$sql = 'INSERT ' . ( $ignore ? 'IGNORE ' : '' ) . "INTO {$table} (" . \implode( ', ', \array_keys( $values ) ) . ')'
			. ' VALUES (' . \implode( ', ', $placeholders ) . ')';
		if ( '' !== $on_duplicate ) {
			$sql .= ' ON DUPLICATE KEY UPDATE ' . $on_duplicate;
		}

		return [] === $params ? $sql : (string) $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
}
