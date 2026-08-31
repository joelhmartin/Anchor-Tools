<?php
/**
 * Anchor Auth Form — the plugin's one sign-in / register form.
 *
 * Extracted from the Anchor Webinars gate, which is now just call site #1. Any
 * surface can render it: a page template, a shortcode ([anchor_auth_form]), or
 * the_content. Nothing here reads get_the_ID() or assumes a singular post, so
 * it works on a standalone /login/ page as well as inside a gated webinar.
 *
 * This is a core service, NOT a toggleable module: it loads unconditionally
 * from anchor-tools.php the way Anchor_Asset_Loader does. A disabled auth
 * module would silently break every gated webinar.
 *
 * Security notes worth keeping:
 *  - The login form is a real POST to wp-login.php, progressively enhanced to
 *    AJAX. wp_signon() runs the full `authenticate` filter chain, so Wordfence
 *    brute-force protection and 2FA checks still execute. The one gap is that
 *    the AJAX path has no 2FA *challenge UI*: with 2FA enabled a user would get
 *    the generic "Invalid username or password" instead of a code prompt. No
 *    2FA users are configured today. If you improve this, surface the real
 *    WP_Error code — do not swap wp_signon() for a wp-login.php POST, which
 *    would lose nothing but gain nothing either.
 *  - Error copy never reveals whether a username or email exists. That is
 *    deliberate; keep the messages neutral.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Anchor_Auth_Form {

    /** Settings live in their own option so the component isn't tied to webinars. */
    const OPTION_KEY = 'anchor_auth_settings';

    /** Option we seed from on first load (the webinars module used to own these). */
    const LEGACY_OPTION_KEY = 'anchor_webinars_settings';

    /** Nonce action shared by both AJAX endpoints. */
    const NONCE = 'anchor_auth';

    /** Script/style handles. */
    const STYLE_HANDLE  = 'anchor-auth';
    const SCRIPT_HANDLE = 'anchor-auth';

    /** wp_localize_script() must run once per request, not once per render(). */
    private static $localized = false;

    /** Memoised settings for this request. */
    private static $settings = null;

    /**
     * Wire up the hooks. Called once from anchor-tools.php at plugin load.
     */
    public static function init() {
        add_action( 'wp_ajax_nopriv_anchor_auth_login', [ __CLASS__, 'handle_login' ] );
        add_action( 'wp_ajax_anchor_auth_login', [ __CLASS__, 'handle_login' ] );
        add_action( 'wp_ajax_nopriv_anchor_auth_register', [ __CLASS__, 'handle_register' ] );
        add_action( 'wp_ajax_anchor_auth_register', [ __CLASS__, 'handle_register' ] );

        add_shortcode( 'anchor_auth_form', [ __CLASS__, 'shortcode' ] );

        add_filter( 'anchor_settings_tabs', [ __CLASS__, 'register_tab' ], 45 );
        add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );

        // WP Rocket's Remove Unused CSS strips selectors it can't see in the
        // markup it crawls. exclude_css does not help here; the safelist does.
        // One regex covers the component because every class is `anchor-auth`-prefixed.
        add_filter( 'rocket_rucss_safelist', [ __CLASS__, 'rucss_safelist' ] );
    }

    /* ---------------------------------------------------------------------
     * Public API
     * ------------------------------------------------------------------ */

    /**
     * Render the sign-in / register form.
     *
     * @param array $args {
     *     @type string    $redirect_to   Where to send the visitor after success. Passed through
     *                                    wp_validate_redirect() against home_url() — an off-site URL
     *                                    falls back to the default. Default: the my-account page.
     *     @type string    $title         Heading override.
     *     @type string    $subtitle      Sub-heading override.
     *     @type string    $context       Slug for analytics/filters, e.g. 'webinar' | 'login-page'.
     *     @type bool|null $show_register Force the Register tab off. Null (default) follows the
     *                                    `allow_registration` setting. Callers may force OFF, never ON.
     * }
     * @return string HTML. Echoes nothing.
     */
    public static function render( $args = [] ) {
        $args = wp_parse_args( (array) $args, [
            'redirect_to'   => '',
            'title'         => '',
            'subtitle'      => '',
            'context'       => 'default',
            'show_register' => null,
        ] );

        /** Filter the arguments before rendering. */
        $args = apply_filters( 'anchor_auth_form_args', $args );

        $opts        = self::get_settings();
        $context     = sanitize_key( $args['context'] ) ?: 'default';
        $redirect_to = self::safe_redirect( $args['redirect_to'] );

        // A caller may force registration off, but never on: if the site has it
        // disabled, no call site can re-enable it.
        $can_register = self::registration_enabled();
        if ( null !== $args['show_register'] && ! $args['show_register'] ) {
            $can_register = false;
        }

        $turnstile_key = ( $can_register && self::turnstile_configured() ) ? $opts['turnstile_site_key'] : '';
        $lost_url      = wp_lostpassword_url( $redirect_to );

        $title = $args['title'] !== '' ? $args['title'] : __( 'Sign in', 'anchor-schema' );
        if ( $args['subtitle'] !== '' ) {
            $subtitle = $args['subtitle'];
        } else {
            $subtitle = $can_register
                ? __( 'Sign in or create a free account to continue.', 'anchor-schema' )
                : __( 'Please sign in to continue.', 'anchor-schema' );
        }

        $style = sprintf(
            '--anchor-auth-accent:%s;--anchor-auth-accent-contrast:%s;',
            $opts['accent_color'],
            $opts['accent_text_color']
        );

        // Render implies assets — a shortcode or template call site shouldn't
        // have to remember to enqueue separately.
        self::enqueue_assets();

        ob_start();
        ?>
        <div class="anchor-auth anchor-auth--<?php echo esc_attr( $context ); ?>" data-anchor-auth-context="<?php echo esc_attr( $context ); ?>" style="<?php echo esc_attr( $style ); ?>">
            <div class="anchor-auth-login">
                <h2 class="anchor-auth-login__title"><?php echo esc_html( $title ); ?></h2>
                <p class="anchor-auth-login__subtitle"><?php echo esc_html( $subtitle ); ?></p>

                <?php if ( $can_register ) : ?>
                    <div class="anchor-auth__tabs" role="tablist">
                        <button type="button" class="anchor-auth__tab is-active" data-awtab="login" role="tab" aria-selected="true"><?php echo esc_html__( 'Sign In', 'anchor-schema' ); ?></button>
                        <button type="button" class="anchor-auth__tab" data-awtab="register" role="tab" aria-selected="false"><?php echo esc_html__( 'Register', 'anchor-schema' ); ?></button>
                    </div>
                <?php endif; ?>

                <div class="anchor-auth__panel is-active" data-awpanel="login">
                    <form class="anchor-auth-login__form" method="post" novalidate action="<?php echo esc_url( site_url( 'wp-login.php', 'login_post' ) ); ?>">
                        <input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect_to ); ?>" />
                        <div class="anchor-auth-login__field">
                            <label for="awl-user"><?php echo esc_html__( 'Username or Email', 'anchor-schema' ); ?></label>
                            <input type="text" id="awl-user" name="log" autocomplete="username" required />
                        </div>
                        <div class="anchor-auth-login__field">
                            <label for="awl-pass"><?php echo esc_html__( 'Password', 'anchor-schema' ); ?></label>
                            <input type="password" id="awl-pass" name="pwd" autocomplete="current-password" required />
                        </div>
                        <div class="anchor-auth-login__row">
                            <label class="anchor-auth-login__remember">
                                <input type="checkbox" name="rememberme" value="1" /> <?php echo esc_html__( 'Remember me', 'anchor-schema' ); ?>
                            </label>
                            <a class="anchor-auth-login__lost" href="<?php echo esc_url( $lost_url ); ?>"><?php echo esc_html__( 'Lost your password?', 'anchor-schema' ); ?></a>
                        </div>
                        <div class="anchor-auth-login__error" role="alert" hidden></div>
                        <button type="submit" class="anchor-auth-login__submit"><?php echo esc_html__( 'Sign In', 'anchor-schema' ); ?></button>
                    </form>
                </div>

                <?php if ( $can_register ) : ?>
                    <div class="anchor-auth__panel" data-awpanel="register">
                        <form class="anchor-auth-register__form" method="post" novalidate action="<?php echo esc_url( wp_registration_url() ); ?>">
                            <input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect_to ); ?>" />
                            <div class="anchor-auth-login__field">
                                <label for="awr-name"><?php echo esc_html__( 'Full Name', 'anchor-schema' ); ?></label>
                                <input type="text" id="awr-name" name="name" autocomplete="name" />
                            </div>
                            <div class="anchor-auth-login__field">
                                <label for="awr-email"><?php echo esc_html__( 'Email', 'anchor-schema' ); ?></label>
                                <input type="email" id="awr-email" name="email" autocomplete="email" required />
                            </div>
                            <div class="anchor-auth-login__field">
                                <label for="awr-pass"><?php echo esc_html__( 'Create a Password', 'anchor-schema' ); ?></label>
                                <input type="password" id="awr-pass" name="pwd" autocomplete="new-password" required />
                            </div>
                            <?php // Honeypot: hidden from real users; bots that autofill it are rejected. ?>
                            <div class="anchor-auth-hp" aria-hidden="true">
                                <label for="awr-website"><?php echo esc_html__( 'Website', 'anchor-schema' ); ?></label>
                                <input type="text" id="awr-website" name="website" tabindex="-1" autocomplete="off" />
                            </div>
                            <?php if ( $turnstile_key ) : // Explicit render (see anchor-auth.js) — no auto-render class. ?>
                                <div class="anchor-auth-register__captcha" data-sitekey="<?php echo esc_attr( $turnstile_key ); ?>"></div>
                            <?php endif; ?>
                            <div class="anchor-auth-register__error" role="alert" hidden></div>
                            <button type="submit" class="anchor-auth-register__submit"><?php echo esc_html__( 'Create Account', 'anchor-schema' ); ?></button>
                            <p class="anchor-auth-login__register">
                                <?php echo esc_html__( 'Already have an account?', 'anchor-schema' ); ?>
                                <a href="#" data-awtab-link="login"><?php echo esc_html__( 'Sign in', 'anchor-schema' ); ?></a>
                            </p>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        $html = ob_get_clean();

        /** Filter the rendered form HTML. */
        return apply_filters( 'anchor_auth_form_html', $html, $args );
    }

    /**
     * Enqueue the component's CSS/JS. Idempotent and safe to call from any
     * surface, including late (the_content / a shortcode), where WordPress
     * prints the assets in the footer.
     */
    public static function enqueue_assets() {
        wp_enqueue_style(
            self::STYLE_HANDLE,
            Anchor_Asset_Loader::url( 'includes/assets/anchor-auth.css' ),
            [],
            '1.0.0'
        );

        $deps = [];
        if ( self::registration_enabled() && self::turnstile_configured() ) {
            wp_enqueue_script( 'cf-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', [], null, true );
            $deps[] = 'cf-turnstile';
        }

        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            Anchor_Asset_Loader::url( 'includes/assets/anchor-auth.js' ),
            $deps,
            '1.0.0',
            true
        );

        // wp_localize_script() appends a fresh <script> every call; only once.
        if ( ! self::$localized ) {
            wp_localize_script( self::SCRIPT_HANDLE, 'ANCHOR_AUTH', [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( self::NONCE ),
            ] );
            self::$localized = true;
        }
    }

    /**
     * [anchor_auth_form redirect_to="..." title="..." subtitle="..." context="..." show_register="0"]
     *
     * Lets the form drop into a Divi Code module (or any editor) without PHP.
     */
    public static function shortcode( $atts ) {
        $atts = shortcode_atts( [
            'redirect_to'   => '',
            'title'         => '',
            'subtitle'      => '',
            'context'       => 'shortcode',
            'show_register' => '',
        ], $atts, 'anchor_auth_form' );

        // Only an explicit falsey value forces the Register tab off; anything
        // else leaves the site setting in charge (callers can't force it ON).
        $show_register = null;
        if ( '' !== $atts['show_register'] ) {
            $show_register = filter_var( $atts['show_register'], FILTER_VALIDATE_BOOLEAN );
        }

        return self::render( [
            'redirect_to'   => $atts['redirect_to'],
            'title'         => $atts['title'],
            'subtitle'      => $atts['subtitle'],
            'context'       => $atts['context'],
            'show_register' => $show_register,
        ] );
    }

    /* ---------------------------------------------------------------------
     * AJAX handlers
     * ------------------------------------------------------------------ */

    /**
     * AJAX: sign a visitor in.
     */
    public static function handle_login() {
        if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed. Please refresh the page and try again.', 'anchor-schema' ) ] );
        }

        // Throttle brute-force guessing: at most 10 sign-in attempts per IP per 15 min.
        if ( self::rate_limited( 'login', 10, 15 * MINUTE_IN_SECONDS ) ) {
            wp_send_json_error( [ 'message' => __( 'Too many attempts. Please try again later.', 'anchor-schema' ) ] );
        }

        $login = sanitize_text_field( wp_unslash( $_POST['log'] ?? '' ) );
        $pass  = (string) ( $_POST['pwd'] ?? '' );

        if ( $login === '' || $pass === '' ) {
            wp_send_json_error( [ 'message' => __( 'Please enter your username and password.', 'anchor-schema' ) ] );
        }

        // wp_signon() runs the full `authenticate` chain (Wordfence, 2FA, ...).
        $user = wp_signon(
            [
                'user_login'    => $login,
                'user_password' => $pass,
                'remember'      => ! empty( $_POST['rememberme'] ),
            ],
            is_ssl()
        );

        if ( is_wp_error( $user ) ) {
            // Generic message — never reveal whether the username exists.
            wp_send_json_error( [ 'message' => __( 'Invalid username or password.', 'anchor-schema' ) ] );
        }

        wp_set_current_user( $user->ID );
        wp_send_json_success( [ 'redirect' => self::posted_redirect() ] );
    }

    /**
     * AJAX: create an account, sign the new user in immediately, and hand the
     * JS a redirect target. Honours the component's own `allow_registration`
     * setting, which is deliberately decoupled from WordPress's site-wide
     * "Anyone can register" switch.
     */
    public static function handle_register() {
        if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed. Please refresh the page and try again.', 'anchor-schema' ) ] );
        }

        if ( is_user_logged_in() ) {
            wp_send_json_success( [ 'redirect' => self::posted_redirect() ] );
        }

        if ( ! self::registration_enabled() ) {
            wp_send_json_error( [ 'message' => __( 'Registration is currently disabled. Please contact us for access.', 'anchor-schema' ) ] );
        }

        // Throttle: at most 10 account creations per IP per hour (a backstop —
        // Turnstile is the primary bot barrier; this stays lenient for shared
        // office/NAT IPs where several real people may register).
        if ( self::rate_limited( 'register', 10, HOUR_IN_SECONDS ) ) {
            wp_send_json_error( [ 'message' => __( 'Too many attempts. Please try again later.', 'anchor-schema' ) ] );
        }

        // Honeypot: real visitors never see or fill this field; bots do.
        if ( ! empty( $_POST['website'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Registration failed. Please try again.', 'anchor-schema' ) ] );
        }

        // Bot check (only enforced once Turnstile keys are configured).
        if ( ! self::verify_turnstile( (string) wp_unslash( $_POST['cf-turnstile-response'] ?? '' ) ) ) {
            wp_send_json_error( [ 'message' => __( 'Please complete the verification and try again.', 'anchor-schema' ) ] );
        }

        $email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        $pass  = (string) ( $_POST['pwd'] ?? '' );
        $name  = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );

        if ( ! is_email( $email ) ) {
            wp_send_json_error( [ 'message' => __( 'Please enter a valid email address.', 'anchor-schema' ) ] );
        }
        if ( strlen( $pass ) < 6 ) {
            wp_send_json_error( [ 'message' => __( 'Please choose a password of at least 6 characters.', 'anchor-schema' ) ] );
        }

        // Note: we deliberately do NOT check email_exists() up front and report
        // it separately — that would let the form be used to probe which emails
        // are registered. wp_insert_user() below rejects a duplicate email and
        // we return the same neutral message as any other creation failure, so
        // the response can't distinguish "already registered" from other errors.

        // Derive a unique username from the email's local part.
        $base = sanitize_user( current( explode( '@', $email ) ), true );
        if ( '' === $base ) {
            $base = 'member';
        }
        $username = $base;
        $suffix   = 1;
        while ( username_exists( $username ) ) {
            $username = $base . $suffix;
            $suffix++;
        }

        $user_id = wp_insert_user( [
            'user_login'   => $username,
            'user_email'   => $email,
            'user_pass'    => $pass,
            'display_name' => $name !== '' ? $name : $username,
            'first_name'   => $name,
            'role'         => get_option( 'default_role' ),
        ] );

        if ( is_wp_error( $user_id ) ) {
            // Neutral, non-enumerating message — covers a duplicate email as well
            // as any other failure without revealing which occurred.
            wp_send_json_error( [ 'message' => __( 'We couldn’t complete your registration. If you already have an account, please sign in instead.', 'anchor-schema' ) ] );
        }

        // Notify the site admin of the new registration (the visitor chose their
        // own password and is being logged in, so no "set password" email).
        wp_new_user_notification( $user_id, null, 'admin' );

        /** Fires after a visitor registers through the shared auth form. */
        do_action( 'anchor_auth_user_registered', $user_id );

        wp_set_current_user( $user_id );
        wp_set_auth_cookie( $user_id, true, is_ssl() );
        wp_send_json_success( [ 'redirect' => self::posted_redirect() ] );
    }

    /* ---------------------------------------------------------------------
     * Settings
     * ------------------------------------------------------------------ */

    /**
     * Component settings, seeded once from the webinars option so existing
     * Turnstile keys and the registration flag survive the extraction.
     */
    public static function get_settings() {
        if ( null !== self::$settings ) {
            return self::$settings;
        }

        $defaults = [
            'allow_registration' => 1,
            'turnstile_site_key' => '',
            'turnstile_secret'   => '',
            'accent_color'       => '#2563eb',
            'accent_text_color'  => '#ffffff',
        ];

        $settings = get_option( self::OPTION_KEY, null );

        if ( null === $settings || ! is_array( $settings ) ) {
            // First load after the extraction: migrate from the webinars option.
            // Runs on read, so it always happens before anything can write.
            $legacy = get_option( self::LEGACY_OPTION_KEY, [] );
            $legacy = is_array( $legacy ) ? $legacy : [];

            $settings = [];
            foreach ( array_keys( $defaults ) as $key ) {
                if ( isset( $legacy[ $key ] ) ) {
                    $settings[ $key ] = $legacy[ $key ];
                }
            }

            update_option( self::OPTION_KEY, wp_parse_args( $settings, $defaults ), false );
        }

        self::$settings = wp_parse_args( $settings, $defaults );

        return self::$settings;
    }

    /** Registration through this form is allowed (decoupled from WP's site-wide switch). */
    public static function registration_enabled() {
        $opts = self::get_settings();
        return ! empty( $opts['allow_registration'] );
    }

    /** Settings > Anchor Tools > Login. */
    public static function register_tab( $tabs ) {
        $tabs['login'] = [
            'label'    => __( 'Login', 'anchor-schema' ),
            'callback' => [ __CLASS__, 'render_tab_content' ],
        ];
        return $tabs;
    }

    public static function register_settings() {
        register_setting( 'anchor_auth_group', self::OPTION_KEY, [ __CLASS__, 'sanitize_settings' ] );

        add_settings_section( 'anchor_auth_registration', __( 'Registration', 'anchor-schema' ), function () {
            echo '<p>' . esc_html__( 'Let visitors create an account directly from the Sign In / Register form, without opening WordPress\'s site-wide registration page. New accounts get the default role and are signed in immediately.', 'anchor-schema' ) . '</p>';
        }, 'anchor_auth_settings' );

        add_settings_field( 'allow_registration', __( 'Allow registration', 'anchor-schema' ), function () {
            $opts = self::get_settings();
            printf(
                '<label><input type="checkbox" name="%1$s[allow_registration]" value="1" %2$s /> %3$s</label>',
                esc_attr( self::OPTION_KEY ),
                checked( ! empty( $opts['allow_registration'] ), true, false ),
                esc_html__( 'Show a Register tab so visitors can sign up.', 'anchor-schema' )
            );
        }, 'anchor_auth_settings', 'anchor_auth_registration' );

        add_settings_field( 'turnstile_site_key', __( 'Turnstile Site Key', 'anchor-schema' ), function () {
            $opts = self::get_settings();
            printf(
                '<input type="text" name="%1$s[turnstile_site_key]" value="%2$s" class="regular-text" autocomplete="off" /><p class="description">%3$s</p>',
                esc_attr( self::OPTION_KEY ),
                esc_attr( $opts['turnstile_site_key'] ),
                esc_html__( 'Cloudflare Turnstile (free) protects the register form from bots. Create a free widget at dash.cloudflare.com → Turnstile and paste both keys here. Leave blank to disable the CAPTCHA (a honeypot + rate limit still apply).', 'anchor-schema' )
            );
        }, 'anchor_auth_settings', 'anchor_auth_registration' );

        add_settings_field( 'turnstile_secret', __( 'Turnstile Secret Key', 'anchor-schema' ), function () {
            $opts = self::get_settings();
            printf(
                '<input type="password" name="%1$s[turnstile_secret]" value="%2$s" class="regular-text" autocomplete="off" />',
                esc_attr( self::OPTION_KEY ),
                esc_attr( $opts['turnstile_secret'] )
            );
        }, 'anchor_auth_settings', 'anchor_auth_registration' );

        add_settings_section( 'anchor_auth_appearance', __( 'Appearance', 'anchor-schema' ), function () {
            echo '<p>' . esc_html__( 'Match the Sign In / Register form to your brand. These colors apply to the buttons, active tab, links and focus states everywhere the form appears — the webinar gate included.', 'anchor-schema' ) . '</p>';
        }, 'anchor_auth_settings' );

        add_settings_field( 'accent_color', __( 'Accent Color', 'anchor-schema' ), function () {
            $opts = self::get_settings();
            printf(
                '<input type="color" name="%1$s[accent_color]" value="%2$s" /> <code>%2$s</code><p class="description">%3$s</p>',
                esc_attr( self::OPTION_KEY ),
                esc_attr( $opts['accent_color'] ),
                esc_html__( 'Buttons, active tab, links and focus ring.', 'anchor-schema' )
            );
        }, 'anchor_auth_settings', 'anchor_auth_appearance' );

        add_settings_field( 'accent_text_color', __( 'Button Text Color', 'anchor-schema' ), function () {
            $opts = self::get_settings();
            printf(
                '<input type="color" name="%1$s[accent_text_color]" value="%2$s" /> <code>%2$s</code><p class="description">%3$s</p>',
                esc_attr( self::OPTION_KEY ),
                esc_attr( $opts['accent_text_color'] ),
                esc_html__( 'Text/label color shown on top of the accent buttons.', 'anchor-schema' )
            );
        }, 'anchor_auth_settings', 'anchor_auth_appearance' );
    }

    public static function sanitize_settings( $input ) {
        // A save replaces what get_settings() memoised for this request.
        self::$settings = null;

        return [
            'allow_registration' => empty( $input['allow_registration'] ) ? 0 : 1,
            'turnstile_site_key' => sanitize_text_field( $input['turnstile_site_key'] ?? '' ),
            'turnstile_secret'   => sanitize_text_field( $input['turnstile_secret'] ?? '' ),
            'accent_color'       => self::sanitize_hex( $input['accent_color'] ?? '', '#2563eb' ),
            'accent_text_color'  => self::sanitize_hex( $input['accent_text_color'] ?? '', '#ffffff' ),
        ];
    }

    public static function render_tab_content() {
        echo '<form method="post" action="options.php">';
        settings_fields( 'anchor_auth_group' );
        do_settings_sections( 'anchor_auth_settings' );
        submit_button();
        echo '</form>';
    }

    /** Keep the component's CSS out of WP Rocket's unused-CSS purge. */
    public static function rucss_safelist( $safelist ) {
        $safelist   = is_array( $safelist ) ? $safelist : [];
        $safelist[] = '/anchor-auth/';
        return $safelist;
    }

    /* ---------------------------------------------------------------------
     * Internals
     * ------------------------------------------------------------------ */

    /**
     * Clamp a redirect target to this site. Anything off-host, unparseable or
     * empty falls back to the default destination — never an open redirect.
     */
    private static function safe_redirect( $url ) {
        $default = self::default_redirect();

        $url = trim( (string) $url );
        if ( '' === $url ) {
            return $default;
        }

        $safe = wp_validate_redirect( $url, '' );

        return '' !== $safe ? $safe : $default;
    }

    /** The redirect target posted by the form, re-validated server-side. */
    private static function posted_redirect() {
        return self::safe_redirect( wp_unslash( $_POST['redirect_to'] ?? '' ) );
    }

    /** Where a visitor lands after signing in when no target was given. */
    private static function default_redirect() {
        $url = '';

        if ( function_exists( 'wc_get_page_permalink' ) ) {
            $url = wc_get_page_permalink( 'myaccount' );
        }

        if ( ! $url ) {
            $page = get_page_by_path( 'my-account' );
            if ( $page ) {
                $url = get_permalink( $page );
            }
        }

        if ( ! $url ) {
            $url = home_url( '/' );
        }

        /** Filter the fallback destination after a successful sign-in. */
        return apply_filters( 'anchor_auth_default_redirect', $url );
    }

    /** Both Turnstile keys are present, so the CAPTCHA should render + be enforced. */
    private static function turnstile_configured() {
        $opts = self::get_settings();
        return $opts['turnstile_site_key'] !== '' && $opts['turnstile_secret'] !== '';
    }

    /**
     * Verify a Cloudflare Turnstile token server-side. Returns true when Turnstile
     * is not configured so registration keeps working before keys are added
     * (the honeypot + rate limit still apply in that case).
     */
    private static function verify_turnstile( $token ) {
        if ( ! self::turnstile_configured() ) {
            return true;
        }
        if ( $token === '' ) {
            return false;
        }
        $opts = self::get_settings();
        $resp = wp_remote_post( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'timeout' => 10,
            'body'    => [
                'secret'   => $opts['turnstile_secret'],
                'response' => $token,
                'remoteip' => self::client_ip(),
            ],
        ] );
        if ( is_wp_error( $resp ) ) {
            return false;
        }
        $data = json_decode( wp_remote_retrieve_body( $resp ), true );
        return ! empty( $data['success'] );
    }

    /**
     * Simple per-IP rate limit. Returns true when the caller is OVER the limit
     * and should be blocked; otherwise records the attempt and returns false.
     */
    private static function rate_limited( $bucket, $max, $window ) {
        $key   = 'anchor_auth_rl_' . $bucket . '_' . md5( self::client_ip() );
        $count = (int) get_transient( $key );
        if ( $count >= $max ) {
            return true;
        }
        set_transient( $key, $count + 1, $window );
        return false;
    }

    /** Real client IP for rate-limiting keys. Uses REMOTE_ADDR (not spoofable client headers). */
    private static function client_ip() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
    }

    /** Validate a hex color; fall back to $default when empty/invalid. */
    private static function sanitize_hex( $value, $default ) {
        $clean = sanitize_hex_color( $value );
        return $clean ? $clean : $default;
    }
}
