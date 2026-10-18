<?php
/**
 * Library AJAX: Product Docs, the WooCommerce documents buyers can download (meta _anchor_pd_docs).
 *
 * Ported from Anchor Private File Manager 2.17.0; request and response shapes, nonce and permission checks unchanged.
 *
 * @package Anchor\Library
 */

namespace Anchor\Library\Ajax;

use Anchor\Library\Activity;
use Anchor\Library\Http;
use Anchor\Library\Permissions;
use Anchor\Library\Repository;
use Anchor\Library\Schema;
use Anchor\Library\Storage;
use Anchor\Library\StorageMonitor;
use Anchor\Library\Uploads;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class ProductDocs {

	/** AJAX action => handler; Ajax\Registry hooks each as wp_ajax_{action}. */
	public const ACTIONS = [
		'anchor_pd_products' => 'ajax_pd_products',
		'anchor_pd_save_docs' => 'ajax_pd_save_docs',
		'anchor_pd_my_docs' => 'ajax_pd_my_docs',
		'anchor_pd_upload' => 'ajax_pd_upload',
	];

	public static function ajax_pd_products() {
		Http::auth();
		if (!\current_user_can('manage_woocommerce')) Http::error('Forbidden', 403);
		if (!\function_exists('wc_get_products')) Http::error('WooCommerce not available', 400);

		$products = \wc_get_products([
			'status' => 'publish',
			'limit' => -1,
			'orderby' => 'title',
			'order' => 'ASC',
			'return' => 'objects',
		]);

		$out = [];
		foreach ((array) $products as $product) {
			$docs = Permissions::product_docs($product->get_id());
			$out[] = [
				'id' => (int) $product->get_id(),
				'name' => $product->get_name(),
				'docs' => \array_values(\array_map(function ($d) {
					return [
						'fileId' => (int) $d['fileId'],
						'title' => $d['title'],
						'expires' => $d['expires'],
					];
				}, $docs)),
			];
		}

		Http::success(['products' => $out]);
	}

	/**
	 * The posted product, if it is a WooCommerce product the current user may
	 * edit; otherwise a 404/403. Every product-document write goes through it.
	 */
	private static function require_editable_product($product_id) {
		$product_id = (int) $product_id;
		$post = $product_id > 0 ? \get_post($product_id) : null;
		// Only parent products: documents are resolved from post_type=product.
		if (!$post || $post->post_type !== 'product') {
			Http::error('Product not found', 404);
		}
		if (!\current_user_can('edit_post', $product_id)) Http::error('Forbidden', 403);
		return $product_id;
	}

	/**
	 * Whether $user_id may attach $file_id to a product. Attaching is a grant —
	 * every buyer of the product can then download the file — so viewing the
	 * file is not enough (AFM-01). Allowed: files the user can manage, and
	 * files in the Product Docs area that exists for exactly this purpose.
	 */
	private static function can_publish_product_doc($user_id, $file_id) {
		$file = Repository::file_row($file_id);
		if (!$file) return false;
		if (Permissions::can_manage_file($file_id, $user_id)) return true;
		$pd_folder = (int) \get_option(Schema::OPT_PD_FOLDER_ID, 0);
		if ($pd_folder <= 0) return false;
		$folder_id = (int) $file->folder_id;
		return $folder_id === $pd_folder || Repository::is_descendant($folder_id, $pd_folder);
	}

	public static function ajax_pd_save_docs() {
		Http::auth();
		if (!\current_user_can('manage_woocommerce')) Http::error('Forbidden', 403);

		$product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
		$docs = isset($_POST['docs']) ? (array) $_POST['docs'] : [];
		if ($product_id <= 0) Http::error('Missing product_id');
		self::require_editable_product($product_id);

		$user_id = \get_current_user_id();
		// Documents already on the product were authorized when attached;
		// re-saving them (e.g. while removing a different one) grants nothing new.
		$already = \array_map('intval', \array_column(Permissions::product_docs($product_id), 'fileId'));
		$clean = [];
		foreach ($docs as $doc) {
			if (!\is_array($doc)) continue;
			$file_id = isset($doc['fileId']) ? (int) $doc['fileId'] : 0;
			if ($file_id <= 0) continue;
			// A reference to a deleted file grants nothing; drop it rather
			// than make the product impossible to save.
			if (!Repository::file_row($file_id)) continue;
			// All or nothing: one unauthorized file refuses the whole save.
			if (!\in_array($file_id, $already, true) && !self::can_publish_product_doc($user_id, $file_id)) {
				Http::error(\sprintf('You cannot attach file #%d to a product. Upload it to Product Docs, or ask an administrator.', $file_id), 403);
			}
			$title = isset($doc['title']) ? \sanitize_text_field((string) $doc['title']) : '';
			$expires = isset($doc['expires']) ? \sanitize_text_field((string) $doc['expires']) : '';
			$clean[] = [
				'fileId' => $file_id,
				'title' => $title,
				'expires' => $expires,
				'fileName' => isset($doc['fileName']) ? \sanitize_text_field((string) $doc['fileName']) : '',
			];
		}

		\update_post_meta($product_id, Permissions::META_PRODUCT_DOCS, $clean);
		Activity::log('save_product_docs', 'product', $product_id, ['count' => \count($clean)], \get_current_user_id());
		Http::success(['saved' => true]);
	}

	public static function ajax_pd_my_docs() {
		Http::auth();
		if (!\function_exists('wc_get_products')) Http::error('WooCommerce not available', 400);

		$user_id = \get_current_user_id();
		$products = \get_posts([
			'post_type' => 'product',
			'posts_per_page' => -1,
			'meta_key' => Permissions::META_PRODUCT_DOCS,
			'post_status' => 'publish',
		]);

		$docs_out = [];
		foreach ((array) $products as $p) {
			$pid = (int) $p->ID;
			if (!Permissions::user_has_product($user_id, $pid)) continue;
			$docs = Permissions::product_docs($pid);
			foreach ($docs as $doc) {
				if (Permissions::doc_is_expired($doc['expires'])) continue;
				$file = Repository::file_row((int) $doc['fileId']);
				if (!$file) continue;
				$nonce = \wp_create_nonce('anchor_fm_stream_' . (int) $file->id);
				$download_url = \add_query_arg([
					'action' => 'anchor_fm_stream',
					'file_id' => (int) $file->id,
					'disposition' => 'attachment',
					'nonce' => $nonce,
				], \admin_url('admin-ajax.php'));
				// A row is not a promise of bytes. Handing out a Download link
				// the stream endpoint will answer with a bare 404 strands the
				// customer on a browser error page, so resolve the file here
				// and let the client render the difference.
				$path = Storage::path_for($file);
				$available = @\file_exists($path) && @\is_readable($path);
				if (!$available) {
					Storage::log_refusal((int) $file->id, 'product docs: ' . Storage::describe_unreadable($path));
				}

				$docs_out[] = [
					'fileId' => (int) $file->id,
					'title' => $doc['title'] ?: $file->original_name,
					'product' => \get_the_title($pid),
					'productId' => $pid,
					'expires' => $doc['expires'],
					'downloadUrl' => $download_url,
					'available' => $available,
				];
			}
		}

		Http::success(['docs' => $docs_out]);
	}

	public static function ajax_pd_upload() {
		Http::auth();
		if (!\current_user_can('manage_woocommerce')) Http::error('Forbidden', 403);

		$product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
		if ($product_id <= 0) Http::error('Missing product_id');
		self::require_editable_product($product_id);
		if (empty($_FILES['file'])) Http::error('No file');
		StorageMonitor::require_not_exposed();

		$folder_id = Schema::ensure_product_docs_folder();
		$folder_dir = Storage::ensure_folder_dir($folder_id);

		$file = $_FILES['file'];
		$original = (string) $file['name'];
		$tmp = (string) $file['tmp_name'];
		$size = (int) $file['size'];
		if (!\file_exists($tmp) || !\is_uploaded_file($tmp)) {
			Http::error('Upload failed', 400);
		}

		$valid = Uploads::validate_type($tmp, \sanitize_file_name($original));
		if ($valid === false) {
			Http::error('File type not allowed', 415);
		}
		$mime = $valid['type'];
		$unique = Storage::reserve_stored_name($folder_dir, $valid['filename']);
		if ($unique === false) Http::error('Could not save file', 500);
		$dest = \trailingslashit($folder_dir) . $unique;

		if (!@\move_uploaded_file($tmp, $dest)) {
			@\unlink($dest);
			Http::error('Could not save file', 500);
		}

		global $wpdb;
		$files_table = Schema::table('files');
		$wpdb->insert($files_table, [
			'folder_id' => $folder_id,
			'original_name' => $original,
			'stored_name' => $unique,
			'mime_type' => $mime,
			'size' => $size,
			'sha1' => @\sha1_file($dest) ?: null,
			'uploader_user_id' => \get_current_user_id(),
			'created_at' => \current_time('mysql'),
		]);
		$file_id = (int) $wpdb->insert_id;

		Activity::log('upload_product_doc', 'file', $file_id, ['product_id' => $product_id, 'name' => $original], \get_current_user_id());

		Http::success(['file' => [
			'id' => $file_id,
			'name' => $original,
			'mime' => $mime,
			'size' => $size,
		]]);
	}
}
