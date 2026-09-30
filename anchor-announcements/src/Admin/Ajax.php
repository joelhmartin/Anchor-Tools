<?php
declare(strict_types=1);

namespace Anchor\Announcements\Admin;

use Anchor\Announcements\Content\AnnouncementPostType as PT;
use Anchor\Announcements\Module;
use Anchor\Announcements\Rendering\Renderer;
use Anchor\Announcements\Sending\Queue;
use Anchor\Announcements\Suppression\Suppressions;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class Ajax {

	public function __construct() {
		foreach ( [ 'preview', 'audience', 'search', 'test_send' ] as $a ) {
			\add_action( 'wp_ajax_anchor_announcements_' . $a, [ $this, $a ] );
		}
	}

	private function guard(): void {
		if ( ! \check_ajax_referer( 'anchor_announcements', 'nonce', false ) || ! \current_user_can( Module::CAP ) ) {
			\wp_send_json_error( [ 'message' => \__( 'You are not allowed to do that.', 'anchor-schema' ) ], 403 );
		}
	}

	private function override(): array {
		// phpcs:disable WordPress.Security.NonceVerification -- guard() ran.
		return [
			'subject'   => \sanitize_text_field( \wp_unslash( $_POST['subject'] ?? '' ) ),
			'preheader' => \sanitize_text_field( \wp_unslash( $_POST['preheader'] ?? '' ) ),
			'body'      => (string) \wp_unslash( $_POST['body'] ?? '' ), // Renderer sanitizes.
		];
		// phpcs:enable
	}

	public function preview(): void {
		$this->guard();
		$id   = \absint( $_POST['post_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$mail = Renderer::render( $id, Renderer::sample_recipient(), '', false, $this->override() );
		\wp_send_json_success( [ 'html' => $mail['html'], 'subject' => $mail['subject'] ] );
	}

	public function audience(): void {
		$this->guard();
		$resolver = Module::instance()->resolver();
		$rules    = $resolver->sanitize( (string) \wp_unslash( $_POST['rules'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- sanitize() validates the structure.
		$all      = $resolver->resolve( $rules )->all();
		$gone     = Suppressions::suppressed_among( \array_column( $all, 'email' ) );
		$keep     = \array_values( \array_filter( $all, static fn( $r ) => ! isset( $gone[ \strtolower( $r['email'] ) ] ) ) );
		\wp_send_json_success(
			[
				'count'      => \count( $keep ),
				'suppressed' => \count( $all ) - \count( $keep ),
				'problems'   => $resolver->problems( $rules ),
				'sample'     => \array_map( static fn( $r ) => [ 'email' => $r['email'], 'name' => $r['name'] ], \array_slice( $keep, 0, 25 ) ),
			]
		);
	}

	public function search(): void {
		$this->guard();
		// phpcs:disable WordPress.Security.NonceVerification
		$kind = \sanitize_key( \wp_unslash( $_POST['kind'] ?? '' ) );
		$q    = \sanitize_text_field( \wp_unslash( $_POST['q'] ?? '' ) );
		// phpcs:enable
		$out = [];
		if ( 'users' === $kind ) {
			foreach ( \get_users( [ 'search' => '*' . $q . '*', 'search_columns' => [ 'user_login', 'user_email', 'display_name' ], 'number' => 20 ] ) as $u ) {
				$out[] = [ 'id' => (int) $u->ID, 'text' => $u->display_name . ' (' . $u->user_email . ')' ];
			}
		} else {
			$types = [ 'products' => [ 'product', 'product_variation' ], 'courses' => [ 'anchor_course' ], 'events' => [ 'event' ] ][ $kind ] ?? [];
			if ( $types ) {
				foreach ( \get_posts( [ 'post_type' => $types, 'post_status' => 'any', 's' => $q, 'numberposts' => 20 ] ) as $p ) {
					$out[] = [ 'id' => (int) $p->ID, 'text' => self::label( $kind, (int) $p->ID ) ];
				}
			}
		}
		\wp_send_json_success( $out );
	}

	public function test_send(): void {
		$this->guard();
		$id  = \absint( $_POST['post_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$res = Queue::test_send( $id, (string) \wp_unslash( $_POST['email'] ?? '' ), $this->override() ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- test_send() validates the address.
		\is_wp_error( $res ) ? \wp_send_json_error( [ 'message' => $res->get_error_message() ] ) : \wp_send_json_success( [ 'message' => \__( 'Test sent.', 'anchor-schema' ) ] );
	}

	public static function label( string $kind, int $id ): string {
		if ( 'users' === $kind ) {
			$u = \get_userdata( $id );
			return $u ? $u->display_name . ' (' . $u->user_email . ')' : '#' . $id;
		}
		$title = \get_the_title( $id );
		return '' !== $title ? \html_entity_decode( $title, ENT_QUOTES ) . ' (#' . $id . ')' : '#' . $id;
	}

	/** Chip labels for the ids already saved in an audience. @return array<string,array<int,string>> */
	public static function labels_for( string $raw ): array {
		$rules = \json_decode( $raw, true );
		$all   = Module::instance()->conditions->all();
		$out   = [];
		foreach ( (array) ( \is_array( $rules ) ? ( $rules['groups'] ?? [] ) : [] ) as $g ) {
			foreach ( (array) ( $g['conditions'] ?? [] ) as $c ) {
				$cond = $all[ $c['type'] ?? '' ] ?? null;
				if ( ! $cond ) { continue; }
				foreach ( $cond->fields() as $f ) {
					if ( 'search' !== $f['type'] ) { continue; }
					foreach ( (array) ( $c['params'][ $f['key'] ] ?? [] ) as $id ) {
						$out[ $f['search'] ][ (int) $id ] = self::label( $f['search'], (int) $id );
					}
				}
			}
		}
		return $out;
	}
}
