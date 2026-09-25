<?php
/**
 * Speakers <-> Anchor Events integration (Task 10).
 *
 * Loaded ONLY when \Anchor\Events\Module exists (instantiated inside the
 * class_exists() block in Anchor_Speakers_Module::__construct()), so the
 * speakers module has no hard dependency on the events module. Mirrors the
 * nullable-collaborator pattern the events module itself uses for its own
 * optional WooCommerce integration (Module::$product_sync).
 *
 * Responsibility: let an event author pick and order the speakers appearing
 * on one event, make that list inherit to occurrence children through the
 * same allow-list every other shared event fact goes through, and publish it
 * as schema.org `performer` entries.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Anchor_Speaker_Events {

	/** Event meta: ordered speaker post ids linked to the event. */
	const META_KEY = '_anchor_event_speaker_ids';

	/**
	 * The UNPREFIXED key added to the anchor_events_inherited_keys filter
	 * (same convention as every entry in Occurrences::INHERITED_KEYS).
	 * meta_key( self::INHERITED_KEY ) === self::META_KEY.
	 */
	const INHERITED_KEY = 'speaker_ids';

	const NONCE = 'anchor_speaker_events_nonce';

	public function __construct() {
		add_filter( 'anchor_events_inherited_keys', [ $this, 'inherited_keys' ] );
		add_filter( 'anchor_events_schema_node', [ $this, 'schema_node' ], 10, 2 );
		add_action( 'add_meta_boxes_' . \Anchor\Events\Module::CPT, [ $this, 'add_meta_box' ] );
		// Priority 5: Module::save_meta() is hooked on the same action at the
		// default priority 10 and runs persist_group_authoring() -> reconcile(),
		// which copies this module's own META_KEY to occurrence children as
		// one of the INHERITED_KEYS. This save() must write the new speaker
		// list to the parent BEFORE that copy runs, or a speaker change
		// reaches the children one save late (see class docblock and the
		// final whole-branch review finding this fixes).
		add_action( 'save_post_' . \Anchor\Events\Module::CPT, [ $this, 'save' ], 5 );
		add_action( 'admin_enqueue_scripts', [ $this, 'admin_assets' ] );
	}

	/**
	 * Adds this module's own key to the shared-fact allow-list, unprefixed,
	 * exactly like every entry already in Occurrences::INHERITED_KEYS.
	 * Occurrences::inherited_meta_keys() is the single place that prefixes
	 * it (to self::META_KEY).
	 *
	 * @param array $keys
	 * @return array
	 */
	public function inherited_keys( $keys ) {
		$keys   = (array) $keys;
		$keys[] = self::INHERITED_KEY;
		return $keys;
	}

	/* ------------------------------------------------------------------
	   Resolution: an event's own linked speakers, falling back to the
	   group parent's when an occurrence child has none of its own.
	   ------------------------------------------------------------------ */

	/**
	 * @param int $event_id
	 * @return int[] Ordered, published-or-not speaker post ids as stored.
	 */
	public static function event_speaker_ids( $event_id ) {
		$event_id = (int) $event_id;
		if ( $event_id <= 0 ) {
			return [];
		}

		$ids = self::sanitized_ids( \get_post_meta( $event_id, self::META_KEY, true ) );
		if ( ! empty( $ids ) ) {
			return $ids;
		}

		$module = \Anchor\Events\Module::instance();
		if ( ! $module || ! $module->occurrences->is_group_child( $event_id ) ) {
			return [];
		}

		$parent_id = $module->occurrences->parent_of( $event_id );
		if ( $parent_id <= 0 ) {
			return [];
		}

		return self::sanitized_ids( \get_post_meta( $parent_id, self::META_KEY, true ) );
	}

	/**
	 * @param mixed $raw
	 * @return int[]
	 */
	private static function sanitized_ids( $raw ) {
		if ( ! is_array( $raw ) ) {
			return [];
		}
		return array_values( array_filter( array_map( 'absint', $raw ) ) );
	}

	/**
	 * Filters a list of ids down to the ones that are actually published
	 * `anchor_speaker` posts, preserving the submitted order. Used at save
	 * time so a stale/removed speaker, a draft, or an id that never was a
	 * speaker (a page, a bogus id) never gets written into event meta.
	 *
	 * @param int[] $ids
	 * @return int[]
	 */
	private static function published_speaker_ids( array $ids ) {
		$valid = [];
		foreach ( $ids as $id ) {
			if ( get_post_type( $id ) === Anchor_Speakers_Module::CPT && get_post_status( $id ) === 'publish' ) {
				$valid[] = $id;
			}
		}
		return $valid;
	}

	/* ------------------------------------------------------------------
	   Admin metabox
	   ------------------------------------------------------------------ */

	public function add_meta_box() {
		add_meta_box(
			'anchor_speaker_events',
			__( 'Speakers', 'anchor-schema' ),
			[ $this, 'render_metabox' ],
			\Anchor\Events\Module::CPT,
			'side',
			'default'
		);
	}

	public function render_metabox( $post ) {
		wp_nonce_field( 'anchor_speaker_events', self::NONCE );

		$selected = self::sanitized_ids( get_post_meta( $post->ID, self::META_KEY, true ) );
		$speakers = get_posts( [
			'post_type'      => Anchor_Speakers_Module::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
			'orderby'        => 'title',
			'order'          => 'ASC',
		] );

		$titles = [];
		foreach ( $speakers as $speaker ) {
			$titles[ $speaker->ID ] = $speaker->post_title;
		}
		?>
		<div class="anchor-speaker-events" data-nonce-field="<?php echo esc_attr( self::NONCE ); ?>">
			<ul class="anchor-speaker-events__list">
				<?php foreach ( $selected as $id ) :
					if ( ! isset( $titles[ $id ] ) ) continue;
					?>
					<li class="anchor-speaker-events__row">
						<span class="anchor-speaker-events__name"><?php echo esc_html( $titles[ $id ] ); ?></span>
						<input type="hidden" name="anchor_event_speaker_ids[]" value="<?php echo esc_attr( $id ); ?>" />
						<button type="button" class="button-link anchor-speaker-events__up" aria-label="<?php esc_attr_e( 'Move up', 'anchor-schema' ); ?>">&#8593;</button>
						<button type="button" class="button-link anchor-speaker-events__down" aria-label="<?php esc_attr_e( 'Move down', 'anchor-schema' ); ?>">&#8595;</button>
						<button type="button" class="button-link anchor-speaker-events__remove" aria-label="<?php esc_attr_e( 'Remove', 'anchor-schema' ); ?>">&times;</button>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php if ( empty( $speakers ) ) : ?>
				<p class="description"><?php esc_html_e( 'No published speakers yet.', 'anchor-schema' ); ?></p>
			<?php else : ?>
				<p>
					<select class="anchor-speaker-events__picker">
						<option value=""><?php esc_html_e( 'Add a speaker…', 'anchor-schema' ); ?></option>
						<?php foreach ( $speakers as $speaker ) : ?>
							<option value="<?php echo esc_attr( $speaker->ID ); ?>" data-name="<?php echo esc_attr( $speaker->post_title ); ?>"><?php echo esc_html( $speaker->post_title ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="button" class="button anchor-speaker-events__add"><?php esc_html_e( 'Add', 'anchor-schema' ); ?></button>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	public function save( $post_id ) {
		$post_id = (int) $post_id;

		$nonce = isset( $_POST[ self::NONCE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'anchor_speaker_events' ) ) {
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

		$ids = isset( $_POST['anchor_event_speaker_ids'] ) && is_array( $_POST['anchor_event_speaker_ids'] )
			? self::sanitized_ids( wp_unslash( $_POST['anchor_event_speaker_ids'] ) )
			: [];
		$ids = self::published_speaker_ids( $ids );

		if ( empty( $ids ) ) {
			delete_post_meta( $post_id, self::META_KEY );
		} else {
			update_post_meta( $post_id, self::META_KEY, $ids );
		}
	}

	/**
	 * Enqueued only on the event add/edit screen, jQuery-based (no build step
	 * for a repeatable ordered list this small).
	 */
	public function admin_assets( $hook ) {
		if ( ! in_array( $hook, [ 'post-new.php', 'post.php' ], true ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->post_type !== \Anchor\Events\Module::CPT ) {
			return;
		}

		$path = ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-speakers/assets/speaker-events-admin.js';
		wp_enqueue_script(
			'anchor-speaker-events-admin',
			Anchor_Asset_Loader::url( 'anchor-speakers/assets/speaker-events-admin.js' ),
			[ 'jquery' ],
			file_exists( $path ) ? filemtime( $path ) : false,
			true
		);
	}

	/* ------------------------------------------------------------------
	   schema.org/Event JSON-LD: performer
	   ------------------------------------------------------------------ */

	/**
	 * @param array $node
	 * @param int   $event_id
	 * @return array
	 */
	public function schema_node( $node, $event_id ) {
		$node = (array) $node;

		$ids = Anchor_Speakers_Module::event_speaker_ids( (int) $event_id );
		if ( empty( $ids ) ) {
			return $node;
		}

		$performers = [];
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id <= 0
				|| get_post_type( $id ) !== Anchor_Speakers_Module::CPT
				|| get_post_status( $id ) !== 'publish'
			) {
				continue;
			}

			$performer = [
				'@type' => 'Person',
				'name'  => get_the_title( $id ),
				'url'   => get_permalink( $id ),
			];

			$image = get_the_post_thumbnail_url( $id, 'large' );
			if ( $image ) {
				$performer['image'] = $image;
			}

			$title = Anchor_Speaker_Meta::get( $id )['title'];
			if ( $title !== '' ) {
				$performer['jobTitle'] = $title;
			}

			$performers[] = $performer;
		}

		if ( empty( $performers ) ) {
			return $node;
		}

		$existing = [];
		if ( isset( $node['performer'] ) && is_array( $node['performer'] ) ) {
			// A single existing performer node (associative, has its own
			// @type) is normalized to a one-item list before appending.
			$existing = isset( $node['performer']['@type'] ) ? [ $node['performer'] ] : $node['performer'];
		}

		$node['performer'] = array_merge( $existing, $performers );

		return $node;
	}
}
