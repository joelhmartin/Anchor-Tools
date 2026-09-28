<?php
declare(strict_types=1);

namespace Anchor\Courses\Support;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Queue arbitrary payloads against a WordPress user id, in a transient, so
 * they survive the post-redirect-get every `admin-post.php` action in this
 * plugin performs, and can be flushed exactly once on whichever front-end
 * page the user actually lands on next (Task 35).
 *
 * Deliberately generic: it knows nothing about analytics, `dataLayer`, or
 * courses - it is "queue N payloads for this user, read them back once".
 * `Integrations\Analytics` is the only consumer today (grepped the whole
 * plugin for an existing dataLayer/GTM queue first - see the Task 35 report;
 * there isn't one). Only `Anchor\Courses\` is PSR-4 autoloaded
 * (composer.json's `autoload.psr-4`), so if the events module
 * (`anchor-events-manager`, classic `require_once`-loaded classes) ever wants
 * the same "queue it, print it once on the next page" pattern for its own
 * dataLayer pushes, it should depend on THIS class - Composer's autoloader is
 * registered plugin-wide, so `Anchor\Courses\Support\UserEventQueue` resolves
 * regardless of which module's bootstrap ran - rather than writing a second
 * transient queue. If that dependency ever feels wrong for two
 * independently-togglable modules, promote this one class to a shared
 * `Anchor\Support\` namespace both modules autoload; don't duplicate it.
 */
final class UserEventQueue {

	/**
	 * @param string $transient_prefix Transient key prefix; the user id is appended.
	 * @param int    $ttl_seconds      How long a queued batch survives unflushed.
	 * @param int    $max_entries      Oldest entries are dropped past this cap.
	 */
	public function __construct(
		private readonly string $transient_prefix,
		private readonly int $ttl_seconds = 300,
		private readonly int $max_entries = 50
	) {}

	/** Append one payload to $user_id's queue, capped at max_entries (oldest dropped first). */
	public function push( int $user_id, array $payload ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		$queued   = $this->pending( $user_id );
		$queued[] = $payload;

		if ( \count( $queued ) > $this->max_entries ) {
			$queued = \array_slice( $queued, -$this->max_entries );
		}

		\set_transient( $this->key( $user_id ), $queued, $this->ttl_seconds );
	}

	/** @return array<int,array<string,mixed>> */
	public function pending( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return [];
		}

		$queued = \get_transient( $this->key( $user_id ) );
		return \is_array( $queued ) ? $queued : [];
	}

	/** Read and clear. @return array<int,array<string,mixed>> */
	public function flush( int $user_id ): array {
		$queued = $this->pending( $user_id );
		if ( $user_id > 0 ) {
			\delete_transient( $this->key( $user_id ) );
		}
		return $queued;
	}

	private function key( int $user_id ): string {
		return $this->transient_prefix . $user_id;
	}
}
