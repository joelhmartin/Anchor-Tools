<?php
/**
 * Front-end Store Manager for [anchor_store_manager].
 *
 * Renders a searchable, sortable, paginated CRUD table for the anchor_store CPT
 * and serves the AJAX endpoints behind it.
 *
 * @package Anchor_Tools
 */

namespace Anchor\StoreLocator;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Store_Manager {

	const NONCE            = 'anchor_store_manager';
	const DEFAULT_PER_PAGE = 20;
	const MAX_PER_PAGE     = 200;
	const ASSET_VERSION    = '2.0.0';

	/** Meta keys included in the manager's free-text search. */
	const SEARCH_META_KEYS = [
		'_anchor_store_address',
		'_anchor_store_phone',
		'_anchor_store_email',
		'_anchor_store_website',
		'_anchor_store_owner',
	];

	/** @var Module */
	private $module;

	/** @var int Incremented per rendered shortcode so multiple instances never collide. */
	private $instance = 0;

	public function __construct( Module $module ) {
		$this->module = $module;

		\add_shortcode( 'anchor_store_manager', [ $this, 'render_shortcode' ] );

		\add_action( 'wp_ajax_anchor_store_manager_list', [ $this, 'ajax_list' ] );
		\add_action( 'wp_ajax_anchor_store_manager_get', [ $this, 'ajax_get' ] );
		\add_action( 'wp_ajax_anchor_store_manager_save', [ $this, 'ajax_save' ] );
		\add_action( 'wp_ajax_anchor_store_manager_delete', [ $this, 'ajax_delete' ] );
		\add_action( 'wp_ajax_anchor_store_manager_restore', [ $this, 'ajax_restore' ] );
		\add_action( 'wp_ajax_anchor_store_manager_duplicate', [ $this, 'ajax_duplicate' ] );
		\add_action( 'wp_ajax_anchor_store_manager_bulk', [ $this, 'ajax_bulk' ] );

		\add_filter( 'posts_search', [ $this, 'filter_posts_search' ], 10, 2 );
		\add_filter( 'heartbeat_received', [ $this, 'heartbeat_received' ], 10, 2 );
	}

	/* =====================================================================
	   Capabilities
	   ===================================================================== */

	/**
	 * Capability required to use the manager.
	 *
	 * Defaults to edit_posts. Sites that want to restrict the manager to
	 * editors or administrators can filter this to edit_others_posts or
	 * manage_options.
	 */
	public function capability() {
		return (string) \apply_filters( 'anchor_store_manager_capability', 'edit_posts' );
	}

	public function can_manage() {
		return \current_user_can( $this->capability() );
	}

	/**
	 * Guard shared by every AJAX endpoint: verify nonce + capability.
	 */
	private function guard() {
		if ( ! \check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			\wp_send_json_error(
				[
					'message' => \__( 'Your session expired. Please reload the page and try again.', 'anchor-schema' ),
					'code'    => 'expired_nonce',
				],
				403
			);
		}
		if ( ! $this->can_manage() ) {
			\wp_send_json_error(
				[
					'message' => \__( 'You do not have permission to manage stores.', 'anchor-schema' ),
					'code'    => 'no_cap',
				],
				403
			);
		}
	}

	/**
	 * Fetch a store post, ensuring it exists and is the right post type.
	 *
	 * @return \WP_Post
	 */
	private function require_store( $post_id, $cap = 'edit_post' ) {
		$post = \get_post( (int) $post_id );
		if ( ! $post || $post->post_type !== Module::CPT ) {
			\wp_send_json_error( [ 'message' => \__( 'Store not found.', 'anchor-schema' ) ], 404 );
		}
		if ( $cap && ! \current_user_can( $cap, $post->ID ) ) {
			\wp_send_json_error(
				[ 'message' => \__( 'You do not have permission to modify this store.', 'anchor-schema' ) ],
				403
			);
		}
		return $post;
	}

	/* =====================================================================
	   Schema: statuses and columns
	   ===================================================================== */

	/**
	 * Statuses a store may be saved as.
	 *
	 * The edit form's <select> and the server-side whitelist are both built
	 * from this list so they cannot drift apart. (They used to: the list
	 * showed "pending" stores but the form offered only publish/draft, so
	 * editing a pending store silently demoted it to draft.)
	 */
	public function editable_statuses() {
		return [
			'publish' => \__( 'Published', 'anchor-schema' ),
			'draft'   => \__( 'Draft', 'anchor-schema' ),
			'pending' => \__( 'Pending Review', 'anchor-schema' ),
		];
	}

	/** Statuses shown as filter tabs, in order. */
	public function filter_statuses() {
		return \array_merge(
			[ 'any' => \__( 'All', 'anchor-schema' ) ],
			$this->editable_statuses(),
			[ 'trash' => \__( 'Trash', 'anchor-schema' ) ]
		);
	}

	public function available_columns() {
		return [
			'image'    => \__( 'Image', 'anchor-schema' ),
			'name'     => \__( 'Name', 'anchor-schema' ),
			'owner'    => \__( 'Owner', 'anchor-schema' ),
			'address'  => \__( 'Address', 'anchor-schema' ),
			'phone'    => \__( 'Phone', 'anchor-schema' ),
			'email'    => \__( 'Email', 'anchor-schema' ),
			'status'   => \__( 'Status', 'anchor-schema' ),
			'modified' => \__( 'Modified', 'anchor-schema' ),
		];
	}

	/** Columns that can be clicked to sort, mapped to their orderby value. */
	public function sortable_columns() {
		return [
			'name'     => 'title',
			'owner'    => 'owner',
			'modified' => 'modified',
		];
	}

	/**
	 * Resolve the `columns` shortcode attribute into a validated ordered list.
	 * "name" is always present — it carries the row's identity.
	 */
	public function resolve_columns( $raw ) {
		$available = $this->available_columns();
		$requested = \array_filter( \array_map( 'trim', \explode( ',', (string) $raw ) ) );
		$columns   = [];

		foreach ( $requested as $key ) {
			$key = \sanitize_key( $key );
			if ( isset( $available[ $key ] ) && ! \in_array( $key, $columns, true ) ) {
				$columns[] = $key;
			}
		}

		if ( empty( $columns ) ) {
			$columns = [ 'image', 'name', 'owner', 'address', 'phone', 'status' ];
		}
		if ( ! \in_array( 'name', $columns, true ) ) {
			\array_unshift( $columns, 'name' );
		}

		return $columns;
	}

	/* =====================================================================
	   Query building
	   ===================================================================== */

	/**
	 * Normalise raw request input into safe WP_Query arguments.
	 */
	public function normalize_args( $raw ) {
		$statuses = $this->filter_statuses();

		$status = isset( $raw['status'] ) ? \sanitize_key( $raw['status'] ) : 'any';
		if ( ! isset( $statuses[ $status ] ) ) {
			$status = 'any';
		}

		$sortable = $this->sortable_columns();
		$orderby  = isset( $raw['orderby'] ) ? \sanitize_key( $raw['orderby'] ) : 'title';
		if ( ! \in_array( $orderby, $sortable, true ) ) {
			$orderby = 'title';
		}

		$order = isset( $raw['order'] ) && \strtoupper( $raw['order'] ) === 'DESC' ? 'DESC' : 'ASC';

		$per_page = isset( $raw['per_page'] ) ? (int) $raw['per_page'] : self::DEFAULT_PER_PAGE;
		if ( $per_page < 1 ) {
			$per_page = self::DEFAULT_PER_PAGE;
		}
		$per_page = \min( $per_page, self::MAX_PER_PAGE );

		$paged = isset( $raw['paged'] ) ? \max( 1, (int) $raw['paged'] ) : 1;

		$search = isset( $raw['s'] ) ? \sanitize_text_field( \wp_unslash( $raw['s'] ) ) : '';

		return \compact( 'status', 'orderby', 'order', 'per_page', 'paged', 'search' );
	}

	/**
	 * Build the WP_Query for a normalised argument set.
	 *
	 * @return \WP_Query
	 */
	public function build_query( array $args ) {
		$status = $args['status'] === 'any'
			? \array_keys( $this->editable_statuses() )
			: $args['status'];

		$query_args = [
			'post_type'      => Module::CPT,
			'post_status'    => $status,
			'posts_per_page' => $args['per_page'],
			'paged'          => $args['paged'],
			'order'          => $args['order'],
			// Consulted by filter_posts_search() so the meta-search JOIN only
			// ever applies to this query, never to anything else on the page.
			'anchor_store_manager_search' => true,
		];

		if ( $args['orderby'] === 'owner' ) {
			$query_args['meta_key'] = '_anchor_store_owner';
			$query_args['orderby']  = [ 'meta_value' => $args['order'], 'title' => 'ASC' ];
		} elseif ( $args['orderby'] === 'modified' ) {
			$query_args['orderby'] = 'modified';
		} else {
			$query_args['orderby'] = 'title';
		}

		if ( $args['search'] !== '' ) {
			$query_args['s'] = $args['search'];
		}

		return new \WP_Query( $query_args );
	}

	/**
	 * Extend WP's title/content search to the store meta fields.
	 *
	 * Without this, searching a street name, an owner, or a phone number
	 * returns nothing — which are the three things you actually want to
	 * search a store list by.
	 */
	public function filter_posts_search( $search, $query ) {
		if ( ! $query->get( 'anchor_store_manager_search' ) ) {
			return $search;
		}

		$term = (string) $query->get( 's' );
		if ( $term === '' || $search === '' ) {
			return $search;
		}

		global $wpdb;

		$like         = '%' . $wpdb->esc_like( $term ) . '%';
		$keys         = self::SEARCH_META_KEYS;
		$placeholders = \implode( ',', \array_fill( 0, \count( $keys ), '%s' ) );

		$subquery = $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ( {$placeholders} ) AND meta_value LIKE %s",
			\array_merge( $keys, [ $like ] )
		);

		// $search arrives as " AND (( ... ))"; unwrap the leading AND so the
		// meta clause can be OR'd in at the same level.
		$inner = \preg_replace( '/^\s*AND\s*/i', '', $search );

		return " AND ( {$inner} OR {$wpdb->posts}.ID IN ( {$subquery} ) ) ";
	}

	/** Post counts per status tab, respecting the active search term. */
	public function status_counts( array $args ) {
		$counts = [];
		foreach ( \array_keys( $this->filter_statuses() ) as $status ) {
			$probe            = $args;
			$probe['status']  = $status;
			$probe['paged']   = 1;
			$probe['per_page'] = 1;

			$query            = $this->build_query( $probe );
			$counts[ $status ] = (int) $query->found_posts;
		}
		return $counts;
	}

	/* =====================================================================
	   Shortcode
	   ===================================================================== */

	public function render_shortcode( $atts ) {
		if ( ! $this->can_manage() ) {
			return '<p class="asm-denied">' . \esc_html__( 'You do not have permission to manage stores.', 'anchor-schema' ) . '</p>';
		}

		$atts = \shortcode_atts(
			[
				'per_page' => self::DEFAULT_PER_PAGE,
				'columns'  => 'image,name,owner,address,phone,status',
			],
			$atts,
			'anchor_store_manager'
		);

		$this->instance++;
		$uid     = 'asm-' . $this->instance;
		$columns = $this->resolve_columns( $atts['columns'] );

		// Initial state comes from the URL so a shared/bookmarked link, and the
		// browser's back button, both land on the same view.
		$args = $this->normalize_args(
			[
				'status'   => $_GET['asm_status'] ?? 'any',
				'orderby'  => $_GET['asm_orderby'] ?? 'title',
				'order'    => $_GET['asm_order'] ?? 'ASC',
				's'        => $_GET['asm_s'] ?? '',
				'paged'    => $_GET['asm_paged'] ?? 1,
				'per_page' => $atts['per_page'],
			]
		);

		$can_upload = \current_user_can( 'upload_files' );
		if ( $can_upload ) {
			\wp_enqueue_media();
		}

		\wp_enqueue_script( 'heartbeat' );
		\wp_enqueue_style(
			'anchor-store-manager',
			\Anchor_Asset_Loader::url( 'anchor-store-locator/assets/manager.css' ),
			[],
			self::ASSET_VERSION
		);
		\wp_enqueue_script(
			'anchor-store-manager',
			\Anchor_Asset_Loader::url( 'anchor-store-locator/assets/manager.js' ),
			[ 'jquery', 'heartbeat' ],
			self::ASSET_VERSION,
			true
		);
		\wp_localize_script(
			'anchor-store-manager',
			'ANCHOR_STORE_MGR',
			[
				'ajaxUrl'     => \admin_url( 'admin-ajax.php' ),
				'nonce'       => \wp_create_nonce( self::NONCE ),
				'placesNonce' => \wp_create_nonce( Module::ADMIN_NONCE ),
				'canUpload'   => $can_upload,
				'i18n'        => $this->script_strings(),
			]
		);

		$query  = $this->build_query( $args );
		$counts = $this->status_counts( $args );

		\ob_start();
		?>
		<div class="asm-wrap" id="<?php echo \esc_attr( $uid ); ?>"
			data-per-page="<?php echo \esc_attr( $args['per_page'] ); ?>"
			data-columns="<?php echo \esc_attr( \implode( ',', $columns ) ); ?>">

			<div class="asm-list" data-asm-list>
				<?php $this->render_toolbar( $uid, $args ); ?>
				<div data-asm-tabs-wrap><?php echo $this->render_status_tabs( $args, $counts ); ?></div>
				<div data-asm-bulk-wrap><?php echo $this->render_bulk_bar( $uid, $args ); ?></div>

				<div class="asm-table-wrap" data-asm-table-wrap aria-busy="false">
					<table class="asm-table">
						<thead data-asm-head><?php echo $this->render_head( $columns, $args ); ?></thead>
						<tbody data-asm-rows><?php echo $this->render_rows( $query, $columns, $args ); ?></tbody>
					</table>
				</div>

				<div data-asm-pagination><?php echo $this->render_pagination( $query, $args ); ?></div>
			</div>

			<?php $this->render_form( $uid, $can_upload ); ?>

			<div class="asm-toast" data-asm-toast role="status" aria-live="polite" hidden></div>
		</div>
		<?php
		return \ob_get_clean();
	}

	private function script_strings() {
		return [
			'addStore'       => \__( 'Add Store', 'anchor-schema' ),
			'editStore'      => \__( 'Edit Store', 'anchor-schema' ),
			'saving'         => \__( 'Saving…', 'anchor-schema' ),
			'saveStore'      => \__( 'Save Store', 'anchor-schema' ),
			'created'        => \__( 'Store created.', 'anchor-schema' ),
			'updated'        => \__( 'Store updated.', 'anchor-schema' ),
			'trashed'        => \__( 'Store moved to trash.', 'anchor-schema' ),
			'restored'       => \__( 'Store restored.', 'anchor-schema' ),
			'duplicated'     => \__( 'Store duplicated.', 'anchor-schema' ),
			'undo'           => \__( 'Undo', 'anchor-schema' ),
			'genericError'   => \__( 'Something went wrong. Please try again.', 'anchor-schema' ),
			'requestFailed'  => \__( 'Could not reach the server. Check your connection and try again.', 'anchor-schema' ),
			'unsaved'        => \__( 'You have unsaved changes. Discard them?', 'anchor-schema' ),
			'confirmDelete'  => \__( 'Permanently delete this store? This cannot be undone.', 'anchor-schema' ),
			'confirmBulk'    => \__( 'Apply this action to the selected stores?', 'anchor-schema' ),
			'noSelection'    => \__( 'Select at least one store first.', 'anchor-schema' ),
			'selectImage'    => \__( 'Select Featured Image', 'anchor-schema' ),
			'useImage'       => \__( 'Use Image', 'anchor-schema' ),
			'searching'      => \__( 'Searching…', 'anchor-schema' ),
			'noPlaces'       => \__( 'No matching businesses found.', 'anchor-schema' ),
			'geocodeNoKey'   => \__( 'Saved, but no Google API key is configured so the map position could not be set.', 'anchor-schema' ),
			'geocodeFailed'  => \__( 'Saved, but the address could not be geocoded. Check the address or set coordinates manually.', 'anchor-schema' ),
		];
	}

	private function render_toolbar( $uid, array $args ) {
		?>
		<div class="asm-toolbar">
			<h2 class="asm-title"><?php echo \esc_html__( 'Store Locations', 'anchor-schema' ); ?></h2>

			<div class="asm-toolbar-actions">
				<div class="asm-search" role="search">
					<label class="screen-reader-text" for="<?php echo \esc_attr( $uid ); ?>-search">
						<?php echo \esc_html__( 'Search stores', 'anchor-schema' ); ?>
					</label>
					<input type="search" id="<?php echo \esc_attr( $uid ); ?>-search" data-asm-search
						value="<?php echo \esc_attr( $args['search'] ); ?>"
						placeholder="<?php echo \esc_attr__( 'Search name, owner, address, phone…', 'anchor-schema' ); ?>" />
					<button type="button" class="asm-search-clear" data-asm-search-clear
						<?php echo $args['search'] === '' ? 'hidden' : ''; ?>
						aria-label="<?php echo \esc_attr__( 'Clear search', 'anchor-schema' ); ?>">&times;</button>
				</div>

				<button type="button" class="asm-btn asm-btn--primary" data-asm-action="add">
					<?php echo \esc_html__( 'Add Store', 'anchor-schema' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	public function render_status_tabs( array $args, array $counts ) {
		\ob_start();
		?>
		<ul class="asm-tabs" data-asm-tabs>
			<?php foreach ( $this->filter_statuses() as $key => $label ) : ?>
				<?php
				// Hide an empty Trash tab, but always show the rest so the
				// list doesn't reflow as counts change.
				if ( $key === 'trash' && empty( $counts[ $key ] ) && $args['status'] !== 'trash' ) {
					continue;
				}
				$is_current = $args['status'] === $key;
				?>
				<li>
					<button type="button" class="asm-tab<?php echo $is_current ? ' is-current' : ''; ?>"
						data-asm-status="<?php echo \esc_attr( $key ); ?>"
						<?php echo $is_current ? 'aria-current="page"' : ''; ?>>
						<?php echo \esc_html( $label ); ?>
						<span class="asm-count"><?php echo \esc_html( (int) ( $counts[ $key ] ?? 0 ) ); ?></span>
					</button>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
		return \ob_get_clean();
	}

	public function render_bulk_bar( $uid, array $args ) {
		$in_trash = $args['status'] === 'trash';
		\ob_start();
		?>
		<div class="asm-bulk" data-asm-bulk>
			<label class="screen-reader-text" for="<?php echo \esc_attr( $uid ); ?>-bulk">
				<?php echo \esc_html__( 'Bulk action', 'anchor-schema' ); ?>
			</label>
			<select id="<?php echo \esc_attr( $uid ); ?>-bulk" data-asm-bulk-action>
				<option value=""><?php echo \esc_html__( 'Bulk actions', 'anchor-schema' ); ?></option>
				<?php if ( $in_trash ) : ?>
					<option value="restore"><?php echo \esc_html__( 'Restore', 'anchor-schema' ); ?></option>
					<option value="delete"><?php echo \esc_html__( 'Delete permanently', 'anchor-schema' ); ?></option>
				<?php else : ?>
					<option value="publish"><?php echo \esc_html__( 'Publish', 'anchor-schema' ); ?></option>
					<option value="draft"><?php echo \esc_html__( 'Switch to draft', 'anchor-schema' ); ?></option>
					<option value="trash"><?php echo \esc_html__( 'Move to trash', 'anchor-schema' ); ?></option>
				<?php endif; ?>
			</select>
			<button type="button" class="asm-btn asm-btn--sm" data-asm-action="bulk-apply">
				<?php echo \esc_html__( 'Apply', 'anchor-schema' ); ?>
			</button>
			<span class="asm-bulk-count" data-asm-bulk-count aria-live="polite"></span>
		</div>
		<?php
		return \ob_get_clean();
	}

	public function render_head( array $columns, array $args ) {
		$labels   = $this->available_columns();
		$sortable = $this->sortable_columns();
		\ob_start();
		?>
		<tr>
			<td class="asm-col-cb">
				<input type="checkbox" data-asm-select-all
					aria-label="<?php echo \esc_attr__( 'Select all stores', 'anchor-schema' ); ?>" />
			</td>
			<?php foreach ( $columns as $key ) : ?>
				<?php
				$is_sortable = isset( $sortable[ $key ] );
				$is_current  = $is_sortable && $args['orderby'] === $sortable[ $key ];
				$aria_sort   = 'none';
				if ( $is_current ) {
					$aria_sort = $args['order'] === 'ASC' ? 'ascending' : 'descending';
				}
				?>
				<th scope="col" class="asm-col-<?php echo \esc_attr( $key ); ?>"
					<?php echo $is_sortable ? 'aria-sort="' . \esc_attr( $aria_sort ) . '"' : ''; ?>>
					<?php if ( $is_sortable ) : ?>
						<button type="button" class="asm-sort<?php echo $is_current ? ' is-current' : ''; ?>"
							data-asm-sort="<?php echo \esc_attr( $sortable[ $key ] ); ?>">
							<?php echo \esc_html( $labels[ $key ] ); ?>
							<span class="asm-sort-arrow" aria-hidden="true"></span>
						</button>
					<?php else : ?>
						<?php echo \esc_html( $labels[ $key ] ); ?>
					<?php endif; ?>
				</th>
			<?php endforeach; ?>
			<th scope="col" class="asm-col-actions"><?php echo \esc_html__( 'Actions', 'anchor-schema' ); ?></th>
		</tr>
		<?php
		return \ob_get_clean();
	}

	/**
	 * Render the table body.
	 *
	 * This is the single source of truth for row markup — the initial page
	 * render and every AJAX refresh both come through here, so the two can't
	 * drift the way the old PHP-plus-JS twin templates did.
	 */
	public function render_rows( \WP_Query $query, array $columns, array $args ) {
		$colspan  = \count( $columns ) + 2;
		$in_trash = $args['status'] === 'trash';

		\ob_start();

		if ( ! $query->have_posts() ) {
			$message = $args['search'] !== ''
				? \sprintf(
					/* translators: %s: the search term. */
					\__( 'No stores match “%s”.', 'anchor-schema' ),
					$args['search']
				)
				: ( $in_trash
					? \__( 'The trash is empty.', 'anchor-schema' )
					: \__( 'No stores yet. Choose “Add Store” to create your first one.', 'anchor-schema' ) );
			?>
			<tr class="asm-empty">
				<td colspan="<?php echo \esc_attr( $colspan ); ?>">
					<?php echo \esc_html( $message ); ?>
					<?php if ( $args['search'] !== '' ) : ?>
						<button type="button" class="asm-link" data-asm-action="clear-search">
							<?php echo \esc_html__( 'Clear search', 'anchor-schema' ); ?>
						</button>
					<?php endif; ?>
				</td>
			</tr>
			<?php
			return \ob_get_clean();
		}

		foreach ( $query->posts as $post ) {
			$can_edit   = \current_user_can( 'edit_post', $post->ID );
			$can_delete = \current_user_can( 'delete_post', $post->ID );
			?>
			<tr data-asm-row data-id="<?php echo \esc_attr( $post->ID ); ?>">
				<td class="asm-col-cb">
					<input type="checkbox" data-asm-select value="<?php echo \esc_attr( $post->ID ); ?>"
						aria-label="<?php echo \esc_attr( \sprintf( /* translators: %s: store name. */ \__( 'Select %s', 'anchor-schema' ), $post->post_title ) ); ?>" />
				</td>

				<?php foreach ( $columns as $key ) : ?>
					<td class="asm-col-<?php echo \esc_attr( $key ); ?>"
						data-label="<?php echo \esc_attr( $this->available_columns()[ $key ] ); ?>">
						<?php echo $this->render_cell( $key, $post ); ?>
					</td>
				<?php endforeach; ?>

				<td class="asm-col-actions">
					<?php if ( $in_trash ) : ?>
						<?php if ( $can_delete ) : ?>
							<button type="button" class="asm-btn asm-btn--sm" data-asm-action="restore" data-id="<?php echo \esc_attr( $post->ID ); ?>">
								<?php echo \esc_html__( 'Restore', 'anchor-schema' ); ?>
							</button>
							<button type="button" class="asm-btn asm-btn--sm asm-btn--danger" data-asm-action="delete-permanently" data-id="<?php echo \esc_attr( $post->ID ); ?>">
								<?php echo \esc_html__( 'Delete Permanently', 'anchor-schema' ); ?>
							</button>
						<?php endif; ?>
					<?php else : ?>
						<?php if ( $can_edit ) : ?>
							<button type="button" class="asm-btn asm-btn--sm" data-asm-action="edit" data-id="<?php echo \esc_attr( $post->ID ); ?>">
								<?php echo \esc_html__( 'Edit', 'anchor-schema' ); ?>
							</button>
							<button type="button" class="asm-btn asm-btn--sm" data-asm-action="duplicate" data-id="<?php echo \esc_attr( $post->ID ); ?>">
								<?php echo \esc_html__( 'Duplicate', 'anchor-schema' ); ?>
							</button>
						<?php endif; ?>
						<?php if ( $can_delete ) : ?>
							<button type="button" class="asm-btn asm-btn--sm asm-btn--danger" data-asm-action="delete" data-id="<?php echo \esc_attr( $post->ID ); ?>">
								<?php echo \esc_html__( 'Delete', 'anchor-schema' ); ?>
							</button>
						<?php endif; ?>
					<?php endif; ?>
				</td>
			</tr>
			<?php
		}

		return \ob_get_clean();
	}

	private function render_cell( $key, \WP_Post $post ) {
		switch ( $key ) {
			case 'image':
				$thumb = \get_the_post_thumbnail_url( $post->ID, 'thumbnail' );
				return $thumb
					? '<img src="' . \esc_url( $thumb ) . '" alt="" loading="lazy" width="48" height="48" />'
					: '<span class="asm-no-img" aria-hidden="true"></span>';

			case 'name':
				return '<span class="asm-name">' . \esc_html( $post->post_title ) . '</span>';

			case 'owner':
				return \esc_html( \get_post_meta( $post->ID, '_anchor_store_owner', true ) );

			case 'address':
				return \esc_html( \get_post_meta( $post->ID, '_anchor_store_address', true ) );

			case 'phone':
				$phone = \get_post_meta( $post->ID, '_anchor_store_phone', true );
				if ( ! $phone ) {
					return '';
				}
				$tel = \preg_replace( '/[^0-9+]/', '', $phone );
				return '<a href="tel:' . \esc_attr( $tel ) . '">' . \esc_html( $phone ) . '</a>';

			case 'email':
				$email = \get_post_meta( $post->ID, '_anchor_store_email', true );
				return $email
					? '<a href="mailto:' . \antispambot( $email ) . '">' . \esc_html( $email ) . '</a>'
					: '';

			case 'status':
				$labels = $this->editable_statuses();
				$label  = $labels[ $post->post_status ] ?? \ucfirst( $post->post_status );
				return '<span class="asm-badge asm-badge--' . \esc_attr( $post->post_status ) . '">'
					. \esc_html( $label ) . '</span>';

			case 'modified':
				return '<time datetime="' . \esc_attr( \get_post_modified_time( 'c', true, $post ) ) . '">'
					. \esc_html( \get_post_modified_time( \get_option( 'date_format' ), false, $post ) )
					. '</time>';
		}

		return '';
	}

	public function render_pagination( \WP_Query $query, array $args ) {
		$total_pages = (int) $query->max_num_pages;
		$total       = (int) $query->found_posts;

		\ob_start();
		?>
		<div class="asm-pagination">
			<span class="asm-pagination-count">
				<?php
				echo \esc_html(
					\sprintf(
						/* translators: %s: number of stores. */
						\_n( '%s store', '%s stores', $total, 'anchor-schema' ),
						\number_format_i18n( $total )
					)
				);
				?>
			</span>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="asm-pages">
					<button type="button" class="asm-btn asm-btn--sm" data-asm-page="<?php echo \esc_attr( $args['paged'] - 1 ); ?>"
						<?php \disabled( $args['paged'] <= 1 ); ?>
						aria-label="<?php echo \esc_attr__( 'Previous page', 'anchor-schema' ); ?>">&lsaquo;</button>

					<span class="asm-page-of">
						<?php
						echo \esc_html(
							\sprintf(
								/* translators: 1: current page, 2: total pages. */
								\__( 'Page %1$s of %2$s', 'anchor-schema' ),
								\number_format_i18n( $args['paged'] ),
								\number_format_i18n( $total_pages )
							)
						);
						?>
					</span>

					<button type="button" class="asm-btn asm-btn--sm" data-asm-page="<?php echo \esc_attr( $args['paged'] + 1 ); ?>"
						<?php \disabled( $args['paged'] >= $total_pages ); ?>
						aria-label="<?php echo \esc_attr__( 'Next page', 'anchor-schema' ); ?>">&rsaquo;</button>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return \ob_get_clean();
	}

	private function render_form( $uid, $can_upload ) {
		?>
		<div class="asm-form" data-asm-form hidden>
			<div class="asm-toolbar">
				<h2 id="<?php echo \esc_attr( $uid ); ?>-form-title" tabindex="-1">
					<?php echo \esc_html__( 'Add Store', 'anchor-schema' ); ?>
				</h2>
				<button type="button" class="asm-btn" data-asm-action="cancel">
					<?php echo \esc_html__( 'Back to List', 'anchor-schema' ); ?>
				</button>
			</div>

			<form data-asm-form-el autocomplete="off" novalidate>
				<input type="hidden" name="store_id" data-asm-field="store_id" value="" />
				<input type="hidden" name="place_id" data-asm-field="place_id" value="" />

				<div class="asm-field asm-field--places">
					<label for="<?php echo \esc_attr( $uid ); ?>-places">
						<?php echo \esc_html__( 'Find a business', 'anchor-schema' ); ?>
					</label>
					<input type="text" id="<?php echo \esc_attr( $uid ); ?>-places" data-asm-places
						autocomplete="off" role="combobox" aria-expanded="false" aria-autocomplete="list"
						placeholder="<?php echo \esc_attr__( 'Search Google for a business name or address…', 'anchor-schema' ); ?>" />
					<p class="asm-help"><?php echo \esc_html__( 'Optional. Picking a result fills in the address, coordinates, phone and website below.', 'anchor-schema' ); ?></p>
					<div class="asm-places-results" data-asm-places-results role="listbox" hidden></div>
				</div>

				<div class="asm-field">
					<label for="<?php echo \esc_attr( $uid ); ?>-title">
						<?php echo \esc_html__( 'Store Name', 'anchor-schema' ); ?> <abbr title="<?php echo \esc_attr__( 'required', 'anchor-schema' ); ?>">*</abbr>
					</label>
					<input type="text" id="<?php echo \esc_attr( $uid ); ?>-title" data-asm-field="title" required />
				</div>

				<div class="asm-field">
					<label for="<?php echo \esc_attr( $uid ); ?>-owner"><?php echo \esc_html__( 'Owner', 'anchor-schema' ); ?></label>
					<input type="text" id="<?php echo \esc_attr( $uid ); ?>-owner" data-asm-field="owner" />
				</div>

				<div class="asm-field">
					<label for="<?php echo \esc_attr( $uid ); ?>-address">
						<?php echo \esc_html__( 'Address', 'anchor-schema' ); ?> <abbr title="<?php echo \esc_attr__( 'required', 'anchor-schema' ); ?>">*</abbr>
					</label>
					<textarea id="<?php echo \esc_attr( $uid ); ?>-address" data-asm-field="address" rows="2" required></textarea>
				</div>

				<div class="asm-row">
					<div class="asm-field">
						<label for="<?php echo \esc_attr( $uid ); ?>-lat"><?php echo \esc_html__( 'Latitude', 'anchor-schema' ); ?></label>
						<input type="text" inputmode="decimal" id="<?php echo \esc_attr( $uid ); ?>-lat" data-asm-field="lat" />
					</div>
					<div class="asm-field">
						<label for="<?php echo \esc_attr( $uid ); ?>-lng"><?php echo \esc_html__( 'Longitude', 'anchor-schema' ); ?></label>
						<input type="text" inputmode="decimal" id="<?php echo \esc_attr( $uid ); ?>-lng" data-asm-field="lng" />
					</div>
				</div>

				<div class="asm-field">
					<label for="<?php echo \esc_attr( $uid ); ?>-website"><?php echo \esc_html__( 'Website URL', 'anchor-schema' ); ?></label>
					<input type="url" id="<?php echo \esc_attr( $uid ); ?>-website" data-asm-field="website" />
				</div>

				<div class="asm-row">
					<div class="asm-field">
						<label for="<?php echo \esc_attr( $uid ); ?>-email"><?php echo \esc_html__( 'Email Address', 'anchor-schema' ); ?></label>
						<input type="email" id="<?php echo \esc_attr( $uid ); ?>-email" data-asm-field="email" />
					</div>
					<div class="asm-field">
						<label for="<?php echo \esc_attr( $uid ); ?>-phone"><?php echo \esc_html__( 'Phone Number', 'anchor-schema' ); ?></label>
						<input type="tel" id="<?php echo \esc_attr( $uid ); ?>-phone" data-asm-field="phone" />
					</div>
				</div>

				<div class="asm-field">
					<label for="<?php echo \esc_attr( $uid ); ?>-maps"><?php echo \esc_html__( 'Google Maps Link', 'anchor-schema' ); ?></label>
					<input type="url" id="<?php echo \esc_attr( $uid ); ?>-maps" data-asm-field="maps_url" />
				</div>

				<div class="asm-field">
					<label for="<?php echo \esc_attr( $uid ); ?>-status"><?php echo \esc_html__( 'Status', 'anchor-schema' ); ?></label>
					<select id="<?php echo \esc_attr( $uid ); ?>-status" data-asm-field="status">
						<?php foreach ( $this->editable_statuses() as $value => $label ) : ?>
							<option value="<?php echo \esc_attr( $value ); ?>"><?php echo \esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<?php if ( $can_upload ) : ?>
					<div class="asm-field">
						<label><?php echo \esc_html__( 'Featured Image', 'anchor-schema' ); ?></label>
						<div class="asm-image-upload">
							<input type="hidden" data-asm-field="thumbnail_id" value="" />
							<div class="asm-image-preview" data-asm-image-preview></div>
							<div class="asm-image-buttons">
								<button type="button" class="asm-btn" data-asm-action="upload-image">
									<?php echo \esc_html__( 'Select Image', 'anchor-schema' ); ?>
								</button>
								<button type="button" class="asm-btn asm-btn--danger" data-asm-action="remove-image" hidden>
									<?php echo \esc_html__( 'Remove', 'anchor-schema' ); ?>
								</button>
							</div>
						</div>
					</div>
				<?php endif; ?>

				<div class="asm-form-error" data-asm-form-error role="alert" hidden></div>

				<div class="asm-form-actions">
					<button type="submit" class="asm-btn asm-btn--primary asm-btn--lg">
						<?php echo \esc_html__( 'Save Store', 'anchor-schema' ); ?>
					</button>
					<button type="button" class="asm-btn asm-btn--lg" data-asm-action="cancel">
						<?php echo \esc_html__( 'Cancel', 'anchor-schema' ); ?>
					</button>
				</div>
			</form>
		</div>
		<?php
	}

	/* =====================================================================
	   AJAX: list
	   ===================================================================== */

	public function ajax_list() {
		$this->guard();

		$args    = $this->normalize_args( $_POST );
		$columns = $this->resolve_columns( $_POST['columns'] ?? '' );
		$uid     = \sanitize_html_class( $_POST['uid'] ?? 'asm-1' );
		$query   = $this->build_query( $args );
		$counts  = $this->status_counts( $args );

		\wp_send_json_success(
			[
				'rows'       => $this->render_rows( $query, $columns, $args ),
				'head'       => $this->render_head( $columns, $args ),
				'tabs'       => $this->render_status_tabs( $args, $counts ),
				'bulk'       => $this->render_bulk_bar( $uid, $args ),
				'pagination' => $this->render_pagination( $query, $args ),
				'counts'     => $counts,
				'total'      => (int) $query->found_posts,
				'pages'      => (int) $query->max_num_pages,
				'paged'      => $args['paged'],
				'status'     => $args['status'],
				'orderby'    => $args['orderby'],
				'order'      => $args['order'],
				'search'     => $args['search'],
			]
		);
	}

	/* =====================================================================
	   AJAX: read one
	   ===================================================================== */

	public function ajax_get() {
		$this->guard();

		$post      = $this->require_store( $_POST['store_id'] ?? 0, 'edit_post' );
		$thumb_id  = \get_post_thumbnail_id( $post->ID );
		$thumb_url = $thumb_id ? \wp_get_attachment_image_url( $thumb_id, 'medium' ) : '';

		\wp_send_json_success(
			[
				'id'            => $post->ID,
				'title'         => $post->post_title,
				'status'        => $post->post_status,
				'owner'         => \get_post_meta( $post->ID, '_anchor_store_owner', true ),
				'address'       => \get_post_meta( $post->ID, '_anchor_store_address', true ),
				'lat'           => \get_post_meta( $post->ID, '_anchor_store_lat', true ),
				'lng'           => \get_post_meta( $post->ID, '_anchor_store_lng', true ),
				'website'       => \get_post_meta( $post->ID, '_anchor_store_website', true ),
				'email'         => \get_post_meta( $post->ID, '_anchor_store_email', true ),
				'phone'         => \get_post_meta( $post->ID, '_anchor_store_phone', true ),
				'maps_url'      => \get_post_meta( $post->ID, '_anchor_store_maps_url', true ),
				'place_id'      => \get_post_meta( $post->ID, '_anchor_store_place_id', true ),
				'thumbnail_id'  => $thumb_id ? (int) $thumb_id : 0,
				'thumbnail_url' => $thumb_url,
			]
		);
	}

	/* =====================================================================
	   AJAX: save
	   ===================================================================== */

	public function ajax_save() {
		$this->guard();

		$store_id = (int) ( $_POST['store_id'] ?? 0 );
		$title    = \sanitize_text_field( \wp_unslash( $_POST['title'] ?? '' ) );
		$address  = \sanitize_text_field( \wp_unslash( $_POST['address'] ?? '' ) );

		// Whitelist against the same list the form is built from, and fall back
		// to the store's *existing* status rather than a hardcoded 'draft' —
		// otherwise an unrecognised value silently demotes the store.
		$requested = \sanitize_key( $_POST['status'] ?? '' );
		$statuses  = $this->editable_statuses();

		if ( $store_id ) {
			$existing = $this->require_store( $store_id, 'edit_post' );
			$fallback = isset( $statuses[ $existing->post_status ] ) ? $existing->post_status : 'draft';
		} else {
			if ( ! $this->can_manage() ) {
				\wp_send_json_error( [ 'message' => \__( 'You do not have permission to create stores.', 'anchor-schema' ) ], 403 );
			}
			$fallback = 'draft';
		}

		$status = isset( $statuses[ $requested ] ) ? $requested : $fallback;

		if ( $title === '' ) {
			\wp_send_json_error( [ 'message' => \__( 'Store name is required.', 'anchor-schema' ), 'field' => 'title' ], 400 );
		}
		if ( $address === '' ) {
			\wp_send_json_error( [ 'message' => \__( 'Address is required.', 'anchor-schema' ), 'field' => 'address' ], 400 );
		}

		$post_data = [
			'post_type'   => Module::CPT,
			'post_title'  => $title,
			'post_status' => $status,
		];

		if ( $store_id ) {
			$post_data['ID'] = $store_id;
			$result          = \wp_update_post( $post_data, true );
		} else {
			$result = \wp_insert_post( $post_data, true );
		}

		if ( \is_wp_error( $result ) ) {
			\wp_send_json_error( [ 'message' => $result->get_error_message() ], 500 );
		}

		$post_id = (int) $result;

		$geocode = $this->save_location_meta( $post_id, $address, $_POST );

		\update_post_meta( $post_id, '_anchor_store_owner', \sanitize_text_field( \wp_unslash( $_POST['owner'] ?? '' ) ) );
		\update_post_meta( $post_id, '_anchor_store_website', \esc_url_raw( \wp_unslash( $_POST['website'] ?? '' ) ) );
		\update_post_meta( $post_id, '_anchor_store_email', \sanitize_email( \wp_unslash( $_POST['email'] ?? '' ) ) );
		\update_post_meta( $post_id, '_anchor_store_phone', \sanitize_text_field( \wp_unslash( $_POST['phone'] ?? '' ) ) );
		\update_post_meta( $post_id, '_anchor_store_maps_url', \esc_url_raw( \wp_unslash( $_POST['maps_url'] ?? '' ) ) );

		$place_id = \sanitize_text_field( \wp_unslash( $_POST['place_id'] ?? '' ) );
		if ( $place_id ) {
			\update_post_meta( $post_id, '_anchor_store_place_id', $place_id );
		} else {
			\delete_post_meta( $post_id, '_anchor_store_place_id' );
		}

		if ( \current_user_can( 'upload_files' ) ) {
			$thumb_id = (int) ( $_POST['thumbnail_id'] ?? 0 );
			if ( $thumb_id ) {
				\set_post_thumbnail( $post_id, $thumb_id );
			} else {
				\delete_post_thumbnail( $post_id );
			}
		}

		\wp_send_json_success(
			[
				'id'      => $post_id,
				'is_new'  => ! $store_id,
				'status'  => $status,
				'geocode' => $geocode,
			]
		);
	}

	/**
	 * Persist address and coordinates, geocoding when the address changed.
	 *
	 * Returns one of: ok | unchanged | manual | no_key | failed.
	 *
	 * A failed lookup must never clobber coordinates that are already good —
	 * writing 0,0 drops the pin in the Atlantic and looks like data loss.
	 */
	private function save_location_meta( $post_id, $address, array $input ) {
		$lat = \sanitize_text_field( \wp_unslash( $input['lat'] ?? '' ) );
		$lng = \sanitize_text_field( \wp_unslash( $input['lng'] ?? '' ) );

		$lat_value = \is_numeric( $lat ) ? (float) $lat : 0.0;
		$lng_value = \is_numeric( $lng ) ? (float) $lng : 0.0;

		$prev_lat = (float) \get_post_meta( $post_id, '_anchor_store_lat', true );
		$prev_lng = (float) \get_post_meta( $post_id, '_anchor_store_lng', true );

		// Mirror the admin metabox's change tracking so both save paths agree
		// on whether the address actually moved.
		$prev_address  = \get_post_meta( $post_id, '_anchor_store_address_prev', true );
		$address_moved = ( $address !== '' && $address !== $prev_address );
		$has_coords    = ( $lat_value && $lng_value );

		\update_post_meta( $post_id, '_anchor_store_address', $address );

		$result = 'unchanged';

		if ( $has_coords && ! $address_moved ) {
			// Explicit coordinates supplied and the address is unchanged.
			$result = 'manual';
		} elseif ( $address !== '' && ( $address_moved || ! $has_coords ) ) {
			if ( ! $this->module->get_google_api_key() ) {
				$result = 'no_key';
			} else {
				$coords = $this->module->geocode_address( $address );
				if ( $coords ) {
					$lat_value = $coords['lat'];
					$lng_value = $coords['lng'];
					$result    = 'ok';
					\update_post_meta( $post_id, '_anchor_store_address_prev', $address );
				} else {
					$result = 'failed';
				}
			}
		}

		if ( ! $lat_value || ! $lng_value ) {
			// Geocoding produced nothing usable — keep whatever we already had
			// rather than overwriting good coordinates with zeroes.
			$lat_value = $lat_value ?: $prev_lat;
			$lng_value = $lng_value ?: $prev_lng;
		}

		\update_post_meta( $post_id, '_anchor_store_lat', $lat_value );
		\update_post_meta( $post_id, '_anchor_store_lng', $lng_value );

		return $result;
	}

	/* =====================================================================
	   AJAX: delete / restore / duplicate
	   ===================================================================== */

	public function ajax_delete() {
		$this->guard();

		$post      = $this->require_store( $_POST['store_id'] ?? 0, 'delete_post' );
		$permanent = ! empty( $_POST['permanent'] );

		if ( $permanent || $post->post_status === 'trash' ) {
			if ( ! \wp_delete_post( $post->ID, true ) ) {
				\wp_send_json_error( [ 'message' => \__( 'Could not delete the store.', 'anchor-schema' ) ], 500 );
			}
			\wp_send_json_success( [ 'id' => $post->ID, 'permanent' => true ] );
		}

		if ( ! \wp_trash_post( $post->ID ) ) {
			\wp_send_json_error( [ 'message' => \__( 'Could not move the store to trash.', 'anchor-schema' ) ], 500 );
		}

		\wp_send_json_success( [ 'id' => $post->ID, 'permanent' => false ] );
	}

	public function ajax_restore() {
		$this->guard();

		$post = $this->require_store( $_POST['store_id'] ?? 0, 'delete_post' );

		if ( ! \wp_untrash_post( $post->ID ) ) {
			\wp_send_json_error( [ 'message' => \__( 'Could not restore the store.', 'anchor-schema' ) ], 500 );
		}

		// WP may restore to 'draft' depending on wp_untrash_post_status; make
		// sure the row reappears under a status the manager actually lists.
		$restored = \get_post( $post->ID );
		if ( $restored && ! isset( $this->editable_statuses()[ $restored->post_status ] ) ) {
			\wp_update_post( [ 'ID' => $post->ID, 'post_status' => 'draft' ] );
		}

		\wp_send_json_success( [ 'id' => $post->ID ] );
	}

	public function ajax_duplicate() {
		$this->guard();

		$post = $this->require_store( $_POST['store_id'] ?? 0, 'edit_post' );

		$new_id = \wp_insert_post(
			[
				'post_type'    => Module::CPT,
				'post_status'  => 'draft',
				/* translators: %s: original store name. */
				'post_title'   => \sprintf( \__( '%s (Copy)', 'anchor-schema' ), $post->post_title ),
				'post_content' => $post->post_content,
				'post_excerpt' => $post->post_excerpt,
			],
			true
		);

		if ( \is_wp_error( $new_id ) ) {
			\wp_send_json_error( [ 'message' => $new_id->get_error_message() ], 500 );
		}

		// Copy every store meta key, so fields added later come along for free.
		foreach ( \get_post_meta( $post->ID ) as $key => $values ) {
			if ( \strpos( $key, '_anchor_store_' ) !== 0 ) {
				continue;
			}
			foreach ( $values as $value ) {
				\add_post_meta( $new_id, $key, \maybe_unserialize( $value ) );
			}
		}

		$thumb_id = \get_post_thumbnail_id( $post->ID );
		if ( $thumb_id ) {
			\set_post_thumbnail( $new_id, $thumb_id );
		}

		\wp_send_json_success( [ 'id' => (int) $new_id ] );
	}

	/* =====================================================================
	   AJAX: bulk
	   ===================================================================== */

	public function ajax_bulk() {
		$this->guard();

		$action = \sanitize_key( $_POST['bulk_action'] ?? '' );
		$ids    = \array_filter( \array_map( 'intval', (array) ( $_POST['ids'] ?? [] ) ) );

		$allowed = [ 'publish', 'draft', 'trash', 'restore', 'delete' ];
		if ( ! \in_array( $action, $allowed, true ) ) {
			\wp_send_json_error( [ 'message' => \__( 'Unknown bulk action.', 'anchor-schema' ) ], 400 );
		}
		if ( empty( $ids ) ) {
			\wp_send_json_error( [ 'message' => \__( 'Select at least one store first.', 'anchor-schema' ) ], 400 );
		}

		$needs_delete_cap = \in_array( $action, [ 'trash', 'restore', 'delete' ], true );
		$done             = 0;
		$skipped          = 0;

		foreach ( $ids as $id ) {
			$post = \get_post( $id );
			if ( ! $post || $post->post_type !== Module::CPT ) {
				$skipped++;
				continue;
			}

			$cap = $needs_delete_cap ? 'delete_post' : 'edit_post';
			if ( ! \current_user_can( $cap, $id ) ) {
				$skipped++;
				continue;
			}

			switch ( $action ) {
				case 'publish':
				case 'draft':
					\wp_update_post( [ 'ID' => $id, 'post_status' => $action ] );
					break;
				case 'trash':
					\wp_trash_post( $id );
					break;
				case 'restore':
					\wp_untrash_post( $id );
					$restored = \get_post( $id );
					if ( $restored && ! isset( $this->editable_statuses()[ $restored->post_status ] ) ) {
						\wp_update_post( [ 'ID' => $id, 'post_status' => 'draft' ] );
					}
					break;
				case 'delete':
					\wp_delete_post( $id, true );
					break;
			}

			$done++;
		}

		\wp_send_json_success(
			[
				'action'  => $action,
				'done'    => $done,
				'skipped' => $skipped,
			]
		);
	}

	/* =====================================================================
	   Heartbeat nonce refresh
	   ===================================================================== */

	/**
	 * Hand the page a fresh nonce every heartbeat.
	 *
	 * The manager lives on a front-end page that can sit open for days; without
	 * this, every save after the nonce lifetime dies with a generic failure.
	 */
	public function heartbeat_received( $response, $data ) {
		if ( empty( $data['anchor_store_manager'] ) || ! $this->can_manage() ) {
			return $response;
		}

		$response['anchor_store_manager'] = [
			'nonce'       => \wp_create_nonce( self::NONCE ),
			'placesNonce' => \wp_create_nonce( Module::ADMIN_NONCE ),
		];

		return $response;
	}
}
