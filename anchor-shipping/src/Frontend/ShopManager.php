<?php
declare(strict_types=1);

namespace Anchor\Shipping\Frontend;

use Anchor\Shipping\Admin\Ajax;
use Anchor\Shipping\Admin\OrderPanel;
use Anchor\Shipping\Carriers\CarrierRegistry;
use Anchor\Shipping\Database\ShipmentRepository;
use Anchor\Shipping\Module;
use Anchor\Shipping\Packing\Packer;
use Anchor\Shipping\Services\LabelService;
use Anchor\Shipping\Support\Settings;
use Anchor\Shipping\Support\TrackingLinks;
use Anchor\Shipping\Support\Urls;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * [shop_manager]: the front-end shipping console (orders list + order view) so shop staff never open wp-admin.
 * Markup is plain and `asm-` prefixed; the client theme wraps and styles it.
 */
final class ShopManager {

	public const PER_PAGE = 20;

	private const STATE_TABS = [ 'labelled', 'shipped', 'delivered' ];

	private TrackingLinks $tracking;

	public function __construct(
		private LabelService $labels,
		private ShipmentRepository $shipments,
		private CarrierRegistry $carriers,
		private Packer $packer
	) {
		$this->tracking = new TrackingLinks( $shipments, $carriers );
		\add_shortcode( 'shop_manager', [ $this, 'shortcode' ] );
		\add_action( 'template_redirect', [ $this, 'maybe_mark_private' ] );
		\add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue' ] );
	}

	public static function capability(): string {
		return (string) \apply_filters( 'anchor_shipping_console_capability', 'edit_shop_orders' );
	}

	public static function can_use(): bool {
		return \is_user_logged_in() && \current_user_can( self::capability() );
	}

	/** @return array<string,string> tab slug => label */
	public static function tabs(): array {
		return [
			'needs-label' => \__( 'Needs label', 'anchor-schema' ),
			'labelled'    => \__( 'Labelled', 'anchor-schema' ),
			'shipped'     => \__( 'Shipped', 'anchor-schema' ),
			'delivered'   => \__( 'Delivered', 'anchor-schema' ),
			'all'         => \__( 'All', 'anchor-schema' ),
		];
	}

	public function shortcode(): string {
		return $this->render( [
			'tab'   => isset( $_GET['tab'] ) ? \sanitize_key( \wp_unslash( $_GET['tab'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification -- read-only view state
			's'     => isset( $_GET['s'] ) ? \sanitize_text_field( \wp_unslash( $_GET['s'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			'paged' => isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1, // phpcs:ignore WordPress.Security.NonceVerification
			'order' => isset( $_GET['order'] ) ? (int) $_GET['order'] : 0, // phpcs:ignore WordPress.Security.NonceVerification
			'bulk'  => ! empty( $_GET['bulk'] ),  // phpcs:ignore WordPress.Security.NonceVerification
			'ids'   => isset( $_GET['ids'] ) ? self::parse_ids( \wp_unslash( $_GET['ids'] ) ) : [], // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		] );
	}

	/** The console shows customer data: never cache it, never index it. */
	public function mark_private(): void {
		if ( ! \headers_sent() ) {
			\nocache_headers();
		}
		\add_filter( 'wp_robots', 'wp_robots_no_robots' );
	}

	public function maybe_mark_private(): void {
		if ( $this->page_has_shortcode() ) {
			$this->mark_private();
		}
	}

	public function maybe_enqueue(): void {
		if ( self::can_use() && $this->page_has_shortcode() ) {
			$this->enqueue();
		}
	}

	private function page_has_shortcode(): bool {
		$post = \is_singular() ? \get_post() : null;
		return $post && \has_shortcode( (string) $post->post_content, 'shop_manager' );
	}

	public static function enqueue(): void {
		OrderPanel::enqueue();
		\wp_enqueue_style( 'anchor-shipping-console', \ANCHOR_TOOLS_PLUGIN_URL . 'anchor-shipping/assets/console.css', [ 'anchor-shipping-admin' ], '1.0.0' );
		\wp_enqueue_script( 'anchor-shipping-console', \ANCHOR_TOOLS_PLUGIN_URL . 'anchor-shipping/assets/console.js', [ 'jquery', 'anchor-shipping-admin' ], '1.0.0', true );
		\wp_localize_script( 'anchor-shipping-console', 'anchorShippingConsole', [
			'confirmCancel' => \__( 'Cancel this order? Any labels will be voided.', 'anchor-schema' ),
			'confirmDone'   => \__( 'Mark this order completed?', 'anchor-schema' ),
			'confirmDoneOnHold' => \__( 'This order is on hold (payment may not be confirmed). Mark it completed anyway?', 'anchor-schema' ),
			'confirmBulk'   => \__( 'Create %d labels? Each one is billed by the carrier.', 'anchor-schema' ),
			'working'       => \__( 'Creating labels, this can take a minute…', 'anchor-schema' ),
		] );
	}

	/** Order ids from `ids[]=1&ids[]=2` or `ids=1,2`; positive, unique, capped at the bulk limit. @return int[] */
	public static function parse_ids( $raw ): array {
		$raw = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $raw ), static fn( int $i ): bool => $i > 0 ) ) );
		return array_slice( $ids, 0, ConsoleAjax::BULK_SELECT_MAX );
	}

	/** @param array{tab?:string,s?:string,paged?:int,order?:int,bulk?:bool,ids?:int[]} $args */
	public function render( array $args = [] ): string {
		$this->mark_private();
		if ( ! \is_user_logged_in() ) {
			$here = (string) \get_permalink();
			return '<p class="asm-login"><a href="' . \esc_url( \wp_login_url( '' !== $here ? $here : \home_url( '/' ) ) ) . '">' . \esc_html__( 'Log in to use the shipping console.', 'anchor-schema' ) . '</a></p>';
		}
		if ( ! self::can_use() ) {
			return '<p class="asm-denied">' . \esc_html__( 'You are not permitted to view this page.', 'anchor-schema' ) . '</p>';
		}
		$this->enqueue();

		if ( ! empty( $args['bulk'] ) ) {
			return '<div class="asm-console">' . $this->bulk_view( (array) ( $args['ids'] ?? [] ), $this->url() ) . '</div>';
		}
		$out = (int) ( $args['order'] ?? 0 ) > 0 ? $this->render_order( (int) $args['order'] ) : $this->render_list( $args );
		return '<div class="asm-console">' . $out . '</div>';
	}

	/** Console URL for a view; stays on the page the shortcode sits on. */
	public function url( array $args = [] ): string {
		$base = (string) \get_permalink();
		$base = '' !== $base ? $base : Urls::console();
		$base = '' !== $base ? $base : \home_url( '/' );
		return \add_query_arg( $args, $base );
	}

	private function render_list( array $args ): string {
		$tabs = self::tabs();
		if ( ConsoleProducts::can_use() ) {
			$tabs['products'] = \__( 'Products', 'anchor-schema' );
		}
		$tab  = isset( $tabs[ $args['tab'] ?? '' ] ) ? (string) $args['tab'] : 'needs-label';
		$s    = trim( (string) ( $args['s'] ?? '' ) );
		$page = max( 1, (int) ( $args['paged'] ?? 1 ) );

		if ( 'products' === $tab ) {
			return $this->render_products( $tabs, $s, $page );
		}
		[ $ids, $pages ] = $this->query( $tab, $s, $page );
		$rows = [];
		foreach ( $ids as $id ) {
			$order = \wc_get_order( $id );
			if ( $order instanceof \WC_Order ) {
				$rows[] = [
					'order'    => $order,
					'url'      => $this->url( [ 'order' => $id ] ),
					'state'    => self::state_label( (string) $order->get_meta( LabelService::STATE_META ) ),
					'tracking' => $this->tracking->for_order( $id ),
				];
			}
		}
		return $this->template( 'orders', [
			'tabs'    => $tabs,
			'tab'     => $tab,
			's'       => $s,
			'rows'    => $rows,
			'page'    => $page,
			'pages'   => $pages,
			'tab_url' => fn( string $t ): string => $this->url( [ 'tab' => $t, 's' => '' !== $s ? $s : null ] ),
			'page_url' => fn( int $p ): string => $this->url( [ 'tab' => $tab, 's' => '' !== $s ? $s : null, 'paged' => $p > 1 ? $p : null ] ),
			'action'  => $this->url(),
			'bulk'    => 'needs-label' === $tab,
		] );
	}

	/**
	 * Confirm screen for labelling several orders: one editable row per order, prefilled from the packer.
	 * Used by the console (?bulk=1&ids=…) and by the wp-admin fallback screen.
	 *
	 * @param int[] $ids
	 */
	public function bulk_view( array $ids, string $back ): string {
		$entries = [];
		foreach ( self::parse_ids( $ids ) as $id ) {
			$order = \wc_get_order( $id );
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			$entry = [ 'order' => $order, 'url' => Urls::order( $order ), 'blocked' => '', 'box' => '', 'weight' => '', 'note' => '' ];
			if ( $this->labels->is_labelled( $order ) ) {
				$entry['blocked'] = \__( 'Already has a label.', 'anchor-schema' );
			} elseif ( ! $this->labels->is_shippable( $order ) ) {
				$entry['blocked'] = \__( 'Nothing to ship on this order.', 'anchor-schema' );
			} else {
				$pack = $this->packer->pack_order( $order );
				$entry['box']    = (string) ( $pack->suggestion['box'] ?? '' );
				$entry['weight'] = isset( $pack->suggestion['weight'] ) ? (string) $pack->suggestion['weight'] : '';
				$entry['note']   = $pack->reason;
			}
			$entries[] = $entry;
		}
		return self::render_template( 'bulk', [
			'entries' => $entries,
			'boxes'   => Settings::boxes(),
			'unit'    => (string) \get_option( 'woocommerce_weight_unit' ),
			'back'    => $back,
		] );
	}

	private function render_products( array $tabs, string $s, int $page ): string {
		$products = new ConsoleProducts();
		[ $rows, $pages ] = $products->listing( $s, $page );
		return $this->template( 'products', [
			'tabs'     => $tabs,
			'tab'      => 'products',
			's'        => $s,
			'rows'     => $rows,
			'products' => $products,
			'page'     => $page,
			'pages'    => $pages,
			'tab_url'  => fn( string $t ): string => $this->url( [ 'tab' => $t ] ),
			'page_url' => fn( int $p ): string => $this->url( [ 'tab' => 'products', 's' => '' !== $s ? $s : null, 'paged' => $p > 1 ? $p : null ] ),
			'action'   => $this->url(),
		] );
	}

	/**
	 * @return array{0:int[],1:int} order ids for the page, and the page count
	 *
	 * Store-agnostic on purpose: the legacy (posts) order store ignores `meta_query` and `s` in wc_get_orders(),
	 * so state tabs use meta_key/meta_value (both stores) and search goes through the data store's search_orders().
	 */
	private function query( string $tab, string $s, int $page ): array {
		$base = [ 'orderby' => 'date', 'order' => 'DESC', 'return' => 'ids', 'status' => \array_keys( \wc_get_order_statuses() ) ];
		if ( '' !== $s ) {
			$ids = array_map( 'intval', (array) \WC_Data_Store::load( 'order' )->search_orders( $s ) );
			return $this->paginate( array_values( array_filter( $ids, fn( int $id ): bool => $this->in_tab( $id, $tab ) ) ), $page );
		}
		if ( 'needs-label' === $tab ) {
			$flagged = \wc_get_orders( $base + [ 'limit' => -1, 'meta_key' => LabelService::STATE_META, 'meta_value' => 'needs_attention' ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
			$open    = \wc_get_orders( [ 'status' => [ 'wc-processing' ], 'limit' => -1 ] + $base );
			$ids     = [];
			foreach ( array_merge( (array) $flagged, (array) $open ) as $id ) {
				if ( $this->in_tab( (int) $id, 'needs-label' ) ) {
					$ids[] = (int) $id;
				}
			}
			return $this->paginate( array_values( array_unique( $ids ) ), $page );
		}
		$q = $base + [ 'limit' => self::PER_PAGE, 'paged' => $page, 'paginate' => true ];
		if ( in_array( $tab, self::STATE_TABS, true ) ) {
			$q['meta_key']   = LabelService::STATE_META; // phpcs:ignore WordPress.DB.SlowDBQuery
			$q['meta_value'] = $tab; // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		$res = \wc_get_orders( $q );
		return [ array_map( 'intval', (array) $res->orders ), (int) $res->max_num_pages ];
	}

	/** Tab membership. Needs label = a live needs_attention flag (see LabelService::has_live_attention_flag), OR processing + shippable + no active label. */
	private function in_tab( int $id, string $tab ): bool {
		$order = \wc_get_order( $id );
		if ( ! $order instanceof \WC_Order || in_array( $order->get_status(), [ 'trash', 'auto-draft' ], true ) ) {
			return false;
		}
		$state = (string) $order->get_meta( LabelService::STATE_META );
		if ( 'all' === $tab ) {
			return true;
		}
		if ( in_array( $tab, self::STATE_TABS, true ) ) {
			return $tab === $state;
		}
		return $this->labels->has_live_attention_flag( $order )
			|| ( $order->has_status( 'processing' ) && ! in_array( $state, self::STATE_TABS, true ) && $this->labels->is_shippable( $order ) && ! $this->labels->is_labelled( $order ) );
	}

	/** @return array{0:int[],1:int} */
	private function paginate( array $ids, int $page ): array {
		rsort( $ids ); // newest first (ids grow with creation)
		$pages = max( 1, (int) ceil( count( $ids ) / self::PER_PAGE ) );
		return [ array_slice( $ids, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE ), $pages ];
	}

	private function render_order( int $id ): string {
		$order = \wc_get_order( $id );
		if ( ! $order instanceof \WC_Order ) {
			return '<p class="asm-error">' . \esc_html__( 'Order not found.', 'anchor-schema' ) . '</p><p><a href="' . \esc_url( $this->url() ) . '">' . \esc_html__( 'Back to orders', 'anchor-schema' ) . '</a></p>';
		}
		$latest = null;
		foreach ( array_reverse( $this->shipments->active_for_order( $id ) ) as $row ) {
			if ( '' !== (string) $row['label_path'] ) {
				$latest = $row;
				break;
			}
		}
		$panel = new OrderPanel( $this->shipments, $this->packer, $this->carriers );
		return $this->template( 'order', [
			'order'     => $order,
			'back'      => $this->url(),
			'state'     => self::state_label( (string) $order->get_meta( LabelService::STATE_META ) ),
			'tracking'  => $this->tracking->for_order( $id ),
			'notes'     => \wc_get_order_notes( [ 'order_id' => $id ] ),
			'panel'     => $panel->render_panel( $order ),
			'label_url' => $latest ? Ajax::download_url( (int) $latest['id'] ) : '',
			'label_pdf' => $latest && 'ZPL' !== $latest['label_format'],
			'can_complete' => $order->has_status( [ 'processing', 'on-hold' ] ),
			'can_cancel'   => $order->has_status( [ 'pending', 'processing', 'on-hold' ] ),
		] );
	}

	public static function state_label( string $state ): string {
		$labels = [
			'needs_attention' => \__( 'Needs attention', 'anchor-schema' ),
			'labelled'        => \__( 'Labelled', 'anchor-schema' ),
			'shipped'         => \__( 'Shipped', 'anchor-schema' ),
			'delivered'       => \__( 'Delivered', 'anchor-schema' ),
		];
		return $labels[ $state ] ?? '';
	}

	private function template( string $name, array $vars ): string {
		return self::render_template( $name, $vars );
	}

	/** Renders templates/console/<name>.php; shared by the console views and AJAX-returned fragments. */
	public static function render_template( string $name, array $vars ): string {
		\ob_start();
		( static function ( string $file, array $vars ): void {
			extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
			include $file;
		} )( Module::dir() . '/templates/console/' . $name . '.php', $vars );
		return (string) \ob_get_clean();
	}
}
