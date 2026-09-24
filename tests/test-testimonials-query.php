<?php
/** @group testimonials */
class Test_Testimonials_Query extends WP_UnitTestCase {
	private function t( $aud, $related = [], $extra = [] ) {
		$id = self::factory()->post->create( [ 'post_type' => 'anchor_testimonial', 'post_content' => $extra['content'] ?? 'Great.' ] );
		wp_set_object_terms( $id, $aud, 'anchor_testimonial_audience' );
		Anchor_Testimonial_Meta::save( $id, array_merge( [ 'related' => $related ], $extra ) );
		return $id;
	}
	public function test_audience_filter() {
		$p = $this->t( 'patient' ); $d = $this->t( 'doctor' );
		$ids = wp_list_pluck( Anchor_Testimonial_Query::find( [ 'audience' => 'doctor' ] ), 'ID' );
		$this->assertSame( [ $d ], $ids );
	}
	public function test_related_current_on_occurrence_matches_group_parent() {
		$parent = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$child  = self::factory()->post->create( [ 'post_type' => 'post' ] );
		update_post_meta( $child, '_anchor_event_group_role', 'child' );
		update_post_meta( $child, '_anchor_event_group_id', $parent );
		$hit  = $this->t( 'doctor', [ $parent ] );
		$this->t( 'doctor', [ 999999 ] );
		$ids = wp_list_pluck( Anchor_Testimonial_Query::find( [ 'related' => 'current', 'fallback' => 'none' ], $child ), 'ID' );
		$this->assertSame( [ $hit ], $ids );
	}
	public function test_fallback_all_when_nothing_related() {
		$a = $this->t( 'patient' );
		$page = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$ids = wp_list_pluck( Anchor_Testimonial_Query::find( [ 'related' => 'current' ], $page ), 'ID' );
		$this->assertSame( [ $a ], $ids );
	}
	public function test_type_video_and_featured() {
		$v = $this->t( 'patient', [], [ 'video_url' => 'https://youtu.be/dwr8S2iOfs8', 'featured' => '1' ] );
		$this->t( 'patient', [], [ 'featured' => '1' ] );
		$ids = wp_list_pluck( Anchor_Testimonial_Query::find( [ 'type' => 'video', 'featured' => '1' ] ), 'ID' );
		$this->assertSame( [ $v ], $ids );
	}
	public function test_type_quote_excludes_video_and_includes_missing_or_empty_video_id() {
		$quote = $this->t( 'patient' );
		$video = $this->t( 'patient', [], [ 'video_url' => 'https://youtu.be/dwr8S2iOfs8' ] );
		$ids   = wp_list_pluck( Anchor_Testimonial_Query::find( [ 'type' => 'quote' ] ), 'ID' );
		$this->assertContains( $quote, $ids );
		$this->assertNotContains( $video, $ids );
	}
	public function test_related_junk_only_with_default_fallback_returns_all() {
		$a = $this->t( 'patient' );
		$b = $this->t( 'doctor' );
		$ids = wp_list_pluck( Anchor_Testimonial_Query::find( [ 'related' => 'abc' ] ), 'ID' );
		sort( $ids );
		$expected = [ $a, $b ];
		sort( $expected );
		$this->assertSame( $expected, $ids );
	}

	/**
	 * Final whole-branch review finding 5: `limit <= 0` must mean "all"
	 * (capped at 100), matching how the speakers module already treats -1,
	 * instead of clamping -1/0 down to 1 (find()'s previous
	 * `max( 1, min( 100, (int) $a['limit'] ) )`). Captured via pre_get_posts
	 * rather than creating 100+ posts.
	 */
	public function test_limit_zero_or_negative_means_all_capped_at_100() {
		$captured = [];
		$capture  = function ( $query ) use ( &$captured ) {
			if ( $query->get( 'post_type' ) === Anchor_Testimonials_Module::CPT ) {
				$captured[] = $query->get( 'posts_per_page' );
			}
			return $query;
		};
		add_action( 'pre_get_posts', $capture );
		try {
			Anchor_Testimonial_Query::find( [ 'limit' => -1 ] );
			Anchor_Testimonial_Query::find( [ 'limit' => 0 ] );
			Anchor_Testimonial_Query::find( [ 'limit' => 5 ] );
			Anchor_Testimonial_Query::find( [ 'limit' => 250 ] );
		} finally {
			remove_action( 'pre_get_posts', $capture );
		}

		$this->assertSame(
			[ 100, 100, 5, 100 ],
			$captured,
			'-1 and 0 must mean "all" capped at 100; a positive limit is still capped at 100; a normal positive limit passes through.'
		);
	}
}
