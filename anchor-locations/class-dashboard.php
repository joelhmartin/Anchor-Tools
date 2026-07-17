<?php
/**
 * Anchor Locations — Phase 5: Coverage Matrix + SEO Quality Dashboard.
 *
 * A strictly READ-ONLY reporting + navigation surface. The plugin owner populates
 * pages via external connections / WP-CLI, so this phase NEVER creates, mutates,
 * or bulk-generates content. For a missing service×location combination the matrix
 * cell links to WordPress's own "Add New Service Page" screen with the service +
 * location pre-filled via query args — the human/AI still creates and Publishes the
 * page through the normal flow. There is no "generate all" button, no cron, no bulk
 * publish. Every builder here only queries; a unit test asserts post counts are
 * unchanged after building the reports.
 *
 * Kept in its own class to keep anchor-locations.php lean; instantiated from
 * Module::__construct.
 *
 * @package Anchor\Locations
 */
namespace Anchor\Locations;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Dashboard {

	/** Body-length threshold (stripped chars) below which content is "thin". */
	const THIN_CHARS = 300;

	/** Internal-linking shortcodes that earn the quality-score linking credit. */
	const LINK_SHORTCODES = [
		'anchor_location_services', 'anchor_nearby_locations', 'anchor_breadcrumbs',
		'anchor_service_locations', 'anchor_child_locations', 'anchor_location_parent',
		'anchor_location_map', 'anchor_service_area_directory', 'anchor_local_projects',
		'anchor_local_testimonials', 'anchor_local_faqs', 'anchor_h1',
	];

	public function __construct() {
		\add_action( 'admin_menu', [ $this, 'register_pages' ], 30 );

		// Pre-fill the Services taxonomy box on a NEW service page when the
		// al_prefill_service query arg is present. This is a render-side default
		// only — no term is written until the human saves the post.
		\add_filter( 'wp_terms_checklist_args', [ $this, 'prefill_service_checklist' ], 10, 2 );
	}

	/* ---- Admin pages ---- */

	public function register_pages() {
		$parent = 'edit.php?post_type=' . Module::CPT_LOCATION;
		\add_submenu_page( $parent, \__( 'Coverage Matrix', 'anchor-schema' ), \__( 'Coverage', 'anchor-schema' ), 'edit_posts', 'anchor-locations-coverage', [ $this, 'render_coverage_page' ] );
		\add_submenu_page( $parent, \__( 'SEO Reports', 'anchor-schema' ), \__( 'SEO Reports', 'anchor-schema' ), 'edit_posts', 'anchor-locations-seo-reports', [ $this, 'render_seo_page' ] );
	}

	/* ---- URL helpers (admin_url-based so builders are testable headless) ---- */

	private function edit_url( int $post_id ): string {
		return \admin_url( 'post.php?post=' . $post_id . '&action=edit' );
	}

	private function add_url( int $loc_id, int $term_id ): string {
		return \admin_url( 'post-new.php?post_type=' . Module::CPT_SERVICE . '&al_prefill_location=' . $loc_id . '&al_prefill_service=' . $term_id );
	}

	/* ---- A. Coverage matrix (pure builder) ---- */

	/**
	 * Build the service×location coverage matrix.
	 *
	 * @param array $args { Optional. 'type' => location al_type to filter rows by. }
	 * @return array<int,array<int,array{status:string,page_id:int,edit:string,view:string,add:string,score:int}>>
	 *   [ location_id => [ term_id => cell ] ]. Status precedence: published >
	 *   noindex > draft > missing.
	 */
	public function coverage_matrix( array $args = [] ): array {
		$locations = $this->query_locations( isset( $args['type'] ) ? (string) $args['type'] : '' );
		$terms     = \get_terms( [ 'taxonomy' => Module::TAX_SERVICE, 'hide_empty' => false ] );
		if ( \is_wp_error( $terms ) ) { $terms = []; }

		// Index published/draft service pages by [location_id][term_id].
		$pages = \get_posts( [
			'post_type'      => Module::CPT_SERVICE,
			'post_status'    => [ 'publish', 'draft' ],
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		] );

		$index = []; // [loc][term] => list of ['id','status','noindex']
		foreach ( $pages as $p ) {
			$loc_id  = (int) \get_post_meta( $p->ID, 'al_location_id', true );
			if ( $loc_id <= 0 ) { continue; }
			$term_ids = \wp_get_object_terms( $p->ID, Module::TAX_SERVICE, [ 'fields' => 'ids' ] );
			if ( \is_wp_error( $term_ids ) ) { continue; }
			$noindex = \get_post_meta( $p->ID, 'al_robots_noindex', true ) === '1';
			foreach ( $term_ids as $tid ) {
				$index[ $loc_id ][ (int) $tid ][] = [
					'id'      => (int) $p->ID,
					'status'  => $p->post_status,
					'noindex' => $noindex,
				];
			}
		}

		$matrix = [];
		foreach ( $locations as $loc ) {
			$loc_id = (int) $loc->ID;
			foreach ( $terms as $term ) {
				$tid  = (int) $term->term_id;
				$cell = $this->resolve_cell( $loc_id, $tid, $index[ $loc_id ][ $tid ] ?? [] );
				$matrix[ $loc_id ][ $tid ] = $cell;
			}
		}
		return $matrix;
	}

	/**
	 * Resolve one matrix cell from the candidate pages for a location×term.
	 *
	 * @param array $cands List of ['id','status','noindex'].
	 */
	private function resolve_cell( int $loc_id, int $term_id, array $cands ): array {
		$published = null; // first published, non-noindex
		$noindex   = null; // first published, noindex
		$draft     = null;
		foreach ( $cands as $c ) {
			if ( $c['status'] === 'publish' ) {
				if ( $c['noindex'] ) { $noindex = $noindex ?? $c; }
				else { $published = $published ?? $c; }
			} elseif ( $c['status'] === 'draft' ) {
				$draft = $draft ?? $c;
			}
		}

		if ( $published ) {
			$pid = $published['id'];
			return [ 'status' => 'published', 'page_id' => $pid, 'edit' => $this->edit_url( $pid ), 'view' => $this->view_url( $pid ), 'add' => '', 'score' => $this->quality_score( $pid ) ];
		}
		if ( $noindex ) {
			$pid = $noindex['id'];
			return [ 'status' => 'noindex', 'page_id' => $pid, 'edit' => $this->edit_url( $pid ), 'view' => $this->view_url( $pid ), 'add' => '', 'score' => $this->quality_score( $pid ) ];
		}
		if ( $draft ) {
			$pid = $draft['id'];
			return [ 'status' => 'draft', 'page_id' => $pid, 'edit' => $this->edit_url( $pid ), 'view' => '', 'add' => '', 'score' => $this->quality_score( $pid ) ];
		}
		return [ 'status' => 'missing', 'page_id' => 0, 'edit' => '', 'view' => '', 'add' => $this->add_url( $loc_id, $term_id ), 'score' => 0 ];
	}

	/** Front-end URL for a service page, or '' when it can't be resolved. */
	private function view_url( int $post_id ): string {
		// Reuse the already-constructed Module singleton — building a new Module
		// here re-registers ~20 hooks (plus Libraries/SEO/Dashboard) per matrix cell.
		$mod = Module::instance();
		if ( ! $mod ) { return ''; }
		$url = $mod->service_page_url( $post_id );
		return ( $url && $url !== '#' ) ? $url : '';
	}

	/** @return \WP_Post[] Published locations, optionally filtered by al_type. */
	private function query_locations( string $type = '' ): array {
		$q = [
			'post_type'      => Module::CPT_LOCATION,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		];
		if ( $type !== '' ) {
			$q['meta_query'] = [ [ 'key' => 'al_type', 'value' => $type ] ];
		}
		return \get_posts( $q );
	}

	/* ---- D. Content quality score (pure) ---- */

	/**
	 * Additive 0–100 content-quality score for a location or service page.
	 * Weights: body>=300 (20), al_seo_title (15), al_seo_desc (15), coords (15),
	 * internal-linking shortcode (15), not noindex (10), has H1 (10).
	 */
	public function quality_score( int $post_id ): int {
		if ( $post_id <= 0 || ! \get_post( $post_id ) ) { return 0; }
		$type = \get_post_type( $post_id );
		$html = (string) \get_post_meta( $post_id, 'al_html', true );

		$score = 0;
		if ( \mb_strlen( \trim( \wp_strip_all_tags( $html ) ) ) >= self::THIN_CHARS ) { $score += 20; }
		if ( \trim( (string) \get_post_meta( $post_id, 'al_seo_title', true ) ) !== '' ) { $score += 15; }
		if ( \trim( (string) \get_post_meta( $post_id, 'al_seo_desc', true ) ) !== '' ) { $score += 15; }
		if ( $this->has_coords_for( $post_id, $type ) ) { $score += 15; }
		if ( $this->has_link_shortcode( $html ) ) { $score += 15; }
		if ( \get_post_meta( $post_id, 'al_robots_noindex', true ) !== '1' ) { $score += 10; }
		if ( $this->has_h1( $post_id, $html ) ) { $score += 10; }
		return $score;
	}

	/** Coords present: on a location its own al_lat/al_lng; on a service page its linked location's. */
	private function has_coords_for( int $post_id, $type ): bool {
		$target = $post_id;
		if ( $type === Module::CPT_SERVICE ) {
			$target = (int) \get_post_meta( $post_id, 'al_location_id', true );
			if ( $target <= 0 ) { return false; }
		}
		$lat = \trim( (string) \get_post_meta( $target, 'al_lat', true ) );
		$lng = \trim( (string) \get_post_meta( $target, 'al_lng', true ) );
		return $lat !== '' && $lng !== '';
	}

	private function has_link_shortcode( string $html ): bool {
		foreach ( self::LINK_SHORTCODES as $sc ) {
			if ( \strpos( $html, '[' . $sc ) !== false ) { return true; }
		}
		return false;
	}

	private function has_h1( int $post_id, string $html ): bool {
		if ( \trim( (string) \get_post_meta( $post_id, 'al_h1', true ) ) !== '' ) { return true; }
		return \stripos( $html, '<h1' ) !== false;
	}

	/* ---- C. SEO issues (pure builder) ---- */

	/**
	 * All detected SEO issues grouped by severity.
	 *
	 * @return array{high:array,medium:array,low:array} Each bucket is a list of
	 *   ['type'=>slug,'label'=>string,'posts'=>[['id','title','edit'?,'add'?],...]].
	 *   Only issue types with >=1 affected item are emitted.
	 */
	public function seo_issues(): array {
		$high = [];
		$med  = [];
		$low  = [];

		$locations = \get_posts( [ 'post_type' => Module::CPT_LOCATION, 'post_status' => 'publish', 'posts_per_page' => -1, 'no_found_rows' => true ] );
		$services  = \get_posts( [ 'post_type' => Module::CPT_SERVICE, 'post_status' => 'publish', 'posts_per_page' => -1, 'no_found_rows' => true ] );
		$all       = \array_merge( $locations, $services );

		$thin = $no_title = $no_desc = $no_h1 = $noindex = $sitemap = $missing_coords = $orphans = [];

		foreach ( $all as $p ) {
			$html = (string) \get_post_meta( $p->ID, 'al_html', true );
			if ( \mb_strlen( \trim( \wp_strip_all_tags( $html ) ) ) < self::THIN_CHARS ) { $thin[] = $this->post_ref( $p ); }
			if ( \trim( (string) \get_post_meta( $p->ID, 'al_seo_title', true ) ) === '' ) { $no_title[] = $this->post_ref( $p ); }
			if ( \trim( (string) \get_post_meta( $p->ID, 'al_seo_desc', true ) ) === '' ) { $no_desc[] = $this->post_ref( $p ); }
			if ( ! $this->has_h1( (int) $p->ID, $html ) ) { $no_h1[] = $this->post_ref( $p ); }
			if ( \get_post_meta( $p->ID, 'al_robots_noindex', true ) === '1' ) { $noindex[] = $this->post_ref( $p ); }
			if ( \get_post_meta( $p->ID, 'al_sitemap_exclude', true ) === '1' ) { $sitemap[] = $this->post_ref( $p ); }
		}

		// Locations missing coordinates.
		foreach ( $locations as $p ) {
			$lat = \trim( (string) \get_post_meta( $p->ID, 'al_lat', true ) );
			$lng = \trim( (string) \get_post_meta( $p->ID, 'al_lng', true ) );
			if ( $lat === '' || $lng === '' ) { $missing_coords[] = $this->post_ref( $p ); }
		}

		// Orphan + duplicate service pages.
		$combo = []; // "loc:term" => [ post_ref, ... ]
		foreach ( $services as $p ) {
			$loc_id = (int) \get_post_meta( $p->ID, 'al_location_id', true );
			$loc    = $loc_id > 0 ? \get_post( $loc_id ) : null;
			if ( ! $loc || $loc->post_type !== Module::CPT_LOCATION || $loc->post_status !== 'publish' ) {
				$orphans[] = $this->post_ref( $p );
			}
			$tids = \wp_get_object_terms( $p->ID, Module::TAX_SERVICE, [ 'fields' => 'ids' ] );
			if ( \is_wp_error( $tids ) ) { $tids = []; }
			foreach ( $tids as $tid ) {
				if ( $loc_id > 0 ) { $combo[ $loc_id . ':' . (int) $tid ][] = $this->post_ref( $p ); }
			}
		}
		$duplicates = [];
		foreach ( $combo as $refs ) {
			if ( \count( $refs ) > 1 ) { $duplicates = \array_merge( $duplicates, $refs ); }
		}

		// Coverage gaps (informational, from the matrix).
		$gaps = [];
		foreach ( $this->coverage_matrix() as $loc_id => $cells ) {
			foreach ( $cells as $tid => $cell ) {
				if ( $cell['status'] === 'missing' ) {
					$term = \get_term( (int) $tid, Module::TAX_SERVICE );
					$name = ( $term && ! \is_wp_error( $term ) ) ? $term->name : (string) $tid;
					$gaps[] = [
						'id'    => 0,
						'title' => \sprintf( \__( '%1$s in %2$s', 'anchor-schema' ), $name, \get_the_title( (int) $loc_id ) ),
						'add'   => $cell['add'],
					];
				}
			}
		}

		$push = function ( array &$bucket, string $type, string $label, array $posts ) {
			if ( $posts ) { $bucket[] = [ 'type' => $type, 'label' => $label, 'posts' => $posts ]; }
		};

		$push( $high, 'thin_content',   \__( 'Thin content (under 300 characters)', 'anchor-schema' ), $thin );
		$push( $high, 'missing_coords', \__( 'Location missing coordinates', 'anchor-schema' ), $missing_coords );
		$push( $high, 'orphan_service', \__( 'Orphan service page (no valid linked location)', 'anchor-schema' ), $orphans );
		$push( $high, 'duplicate_combo',\__( 'Duplicate service + location combination', 'anchor-schema' ), $duplicates );

		$push( $med, 'missing_seo_title', \__( 'Missing SEO title', 'anchor-schema' ), $no_title );
		$push( $med, 'missing_seo_desc',  \__( 'Missing meta description', 'anchor-schema' ), $no_desc );
		$push( $med, 'missing_h1',        \__( 'Missing H1', 'anchor-schema' ), $no_h1 );

		$push( $low, 'coverage_gap',     \__( 'Coverage gaps (missing service + location pages)', 'anchor-schema' ), $gaps );
		$push( $low, 'noindex_pages',    \__( 'Noindex pages', 'anchor-schema' ), $noindex );
		$push( $low, 'sitemap_excluded', \__( 'Sitemap-excluded pages', 'anchor-schema' ), $sitemap );

		return [ 'high' => $high, 'medium' => $med, 'low' => $low ];
	}

	/** @return array{id:int,title:string,edit:string} A post reference with an edit link. */
	private function post_ref( $post ): array {
		return [ 'id' => (int) $post->ID, 'title' => (string) \get_the_title( $post ), 'edit' => $this->edit_url( (int) $post->ID ) ];
	}

	/* ---- B. Pre-fill support (render-side only, no writes) ---- */

	/**
	 * Pre-check the service term in the Services taxonomy box on a NEW service page
	 * when ?al_prefill_service=<term_id> is present. Purely a default selection —
	 * nothing is persisted until the human saves the post.
	 */
	public function prefill_service_checklist( $args, $post_id ) {
		if ( ! \is_admin() ) { return $args; }
		$taxonomy = isset( $args['taxonomy'] ) ? $args['taxonomy'] : 'category';
		if ( $taxonomy !== Module::TAX_SERVICE ) { return $args; }
		if ( ! isset( $_GET['al_prefill_service'] ) ) { return $args; }

		$post = $post_id ? \get_post( $post_id ) : null;
		// Only default on a brand-new (auto-draft) service page.
		if ( ! $post || $post->post_type !== Module::CPT_SERVICE || $post->post_status !== 'auto-draft' ) { return $args; }

		$term_id = (int) $_GET['al_prefill_service'];
		if ( $term_id > 0 && \get_term( $term_id, Module::TAX_SERVICE ) ) {
			$args['selected_cats'] = [ $term_id ];
		}
		return $args;
	}

	/* ---- Admin page renderers ---- */

	public function render_coverage_page() {
		if ( ! \current_user_can( 'edit_posts' ) ) { \wp_die( \esc_html__( 'You do not have permission to view this page.', 'anchor-schema' ) ); }

		$type   = isset( $_GET['al_type'] ) ? \sanitize_key( \wp_unslash( $_GET['al_type'] ) ) : '';
		$matrix = $this->coverage_matrix( $type !== '' ? [ 'type' => $type ] : [] );
		$terms  = \get_terms( [ 'taxonomy' => Module::TAX_SERVICE, 'hide_empty' => false ] );
		if ( \is_wp_error( $terms ) ) { $terms = []; }

		echo '<div class="wrap anchor-locations-coverage">';
		echo '<h1>' . \esc_html__( 'Coverage Matrix', 'anchor-schema' ) . '</h1>';
		echo '<p class="description">' . \esc_html__( 'Read-only report. A “Missing” cell links to the standard Add New Service Page screen with the service and location pre-filled — pages are never generated automatically.', 'anchor-schema' ) . '</p>';

		// Type filter.
		$types = [ 'state', 'county', 'city', 'township', 'borough', 'neighborhood', 'region' ];
		echo '<form method="get" style="margin:12px 0;">';
		echo '<input type="hidden" name="post_type" value="' . \esc_attr( Module::CPT_LOCATION ) . '">';
		echo '<input type="hidden" name="page" value="anchor-locations-coverage">';
		echo '<label>' . \esc_html__( 'Filter by type:', 'anchor-schema' ) . ' <select name="al_type" onchange="this.form.submit()">';
		echo '<option value="">' . \esc_html__( '— all —', 'anchor-schema' ) . '</option>';
		foreach ( $types as $t ) {
			echo '<option value="' . \esc_attr( $t ) . '" ' . \selected( $type, $t, false ) . '>' . \esc_html( \ucfirst( $t ) ) . '</option>';
		}
		echo '</select></label></form>';

		if ( empty( $matrix ) || empty( $terms ) ) {
			echo '<p>' . \esc_html__( 'No published locations or service terms to report on yet.', 'anchor-schema' ) . '</p></div>';
			return;
		}

		echo '<table class="widefat striped anchor-coverage-table"><thead><tr>';
		echo '<th>' . \esc_html__( 'Location', 'anchor-schema' ) . '</th>';
		foreach ( $terms as $term ) { echo '<th>' . \esc_html( $term->name ) . '</th>'; }
		echo '</tr></thead><tbody>';

		foreach ( $matrix as $loc_id => $cells ) {
			echo '<tr><th scope="row"><a href="' . \esc_url( $this->edit_url( (int) $loc_id ) ) . '">' . \esc_html( \get_the_title( (int) $loc_id ) ) . '</a></th>';
			foreach ( $terms as $term ) {
				$cell = $cells[ (int) $term->term_id ] ?? [ 'status' => 'missing', 'edit' => '', 'view' => '', 'add' => $this->add_url( (int) $loc_id, (int) $term->term_id ), 'score' => 0 ];
				echo $this->render_cell( $cell ); // built from esc_* internally
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		$this->legend();
		echo '</div>';
	}

	/** One coverage cell as an escaped <td>. */
	private function render_cell( array $cell ): string {
		$status = $cell['status'];
		$labels = [
			'published' => \__( 'Published', 'anchor-schema' ),
			'noindex'   => \__( 'Noindex', 'anchor-schema' ),
			'draft'     => \__( 'Draft', 'anchor-schema' ),
			'missing'   => \__( 'Missing', 'anchor-schema' ),
		];
		$colors = [ 'published' => '#e6f4ea', 'noindex' => '#fff4e5', 'draft' => '#fef7e0', 'missing' => '#fce8e6' ];
		$label  = $labels[ $status ] ?? $status;
		$bg     = $colors[ $status ] ?? '#fff';

		$html = '<td style="background:' . \esc_attr( $bg ) . ';white-space:nowrap;">';
		$html .= '<strong>' . \esc_html( $label ) . '</strong>';
		if ( $status === 'missing' ) {
			$html .= ' <a href="' . \esc_url( $cell['add'] ) . '">' . \esc_html__( '+ Add', 'anchor-schema' ) . '</a>';
		} else {
			$html .= ' <span class="al-score" title="' . \esc_attr__( 'Quality score', 'anchor-schema' ) . '">(' . (int) $cell['score'] . ')</span>';
			$links = [];
			if ( ! empty( $cell['view'] ) ) { $links[] = '<a href="' . \esc_url( $cell['view'] ) . '" target="_blank" rel="noopener">' . \esc_html__( 'View', 'anchor-schema' ) . '</a>'; }
			if ( ! empty( $cell['edit'] ) ) { $links[] = '<a href="' . \esc_url( $cell['edit'] ) . '">' . \esc_html__( 'Edit', 'anchor-schema' ) . '</a>'; }
			if ( $links ) { $html .= '<br><small>' . \implode( ' · ', $links ) . '</small>'; }
		}
		$html .= '</td>';
		return $html;
	}

	private function legend() {
		echo '<p class="description" style="margin-top:10px;">';
		echo \esc_html__( 'Legend:', 'anchor-schema' ) . ' ';
		echo '<span style="background:#e6f4ea;padding:2px 6px;">' . \esc_html__( 'Published', 'anchor-schema' ) . '</span> ';
		echo '<span style="background:#fff4e5;padding:2px 6px;">' . \esc_html__( 'Noindex', 'anchor-schema' ) . '</span> ';
		echo '<span style="background:#fef7e0;padding:2px 6px;">' . \esc_html__( 'Draft', 'anchor-schema' ) . '</span> ';
		echo '<span style="background:#fce8e6;padding:2px 6px;">' . \esc_html__( 'Missing', 'anchor-schema' ) . '</span>';
		echo '</p>';
	}

	public function render_seo_page() {
		if ( ! \current_user_can( 'edit_posts' ) ) { \wp_die( \esc_html__( 'You do not have permission to view this page.', 'anchor-schema' ) ); }

		$issues = $this->seo_issues();
		$sev    = [
			'high'   => [ \__( 'High priority', 'anchor-schema' ), '#fce8e6' ],
			'medium' => [ \__( 'Medium priority', 'anchor-schema' ), '#fef7e0' ],
			'low'    => [ \__( 'Low / informational', 'anchor-schema' ), '#e8f0fe' ],
		];

		echo '<div class="wrap anchor-locations-seo-reports">';
		echo '<h1>' . \esc_html__( 'SEO Reports', 'anchor-schema' ) . '</h1>';
		echo '<p class="description">' . \esc_html__( 'Read-only quality audit of your location and service pages.', 'anchor-schema' ) . '</p>';

		$total = 0;
		foreach ( $issues as $bucket ) { foreach ( $bucket as $i ) { $total += \count( $i['posts'] ); } }
		if ( $total === 0 ) {
			echo '<p>' . \esc_html__( 'No issues detected. ', 'anchor-schema' ) . '</p></div>';
			return;
		}

		foreach ( [ 'high', 'medium', 'low' ] as $level ) {
			if ( empty( $issues[ $level ] ) ) { continue; }
			list( $heading, $bg ) = $sev[ $level ];
			echo '<h2 style="border-left:6px solid ' . \esc_attr( $bg ) . ';padding-left:8px;">' . \esc_html( $heading ) . '</h2>';
			foreach ( $issues[ $level ] as $issue ) {
				echo '<h3>' . \esc_html( $issue['label'] ) . ' <span class="count">(' . (int) \count( $issue['posts'] ) . ')</span></h3>';
				echo '<ul style="list-style:disc;margin-left:22px;">';
				foreach ( $issue['posts'] as $ref ) {
					$title = $ref['title'] !== '' ? $ref['title'] : \__( '(untitled)', 'anchor-schema' );
					if ( ! empty( $ref['edit'] ) ) {
						echo '<li><a href="' . \esc_url( $ref['edit'] ) . '">' . \esc_html( $title ) . '</a></li>';
					} elseif ( ! empty( $ref['add'] ) ) {
						echo '<li>' . \esc_html( $title ) . ' — <a href="' . \esc_url( $ref['add'] ) . '">' . \esc_html__( '+ Add', 'anchor-schema' ) . '</a></li>';
					} else {
						echo '<li>' . \esc_html( $title ) . '</li>';
					}
				}
				echo '</ul>';
			}
		}
		echo '</div>';
	}
}
