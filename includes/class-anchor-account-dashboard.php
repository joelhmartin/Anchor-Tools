<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Anchor_Account_Dashboard {
    public function __construct() {
        add_shortcode( 'anchor_account_dashboard', [ $this, 'render_dashboard' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function enqueue_assets() {
        if ( ! is_singular() ) {
            return;
        }
        wp_register_style( 'anchor-account-dashboard', false, [], ANCHOR_TOOLS_VERSION );
        $css = '
            .anchor-account{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:16px;margin:12px 0;font-family:system-ui,sans-serif;}
            .anchor-account h3{margin:16px 0 8px;font-size:18px;}
            .anchor-account table{width:100%;border-collapse:collapse;margin:8px 0;}
            .anchor-account th,.anchor-account td{padding:8px;border-bottom:1px solid #eee;text-align:left;font-size:14px;}
            .anchor-account .anchor-flex{display:flex;gap:12px;flex-wrap:wrap;}
            .anchor-account .anchor-card{border:1px solid #e5e7eb;border-radius:6px;padding:12px;margin:8px 0;}
            .anchor-account .anchor-btn{display:inline-block;padding:6px 12px;border-radius:4px;background:#2271b1;color:#fff;text-decoration:none;border:1px solid #1d5f90;font-size:13px;}
            .anchor-account .anchor-btn.secondary{background:#f3f4f6;color:#111;border-color:#d1d5db;}
            .anchor-account .anchor-nav{display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;}
            .anchor-account .anchor-nav a{padding:6px 10px;border-radius:999px;border:1px solid #e5e7eb;text-decoration:none;color:#111;font-size:13px;}
            .anchor-account .anchor-nav a.active{background:#2271b1;color:#fff;border-color:#1d5f90;}
            .anchor-account form .field{margin-bottom:10px;}
            .anchor-account label{font-weight:600;font-size:13px;display:block;margin-bottom:4px;}
            .anchor-account input[type=text],
            .anchor-account input[type=email],
            .anchor-account input[type=password]{width:100%;padding:8px;border:1px solid #d1d5db;border-radius:4px;font-size:14px;}
            .anchor-account .anchor-notice{padding:8px 10px;border:1px solid #d1d5db;border-radius:4px;background:#f8fafc;margin-bottom:8px;font-size:13px;}
        ';
        wp_add_inline_style( 'anchor-account-dashboard', $css );
        wp_enqueue_style( 'anchor-account-dashboard' );
    }

    public function render_dashboard() {
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return '<div class="anchor-account"><p>WooCommerce is required for the account dashboard.</p></div>';
        }
        ob_start();
        echo '<div class="anchor-account">';
        if ( ! is_user_logged_in() ) {
            echo '<div class="anchor-notice">Please log in to view your account.</div>';
            wp_login_form( [
                'redirect' => esc_url( get_permalink() ),
            ] );
            echo '</div>';
            return ob_get_clean();
        }

        wc_print_notices();

        $section = isset( $_GET['anchor_section'] ) ? sanitize_key( $_GET['anchor_section'] ) : 'overview';
        $order_id = isset( $_GET['anchor_order'] ) ? absint( $_GET['anchor_order'] ) : 0;

        $nav = [
            'overview' => 'Overview',
            'orders'   => 'Orders',
            'details'  => 'Account Details',
        ];
        echo '<div class="anchor-nav">';
        foreach ( $nav as $key => $label ) {
            $url = add_query_arg( 'anchor_section', $key );
            $class = $section === $key ? 'active' : '';
            echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</div>';

        if ( $section === 'orders' ) {
            $this->render_orders( $order_id );
        } elseif ( $section === 'details' ) {
            $this->render_account_details();
        } else {
            $this->render_overview();
        }

        echo '</div>';
        return ob_get_clean();
    }

    private function render_overview() {
        $customer = wc()->customer;
        echo '<div class="anchor-card">';
        echo '<h3>Welcome back, ' . esc_html( wp_get_current_user()->display_name ) . '</h3>';
        echo '<p>Email: ' . esc_html( wp_get_current_user()->user_email ) . '</p>';
        if ( $customer ) {
            echo '<p>Billing city: ' . esc_html( $customer->get_billing_city() ) . '</p>';
        }
        echo '<a class="anchor-btn" href="' . esc_url( add_query_arg( 'anchor_section', 'orders' ) ) . '">View Orders</a> ';
        echo '<a class="anchor-btn secondary" href="' . esc_url( add_query_arg( 'anchor_section', 'details' ) ) . '">Account Settings</a>';
        echo '</div>';
    }

    private function render_orders( $order_id ) {
        $user_id = get_current_user_id();
        if ( $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order || (int) $order->get_user_id() !== $user_id ) {
                echo '<div class="anchor-notice">Order not found.</div>';
                return;
            }
            echo '<h3>Order #' . esc_html( $order->get_order_number() ) . '</h3>';
            echo '<div class="anchor-card">';
            echo '<p>Status: ' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . '</p>';
            echo '<p>Date: ' . esc_html( wc_format_datetime( $order->get_date_created() ) ) . '</p>';
            echo '<p>Total: ' . wp_kses_post( $order->get_formatted_order_total() ) . '</p>';
            echo '<h4>Items</h4>';
            echo '<table><thead><tr><th>Product</th><th>Qty</th><th>Total</th></tr></thead><tbody>';
            foreach ( $order->get_items() as $item ) {
                echo '<tr><td>' . esc_html( $item->get_name() ) . '</td><td>' . esc_html( $item->get_quantity() ) . '</td><td>' . wp_kses_post( $order->get_formatted_line_subtotal( $item ) ) . '</td></tr>';
            }
            echo '</tbody></table>';
            echo '<a class="anchor-btn secondary" href="' . esc_url( add_query_arg( 'anchor_section', 'orders' ) ) . '">Back to Orders</a>';
            echo '</div>';
            return;
        }

        $orders = wc_get_orders( [
            'customer' => $user_id,
            'status'   => array_keys( wc_get_order_statuses() ),
            'limit'    => 20,
        ] );
        if ( empty( $orders ) ) {
            echo '<div class="anchor-notice">No recent orders.</div>';
            return;
        }
        echo '<h3>Your Orders</h3>';
        echo '<table><thead><tr><th>Order</th><th>Date</th><th>Status</th><th>Total</th><th></th></tr></thead><tbody>';
        foreach ( $orders as $order ) {
            $view_url = add_query_arg(
                [
                    'anchor_section' => 'orders',
                    'anchor_order'   => $order->get_id(),
                ]
            );
            echo '<tr>';
            echo '<td>#' . esc_html( $order->get_order_number() ) . '</td>';
            echo '<td>' . esc_html( wc_format_datetime( $order->get_date_created() ) ) . '</td>';
            echo '<td>' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . '</td>';
            echo '<td>' . wp_kses_post( $order->get_formatted_order_total() ) . '</td>';
            echo '<td><a class="anchor-btn secondary" href="' . esc_url( $view_url ) . '">View</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private function render_account_details() {
        $user = wp_get_current_user();
        $address = wc()->countries->get_formatted_address( [
            'first_name' => get_user_meta( $user->ID, 'billing_first_name', true ),
            'last_name'  => get_user_meta( $user->ID, 'billing_last_name', true ),
            'company'    => get_user_meta( $user->ID, 'billing_company', true ),
            'address_1'  => get_user_meta( $user->ID, 'billing_address_1', true ),
            'address_2'  => get_user_meta( $user->ID, 'billing_address_2', true ),
            'city'       => get_user_meta( $user->ID, 'billing_city', true ),
            'state'      => get_user_meta( $user->ID, 'billing_state', true ),
            'postcode'   => get_user_meta( $user->ID, 'billing_postcode', true ),
            'country'    => get_user_meta( $user->ID, 'billing_country', true ),
        ] );

        echo '<div class="anchor-card">';
        echo '<h3>Account Details</h3>';
        echo '<form method="post">';
        do_action( 'woocommerce_edit_account_form_tag' );
        wp_nonce_field( 'save_account_details' );
        echo '<div class="field"><label for="account_first_name">First name</label><input type="text" name="account_first_name" id="account_first_name" value="' . esc_attr( $user->first_name ) . '" required></div>';
        echo '<div class="field"><label for="account_last_name">Last name</label><input type="text" name="account_last_name" id="account_last_name" value="' . esc_attr( $user->last_name ) . '" required></div>';
        echo '<div class="field"><label for="account_display_name">Display name</label><input type="text" name="account_display_name" id="account_display_name" value="' . esc_attr( $user->display_name ) . '" required></div>';
        echo '<div class="field"><label for="account_email">Email address</label><input type="email" name="account_email" id="account_email" value="' . esc_attr( $user->user_email ) . '" required></div>';
        echo '<h4>Password change</h4>';
        echo '<div class="field"><label for="password_current">Current password</label><input type="password" name="password_current" id="password_current"></div>';
        echo '<div class="field"><label for="password_1">New password</label><input type="password" name="password_1" id="password_1"></div>';
        echo '<div class="field"><label for="password_2">Confirm new password</label><input type="password" name="password_2" id="password_2"></div>';
        echo '<input type="hidden" name="action" value="save_account_details">';
        echo '<button type="submit" class="anchor-btn">Save changes</button>';
        echo '</form>';
        echo '</div>';

        echo '<div class="anchor-card">';
        echo '<h3>Billing Address</h3>';
        if ( $address ) {
            echo wp_kses_post( wpautop( $address ) );
        } else {
            echo '<p>No billing address on file.</p>';
        }
        echo '<a class="anchor-btn secondary" href="' . esc_url( wc_get_endpoint_url( 'edit-address', 'billing', wc_get_page_permalink( 'myaccount' ) ) ) . '">Edit in WooCommerce</a>';
        echo '</div>';
    }
}

new Anchor_Account_Dashboard();

