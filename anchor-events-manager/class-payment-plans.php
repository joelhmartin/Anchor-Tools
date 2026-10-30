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
     * @return string
     */
    public static function render( $product_id ) {
        $info = self::for_product( $product_id );
        if ( empty( $info['plans'] ) ) {
            return '';
        }
        $name    = \wp_unique_id( 'anchor_event_plan_' );
        $options = [];
        if ( ! $info['forced'] ) {
            $options[''] = \__( 'Pay in full', 'anchor-schema' );
        }
        $options += $info['plans'];

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
