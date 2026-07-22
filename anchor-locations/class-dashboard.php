<?php
/**
 * Anchor Locations — Coverage Matrix Dashboard.
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
		'anchor_local_testimonials', 'anchor_local_faqs',
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
	 *   draft > missing.
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

		$index = []; // [loc][term] => list of ['id','status']
		foreach ( $pages as $p ) {
			$loc_id  = (int) \get_post_meta( $p->ID, 'al_location_id', true );
			if ( $loc_id <= 0 ) { continue; }
			$term_ids = \wp_get_object_terms( $p->ID, Module::TAX_SERVICE, [ 'fields' => 'ids' ] );
			if ( \is_wp_error( $term_ids ) ) { continue; }
			foreach ( $term_ids as $tid ) {
				$index[ $loc_id ][ (int) $tid ][] = [
					'id'     => (int) $p->ID,
					'status' => $p->post_status,
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
	 * @param array $cands List of ['id','status'].
	 */
	private function resolve_cell( int $loc_id, int $term_id, array $cands ): array {
		$published = null; // first published
		$draft     = null; // first draft
		foreach ( $cands as $c ) {
			if ( $c['status'] === 'publish' ) { $published = $published ?? $c; }
			elseif ( $c['status'] === 'draft' ) { $draft = $draft ?? $c; }
		}

		if ( $published ) {
			$pid = $published['id'];
			return [ 'status' => 'published', 'page_id' => $pid, 'edit' => $this->edit_url( $pid ), 'view' => $this->view_url( $pid ), 'add' => '', 'score' => $this->quality_score( $pid ) ];
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
	 * Weights: body>=300 (40), coords (30), internal-linking shortcode (30).
	 */
	public function quality_score( int $post_id ): int {
		if ( $post_id <= 0 || ! \get_post( $post_id ) ) { return 0; }
		$type = \get_post_type( $post_id );
		$html = (string) \get_post_meta( $post_id, 'al_html', true );

		$score = 0;
		if ( \mb_strlen( \trim( \wp_strip_all_tags( $html ) ) ) >= self::THIN_CHARS ) { $score += 40; }
		if ( $this->has_coords_for( $post_id, $type ) ) { $score += 30; }
		if ( $this->has_link_shortcode( $html ) ) { $score += 30; }
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
			'draft'     => \__( 'Draft', 'anchor-schema' ),
			'missing'   => \__( 'Missing', 'anchor-schema' ),
		];
		$colors = [ 'published' => '#e6f4ea', 'draft' => '#fef7e0', 'missing' => '#fce8e6' ];
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
		echo '<span style="background:#fef7e0;padding:2px 6px;">' . \esc_html__( 'Draft', 'anchor-schema' ) . '</span> ';
		echo '<span style="background:#fce8e6;padding:2px 6px;">' . \esc_html__( 'Missing', 'anchor-schema' ) . '</span>';
		echo '</p>';
	}
}
