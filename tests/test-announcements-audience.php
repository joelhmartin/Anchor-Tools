<?php
use Anchor\Announcements\Audience\Condition;
use Anchor\Announcements\Audience\RecipientSet;
use Anchor\Announcements\Audience\Registry;
use Anchor\Announcements\Audience\Resolver;
use Anchor\Announcements\Support\Dates;

/** A fixed-set condition for engine tests. */
class AA_Fixed_Condition implements Condition {
	public function __construct( private string $k, private array $emails ) {}
	public function key(): string { return $this->k; }
	public function label(): string { return $this->k; }
	public function group(): string { return 'Test'; }
	public function available(): bool { return true; }
	public function fields(): array { return []; }
	public function match( array $params ): RecipientSet {
		$s = new RecipientSet();
		foreach ( $this->emails as $e ) { $s->add( $e ); }
		return $s;
	}
}

class Test_Announcements_Audience extends Anchor_Announcements_TestCase {

	private function resolver( array $conditions ): Resolver {
		$r = new Registry();
		foreach ( $conditions as $c ) { $r->register( $c ); }
		return new Resolver( $r );
	}

	private function rules( array $groups ): array {
		return [ 'groups' => array_map( fn( $g ) => [ 'conditions' => $g ], $groups ) ];
	}

	public function test_set_normalises_and_dedupes() {
		$s = new RecipientSet();
		$s->add( ' A@X.com ' );
		$s->add( 'a@x.com', 0, 'Guest Name' );
		$s->add( 'not-an-email' );
		$this->assertSame( 1, $s->count() );
		$this->assertSame( 'a@x.com', $s->all()[0]['email'] );
		$this->assertSame( 'Guest Name', $s->all()[0]['name'] );
	}

	public function test_user_data_wins_over_guest_data() {
		$s = new RecipientSet();
		$s->add( 'a@x.com', 0, 'Guest Name' );
		$s->add( 'a@x.com', 42, 'Account Name' );
		$s->add( 'a@x.com', 0, 'Another Guest' );
		$this->assertSame( [ 'email' => 'a@x.com', 'user_id' => 42, 'name' => 'Account Name' ], $s->get( 'a@x.com' ) );
	}

	public function test_set_algebra() {
		$a = new RecipientSet(); $a->add( '1@x.com' ); $a->add( '2@x.com' );
		$b = new RecipientSet(); $b->add( '2@x.com' ); $b->add( '3@x.com' );
		$this->assertSame( 3, $a->union( $b )->count() );
		$this->assertTrue( $a->intersect( $b )->has( '2@x.com' ) );
		$this->assertSame( 1, $a->intersect( $b )->count() );
		$this->assertSame( [ '1@x.com' ], array_column( $a->diff( $b )->all(), 'email' ) );
	}

	public function test_and_inside_a_group_or_between_groups() {
		$r = $this->resolver( [ new AA_Fixed_Condition( 'a', [ '1@x.com', '2@x.com' ] ), new AA_Fixed_Condition( 'b', [ '2@x.com', '3@x.com' ] ), new AA_Fixed_Condition( 'c', [ '9@x.com' ] ) ] );
		$set = $r->resolve( $this->rules( [
			[ [ 'type' => 'a', 'negate' => false, 'params' => [] ], [ 'type' => 'b', 'negate' => false, 'params' => [] ] ],
			[ [ 'type' => 'c', 'negate' => false, 'params' => [] ] ],
		] ) );
		$this->assertSame( [ '2@x.com', '9@x.com' ], array_column( $set->all(), 'email' ) );
	}

	public function test_negation_subtracts_within_the_group() {
		$r = $this->resolver( [ new AA_Fixed_Condition( 'a', [ 'pasted@x.com', '2@x.com' ] ), new AA_Fixed_Condition( 'b', [ '2@x.com' ] ) ] );
		$set = $r->resolve( $this->rules( [ [ [ 'type' => 'a', 'negate' => false, 'params' => [] ], [ 'type' => 'b', 'negate' => true, 'params' => [] ] ] ] ) );
		// pasted@x.com is not a user, so it is outside the universe: it must survive anyway.
		$this->assertSame( [ 'pasted@x.com' ], array_column( $set->all(), 'email' ) );
	}

	public function test_only_negated_group_uses_the_universe() {
		$this->make_user( 'u1@x.com' );
		$this->make_user( 'u2@x.com' );
		$r   = $this->resolver( [ new AA_Fixed_Condition( 'b', [ 'u2@x.com' ] ) ] );
		$set = $r->resolve( $this->rules( [ [ [ 'type' => 'b', 'negate' => true, 'params' => [] ] ] ] ) );
		$this->assertTrue( $set->has( 'u1@x.com' ) );
		$this->assertFalse( $set->has( 'u2@x.com' ) );
	}

	public function test_sanitize_is_structural_and_keeps_unknown_types() {
		$r   = $this->resolver( [ new AA_Fixed_Condition( 'a', [] ) ] );
		$out = $r->sanitize( wp_json_encode( [ 'groups' => [ [ 'conditions' => [ [ 'type' => 'nope' ] ] ], [ 'conditions' => [ [ 'type' => 'a', 'negate' => '1', 'params' => [ 'x' => 1 ] ], [ 'type' => '' ], [ 'params' => [] ] ] ], [ 'conditions' => [] ] ] ] ) );
		$this->assertSame(
			[ 'groups' => [
				[ 'conditions' => [ [ 'type' => 'nope', 'negate' => false, 'params' => [] ] ] ],
				[ 'conditions' => [ [ 'type' => 'a', 'negate' => true, 'params' => [ 'x' => 1 ] ] ] ],
			] ],
			$out
		);
		$this->assertSame( [ 'groups' => [] ], $r->sanitize( 'garbage' ) );
	}

	public function test_a_group_with_an_unavailable_condition_contributes_nobody_but_other_groups_resolve() {
		$r     = $this->resolver( [ new AA_Fixed_Condition( 'a', [ '1@x.com', '2@x.com' ] ), new AA_Fixed_Condition( 'c', [ '9@x.com' ] ) ] );
		$rules = $this->rules( [
			[ [ 'type' => 'a', 'negate' => false, 'params' => [] ], [ 'type' => 'wc_purchased', 'negate' => false, 'params' => [ 'products' => [ 5 ] ] ] ],
			[ [ 'type' => 'c', 'negate' => false, 'params' => [] ] ],
		] );
		$this->assertCount( 2, $r->sanitize( $rules )['groups'] );
		$this->assertCount( 2, $r->sanitize( $rules )['groups'][0]['conditions'] );
		$this->assertSame( [ 'Group 1: "wc_purchased" is not available on this site.' ], $r->problems( $rules ) );
		$this->assertSame( [ '9@x.com' ], array_column( $r->resolve( $rules )->all(), 'email' ) );
	}

	public function test_problems_is_empty_for_usable_rules() {
		$r = $this->resolver( [ new AA_Fixed_Condition( 'a', [] ) ] );
		$this->assertSame( [], $r->problems( $this->rules( [ [ [ 'type' => 'a', 'negate' => false, 'params' => [] ] ] ] ) ) );
	}

	public function test_empty_rules_resolve_to_nobody() {
		$this->make_user( 'u1@x.com' );
		$this->assertSame( 0, $this->resolver( [] )->resolve( [ 'groups' => [] ] )->count() );
	}

	public function test_gmt_range_is_inclusive_site_days() {
		update_option( 'timezone_string', 'America/New_York' );
		[ $from, $to ] = Dates::gmt_range( '2026-03-01', '2026-03-31' );
		$this->assertSame( '2026-03-01 05:00:00', $from );
		$this->assertSame( '2026-04-01 03:59:59', $to ); // DST began 2026-03-08
		$this->assertSame( [ null, null ], Dates::gmt_range( '', 'garbage' ) );
		update_option( 'timezone_string', '' );
	}
}
