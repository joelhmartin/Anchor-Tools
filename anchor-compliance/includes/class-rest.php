<?php
/**
 * Anchor Compliance — REST surface.
 *
 * The consent cookie is written client-side so full-page caches are never
 * involved in the consent decision; this endpoint exists only to author the
 * server-side audit record of that choice. It does not set cookies, does
 * not decide consent, and does not gate anything.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Anchor_Compliance_Rest {

	const NAMESPACE = 'anchor-compliance/v1';

	/** How long a repeat POST for the same consent_id is ignored. */
	const DEDUPE_WINDOW = MINUTE_IN_SECONDS;

	/** @var Anchor_Compliance_Consent_Log */
	private $log;

	/** @var Anchor_Compliance_Geo */
	private $geo;

	public function __construct( $log, $geo ) {
		$this->log = $log;
		$this->geo = $geo;
	}

	public function register_routes() {
		register_rest_route( self::NAMESPACE, '/consent', [
			'methods'             => 'POST',
			// Public and unauthenticated on purpose: anonymous visitors must be
			// able to record their own consent choice before they ever log in
			// to anything. This is safe to expose because the callback writes
			// only a hashed, write-only audit row and returns no data back —
			// there is nothing here for an anonymous caller to read or forge
			// on someone else's behalf.
			'permission_callback' => '__return_true',
			'callback'            => [ $this, 'handle_consent' ],
			'args'                => [
				'consent_id' => [
					'required'          => true,
					'type'              => 'string',
					'validate_callback' => static function ( $v ) {
						return (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', (string) $v );
					},
				],
				'categories' => [
					'required'          => true,
					'type'              => 'array',
					'validate_callback' => static function ( $v ) {
						if ( ! is_array( $v ) ) {
							return false;
						}
						foreach ( $v as $cat ) {
							if ( ! in_array( $cat, Anchor_Compliance_Consent_State::all_categories(), true ) ) {
								return false;
							}
						}
						return true;
					},
				],
				'method' => [
					'required'          => false,
					'type'              => 'string',
					'default'           => 'banner',
					// Single source of truth for the method vocabulary: the
					// log's VALID_METHODS, so the endpoint and the audit table
					// can never drift apart on what a recordable method is.
					'validate_callback' => static function ( $v ) {
						return in_array( $v, Anchor_Compliance_Consent_Log::VALID_METHODS, true );
					},
				],
				'policy_version' => [
					'required'          => false,
					'type'              => 'integer',
					// The policy version the client's payload/cookie was minted
					// under, so the audit row records the policy text the
					// visitor actually saw (settings may have moved since the
					// page rendered). Optional; the log falls back to current
					// settings when omitted.
					//
					// 0 is ACCEPTED, meaning "unknown": a page served from a
					// stale full-page cache can carry a payload whose
					// policyVersion the client normalized to 0, and rejecting
					// the whole POST for that killed the audit record of a
					// real consent choice. The log treats 0 exactly like an
					// absent value — it falls back to the current settings
					// version (see Anchor_Compliance_Consent_Log::record()).
					'validate_callback' => static function ( $v ) {
						return is_numeric( $v ) && (int) $v >= 0;
					},
				],
			],
		] );
	}

	public function handle_consent( WP_REST_Request $request ) {
		$consent_id = (string) $request->get_param( 'consent_id' );
		$categories = array_values( (array) $request->get_param( 'categories' ) );
		$method     = (string) $request->get_param( 'method' );

		/*
		 * Abuse resistance: this route is public and unauthenticated, so it
		 * can be POSTed to in a loop. The write itself is cheap (one hashed
		 * row, no outbound calls) and already bounded by the log's own
		 * retention/purge job, so an elaborate rate limiter isn't warranted
		 * here. The one case worth guarding against directly is a client
		 * (retry logic, a double-fired listener, a replayed request)
		 * resubmitting the SAME consent_id repeatedly — a legitimate client
		 * mints exactly one UUID per consent decision, so repeats of the
		 * same id carry no new information. A short transient debounces
		 * that: a repeat within the window is dropped before it reaches the
		 * DB or re-fires the changed action, while still answering 200 so
		 * the caller doesn't treat it as an error. This does not defend
		 * against a flood of *distinct* consent_ids (a determined attacker
		 * can always mint new UUIDs); accepting that risk is reasonable
		 * because each such row is a few bytes, carries no PII beyond a
		 * salted IP hash, and ages out under the existing retention policy —
		 * the same posture WordPress already takes with e.g. public comment
		 * or search endpoints.
		 */
		$dedupe_key = 'anchor_cmp_consent_' . md5( $consent_id );
		if ( false !== get_transient( $dedupe_key ) ) {
			return rest_ensure_response( [ 'ok' => true, 'logged' => false ] );
		}
		set_transient( $dedupe_key, 1, self::DEDUPE_WINDOW );

		/*
		 * Full geo ladder, same as the page render: the audit row must record
		 * the region/posture the visitor was actually SERVED, and a
		 * header-only resolution here contradicted the page whenever tier 2
		 * decided the served experience. The original reason for skipping the
		 * remote tier — an attacker rotating spoofed proxy headers to mint
		 * metered API calls — is closed inside the geo class itself now:
		 * client-IP headers only drive the tier-2 lookup under a declared
		 * trusted proxy (otherwise REMOTE_ADDR, which a POSTing attacker
		 * cannot rotate), and results are cached per /24 block. Called before
		 * posture() on purpose: country() memoizes its result, so the
		 * subsequent posture() reuses this same resolution instead of
		 * re-running the ladder.
		 */
		$region = (string) $this->geo->country();

		$record = [
			'consent_id' => $consent_id,
			'categories' => $categories,
			'region'     => $region,
			'posture'    => $this->geo->posture(),
			'method'     => $method,
		];
		if ( null !== $request->get_param( 'policy_version' ) ) {
			// A 0 ("unknown") flows through as-is; the log's record() treats
			// any non-positive value like an absent one and stamps the row
			// with the current settings version instead.
			$record['policy_version'] = (int) $request->get_param( 'policy_version' );
		}

		$logged = $this->log->record( $record );

		/**
		 * Fires after a consent choice reaches the server.
		 *
		 * @param string[] $categories
		 * @param string   $consent_id
		 */
		do_action( 'anchor_compliance_consent_changed', $categories, $consent_id );

		return rest_ensure_response( [ 'ok' => true, 'logged' => (bool) $logged ] );
	}
}
