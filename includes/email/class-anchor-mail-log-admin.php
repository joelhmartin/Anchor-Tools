<?php
/**
 * Mail log admin: view, resend, and a reusable "Emails" panel.
 *
 * Generic primitive; knows nothing about any module. A caller fetches rows with
 * Anchor_Mail_Log::query(), calls enqueue(), and echoes render_panel().
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

        public static function init() {
            add_action( 'wp_ajax_anchor_mail_log_resend', [ __CLASS__, 'ajax_resend' ] );
            add_action( 'wp_ajax_anchor_mail_log_view', [ __CLASS__, 'ajax_view' ] );
        }

        /** Who may view and resend logged mail. Filterable so modules can widen it. */
        public static function can_manage( array $row ) {
            return (bool) apply_filters( 'anchor_mail_log_can_manage', current_user_can( 'manage_options' ), $row );
        }

        /* ───────── Resend ───────── */

        /**
         * Send a logged email again to its stored recipient. The new row links back
         * through resend_of to the row that was clicked (a resend of a resend points
         * at the resend).
         *
         * @return array{ok:bool,id:int,message:string}
         */
        public static function resend( $id, $user_id ) {
            $orig = Anchor_Mail_Log::get( (int) $id );
            if ( ! $orig ) {
                return [ 'ok' => false, 'id' => 0, 'message' => __( 'That email is no longer in the log.', 'anchor-schema' ) ];
            }
            if ( '' === (string) $orig['recipient'] ) {
                return [ 'ok' => false, 'id' => 0, 'message' => __( 'This email has no recipient on record.', 'anchor-schema' ) ];
            }

            $stored  = json_decode( (string) $orig['headers'], true );
            $headers = [];
            foreach ( is_array( $stored ) ? $stored : [] as $line ) {
                // The log re-tags every armed send with the NEW row id.
                if ( ! preg_match( '/^\s*X-Mailgun-(Variables|Track)/i', (string) $line ) ) {
                    $headers[] = (string) $line;
                }
            }
            if ( ! preg_grep( '/^\s*Content-Type\s*:/i', $headers ) ) {
                $headers[] = 'Content-Type: text/html; charset=UTF-8';
            }

            Anchor_Mail_Log::begin(
                [
                    'context'   => $orig['context'],
                    'kind'      => $orig['kind'],
                    'audience'  => $orig['audience'],
                    'event_id'  => (int) $orig['event_id'],
                    'seat_id'   => (int) $orig['seat_id'],
                    'order_id'  => (int) $orig['order_id'],
                    'source'    => 'resend',
                    'resend_of' => (int) $orig['id'],
                    'sent_by'   => (int) $user_id,
                ]
            );
            $sent = false;
            $new  = null;
            try {
                $sent = (bool) wp_mail( $orig['recipient'], (string) $orig['subject'], (string) $orig['html'], $headers );
            } finally {
                // Always disarm, even if wp_mail throws, so the next unrelated mail is not logged under us.
                $new = Anchor_Mail_Log::finish( $sent );
            }
            if ( ! $new ) {
                return [ 'ok' => false, 'id' => 0, 'message' => __( 'The email could not be logged.', 'anchor-schema' ) ];
            }

            // A send short-circuited before the wp_mail filter leaves a bare row; fill it in.
            $row = Anchor_Mail_Log::get( $new );
            if ( $row && '' === (string) $row['recipient'] ) {
                global $wpdb;
                $wpdb->update(
                    Anchor_Mail_Log::table(),
                    [
                        'recipient' => $orig['recipient'],
                        'subject'   => $orig['subject'],
                        'html'      => $orig['html'],
                        'headers'   => $orig['headers'],
                    ],
                    [ 'id' => $new ]
                );
            }

            if ( ! $sent ) {
                $row = Anchor_Mail_Log::get( $new );
                return [ 'ok' => false, 'id' => $new, 'message' => $row && '' !== (string) $row['status_detail'] ? $row['status_detail'] : __( 'The email could not be sent.', 'anchor-schema' ) ];
            }
            return [ 'ok' => true, 'id' => $new, 'message' => __( 'Sent', 'anchor-schema' ) ];
        }

        public static function ajax_resend() {
            $id  = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
            $row = $id ? Anchor_Mail_Log::get( $id ) : null;
            if ( ! $row || ! self::can_manage( $row ) || ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
                wp_send_json_error( [ 'message' => __( 'You are not allowed to do that.', 'anchor-schema' ) ], 403 );
            }

            $res = self::resend( $id, get_current_user_id() );
            if ( empty( $res['id'] ) ) {
                wp_send_json_error( [ 'message' => $res['message'] ], 500 );
            }
            $labels = apply_filters( 'anchor_mail_log_kind_labels', [] );
            $html   = self::render_row( Anchor_Mail_Log::get( $res['id'] ), is_array( $labels ) ? $labels : [] );
            if ( $res['ok'] ) {
                wp_send_json_success( [ 'ok' => true, 'row_html' => $html, 'message' => $res['message'] ] );
            }
            // The failed attempt is logged and shown, but the click itself reports the error.
            wp_send_json_error( [ 'message' => $res['message'], 'row_html' => $html ], 502 );
        }

        /* ───────── View ───────── */

        public static function ajax_view() {
            $id  = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
            $row = $id ? Anchor_Mail_Log::get( $id ) : null;
            if ( ! $row || ! self::can_manage( $row ) || ! check_ajax_referer( self::NONCE, '_wpnonce', false ) ) {
                status_header( 403 );
                wp_die( esc_html__( 'You are not allowed to view this email.', 'anchor-schema' ), '', [ 'response' => 403 ] );
            }
            if ( ! headers_sent() ) {
                header( 'Content-Type: text/html; charset=utf-8' );
                header( "Content-Security-Policy: sandbox; default-src 'none'; img-src * data:; style-src 'unsafe-inline' *" );
                header( 'X-Content-Type-Options: nosniff' );
                header( 'X-Robots-Tag: noindex' );
            }
            echo self::strip_sign_in_tokens( (string) $row['html'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- stored email body, served sandboxed by CSP.
            wp_die();
        }

        /**
         * Remove the one-click sign-in parameter (`aek`, room-link token) from every
         * href so viewing a stored email cannot sign a viewer in as the recipient.
         * View output only; the stored row is untouched.
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

        /* ───────── Rendering ───────── */

        /**
         * @param array[]               $rows   Rows from Anchor_Mail_Log::query().
         * @param array<string,string>  $labels kind => human label (fallback: subject).
         */
        public static function render_panel( array $rows, array $labels = [] ) {
            $out  = '<section class="anchor-mail-log">';
            $out .= '<h3 class="anchor-mail-log__title">' . esc_html__( 'Emails', 'anchor-schema' ) . '</h3>';
            $out .= '<div class="anchor-mail-log__scroll"><table class="anchor-mail-log__table"><thead><tr>';
            foreach ( [ 'Sent', 'Email', 'Delivery', 'Opened', 'Clicked' ] as $h ) {
                $out .= '<th>' . esc_html( $h ) . '</th>';
            }
            $out .= '<th><span class="screen-reader-text">' . esc_html__( 'Actions', 'anchor-schema' ) . '</span></th></tr></thead><tbody>';
            $out .= '<tr class="anchor-mail-log__empty"' . ( $rows ? ' hidden' : '' ) . '><td colspan="6">'
                . esc_html__( 'No emails logged for this registrant yet.', 'anchor-schema' ) . '</td></tr>';
            foreach ( $rows as $row ) {
                $out .= self::render_row( $row, $labels );
            }
            return $out . '</tbody></table></div></section>';
        }

        public static function render_row( array $row, array $labels = [] ) {
            $id       = (int) $row['id'];
            $imported = 'import' === $row['source'];
            $title    = isset( $labels[ $row['kind'] ] ) && '' !== $labels[ $row['kind'] ] ? $labels[ $row['kind'] ] : $row['subject'];
            [ $badge, $class ] = self::badge( $row );

            $email = '<strong>' . esc_html( (string) $title ) . '</strong>';
            if ( '' !== (string) $row['recipient'] ) {
                $email .= '<br><span class="anchor-mail-log__to">' . esc_html( $row['recipient'] ) . '</span>';
            }
            if ( 'resend' === $row['source'] ) {
                $user   = (int) $row['sent_by'] ? get_userdata( (int) $row['sent_by'] ) : false;
                $email .= '<br><span class="anchor-mail-log__note">' . esc_html(
                    sprintf(
                        /* translators: %s: user display name */
                        __( 'Resent by %s', 'anchor-schema' ),
                        $user ? $user->display_name : __( 'a user', 'anchor-schema' )
                    )
                ) . '</span>';
            }

            $view = add_query_arg(
                [ 'action' => 'anchor_mail_log_view', 'id' => $id, '_wpnonce' => wp_create_nonce( self::NONCE ) ],
                admin_url( 'admin-ajax.php' )
            );

            $out  = '<tr class="anchor-mail-log__row" data-id="' . $id . '">';
            $out .= '<td>' . esc_html( self::when( $row['created_at'] ) ) . '</td>';
            $out .= '<td>' . $email . '</td>';
            $out .= '<td><span class="anchor-mail-log__badge anchor-mail-log__badge--' . esc_attr( $class ) . '" title="' . esc_attr( (string) $row['status_detail'] ) . '">' . esc_html( $badge ) . '</span></td>';
            $out .= '<td>' . esc_html( self::engagement( $row['opened_at'], $row['open_count'], $imported ) ) . '</td>';
            $out .= '<td>' . esc_html( self::engagement( $row['clicked_at'], $row['click_count'], $imported ) ) . '</td>';
            $out .= '<td class="anchor-mail-log__actions"><a href="' . esc_url( $view ) . '" target="_blank" rel="noopener">' . esc_html__( 'View', 'anchor-schema' ) . '</a> '
                . '<button type="button" class="button anchor-mail-log__resend" data-resend="' . $id . '" data-id="' . $id . '">' . esc_html__( 'Resend', 'anchor-schema' ) . '</button>'
                . '<span class="anchor-mail-log__msg" role="status"></span></td>';
            return $out . '</tr>';
        }

        /** @return array{0:string,1:string} Badge text and colour class. */
        private static function badge( array $row ) {
            if ( 'import' === $row['source'] && 'sent' === $row['status'] ) {
                return [ __( 'Sent (before tracking)', 'anchor-schema' ), 'grey' ];
            }
            switch ( $row['status'] ) {
                case 'delivered':
                    return [ __( 'Delivered', 'anchor-schema' ), 'green' ];
                case 'deferred':
                    return [ __( 'Deferred (retrying)', 'anchor-schema' ), 'amber' ];
                case 'bounced':
                    return [ __( 'Bounced', 'anchor-schema' ), 'red' ];
                case 'complained':
                    return [ __( 'Marked as spam', 'anchor-schema' ), 'red' ];
                case 'failed':
                    return [ __( 'Failed', 'anchor-schema' ), 'red' ];
                default:
                    return [ __( 'Waiting for delivery report', 'anchor-schema' ), 'grey' ];
            }
        }

        private static function engagement( $at, $count, $imported ) {
            if ( empty( $at ) ) {
                return $imported ? __( 'not tracked', 'anchor-schema' ) : '—';
            }
            $text = 'Yes · ' . self::when( $at );
            return (int) $count > 1 ? $text . ' (×' . (int) $count . ')' : $text;
        }

        /** Stored UTC -> site timezone. */
        private static function when( $utc ) {
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
                        'confirm' => __( 'Click again to resend', 'anchor-schema' ),
                        'sending' => __( 'Sending…', 'anchor-schema' ),
                        'sent'    => __( 'Sent', 'anchor-schema' ),
                        'error'   => __( 'Could not resend. Please try again.', 'anchor-schema' ),
                        'resend'  => __( 'Resend', 'anchor-schema' ),
                    ],
                ]
            );
        }
    }

    Anchor_Mail_Log_Admin::init();
}
