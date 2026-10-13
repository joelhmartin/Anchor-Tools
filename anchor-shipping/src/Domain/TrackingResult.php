<?php
declare(strict_types=1);

namespace Anchor\Shipping\Domain;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** A carrier's current word on one tracking number. */
final class TrackingResult {

	public const STATUSES = [ 'label_created', 'in_transit', 'out_for_delivery', 'delivered', 'exception', 'returned', 'unknown' ];

	/**
	 * @param string   $status   One of self::STATUSES.
	 * @param string   $detail   Carrier's human-readable status text (may be empty).
	 * @param int|null $event_at Unix time (UTC) of the latest carrier event, null when none.
	 */
	public function __construct(
		public string $tracking_number,
		public string $status,
		public string $detail,
		public ?int $event_at
	) {}
}
