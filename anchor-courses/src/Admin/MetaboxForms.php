<?php
declare(strict_types=1);

namespace Anchor\Courses\Admin;

use Anchor\Courses\Content\CoursePostType;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * The one place a metabox queues an admin-post `<form>` to be printed outside
 * the post-edit `<form>` (CodeRabbit PR #29, Task 32 fix wave).
 *
 * A metabox body renders inside WordPress's own post-edit `<form>`. Browsers
 * ignore a nested `<form>` start tag entirely, so any admin-post `<form>` a
 * metabox tries to print directly submits to post.php instead of
 * admin-post.php, and its `_wpnonce` field can shadow the post form's own
 * `update-post_{id}` nonce (PHP keeps the LAST value for a repeated key).
 *
 * The fix is not "don't use a form" - the four affected controls
 * (LearnerReports::render_add_learner_form(), ::revoke_button(),
 * EnrollmentManager::render_form(), CourseEditor::role_row()'s delete-role
 * button) all still need a real POST to a real admin-post handler. Instead,
 * each caller queues the actual `<form>...</form>` markup here, keyed by a
 * unique id, and prints only the VISIBLE control (a button, a select, a text
 * input) inline in the metabox with a `form="<that id>"` attribute. The
 * queued forms are printed once, from `admin_footer`, on the course edit
 * screen only - outside any `<form>`, so nothing here is ever nested.
 */
final class MetaboxForms {

	/** @var array<string,string> Form id => the form's own rendered HTML. */
	private static array $queue = [];

	private static bool $hooked = false;

	/**
	 * Queue a form's HTML (the `<form id="$id" ...>...</form>` markup,
	 * including its own nonce and hidden fields) to print from admin_footer.
	 * Idempotent per id, so a metabox that renders more than once in a
	 * request (it does not, normally) does not queue duplicates.
	 */
	public static function queue( string $id, string $html ): void {
		self::$queue[ $id ] = $html;

		if ( ! self::$hooked ) {
			self::$hooked = true;
			\add_action( 'admin_footer', [ self::class, 'render' ] );
		}
	}

	/**
	 * Build one complete, self-contained admin-post <form> (its own nonce and
	 * hidden fields, nothing else) and queue it under $id. Every control that
	 * drives it - a button, a select, an input - lives elsewhere in the page
	 * and is bound to it by a matching `form="$id"` attribute; this is the
	 * one place that shape gets built, so the three admin-post forms this
	 * module prints from a metabox (add/revoke a learner, manage an
	 * enrolment, delete a course role) agree on it.
	 *
	 * @param array<string,string> $hidden       Hidden field name => value, besides the nonce.
	 * @param string               $extra_attrs  Raw extra attributes for the <form> tag (e.g. onsubmit="…"); caller escapes.
	 */
	public static function queue_form( string $id, string $action, array $hidden, string $nonce_action, string $extra_attrs = '' ): void {
		$html = \sprintf(
			'<form id="%s" method="post" action="%s"%s>',
			\esc_attr( $id ),
			\esc_url( \admin_url( 'admin-post.php' ) ),
			'' === $extra_attrs ? '' : ' ' . $extra_attrs
		);
		$html .= (string) \wp_nonce_field( $nonce_action, '_wpnonce', true, false );
		$html .= \sprintf( '<input type="hidden" name="action" value="%s" />', \esc_attr( $action ) );
		foreach ( $hidden as $name => $value ) {
			$html .= \sprintf( '<input type="hidden" name="%s" value="%s" />', \esc_attr( $name ), \esc_attr( $value ) );
		}
		$html .= '</form>';

		self::queue( $id, $html );
	}

	/** admin_footer callback. Course edit screen only - see class docblock. */
	public static function render(): void {
		$screen = \function_exists( '\get_current_screen' ) ? \get_current_screen() : null;
		if ( ! $screen || CoursePostType::CPT !== $screen->id ) {
			return;
		}

		foreach ( self::$queue as $html ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- caller-built admin-post form markup, already escaped at each field.
		}
	}

	/** Test seam: forget every queued form and unhook. */
	public static function reset(): void {
		self::$queue  = [];
		self::$hooked = false;
	}
}
