<?php
/**
 * Anchor Locations — Phase 6: Import / Export.
 *
 * Moves an entire Anchor Locations structure between client sites and enables
 * bulk editing of the two most-edited surfaces (locations, service pages) via a
 * CSV round-trip.
 *
 *   - JSON (full migration): a versioned envelope carrying settings, the service
 *     taxonomy, locations (hierarchy), and service pages. Everything references
 *     by SLUG (post_name / term slug / parent slug), never numeric ID, so it is
 *     portable across sites.
 *   - CSV (bulk edit): separate scalar exports/imports for locations and service
 *     pages. Large code fields (al_html/al_css/al_js, the al_*_html content
 *     sections) and boundary GeoJSON are JSON-only and deliberately omitted
 *     from CSV.
 *
 * This is portability, NOT the page generator the owner rejected: import only
 * creates/updates from a user-supplied file, never fabricates combinations, and
 * NEVER deletes. Every imported value is sanitized through the same tiers as the
 * module's save handlers, and a malformed row is skipped + recorded, never fatal.
 *
 * Kept in its own class to keep anchor-locations.php lean; instantiated from
 * Module::__construct.
 *
 * @package Anchor\Locations
 */
namespace Anchor\Locations;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class IO {

	const FORMAT       = 'anchor-locations';
	const VERSION      = 1;
	const NONCE_EXPORT = 'anchor_locations_export';
	const NONCE_IMPORT = 'anchor_locations_import';
	const MAX_UPLOAD   = 5242880; // 5 MB.

	/** Post statuses an import is allowed to set. */
	const STATUSES = [ 'publish', 'draft', 'pending', 'private' ];

	public function __construct() {
		\add_action( 'admin_menu', [ $this, 'register_pages' ], 40 );
		\add_action( 'admin_post_anchor_locations_export', [ $this, 'handle_export' ] );
		\add_action( 'admin_post_anchor_locations_import', [ $this, 'handle_import' ] );
	}

	/* ---- Meta-key contracts (mirror the module save handlers) ---- */

	/**
	 * Sanitize tier per meta key. Unlisted keys default to 'text'. Mirrors the
	 * tiers in Module::save_meta.
	 *
	 * @return array<string,string> key => one of text|code|bool.
	 */
	private static function tiers() {
		return [
			'al_type'          => 'text',
			'al_lat'           => 'text',
			'al_lng'           => 'text',
			'al_place_id'      => 'text',
			'al_state_abbr'    => 'text',
			'al_county'        => 'text',
			'al_postal_codes'  => 'text',
			'al_marker_icon'   => 'text',
			'al_html'          => 'code',
			'al_css'           => 'code',
			'al_js'            => 'code',
			'al_boundary'      => 'code',
			'al_faq_html'          => 'code',
			'al_testimonials_html' => 'code',
			'al_projects_html'     => 'code',
			'al_disable_wrapper'   => 'bool',
		];
	}

	/** Meta keys stored on a LOCATION post (excludes the linked-location id it never has). */
	private static function location_meta_keys() {
		return [
			'al_type', 'al_lat', 'al_lng', 'al_place_id', 'al_state_abbr', 'al_county',
			'al_postal_codes', 'al_marker_icon', 'al_html', 'al_css', 'al_js', 'al_boundary',
			'al_faq_html', 'al_testimonials_html', 'al_projects_html', 'al_disable_wrapper',
		];
	}

	/** Meta keys stored on a SERVICE PAGE (al_location_id is carried as location_slug). */
	private static function service_meta_keys() {
		return [
			'al_html', 'al_css', 'al_js', 'al_faq_html', 'al_testimonials_html',
			'al_projects_html', 'al_disable_wrapper',
		];
	}

	/** Scalar location columns for CSV (code/section fields + boundary are JSON-only). */
	private static function location_csv_keys() {
		return [
			'al_type', 'al_lat', 'al_lng', 'al_place_id', 'al_state_abbr', 'al_county',
			'al_postal_codes', 'al_marker_icon', 'al_disable_wrapper',
		];
	}

	/** Scalar service-page columns for CSV (code/section fields are JSON-only). */
	private static function service_csv_keys() {
		return [
			'al_disable_wrapper',
		];
	}

	/* ---- Sanitize (import side) ---- */

	/**
	 * Sanitize one meta value by its tier, mirroring the module save handlers.
	 * Returns the value ready to store (callers wp_slash before update_post_meta,
	 * since update_metadata unslashes).
	 */
	private function sanitize_meta_value( $key, $value ) {
		$tier = self::tiers()[ $key ] ?? 'text';
		switch ( $tier ) {
			case 'code':
				return (string) $value;
			case 'textarea':
				return \sanitize_textarea_field( (string) $value );
			case 'url':
				return \esc_url_raw( (string) $value );
			case 'bool':
				return ( (string) $value === '1' ) ? '1' : '';
			case 'int':
				$r = (int) $value;
				if ( $key === 'al_rating' ) { return ( $r > 5 ) ? 5 : ( ( $r < 1 ) ? 0 : $r ); } // mirror editor clamp (>5 -> 5)
				return $r;
			case 'kses':
				return \wp_kses_post( (string) $value );
			case 'text':
			default:
				return \sanitize_text_field( (string) $value );
		}
	}

	/* ================================================================
	 * EXPORT
	 * ================================================================ */

	/**
	 * Full JSON migration envelope. All references use slugs for portability.
	 *
	 * @return array
	 */
	public function export_json() {
		return [
			'format'       => self::FORMAT,
			'version'      => self::VERSION,
			'exported_at'  => \gmdate( 'c' ),
			'settings'     => (array) \get_option( Module::OPTION, [] ),
			'services'     => $this->export_services(),
			'locations'    => $this->export_locations(),
			'service_pages'=> $this->export_service_pages(),
		];
	}

	private function export_services() {
		$terms = \get_terms( [ 'taxonomy' => Module::TAX_SERVICE, 'hide_empty' => false ] );
		if ( \is_wp_error( $terms ) ) { return []; }
		$out = [];
		foreach ( $terms as $t ) {
			$parent_slug = '';
			if ( $t->parent ) {
				$p = \get_term( $t->parent, Module::TAX_SERVICE );
				if ( $p && ! \is_wp_error( $p ) ) { $parent_slug = $p->slug; }
			}
			$out[] = [ 'name' => $t->name, 'slug' => $t->slug, 'parent_slug' => $parent_slug ];
		}
		return $out;
	}

	private function export_locations() {
		$posts = \get_posts( [ 'post_type' => Module::CPT_LOCATION, 'post_status' => 'any', 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true ] );
		$out = [];
		foreach ( $posts as $p ) {
			$out[] = [
				'title'       => $p->post_title,
				'slug'        => $p->post_name,
				'status'      => $p->post_status,
				'parent_slug' => $p->post_parent ? (string) \get_post_field( 'post_name', $p->post_parent ) : '',
				'meta'        => $this->collect_meta( $p->ID, self::location_meta_keys() ),
			];
		}
		return $out;
	}

	private function export_service_pages() {
		$posts = \get_posts( [ 'post_type' => Module::CPT_SERVICE, 'post_status' => 'any', 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true ] );
		$out = [];
		foreach ( $posts as $p ) {
			$loc_id   = (int) \get_post_meta( $p->ID, 'al_location_id', true );
			$loc_slug = $loc_id ? (string) \get_post_field( 'post_name', $loc_id ) : '';
			$out[] = [
				'title'         => $p->post_title,
				'slug'          => $p->post_name,
				'status'        => $p->post_status,
				'location_slug' => $loc_slug,
				'service_slugs' => $this->term_slugs( $p->ID ),
				'meta'          => $this->collect_meta( $p->ID, self::service_meta_keys() ),
			];
		}
		return $out;
	}

	/** @return array<string,string> Raw stored meta for the given keys. */
	private function collect_meta( $post_id, array $keys ) {
		$out = [];
		foreach ( $keys as $k ) {
			$out[ $k ] = (string) \get_post_meta( $post_id, $k, true );
		}
		return $out;
	}

	/** @return string[] service-term slugs assigned to a post. */
	private function term_slugs( $post_id ) {
		$slugs = \wp_get_object_terms( $post_id, Module::TAX_SERVICE, [ 'fields' => 'slugs' ] );
		return \is_wp_error( $slugs ) ? [] : \array_values( $slugs );
	}

	/* ---- CSV export ---- */

	/**
	 * Guard a CSV cell against spreadsheet formula injection: any value beginning
	 * with = + - @ TAB or CR is prefixed with a single quote.
	 */
	private function csv_cell( $value ) {
		$value = (string) $value;
		if ( $value !== '' && \strpos( "=+-@\t\r", $value[0] ) !== false ) {
			$value = "'" . $value;
		}
		return $value;
	}

	/** @param array $rows list of assoc rows keyed by $headers; @return string CSV text. */
	private function build_csv( array $headers, array $rows ) {
		$fh = \fopen( 'php://temp', 'r+' );
		// Explicit args incl. an empty escape: RFC-4180-style CSV and no PHP 8.4
		// "$escape must be provided" deprecation. Symmetric with parse_csv().
		\fputcsv( $fh, $headers, ',', '"', '' );
		foreach ( $rows as $row ) {
			$line = [];
			foreach ( $headers as $h ) {
				$line[] = $this->csv_cell( $row[ $h ] ?? '' );
			}
			\fputcsv( $fh, $line, ',', '"', '' );
		}
		\rewind( $fh );
		$csv = \stream_get_contents( $fh );
		\fclose( $fh );
		return $csv;
	}

	public function export_locations_csv() {
		$headers = \array_merge( [ 'slug', 'title', 'status', 'parent_slug' ], self::location_csv_keys() );
		$posts = \get_posts( [ 'post_type' => Module::CPT_LOCATION, 'post_status' => 'any', 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true ] );
		$rows = [];
		foreach ( $posts as $p ) {
			$row = [
				'slug'        => $p->post_name,
				'title'       => $p->post_title,
				'status'      => $p->post_status,
				'parent_slug' => $p->post_parent ? (string) \get_post_field( 'post_name', $p->post_parent ) : '',
			];
			foreach ( self::location_csv_keys() as $k ) {
				$row[ $k ] = (string) \get_post_meta( $p->ID, $k, true );
			}
			$rows[] = $row;
		}
		return $this->build_csv( $headers, $rows );
	}

	public function export_service_pages_csv() {
		$headers = \array_merge( [ 'slug', 'title', 'status', 'location_slug', 'service_slugs' ], self::service_csv_keys() );
		$posts = \get_posts( [ 'post_type' => Module::CPT_SERVICE, 'post_status' => 'any', 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true ] );
		$rows = [];
		foreach ( $posts as $p ) {
			$loc_id = (int) \get_post_meta( $p->ID, 'al_location_id', true );
			$row = [
				'slug'          => $p->post_name,
				'title'         => $p->post_title,
				'status'        => $p->post_status,
				'location_slug' => $loc_id ? (string) \get_post_field( 'post_name', $loc_id ) : '',
				'service_slugs' => \implode( '|', $this->term_slugs( $p->ID ) ),
			];
			foreach ( self::service_csv_keys() as $k ) {
				$row[ $k ] = (string) \get_post_meta( $p->ID, $k, true );
			}
			$rows[] = $row;
		}
		return $this->build_csv( $headers, $rows );
	}

	/* ================================================================
	 * IMPORT
	 * ================================================================ */

	private function empty_summary() {
		return [ 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [] ];
	}

	/**
	 * Import a full JSON envelope. Upsert-by-slug; never deletes. Ordered so
	 * references resolve: settings -> service terms -> locations -> service
	 * pages. Each row is guarded so one bad row is skipped + recorded.
	 *
	 * @param array $data JSON-decoded envelope.
	 * @param array $opts { 'dry_run' => bool }
	 * @return array{created:int,updated:int,skipped:int,errors:string[]}
	 */
	public function import_json( array $data, array $opts = [] ) {
		$dry = ! empty( $opts['dry_run'] );
		$summary = $this->empty_summary();

		if ( ( $data['format'] ?? '' ) !== self::FORMAT ) {
			$summary['errors'][] = \__( 'Unrecognized file format.', 'anchor-schema' );
			return $summary;
		}
		if ( (int) ( $data['version'] ?? 0 ) !== self::VERSION ) {
			$summary['errors'][] = \sprintf( \__( 'Unsupported version (expected %d).', 'anchor-schema' ), self::VERSION );
			return $summary;
		}

		// A real import performs hundreds of al_* meta writes across N posts, each
		// of which would otherwise bump the cache version (an update_option). Suspend
		// those per-write bumps and invalidate the map/directory cache with a single
		// explicit bump in the finally below. Dry runs write nothing, so no suspend.
		if ( ! $dry ) { Integrity::$suspend_bumps = true; }

		try {
			// Settings (full replace of the option; never touches posts). Not counted.
			if ( ! $dry && isset( $data['settings'] ) && \is_array( $data['settings'] ) ) {
				$mod = Module::instance();
				$clean = $mod ? $mod->sanitize_settings( $data['settings'] ) : $data['settings'];
				\update_option( Module::OPTION, $clean, false );
			}

			$term_map = $this->import_services( $data['services'] ?? [], $dry, $summary );
			$loc_map  = $this->import_locations( $data['locations'] ?? [], $dry, $summary );
			$this->import_service_pages( $data['service_pages'] ?? [], $loc_map, $term_map, $dry, $summary );

			return $summary;
		} finally {
			if ( ! $dry ) {
				Integrity::$suspend_bumps = false;
				Integrity::bump_now(); // exactly one cache invalidation for the whole import
			}
		}
	}

	/**
	 * Two-phase service-term upsert (create at root, then set parents). Returns a
	 * slug => term_id map (existing + imported). In dry runs nothing is written;
	 * would-create terms map to -1.
	 */
	private function import_services( array $services, $dry, array &$summary ) {
		$map = [];
		foreach ( \get_terms( [ 'taxonomy' => Module::TAX_SERVICE, 'hide_empty' => false ] ) as $t ) {
			if ( ! \is_wp_error( $t ) ) { $map[ $t->slug ] = (int) $t->term_id; }
		}
		// Phase A: ensure each term exists.
		foreach ( $services as $svc ) {
			try {
				$slug = \sanitize_title( $svc['slug'] ?? $svc['name'] ?? '' );
				if ( $slug === '' ) { throw new \Exception( 'service term missing slug' ); }
				$name = \sanitize_text_field( (string) ( $svc['name'] ?? $slug ) );
				if ( isset( $map[ $slug ] ) ) {
					$summary['updated']++;
					continue;
				}
				if ( $dry ) {
					$map[ $slug ] = -1;
					$summary['created']++;
					continue;
				}
				$res = \wp_insert_term( $name, Module::TAX_SERVICE, [ 'slug' => $slug ] );
				if ( \is_wp_error( $res ) ) {
					$existing = \get_term_by( 'slug', $slug, Module::TAX_SERVICE );
					if ( $existing ) { $map[ $slug ] = (int) $existing->term_id; $summary['updated']++; continue; }
					throw new \Exception( $res->get_error_message() );
				}
				$map[ $slug ] = (int) $res['term_id'];
				$summary['created']++;
			} catch ( \Throwable $e ) {
				$summary['skipped']++;
				$summary['errors'][] = \sprintf( \__( 'Service term "%1$s": %2$s', 'anchor-schema' ), (string) ( $svc['slug'] ?? $svc['name'] ?? '?' ), $e->getMessage() );
			}
		}
		// Phase B: parents.
		if ( ! $dry ) {
			foreach ( $services as $svc ) {
				$slug   = \sanitize_title( $svc['slug'] ?? '' );
				$parent = \sanitize_title( $svc['parent_slug'] ?? '' );
				if ( $slug === '' || $parent === '' ) { continue; }
				if ( isset( $map[ $slug ], $map[ $parent ] ) && $map[ $slug ] > 0 && $map[ $parent ] > 0 ) {
					\wp_update_term( $map[ $slug ], Module::TAX_SERVICE, [ 'parent' => $map[ $parent ] ] );
				}
			}
		}
		return $map;
	}

	/**
	 * Two-phase location upsert: (A) upsert every location at root, building
	 * slug => id; (B) set post_parent from parent_slug. Order-independent, no
	 * infinite loops. Returns the slug => id map.
	 */
	private function import_locations( array $locations, $dry, array &$summary ) {
		$map = [];
		// Prepopulate with existing locations so parent/other references resolve.
		foreach ( \get_posts( [ 'post_type' => Module::CPT_LOCATION, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ] ) as $lid ) {
			$slug = (string) \get_post_field( 'post_name', $lid );
			if ( $slug !== '' ) { $map[ $slug ] = (int) $lid; }
		}
		// Phase A: upsert content at root.
		foreach ( $locations as $loc ) {
			try {
				$slug = \sanitize_title( $loc['slug'] ?? '' );
				if ( $slug === '' ) { throw new \Exception( 'location missing slug' ); }
				$id = $this->upsert_post( Module::CPT_LOCATION, $slug, $loc, $dry, $summary );
				$map[ $slug ] = $id;
				if ( ! $dry && $id > 0 ) {
					$this->write_meta( $id, (array) ( $loc['meta'] ?? [] ), self::location_meta_keys() );
				}
			} catch ( \Throwable $e ) {
				$summary['skipped']++;
				$summary['errors'][] = \sprintf( \__( 'Location "%1$s": %2$s', 'anchor-schema' ), (string) ( $loc['slug'] ?? '?' ), $e->getMessage() );
			}
		}
		// Phase B: parents.
		if ( ! $dry ) {
			foreach ( $locations as $loc ) {
				$slug   = \sanitize_title( $loc['slug'] ?? '' );
				$parent = \sanitize_title( $loc['parent_slug'] ?? '' );
				if ( $slug === '' || $parent === '' ) { continue; }
				if ( isset( $map[ $slug ], $map[ $parent ] ) && $map[ $slug ] > 0 && $map[ $parent ] > 0 ) {
					\wp_update_post( [ 'ID' => $map[ $slug ], 'post_parent' => $map[ $parent ] ] );
				} elseif ( $parent !== '' && ! isset( $map[ $parent ] ) ) {
					$summary['errors'][] = \sprintf( \__( 'Location "%1$s": unknown parent_slug "%2$s".', 'anchor-schema' ), $slug, $parent );
				}
			}
		}
		return $map;
	}

	private function import_service_pages( array $pages, array $loc_map, array $term_map, $dry, array &$summary ) {
		foreach ( $pages as $sp ) {
			try {
				$slug = \sanitize_title( $sp['slug'] ?? '' );
				if ( $slug === '' ) { throw new \Exception( 'service page missing slug' ); }
				$loc_slug = \sanitize_title( $sp['location_slug'] ?? '' );
				if ( $loc_slug === '' || ! isset( $loc_map[ $loc_slug ] ) ) {
					throw new \Exception( \sprintf( 'unknown location_slug "%s"', (string) ( $sp['location_slug'] ?? '' ) ) );
				}
				$loc_id = (int) $loc_map[ $loc_slug ];

				$id = $this->upsert_post( Module::CPT_SERVICE, $slug, $sp, $dry, $summary );
				if ( $dry || $id <= 0 ) { continue; }

				// Linked location id ($loc_id may be -1 only in dry runs, guarded above).
				if ( $loc_id > 0 ) { \update_post_meta( $id, 'al_location_id', $loc_id ); }
				$this->write_meta( $id, (array) ( $sp['meta'] ?? [] ), self::service_meta_keys() );
				$this->assign_terms( $id, (array) ( $sp['service_slugs'] ?? [] ), $term_map, $slug, $summary );
			} catch ( \Throwable $e ) {
				$summary['skipped']++;
				$summary['errors'][] = \sprintf( \__( 'Service page "%1$s": %2$s', 'anchor-schema' ), (string) ( $sp['slug'] ?? '?' ), $e->getMessage() );
			}
		}
	}

	/**
	 * Upsert a post by slug. Finds an existing post of $type with post_name==$slug
	 * (any status) and updates title/status/parent, else inserts. Increments the
	 * summary. In dry runs nothing is written and it returns -1 (would-create) or
	 * the existing id.
	 *
	 * @return int post id, -1 for a dry-run would-create.
	 */
	private function upsert_post( $type, $slug, array $row, $dry, array &$summary ) {
		$existing = \get_posts( [ 'post_type' => $type, 'name' => $slug, 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => true ] );
		$id = $existing ? (int) $existing[0] : 0;

		$status = \in_array( ( $row['status'] ?? '' ), self::STATUSES, true ) ? $row['status'] : 'publish';
		$title  = \sanitize_text_field( (string) ( $row['title'] ?? $slug ) );

		if ( $dry ) {
			if ( $id ) { $summary['updated']++; return $id; }
			$summary['created']++;
			return -1;
		}

		$arr = [ 'post_type' => $type, 'post_title' => $title, 'post_status' => $status, 'post_name' => $slug ];
		if ( $id ) {
			$arr['ID'] = $id;
			$res = \wp_update_post( $arr, true );
			if ( \is_wp_error( $res ) ) { throw new \Exception( $res->get_error_message() ); }
			$summary['updated']++;
			return $id;
		}
		$res = \wp_insert_post( $arr, true );
		if ( \is_wp_error( $res ) ) { throw new \Exception( $res->get_error_message() ); }
		$summary['created']++;
		return (int) $res;
	}

	/** Sanitize + store each provided meta key (only keys in $allowed). */
	private function write_meta( $post_id, array $meta, array $allowed ) {
		foreach ( $allowed as $k ) {
			if ( ! \array_key_exists( $k, $meta ) ) { continue; }
			$val = $this->sanitize_meta_value( $k, $meta[ $k ] );
			// update_metadata unslashes; wp_slash so the stored value is exact.
			\update_post_meta( $post_id, $k, \is_string( $val ) ? \wp_slash( $val ) : $val );
		}
	}

	/**
	 * Assign existing service terms (by slug) to a post. Only terms present in the
	 * $term_map (existing or imported) are assigned; an unknown slug is a soft
	 * warning recorded in errors, not a skip.
	 */
	private function assign_terms( $post_id, array $slugs, array $term_map, $owner_slug, array &$summary ) {
		$ids = [];
		foreach ( $slugs as $s ) {
			$s = \sanitize_title( (string) $s );
			if ( $s === '' ) { continue; }
			if ( isset( $term_map[ $s ] ) && $term_map[ $s ] > 0 ) {
				$ids[] = (int) $term_map[ $s ];
			} else {
				$term = \get_term_by( 'slug', $s, Module::TAX_SERVICE );
				if ( $term ) { $ids[] = (int) $term->term_id; }
				else { $summary['errors'][] = \sprintf( \__( '"%1$s": unknown service term "%2$s" (skipped).', 'anchor-schema' ), $owner_slug, $s ); }
			}
		}
		\wp_set_object_terms( $post_id, $ids, Module::TAX_SERVICE, false );
	}

	/* ---- CSV import ---- */

	/**
	 * Import a locations or service_pages CSV (type detected from the header).
	 * Scalar fields only; upsert by slug.
	 *
	 * @return array{created:int,updated:int,skipped:int,errors:string[]}
	 */
	public function import_csv( $csv, array $opts = [] ) {
		$dry = ! empty( $opts['dry_run'] );
		$summary = $this->empty_summary();

		$rows = $this->parse_csv( (string) $csv );
		if ( empty( $rows ) ) {
			$summary['errors'][] = \__( 'CSV is empty or unreadable.', 'anchor-schema' );
			return $summary;
		}
		$headers = \array_keys( $rows[0] );
		$is_service = \in_array( 'location_slug', $headers, true );
		$is_location = ! $is_service && ( \in_array( 'parent_slug', $headers, true ) || \in_array( 'al_type', $headers, true ) );

		// Same single-bump strategy as import_json: suspend per-write cache bumps
		// during a real import and invalidate once at the end (dry runs write nothing).
		if ( ! $dry ) { Integrity::$suspend_bumps = true; }

		try {
		if ( $is_service ) {
			$term_map = $this->existing_term_map();
			$loc_map  = $this->existing_location_map();
			foreach ( $rows as $row ) {
				try {
					$slug = \sanitize_title( $row['slug'] ?? '' );
					if ( $slug === '' ) { throw new \Exception( 'row missing slug' ); }
					$loc_slug = \sanitize_title( $row['location_slug'] ?? '' );
					if ( $loc_slug === '' || ! isset( $loc_map[ $loc_slug ] ) ) {
						throw new \Exception( \sprintf( 'unknown location_slug "%s"', (string) ( $row['location_slug'] ?? '' ) ) );
					}
					$id = $this->upsert_post( Module::CPT_SERVICE, $slug, $row, $dry, $summary );
					if ( $dry || $id <= 0 ) { continue; }
					\update_post_meta( $id, 'al_location_id', (int) $loc_map[ $loc_slug ] );
					$this->write_meta( $id, $row, self::service_csv_keys() );
					$svc_slugs = $this->split_slugs( $row['service_slugs'] ?? '' );
					$this->assign_terms( $id, $svc_slugs, $term_map, $slug, $summary );
				} catch ( \Throwable $e ) {
					$summary['skipped']++;
					$summary['errors'][] = \sprintf( \__( 'Service page "%1$s": %2$s', 'anchor-schema' ), (string) ( $row['slug'] ?? '?' ), $e->getMessage() );
				}
			}
		} elseif ( $is_location ) {
			$loc_map = [];
			foreach ( $rows as $row ) {
				try {
					$slug = \sanitize_title( $row['slug'] ?? '' );
					if ( $slug === '' ) { throw new \Exception( 'row missing slug' ); }
					$id = $this->upsert_post( Module::CPT_LOCATION, $slug, $row, $dry, $summary );
					$loc_map[ $slug ] = $id;
					if ( $dry || $id <= 0 ) { continue; }
					$this->write_meta( $id, $row, self::location_csv_keys() );
				} catch ( \Throwable $e ) {
					$summary['skipped']++;
					$summary['errors'][] = \sprintf( \__( 'Location "%1$s": %2$s', 'anchor-schema' ), (string) ( $row['slug'] ?? '?' ), $e->getMessage() );
				}
			}
			// Second pass: parents (slug may reference existing or imported rows).
			if ( ! $dry ) {
				$existing = $this->existing_location_map();
				$loc_map += $existing;
				foreach ( $rows as $row ) {
					$slug   = \sanitize_title( $row['slug'] ?? '' );
					$parent = \sanitize_title( $row['parent_slug'] ?? '' );
					if ( $slug === '' || $parent === '' ) { continue; }
					if ( isset( $loc_map[ $slug ], $loc_map[ $parent ] ) && $loc_map[ $slug ] > 0 && $loc_map[ $parent ] > 0 ) {
						\wp_update_post( [ 'ID' => $loc_map[ $slug ], 'post_parent' => $loc_map[ $parent ] ] );
					}
				}
			}
		} else {
			$summary['errors'][] = \__( 'Unrecognized CSV header (need a locations or service-pages export).', 'anchor-schema' );
		}

		return $summary;
		} finally {
			if ( ! $dry ) {
				Integrity::$suspend_bumps = false;
				Integrity::bump_now(); // exactly one cache invalidation for the whole import
			}
		}
	}

	private function existing_location_map() {
		$map = [];
		foreach ( \get_posts( [ 'post_type' => Module::CPT_LOCATION, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ] ) as $lid ) {
			$slug = (string) \get_post_field( 'post_name', $lid );
			if ( $slug !== '' ) { $map[ $slug ] = (int) $lid; }
		}
		return $map;
	}

	private function existing_term_map() {
		$map = [];
		foreach ( \get_terms( [ 'taxonomy' => Module::TAX_SERVICE, 'hide_empty' => false ] ) as $t ) {
			if ( ! \is_wp_error( $t ) ) { $map[ $t->slug ] = (int) $t->term_id; }
		}
		return $map;
	}

	private function split_slugs( $val ) {
		$val = (string) $val;
		if ( $val === '' ) { return []; }
		return \array_filter( \array_map( 'sanitize_title', \preg_split( '/[|,]/', $val ) ) );
	}

	/** Parse CSV text into a list of assoc rows keyed by the header row. */
	private function parse_csv( $csv ) {
		$csv = \preg_replace( "/^\xEF\xBB\xBF/", '', $csv ); // strip BOM.
		$fh = \fopen( 'php://temp', 'r+' );
		\fwrite( $fh, $csv );
		\rewind( $fh );
		$headers = \fgetcsv( $fh, null, ',', '"', '' );
		if ( ! \is_array( $headers ) ) { \fclose( $fh ); return []; }
		$headers = \array_map( 'trim', $headers );
		$rows = [];
		while ( ( $line = \fgetcsv( $fh, null, ',', '"', '' ) ) !== false ) {
			if ( $line === [ null ] || $line === false ) { continue; }
			$row = [];
			foreach ( $headers as $i => $h ) {
				$cell = $line[ $i ] ?? '';
				// Undo the formula-injection guard: a leading ' we added on export.
				if ( \is_string( $cell ) && isset( $cell[0] ) && $cell[0] === "'" && isset( $cell[1] ) && \strpos( "=+-@\t\r", $cell[1] ) !== false ) {
					$cell = \substr( $cell, 1 );
				}
				$row[ $h ] = $cell;
			}
			$rows[] = $row;
		}
		\fclose( $fh );
		return $rows;
	}

	/* ================================================================
	 * ADMIN UI + handlers
	 * ================================================================ */

	public function register_pages() {
		\add_submenu_page(
			'edit.php?post_type=' . Module::CPT_LOCATION,
			\__( 'Import / Export', 'anchor-schema' ),
			\__( 'Import / Export', 'anchor-schema' ),
			'manage_options',
			'anchor-locations-io',
			[ $this, 'render_page' ]
		);
	}

	/** Build the admin page URL (for redirect-back after import). */
	private function page_url( array $args = [] ) {
		$base = \admin_url( 'edit.php?post_type=' . Module::CPT_LOCATION . '&page=anchor-locations-io' );
		return $args ? \add_query_arg( $args, $base ) : $base;
	}

	public function render_page() {
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_die( \esc_html__( 'You do not have permission to view this page.', 'anchor-schema' ) );
		}

		echo '<div class="wrap anchor-locations-io">';
		echo '<h1>' . \esc_html__( 'Locations Import / Export', 'anchor-schema' ) . '</h1>';

		// Result summary (after an import redirect).
		$this->maybe_render_result();

		// Export.
		echo '<h2>' . \esc_html__( 'Export', 'anchor-schema' ) . '</h2>';
		echo '<p class="description">' . \esc_html__( 'JSON is a full, portable migration (all locations, service pages, services, and settings — referenced by slug). CSV is for bulk-editing scalar fields; HTML/CSS/JS and boundary GeoJSON are omitted from CSV and stay JSON-only.', 'anchor-schema' ) . '</p>';
		foreach ( [
			'json'          => \__( 'Export full JSON', 'anchor-schema' ),
			'locations'     => \__( 'Export locations CSV', 'anchor-schema' ),
			'service_pages' => \__( 'Export service pages CSV', 'anchor-schema' ),
		] as $type => $label ) {
			echo '<form method="post" action="' . \esc_url( \admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px;">';
			echo '<input type="hidden" name="action" value="anchor_locations_export">';
			echo '<input type="hidden" name="type" value="' . \esc_attr( $type ) . '">';
			\wp_nonce_field( self::NONCE_EXPORT );
			echo '<button type="submit" class="button">' . \esc_html( $label ) . '</button>';
			echo '</form>';
		}

		// Import.
		echo '<h2 style="margin-top:28px;">' . \esc_html__( 'Import', 'anchor-schema' ) . '</h2>';
		echo '<p class="description">' . \esc_html__( 'Upload a .json (full) or .csv (locations or service pages) file. Import upserts by slug — it never deletes anything. Use “Preview” first to see what would change.', 'anchor-schema' ) . '</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . \esc_url( \admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="anchor_locations_import">';
		\wp_nonce_field( self::NONCE_IMPORT );
		echo '<p><input type="file" name="anchor_io_file" accept=".json,.csv" required></p>';
		echo '<p><label><input type="checkbox" name="dry_run" value="1" checked> ' . \esc_html__( 'Preview (dry run) — report what would change without writing', 'anchor-schema' ) . '</label></p>';
		echo '<p><button type="submit" class="button button-primary">' . \esc_html__( 'Upload & run', 'anchor-schema' ) . '</button></p>';
		echo '</form>';

		echo '</div>';
	}

	/** Render the stored import result summary (transient) if present. */
	private function maybe_render_result() {
		if ( empty( $_GET['al_io_result'] ) ) { return; }
		$key = \sanitize_key( \wp_unslash( $_GET['al_io_result'] ) );
		$res = \get_transient( 'anchor_io_' . $key );
		if ( ! \is_array( $res ) ) { return; }
		\delete_transient( 'anchor_io_' . $key );

		$dry = ! empty( $res['dry_run'] );
		$cls = empty( $res['errors'] ) ? 'notice-success' : 'notice-warning';
		echo '<div class="notice ' . \esc_attr( $cls ) . '"><p><strong>';
		echo $dry ? \esc_html__( 'Preview (dry run) — nothing was written.', 'anchor-schema' ) : \esc_html__( 'Import complete.', 'anchor-schema' );
		echo '</strong></p><p>';
		echo \esc_html( \sprintf(
			/* translators: 1: created, 2: updated, 3: skipped counts. */
			\__( 'Created: %1$d · Updated: %2$d · Skipped: %3$d', 'anchor-schema' ),
			(int) ( $res['created'] ?? 0 ), (int) ( $res['updated'] ?? 0 ), (int) ( $res['skipped'] ?? 0 )
		) );
		echo '</p>';
		if ( ! empty( $res['errors'] ) ) {
			echo '<ul style="list-style:disc;margin-left:22px;">';
			foreach ( \array_slice( (array) $res['errors'], 0, 100 ) as $err ) {
				echo '<li>' . \esc_html( (string) $err ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</div>';
	}

	public function handle_export() {
		if ( ! \current_user_can( 'manage_options' ) ) { \wp_die( \esc_html__( 'Insufficient permissions.', 'anchor-schema' ) ); }
		\check_admin_referer( self::NONCE_EXPORT );

		$type = isset( $_POST['type'] ) ? \sanitize_key( \wp_unslash( $_POST['type'] ) ) : 'json';
		$stamp = \gmdate( 'Ymd-His' );

		if ( $type === 'locations' ) {
			$this->stream( 'anchor-locations-' . $stamp . '.csv', 'text/csv', $this->export_locations_csv() );
		} elseif ( $type === 'service_pages' ) {
			$this->stream( 'anchor-service-pages-' . $stamp . '.csv', 'text/csv', $this->export_service_pages_csv() );
		} else {
			$json = \wp_json_encode( $this->export_json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			$this->stream( 'anchor-locations-' . $stamp . '.json', 'application/json', (string) $json );
		}
	}

	/** Stream a string as a file download and exit. */
	private function stream( $filename, $mime, $body ) {
		\nocache_headers();
		\header( 'Content-Type: ' . $mime . '; charset=utf-8' );
		\header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		\header( 'Content-Length: ' . \strlen( $body ) );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- file body, not HTML.
		exit;
	}

	public function handle_import() {
		if ( ! \current_user_can( 'manage_options' ) ) { \wp_die( \esc_html__( 'Insufficient permissions.', 'anchor-schema' ) ); }
		\check_admin_referer( self::NONCE_IMPORT );

		$dry = ! empty( $_POST['dry_run'] );

		$file = isset( $_FILES['anchor_io_file'] ) ? $_FILES['anchor_io_file'] : null;
		$summary = $this->empty_summary();

		if ( ! $file || ! isset( $file['error'] ) || $file['error'] !== UPLOAD_ERR_OK || empty( $file['tmp_name'] ) || ! \is_uploaded_file( $file['tmp_name'] ) ) {
			$summary['errors'][] = \__( 'No file uploaded or the upload failed.', 'anchor-schema' );
			$this->finish_import( $summary, $dry );
		}

		$name = \sanitize_file_name( (string) $file['name'] );
		$ext  = \strtolower( (string) \pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! \in_array( $ext, [ 'json', 'csv' ], true ) ) {
			$summary['errors'][] = \__( 'Only .json and .csv files are accepted.', 'anchor-schema' );
			$this->finish_import( $summary, $dry );
		}
		if ( (int) $file['size'] > self::MAX_UPLOAD ) {
			$summary['errors'][] = \sprintf( \__( 'File exceeds the %d MB limit.', 'anchor-schema' ), (int) ( self::MAX_UPLOAD / 1048576 ) );
			$this->finish_import( $summary, $dry );
		}

		$contents = \file_get_contents( $file['tmp_name'] );
		if ( $contents === false ) {
			$summary['errors'][] = \__( 'Could not read the uploaded file.', 'anchor-schema' );
			$this->finish_import( $summary, $dry );
		}

		if ( $ext === 'json' ) {
			$data = \json_decode( $contents, true );
			if ( ! \is_array( $data ) ) {
				$summary['errors'][] = \__( 'Invalid JSON.', 'anchor-schema' );
				$this->finish_import( $summary, $dry );
			}
			$summary = $this->import_json( $data, [ 'dry_run' => $dry ] );
		} else {
			$summary = $this->import_csv( $contents, [ 'dry_run' => $dry ] );
		}

		$this->finish_import( $summary, $dry );
	}

	/** Persist the summary to a transient and redirect back to the page. */
	private function finish_import( array $summary, $dry ) {
		$summary['dry_run'] = (bool) $dry;
		$key = \wp_generate_password( 12, false );
		\set_transient( 'anchor_io_' . $key, $summary, 300 );
		\wp_safe_redirect( $this->page_url( [ 'al_io_result' => $key ] ) );
		exit;
	}
}
