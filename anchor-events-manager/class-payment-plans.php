<?php
/**
 * Payment plans on the event storefront (WooCommerce Subscriptions' built-in
 * "All Products for Subscriptions" plans).
 *
 * APFS lets a product offer a plan ("3 monthly payments") beside the one-time
 * price, but the plan is chosen ONLY at add-to-cart time, from a posted
 * `convert_to_sub_<product id>` request field: the product page prints that
 * radio; the cart deliberately no longer offers a switcher. The event
 * storefront adds tickets through its own AJAX endpoint, so without this class
 * a plan configured on the managed product is unreachable.
 *
 * This class renders the "Pay in full / plan" choice under the ticket rows and,
 * on add-to-cart, validates the posted choice against the product's OWN plans
 * before handing it to APFS. Inert (renders nothing, applies nothing) when
 * WC Subscriptions is absent or the product has no plans.
 *
 * A pay-in-full discount (event meta, per seat) makes paying up front cheaper
 * than the plan: the ticket price is what the plan totals, and a one-time line
 * is charged that price less the discount. APFS can only DISCOUNT a plan below
 * the one-time price, never the reverse, so the reverse lives here.
 *
 * Renewal orders of a fixed-length plan do not create seats — see
 * Subscription_Renewals.
 *
 * @package Anchor\Events
 */

namespace Anchor\Events;

if ( ! \defined( 'ABSPATH' ) ) {
    exit;
}

class Payment_Plans {

    /** POST field the storefront sends with the chosen plan ('' = pay in full). */
    const POST_FIELD = 'plan';

    /** Event meta: amount off each seat bought outright (no plan). */
    const DISCOUNT_META = '_anchor_event_pay_in_full_discount';

    /** Admin form field carrying DISCOUNT_META. */
    const DISCOUNT_FIELD = 'anchor_event_pay_in_full_discount';

    /**
     * The event's pay-in-full discount per seat. A date (child) event with none
     * of its own inherits its offering's, the same way dates inherit e-mails.
     *
     * @param int $event_id
     * @param int $parent_id Offering the date belongs to (0 = none).
     * @return float
     */
    public static function discount( $event_id, $parent_id = 0 ) {
        $own = \get_post_meta( (int) $event_id, self::DISCOUNT_META, true );
        if ( '' !== (string) $own ) {
            return \max( 0.0, (float) $own );
        }
        return $parent_id > 0 ? \max( 0.0, (float) \get_post_meta( (int) $parent_id, self::DISCOUNT_META, true ) ) : 0.0;
    }

    /**
     * Persist the admin field. Blank removes the meta (a date then inherits).
     *
     * @param int   $event_id
     * @param array $src Unslashed request data.
     */
    public static function save_discount( $event_id, array $src ) {
        if ( ! \array_key_exists( self::DISCOUNT_FIELD, $src ) ) {
            return;
        }
        $raw = \trim( (string) $src[ self::DISCOUNT_FIELD ] );
        if ( '' === $raw ) {
            \delete_post_meta( (int) $event_id, self::DISCOUNT_META );
            return;
        }
        $amount = \max( 0.0, (float) $raw );
        \update_post_meta( (int) $event_id, self::DISCOUNT_META, \function_exists( 'wc_format_decimal' ) ? \wc_format_decimal( $amount ) : (string) $amount );
    }

    /**
     * Admin input under the ticket table (escaped HTML).
     *
     * @param int $event_id
     * @return string
     */
    public static function render_discount_field( $event_id ) {
        $value = \get_post_meta( (int) $event_id, self::DISCOUNT_META, true );
        return '<p class="anchor-event-pay-in-full"><label>' . \esc_html__( 'Pay-in-full discount per seat', 'anchor-schema' )
            . ' <input type="number" min="0" step="0.01" name="' . \esc_attr( self::DISCOUNT_FIELD ) . '" value="' . \esc_attr( (string) $value ) . '" class="small-text" /></label>'
            . '<br><span class="description">' . \esc_html__( 'Only used when the ticket product offers a payment plan: the ticket price is what the plan totals, and buyers who pay in full are charged that price minus this amount. Blank on a date = use the main event\'s amount.', 'anchor-schema' ) . '</span></p>';
    }

    /**
     * Price of a seat bought outright.
     *
     * @param float $price    Ticket (plan-total) price.
     * @param float $discount Pay-in-full discount.
     * @return float
     */
    public static function one_time_price( $price, $discount ) {
        return \max( 0.0, \round( (float) $price - \max( 0.0, (float) $discount ), 2 ) );
    }

    /**
     * Whether a cart line is a fresh one-time purchase the discount applies to:
     * no active plan, and not a renewal / resubscribe / switch cart.
     *
     * @param array $cart_item
     * @return bool
     */
    public static function is_one_time_line( array $cart_item ) {
        foreach ( [ 'subscription_renewal', 'subscription_resubscribe', 'subscription_initial_payment', 'subscription_switch' ] as $key ) {
            if ( ! empty( $cart_item[ $key ] ) ) {
                return false;
            }
        }
        return empty( $cart_item['wcsatt_data']['active_subscription_scheme'] );
    }

    /**
     * woocommerce_before_calculate_totals: charge one-time event lines the
     * pay-in-full price. Re-reads the stored price each pass (this hook runs
     * more than once per request), so the discount is never applied twice.
     *
     * @param \WC_Cart  $cart
     * @param callable  $discount_for Event id → discount.
     */
    public static function apply_cart_discounts( $cart, callable $discount_for ) {
        if ( ! \is_object( $cart ) || ! \method_exists( $cart, 'get_cart' ) ) {
            return;
        }
        foreach ( $cart->get_cart() as $item ) {
            $event_id = isset( $item['anchor_event_id'] ) ? (int) $item['anchor_event_id'] : 0;
            if ( $event_id <= 0 || empty( $item['data'] ) || ! self::is_one_time_line( $item ) ) {
                continue;
            }
            $discount = (float) $discount_for( $event_id );
            if ( $discount <= 0 ) {
                continue;
            }
            $plans = self::for_product( (int) $item['product_id'] );
            if ( empty( $plans['plans'] ) || $plans['forced'] ) {
                continue; // No plan to compare against: the ticket price is the price.
            }
            $stored = \wc_get_product( ! empty( $item['variation_id'] ) ? (int) $item['variation_id'] : (int) $item['product_id'] );
            if ( $stored ) {
                $item['data']->set_price( self::one_time_price( (float) $stored->get_price( 'edit' ), $discount ) );
            }
        }
    }

    /**
     * The product's plans as [ key => label ], plus whether one is forced.
     *
     * @param int $product_id Managed (parent) product id.
     * @return array{plans:array<string,string>,forced:bool}
     */
    public static function for_product( $product_id ) {
        $none = [ 'plans' => [], 'forced' => false ];
        if ( ! \class_exists( '\WCS_ATT_Product_Schemes' ) || ! \function_exists( 'wc_get_product' ) ) {
            return $none;
        }
        $product = \wc_get_product( (int) $product_id );
        if ( ! $product ) {
            return $none;
        }
        $plans = [];
        foreach ( (array) \WCS_ATT_Product_Schemes::get_subscription_schemes( $product ) as $scheme ) {
            if ( ! \is_object( $scheme ) || ! \method_exists( $scheme, 'get_key' ) ) {
                continue;
            }
            $key = \WCS_ATT_Product_Schemes::stringify_subscription_scheme_key( $scheme->get_key() );
            if ( '' !== (string) $key ) {
                $plans[ (string) $key ] = self::label( (int) $scheme->get_length(), (string) $scheme->get_period(), (int) $scheme->get_interval() );
            }
        }
        return [
            'plans'  => $plans,
            'forced' => ! empty( $plans ) && (bool) \WCS_ATT_Product_Schemes::has_forced_subscription_scheme( $product ),
        ];
    }

    /**
     * "3 monthly payments" / "Monthly payments" (open-ended) / "3 payments every 2 weeks".
     *
     * @param int    $length   Number of payments; 0 = until cancelled.
     * @param string $period   day|week|month|year.
     * @param int    $interval Every N periods.
     * @return string
     */
    public static function label( $length, $period, $interval ) {
        $adjectives = [
            'day'   => \__( 'daily', 'anchor-schema' ),
            'week'  => \__( 'weekly', 'anchor-schema' ),
            'month' => \__( 'monthly', 'anchor-schema' ),
            'year'  => \__( 'yearly', 'anchor-schema' ),
        ];
        if ( $interval <= 1 && isset( $adjectives[ $period ] ) ) {
            return $length > 0
                /* translators: 1: number of payments, 2: e.g. "monthly". */
                ? \sprintf( \__( '%1$d %2$s payments', 'anchor-schema' ), $length, $adjectives[ $period ] )
                /* translators: %s: e.g. "monthly". */
                : \ucfirst( \sprintf( \__( '%s payments', 'anchor-schema' ), $adjectives[ $period ] ) );
        }
        $every = \function_exists( 'wcs_get_subscription_period_strings' )
            ? (string) \wcs_get_subscription_period_strings( max( 1, $interval ), $period )
            : $interval . ' ' . $period;
        return $length > 0
            /* translators: 1: number of payments, 2: e.g. "2 weeks". */
            ? \sprintf( \__( '%1$d payments, every %2$s', 'anchor-schema' ), $length, $every )
            /* translators: %s: e.g. "2 weeks". */
            : \sprintf( \__( 'A payment every %s', 'anchor-schema' ), $every );
    }

    /**
     * The choice under the ticket rows ('' when the product offers no plan).
     *
     * @param int $product_id
     * @param float $discount     Pay-in-full discount per seat.
     * @param int   $variation_id When the event sells ONE ticket type: its
     *                            variation, so each option can state its amount.
     * @return string
     */
    public static function render( $product_id, $discount = 0.0, $variation_id = 0 ) {
        $info = self::for_product( $product_id );
        if ( empty( $info['plans'] ) ) {
            return '';
        }
        $variation = ( $variation_id > 0 && \function_exists( 'wc_get_product' ) ) ? \wc_get_product( (int) $variation_id ) : null;
        $name      = \wp_unique_id( 'anchor_event_plan_' );
        $options   = [];
        if ( ! $info['forced'] ) {
            $full = \__( 'Pay in full', 'anchor-schema' );
            if ( $variation ) {
                $full .= ': ' . self::money( self::one_time_price( (float) $variation->get_price( 'edit' ), $discount ) );
            }
            if ( $discount > 0 ) {
                /* translators: %s: amount saved. */
                $full .= ' (' . \sprintf( \__( 'save %s', 'anchor-schema' ), self::money( $discount ) ) . ')';
            }
            $options[''] = $full;
        }
        foreach ( $info['plans'] as $key => $label ) {
            $each = ( $variation && \class_exists( '\WCS_ATT_Product_Prices' ) )
                ? \WCS_ATT_Product_Prices::get_price( $variation, (string) $key )
                : '';
            /* translators: 1: e.g. "3 monthly payments", 2: amount of each. */
            $options[ $key ] = ( '' !== (string) $each ) ? \sprintf( \__( '%1$s of %2$s', 'anchor-schema' ), $label, self::money( (float) $each ) ) : $label;
        }

        $out   = '<fieldset class="anchor-event-plan" data-plan-choice>';
        $out  .= '<legend>' . \esc_html__( 'Payment', 'anchor-schema' ) . '</legend>';
        $first = true;
        foreach ( $options as $key => $label ) {
            $out  .= '<label class="anchor-event-plan-option"><input type="radio" name="' . \esc_attr( $name ) . '" value="' . \esc_attr( $key ) . '"'
                . ( $first ? ' checked' : '' ) . '> ' . \esc_html( $label ) . '</label>';
            $first = false;
        }
        return $out . '</fieldset>';
    }

    /** Plain-text money for a radio label ("$2,431.67"). */
    private static function money( $amount ) {
        return \function_exists( 'wc_price' )
            ? \html_entity_decode( \wp_strip_all_tags( \wc_price( (float) $amount ) ), ENT_QUOTES, 'UTF-8' )
            : \number_format_i18n( (float) $amount, 2 );
    }

    /**
     * Before WC_Cart::add_to_cart(): pass a VALID posted plan to APFS via the
     * request field it reads. Anything not among the product's own plans
     * (including '') is a one-time purchase, unless the product forces a plan,
     * in which case APFS applies its default and nothing is set here.
     *
     * @param int $product_id Managed (parent) product id.
     * @return string The plan key applied, '' for pay in full.
     */
    public static function apply_posted( $product_id ) {
        $field = 'convert_to_sub_' . (int) $product_id;
        unset( $_REQUEST[ $field ] );

        $posted = isset( $_POST[ self::POST_FIELD ] ) ? \sanitize_text_field( \wp_unslash( (string) $_POST[ self::POST_FIELD ] ) ) : '';
        if ( '' === $posted ) {
            return '';
        }
        $info = self::for_product( $product_id );
        if ( ! isset( $info['plans'][ $posted ] ) ) {
            return '';
        }
        $_REQUEST[ $field ] = $posted; // phpcs:ignore WordPress.Security.NonceVerification -- endpoint already verified its nonce.
        return $posted;
    }
}
