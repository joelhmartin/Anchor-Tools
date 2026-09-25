<?php
/**
 * Anchor Tools module: Anchor Speakers.
 * Speaker/faculty CPT with a configurable URL base and optional Events linkage.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-speaker-meta.php';
require_once __DIR__ . '/class-speaker-render.php';

class Anchor_Speakers_Module {
	const CPT              = 'anchor_speaker';
	const OPTION           = 'anchor_speakers_options';
	const SIGNATURE_OPTION = 'anchor_speakers_rules_signature';

	/** @var Anchor_Speaker_Events|null Null when the events module is inactive. */
	public $events = null;

	public static function options() {
		$o = get_option( self::OPTION, [] );
		return wp_parse_args( is_array( $o ) ? $o : [], [ 'base' => 'speakers', 'archive' => false ] );
	}

	public static function base() {
		$b = trim( sanitize_title_with_dashes( (string) self::options()['base'] ), '/' );
		return $b !== '' ? $b : 'speakers';
	}

	public function __construct() {
		add_action( 'init', [ $this, 'register' ] );
		add_action( 'init', [ $this, 'maybe_flush' ], 99 );
		add_filter( 'request', [ $this, 'page_fallback' ] );
		add_action( 'admin_menu', [ $this, 'settings_menu' ] );
		add_action( 'admin_init', [ $this, 'settings_register' ] );
		add_filter( 'single_template', [ $this, 'single_template' ] );
		add_shortcode( 'anchor_speakers', [ $this, 'shortcode' ] );
		add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
		add_action( 'save_post_' . self::CPT, [ $this, 'save' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue_frontend_assets' ] );

		// Optional Events linkage (Task 10). Loaded only when the events
		// module is active, mirroring the events module's own nullable
		// collaborator pattern for its optional WooCommerce integration.
		if ( class_exists( '\\Anchor\\Events\\Module' ) ) {
			require_once __DIR__ . '/class-speaker-events.php';
			$this->events = new Anchor_Speaker_Events();
		}
	}

	/**
	 * Ordered speaker ids linked to an event, resolving a group child with no
	 * list of its own to its group parent's list. Returns an empty array
	 * when the events module isn't active. Added in Task 10; the
	 * method_exists() guard around this call in query_speakers() below
	 * predates it and stays as a defensive check for a partial rollout.
	 *
	 * @param int $event_id
	 * @return int[]
	 */
	public static function event_speaker_ids( $event_id ) {
		return class_exists( 'Anchor_Speaker_Events' ) ? Anchor_Speaker_Events::event_speaker_ids( (int) $event_id ) : [];
	}

	public function register() {
		register_post_type( self::CPT, [
			'labels'       => [
				'name'          => __( 'Speakers', 'anchor-schema' ),
				'singular_name' => __( 'Speaker', 'anchor-schema' ),
				'add_new_item'  => __( 'Add New Speaker', 'anchor-schema' ),
				'edit_item'     => __( 'Edit Speaker', 'anchor-schema' ),
			],
			'public'       => true,
			'show_in_menu' => apply_filters( 'anchor_speakers_parent_menu', true ),
			'menu_icon'    => 'dashicons-businessperson',
			'supports'     => [ 'title', 'editor', 'excerpt', 'thumbnail', 'page-attributes', 'revisions' ],
			'has_archive'  => (bool) self::options()['archive'],
			'rewrite'      => [ 'slug' => self::base(), 'with_front' => false ],
			'show_in_rest' => true,
		] );
	}

	/**
	 * When the configured base matches a real page's path, the CPT's rewrite
	 * rules sit above the page rules and would otherwise swallow requests for
	 * that page's own non-speaker children (base/child-slug). This checks
	 * whether the matched request is an actual speaker first; if not, and a
	 * page exists at that same path, it hands the request back to that page.
	 *
	 * Reads the raw matched path from $wp->request (set by WP before this
	 * filter runs) rather than the query vars alone: a request nested two
	 * segments below the base (base/child/grandchild) matches WordPress' own
	 * auto-generated "attachment under this post type" rewrite rule, which
	 * only exposes the last segment as `attachment`, discarding the middle
	 * one, so the full path is not otherwise recoverable here.
	 */
	public function page_fallback( $vars ) {
		global $wp;
		$base    = self::base();
		$request = isset( $wp->request ) ? trim( (string) $wp->request, '/' ) : '';
		if ( $request === '' || strpos( $request, $base . '/' ) !== 0 ) return $vars;
		$path = substr( $request, strlen( $base ) + 1 );
		if ( $path === '' ) return $vars;

		if ( strpos( $path, '/' ) === false
			&& ! empty( $vars['post_type'] ) && $vars['post_type'] === self::CPT
			&& ! empty( $vars[ self::CPT ] )
			// A STRING $post_type makes WP core's get_page_by_path() also
			// match 'attachment' posts of the same slug (it does
			// `array( $post_type, 'attachment' )` internally); an array
			// with only this CPT is the only way to exclude that.
			&& get_page_by_path( $path, OBJECT, [ self::CPT ] )
		) {
			return $vars;
		}

		// Same reasoning: without an array here, an attachment (e.g. an
		// image) whose slug happens to equal $base . '/' . $path would be
		// treated as "a real page exists here" and this request would be
		// handed to a page that does not actually exist.
		$page = get_page_by_path( $base . '/' . $path, OBJECT, [ 'page' ] );
		if ( ! $page ) return $vars;
		return [ 'pagename' => $base . '/' . $path ];
	}

	/**
	 * One-time rewrite flush keyed on a signature of the settings that shape
	 * the rewrite rules (base + archive flag), rather than a save-triggered
	 * flag: this also covers the module's first load (no stored signature
	 * yet) and any base change made outside the settings page (e.g. a
	 * direct update_option() call, as tests do), not just a settings-page
	 * save. A settings-page save still flushes here too, since the option
	 * is already updated by the time this runs on the next request.
	 */
	public function maybe_flush() {
		$signature = self::rules_signature();
		if ( get_option( self::SIGNATURE_OPTION ) === $signature ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( self::SIGNATURE_OPTION, $signature, false );
	}

	private static function rules_signature() {
		$o = self::options();
		return md5( self::base() . '|' . ( $o['archive'] ? '1' : '0' ) );
	}

	public function settings_menu() {
		add_options_page( __( 'Anchor Speakers', 'anchor-schema' ), __( 'Anchor Speakers', 'anchor-schema' ), 'manage_options', 'anchor-speakers', [ $this, 'settings_page' ] );
	}

	public function settings_register() {
		register_setting( 'anchor_speakers', self::OPTION, [ 'sanitize_callback' => [ $this, 'sanitize' ] ] );
	}

	/**
	 * No explicit flush flag here: maybe_flush() on the next 'init' compares
	 * a signature of the (already-updated) option against what it last
	 * flushed for, and flushes when they differ. That covers a settings-page
	 * save the same way it covers any other change to the option.
	 */
	public function sanitize( $in ) {
		if ( function_exists( 'wp_set_option_autoload' ) ) {
			wp_set_option_autoload( self::OPTION, false );
		}
		return [
			'base'    => sanitize_title_with_dashes( $in['base'] ?? 'speakers' ) ?: 'speakers',
			'archive' => ! empty( $in['archive'] ),
		];
	}

	public function settings_page() {
		$o = self::options();
		echo '<div class="wrap"><h1>' . esc_html__( 'Anchor Speakers', 'anchor-schema' ) . '</h1><form method="post" action="options.php">';
		settings_fields( 'anchor_speakers' );
		echo '<table class="form-table"><tr><th><label for="as-base">' . esc_html__( 'URL base', 'anchor-schema' ) . '</label></th><td><code>' . esc_html( home_url( '/' ) ) . '</code><input id="as-base" name="' . esc_attr( self::OPTION ) . '[base]" value="' . esc_attr( $o['base'] ) . '" class="regular-text"><code>/speaker-name/</code><p class="description">' . esc_html__( 'May match an existing page path (for example about-us); child pages that are not speakers keep working.', 'anchor-schema' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Archive page', 'anchor-schema' ) . '</th><td><label><input type="checkbox" name="' . esc_attr( self::OPTION ) . '[archive]" value="1" ' . checked( $o['archive'], true, false ) . '> ' . esc_html__( 'Enable an archive at the base URL', 'anchor-schema' ) . '</label></td></tr></table>';
		submit_button();
		echo '</form></div>';
	}

	/* ------------------------------------------------------------------
	   Single template fallback
	   ------------------------------------------------------------------ */

	/**
	 * Ships a minimal fallback template only when the active theme has no
	 * single-anchor_speaker.php of its own.
	 */
	public function single_template( $template ) {
		if ( get_post_type() === self::CPT ) {
			$theme_template = locate_template( 'single-anchor_speaker.php' );
			if ( ! $theme_template ) {
				return __DIR__ . '/templates/single-anchor_speaker.php';
			}
		}
		return $template;
	}

	/* ------------------------------------------------------------------
	   Admin metabox
	   ------------------------------------------------------------------ */

	public function add_meta_boxes() {
		add_meta_box(
			'anchor_speaker_details',
			__( 'Speaker details', 'anchor-schema' ),
			[ $this, 'render_metabox' ],
			self::CPT,
			'normal',
			'high'
		);
	}

	public function render_metabox( $post ) {
		wp_nonce_field( 'anchor_speakers_meta', 'anchor_speakers_meta_nonce' );
		$m = Anchor_Speaker_Meta::get( $post->ID );
		?>
		<table class="form-table anchor-speaker-fields">
			<tr>
				<th><label for="as_credentials"><?php esc_html_e( 'Credentials', 'anchor-schema' ); ?></label></th>
				<td><input type="text" id="as_credentials" name="anchor_speaker[credentials]" value="<?php echo esc_attr( $m['credentials'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. DDS, MS, DABCP', 'anchor-schema' ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="as_title"><?php esc_html_e( 'Title', 'anchor-schema' ); ?></label></th>
				<td><input type="text" id="as_title" name="anchor_speaker[title]" value="<?php echo esc_attr( $m['title'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Founder', 'anchor-schema' ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="as_location"><?php esc_html_e( 'Location', 'anchor-schema' ); ?></label></th>
				<td><input type="text" id="as_location" name="anchor_speaker[location]" value="<?php echo esc_attr( $m['location'] ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="as_featured"><?php esc_html_e( 'Featured', 'anchor-schema' ); ?></label></th>
				<td><label><input type="checkbox" id="as_featured" name="anchor_speaker[featured]" value="1" <?php checked( $m['featured'] ); ?> /> <?php esc_html_e( 'Show in featured speakers', 'anchor-schema' ); ?></label></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Links', 'anchor-schema' ); ?></th>
				<td>
					<?php for ( $i = 0; $i < 5; $i++ ) :
						$row = $m['links'][ $i ] ?? [ 'label' => '', 'url' => '' ];
						?>
						<p>
							<input type="text" name="anchor_speaker[links][<?php echo esc_attr( $i ); ?>][label]" value="<?php echo esc_attr( $row['label'] ); ?>" placeholder="<?php esc_attr_e( 'Label', 'anchor-schema' ); ?>" class="regular-text" style="width:180px;" />
							<input type="url" name="anchor_speaker[links][<?php echo esc_attr( $i ); ?>][url]" value="<?php echo esc_attr( $row['url'] ); ?>" placeholder="https://" class="regular-text" style="width:320px;" />
						</p>
					<?php endfor; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	public function save( $post_id ) {
		$nonce = isset( $_POST['anchor_speakers_meta_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['anchor_speakers_meta_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'anchor_speakers_meta' ) ) {
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
		Anchor_Speaker_Meta::save( $post_id, wp_unslash( $_POST['anchor_speaker'] ?? [] ) );
	}

	/* ------------------------------------------------------------------
	   Shortcode and front-end assets
	   ------------------------------------------------------------------ */

	/**
	 * [anchor_speakers] shortcode. Ordering: `ids` order when given, event
	 * order when `event` resolves (via Anchor_Speakers_Module::event_speaker_ids(),
	 * added in Task 10 and only called when it exists), else menu_order ASC,
	 * title ASC. Renders nothing when the query is empty, and only enqueues
	 * front-end assets when there is something to render.
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts( [
			'featured' => '',
			'ids'      => '',
			'event'    => '',
			'layout'   => 'grid',
			'columns'  => 3,
			'limit'    => -1,
			'link'     => 1,
			'show'     => 'credentials,title,excerpt',
		], $atts, 'anchor_speakers' );

		$posts = $this->query_speakers( $atts );
		if ( empty( $posts ) ) {
			return '';
		}

		$this->enqueue_frontend_assets();

		return Anchor_Speaker_Render::html( $posts, $atts );
	}

	private function query_speakers( array $atts ) {
		$ids = array_values( array_filter( array_map( 'absint', explode( ',', (string) $atts['ids'] ) ) ) );

		if ( empty( $ids ) && (string) $atts['event'] !== '' && method_exists( __CLASS__, 'event_speaker_ids' ) ) {
			$event_id = $atts['event'] === 'current' ? get_queried_object_id() : absint( $atts['event'] );
			$ids      = $event_id ? self::event_speaker_ids( $event_id ) : [];
			// An event scope that resolves to no speakers (a real event with
			// none linked, an invalid event id, or the Events module being
			// inactive) must render nothing, not fall through to every
			// published speaker.
			if ( empty( $ids ) ) {
				return [];
			}
		}

		$args = [
			'post_type'      => self::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => (int) $atts['limit'],
			'no_found_rows'  => true,
			'meta_query'     => [],
		];

		if ( (string) $atts['featured'] === '1' ) {
			$args['meta_query'][] = [ 'key' => '_as_featured', 'value' => '1' ];
		}

		if ( ! empty( $ids ) ) {
			$args['post__in'] = $ids;
			$args['orderby']  = 'post__in';
		} else {
			$args['orderby'] = [ 'menu_order' => 'ASC', 'title' => 'ASC' ];
		}

		return get_posts( $args );
	}

	/**
	 * Only fires the front-end enqueue on a singular speaker page (the
	 * fallback single template); the shortcode enqueues directly from its
	 * own handler (not hooked on wp_enqueue_scripts) so nothing loads on
	 * pages that never render a speaker.
	 */
	public function maybe_enqueue_frontend_assets() {
		if ( is_singular( self::CPT ) ) {
			$this->enqueue_frontend_assets();
		}
	}

	public function enqueue_frontend_assets() {
		$css_path = ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-speakers/assets/speakers.css';
		wp_enqueue_style(
			'anchor-speakers',
			Anchor_Asset_Loader::url( 'anchor-speakers/assets/speakers.css' ),
			[],
			file_exists( $css_path ) ? filemtime( $css_path ) : false
		);
	}
}
