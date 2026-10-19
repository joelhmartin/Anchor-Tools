<?php
/**
 * Library AJAX: the account portal's orders, profile and password endpoints.
 *
 * Ported from Anchor Private File Manager 2.17.0; request and response shapes, nonce and permission checks unchanged.
 *
 * @package Anchor\Library
 */

namespace Anchor\Library\Ajax;

use Anchor\Library\Activity;
use Anchor\Library\Http;
use Anchor\Library\Users;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Account {

	/** AJAX action => handler; Ajax\Registry hooks each as wp_ajax_{action}. */
	public const ACTIONS = [
		'anchor_ap_orders' => 'ajax_ap_orders',
		'anchor_ap_order' => 'ajax_ap_order',
		'anchor_ap_update_profile' => 'ajax_ap_update_profile',
		'anchor_ap_change_password' => 'ajax_ap_change_password',
		'anchor_ap_send_reset' => 'ajax_ap_send_reset',
	];

	public static function ajax_ap_orders() {
		Http::auth();
		if (!\function_exists('wc_get_orders')) Http::error('WooCommerce not available', 400);

		$user_id = \get_current_user_id();
		$page = isset($_POST['page']) ? \max(1, (int) $_POST['page']) : 1;
		$per_page = 20;

		$orders = \wc_get_orders([
			'customer_id' => $user_id,
			'limit' => $per_page,
			'paged' => $page,
			'orderby' => 'date',
			'order' => 'DESC',
		]);

		$out = [];
		foreach ((array) $orders as $order) {
			if (!\is_a($order, 'WC_Order')) continue;
			$out[] = [
				'id' => (int) $order->get_id(),
				'number' => $order->get_order_number(),
				'status' => $order->get_status(),
				'statusLabel' => \function_exists('wc_get_order_status_name') ? \wc_get_order_status_name($order->get_status()) : $order->get_status(),
				'date' => $order->get_date_created() ? $order->get_date_created()->date_i18n(\get_option('date_format')) : '',
				'total' => $order->get_total(),
				'totalHtml' => \function_exists('wc_price') ? \wc_price($order->get_total(), ['currency' => $order->get_currency()]) : (string) $order->get_total(),
				'items' => (int) $order->get_item_count(),
			];
		}

		Http::success(['orders' => $out, 'page' => $page]);
	}

	public static function ajax_ap_order() {
		Http::auth();
		if (!\function_exists('wc_get_order')) Http::error('WooCommerce not available', 400);

		$order_id = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
		if ($order_id <= 0) Http::error('Missing order_id');

		$order = \wc_get_order($order_id);
		if (!$order || !\is_a($order, 'WC_Order')) Http::error('Not found', 404);

		if ((int) $order->get_customer_id() !== (int) \get_current_user_id()) {
			Http::error('Forbidden', 403);
		}

		$items = [];
		foreach ($order->get_items() as $item) {
			if (!\is_a($item, 'WC_Order_Item_Product')) continue;
			$product = $item->get_product();
			$items[] = [
				'name' => $item->get_name(),
				'quantity' => (int) $item->get_quantity(),
				'totalHtml' => \function_exists('wc_price') ? \wc_price($item->get_total(), ['currency' => $order->get_currency()]) : (string) $item->get_total(),
				'sku' => $product ? (string) $product->get_sku() : '',
			];
		}

		Http::success([
			'order' => [
				'id' => (int) $order->get_id(),
				'number' => $order->get_order_number(),
				'status' => $order->get_status(),
				'statusLabel' => \function_exists('wc_get_order_status_name') ? \wc_get_order_status_name($order->get_status()) : $order->get_status(),
				'date' => $order->get_date_created() ? $order->get_date_created()->date_i18n(\get_option('date_format')) : '',
				'totalHtml' => \function_exists('wc_price') ? \wc_price($order->get_total(), ['currency' => $order->get_currency()]) : (string) $order->get_total(),
				'paymentMethod' => (string) $order->get_payment_method_title(),
			],
			'items' => $items,
		]);
	}

	public static function ajax_ap_update_profile() {
		Http::auth();

		$user_id = \get_current_user_id();
		$first = isset($_POST['first_name']) ? \sanitize_text_field((string) $_POST['first_name']) : '';
		$last = isset($_POST['last_name']) ? \sanitize_text_field((string) $_POST['last_name']) : '';
		$email = isset($_POST['user_email']) ? \sanitize_email((string) $_POST['user_email']) : '';

		if ($email === '' || !\is_email($email)) {
			Http::error('Invalid email address', 400);
		}

		$existing = \email_exists($email);
		if ($existing && (int) $existing !== (int) $user_id) {
			Http::error('Email already in use', 400);
		}

		$res = \wp_update_user([
			'ID' => $user_id,
			'user_email' => $email,
			'first_name' => $first,
			'last_name' => $last,
			'display_name' => \trim($first . ' ' . $last) ?: \wp_get_current_user()->display_name,
		]);
		if (\is_wp_error($res)) {
			Http::error($res->get_error_message(), 400);
		}

		Activity::log('update_profile', 'user', $user_id, [], $user_id);
		Http::success(['saved' => true]);
	}

	public static function ajax_ap_change_password() {
		Http::auth();

		$user_id = \get_current_user_id();
		$new = isset($_POST['new_password']) ? (string) $_POST['new_password'] : '';
		$new = \trim($new);
		$pv = \Anchor_FM_User_Admin::validate_password($new);
		if (!$pv['ok']) {
			Http::error($pv['error'], 400);
		}

		$user = \get_user_by('id', $user_id);
		if (!$user) Http::error('User not found', 404);

		\wp_set_password($new, $user_id);
		Activity::log('change_password', 'user', $user_id, [], $user_id);
		Http::success(['saved' => true, 'requiresReauth' => true, 'loginUrl' => \wp_login_url()]);
	}

	public static function ajax_ap_send_reset() {
		Http::auth();

		$user = \wp_get_current_user();
		$sent = Users::send_password_reset_email($user);
		if (\is_wp_error($sent)) Http::error($sent->get_error_message(), 400);
		Activity::log('send_password_reset', 'user', (int) $user->ID, [], (int) $user->ID);
		Http::success(['sent' => true]);
	}
}
