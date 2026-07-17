<?php
/**
 * Anchor Locations — Phase 2: Content Libraries.
 *
 * Reusable projects / testimonials / FAQs that auto-surface on Phase-1
 * location & service pages by relevance. Owns its own CPTs, metaboxes, save
 * handler, shortcodes, specificity resolver, and FAQ JSON-LD — deliberately
 * decoupled from Module::build_schema() so the Phase-1 schema stays untouched.
 *
 * @package Anchor\Locations
 */
namespace Anchor\Locations;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Libraries {
	const CPT_PROJECT     = 'anchor_project';
	const CPT_TESTIMONIAL = 'anchor_testimonial';
	const CPT_FAQ         = 'anchor_faq';
	const TAX_SERVICE     = 'service';
	const NONCE           = 'anchor_locations_lib_nonce';

	/** Per-request FAQ collector: list of [ 'q' => string, 'a' => string ]. */
	private $faq_items = [];

	/** Post IDs already added to $faq_items this request (dedupe guard). */
	private $faq_seen = [];

	/** Per-request Review collector: list of [ 'author', 'body', 'rating' ]. */
	private $review_items = [];

	/** Testimonial IDs already added to $review_items this request (dedupe guard). */
	private $review_seen = [];

	public function __construct() {
		\add_action( 'init', [ $this, 'register_types' ] );

		\add_action( 'add_meta_boxes', [ $this, 'add_metaboxes' ] );
		\add_action( 'save_post', [ $this, 'save_meta' ] );
		\add_action( 'admin_enqueue_scripts', [ $this, 'admin_assets' ] );

		foreach ( $this->cpts() as $cpt ) {
			\add_filter( 'manage_' . $cpt . '_posts_columns', [ $this, 'admin_columns' ] );
			\add_action( 'manage_' . $cpt . '_posts_custom_column', [ $this, 'admin_column' ], 10, 2 );
		}

		\add_shortcode( 'anchor_local_projects', [ $this, 'sc_projects' ] );
		\add_shortcode( 'anchor_local_testimonials', [ $this, 'sc_testimonials' ] );
		\add_shortcode( 'anchor_local_faqs', [ $this, 'sc_faqs' ] );

		// Emit on wp_footer, not wp_head: the FAQ collector is filled while the
		// body renders (the_content), which happens AFTER wp_head. JSON-LD is
		// valid anywhere in the document, and wp_footer fires after the loop so
		// $faq_items is populated by the time we print.
		\add_action( 'wp_footer', [ $this, 'print_faq_schema' ], 21 );

		// Phase 4: Review + AggregateRating schema from rated testimonials. Same
		// footer-collector pattern as the FAQ schema (the collector is filled while
		// the body renders [anchor_local_testimonials], which runs after wp_head).
		\add_action( 'wp_footer', [ $this, 'print_review_schema' ], 21 );
	}

	/** @return string[] The three library CPT slugs. */
	private function cpts() {
		return [ self::CPT_PROJECT, self::CPT_TESTIMONIAL, self::CPT_FAQ ];
	}

	/* ---- CPT registration ---- */

	public function register_types() {
		$common = [
			'public'       => false,
			'show_ui'      => true,
			'show_in_menu' => 'edit.php?post_type=' . Module::CPT_LOCATION,
			'hierarchical' => false,
			'taxonomies'   => [ self::TAX_SERVICE ],
		];

		\register_post_type( self::CPT_PROJECT, $common + [
			'labels'    => [
				'name'          => \__( 'Projects', 'anchor-schema' ),
				'singular_name' => \__( 'Project', 'anchor-schema' ),
				'add_new_item'  => \__( 'Add New Project', 'anchor-schema' ),
				'edit_item'     => \__( 'Edit Project', 'anchor-schema' ),
			],
			'menu_icon' => 'dashicons-portfolio',
			'supports'  => [ 'title', 'thumbnail' ],
		] );

		\register_post_type( self::CPT_TESTIMONIAL, $common + [
			'labels'    => [
				'name'          => \__( 'Testimonials', 'anchor-schema' ),
				'singular_name' => \__( 'Testimonial', 'anchor-schema' ),
				'add_new_item'  => \__( 'Add New Testimonial', 'anchor-schema' ),
				'edit_item'     => \__( 'Edit Testimonial', 'anchor-schema' ),
			],
			'menu_icon' => 'dashicons-format-quote',
			'supports'  => [ 'title' ],
		] );

		\register_post_type( self::CPT_FAQ, $common + [
			'labels'    => [
				'name'          => \__( 'FAQs', 'anchor-schema' ),
				'singular_name' => \__( 'FAQ', 'anchor-schema' ),
				'add_new_item'  => \__( 'Add New FAQ', 'anchor-schema' ),
				'edit_item'     => \__( 'Edit FAQ', 'anchor-schema' ),
			],
			'menu_icon' => 'dashicons-editor-help',
			'supports'  => [ 'title' ],
		] );

		// Ensure the Phase-1 `service` taxonomy also applies to the new CPTs even
		// if it was registered before these types (register_post_type's taxonomies
		// arg covers new-registration order; this is belt-and-braces).
		foreach ( $this->cpts() as $cpt ) {
			\register_taxonomy_for_object_type( self::TAX_SERVICE, $cpt );
		}
	}

	/* ---- Specificity resolver ---- */

	/**
	 * Resolve published library items of $cpt ranked by relevance to a page.
	 *
	 * Scoring (a "match" = the item carries that assignment):
	 *   +8 matches BOTH the page's service term AND the location
	 *   +4 matches the location (item al_location_ids contains $location_id or an ancestor)
	 *   +2 matches the service term
	 *   +1 al_global === '1'
	 * Items scoring 0 are excluded. Sorted score DESC, then post_date DESC.
	 *
	 * @param string $cpt             One of the library CPT slugs.
	 * @param int    $location_id     The page's location post ID (0 = none).
	 * @param int    $service_term_id The page's service term id (0 = none).
	 * @param int    $limit           Max items (<= 0 = unlimited).
	 * @return int[] Published item IDs, highest score first.
	 */
	public function match_items( string $cpt, int $location_id, int $service_term_id = 0, int $limit = 0 ): array {
		if ( ! \in_array( $cpt, $this->cpts(), true ) ) { return []; }

		// The location match set = the location itself plus every ancestor, so a
		// county-level assignment surfaces on a child city.
		$loc_chain = [];
		if ( $location_id > 0 ) {
			$loc_chain = \array_map( 'intval', \get_post_ancestors( $location_id ) );
			$loc_chain[] = $location_id;
		}

		$posts = \get_posts( [
			'post_type'      => $cpt,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		] );

		$scored = [];
		foreach ( $posts as $i => $post ) {
			$id = (int) $post->ID;

			$loc_ids = \get_post_meta( $id, 'al_location_ids', true );
			$loc_ids = \is_array( $loc_ids ) ? \array_map( 'intval', $loc_ids ) : [];
			$loc_match = $loc_chain && \array_intersect( $loc_chain, $loc_ids );

			$svc_match = false;
			if ( $service_term_id > 0 ) {
				$svc_match = \has_term( $service_term_id, self::TAX_SERVICE, $id );
			}

			$global = \get_post_meta( $id, 'al_global', true ) === '1';

			$score = 0;
			if ( $loc_match && $svc_match ) { $score += 8; }
			if ( $loc_match ) { $score += 4; }
			if ( $svc_match ) { $score += 2; }
			if ( $global )    { $score += 1; }

			if ( $score <= 0 ) { continue; }

			// $i (query date-DESC index) is the stable tiebreak within equal scores.
			$scored[] = [ 'id' => $id, 'score' => $score, 'seq' => $i ];
		}

		\usort( $scored, function ( $a, $b ) {
			if ( $a['score'] !== $b['score'] ) { return $b['score'] - $a['score']; }
			return $a['seq'] - $b['seq'];
		} );

		$ids = \array_column( $scored, 'id' );
		if ( $limit > 0 ) { $ids = \array_slice( $ids, 0, $limit ); }
		return $ids;
	}

	/* ---- Shortcode context ---- */

	/**
	 * Derive [ location_id, service_term_id ] for a shortcode, honoring
	 * id/service attribute overrides.
	 *
	 * @return array{0:int,1:int}
	 */
	private function context( $atts ) {
		$location_id = 0;
		$service_id  = 0;
		$post        = \get_post( \get_the_ID() );

		if ( $post && $post->post_type === Module::CPT_SERVICE ) {
			$location_id = (int) \get_post_meta( $post->ID, 'al_location_id', true );
			$terms = \wp_get_object_terms( $post->ID, self::TAX_SERVICE, [ 'fields' => 'ids' ] );
			if ( ! \is_wp_error( $terms ) && $terms ) { $service_id = (int) $terms[0]; }
		} elseif ( $post && $post->post_type === Module::CPT_LOCATION ) {
			$location_id = (int) $post->ID;
		}

		// Attribute overrides.
		if ( isset( $atts['id'] ) && (int) $atts['id'] > 0 ) {
			$location_id = (int) $atts['id'];
		}
		if ( isset( $atts['service'] ) && $atts['service'] !== '' ) {
			$service_id = $this->resolve_service_term( $atts['service'] );
		}

		return [ $location_id, $service_id ];
	}

	/** Resolve a service attribute (numeric id or term slug) to a term id. */
	private function resolve_service_term( $val ) {
		if ( \is_numeric( $val ) ) { return (int) $val; }
		$term = \get_term_by( 'slug', \sanitize_title( $val ), self::TAX_SERVICE );
		return $term ? (int) $term->term_id : 0;
	}

	/* ---- Shortcodes ---- */

	public function sc_projects( $atts ) {
		$atts = \shortcode_atts( [ 'id' => 0, 'service' => '', 'limit' => 6 ], $atts, 'anchor_local_projects' );
		list( $location_id, $service_id ) = $this->context( $atts );
		$ids = $this->match_items( self::CPT_PROJECT, $location_id, $service_id, \max( 0, (int) $atts['limit'] ) );

		$html = '';
		if ( $ids ) {
			$html .= '<div class="al-projects">';
			foreach ( $ids as $id ) {
				$img = \esc_url_raw( (string) \get_post_meta( $id, 'al_image', true ) );
				if ( $img === '' && \has_post_thumbnail( $id ) ) { $img = (string) \get_the_post_thumbnail_url( $id, 'medium' ); }
				$desc = \wp_kses_post( (string) \get_post_meta( $id, 'al_description', true ) );
				$html .= '<div class="al-project">';
				if ( $img !== '' ) {
					$html .= '<div class="al-project-thumb"><img src="' . \esc_url( $img ) . '" alt="' . \esc_attr( \get_the_title( $id ) ) . '" loading="lazy"></div>';
				}
				$html .= '<h3 class="al-project-title">' . \esc_html( \get_the_title( $id ) ) . '</h3>';
				if ( $desc !== '' ) { $html .= '<div class="al-project-desc">' . $desc . '</div>'; }
				$html .= '</div>';
			}
			$html .= '</div>';
		}

		$ctx = [ 'location_id' => $location_id, 'service_term_id' => $service_id, 'ids' => $ids ];
		return \apply_filters( 'anchor_locations_local_projects_html', $html, $ctx );
	}

	public function sc_testimonials( $atts ) {
		$atts = \shortcode_atts( [ 'id' => 0, 'service' => '', 'limit' => 3 ], $atts, 'anchor_local_testimonials' );
		list( $location_id, $service_id ) = $this->context( $atts );
		$ids = $this->match_items( self::CPT_TESTIMONIAL, $location_id, $service_id, \max( 0, (int) $atts['limit'] ) );

		$html = '';
		if ( $ids ) {
			$html .= '<div class="al-testimonials">';
			foreach ( $ids as $id ) {
				$quote  = \wp_kses_post( (string) \get_post_meta( $id, 'al_quote', true ) );
				$author = \sanitize_text_field( (string) \get_post_meta( $id, 'al_author', true ) );
				$rating = (int) \get_post_meta( $id, 'al_rating', true );
				$html .= '<figure class="al-testimonial">';
				if ( $rating >= 1 && $rating <= 5 ) {
					$html .= '<div class="al-testimonial-rating" aria-label="' . \esc_attr( \sprintf( \__( '%d out of 5 stars', 'anchor-schema' ), $rating ) ) . '">' . \str_repeat( '★', $rating ) . \str_repeat( '☆', 5 - $rating ) . '</div>';
				}
				if ( $quote !== '' ) { $html .= '<blockquote class="al-testimonial-quote">' . $quote . '</blockquote>'; }
				if ( $author !== '' ) { $html .= '<figcaption class="al-testimonial-author">' . \esc_html( $author ) . '</figcaption>'; }
				$html .= '</figure>';

				// Feed the Review-schema collector — only rated testimonials count,
				// deduped by post ID so two shortcode calls yield one Review node.
				if ( $rating >= 1 && $rating <= 5 && ! \in_array( $id, $this->review_seen, true ) ) {
					$this->review_seen[]  = $id;
					$this->review_items[] = [
						'author' => $author,
						'body'   => \wp_strip_all_tags( $quote ),
						'rating' => $rating,
					];
				}
			}
			$html .= '</div>';
		}

		$ctx = [ 'location_id' => $location_id, 'service_term_id' => $service_id, 'ids' => $ids ];
		return \apply_filters( 'anchor_locations_local_testimonials_html', $html, $ctx );
	}

	public function sc_faqs( $atts ) {
		$atts = \shortcode_atts( [ 'id' => 0, 'service' => '', 'limit' => 10 ], $atts, 'anchor_local_faqs' );
		list( $location_id, $service_id ) = $this->context( $atts );
		$ids = $this->match_items( self::CPT_FAQ, $location_id, $service_id, \max( 0, (int) $atts['limit'] ) );

		$html = '';
		if ( $ids ) {
			$html .= '<div class="al-faqs">';
			foreach ( $ids as $id ) {
				$q = \sanitize_text_field( (string) \get_post_meta( $id, 'al_question', true ) );
				if ( $q === '' ) { $q = (string) \get_the_title( $id ); }
				$a = \wp_kses_post( (string) \get_post_meta( $id, 'al_answer', true ) );
				$html .= '<div class="al-faq">';
				$html .= '<h3 class="al-faq-q">' . \esc_html( $q ) . '</h3>';
				$html .= '<div class="al-faq-a">' . $a . '</div>';
				$html .= '</div>';
				// Feed the per-request FAQ-schema collector, deduped by post ID so
				// the same FAQ rendered by two shortcode calls on one page yields a
				// single Question entry.
				if ( ! \in_array( $id, $this->faq_seen, true ) ) {
					$this->faq_seen[]  = $id;
					$this->faq_items[] = [ 'q' => $q, 'a' => \wp_strip_all_tags( $a ) ];
				}
			}
			$html .= '</div>';
		}

		$ctx = [ 'location_id' => $location_id, 'service_term_id' => $service_id, 'ids' => $ids ];
		return \apply_filters( 'anchor_locations_local_faqs_html', $html, $ctx );
	}

	/* ---- FAQ JSON-LD ---- */

	/**
	 * Emit a single FAQPage node when FAQs were rendered on a singular page.
	 * Independent of Module::print_schema()/build_schema(). Uses the same safe
	 * encoding: no JSON_UNESCAPED_SLASHES, plus a defensive `</` -> `<\/` guard
	 * so a literal "</script>" in a question/answer can't break out of the
	 * inline <script type="application/ld+json"> tag.
	 */
	public function print_faq_schema() {
		if ( empty( $this->faq_items ) || ! \is_singular() ) { return; }

		$main = [];
		foreach ( $this->faq_items as $f ) {
			if ( $f['q'] === '' ) { continue; }
			$main[] = [
				'@type'          => 'Question',
				'name'           => $f['q'],
				'acceptedAnswer' => [ '@type' => 'Answer', 'text' => $f['a'] ],
			];
		}
		if ( ! $main ) { return; }

		$doc  = [ '@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $main ];
		$json = \wp_json_encode( $doc, JSON_UNESCAPED_UNICODE );
		$json = \str_replace( '</', '<\/', $json );
		echo "\n<script type=\"application/ld+json\">" . $json . "</script>\n";
	}

	/**
	 * Emit Review nodes + an AggregateRating for the rated testimonials rendered
	 * on a singular page. Rather than a second, fully-typed standalone entity,
	 * this node reuses the SAME `@id` and `@type` as the page's main Place/Service
	 * node (Module::build_schema(), wp_head) — see Module::entity_id() /
	 * entity_type() — so consumers merge the aggregateRating + reviews INTO the
	 * main entity instead of seeing two unlinked nodes for one page. Independent
	 * of Module::print_schema(); same safe encoding (no JSON_UNESCAPED_SLASHES +
	 * `</` -> `<\/` guard) so a literal "</script>" in a quote can't break out of
	 * the inline JSON-LD tag.
	 */
	public function print_review_schema() {
		if ( empty( $this->review_items ) || ! \is_singular() ) { return; }

		$reviews = [];
		$sum     = 0;
		$count   = 0;
		foreach ( $this->review_items as $r ) {
			$count++;
			$sum += (int) $r['rating'];
			$reviews[] = [
				'@type'        => 'Review',
				'author'       => [ '@type' => 'Person', 'name' => $r['author'] !== '' ? $r['author'] : \__( 'Anonymous', 'anchor-schema' ) ],
				'reviewBody'   => $r['body'],
				'reviewRating' => [ '@type' => 'Rating', 'ratingValue' => (int) $r['rating'], 'bestRating' => 5 ],
			];
		}
		if ( ! $count ) { return; }

		$post_id = (int) \get_queried_object_id();

		$doc = [
			'@context'        => 'https://schema.org',
			'@type'           => Module::entity_type( $post_id ),
			'@id'             => Module::entity_id( $post_id ),
			'aggregateRating' => [
				'@type'       => 'AggregateRating',
				'ratingValue' => \round( $sum / $count, 1 ),
				'reviewCount' => $count,
				'bestRating'  => 5,
			],
			'review'          => $reviews,
		];

		$json = \wp_json_encode( $doc, JSON_UNESCAPED_UNICODE );
		$json = \str_replace( '</', '<\/', $json );
		echo "\n<script type=\"application/ld+json\">" . $json . "</script>\n";
	}

	/* ---- Admin: metaboxes, save, assets, columns ---- */

	public function add_metaboxes() {
		foreach ( $this->cpts() as $cpt ) {
			\add_meta_box( 'al_lib_details', \__( 'Details', 'anchor-schema' ), [ $this, 'render_details_metabox' ], $cpt, 'normal', 'high' );
			\add_meta_box( 'al_lib_assign', \__( 'Assignment', 'anchor-schema' ), [ $this, 'render_assign_metabox' ], $cpt, 'side', 'default' );
		}
	}

	public function render_details_metabox( $post ) {
		\wp_nonce_field( self::NONCE, self::NONCE );
		$g = function ( $k ) use ( $post ) { return \get_post_meta( $post->ID, $k, true ); };

		if ( $post->post_type === self::CPT_PROJECT ) {
			echo '<p><label>' . \esc_html__( 'Image URL', 'anchor-schema' ) . '<br><input type="text" name="al_image" value="' . \esc_attr( $g( 'al_image' ) ) . '" class="widefat al-media"></label></p>';
			echo '<p class="description">' . \esc_html__( 'Falls back to the featured image if left blank.', 'anchor-schema' ) . '</p>';
			echo '<p><label>' . \esc_html__( 'Description', 'anchor-schema' ) . '<br><textarea name="al_description" class="widefat" rows="5">' . \esc_textarea( $g( 'al_description' ) ) . '</textarea></label></p>';
		} elseif ( $post->post_type === self::CPT_TESTIMONIAL ) {
			echo '<p><label>' . \esc_html__( 'Quote', 'anchor-schema' ) . '<br><textarea name="al_quote" class="widefat" rows="4">' . \esc_textarea( $g( 'al_quote' ) ) . '</textarea></label></p>';
			echo '<p><label>' . \esc_html__( 'Author', 'anchor-schema' ) . '<br><input type="text" name="al_author" value="' . \esc_attr( $g( 'al_author' ) ) . '" class="widefat"></label></p>';
			$rating = (int) $g( 'al_rating' );
			echo '<p><label>' . \esc_html__( 'Rating (1–5, optional)', 'anchor-schema' ) . '<br><select name="al_rating" class="widefat">';
			echo '<option value="0" ' . \selected( $rating, 0, false ) . '>' . \esc_html__( '— none —', 'anchor-schema' ) . '</option>';
			for ( $i = 1; $i <= 5; $i++ ) { echo '<option value="' . $i . '" ' . \selected( $rating, $i, false ) . '>' . $i . '</option>'; }
			echo '</select></label></p>';
		} else { // FAQ
			echo '<p><label>' . \esc_html__( 'Question', 'anchor-schema' ) . '<br><input type="text" name="al_question" value="' . \esc_attr( $g( 'al_question' ) ) . '" class="widefat"></label></p>';
			echo '<p class="description">' . \esc_html__( 'Falls back to the post title if left blank.', 'anchor-schema' ) . '</p>';
			echo '<p><label>' . \esc_html__( 'Answer', 'anchor-schema' ) . '<br><textarea name="al_answer" class="widefat" rows="5">' . \esc_textarea( $g( 'al_answer' ) ) . '</textarea></label></p>';
		}
	}

	public function render_assign_metabox( $post ) {
		$assigned = \get_post_meta( $post->ID, 'al_location_ids', true );
		$assigned = \is_array( $assigned ) ? \array_map( 'intval', $assigned ) : [];
		$global   = \get_post_meta( $post->ID, 'al_global', true ) === '1';

		echo '<p><label><input type="checkbox" name="al_global" value="1" ' . \checked( $global, true, false ) . '> ' . \esc_html__( 'Show on every page (global)', 'anchor-schema' ) . '</label></p>';
		echo '<p><strong>' . \esc_html__( 'Locations', 'anchor-schema' ) . '</strong><br><span class="description">' . \esc_html__( 'Applies to the selected location and all its descendants.', 'anchor-schema' ) . '</span></p>';

		$locations = \get_posts( [ 'post_type' => Module::CPT_LOCATION, 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ] );
		if ( $locations ) {
			echo '<select name="al_location_ids[]" multiple size="8" class="widefat">';
			foreach ( $locations as $loc ) {
				echo '<option value="' . (int) $loc->ID . '" ' . ( \in_array( (int) $loc->ID, $assigned, true ) ? 'selected' : '' ) . '>' . \esc_html( \get_the_title( $loc ) ) . '</option>';
			}
			echo '</select>';
		} else {
			echo '<p class="description">' . \esc_html__( 'No published locations yet.', 'anchor-schema' ) . '</p>';
		}
		echo '<p class="description">' . \esc_html__( 'Use the Services box to also target a service.', 'anchor-schema' ) . '</p>';
	}

	public function save_meta( $post_id ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! \wp_verify_nonce( $_POST[ self::NONCE ], self::NONCE ) ) { return; }
		if ( \defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
		if ( ! \current_user_can( 'edit_post', $post_id ) ) { return; }

		// Text fields.
		if ( isset( $_POST['al_author'] ) )   { \update_post_meta( $post_id, 'al_author', \sanitize_text_field( \wp_unslash( $_POST['al_author'] ) ) ); }
		if ( isset( $_POST['al_question'] ) ) { \update_post_meta( $post_id, 'al_question', \sanitize_text_field( \wp_unslash( $_POST['al_question'] ) ) ); }
		if ( isset( $_POST['al_image'] ) )    { \update_post_meta( $post_id, 'al_image', \esc_url_raw( \wp_unslash( $_POST['al_image'] ) ) ); }

		// Rich text fields (wp_kses_post).
		foreach ( [ 'al_description', 'al_quote', 'al_answer' ] as $k ) {
			if ( isset( $_POST[ $k ] ) ) { \update_post_meta( $post_id, $k, \wp_kses_post( \wp_unslash( $_POST[ $k ] ) ) ); }
		}

		// Rating: int clamped to 1–5, 0 = none.
		if ( isset( $_POST['al_rating'] ) ) {
			$r = (int) $_POST['al_rating'];
			if ( $r < 1 || $r > 5 ) { $r = ( $r > 5 ) ? 5 : 0; }
			\update_post_meta( $post_id, 'al_rating', $r );
		}

		// Assignment: location ids (array of positive ints), global flag.
		$loc_ids = [];
		if ( isset( $_POST['al_location_ids'] ) && \is_array( $_POST['al_location_ids'] ) ) {
			foreach ( $_POST['al_location_ids'] as $v ) {
				$v = (int) $v;
				if ( $v > 0 ) { $loc_ids[] = $v; }
			}
		}
		\update_post_meta( $post_id, 'al_location_ids', $loc_ids );
		\update_post_meta( $post_id, 'al_global', isset( $_POST['al_global'] ) ? '1' : '' );
	}

	public function admin_assets( $hook ) {
		if ( $hook !== 'post.php' && $hook !== 'post-new.php' ) { return; }
		$screen = \get_current_screen();
		if ( ! $screen || ! \in_array( $screen->post_type, $this->cpts(), true ) ) { return; }
		\wp_enqueue_media(); // .al-media picker (project image)
		$dir = ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-locations/assets/';
		\wp_enqueue_style( 'anchor-locations-admin', \Anchor_Asset_Loader::url( 'anchor-locations/assets/admin.css' ), [], (string) \filemtime( $dir . 'admin.css' ) );
		\wp_enqueue_script( 'anchor-locations-admin', \Anchor_Asset_Loader::url( 'anchor-locations/assets/admin.js' ), [ 'jquery' ], (string) \filemtime( $dir . 'admin.js' ), true );
	}

	public function admin_columns( $cols ) {
		$cols['al_assign'] = \__( 'Assignment', 'anchor-schema' );
		return $cols;
	}

	public function admin_column( $col, $post_id ) {
		if ( $col !== 'al_assign' ) { return; }
		$parts = [];
		$terms = \wp_get_object_terms( $post_id, self::TAX_SERVICE, [ 'fields' => 'names' ] );
		if ( ! \is_wp_error( $terms ) && $terms ) { $parts[] = \implode( ', ', $terms ); }
		$loc_ids = \get_post_meta( $post_id, 'al_location_ids', true );
		if ( \is_array( $loc_ids ) && $loc_ids ) {
			$names = [];
			foreach ( $loc_ids as $lid ) { $t = \get_the_title( (int) $lid ); if ( $t ) { $names[] = $t; } }
			if ( $names ) { $parts[] = \implode( ', ', $names ); }
		}
		if ( \get_post_meta( $post_id, 'al_global', true ) === '1' ) { $parts[] = \__( 'Global', 'anchor-schema' ); }
		echo \esc_html( $parts ? \implode( ' · ', $parts ) : \__( '— unassigned —', 'anchor-schema' ) );
	}
}
