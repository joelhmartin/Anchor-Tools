<?php
use Anchor\Announcements\Audience\Conditions\SpecificPeople;
use Anchor\Announcements\Audience\Conditions\UserField;
use Anchor\Announcements\Audience\Conditions\UserRegistered;
use Anchor\Announcements\Audience\Conditions\UserRole;

class Test_Announcements_Conditions_Users extends Anchor_Announcements_TestCase {

	public function test_user_role() {
		$this->make_user( 'sub@x.com' );
		$this->make_user( 'ed@x.com', [ 'role' => 'editor' ] );
		$set = ( new UserRole() )->match( [ 'roles' => [ 'editor' ] ] );
		$this->assertTrue( $set->has( 'ed@x.com' ) );
		$this->assertFalse( $set->has( 'sub@x.com' ) );
		$this->assertSame( 0, ( new UserRole() )->match( [ 'roles' => [] ] )->count() );
	}

	public function test_user_registered_range() {
		$this->make_user( 'old@x.com', [ 'user_registered' => '2025-01-10 12:00:00' ] );
		$this->make_user( 'new@x.com', [ 'user_registered' => '2026-02-10 12:00:00' ] );
		$set = ( new UserRegistered() )->match( [ 'from' => '2026-01-01', 'to' => '' ] );
		$this->assertTrue( $set->has( 'new@x.com' ) );
		$this->assertFalse( $set->has( 'old@x.com' ) );
	}

	public function test_user_field_compares() {
		$a = $this->make_user( 'a@x.com' );
		$b = $this->make_user( 'b@x.com' );
		update_user_meta( $a, 'practice_state', 'Texas' );
		update_user_meta( $b, 'practice_state', 'Ohio' );
		$c = new UserField();
		$this->assertSame( [ 'a@x.com' ], array_column( $c->match( [ 'key' => 'practice_state', 'compare' => '=', 'value' => 'Texas' ] )->all(), 'email' ) );
		$this->assertTrue( $c->match( [ 'key' => 'practice_state', 'compare' => 'contains', 'value' => 'hi' ] )->has( 'b@x.com' ) );
		$this->assertSame( 2, $c->match( [ 'key' => 'practice_state', 'compare' => 'exists', 'value' => '' ] )->count() );
		$this->assertSame( 0, $c->match( [ 'key' => '', 'compare' => '=', 'value' => 'x' ] )->count() );
	}

	public function test_user_field_key_keeps_its_case() {
		$a = $this->make_user( 'a@x.com' );
		update_user_meta( $a, 'MyField', 'yes' );
		$this->assertTrue( ( new UserField() )->match( [ 'key' => 'MyField', 'compare' => '=', 'value' => 'yes' ] )->has( 'a@x.com' ) );
	}

	public function test_unconfigured_conditions_are_dropped_on_sanitize() {
		$r   = $this->module()->resolver();
		$raw = [ 'groups' => [ [ 'conditions' => [
			[ 'type' => 'user_role', 'params' => [ 'roles' => [] ] ],
			[ 'type' => 'specific_people', 'params' => [ 'users' => [], 'emails' => 'not-an-email' ] ],
			[ 'type' => 'user_field', 'params' => [ 'key' => '', 'compare' => 'exists' ] ],
			[ 'type' => 'user_field', 'params' => [ 'key' => 'k', 'compare' => '=', 'value' => '' ] ],
			[ 'type' => 'user_field', 'params' => [ 'key' => 'k', 'compare' => 'exists' ] ],
			[ 'type' => 'wc_total_spent', 'params' => [ 'compare' => '>=' ] ],
			[ 'type' => 'wc_order_count', 'params' => [ 'compare' => '>=' ] ],
			[ 'type' => 'user_role', 'params' => [ 'roles' => [ 'subscriber' ] ] ],
		] ] ] ];
		$kept = $r->sanitize( $raw )['groups'][0]['conditions'];
		$this->assertSame( [ 'user_field', 'user_role' ], array_column( $kept, 'type' ) );
		// A group with only unconfigured conditions disappears, so it cannot match everyone.
		$this->assertSame( [], $r->sanitize( [ 'groups' => [ [ 'conditions' => [ [ 'type' => 'user_role', 'params' => [] ] ] ] ] ] )['groups'] );
		$this->assertSame( 0, $r->resolve( [ 'groups' => [ [ 'conditions' => [ [ 'type' => 'wc_total_spent', 'params' => [] ] ] ] ] ] )->count() );
	}

	public function test_group_with_only_dropped_positive_conditions_is_removed_not_widened() {
		$r = $this->module()->resolver();
		// Positive condition incomplete + a valid negated one: must not become "everyone except".
		$rules = [ 'groups' => [ [ 'conditions' => [
			[ 'type' => 'user_role', 'params' => [] ],
			[ 'type' => 'specific_people', 'negate' => true, 'params' => [ 'emails' => 'x@x.com' ] ],
		] ] ] ];
		$this->assertSame( [], $r->sanitize( $rules )['groups'] );
		$this->make_user( 'someone@x.com' );
		$this->assertSame( 0, $r->resolve( $rules )->count() );
	}

	public function test_all_negated_group_by_choice_still_starts_from_everyone() {
		$r = $this->module()->resolver();
		$this->make_user( 'keep@x.com' );
		$this->make_user( 'skip@x.com' );
		$rules = [ 'groups' => [ [ 'conditions' => [ [ 'type' => 'specific_people', 'negate' => true, 'params' => [ 'emails' => 'skip@x.com' ] ] ] ] ] ];
		$this->assertCount( 1, $r->sanitize( $rules )['groups'] );
		$set = $r->resolve( $rules );
		$this->assertTrue( $set->has( 'keep@x.com' ) );
		$this->assertFalse( $set->has( 'skip@x.com' ) );
	}

	public function test_specific_people_users_and_pasted_addresses() {
		$u   = $this->make_user( 'user@x.com', [ 'display_name' => 'Uma User' ] );
		$set = ( new SpecificPeople() )->match( [ 'users' => [ $u ], 'emails' => "Pasted@X.com, second@x.com\nnot-an-email" ] );
		$this->assertSame( [ 'pasted@x.com', 'second@x.com', 'user@x.com' ], array_column( $set->all(), 'email' ) );
		$this->assertSame( 'Uma User', $set->get( 'user@x.com' )['name'] );
	}

	public function test_all_four_are_registered() {
		$keys = array_keys( $this->module()->conditions->all() );
		foreach ( [ 'user_role', 'user_registered', 'user_field', 'specific_people' ] as $k ) {
			$this->assertContains( $k, $keys );
		}
	}
}
