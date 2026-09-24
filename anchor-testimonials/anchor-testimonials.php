<?php
/**
 * Anchor Tools module: Anchor Testimonials.
 * Authored testimonials (quote and/or video) with an audience taxonomy and a
 * "related to" list of post IDs used to scope them to courses, pages or speakers.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-testimonial-meta.php';
require_once __DIR__ . '/class-testimonial-query.php';

class Anchor_Testimonials_Module {
	const CPT         = 'anchor_testimonial';
	const TAX         = 'anchor_testimonial_audience';
	const META_PREFIX = '_at_';

	public function __construct() {
		add_action( 'init', [ $this, 'register' ] );
		add_action( 'init', [ $this, 'seed_terms' ], 20 );
		add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
		add_action( 'save_post_' . self::CPT, [ $this, 'save' ] );
		add_action( 'admin_notices', [ $this, 'incomplete_notice' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		add_action( 'wp_ajax_anchor_testimonials_search_posts', [ $this, 'ajax_search_posts' ] );

		add_filter( 'manage_' . self::CPT . '_posts_columns', [ $this, 'admin_columns' ] );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', [ $this, 'render_admin_column' ], 10, 2 );
	}

	public function register() {
		register_post_type( self::CPT, [
			'labels'             => [
				'name'          => __( 'Testimonials', 'anchor-schema' ),
				'singular_name' => __( 'Testimonial', 'anchor-schema' ),
				'add_new_item'  => __( 'Add New Testimonial', 'anchor-schema' ),
				'edit_item'     => __( 'Edit Testimonial', 'anchor-schema' ),
			],
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => apply_filters( 'anchor_testimonials_parent_menu', true ),
			'menu_icon'          => 'dashicons-format-quote',
			'supports'           => [ 'title', 'editor', 'thumbnail', 'page-attributes' ],
			'show_in_rest'       => false,
		] );
		register_taxonomy( self::TAX, self::CPT, [
			'labels'            => [ 'name' => __( 'Audiences', 'anchor-schema' ), 'singular_name' => __( 'Audience', 'anchor-schema' ) ],
			'public'            => false,
			'show_ui'           => true,
			'show_admin_column' => true,
			'hierarchical'      => false,
			'rewrite'           => false,
		] );
	}

	public function seed_terms() {
		if ( get_option( 'anchor_testimonials_seeded' ) ) return;
		foreach ( [ 'patient' => __( 'Patient', 'anchor-schema' ), 'doctor' => __( 'Doctor', 'anchor-schema' ) ] as $slug => $name ) {
			if ( ! term_exists( $slug, self::TAX ) ) wp_insert_term( $name, self::TAX, [ 'slug' => $slug ] );
		}
		update_option( 'anchor_testimonials_seeded', 1, false );
	}

	/* ------------------------------------------------------------------
	   Admin metabox
	   ------------------------------------------------------------------ */

	public function add_meta_boxes() {
		add_meta_box(
			'anchor_testimonial_details',
			__( 'Testimonial details', 'anchor-schema' ),
			[ $this, 'render_metabox' ],
			self::CPT,
			'normal',
			'high'
		);
	}

	public function render_metabox( $post ) {
		wp_nonce_field( 'anchor_testimonials_meta', 'anchor_testimonials_meta_nonce' );
		$m = Anchor_Testimonial_Meta::get( $post->ID );
		?>
		<table class="form-table anchor-testimonial-fields">
			<tr>
				<th><label for="at_person_name"><?php esc_html_e( 'Person name', 'anchor-schema' ); ?></label></th>
				<td><input type="text" id="at_person_name" name="anchor_testimonial[person_name]" value="<?php echo esc_attr( $m['person_name'] ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="at_person_meta"><?php esc_html_e( 'Person meta', 'anchor-schema' ); ?></label></th>
				<td><input type="text" id="at_person_meta" name="anchor_testimonial[person_meta]" value="<?php echo esc_attr( $m['person_meta'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Patient, Denver', 'anchor-schema' ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="at_video_url"><?php esc_html_e( 'Video URL', 'anchor-schema' ); ?></label></th>
				<td>
					<input type="url" id="at_video_url" name="anchor_testimonial[video_url]" value="<?php echo esc_attr( $m['video_url'] ); ?>" class="regular-text" placeholder="https://youtu.be/... or https://vimeo.com/..." />
					<div class="anchor-testimonial-video-preview">
						<img id="at_video_thumb" src="<?php echo esc_url( $m['video_thumb'] ); ?>" alt="" <?php echo $m['video_thumb'] ? '' : 'style="display:none;"'; ?> />
					</div>
				</td>
			</tr>
			<tr>
				<th><label for="at_rating"><?php esc_html_e( 'Rating', 'anchor-schema' ); ?></label></th>
				<td>
					<select id="at_rating" name="anchor_testimonial[rating]">
						<?php for ( $i = 0; $i <= 5; $i++ ) : ?>
							<option value="<?php echo esc_attr( $i ); ?>" <?php selected( $m['rating'], $i ); ?>><?php echo esc_html( $i ); ?></option>
						<?php endfor; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="at_featured"><?php esc_html_e( 'Featured', 'anchor-schema' ); ?></label></th>
				<td><label><input type="checkbox" id="at_featured" name="anchor_testimonial[featured]" value="1" <?php checked( $m['featured'] ); ?> /> <?php esc_html_e( 'Show in featured testimonials', 'anchor-schema' ); ?></label></td>
			</tr>
			<tr>
				<th><label for="at_related_search"><?php esc_html_e( 'Related to', 'anchor-schema' ); ?></label></th>
				<td>
					<input type="text" id="at_related_search" class="regular-text" placeholder="<?php esc_attr_e( 'Search pages, courses, speakers...', 'anchor-schema' ); ?>" autocomplete="off" />
					<div id="at_related_results" class="anchor-testimonial-related-results"></div>
					<div id="at_related_chips" class="anchor-testimonial-related-chips">
						<?php foreach ( $m['related'] as $rid ) :
							$rp = get_post( $rid );
							if ( ! $rp ) continue;
							?>
							<span class="anchor-testimonial-chip" data-id="<?php echo esc_attr( $rid ); ?>">
								<?php echo esc_html( get_the_title( $rp ) ); ?>
								<input type="hidden" name="anchor_testimonial[related][]" value="<?php echo esc_attr( $rid ); ?>" />
								<button type="button" class="anchor-testimonial-chip-remove" aria-label="<?php esc_attr_e( 'Remove', 'anchor-schema' ); ?>">&times;</button>
							</span>
						<?php endforeach; ?>
					</div>
				</td>
			</tr>
		</table>
		<?php
	}

	public function save( $post_id ) {
		$nonce = isset( $_POST['anchor_testimonials_meta_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['anchor_testimonials_meta_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'anchor_testimonials_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		Anchor_Testimonial_Meta::save( $post_id, wp_unslash( $_POST['anchor_testimonial'] ?? [] ) );
	}

	public function incomplete_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->base !== 'post' || $screen->post_type !== self::CPT ) {
			return;
		}
		global $post;
		if ( ! $post || $post->post_type !== self::CPT ) {
			return;
		}
		$video_id = get_post_meta( $post->ID, self::META_PREFIX . 'video_id', true );
		if ( trim( (string) $post->post_content ) !== '' || $video_id !== '' ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'This testimonial has no quote text and no video yet.', 'anchor-schema' ) . '</p></div>';
	}

	/* ------------------------------------------------------------------
	   Admin assets and related-post search
	   ------------------------------------------------------------------ */

	public function enqueue_admin_assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->post_type !== self::CPT ) {
			return;
		}

		$base_dir = ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-testimonials/assets/';
		$css_path = $base_dir . 'admin.css';
		$js_path  = $base_dir . 'admin.js';

		wp_enqueue_style( 'anchor-testimonials-admin', Anchor_Asset_Loader::url( 'anchor-testimonials/assets/admin.css' ), [], file_exists( $css_path ) ? filemtime( $css_path ) : false );
		wp_enqueue_script( 'anchor-testimonials-admin', Anchor_Asset_Loader::url( 'anchor-testimonials/assets/admin.js' ), [ 'jquery' ], file_exists( $js_path ) ? filemtime( $js_path ) : false, true );
		wp_localize_script( 'anchor-testimonials-admin', 'ANCHOR_TESTIMONIALS_ADMIN', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'anchor_testimonials_admin' ),
		] );
	}

	/**
	 * AJAX post search backing the related-to chip picker.
	 *
	 * Only ever queries post_status=publish: the picker links a testimonial
	 * to already-live content, and restricting to publish means this endpoint
	 * can never hand a private or draft post to a user who could not
	 * otherwise read it, regardless of their capabilities.
	 */
	public function ajax_search_posts() {
		if ( ! check_ajax_referer( 'anchor_testimonials_admin', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'anchor-schema' ) ], 403 );
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Not allowed.', 'anchor-schema' ) ], 403 );
		}

		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		$post_types = get_post_types( [ 'public' => true ] );
		foreach ( [ 'event', 'anchor_speaker' ] as $extra ) {
			if ( post_type_exists( $extra ) ) {
				$post_types[] = $extra;
			}
		}
		$post_types = array_values( array_unique( array_diff( $post_types, [ 'attachment' ] ) ) );

		$query = new WP_Query( [
			's'              => $search,
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		] );

		$results = [];
		foreach ( $query->posts as $p ) {
			$pt          = get_post_type_object( $p->post_type );
			$results[]   = [
				'id'         => $p->ID,
				'title'      => get_the_title( $p ),
				'type_label' => $pt ? $pt->labels->singular_name : $p->post_type,
			];
		}

		wp_send_json( $results );
	}

	/* ------------------------------------------------------------------
	   Admin list columns
	   ------------------------------------------------------------------ */

	public function admin_columns( $columns ) {
		$new = [];
		foreach ( $columns as $key => $label ) {
			if ( $key === 'title' ) {
				$new['at_thumb'] = __( 'Thumbnail', 'anchor-schema' );
			}
			$new[ $key ] = $label;
			if ( $key === 'title' ) {
				$new['at_person']   = __( 'Person', 'anchor-schema' );
				$new['at_featured'] = __( 'Featured', 'anchor-schema' );
				$new['at_related']  = __( 'Related', 'anchor-schema' );
			}
		}
		return $new;
	}

	public function render_admin_column( $column, $post_id ) {
		switch ( $column ) {
			case 'at_thumb':
				if ( has_post_thumbnail( $post_id ) ) {
					echo get_the_post_thumbnail( $post_id, [ 40, 40 ] );
				} else {
					$thumb = get_post_meta( $post_id, self::META_PREFIX . 'video_thumb', true );
					if ( $thumb ) {
						echo '<img src="' . esc_url( $thumb ) . '" width="40" height="40" style="object-fit:cover;" alt="" />';
					}
				}
				break;
			case 'at_person':
				$name = get_post_meta( $post_id, self::META_PREFIX . 'person_name', true );
				$meta = get_post_meta( $post_id, self::META_PREFIX . 'person_meta', true );
				echo esc_html( $name );
				if ( $meta ) {
					echo '<br /><span class="description">' . esc_html( $meta ) . '</span>';
				}
				break;
			case 'at_featured':
				echo get_post_meta( $post_id, self::META_PREFIX . 'featured', true ) === '1' ? esc_html__( 'Yes', 'anchor-schema' ) : '-';
				break;
			case 'at_related':
				$related = get_post_meta( $post_id, self::META_PREFIX . 'related', true );
				echo esc_html( count( is_array( $related ) ? $related : [] ) );
				break;
		}
	}
}
