<?php
/**
 * Application Passwords hardening for the member app (spec 2026-10-12-events-feed-api).
 *
 * The phone app signs in through core's wp-admin/authorize-application.php with
 * a custom-scheme success_url. When the "Member app URL schemes" setting is
 * non-empty this class (a) refuses any redirect (custom scheme or https) that
 * isn't the member app's, so a third party can't phish a password through the
 * consent screen, and (b) keeps administrator accounts from authorizing the
 * member app. Empty setting = no behaviour change at all.
 *
 * It only adds errors to core's authorize-request validation. It never filters
 * wp_is_application_passwords_available*, and existing application passwords
 * are untouched. This is a consent-screen gate, not a security boundary: core
 * validates the URLs only when that screen renders, and an administrator can
 * still create an application password from their profile.
 *
 * @package Anchor\Events
 */

namespace Anchor\Events;

if ( ! \defined( 'ABSPATH' ) ) {
    exit;
}

class App_Auth {

    /** @var Module */
    private $module;

    public function __construct( Module $module ) {
        $this->module = $module;
        \add_action( 'wp_authorize_application_password_request_errors', [ $this, 'filter_request_errors' ], 10, 3 );
    }

    /**
     * Lowercased, de-duplicated, RFC 3986 scheme tokens from free text.
     * A token that isn't wholly a valid scheme is dropped, not trimmed.
     *
     * @param mixed $raw Comma/space separated string (or array of tokens).
     * @return string[]
     */
    public static function parse_schemes( $raw ) {
        if ( \is_array( $raw ) ) {
            $raw = \implode( ' ', \array_map( 'strval', $raw ) );
        }
        $out = [];
        foreach ( \preg_split( '/[\s,]+/', \strtolower( (string) $raw ), -1, PREG_SPLIT_NO_EMPTY ) as $token ) {
            if ( \preg_match( '/^[a-z][a-z0-9+.-]*$/', $token ) ) {
                $out[ $token ] = $token;
            }
        }
        return \array_values( $out );
    }

    /** Settings sanitizer: normalized "a, b" string ('' when nothing valid). */
    public static function sanitize( $raw ) {
        return \implode( ', ', self::parse_schemes( $raw ) );
    }

    /** @return string[] The configured allowlist; [] disables every check. */
    public function allowed_schemes() {
        return self::parse_schemes( $this->module->get_settings()['app_schemes'] ?? '' );
    }

    /**
     * Core action: wp_authorize_application_password_request_errors.
     * Core runs it from the authorize screen, after auth_redirect() (wp-admin/admin.php),
     * so the user is always logged in; a missing user (ID 0) is skipped defensively.
     *
     * @param \WP_Error $error
     * @param array     $request success_url / reject_url / app_name / app_id.
     * @param \WP_User  $user
     */
    public function filter_request_errors( $error, $request, $user = null ) {
        $allowed = $this->allowed_schemes();
        if ( ! $allowed || ! $error instanceof \WP_Error ) {
            return;
        }

        $success_scheme = self::scheme_of( $request['success_url'] ?? '' );
        $schemes        = [ $success_scheme, self::scheme_of( $request['reject_url'] ?? '' ) ];
        foreach ( $schemes as $scheme ) {
            if ( '' !== $scheme && ! \in_array( $scheme, $allowed, true ) ) {
                $error->add( 'anchor_events_app_scheme', __( "This app isn't allowed to sign in to this site.", 'anchor-schema' ) );
                return;
            }
        }

        if ( \in_array( $success_scheme, $allowed, true )
            && $user instanceof \WP_User && $user->exists()
            && \in_array( 'administrator', (array) $user->roles, true ) ) {
            $error->add( 'anchor_events_app_admin', __( "Administrator accounts can't sign in to the member app. Use a Centre account.", 'anchor-schema' ) );
        }
    }

    private static function scheme_of( $url ) {
        $scheme = \is_string( $url ) && '' !== $url ? \wp_parse_url( $url, PHP_URL_SCHEME ) : '';
        return \is_string( $scheme ) ? \strtolower( $scheme ) : '';
    }
}
