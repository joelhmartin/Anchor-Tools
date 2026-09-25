<?php
declare(strict_types=1);

namespace Anchor\Courses\Support;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Find or create the account a learner will be enrolled under.
 *
 * Progress, credits and certificates all hang off a user id, so somebody being
 * added by name and email has to become a real WordPress user. They are being
 * ADDED by staff, though - they did not sign up - so no "here is your new
 * password" email goes out.
 *
 * This mirrors the events module's `Entitlements::create_account()`
 * (anchor-events-manager/class-entitlements.php) on purpose, from a different
 * starting point - a name and an email typed into a form, with no seat, rather
 * than a registration seat. Courses must work without the events module (spec
 * section 1), so this cannot call into it directly; the duplication is a known,
 * parked cost (SDD progress ledger, Task 21 ruling) with a named follow-up: a
 * shared `includes/class-anchor-accounts.php` primitive both modules would call.
 * The two must keep agreeing on one thing - no welcome email - if either
 * changes that behaviour, check the other.
 */
final class Accounts {

	/**
	 * The account for this email, creating one if there is none.
	 *
	 * @return int User id, or 0 (bad email, site opted out, or create failed).
	 */
	public static function ensure_user( string $name, string $email ): int {
		$email = \sanitize_email( $email );
		$name  = \sanitize_text_field( $name );

		if ( '' === $email || ! \is_email( $email ) ) {
			return 0;
		}

		$existing = \get_user_by( 'email', $email );
		if ( $existing instanceof \WP_User ) {
			return (int) $existing->ID;
		}

		/**
		 * Whether this site creates accounts for learners who have none.
		 *
		 * Returning false means staff can only add people who already have an
		 * account here.
		 *
		 * @param bool   $create
		 * @param string $email
		 */
		if ( ! \apply_filters( 'anchor_courses_create_account', true, $email ) ) {
			return 0;
		}

		$username = self::unique_username( $email );
		$password = \wp_generate_password( 24, true, true );

		// Suppress WooCommerce's "New account" email for the length of the
		// create: wc_create_new_customer() fires woocommerce_created_customer,
		// which WC_Emails turns into a mail. wp_insert_user() sends nothing of
		// its own, so the plain branch needs no suppression - but the filter is
		// removed in finally regardless, because this request may go on to
		// create other accounts that DO want it.
		\add_filter( 'woocommerce_email_enabled_customer_new_account', '__return_false', 99 );
		try {
			if ( \function_exists( 'wc_create_new_customer' ) ) {
				// A WooCommerce customer, so My Account and past orders work.
				$user_id = \wc_create_new_customer( $email, $username, $password, [ 'display_name' => $name ] );
			} else {
				$user_id = \wp_insert_user(
					[
						'user_login'   => $username,
						'user_email'   => $email,
						'user_pass'    => $password,
						'display_name' => '' !== $name ? $name : $username,
						'role'         => (string) \get_option( 'default_role', 'subscriber' ),
					]
				);
			}
		} finally {
			\remove_filter( 'woocommerce_email_enabled_customer_new_account', '__return_false', 99 );
		}

		if ( \is_wp_error( $user_id ) || ! $user_id ) {
			// Never log the address itself.
			Log::write( 'account_create_failed', [ 'to' => \substr( \md5( $email ), 0, 8 ) ] );
			return 0;
		}

		if ( '' !== $name ) {
			\wp_update_user( [ 'ID' => (int) $user_id, 'display_name' => $name ] );
		}

		return (int) $user_id;
	}

	/** A login derived from the email, with a numeric suffix if it is taken. */
	public static function unique_username( string $email ): string {
		$base = \sanitize_user( (string) \strstr( $email, '@', true ), true );
		if ( '' === $base ) {
			$base = 'learner';
		}

		$candidate = $base;
		$suffix    = 1;
		while ( \username_exists( $candidate ) ) {
			$candidate = $base . ++$suffix;
		}

		return $candidate;
	}
}
