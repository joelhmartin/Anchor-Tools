<?php
/**
 * Mail log admin: serving an email body sandboxed, cell helpers for showing a
 * logged send, and the arm-then-confirm send button script.
 *
 * Generic primitive; knows nothing about any module. A module renders its own
 * table with the `anchor-mail-log` classes, calls enqueue(), and gives each send
 * button `data-mail-action` (its own AJAX action) and `data-mail-args` (JSON).
 * The action answers { row_html, message }; row_html replaces the button's row.
 *
 * @package Anchor_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Anchor_Mail_Log_Admin' ) ) {

    class Anchor_Mail_Log_Admin {

        const NONCE  = 'anchor_mail_log';
        const HANDLE = 'anchor-mail-log';

        /* ───────── View ───────── */

        /**
         * Serve an email body as a sandboxed, noindexed page and end the request.
         * One-click sign-in tokens are stripped from its links first.
         */
        public static function serve_sandboxed( $html ) {
            if ( ! headers_sent() ) {
                header( 'Content-Type: text/html; charset=utf-8' );
                header( "Content-Security-Policy: sandbox; default-src 'none'; img-src * data:; style-src 'unsafe-inline' *" );
                header( 'X-Content-Type-Options: nosniff' );
                header( 'X-Robots-Tag: noindex' );
            }
            echo self::strip_sign_in_tokens( (string) $html ); // phpcs:ignore WordPress.Security.EscapeOutput -- email body, served sandboxed by CSP.
            wp_die();
        }

        /**
         * Remove the one-click sign-in parameter (`aek`, room-link token) from every
         * href so viewing an email cannot sign a viewer in as the recipient.
         */
        public static function strip_sign_in_tokens( $html ) {
            return (string) preg_replace_callback(
                '/(href\s*=\s*)(["\'])(.*?)\2/is',
                static function ( $m ) {
                    $url = html_entity_decode( $m[3], ENT_QUOTES );
                    if ( false === strpos( $url, 'aek=' ) ) {
                        return $m[0];
                    }
                    $url = preg_replace( '/([?&])aek=[^&#]*(&|(?=#)|$)/', '$1', $url );
                    $url = preg_replace( '/[?&]$/', '', str_replace( '?&', '?', $url ) );
                    return $m[1] . $m[2] . htmlspecialchars( $url, ENT_QUOTES ) . $m[2];
                },
                $html
            );
        }

        /* ───────── Cell helpers ───────── */

        /**
         * Delivery state of a logged send.
         *
         * @return array{0:string,1:string} Text and badge colour ('' = plain text). "—" when
         *                                  no delivery report exists (imported or untracked).
         */
        public static function delivery( array $row ) {
            switch ( $row['status'] ) {
                case 'delivered':
                    return [ empty( $row['delivered_at'] ) ? __( 'Yes', 'anchor-schema' ) : 'Yes · ' . self::when( $row['delivered_at'] ), '' ];
                case 'deferred':
                    return [ __( 'Deferred (retrying)', 'anchor-schema' ), 'amber' ];
                case 'bounced':
                    return [ __( 'Bounced', 'anchor-schema' ), 'red' ];
                case 'complained':
                    return [ __( 'Marked as spam', 'anchor-schema' ), 'red' ];
                case 'failed':
                    return [ __( 'Failed', 'anchor-schema' ), 'red' ];
                default:
                    return [ '—', '' ];
            }
        }

        /** "Yes · Oct 20, 3:04 PM (×2)" or "—" for an open/click timestamp and count. */
        public static function engagement( $at, $count ) {
            if ( empty( $at ) ) {
                return '—';
            }
            $text = 'Yes · ' . self::when( $at );
            return (int) $count > 1 ? $text . ' (×' . (int) $count . ')' : $text;
        }

        /** Stored UTC -> site timezone. */
        public static function when( $utc ) {
            $ts = strtotime( (string) $utc . ' UTC' );
            return $ts ? wp_date( 'M j, g:i A', $ts ) : '—';
        }

        /* ───────── Assets ───────── */

        /** Enqueue the panel's JS/CSS. Works in wp-admin and on the front end. */
        public static function enqueue() {
            $rel = 'includes/email/assets/';
            $url = static function ( $file ) use ( $rel ) {
                return class_exists( 'Anchor_Asset_Loader' ) ? Anchor_Asset_Loader::url( $rel . $file ) : ANCHOR_TOOLS_PLUGIN_URL . $rel . $file;
            };
            $ver = static function ( $file ) use ( $rel ) {
                $path = ANCHOR_TOOLS_PLUGIN_DIR . $rel . $file;
                return file_exists( $path ) ? (string) filemtime( $path ) : null;
            };

            wp_enqueue_style( self::HANDLE, $url( 'mail-log.css' ), [], $ver( 'mail-log.css' ) );
            wp_enqueue_script( self::HANDLE, $url( 'mail-log.js' ), [ 'jquery' ], $ver( 'mail-log.js' ), true );
            wp_localize_script(
                self::HANDLE,
                'anchorMailLog',
                [
                    'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                    'nonce'   => wp_create_nonce( self::NONCE ),
                    'i18n'    => [
                        'confirm' => __( 'Click again to send', 'anchor-schema' ),
                        'sending' => __( 'Sending…', 'anchor-schema' ),
                        'sent'    => __( 'Sent', 'anchor-schema' ),
                        'error'   => __( 'Could not send. Please try again.', 'anchor-schema' ),
                    ],
                ]
            );
        }
    }

}
