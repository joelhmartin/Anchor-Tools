<?php
declare(strict_types=1);

namespace Anchor\CheckoutQuestions\Admin;

use Anchor\CheckoutQuestions\Support\QuestionSets;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Edit screen for a question set: the questions, and which products/categories ask them. */
final class SetEditor {

	private const NONCE_ACTION = 'anchor_cq_save_set';
	private const NONCE_FIELD  = 'anchor_cq_set_nonce';
	private const PREFIX       = 'anchor_cq_questions';

	public function __construct() {
		\add_action( 'add_meta_boxes_' . QuestionSets::CPT, [ $this, 'add_boxes' ] );
		\add_action( 'save_post_' . QuestionSets::CPT, [ $this, 'save' ], 10, 2 );
		\add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function add_boxes(): void {
		\add_meta_box( 'anchor_cq_questions', \__( 'Questions', 'anchor-schema' ), [ $this, 'render_questions' ], QuestionSets::CPT, 'normal', 'high' );
		\add_meta_box( 'anchor_cq_where', \__( 'Where it applies', 'anchor-schema' ), [ $this, 'render_where' ], QuestionSets::CPT, 'normal', 'default' );
	}

	public function enqueue( string $hook ): void {
		if ( ! \in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}
		$screen = \get_current_screen();
		if ( ! $screen || QuestionSets::CPT !== $screen->post_type ) {
			return;
		}
		\Anchor_Questions_Editor::enqueue();
		\wp_enqueue_script( 'wc-enhanced-select' );
	}

	public function render_questions( \WP_Post $post ): void {
		\wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		echo $this->questions_box_html( $post ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the editor
	}

	public function questions_box_html( \WP_Post $post ): string {
		$html  = '<p class="description">' . \esc_html__( 'Buyers answer these at checkout when their cart matches the products or categories below.', 'anchor-schema' ) . '</p>';
		$html .= \Anchor_Questions_Editor::repeater_html( self::PREFIX, QuestionSets::questions( (int) $post->ID ) );
		return $html;
	}

	public function render_where( \WP_Post $post ): void {
		$set_id   = (int) $post->ID;
		$products = [];
		foreach ( QuestionSets::product_ids( $set_id ) as $pid ) {
			$product = \wc_get_product( $pid );
			if ( $product ) {
				$products[ $pid ] = \wp_kses_post( $product->get_formatted_name() );
			}
		}
		$selected_cats = QuestionSets::category_ids( $set_id );
		?>
		<p>
			<label for="anchor_cq_product_ids"><strong><?php echo \esc_html__( 'Products', 'anchor-schema' ); ?></strong></label><br />
			<select id="anchor_cq_product_ids" class="wc-product-search" multiple="multiple" style="width:100%;" name="anchor_cq_product_ids[]" data-placeholder="<?php echo \esc_attr__( 'Search for a product…', 'anchor-schema' ); ?>" data-action="woocommerce_json_search_products">
				<?php foreach ( $products as $pid => $name ) : ?>
					<option value="<?php echo \esc_attr( (string) $pid ); ?>" selected="selected"><?php echo \wp_kses_post( $name ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p><strong><?php echo \esc_html__( 'Product categories', 'anchor-schema' ); ?></strong></p>
		<ul class="anchor-cq-categories" style="max-height:200px;overflow:auto;margin:0;">
			<?php foreach ( (array) \get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] ) as $term ) : ?>
				<?php if ( ! $term instanceof \WP_Term ) { continue; } ?>
				<li><label><input type="checkbox" name="anchor_cq_category_ids[]" value="<?php echo \esc_attr( (string) $term->term_id ); ?>" <?php \checked( \in_array( $term->term_id, $selected_cats, true ) ); ?> /> <?php echo \esc_html( $term->name ); ?></label></li>
			<?php endforeach; ?>
		</ul>
		<input type="hidden" name="anchor_cq_where_present" value="1" />
		<p class="description"><?php echo \esc_html__( 'The set applies to a cart line that is one of these products or in one of these categories. Event tickets match only when picked as a product.', 'anchor-schema' ); ?></p>
		<?php
	}

	public function save( int $post_id, \WP_Post $post ): void {
		if ( \defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? \sanitize_text_field( \wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';
		if ( ! $nonce || ! \wp_verify_nonce( $nonce, self::NONCE_ACTION ) || ! \current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$this->save_questions( $post_id, $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above
		if ( isset( $_POST['anchor_cq_where_present'] ) || isset( $_POST['anchor_cq_product_ids'] ) || isset( $_POST['anchor_cq_category_ids'] ) ) {
			// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified above; reduced to ints by QuestionSets::ids
			$this->save_ids( $post_id, QuestionSets::META_PRODUCTS, self::parent_product_ids( $_POST['anchor_cq_product_ids'] ?? [] ) );
			$this->save_ids( $post_id, QuestionSets::META_CATEGORIES, $_POST['anchor_cq_category_ids'] ?? [] );
			// phpcs:enable
		}
	}

	/** Mirrors the Events console: no marker and no rows means this form did not edit the questions. */
	private function save_questions( int $post_id, array $src ): void {
		if ( ! isset( $src[ self::PREFIX ] ) && empty( $src[ self::PREFIX . '_present' ] ) ) {
			return;
		}
		$raw   = isset( $src[ self::PREFIX ] ) && \is_array( $src[ self::PREFIX ] ) ? \wp_unslash( $src[ self::PREFIX ] ) : [];
		$clean = \Anchor_Questions::normalize( $raw );
		if ( empty( $clean ) ) {
			\delete_post_meta( $post_id, QuestionSets::META_QUESTIONS );
			return;
		}
		// wp_slash(): rows were unslashed above and update_post_meta() unslashes again.
		\update_post_meta( $post_id, QuestionSets::META_QUESTIONS, \wp_slash( $clean ) );
	}

	/** @param mixed $raw */
	private function save_ids( int $post_id, string $meta_key, $raw ): void {
		$ids = QuestionSets::ids( \is_array( $raw ) ? $raw : [] );
		if ( $ids ) {
			\update_post_meta( $post_id, $meta_key, $ids );
		} else {
			\delete_post_meta( $post_id, $meta_key );
		}
	}

	/**
	 * The matcher compares parent product ids only, so a posted variation id
	 * (hand-posted or legacy) is mapped to its parent product; deduped after mapping.
	 *
	 * @param mixed $raw
	 * @return int[]
	 */
	private static function parent_product_ids( $raw ): array {
		$out = [];
		foreach ( QuestionSets::ids( \is_array( $raw ) ? $raw : [] ) as $id ) {
			if ( 'product_variation' === \get_post_type( $id ) ) {
				$id = (int) \wp_get_post_parent_id( $id );
			}
			if ( $id > 0 ) {
				$out[ $id ] = $id;
			}
		}
		return \array_values( $out );
	}
}
