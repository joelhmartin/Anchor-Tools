<?php
declare(strict_types=1);

namespace Anchor\Shipping\Domain;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** A carrier's current word on one tracking number. */
final class TrackingResult {

	public const STATUSES = [ 'label_created', 'in_transit', 'out_for_delivery', 'delivered', 'exception', 'returned', 'voided', 'unknown', 'unknown_type' ];

	/**
	 * @param string   $status   One of self::STATUSES. 'unknown' = the carrier has no such package (yet);
	 *                           'unknown_type' = it answered with a status we do not map; 'voided' = the carrier says the label was voided.
	 * @param string   $detail   Carrier's human-readable status text (may be empty).
	 * @param int|null $event_at Unix time (UTC) of the latest carrier event, null when none.
	 * @param string   $expected_date Carrier's scheduled (or rescheduled) delivery date, Y-m-d; '' when it gives none.
	 * @param string   $location      Where the latest event happened, e.g. "Nashville, TN"; '' when unknown.
	 */
	public function __construct(
		public string $tracking_number,
		public string $status,
		public string $detail,
		public ?int $event_at,
		public string $expected_date = '',
		public string $location = ''
	) {}
}
