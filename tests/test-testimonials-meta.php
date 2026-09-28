<?php
/** @group testimonials */
class Test_Testimonials_Meta extends WP_UnitTestCase {
	public function test_save_normalizes_video_and_indexes_related() {
		$id   = self::factory()->post->create( [ 'post_type' => 'anchor_testimonial' ] );
		$page = self::factory()->post->create( [ 'post_type' => 'page' ] );
		Anchor_Testimonial_Meta::save( $id, [
			'person_name' => 'Jane Doe', 'person_meta' => 'Patient, Denver',
			'video_url'   => 'https://youtu.be/dwr8S2iOfs8?t=5',
			'rating' => 9, 'featured' => '1', 'related' => [ $page, $page, 'x' ],
		] );
		$m = Anchor_Testimonial_Meta::get( $id );
		$this->assertSame( 'youtube', $m['video_provider'] );
		$this->assertSame( 'dwr8S2iOfs8', $m['video_id'] );
		$this->assertSame( 5, $m['video_start'] );
		$this->assertSame( 5, $m['rating'] );
		$this->assertTrue( $m['featured'] );
		$this->assertSame( [ $page ], $m['related'] );
		$this->assertSame( [ (string) $page ], get_post_meta( $id, '_at_related_id' ) );
	}
	public function test_resave_replaces_related_rows_and_clears_video() {
		delete_transient( 'anchor_vimeo_thumb_123456789' );
		$mock = function ( $pre, $args, $url ) {
			return [
				'headers'  => [],
				'body'     => wp_json_encode( [ 'thumbnail_url' => 'https://i.vimeocdn.com/video/123456789.jpg' ] ),
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'cookies'  => [],
				'filename' => null,
			];
		};
		add_filter( 'pre_http_request', $mock, 10, 3 );

		$id = self::factory()->post->create( [ 'post_type' => 'anchor_testimonial' ] );
		try {
			Anchor_Testimonial_Meta::save( $id, [ 'related' => [ 11, 12 ], 'video_url' => 'https://vimeo.com/123456789' ] );
			Anchor_Testimonial_Meta::save( $id, [ 'related' => [ 12 ], 'video_url' => '' ] );
		} finally {
			remove_filter( 'pre_http_request', $mock, 10 );
			delete_transient( 'anchor_vimeo_thumb_123456789' );
		}

		$this->assertSame( [ '12' ], get_post_meta( $id, '_at_related_id' ) );
		$this->assertSame( '', Anchor_Testimonial_Meta::get( $id )['video_id'] );
		$this->assertFalse( Anchor_Testimonial_Meta::get( $id )['featured'] );
	}
}
