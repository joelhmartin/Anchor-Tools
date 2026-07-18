<?php
/**
 * Anchor Locations — Phase 8: Hardening (data-integrity + cache versioning).
 *
 * Two responsibilities, both defensive and both non-destructive:
 *
 *   A/B. Data-integrity NUDGES. The plugin owner populates pages via external
 *        tooling (WP-CLI / import / direct DB), so this never blocks a save,
 *        never rewrites a slug, and never mutates content — it only WARNS:
 *          - a published location whose slug collides with another published
 *            location (both would collapse to the same /services/…/{slug}/ URL,
 *            and find_service_page() then resolves one arbitrarily),
 *          - a location missing coordinates (invisible on the map),
 *          - a service page that is orphaned (no valid published linked location)
 *            or duplicates an existing (service term + location) combination.
 *        Surfaced as dismissible `notice notice-warning`s on the edit screen and
 *        a ⚠ marker in a Locations list "Health" column. The detection itself is
 *        a set of pure predicates so it can be unit-tested headless.
 *
 *   C.   Cache VERSIONING. A single monotonic integer in an option
 *        (`anchor_locations_cache_ver`, autoload=false) is bumped on every write
 *        that could change the relationship graph (post save/trash/delete, term
 *        edit, object-term assignment). Module::map_data() and the directory
 *        shortcode fold that version into their transient keys, so a bump
 *        invalidates every cached entry at once without ever enumerating keys.
 *        When the option is absent the version reads 0 and callers bypass the
 *        cache cleanly (identical behavior to pre-Phase-8).
 *
 * Kept in its own class to keep anchor-locations.php lean; instantiated from
 * Module::__construct.
 *
 * @package Anchor\Locations
 */
namespace Anchor\Locations;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Integrity {

	/** Monotonic cache-version option. Bumped on any relationship-graph write. */
	const CACHE_VER_OPTION = 'anchor_locations_cache_ver';

	/**
	 * When true, the per-write bumps triggered by save_post / meta / term hooks
	 * early-return. IO sets this for the duration of a REAL import (hundreds of
	 * writes in one request) and force-bumps exactly ONCE at the end via
	 * bump_now(), so the map/directory cache invalidates once instead of N times.
	 */
	public static $suspend_bumps = false;

	public function __construct() {
		// A/B — data-integrity nudges (admin only).
		\add_action( 'admin_notices', [ $this, 'edit_screen_notices' ] );
		\add_filter( 'manage_' . Module::CPT_LOCATION . '_posts_columns', [ $this, 'add_health_column' ] );
		\add_action( 'manage_' . Module::CPT_LOCATION . '_posts_custom_column', [ $this, 'render_health_column' ], 10, 2 );

		// C — cache-version invalidation hooks. Scope save_post to our two CPTs
		// (the *_{cpt} variant never fires for revisions or other post types);
		// guard the generic delete/trash + term hooks by type/taxonomy.
		\add_action( 'save_post_' . Module::CPT_LOCATION, [ __CLASS__, 'bump_cache_version' ] );
		\add_action( 'save_post_' . Module::CPT_SERVICE, [ __CLASS__, 'bump_cache_version' ] );
		\add_action( 'deleted_post', [ $this, 'on_deleted_post' ], 10, 1 );
		\add_action( 'trashed_post', [ $this, 'on_deleted_post' ], 10, 1 );
		\add_action( 'edited_term', [ $this, 'on_edited_term' ], 10, 3 );
		\add_action( 'delete_term', [ $this, 'on_edited_term' ], 10, 3 );
		\add_action( 'set_object_terms', [ $this, 'on_set_object_terms' ], 10, 4 );

		// Bare meta writes (WP-CLI `wp post meta update`, direct import) don't fire
		// save_post, so hook the meta CRUD actions and bump when an al_* key changes
		// on one of our CPTs.
		\add_action( 'added_post_meta', [ $this, 'on_changed_post_meta' ], 10, 4 );
		\add_action( 'updated_post_meta', [ $this, 'on_changed_post_meta' ], 10, 4 );
		\add_action( 'deleted_post_meta', [ $this, 'on_changed_post_meta' ], 10, 4 );

		// The settings default marker icon is baked into cached map_data, so a
		// settings change must invalidate too.
		\add_action( 'update_option_' . Module::OPTION, [ __CLASS__, 'bump_cache_version' ] );
		\add_action( 'add_option_' . Module::OPTION, [ __CLASS__, 'bump_cache_version' ] );
	}

	/* ---- A. Slug-uniqueness (pure) ---- */

	/**
	 * The ID of another PUBLISHED location sharing $post_id's post_name, or 0.
	 *
	 * A collision matters because inbound routing (find_service_page) resolves a
	 * location by post_name with posts_per_page=1 — two published locations with
	 * the same slug map to one /services/…/{slug}/ URL and the loser is
	 * unreachable. Drafts, the post itself, and empty slugs never count.
	 */
	public function location_slug_collision( int $post_id ): int {
		$post = \get_post( $post_id );
		if ( ! $post || $post->post_type !== Module::CPT_LOCATION ) { return 0; }
		$slug = (string) $post->post_name;
		if ( $slug === '' ) { return 0; }

		$others = \get_posts( [
			'post_type'      => Module::CPT_LOCATION,
			'post_status'    => 'publish',
			'name'           => $slug,
			'post__not_in'   => [ $post_id ],
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );
		return empty( $others ) ? 0 : (int) $others[0];
	}

	/* ---- B. Data-quality predicates (pure) ---- */

	/** A location with no coordinates never renders a map marker. */
	public function location_missing_coords( int $post_id ): bool {
		$lat = \trim( (string) \get_post_meta( $post_id, 'al_lat', true ) );
		$lng = \trim( (string) \get_post_meta( $post_id, 'al_lng', true ) );
		return $lat === '' || $lng === '';
	}

	/**
	 * True when a service page's al_location_id is missing, non-numeric-zero, or
	 * points at something that is not a published anchor_location — i.e. it can't
	 * route to a live URL.
	 */
	public function service_orphan( int $post_id ): bool {
		$loc_id = (int) \get_post_meta( $post_id, 'al_location_id', true );
		if ( $loc_id <= 0 ) { return true; }
		$loc = \get_post( $loc_id );
		return ! $loc || $loc->post_type !== Module::CPT_LOCATION || $loc->post_status !== 'publish';
	}

	/**
	 * The ID of another PUBLISHED service page covering the same (service term +
	 * location) as $post_id, or 0. Mirrors Dashboard::seo_issues()'s duplicate
	 * detection but scoped to a single post for the per-page edit-screen notice.
	 */
	public function service_duplicate_combo( int $post_id ): int {
		$loc_id = (int) \get_post_meta( $post_id, 'al_location_id', true );
		if ( $loc_id <= 0 ) { return 0; }
		$tids = \wp_get_object_terms( $post_id, Module::TAX_SERVICE, [ 'fields' => 'ids' ] );
		if ( \is_wp_error( $tids ) || empty( $tids ) ) { return 0; }

		$others = \get_posts( [
			'post_type'      => Module::CPT_SERVICE,
			'post_status'    => 'publish',
			'post__not_in'   => [ $post_id ],
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => [ [ 'key' => 'al_location_id', 'value' => $loc_id ] ],
			'tax_query'      => [ [ 'taxonomy' => Module::TAX_SERVICE, 'field' => 'term_id', 'terms' => \array_map( 'intval', $tids ) ] ],
		] );
		return empty( $others ) ? 0 : (int) $others[0];
	}

	/* ---- Admin notices ---- */

	/** Escaped, capability-gated data-integrity warnings on the CPT edit screen. */
	public function edit_screen_notices() {
		if ( ! \function_exists( 'get_current_screen' ) ) { return; }
		$screen = \get_current_screen();
		if ( ! $screen || $screen->base !== 'post' ) { return; }

		$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
		if ( ! $post_id && isset( $GLOBALS['post']->ID ) ) { $post_id = (int) $GLOBALS['post']->ID; }
		if ( ! $post_id || ! \current_user_can( 'edit_post', $post_id ) ) { return; }

		$type    = \get_post_type( $post_id );
		$notices = [];

		if ( $type === Module::CPT_LOCATION ) {
			$other = $this->location_slug_collision( $post_id );
			if ( $other ) {
				$slug = (string) \get_post_field( 'post_name', $post_id );
				$notices[] = \sprintf(
					/* translators: 1: slug, 2: other location edit link. */
					\esc_html__( 'This location shares its URL slug “%1$s” with another published location (%2$s). Inbound /services/…/%1$s/ links resolve to only one of them — give this location a unique slug.', 'anchor-schema' ),
					'</strong>' . \esc_html( $slug ) . '<strong>',
					'<a href="' . \esc_url( \get_edit_post_link( $other ) ) . '">' . \esc_html( \get_the_title( $other ) ) . '</a>'
				);
			}
			if ( $this->location_missing_coords( $post_id ) ) {
				$notices[] = \esc_html__( 'This location has no latitude/longitude, so it will not appear on the map.', 'anchor-schema' );
			}
		} elseif ( $type === Module::CPT_SERVICE ) {
			if ( $this->service_orphan( $post_id ) ) {
				$notices[] = \esc_html__( 'This service page has no valid published linked location, so it will not route to a live URL.', 'anchor-schema' );
			} else {
				$dup = $this->service_duplicate_combo( $post_id );
				if ( $dup ) {
					$notices[] = \sprintf(
						/* translators: %s: duplicate service page edit link. */
						\esc_html__( 'Another published service page (%s) already covers this same service + location combination.', 'anchor-schema' ),
						'<a href="' . \esc_url( \get_edit_post_link( $dup ) ) . '">' . \esc_html( \get_the_title( $dup ) ) . '</a>'
					);
				}
			}
		}

		foreach ( $notices as $n ) {
			// Message pieces are individually escaped above; the only raw markup is
			// the <strong>/<a> we constructed, so allow that limited set through.
			echo '<div class="notice notice-warning"><p><strong>' . \wp_kses( $n, [ 'a' => [ 'href' => [] ], 'strong' => [] ] ) . '</strong></p></div>';
		}
	}

	/* ---- Locations list "Health" column ---- */

	public function add_health_column( $cols ) {
		$cols['al_health'] = \__( 'Health', 'anchor-schema' );
		return $cols;
	}

	public function render_health_column( $col, $post_id ) {
		if ( $col !== 'al_health' ) { return; }
		if ( $this->location_slug_collision( (int) $post_id ) ) {
			echo '<span title="' . \esc_attr__( 'Duplicate slug — collides with another published location', 'anchor-schema' ) . '">⚠ ' . \esc_html__( 'Duplicate slug', 'anchor-schema' ) . '</span>';
		} else {
			echo '<span aria-hidden="true">—</span>';
		}
	}

	/* ---- C. Cache versioning ---- */

	/** Current cache version; 0 when the option is absent (→ callers bypass cache). */
	public static function cache_version(): int {
		$v = \get_option( self::CACHE_VER_OPTION );
		return ( $v === false ) ? 0 : (int) $v;
	}

	/**
	 * Increment the cache version (creating it at 1 on first bump). autoload=false.
	 * Honors the suspend flag so a bulk import can collapse its many hook-driven
	 * bumps into a single explicit bump_now() at the end.
	 */
	public static function bump_cache_version() {
		if ( self::$suspend_bumps ) { return; }
		self::do_bump();
	}

	/** Force a bump regardless of the suspend flag (the one bump IO issues post-import). */
	public static function bump_now() {
		self::do_bump();
	}

	/** The actual monotonic increment; single option write, autoload=false. */
	private static function do_bump() {
		$v    = \get_option( self::CACHE_VER_OPTION );
		$next = ( $v === false ? 0 : (int) $v ) + 1;
		\update_option( self::CACHE_VER_OPTION, $next, false );
	}

	/** Bump when one of our CPT posts is trashed or hard-deleted. */
	public function on_deleted_post( $post_id ) {
		$type = \get_post_type( $post_id );
		if ( $type === Module::CPT_LOCATION || $type === Module::CPT_SERVICE ) {
			self::bump_cache_version();
		}
	}

	/**
	 * Bump when an `al_*` post-meta value is added/updated/deleted on one of our
	 * CPTs — covers WP-CLI/import writes that never trigger save_post. Cheap: two
	 * string/type guards before the single option write.
	 */
	public function on_changed_post_meta( $meta_id, $post_id, $meta_key, $meta_value ) {
		if ( \strpos( (string) $meta_key, 'al_' ) !== 0 ) { return; }
		$type = \get_post_type( $post_id );
		if ( $type === Module::CPT_LOCATION || $type === Module::CPT_SERVICE ) {
			self::bump_cache_version();
		}
	}

	/** Bump when a `service` term is edited or deleted (changes URLs/labels/slugs). */
	public function on_edited_term( $term_id, $tt_id, $taxonomy ) {
		if ( $taxonomy === Module::TAX_SERVICE ) { self::bump_cache_version(); }
	}

	/** Bump when a service page's `service` term assignments change. */
	public function on_set_object_terms( $object_id, $terms, $tt_ids, $taxonomy ) {
		if ( $taxonomy === Module::TAX_SERVICE ) { self::bump_cache_version(); }
	}
}
