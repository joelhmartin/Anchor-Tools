<?php
/**
 * Branded front-end replacement for wp-admin/authorize-application.php (member app sign-in).
 *
 * The phone app opens  {site}/app-connect/?app_name=…&app_id=<uuid>&success_url=<scheme>://…
 * instead of the wp-admin screen. Logged-out visitors are sent to the site's own themed login
 * (WooCommerce My Account when active, else wp-login.php) and come back here with every
 * parameter intact; logged-in users get a consent card rendered inside the active theme.
 * Approving creates an Application Password exactly as core's no-JS fallback does and redirects
 * to success_url with site_url / user_login / password (each urlencode()d, as core does).
 *
 * Same gate as App_Auth: the "Member app URL schemes" setting. Empty = no route, no hooks that
 * change anything. Validation is core's wp_is_authorize_application_password_request_valid()
 * (which runs App_Auth's scheme + administrator errors), plus the same availability checks the
 * core screen performs, so this page is no weaker than core's. It never redirects to a URL
 * whose scheme is not on the allowlist.
 *
 * @package Anchor\Events
 */

namespace Anchor\Events;

if ( ! \defined( 'ABSPATH' ) ) {
    exit;
}

class App_Connect {

    const QUERY_VAR  = 'anchor_app_connect';
    const PATH       = 'app-connect';
    const NONCE      = 'anchor_app_connect';
    const REWRITE_OP = 'anchor_events_app_connect_rw';
    const REWRITE_V  = 'v1';
    const STYLE      = 'anchor-app-connect';

    /** Request parameters carried through login, logout and the consent form. */
    const PARAMS = [ 'app_name', 'app_id', 'success_url', 'reject_url' ];

    /** @var Module */
    private $module;

    /** @var App_Auth */
    private $auth;

    public function __construct( Module $module, App_Auth $auth ) {
        $this->module = $module;
        $this->auth   = $auth;

        \add_action( 'init', [ $this, 'redirect_core_screen' ], 1 );
        \add_action( 'init', [ $this, 'add_rewrite' ] );
        \add_action( 'init', [ $this, 'maybe_flush_rewrites' ], 99 );
        \add_filter( 'query_vars', [ $this, 'query_vars' ] );
        \add_action( 'template_redirect', [ $this, 'return_after_login' ], 0 );
        \add_action( 'template_redirect', [ $this, 'maybe_render' ], 1 );
        // WooCommerce's My Account login/register forms only honour a hidden "redirect" field.
        \add_action( 'woocommerce_login_form', [ $this, 'emit_return_field' ] );
        \add_action( 'woocommerce_register_form', [ $this, 'emit_return_field' ] );
    }

    public function enabled() {
        return (bool) $this->auth->allowed_schemes();
    }

    /* ---------------------------------------------------------------- routing */

    public function query_vars( $vars ) {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    public function add_rewrite() {
        if ( $this->enabled() ) {
            \add_rewrite_rule( '^' . self::PATH . '/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
        }
    }

    /** Flush once per (version, on/off) change — upgrades fire no activation hook. */
    public function maybe_flush_rewrites() {
        $sig = self::REWRITE_V . '|' . ( $this->enabled() ? 'on' : 'off' );
        if ( \get_option( self::REWRITE_OP ) !== $sig ) {
            \flush_rewrite_rules( false );
            \update_option( self::REWRITE_OP, $sig, true ); // Autoloaded: it is read on every init.
        }
    }

    /** Base connect URL. Pretty permalinks get /app-connect/; plain permalinks fall back to the query var. */
    public function base_url() {
        if ( \get_option( 'permalink_structure' ) ) {
            return \home_url( '/' . self::PATH . '/' );
        }
        return \home_url( '/?' . self::QUERY_VAR . '=1' );
    }

    /** Connect URL carrying the given request params (only the four known ones, rebuilt, never raw input). */
    public function connect_url( array $request ) {
        $args = [];
        foreach ( self::PARAMS as $key ) {
            if ( isset( $request[ $key ] ) && '' !== $request[ $key ] && null !== $request[ $key ] ) {
                $args[ $key ] = (string) $request[ $key ];
            }
        }
        if ( ! $args ) {
            return $this->base_url();
        }
        $base = $this->base_url();
        return $base . ( false === \strpos( $base, '?' ) ? '?' : '&' ) . \http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
    }

    /** Is this one of our own connect URLs (the only thing a login form may send people back to)? */
    public function is_connect_url( $url ) {
        $url = \is_string( $url ) ? \wp_validate_redirect( $url, '' ) : '';
        return '' !== $url && 0 === \strpos( $url, $this->base_url() );
    }

    /**
     * Old app builds still open core's wp-admin screen. Send GETs for an allowlisted app to the
     * branded page (runs on init, before auth_redirect(), so logged-out users skip wp-login too).
     */
    public function redirect_core_screen() {
        global $pagenow;
        if ( ! $this->enabled() || 'authorize-application.php' !== $pagenow || ! \is_admin()
            || 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            return;
        }
        $request = $this->read_request( \wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification
        if ( $this->auth->is_allowed_url( $request['success_url'] ) ) {
            \wp_redirect( $this->connect_url( $request ) ); // phpcs:ignore WordPress.Security.SafeRedirect -- own URL.
            exit;
        }
    }

    /* ---------------------------------------------------------------- request */

    /**
     * Normalise raw input to core's request shape (reject_url defaults to success_url + success=false).
     * URLs are kept verbatim: esc_url_raw() would strip a custom scheme.
     *
     * @param array $input Unslashed GET/POST data.
     * @return array{app_name:string,app_id:string,success_url:?string,reject_url:?string}
     */
    public function read_request( array $input ) {
        $text = static function ( $key ) use ( $input ) {
            return isset( $input[ $key ] ) && \is_string( $input[ $key ] ) ? \trim( $input[ $key ] ) : '';
        };
        $success = $text( 'success_url' );
        $reject  = $text( 'reject_url' );
        $success = '' !== $success ? $success : null;
        if ( '' === $reject ) {
            $reject = $success ? \add_query_arg( 'success', 'false', $success ) : null;
        }
        return [
            'app_name'    => \mb_substr( \sanitize_text_field( $text( 'app_name' ) ), 0, 100 ),
            'app_id'      => \sanitize_text_field( $text( 'app_id' ) ),
            'success_url' => $success,
            'reject_url'  => $reject,
        ];
    }

    /**
     * Core's validity function plus the availability checks wp-admin/authorize-application.php does.
     *
     * @param array    $request From read_request().
     * @param \WP_User $user    Current user (ID 0 when logged out).
     * @return \WP_Error Empty when the request is acceptable.
     */
    public function validate( array $request, \WP_User $user ) {
        if ( ! \function_exists( 'wp_is_authorize_application_password_request_valid' ) ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }
        $error = new \WP_Error();
        $core  = \wp_is_authorize_application_password_request_valid( $request, $user );
        if ( \is_wp_error( $core ) ) {
            foreach ( $core->get_error_codes() as $code ) {
                foreach ( $core->get_error_messages( $code ) as $message ) {
                    $error->add( $code, $message );
                }
            }
        }
        if ( ! $request['success_url'] ) {
            $error->add( 'anchor_app_connect_no_return', \__( 'This request is missing the address to return to in the app.', 'anchor-schema' ) );
        } elseif ( ! $this->auth->is_allowed_url( $request['success_url'] ) && ! $error->has_errors() ) {
            $error->add( 'anchor_events_app_scheme', \__( "This app isn't allowed to sign in to this site.", 'anchor-schema' ) );
        }
        if ( '' === $request['app_name'] ) {
            $error->add( 'anchor_app_connect_no_name', \__( 'This request is missing the app name.', 'anchor-schema' ) );
        }
        if ( \function_exists( 'wp_is_site_protected_by_basic_auth' ) && \wp_is_site_protected_by_basic_auth( 'front' ) ) {
            $error->add( 'anchor_app_connect_basic_auth', \__( 'This website uses Basic Authentication, which is not compatible with app sign-in.', 'anchor-schema' ) );
        }
        $available = $user->exists() ? \wp_is_application_passwords_available_for_user( $user ) : \wp_is_application_passwords_available();
        if ( ! $available ) {
            $error->add(
                'anchor_app_connect_unavailable',
                $user->exists()
                    ? \__( 'App sign-in is not available for your account. Please contact the site administrator.', 'anchor-schema' )
                    : \__( 'App sign-in is not available on this site right now. Please contact the site administrator.', 'anchor-schema' )
            );
        }
        return $error;
    }

    /* --------------------------------------------------------------- decision */

    /**
     * Decide what this request gets. Pure of output/exit so it can be tested.
     *
     * @param array $input   Unslashed request data (GET, or GET+POST for a form post).
     * @param bool  $is_post Whether this is a form submission.
     * @return array{type:string,url?:string,errors?:\WP_Error,back?:string,status?:int,request?:array}
     *         type: redirect | error | consent
     */
    public function dispatch( array $input, $is_post ) {
        $request = $this->read_request( $input );
        $user    = \wp_get_current_user();
        $errors  = $this->validate( $request, $user );
        if ( $errors->has_errors() ) {
            return $this->error_result( $errors, $request, 400 );
        }
        if ( ! \is_user_logged_in() ) {
            return [ 'type' => 'redirect', 'url' => $this->login_url( $request ) ];
        }
        if ( ! $is_post ) {
            return [ 'type' => 'consent', 'request' => $request ];
        }
        if ( ! \wp_verify_nonce( (string) ( $input['_anchor_app_nonce'] ?? '' ), self::NONCE ) ) {
            $errors->add( 'anchor_app_connect_nonce', \__( 'This page expired. Please go back to the app and try again.', 'anchor-schema' ) );
            return $this->error_result( $errors, $request, 403 );
        }
        if ( isset( $input['reject'] ) ) {
            return $this->reject_result( $request );
        }
        if ( isset( $input['approve'] ) ) {
            return $this->approve_result( $request, $user );
        }
        return [ 'type' => 'consent', 'request' => $request ];
    }

    private function error_result( \WP_Error $errors, array $request, $status ) {
        $back = $this->auth->is_allowed_url( $request['reject_url'] ) ? $request['reject_url'] : '';
        return [ 'type' => 'error', 'errors' => $errors, 'back' => $back, 'status' => $status, 'request' => $request ];
    }

    private function reject_result( array $request ) {
        return [ 'type' => 'redirect', 'url' => $request['reject_url'] ];
    }

    private function approve_result( array $request, \WP_User $user ) {
        $created = \WP_Application_Passwords::create_new_application_password(
            $user->ID,
            [ 'name' => $request['app_name'], 'app_id' => $request['app_id'] ]
        );
        if ( \is_wp_error( $created ) ) {
            return $this->error_result( $created, $request, 500 );
        }
        // Same args and double encoding as wp-admin/authorize-application.php.
        $url = \add_query_arg(
            [
                'site_url'   => \urlencode( \site_url() ),
                'user_login' => \urlencode( $user->user_login ),
                'password'   => \urlencode( $created[0] ),
            ],
            $request['success_url']
        );
        return [ 'type' => 'redirect', 'url' => $url ];
    }

    /* ------------------------------------------------------------------ login */

    /** Themed login that returns to this page: WooCommerce My Account when active, else wp-login.php. */
    public function login_url( array $request ) {
        $return = $this->connect_url( $request );
        if ( \function_exists( 'wc_get_page_permalink' ) ) {
            $account = \wc_get_page_permalink( 'myaccount' );
            if ( $account ) {
                return \add_query_arg( 'redirect_to', \rawurlencode( $return ), $account );
            }
        }
        return \wp_login_url( $return );
    }

    /** woocommerce_login_form / woocommerce_register_form: carry redirect_to (or a failed attempt's redirect) back. */
    public function emit_return_field() {
        if ( ! $this->enabled() ) {
            return;
        }
        $target = isset( $_GET['redirect_to'] ) ? \wp_unslash( $_GET['redirect_to'] ) : ( isset( $_POST['redirect'] ) ? \wp_unslash( $_POST['redirect'] ) : '' ); // phpcs:ignore WordPress.Security
        if ( $this->is_connect_url( $target ) ) {
            echo '<input type="hidden" name="redirect" value="' . \esc_url( $target ) . '" />';
        }
    }

    /**
     * Themes may post their login form back to the page it sits on (TMJ's My Account form does), so the
     * signed-in user lands on /my-account/?redirect_to=<connect URL>. Finish the hop to the connect page.
     */
    public function return_after_login() {
        if ( ! $this->enabled() || ! \is_user_logged_in() || \get_query_var( self::QUERY_VAR ) || ! isset( $_GET['redirect_to'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }
        $target = \wp_unslash( $_GET['redirect_to'] ); // phpcs:ignore WordPress.Security
        if ( $this->is_connect_url( $target ) ) {
            \wp_safe_redirect( $target );
            exit;
        }
    }

    /* ----------------------------------------------------------------- output */

    public function maybe_render() {
        if ( ! $this->enabled() || ! \get_query_var( self::QUERY_VAR ) ) {
            return;
        }
        \nocache_headers();
        if ( ! \defined( 'DONOTCACHEPAGE' ) ) {
            \define( 'DONOTCACHEPAGE', true ); // WP Rocket et al.
        }
        \header( 'X-Robots-Tag: noindex, nofollow' );
        \header( 'Referrer-Policy: no-referrer' );
        // Same anti-framing as core's wp-admin consent screen.
        \send_frame_options_header();
        \header( "Content-Security-Policy: frame-ancestors 'self'" );

        $is_post = 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        // phpcs:ignore WordPress.Security.NonceVerification -- verified in dispatch() for form posts.
        $input  = \wp_unslash( $is_post ? \array_merge( $_GET, $_POST ) : $_GET );
        $result = $this->dispatch( $input, $is_post );

        if ( 'redirect' === $result['type'] ) {
            // wp_redirect on purpose: wp_safe_redirect() refuses custom schemes (core does the same).
            \wp_redirect( $result['url'] ); // phpcs:ignore WordPress.Security.SafeRedirect
            exit;
        }
        $this->render_page( $result );
        exit;
    }

    private function render_page( array $result ) {
        \add_filter( 'show_admin_bar', '__return_false' );
        \add_filter( 'pre_get_document_title', static function () {
            /* translators: %s: site name. */
            return \sprintf( \__( 'Connect app – %s', 'anchor-schema' ), \get_bloginfo( 'name' ) );
        } );
        \add_filter( 'body_class', static function ( $classes ) {
            $classes[] = 'anchor-app-connect-page';
            return $classes;
        } );
        \add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_style' ] );
        \status_header( 'error' === $result['type'] ? $result['status'] : 200 );

        \get_header();
        echo '<main id="primary" class="anchor-app-connect"><div class="anchor-app-connect__card">';
        echo '<p class="anchor-app-connect__site">' . \esc_html( \get_bloginfo( 'name' ) ) . '</p>';
        if ( 'error' === $result['type'] ) {
            $this->render_error( $result );
        } else {
            $this->render_consent( $result['request'] );
        }
        echo '</div></main>';
        \get_footer();
    }

    private function button_class( $primary ) {
        // With WooCommerce the theme's own .button styling is used; otherwise our neutral .is-plain look.
        if ( \class_exists( 'WooCommerce' ) ) {
            return 'anchor-app-connect__btn button' . ( $primary ? ' alt' : '' ) . ' wp-element-button';
        }
        return 'anchor-app-connect__btn is-plain' . ( $primary ? ' is-primary' : '' ) . ' wp-element-button';
    }

    private function render_error( array $result ) {
        echo '<h1 class="anchor-app-connect__title">' . \esc_html__( "We couldn't connect the app", 'anchor-schema' ) . '</h1>';
        foreach ( $result['errors']->get_error_messages() as $message ) {
            echo '<p class="anchor-app-connect__error">' . \esc_html( $message ) . '</p>';
        }
        if ( '' !== $result['back'] ) {
            // wp_allowed_protocols() lacks the custom scheme; allow exactly the allowlisted ones.
            $protocols = \array_merge( [ 'http', 'https' ], $this->auth->allowed_schemes() );
            echo '<p><a class="' . \esc_attr( $this->button_class( false ) ) . '" href="' . \esc_url( $result['back'], $protocols ) . '">'
                . \esc_html__( 'Back to app', 'anchor-schema' ) . '</a></p>';
        }
    }

    private function render_consent( array $request ) {
        $user = \wp_get_current_user();
        echo '<h1 class="anchor-app-connect__title">' . \esc_html( \sprintf(
            /* translators: %s: app name. */
            \__( 'Connect %s', 'anchor-schema' ),
            $request['app_name']
        ) ) . '</h1>';
        echo '<p class="anchor-app-connect__who">' . \esc_html( \sprintf(
            /* translators: 1: display name, 2: email address. */
            \__( 'Signed in as %1$s (%2$s)', 'anchor-schema' ),
            $user->display_name,
            $user->user_email
        ) ) . '</p>';
        echo '<p>' . \esc_html__( 'The app will be able to see your member content. You can disconnect it any time from your account.', 'anchor-schema' ) . '</p>';

        echo '<form method="post" action="' . \esc_url( $this->base_url() ) . '" class="anchor-app-connect__form">';
        \wp_nonce_field( self::NONCE, '_anchor_app_nonce', false );
        foreach ( self::PARAMS as $key ) {
            if ( null !== $request[ $key ] && '' !== $request[ $key ] ) {
                echo '<input type="hidden" name="' . \esc_attr( $key ) . '" value="' . \esc_attr( $request[ $key ] ) . '" />';
            }
        }
        echo '<button type="submit" name="approve" value="1" class="' . \esc_attr( $this->button_class( true ) ) . '">' . \esc_html__( 'Allow', 'anchor-schema' ) . '</button> ';
        echo '<button type="submit" name="reject" value="1" class="' . \esc_attr( $this->button_class( false ) ) . '">' . \esc_html__( 'Cancel', 'anchor-schema' ) . '</button>';
        echo '</form>';

        $logout = \wp_logout_url( $this->connect_url( $request ) );
        echo '<p class="anchor-app-connect__signout">' . \esc_html__( 'Not you?', 'anchor-schema' )
            . ' <a href="' . \esc_url( $logout ) . '">' . \esc_html__( 'Sign out', 'anchor-schema' ) . '</a></p>';
    }

    public function enqueue_style() {
        \wp_register_style( self::STYLE, false, [], '1' );
        \wp_enqueue_style( self::STYLE );
        \wp_add_inline_style( self::STYLE, self::css() );
    }

    private static function css() {
        return '.anchor-app-connect{padding:48px 16px;font-family:inherit}'
            . '.anchor-app-connect__card{max-width:480px;margin:0 auto;padding:28px;border:1px solid rgba(0,0,0,.12);border-radius:12px;background:#fff;color:#1d2327;box-sizing:border-box}'
            . '.anchor-app-connect__site{margin:0 0 8px;font-size:.85em;letter-spacing:.04em;text-transform:uppercase;opacity:.65}'
            . '.anchor-app-connect__title{margin:0 0 12px;font-size:1.6em;line-height:1.2}'
            . '.anchor-app-connect__who{font-weight:600}'
            . '.anchor-app-connect__error{padding:10px 12px;border-left:4px solid #b32d2e;background:#fcf0f1}'
            . '.anchor-app-connect__form{display:flex;gap:12px;flex-wrap:wrap;margin:20px 0 8px}'
            . '.anchor-app-connect__btn{display:inline-block;padding:12px 24px;text-align:center}'
            . '.anchor-app-connect__btn.is-plain{border:1px solid #1d2327;border-radius:6px;background:transparent;color:#1d2327;font:inherit;text-decoration:none;cursor:pointer}'
            . '.anchor-app-connect__btn.is-plain.is-primary{background:#1d2327;color:#fff}'
            . '.anchor-app-connect__signout{margin:16px 0 0;font-size:.9em;opacity:.8}'
            . '@media (max-width:480px){.anchor-app-connect__form .anchor-app-connect__btn{flex:1 1 100%;text-align:center}}';
    }
}
