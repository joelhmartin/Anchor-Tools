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
 * password" email goes out from here. (The WooCommerce adapter, for an order
 * created outside checkout, sends WordPress's own set-password notice itself,
 * and only for an account resolve() reports as created just now.)
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
	 * @param object|null $context What is asking - a WC_Order when the
	 *                             WooCommerce adapter resolves a guest buyer,
	 *                             null for staff adding a learner by hand.
	 *                             Passed through to the filter only.
	 * @return int User id, or 0 (bad email, site opted out, or create failed).
	 */
	public static function ensure_user( string $name, string $email, ?object $context = null ): int {
		return self::resolve( $name, $email, $context )['user_id'];
	}

	/**
	 * ensure_user() with the outcome spelled out, for a caller that has to
	 * act differently on "found", "created just now" and "declined" (the
	 * WooCommerce adapter links + notifies only an account it created, and
	 * notes a declined create on the order).
	 *
	 * @param object|null $context See ensure_user().
	 * @return array{user_id:int,created:bool,declined:bool}
	 */
	public static function resolve( string $name, string $email, ?object $context = null ): array {
		$email = \sanitize_email( $email );
		$name  = \sanitize_text_field( $name );
		$none  = [ 'user_id' => 0, 'created' => false, 'declined' => false ];

		if ( '' === $email || ! \is_email( $email ) ) {
			return $none;
		}

		$existing = \get_user_by( 'email', $email );
		if ( $existing instanceof \WP_User ) {
			return [ 'user_id' => (int) $existing->ID, 'created' => false, 'declined' => false ];
		}

		/**
		 * Whether this site creates accounts for learners who have none.
		 *
		 * This is the single account-creation point in the courses module:
		 * both staff adding a learner (Learners tab, $context null) and the
		 * WooCommerce adapter resolving a guest course buyer ($context the
		 * WC_Order) come through here. Returning false means only people
		 * who already have an account here can be enrolled.
		 *
		 * Independent of the events module's `anchor_events_create_account`:
		 * different module, different reason - opting one out does not opt
		 * the other out.
		 *
		 * @param bool        $create
		 * @param string      $email
		 * @param object|null $context A WC_Order, or null for a staff add.
		 */
		if ( ! \apply_filters( 'anchor_courses_create_account', true, $email, $context ) ) {
			return [ 'user_id' => 0, 'created' => false, 'declined' => true ];
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
			return $none;
		}

		if ( '' !== $name ) {
			\wp_update_user( [ 'ID' => (int) $user_id, 'display_name' => $name ] );
		}

		return [ 'user_id' => (int) $user_id, 'created' => true, 'declined' => false ];
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
