<?php
/**
 * Anchor Locations — Phase 4: per-page SEO controls.
 *
 * Adds a "SEO" metabox to the location & service-page CPTs, then either feeds
 * the per-page values into an active SEO plugin (Yoast / Rank Math / AIOSEO) or
 * emits our own tags when none is active — never both, so we never duplicate an
 * SEO plugin's output. Robots (noindex/nofollow) always go through the core
 * `wp_robots` filter, which works with or without an SEO plugin. Also owns core
 * sitemap exclusion, the `[anchor_h1]` shortcode, and the full-width single
 * template wiring.
 *
 * Kept in its own class to keep anchor-locations.php lean; instantiated from
 * Module::__construct.
 *
 * @package Anchor\Locations
 */
namespace Anchor\Locations;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEO {
	const NONCE = 'al_seo_nonce';

	/** @return string[] The two CPTs this class governs. */
	private function cpts() {
		return [ Module::CPT_LOCATION, Module::CPT_SERVICE ];
	}

	public function __construct() {
		\add_action( 'add_meta_boxes', [ $this, 'add_seo_metabox' ] );
		\add_action( 'save_post', [ $this, 'save_seo_meta' ] );

		// Robots always via core filter (WP 5.7+), with or without an SEO plugin.
		\add_filter( 'wp_robots', [ $this, 'filter_robots' ] );

		// Own output — only fires (internally guarded) when NO SEO plugin is active.
		\add_filter( 'pre_get_document_title', [ $this, 'own_document_title' ], 20 );
		\add_action( 'wp_head', [ $this, 'print_head_meta' ], 5 );

		// Canonical: override core's own canonical (emitted by the default
		// `rel_canonical` action on wp_head) via its filter, so exactly ONE
		// canonical tag is produced. We deliberately do NOT print our own <link>
		// in print_head_meta — that would duplicate core's tag. Guarded to no-op
		// when an SEO plugin is active (those manage canonical via their own feeds).
		\add_filter( 'get_canonical_url', [ $this, 'filter_canonical' ], 10, 2 );

		// SEO-plugin feed filters — harmless when the plugin is inactive (never fired).
		\add_filter( 'wpseo_title', [ $this, 'yoast_title' ] );
		\add_filter( 'wpseo_metadesc', [ $this, 'yoast_metadesc' ] );
		\add_filter( 'wpseo_canonical', [ $this, 'yoast_canonical' ] );
		\add_filter( 'wpseo_opengraph_title', [ $this, 'yoast_og_title' ] );
		\add_filter( 'wpseo_opengraph_desc', [ $this, 'yoast_og_desc' ] );
		\add_filter( 'wpseo_opengraph_image', [ $this, 'yoast_og_image' ] );
		\add_filter( 'rank_math/frontend/title', [ $this, 'rankmath_title' ] );
		\add_filter( 'rank_math/frontend/description', [ $this, 'rankmath_description' ] );
		\add_filter( 'rank_math/frontend/canonical', [ $this, 'rankmath_canonical' ] );

		// Sitemap exclusion: core (must-have) + Yoast (best-effort).
		\add_filter( 'wp_sitemaps_posts_query_args', [ $this, 'filter_sitemap_query' ], 10, 2 );
		\add_filter( 'wpseo_exclude_from_sitemap_by_post_ids', [ $this, 'yoast_sitemap_exclude_ids' ] );

		// al_h1 exposure.
		\add_shortcode( 'anchor_h1', [ $this, 'sc_h1' ] );

		// Full-width single template wiring (Settings > Locations > fullwidth_template).
		\add_filter( 'template_include', [ $this, 'fullwidth_template' ] );
	}

	/**
	 * Which SEO plugin (if any) is active. Used to suppress our own <title>/<meta>
	 * output so we never duplicate the plugin's tags.
	 *
	 * @return string 'yoast' | 'rankmath' | 'aioseo' | ''.
	 */
	public function active_seo_plugin() {
		if ( \defined( 'WPSEO_VERSION' ) ) { return 'yoast'; }
		if ( \class_exists( 'RankMath' ) ) { return 'rankmath'; }
		if ( \defined( 'AIOSEO_VERSION' ) ) { return 'aioseo'; }
		return '';
	}

	/** Queried post ID when the current view is a singular location/service page, else 0. */
	private function queried_cpt_id() {
		if ( ! \is_singular( $this->cpts() ) ) { return 0; }
		return (int) \get_queried_object_id();
	}

	/* ---- Metabox ---- */

	public function add_seo_metabox() {
		foreach ( $this->cpts() as $cpt ) {
			\add_meta_box( 'al_seo', \__( 'SEO', 'anchor-schema' ), [ $this, 'render_seo_metabox' ], $cpt, 'normal', 'default' );
		}
	}

	public function render_seo_metabox( $post ) {
		\wp_nonce_field( self::NONCE, self::NONCE );
		$g = function ( $k ) use ( $post ) { return \get_post_meta( $post->ID, $k, true ); };
		$text = function ( $k, $label ) use ( $g ) {
			echo '<p><label>' . \esc_html( $label ) . '<br><input type="text" name="' . \esc_attr( $k ) . '" value="' . \esc_attr( $g( $k ) ) . '" class="widefat"></label></p>';
		};
		$area = function ( $k, $label ) use ( $g ) {
			echo '<p><label>' . \esc_html( $label ) . '<br><textarea name="' . \esc_attr( $k ) . '" class="widefat" rows="3">' . \esc_textarea( $g( $k ) ) . '</textarea></label></p>';
		};

		$text( 'al_seo_title', \__( 'SEO title', 'anchor-schema' ) );
		$area( 'al_seo_desc', \__( 'Meta description', 'anchor-schema' ) );
		echo '<p><label>' . \esc_html__( 'Canonical URL', 'anchor-schema' ) . '<br><input type="url" name="al_canonical" value="' . \esc_attr( $g( 'al_canonical' ) ) . '" class="widefat"></label></p>';

		echo '<p><label><input type="checkbox" name="al_robots_noindex" value="1" ' . \checked( $g( 'al_robots_noindex' ), '1', false ) . '> ' . \esc_html__( 'noindex (hide from search engines)', 'anchor-schema' ) . '</label><br>';
		echo '<label><input type="checkbox" name="al_robots_nofollow" value="1" ' . \checked( $g( 'al_robots_nofollow' ), '1', false ) . '> ' . \esc_html__( 'nofollow', 'anchor-schema' ) . '</label></p>';

		$text( 'al_og_title', \__( 'Open Graph title', 'anchor-schema' ) );
		$area( 'al_og_desc', \__( 'Open Graph description', 'anchor-schema' ) );
		echo '<p><label>' . \esc_html__( 'Open Graph image URL', 'anchor-schema' ) . '<br><input type="text" name="al_og_image" value="' . \esc_attr( $g( 'al_og_image' ) ) . '" class="widefat al-media"></label></p>';

		$text( 'al_h1', \__( 'H1 override', 'anchor-schema' ) );
		$text( 'al_breadcrumb_title', \__( 'Breadcrumb label override', 'anchor-schema' ) );

		echo '<p><label><input type="checkbox" name="al_sitemap_exclude" value="1" ' . \checked( $g( 'al_sitemap_exclude' ), '1', false ) . '> ' . \esc_html__( 'Exclude from XML sitemaps', 'anchor-schema' ) . '</label></p>';
	}

	public function save_seo_meta( $post_id ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! \wp_verify_nonce( $_POST[ self::NONCE ], self::NONCE ) ) { return; }
		if ( \defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
		if ( ! \current_user_can( 'edit_post', $post_id ) ) { return; }

		foreach ( [ 'al_seo_title', 'al_og_title', 'al_h1', 'al_breadcrumb_title' ] as $k ) {
			if ( isset( $_POST[ $k ] ) ) { \update_post_meta( $post_id, $k, \sanitize_text_field( \wp_unslash( $_POST[ $k ] ) ) ); }
		}
		foreach ( [ 'al_seo_desc', 'al_og_desc' ] as $k ) {
			if ( isset( $_POST[ $k ] ) ) { \update_post_meta( $post_id, $k, \sanitize_textarea_field( \wp_unslash( $_POST[ $k ] ) ) ); }
		}
		foreach ( [ 'al_canonical', 'al_og_image' ] as $k ) {
			if ( isset( $_POST[ $k ] ) ) { \update_post_meta( $post_id, $k, \esc_url_raw( \wp_unslash( $_POST[ $k ] ) ) ); }
		}
		foreach ( [ 'al_robots_noindex', 'al_robots_nofollow', 'al_sitemap_exclude' ] as $k ) {
			\update_post_meta( $post_id, $k, isset( $_POST[ $k ] ) && $_POST[ $k ] === '1' ? '1' : '' );
		}
	}

	/* ---- Robots ---- */

	public function filter_robots( $robots ) {
		$id = $this->queried_cpt_id();
		if ( ! $id ) { return $robots; }
		if ( \get_post_meta( $id, 'al_robots_noindex', true ) === '1' ) {
			$robots['noindex'] = true;
			unset( $robots['index'] );
		}
		if ( \get_post_meta( $id, 'al_robots_nofollow', true ) === '1' ) {
			$robots['nofollow'] = true;
			unset( $robots['follow'] );
		}
		return $robots;
	}

	/* ---- Own output (no SEO plugin active) ---- */

	public function own_document_title( $title ) {
		if ( $this->active_seo_plugin() ) { return $title; }
		$id = $this->queried_cpt_id();
		if ( ! $id ) { return $title; }
		$v = (string) \get_post_meta( $id, 'al_seo_title', true );
		return $v !== '' ? $v : $title;
	}

	public function print_head_meta() {
		if ( $this->active_seo_plugin() ) { return; }
		$id = $this->queried_cpt_id();
		if ( ! $id ) { return; }

		$desc      = (string) \get_post_meta( $id, 'al_seo_desc', true );
		$og_title  = (string) \get_post_meta( $id, 'al_og_title', true );
		$og_desc   = (string) \get_post_meta( $id, 'al_og_desc', true );
		$og_image  = (string) \get_post_meta( $id, 'al_og_image', true );

		// NOTE: canonical is intentionally NOT printed here. Core's default
		// `rel_canonical` action already emits one canonical tag on wp_head; we
		// override its href via the `get_canonical_url` filter (see
		// filter_canonical) so there is exactly one canonical, never two.
		if ( $desc !== '' ) {
			echo '<meta name="description" content="' . \esc_attr( $desc ) . '">' . "\n";
		}
		if ( $og_title !== '' ) {
			echo '<meta property="og:title" content="' . \esc_attr( $og_title ) . '">' . "\n";
		}
		if ( $og_desc !== '' ) {
			echo '<meta property="og:description" content="' . \esc_attr( $og_desc ) . '">' . "\n";
		}
		if ( $og_image !== '' ) {
			echo '<meta property="og:image" content="' . \esc_url( $og_image ) . '">' . "\n";
		}
	}

	/**
	 * Override core's canonical URL for our singular CPTs when a per-page
	 * `al_canonical` is set and NO SEO plugin is active. Core's default
	 * `rel_canonical` action then emits our single, overridden tag instead of
	 * the permalink — so we never end up with two conflicting canonicals. SEO
	 * plugins are skipped here (they manage canonical via their own filters,
	 * which we already feed via yoast_canonical / rankmath_canonical).
	 *
	 * @param string    $canonical The canonical URL core computed (the permalink).
	 * @param \WP_Post  $post      The post the canonical is being generated for.
	 * @return string
	 */
	public function filter_canonical( $canonical, $post ) {
		if ( $this->active_seo_plugin() ) { return $canonical; }
		if ( ! ( $post instanceof \WP_Post ) || ! \in_array( $post->post_type, $this->cpts(), true ) ) { return $canonical; }
		$v = (string) \get_post_meta( $post->ID, 'al_canonical', true );
		return $v !== '' ? $v : $canonical;
	}

	/* ---- SEO-plugin feed callbacks (override only when our field is non-empty) ---- */

	private function feed( $meta_key, $passthrough ) {
		$id = $this->queried_cpt_id();
		if ( ! $id ) { return $passthrough; }
		$v = (string) \get_post_meta( $id, $meta_key, true );
		return $v !== '' ? $v : $passthrough;
	}

	public function yoast_title( $t )         { return $this->feed( 'al_seo_title', $t ); }
	public function yoast_metadesc( $t )      { return $this->feed( 'al_seo_desc', $t ); }
	public function yoast_canonical( $t )     { return $this->feed( 'al_canonical', $t ); }
	public function yoast_og_title( $t )      { return $this->feed( 'al_og_title', $t ); }
	public function yoast_og_desc( $t )       { return $this->feed( 'al_og_desc', $t ); }
	public function yoast_og_image( $t )      { return $this->feed( 'al_og_image', $t ); }
	public function rankmath_title( $t )      { return $this->feed( 'al_seo_title', $t ); }
	public function rankmath_description( $t ){ return $this->feed( 'al_seo_desc', $t ); }
	public function rankmath_canonical( $t )  { return $this->feed( 'al_canonical', $t ); }

	/* ---- Sitemap exclusion ---- */

	/** Per-request memoization for excluded_ids() (null = not yet computed). */
	private $excluded_ids_cache = null;

	/**
	 * Published CPT post IDs flagged al_sitemap_exclude.
	 *
	 * Memoized per request (instance-scoped): a paginated sitemap fires this
	 * filter once per page, and the flagged set doesn't change mid-request, so
	 * the unbounded get_posts(-1) query runs at most once instead of per page.
	 *
	 * @return int[]
	 */
	private function excluded_ids() {
		if ( $this->excluded_ids_cache !== null ) { return $this->excluded_ids_cache; }
		$this->excluded_ids_cache = \array_map( 'intval', \get_posts( [
			'post_type'      => $this->cpts(),
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => [ [ 'key' => 'al_sitemap_exclude', 'value' => '1' ] ],
		] ) );
		return $this->excluded_ids_cache;
	}

	public function filter_sitemap_query( $args, $post_type ) {
		if ( ! \in_array( $post_type, $this->cpts(), true ) ) { return $args; }
		$ids = $this->excluded_ids();
		if ( ! $ids ) { return $args; }
		$existing = isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : [];
		$args['post__not_in'] = \array_values( \array_unique( \array_merge( $existing, $ids ) ) );
		return $args;
	}

	/** Yoast best-effort: add our flagged IDs to Yoast's sitemap exclusion list. */
	public function yoast_sitemap_exclude_ids( $ids ) {
		$ids = \is_array( $ids ) ? $ids : [];
		return \array_values( \array_unique( \array_merge( \array_map( 'intval', $ids ), $this->excluded_ids() ) ) );
	}

	/* ---- al_h1 ---- */

	public function sc_h1( $atts ) {
		$a  = \shortcode_atts( [ 'id' => 0 ], $atts, 'anchor_h1' );
		$id = (int) $a['id'] ? (int) $a['id'] : (int) \get_the_ID();
		if ( ! $id ) { return ''; }
		$h1 = (string) \get_post_meta( $id, 'al_h1', true );
		if ( $h1 === '' ) { $h1 = (string) \get_the_title( $id ); }
		$html = '<h1 class="al-h1">' . \esc_html( $h1 ) . '</h1>';
		return \apply_filters( 'anchor_locations_h1', $html, $id );
	}

	/* ---- Full-width single template ---- */

	public function fullwidth_template( $template ) {
		$s = \get_option( Module::OPTION, [] );
		if ( empty( $s['fullwidth_template'] ) ) { return $template; }
		if ( ! \is_singular( $this->cpts() ) ) { return $template; }
		$tpl = ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-locations/templates/single-anchor-fullwidth.php';
		return \file_exists( $tpl ) ? $tpl : $template;
	}
}
