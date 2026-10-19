<?php
/**
 * PHPUnit port of the Anchor Private File Manager plugin's tests/run.php (pure helper classes), at the 9e3f491 pin.
 * The Storage_Guard cases are covered by test-library-storage.php against \Anchor\Library\StorageGuard.
 *
 * @package Anchor\Library\Tests
 * @group library
 */

class Anchor_Library_Helpers_Test extends Anchor_Library_TestCase {

	/** Last URL requested through the stubbed HTTP layer. */
	private string $last_url = '';

	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	/** Mirrors run.php's check(): label first, then actual, then expected. */
	private function check( $label, $actual, $expected ): void {
		$this->assertSame( $expected, $actual, $label );
	}

	/** Canned response for the next HTTP calls (null = transport failure), replacing run.php's wp_remote_get stub. */
	private function stub_http( $response ): void {
		remove_all_filters( 'pre_http_request' );
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( $response ) {
			$this->last_url = $url;
			return null === $response ? new \WP_Error( 'http_request_failed', 'No stub set' ) : array_merge( [ 'headers' => [], 'cookies' => [], 'filename' => null ], $response );
		}, 10, 3 );
	}

	/** Anchor_FM_Vimeo::parse_id */
	public function test_anchor_fm_vimeo_parse_id() {
		$this->check('bare numeric id', \Anchor_FM_Vimeo::parse_id('123456789'), '123456789');
		$this->check('vimeo.com/<id>', \Anchor_FM_Vimeo::parse_id('https://vimeo.com/123456789'), '123456789');
		$this->check('player.vimeo.com', \Anchor_FM_Vimeo::parse_id('https://player.vimeo.com/video/123456789'), '123456789');
		$this->check('channel url', \Anchor_FM_Vimeo::parse_id('https://vimeo.com/channels/staff/123456789'), '123456789');
		$this->check('url with hash/query', \Anchor_FM_Vimeo::parse_id('https://vimeo.com/123456789?h=abc#t=1'), '123456789');
		$this->check('trailing slash', \Anchor_FM_Vimeo::parse_id('https://vimeo.com/123456789/'), '123456789');
		$this->check('private id with hash path', \Anchor_FM_Vimeo::parse_id('https://vimeo.com/123456789/abcdef0123'), '123456789');
		$this->check('garbage returns empty', \Anchor_FM_Vimeo::parse_id('not a video'), '');
		$this->check('empty returns empty', \Anchor_FM_Vimeo::parse_id(''), '');

		// URL forms that previously 400'd because parse_id could not read them.
		$this->check('manage/videos dashboard url', \Anchor_FM_Vimeo::parse_id('https://vimeo.com/manage/videos/123456789'), '123456789');
		$this->check('ondemand url', \Anchor_FM_Vimeo::parse_id('https://vimeo.com/ondemand/someshow/123456789'), '123456789');
		$this->check('album url', \Anchor_FM_Vimeo::parse_id('https://vimeo.com/album/12345/video/123456789'), '123456789');
		$this->check('groups url', \Anchor_FM_Vimeo::parse_id('https://vimeo.com/groups/foo/videos/123456789'), '123456789');
		$this->check('iframe embed code', \Anchor_FM_Vimeo::parse_id('<iframe src="https://player.vimeo.com/video/123456789?h=abcdef0123&title=0" width="640"></iframe>'), '123456789');
		$this->check('bare id with whitespace', \Anchor_FM_Vimeo::parse_id('  123456789  '), '123456789');
		$this->check('http (not https)', \Anchor_FM_Vimeo::parse_id('http://vimeo.com/123456789'), '123456789');
		$this->check('www subdomain', \Anchor_FM_Vimeo::parse_id('https://www.vimeo.com/123456789'), '123456789');
	}

	/** Anchor_FM_Vimeo::parse_ref (id + unlisted hash) */
	public function test_anchor_fm_vimeo_parse_ref_id_unlisted_hash() {
		$this->check('ref: bare id has no hash', \Anchor_FM_Vimeo::parse_ref('123456789'), ['id' => '123456789', 'hash' => '']);
		$this->check('ref: public url has no hash', \Anchor_FM_Vimeo::parse_ref('https://vimeo.com/123456789'), ['id' => '123456789', 'hash' => '']);
		$this->check('ref: unlisted path hash', \Anchor_FM_Vimeo::parse_ref('https://vimeo.com/123456789/abcdef0123'), ['id' => '123456789', 'hash' => 'abcdef0123']);
		$this->check('ref: unlisted query hash', \Anchor_FM_Vimeo::parse_ref('https://vimeo.com/123456789?h=abcdef0123'), ['id' => '123456789', 'hash' => 'abcdef0123']);
		$this->check('ref: player url query hash', \Anchor_FM_Vimeo::parse_ref('https://player.vimeo.com/video/123456789?h=abcdef0123'), ['id' => '123456789', 'hash' => 'abcdef0123']);
		$this->check('ref: iframe embed keeps hash', \Anchor_FM_Vimeo::parse_ref('<iframe src="https://player.vimeo.com/video/123456789?h=abcdef0123&title=0"></iframe>'), ['id' => '123456789', 'hash' => 'abcdef0123']);
		$this->check('ref: manage url has no hash', \Anchor_FM_Vimeo::parse_ref('https://vimeo.com/manage/videos/123456789'), ['id' => '123456789', 'hash' => '']);
		$this->check('ref: trailing slash is not a hash', \Anchor_FM_Vimeo::parse_ref('https://vimeo.com/123456789/'), ['id' => '123456789', 'hash' => '']);
		$this->check('ref: garbage is empty', \Anchor_FM_Vimeo::parse_ref('not a video'), ['id' => '', 'hash' => '']);
	}

	/** Anchor_FM_Vimeo::split_refs (bulk paste) */
	public function test_anchor_fm_vimeo_split_refs_bulk_paste() {
		$this->check('split: commas', \Anchor_FM_Vimeo::split_refs('123456789, 987654321'), ['123456789', '987654321']);
		$this->check('split: newlines', \Anchor_FM_Vimeo::split_refs("123456789\n987654321"), ['123456789', '987654321']);
		$this->check('split: mixed + blanks', \Anchor_FM_Vimeo::split_refs("123456789,\n\n 987654321 ,"), ['123456789', '987654321']);
		$this->check('split: empty', \Anchor_FM_Vimeo::split_refs('   '), []);
		$this->check('split: single', \Anchor_FM_Vimeo::split_refs('https://vimeo.com/123456789'), ['https://vimeo.com/123456789']);
	}

	/** Anchor_FM_Vimeo::video_url */
	public function test_anchor_fm_vimeo_video_url() {
		$this->check('video_url: public', \Anchor_FM_Vimeo::video_url('123456789'), 'https://vimeo.com/123456789');
		$this->check('video_url: unlisted appends hash', \Anchor_FM_Vimeo::video_url('123456789', 'abcdef0123'), 'https://vimeo.com/123456789/abcdef0123');
		$this->check('video_url: no id', \Anchor_FM_Vimeo::video_url(''), '');
	}

	/** Anchor_FM_Vimeo::fetch_meta */
	public function test_anchor_fm_vimeo_fetch_meta() {
		// Body captured from the live oEmbed API for vimeo.com/22439234.
		$real_body = '{"type":"video","version":"1.0","provider_name":"Vimeo","title":"The Mountain","author_name":"TSO Photography","duration":185,"thumbnail_url":"https:\/\/i.vimeocdn.com\/video\/145027281-cf3e3e047a52e2210b26bbcf42fcde909a80a7dd023a757b95af01936d065ec0-d_295x166?region=us","video_id":22439234}';

		$this->stub_http(['response' => ['code' => 200], 'body' => $real_body]);
		$this->check('fetch_meta: reads title', \Anchor_FM_Vimeo::fetch_meta('22439234')['title'], 'The Mountain');
		$this->check('fetch_meta: reads thumbnail', \Anchor_FM_Vimeo::fetch_meta('22439234')['thumbnail_url'], 'https://i.vimeocdn.com/video/145027281-cf3e3e047a52e2210b26bbcf42fcde909a80a7dd023a757b95af01936d065ec0-d_295x166?region=us');
		\Anchor_FM_Vimeo::fetch_meta('123456789', 'abcdef0123');
		$this->check('fetch_meta: hash reaches the endpoint', strpos($this->last_url, rawurlencode('https://vimeo.com/123456789/abcdef0123')) !== false, true);

		// 404 is ambiguous on the live API: it answers 404 both for a missing video and
		// for a real one whose owner blocks embedding. The message must not claim the
		// video doesn't exist.
		$this->stub_http(['response' => ['code' => 404], 'body' => '404 Not Found']);
		$e404 = \Anchor_FM_Vimeo::fetch_meta('76979871');
		$this->check('fetch_meta: 404 is a WP_Error', is_wp_error($e404), true);
		$this->check('fetch_meta: 404 does not claim deletion', strpos($e404->get_error_message(), 'blocks embedding') !== false, true);

		$this->stub_http(['response' => ['code' => 403], 'body' => '']);
		$this->check('fetch_meta: 403 mentions the hash', strpos(\Anchor_FM_Vimeo::fetch_meta('1')->get_error_message(), 'hash') !== false, true);

		$this->stub_http(['response' => ['code' => 500], 'body' => '']);
		$this->check('fetch_meta: 500 is an error', is_wp_error(\Anchor_FM_Vimeo::fetch_meta('1')), true);

		$this->stub_http(['response' => ['code' => 200], 'body' => 'not json']);
		$this->check('fetch_meta: junk body is an error', is_wp_error(\Anchor_FM_Vimeo::fetch_meta('1')), true);

		$this->stub_http(['response' => ['code' => 200], 'body' => '{"title":"No thumb here"}']);
		$this->check('fetch_meta: missing thumbnail is not fatal', \Anchor_FM_Vimeo::fetch_meta('1')['thumbnail_url'], '');

		$this->stub_http(null);
		$this->check('fetch_meta: transport failure surfaces', is_wp_error(\Anchor_FM_Vimeo::fetch_meta('1')), true);
		$this->check('fetch_meta: empty id never calls out', is_wp_error(\Anchor_FM_Vimeo::fetch_meta('')), true);

		$this->check('fallback_title', \Anchor_FM_Vimeo::fallback_title('123456789'), 'Vimeo video 123456789');

	}

	/** Anchor_FM_Watch_Math::furthest_point */
	public function test_watch_math_furthest_point() {
		// furthest_point($prev_furthest, $point_seconds, $duration_seconds)
		// The furthest position ever reached. No longer drives percent — that comes
		// from \Anchor_FM_Coverage — but is still recorded.
		$this->check('furthest first beat', \Anchor_FM_Watch_Math::furthest_point(0, 30, 100), 30);
		$this->check('furthest keeps max when user scrubs back', \Anchor_FM_Watch_Math::furthest_point(30, 10, 100), 30);
		$this->check('furthest advances', \Anchor_FM_Watch_Math::furthest_point(30, 90, 100), 90);
		$this->check('furthest clamped to duration', \Anchor_FM_Watch_Math::furthest_point(0, 500, 100), 100);
		$this->check('furthest unknown duration keeps point', \Anchor_FM_Watch_Math::furthest_point(0, 500, 0), 500);
		$this->check('furthest negative point ignored', \Anchor_FM_Watch_Math::furthest_point(20, -5, 100), 20);
		$this->check('furthest negative previous treated as zero', \Anchor_FM_Watch_Math::furthest_point(-3, 10, 100), 10);
		$this->check('furthest never goes backwards when duration shrinks', \Anchor_FM_Watch_Math::furthest_point(500, 20, 100), 500);
	}

	/** Anchor_FM_User_Import::sanitize_username */
	public function test_anchor_fm_user_import_sanitize_username() {
		$this->check('sanitize lowercases', \Anchor_FM_User_Import::sanitize_username('J.Smith'), 'j.smith');
		$this->check('sanitize strips spaces/symbols', \Anchor_FM_User_Import::sanitize_username('Mary O\'Brien!'), 'maryobrien');
		$this->check('sanitize keeps dot/dash/underscore', \Anchor_FM_User_Import::sanitize_username('a.b-c_d'), 'a.b-c_d');
	}

	/** Anchor_FM_User_Import::derive_username */
	public function test_anchor_fm_user_import_derive_username() {
		$this->check('derive basic', \Anchor_FM_User_Import::derive_username('Jane', 'Smith'), 'j.smith');
		$this->check('derive trims', \Anchor_FM_User_Import::derive_username('  Bob ', ' Lee '), 'b.lee');
		$this->check('derive lowercases', \Anchor_FM_User_Import::derive_username('AL', 'CAPONE'), 'a.capone');
	}

	/** Anchor_FM_User_Import::make_unique */
	public function test_anchor_fm_user_import_make_unique() {
		$none = function ($n) { return false; };
		$this->check('unique passthrough', \Anchor_FM_User_Import::make_unique('j.smith', $none), 'j.smith');

		$taken = ['j.smith' => true, 'j.smith2' => true];
		$exists = function ($n) use ($taken) { return isset($taken[$n]); };
		$this->check('unique suffixes past taken', \Anchor_FM_User_Import::make_unique('j.smith', $exists), 'j.smith3');
	}

	/** Anchor_FM_User_Import::is_header_row */
	public function test_anchor_fm_user_import_is_header_row() {
		$this->check('header detected by email', \Anchor_FM_User_Import::is_header_row(['username','first name','last name','email']), true);
		$this->check('non-header not detected', \Anchor_FM_User_Import::is_header_row(['jsmith','Jane','Smith','jane@x.com']), false);
		$this->check('single token not header', \Anchor_FM_User_Import::is_header_row(['login','Jane','Smith','jane@x.com']), false);
		$this->check('one header key not enough', \Anchor_FM_User_Import::is_header_row(['Last','Bob','Lee','bob@x.com']), false);
		$this->check('two distinct header keys is header', \Anchor_FM_User_Import::is_header_row(['email','first name']), true);
	}

	/** Anchor_FM_User_Import::parse (positional, no header) */
	public function test_user_import_parse_header_blank_lines_and_password() {
		$p = \Anchor_FM_User_Import::parse("jsmith,Jane,Smith,jane@x.com\n,Bob,Lee,bob@x.com\n");
		$this->check('positional no header flag', $p['header_detected'], false);
		$this->check('positional row count', count($p['rows']), 2);
		$this->check('positional username', $p['rows'][0]['username'], 'jsmith');
		$this->check('positional first', $p['rows'][0]['first_name'], 'Jane');
		$this->check('positional email', $p['rows'][0]['email'], 'jane@x.com');
		$this->check('positional blank username kept', $p['rows'][1]['username'], '');
		$this->check('positional line number', $p['rows'][1]['line'], 2);
		$h = \Anchor_FM_User_Import::parse("email,first name,last name\njane@x.com,Jane,Smith\n");
		$this->check('header detected flag', $h['header_detected'], true);
		$this->check('header maps email', $h['rows'][0]['email'], 'jane@x.com');
		$this->check('header maps first', $h['rows'][0]['first_name'], 'Jane');
		$this->check('header missing username empty', $h['rows'][0]['username'], '');
		$this->check('header data line number', $h['rows'][0]['line'], 2);
		$b = \Anchor_FM_User_Import::parse("jsmith,Jane,Smith,jane@x.com\n\n   \n,Bob,Lee,bob@x.com\n");
		$this->check('blank lines skipped', count($b['rows']), 2);
		$this->check('4-column file has empty password', $p['rows'][0]['password'], '');
		$pp = \Anchor_FM_User_Import::parse("jsmith,Jane,Smith,jane@x.com,Secret12345\n");
		$this->check('5th positional column is password', $pp['rows'][0]['password'], 'Secret12345');
		$ph = \Anchor_FM_User_Import::parse("email,first name,last name,password\njane@x.com,Jane,Smith,Secret12345\n");
		$this->check('password header mapped', $ph['rows'][0]['password'], 'Secret12345');
		$this->check('pwd header alias', \Anchor_FM_User_Import::parse("email,pwd\njane@x.com,Secret12345\n")['rows'][0]['password'], 'Secret12345');
		$this->check('header without password column', $h['rows'][0]['password'], '');
	}

	/** Anchor_FM_User_Import::normalize_email */
	public function test_anchor_fm_user_import_normalize_email() {
		$this->check('normalize email', \Anchor_FM_User_Import::normalize_email('  Jane@X.COM '), 'jane@x.com');
	}

	/** Anchor_FM_User_Import::validate */
	public function test_anchor_fm_user_import_validate() {
		$ok = \Anchor_FM_User_Import::validate(['first_name'=>'Jane','last_name'=>'Smith','email'=>'jane@x.com','username'=>'']);
		$this->check('valid row ok', $ok['ok'], true);
		$this->check('valid row no error', $ok['error'], '');

		$bad_email = \Anchor_FM_User_Import::validate(['first_name'=>'Jane','last_name'=>'Smith','email'=>'not-an-email','username'=>'']);
		$this->check('bad email not ok', $bad_email['ok'], false);
		$this->check('bad email message', $bad_email['error'], 'Invalid or missing email');

		$no_first = \Anchor_FM_User_Import::validate(['first_name'=>'','last_name'=>'Smith','email'=>'jane@x.com','username'=>'']);
		$this->check('missing first not ok', $no_first['ok'], false);
		$this->check('missing first message', $no_first['error'], 'Missing first name');

		$no_last = \Anchor_FM_User_Import::validate(['first_name'=>'Jane','last_name'=>'','email'=>'jane@x.com','username'=>'']);
		$this->check('missing last not ok', $no_last['ok'], false);
		$this->check('missing last message', $no_last['error'], 'Missing last name');
	}

	/** Anchor_FM_Copy_Namer::split_extension */
	public function test_anchor_fm_copy_namer_split_extension() {
		$this->check('split pdf base', \Anchor_FM_Copy_Namer::split_extension('report.pdf')[0], 'report');
		$this->check('split pdf ext', \Anchor_FM_Copy_Namer::split_extension('report.pdf')[1], '.pdf');
		$this->check('split no ext folder', \Anchor_FM_Copy_Namer::split_extension('My Folder')[1], '');
		$this->check('split dotfile no ext', \Anchor_FM_Copy_Namer::split_extension('.htaccess')[1], '');
		$this->check('split double ext base', \Anchor_FM_Copy_Namer::split_extension('archive.tar.gz')[0], 'archive.tar');
		$this->check('split double ext ext', \Anchor_FM_Copy_Namer::split_extension('archive.tar.gz')[1], '.gz');
	}

	/** Anchor_FM_Copy_Namer::add_copy_suffix */
	public function test_anchor_fm_copy_namer_add_copy_suffix() {
		$this->check('suffix first', \Anchor_FM_Copy_Namer::add_copy_suffix('Report'), 'Report (copy)');
		$this->check('suffix second', \Anchor_FM_Copy_Namer::add_copy_suffix('Report (copy)'), 'Report (copy 2)');
		$this->check('suffix third', \Anchor_FM_Copy_Namer::add_copy_suffix('Report (copy 2)'), 'Report (copy 3)');
	}

	/** Anchor_FM_Copy_Namer::next_copy_name */
	public function test_anchor_fm_copy_namer_next_copy_name() {
		$this->check('next file keeps ext', \Anchor_FM_Copy_Namer::next_copy_name('report.pdf', true), 'report (copy).pdf');
		$this->check('next folder no ext', \Anchor_FM_Copy_Namer::next_copy_name('My Folder', false), 'My Folder (copy)');
	}

	/** Anchor_FM_Copy_Namer::resolve_unique */
	public function test_anchor_fm_copy_namer_resolve_unique() {
		$this->check('resolve no collision unchanged', \Anchor_FM_Copy_Namer::resolve_unique('report.pdf', ['other.pdf'], true, false), 'report.pdf');
		$this->check('resolve forced duplicate', \Anchor_FM_Copy_Namer::resolve_unique('report.pdf', [], true, true), 'report (copy).pdf');
		$this->check('resolve collide bumps', \Anchor_FM_Copy_Namer::resolve_unique('report.pdf', ['report (copy).pdf'], true, true), 'report (copy 2).pdf');
		$this->check('resolve case-insensitive collision', \Anchor_FM_Copy_Namer::resolve_unique('Report.PDF', ['report.pdf'], true, false), 'Report (copy).PDF');
		$this->check('resolve folder forced', \Anchor_FM_Copy_Namer::resolve_unique('Docs', ['Docs (copy)'], false, true), 'Docs (copy 2)');
	}

	/** Anchor_FM_Range::parse */
	public function test_anchor_fm_range_parse() {
		// A 5000-byte file for every case below unless stated otherwise.
		$this->check('range absent', \Anchor_FM_Range::parse('', 5000), null);
		$this->check('range not bytes unit', \Anchor_FM_Range::parse('items=0-99', 5000), null);
		$this->check('range garbage', \Anchor_FM_Range::parse('bytes=abc', 5000), null);
		$this->check('range no dash', \Anchor_FM_Range::parse('bytes=100', 5000), null);
		$this->check('range multi rejected', \Anchor_FM_Range::parse('bytes=0-99,200-299', 5000), null);
		$this->check('range reversed rejected', \Anchor_FM_Range::parse('bytes=900-100', 5000), null);
		$this->check('range zero-byte file', \Anchor_FM_Range::parse('bytes=0-', 0), null);

		$this->check('range open-ended from zero', \Anchor_FM_Range::parse('bytes=0-', 5000),
		    ['satisfiable' => true, 'start' => 0, 'end' => 4999]);
		$this->check('range open-ended from offset', \Anchor_FM_Range::parse('bytes=1000-', 5000),
		    ['satisfiable' => true, 'start' => 1000, 'end' => 4999]);
		$this->check('range explicit window', \Anchor_FM_Range::parse('bytes=500-999', 5000),
		    ['satisfiable' => true, 'start' => 500, 'end' => 999]);
		$this->check('range end clamped to EOF', \Anchor_FM_Range::parse('bytes=1000-99999', 5000),
		    ['satisfiable' => true, 'start' => 1000, 'end' => 4999]);
		$this->check('range single byte', \Anchor_FM_Range::parse('bytes=0-0', 5000),
		    ['satisfiable' => true, 'start' => 0, 'end' => 0]);
		$this->check('range last byte', \Anchor_FM_Range::parse('bytes=4999-', 5000),
		    ['satisfiable' => true, 'start' => 4999, 'end' => 4999]);
		$this->check('range case-insensitive unit', \Anchor_FM_Range::parse('BYTES=0-99', 5000),
		    ['satisfiable' => true, 'start' => 0, 'end' => 99]);
		$this->check('range tolerates whitespace', \Anchor_FM_Range::parse('bytes= 500 - 999 ', 5000),
		    ['satisfiable' => true, 'start' => 500, 'end' => 999]);

		// Suffix ranges: "the last N bytes". Players use these to read the container
		// index (e.g. an MP4 moov atom written at the end of the file).
		$this->check('range suffix', \Anchor_FM_Range::parse('bytes=-500', 5000),
		    ['satisfiable' => true, 'start' => 4500, 'end' => 4999]);
		$this->check('range suffix larger than file', \Anchor_FM_Range::parse('bytes=-99999', 5000),
		    ['satisfiable' => true, 'start' => 0, 'end' => 4999]);
		$this->check('range suffix zero unsatisfiable', \Anchor_FM_Range::parse('bytes=-0', 5000),
		    ['satisfiable' => false]);

		$this->check('range start past EOF', \Anchor_FM_Range::parse('bytes=5000-', 5000),
		    ['satisfiable' => false]);
		$this->check('range start well past EOF', \Anchor_FM_Range::parse('bytes=99999-100000', 5000),
		    ['satisfiable' => false]);
	}

	/** Anchor_FM_Watch_Math::resume_point */
	public function test_anchor_fm_watch_math_resume_point() {
		$this->check('resume normal midpoint', \Anchor_FM_Watch_Math::resume_point(300, 1200, false), 300);
		$this->check('resume below minimum', \Anchor_FM_Watch_Math::resume_point(9, 1200, false), 0);
		$this->check('resume at minimum kept', \Anchor_FM_Watch_Math::resume_point(10, 1200, false), 10);
		$this->check('resume ended clears', \Anchor_FM_Watch_Math::resume_point(300, 1200, true), 0);
		$this->check('resume within end pad', \Anchor_FM_Watch_Math::resume_point(1190, 1200, false), 0);
		$this->check('resume exactly at end pad', \Anchor_FM_Watch_Math::resume_point(1185, 1200, false), 0);
		$this->check('resume just before end pad', \Anchor_FM_Watch_Math::resume_point(1184, 1200, false), 1184);
		$this->check('resume past duration clamps then pads to zero', \Anchor_FM_Watch_Math::resume_point(9999, 1200, false), 0);
		$this->check('resume negative point', \Anchor_FM_Watch_Math::resume_point(-5, 1200, false), 0);
		// Duration 0 means the player never reported a length. The end-pad rule cannot
		// apply, so we keep whatever position we have.
		$this->check('resume unknown duration keeps point', \Anchor_FM_Watch_Math::resume_point(300, 0, false), 300);
		$this->check('resume unknown duration below minimum', \Anchor_FM_Watch_Math::resume_point(4, 0, false), 0);
		// A video shorter than the end pad is always "finished" wherever you stop.
		$this->check('resume very short video', \Anchor_FM_Watch_Math::resume_point(11, 12, false), 0);
		// Unknown duration cannot clamp against length, so a hostile/buggy client
		// could otherwise post a value too large for the INT(10) UNSIGNED column.
		$this->check('resume unknown duration clamps at 24h', \Anchor_FM_Watch_Math::resume_point(999999999, 0, false), 86400);
		$this->check('resume unknown duration at clamp boundary', \Anchor_FM_Watch_Math::resume_point(86400, 0, false), 86400);
		$this->check('resume unknown duration just under clamp', \Anchor_FM_Watch_Math::resume_point(86399, 0, false), 86399);
		// The clamp applies after the duration clamp, so a long-but-known duration
		// still resumes normally below 24h.
		$this->check('resume clamp does not disturb known duration', \Anchor_FM_Watch_Math::resume_point(4000, 7200, false), 4000);
	}

	/** Anchor_FM_Watch_Math::is_resume_stale */
	public function test_anchor_fm_watch_math_is_resume_stale() {
		$afm_now = strtotime('2026-08-17 12:00:00');
		$this->check('stale 29 days is fresh', \Anchor_FM_Watch_Math::is_resume_stale('2026-07-19 12:00:00', $afm_now), false);
		$this->check('stale 1 day is fresh', \Anchor_FM_Watch_Math::is_resume_stale('2026-08-16 12:00:00', $afm_now), false);
		// Boundary: exactly 30 days old is still returned; 30 days plus a second is not.
		$this->check('stale exactly 30 days is fresh', \Anchor_FM_Watch_Math::is_resume_stale('2026-07-18 12:00:00', $afm_now), false);
		$this->check('stale 30 days plus a second', \Anchor_FM_Watch_Math::is_resume_stale('2026-07-18 11:59:59', $afm_now), true);
		$this->check('stale 31 days', \Anchor_FM_Watch_Math::is_resume_stale('2026-07-17 12:00:00', $afm_now), true);
		// Fail closed on anything we cannot read.
		$this->check('stale empty string', \Anchor_FM_Watch_Math::is_resume_stale('', $afm_now), true);
		$this->check('stale zero date', \Anchor_FM_Watch_Math::is_resume_stale('0000-00-00 00:00:00', $afm_now), true);
		$this->check('stale garbage', \Anchor_FM_Watch_Math::is_resume_stale('not a date', $afm_now), true);
		$this->check('stale custom ttl', \Anchor_FM_Watch_Math::is_resume_stale('2026-08-10 12:00:00', $afm_now, 5), true);
	}

	/** Anchor_FM_Coverage */
	public function test_anchor_fm_coverage() {
		// Ranges are HALF-OPEN [from, to): mark(0,5) covers seconds 0,1,2,3,4.

		// The scenario this whole feature exists for, stated literally:
		// five seconds at the intro, five at the end of a 120s video, = 10 seconds.
		$cov = \Anchor_FM_Coverage::mark('', 0, 5);
		$cov = \Anchor_FM_Coverage::mark($cov, 115, 120);
		$this->check('coverage two disjoint stretches', \Anchor_FM_Coverage::count_set($cov), 10);
		$cov = \Anchor_FM_Coverage::mark($cov, 0, 5); // re-watch the intro
		$this->check('coverage rewatch adds nothing', \Anchor_FM_Coverage::count_set($cov), 10);
		$this->check('coverage percent of 120s video', \Anchor_FM_Coverage::percent(10, 120), 8);

		$this->check('coverage empty bitset', \Anchor_FM_Coverage::count_set(''), 0);
		$this->check('coverage single range', \Anchor_FM_Coverage::count_set(\Anchor_FM_Coverage::mark('', 0, 5)), 5);
		$this->check('coverage adjacent ranges do not double count',
		    \Anchor_FM_Coverage::count_set(\Anchor_FM_Coverage::mark(\Anchor_FM_Coverage::mark('', 0, 5), 5, 10)), 10);
		$this->check('coverage overlapping ranges merge',
		    \Anchor_FM_Coverage::count_set(\Anchor_FM_Coverage::mark(\Anchor_FM_Coverage::mark('', 0, 10), 5, 15)), 15);
		$this->check('coverage identical range twice',
		    \Anchor_FM_Coverage::count_set(\Anchor_FM_Coverage::mark(\Anchor_FM_Coverage::mark('', 3, 9), 3, 9)), 6);
		$this->check('coverage reversed range rejected', \Anchor_FM_Coverage::count_set(\Anchor_FM_Coverage::mark('', 9, 3)), 0);
		$this->check('coverage zero-length range rejected', \Anchor_FM_Coverage::count_set(\Anchor_FM_Coverage::mark('', 5, 5)), 0);
		$this->check('coverage negative from clamps to zero', \Anchor_FM_Coverage::count_set(\Anchor_FM_Coverage::mark('', -10, 5)), 5);
		$this->check('coverage byte boundary 7 to 9', \Anchor_FM_Coverage::count_set(\Anchor_FM_Coverage::mark('', 7, 9)), 2);
		$this->check('coverage spans many bytes', \Anchor_FM_Coverage::count_set(\Anchor_FM_Coverage::mark('', 0, 100)), 100);
		$this->check('coverage cap truncates tail', \Anchor_FM_Coverage::count_set(\Anchor_FM_Coverage::mark('', 86395, 90000)), 5);
		$this->check('coverage entirely beyond cap', \Anchor_FM_Coverage::count_set(\Anchor_FM_Coverage::mark('', 90000, 90010)), 0);

		// mark_segments must survive whatever a client sends it.
		$this->check('coverage segments applied',
		    \Anchor_FM_Coverage::count_set(\Anchor_FM_Coverage::mark_segments('', [[0,5],[10,15]])), 10);
		$this->check('coverage segments empty list',
		    \Anchor_FM_Coverage::count_set(\Anchor_FM_Coverage::mark_segments('', [])), 0);
		$this->check('coverage segments skip malformed',
		    \Anchor_FM_Coverage::count_set(\Anchor_FM_Coverage::mark_segments('', [[0,5], 'x', [3], null, [10,15]])), 10);
		$this->check('coverage segments non-array input',
		    \Anchor_FM_Coverage::count_set(\Anchor_FM_Coverage::mark_segments('', 'nope')), 0);
		$this->check('coverage segments skip object shaped',
		    \Anchor_FM_Coverage::count_set(\Anchor_FM_Coverage::mark_segments('', [['from'=>0,'to'=>5],[10,15]])), 5);
		$this->check('coverage segments skip string keys only',
		    \Anchor_FM_Coverage::count_set(\Anchor_FM_Coverage::mark_segments('', [['a'=>1,'b'=>2]])), 0);

		// percent
		$this->check('percent zero duration', \Anchor_FM_Coverage::percent(50, 0), 0);
		$this->check('percent half', \Anchor_FM_Coverage::percent(60, 120), 50);
		$this->check('percent floors', \Anchor_FM_Coverage::percent(59, 120), 49);
		$this->check('percent exact hundred', \Anchor_FM_Coverage::percent(120, 120), 100);
		$this->check('percent clamps above hundred', \Anchor_FM_Coverage::percent(200, 120), 100);
		$this->check('percent zero coverage', \Anchor_FM_Coverage::percent(0, 120), 0);
		$this->check('percent negative coverage', \Anchor_FM_Coverage::percent(-5, 120), 0);
	}

	/** Anchor_FM_Media_Progress::clamp_segments */
	public function test_anchor_fm_media_progress_clamp_segments() {

		$this->check('clamp trims a segment past the duration',
		    \Anchor_FM_Media_Progress::clamp_segments([[0, 86400]], 120), [[0, 120]]);
		$this->check('clamp leaves an in-range segment alone',
		    \Anchor_FM_Media_Progress::clamp_segments([[10, 40]], 120), [[10, 40]]);
		$this->check('clamp leaves a segment ending exactly at the duration',
		    \Anchor_FM_Media_Progress::clamp_segments([[0, 120]], 120), [[0, 120]]);
		$this->check('clamp trims every offending segment',
		    \Anchor_FM_Media_Progress::clamp_segments([[0, 500], [10, 20], [30, 999]], 120),
		    [[0, 120], [10, 20], [30, 120]]);
		$this->check('clamp with unknown duration is a no-op',
		    \Anchor_FM_Media_Progress::clamp_segments([[0, 86400]], 0), [[0, 86400]]);
		$this->check('clamp passes malformed entries through untouched',
		    \Anchor_FM_Media_Progress::clamp_segments([['a', 'b'], [0, 500]], 120), [['a', 'b'], [0, 120]]);
		$this->check('clamp on a non-array returns it unchanged',
		    \Anchor_FM_Media_Progress::clamp_segments('nope', 120), 'nope');
		$this->check('clamped segment marks only the real duration',
		    \Anchor_FM_Coverage::count_set(\Anchor_FM_Coverage::mark_segments('',
		        \Anchor_FM_Media_Progress::clamp_segments([[0, 86400]], 120))), 120);
	}

	/** Anchor_FM_Permission_Policy */
	public function test_permission_policy_evaluate_without_role_and_validate_for_write() {

		$policy = [
		    'operator' => 'any',
		    'rules' => [
		        [
		            'operator' => 'all',
		            'conditions' => [
		                ['type' => 'role', 'role' => 'member'],
		                ['type' => 'date', 'start' => '2026-08-01', 'end' => '2026-08-31'],
		            ],
		        ],
		        [
		            'operator' => 'all',
		            'conditions' => [
		                ['type' => 'role', 'role' => 'staff'],
		                ['type' => 'date', 'start' => '2026-09-01', 'end' => '2026-09-30'],
		            ],
		        ],
		    ],
		];
		$this->check('permission policy role and date match',
		    \Anchor_FM_Permission_Policy::evaluate($policy, ['userId' => 7, 'roles' => ['member']], '2026-08-15'), true);
		$this->check('permission policy date blocks matching role',
		    \Anchor_FM_Permission_Policy::evaluate($policy, ['userId' => 7, 'roles' => ['member']], '2026-09-15'), false);
		$this->check('permission policy alternate role rule matches',
		    \Anchor_FM_Permission_Policy::evaluate($policy, ['userId' => 7, 'roles' => ['staff']], '2026-09-15'), true);

		$all_policy = [
		    'operator' => 'all',
		    'rules' => [
		        ['operator' => 'all', 'conditions' => [['type' => 'role', 'role' => 'member']]],
		        ['operator' => 'all', 'conditions' => [['type' => 'user', 'userId' => '7']]],
		    ],
		];
		$this->check('permission policy top-level all passes',
		    \Anchor_FM_Permission_Policy::evaluate($all_policy, ['userId' => 7, 'roles' => ['member']], '2026-08-15'), true);
		$this->check('permission policy top-level all fails one rule',
		    \Anchor_FM_Permission_Policy::evaluate($all_policy, ['userId' => 8, 'roles' => ['member']], '2026-08-15'), false);
		$role_and_date = ['operator' => 'any', 'rules' => [
		    ['operator' => 'all', 'conditions' => [['type' => 'role', 'role' => 'gone'], ['type' => 'date', 'start' => '2026-01-01', 'end' => '2026-12-31']]],
		    ['operator' => 'all', 'conditions' => [['type' => 'user', 'userId' => '7']]],
		]];
		$stripped = \Anchor_FM_Permission_Policy::without_role($role_and_date, 'gone');
		$this->check('without_role: an all-rule needing the role is dropped whole (never widens to the date alone)',
		    $stripped['rules'], [['operator' => 'all', 'conditions' => [['type' => 'user', 'userId' => '7']]]]);
		$this->check('without_role: nobody else gains access in the date window',
		    \Anchor_FM_Permission_Policy::evaluate($stripped, ['userId' => 8, 'roles' => []], '2026-06-01'), false);

		$role_or_user = ['operator' => 'any', 'rules' => [
		    ['operator' => 'any', 'conditions' => [['type' => 'role', 'role' => 'gone'], ['type' => 'user', 'userId' => '7']]],
		]];
		$this->check('without_role: an any-rule just loses the condition',
		    \Anchor_FM_Permission_Policy::without_role($role_or_user, 'gone')['rules'],
		    [['operator' => 'any', 'conditions' => [['type' => 'user', 'userId' => '7']]]]);

		$only_role = ['operator' => 'any', 'rules' => [['operator' => 'any', 'conditions' => [['type' => 'role', 'role' => 'gone']]]]];
		$this->check('without_role: a rule left with no conditions is dropped',
		    \Anchor_FM_Permission_Policy::without_role($only_role, 'gone')['rules'], []);

		$all_top = ['operator' => 'all', 'rules' => [
		    ['operator' => 'all', 'conditions' => [['type' => 'role', 'role' => 'gone']]],
		    ['operator' => 'all', 'conditions' => [['type' => 'date', 'start' => '2026-01-01']]],
		]];
		$this->check('without_role: top-level all with a now-false rule matches nobody (empty policy)',
		    \Anchor_FM_Permission_Policy::without_role($all_top, 'gone')['rules'], []);

		$this->check('without_role: other roles untouched',
		    \Anchor_FM_Permission_Policy::without_role($policy, 'gone'), \Anchor_FM_Permission_Policy::normalize($policy));

		$any_rule_policy = [
		    'operator' => 'any',
		    'rules' => [
		        [
		            'operator' => 'any',
		            'conditions' => [
		                ['type' => 'role', 'role' => 'member'],
		                ['type' => 'user', 'userId' => '9'],
		            ],
		        ],
		    ],
		];
		$this->check('permission policy any condition passes on user',
		    \Anchor_FM_Permission_Policy::evaluate($any_rule_policy, ['userId' => 9, 'roles' => []], '2026-08-15'), true);

		$normalized_policy = \Anchor_FM_Permission_Policy::normalize([
		    'operator' => 'all',
		    'rules' => [
		        [
		            'operator' => 'all',
		            'conditions' => [
		                ['type' => 'role', 'role' => 'administrator'],
		                ['type' => 'role', 'role' => 'Customer+Role'],
		                ['type' => 'date', 'start' => '2026-10-31', 'end' => '2026-10-01'],
		            ],
		        ],
		    ],
		]);
		$this->check('permission policy normalize keeps every condition (admin → never) and swaps dates', $normalized_policy, [
		    'operator' => 'all',
		    'rules' => [
		        [
		            'operator' => 'all',
		            'conditions' => [
		                ['type' => 'never'],
		                ['type' => 'role', 'role' => 'customerrole'],
		                ['type' => 'date', 'start' => '2026-10-01', 'end' => '2026-10-31'],
		            ],
		        ],
		    ],
		]);

		// AFM-02: reading a policy must never widen it. A role that disappeared
		// outside the plugin stays a role condition nobody satisfies.
		$premium_2026 = ['operator' => 'any', 'rules' => [
		    ['operator' => 'all', 'conditions' => [
		        ['type' => 'role', 'role' => 'premium'],
		        ['type' => 'date', 'start' => '2026-01-01', 'end' => '2026-12-31'],
		    ]],
		]];
		$this->check('AFM-02: outsider denied while role exists',
		    \Anchor_FM_Permission_Policy::evaluate($premium_2026, ['userId' => 5, 'roles' => ['subscriber']], '2026-06-01'), false);
		$this->check('AFM-02: outsider still denied after the role vanishes (read path keeps the condition)',
		    \Anchor_FM_Permission_Policy::evaluate(\Anchor_FM_Permission_Policy::normalize($premium_2026), ['userId' => 5, 'roles' => ['subscriber']], '2026-06-01'), false);
		$this->check('AFM-02: malformed conditions become never, not dropped',
		    \Anchor_FM_Permission_Policy::normalize(['rules' => [['operator' => 'all', 'conditions' => [
		        ['type' => 'user', 'userId' => 0], ['type' => 'date'], ['type' => 'bogus'], 'junk', ['type' => 'date', 'start' => '2026-01-01'],
		    ]]]])['rules'][0]['conditions'],
		    [['type' => 'never'], ['type' => 'never'], ['type' => 'never'], ['type' => 'never'], ['type' => 'date', 'start' => '2026-01-01', 'end' => '']]);
		$this->check('AFM-02: an all-rule with a malformed conjunct grants nobody in its date window',
		    \Anchor_FM_Permission_Policy::evaluate(['rules' => [['operator' => 'all', 'conditions' => [
		        ['type' => 'bogus'], ['type' => 'date', 'start' => '2026-01-01'],
		    ]]]], ['userId' => 5, 'roles' => []], '2026-06-01'), false);
		$this->check('AFM-02: a rule with no conditions stays as a false rule (top-level all denies)',
		    \Anchor_FM_Permission_Policy::evaluate(['operator' => 'all', 'rules' => [
		        ['operator' => 'all', 'conditions' => []],
		        ['operator' => 'all', 'conditions' => [['type' => 'user', 'userId' => 5]]],
		    ]], ['userId' => 5, 'roles' => []], '2026-06-01'), false);
		$this->check('AFM-02: a vanished role that comes back works again (nothing was rewritten)',
		    \Anchor_FM_Permission_Policy::evaluate(\Anchor_FM_Permission_Policy::normalize($premium_2026), ['userId' => 5, 'roles' => ['premium']], '2026-06-01'), true);
		$vw = \Anchor_FM_Permission_Policy::validate_for_write($premium_2026, ['subscriber']);
		$this->check('write: unknown role rejected', [$vw['ok'], $vw['error']], [false, 'Rule 1 uses a role that no longer exists (premium). Pick another role or remove that condition.']);
		$this->check('write: valid policy accepted and normalized',
		    \Anchor_FM_Permission_Policy::validate_for_write($premium_2026, ['premium']),
		    ['ok' => true, 'error' => '', 'policy' => \Anchor_FM_Permission_Policy::normalize($premium_2026)]);
		$this->check('write: administrator role rejected',
		    \Anchor_FM_Permission_Policy::validate_for_write(['rules' => [['conditions' => [['type' => 'role', 'role' => 'administrator']]]]], ['administrator'])['ok'], false);
		$this->check('write: unrecognized condition rejected',
		    \Anchor_FM_Permission_Policy::validate_for_write(['rules' => [['conditions' => [['type' => 'never']]]]], [])['error'], 'Rule 1 has a condition that is not recognized. Remove it and save again.');
		$this->check('write: empty date rejected',
		    \Anchor_FM_Permission_Policy::validate_for_write(['rules' => [['conditions' => [['type' => 'date', 'start' => '', 'end' => '']]]]], [])['error'], 'Rule 1: a date range needs a start or end date.');
		$this->check('write: missing user rejected',
		    \Anchor_FM_Permission_Policy::validate_for_write(['rules' => [['conditions' => [['type' => 'user', 'userId' => '']]]]], [])['error'], 'Rule 1: choose a user.');
		$this->check('write: rule with no conditions rejected',
		    \Anchor_FM_Permission_Policy::validate_for_write(['rules' => [['conditions' => []]]], [])['error'], 'Rule 1 has no conditions.');
		$this->check('write: no rules is fine (clears the policy)',
		    \Anchor_FM_Permission_Policy::validate_for_write(['operator' => 'any', 'rules' => []], [])['ok'], true);
		$this->check('write: second rule numbered in the message',
		    \Anchor_FM_Permission_Policy::validate_for_write(['rules' => [
		        ['conditions' => [['type' => 'user', 'userId' => 3]]],
		        ['conditions' => [['type' => 'role', 'role' => 'gone']]],
		    ]], ['subscriber'])['error'], 'Rule 2 uses a role that no longer exists (gone). Pick another role or remove that condition.');
	}

	/** Anchor_FM_Permission_Index */
	public function test_anchor_fm_permission_index() {

		$idx = new \Anchor_FM_Permission_Index();
		$idx->add_folder(1, 0, 0);
		$idx->add_folder(2, 1, 0);
		$idx->add_folder(3, 2, 77);
		$idx->add_permission('folder', 2, 'role', 'centre', 'view');
		$idx->add_permission('folder', 2, 'role', 'centre', 'manage');   // not view: must not surface
		$idx->add_permission('folder', 2, 'user', '42', 'manage');
		$idx->add_permission('file',  9, 'group', 'anything', 'view');   // unknown subject type
		$idx->add_policy('folder', 2, 'view', '{"operator":"all"}');

		$this->check('index: folder ancestry', $idx->folder(3), ['parent' => 2, 'owner' => 77]);
		$this->check('index: unknown folder is null', $idx->folder(999), null);
		$this->check('index: root parent is 0', $idx->folder(1), ['parent' => 0, 'owner' => 0]);

		$this->check('index: user capabilities are unfiltered by capability',
		    $idx->user_capabilities('folder', 2, '42'), ['manage']);
		$this->check('index: user miss returns empty', $idx->user_capabilities('folder', 2, '43'), []);

		// The query being mirrored filters capability = 'view'; a role row granting
		// 'manage' was never returned by it, and must not start being returned now.
		$this->check('index: role lookup keeps the view-only filter',
		    $idx->role_view_capabilities('folder', 2, ['centre']), ['view']);
		$this->check('index: role miss returns empty',
		    $idx->role_view_capabilities('folder', 2, ['subscriber']), []);
		$this->check('index: no roles returns empty',
		    $idx->role_view_capabilities('folder', 2, []), []);
		$this->check('index: unrelated entity returns empty',
		    $idx->role_view_capabilities('folder', 7, ['centre']), []);

		// MySQL compared these keys under a _ci PAD SPACE collation. Matching that is
		// the contract: stricter would revoke access the site currently grants.
		$this->check('index: role match is case-insensitive',
		    $idx->role_view_capabilities('folder', 2, ['CENTRE']), ['view']);
		$this->check('index: role match ignores trailing spaces',
		    $idx->role_view_capabilities('folder', 2, ['centre  ']), ['view']);
		$this->check('index: leading space is NOT ignored',
		    $idx->role_view_capabilities('folder', 2, [' centre']), []);

		$this->check('index: unknown subject types are dropped',
		    $idx->user_capabilities('file', 9, 'anything'), []);
		$this->check('index: policy round-trips', $idx->policy('folder', 2, 'view'), '{"operator":"all"}');
		$this->check('index: policy miss is null', $idx->policy('folder', 2, 'manage'), null);
		$this->check('index: policy miss on other entity is null', $idx->policy('file', 2, 'view'), null);

		// entity_type is part of the key: a file and a folder sharing an id must not
		// bleed into each other, which is the same collision the delete path guards.
		$idx2 = new \Anchor_FM_Permission_Index();
		$idx2->add_permission('folder', 5, 'role', 'centre', 'view');
		$this->check('index: file and folder ids do not collide',
		    $idx2->role_view_capabilities('file', 5, ['centre']), []);
	}

	/** Anchor_FM_User_Admin::validate_password */
	public function test_anchor_fm_user_admin_validate_password() {
		$this->check('pw: 9 chars rejected', \Anchor_FM_User_Admin::validate_password('123456789')['ok'], false);
		$this->check('pw: 10 chars ok', \Anchor_FM_User_Admin::validate_password('1234567890')['ok'], true);
		$this->check('pw: 5 multibyte chars (10 bytes) rejected', \Anchor_FM_User_Admin::validate_password('ééééé')['ok'], false);
		$this->check('pw: 10 multibyte chars ok', \Anchor_FM_User_Admin::validate_password('éééééééééé')['ok'], true);
		$this->check('pw: invalid UTF-8 rejected', \Anchor_FM_User_Admin::validate_password("\xff\xfe\xfd\xfc\xfb\xfa\xf9\xf8\xf7\xf6")['ok'], false);
		$this->check('pw: error text', \Anchor_FM_User_Admin::validate_password('short')['error'], 'Password must be at least 10 characters');
		$this->check('pw: trimmed before length', \Anchor_FM_User_Admin::validate_password('  12345678  ')['ok'], false);
		$this->check('pw: empty rejected', \Anchor_FM_User_Admin::validate_password('')['ok'], false);
	}

	/** Anchor_FM_User_Admin::resolve_password */
	public function test_anchor_fm_user_admin_resolve_password() {
		$this->check('resolve: row wins', \Anchor_FM_User_Admin::resolve_password(' rowpass123 ', 'default1234'), ['source' => 'row', 'password' => 'rowpass123']);
		$this->check('resolve: default when row blank', \Anchor_FM_User_Admin::resolve_password('  ', 'default1234'), ['source' => 'default', 'password' => 'default1234']);
		$this->check('resolve: generate when both blank', \Anchor_FM_User_Admin::resolve_password('', ''), ['source' => 'generate', 'password' => '']);
		$this->check('resolve: null inputs generate', \Anchor_FM_User_Admin::resolve_password(null, null), ['source' => 'generate', 'password' => '']);
	}

	/** Anchor_FM_User_Admin::is_manageable */
	public function test_anchor_fm_user_admin_is_manageable() {
		$this->check('manage: other subscriber ok', \Anchor_FM_User_Admin::is_manageable(['subscriber'], 7, 1), true);
		$this->check('manage: self refused', \Anchor_FM_User_Admin::is_manageable(['subscriber'], 1, 1), false);
		$this->check('manage: administrator refused', \Anchor_FM_User_Admin::is_manageable(['editor', 'administrator'], 7, 1), false);
		$this->check('manage: missing id refused', \Anchor_FM_User_Admin::is_manageable(['subscriber'], 0, 1), false);
	}

	/** Anchor_FM_User_Admin::valid_role */
	public function test_anchor_fm_user_admin_valid_role() {
		$this->check('role: listed ok', \Anchor_FM_User_Admin::valid_role('tmj_patient', ['subscriber', 'tmj_patient']), true);
		$this->check('role: unlisted refused', \Anchor_FM_User_Admin::valid_role('editor', ['subscriber']), false);
		$this->check('role: administrator refused even if listed', \Anchor_FM_User_Admin::valid_role('administrator', ['administrator']), false);
		$this->check('role: empty refused', \Anchor_FM_User_Admin::valid_role('', ['subscriber']), false);
	}

	/** Anchor_FM_User_Admin::valid_roles */
	public function test_anchor_fm_user_admin_valid_roles() {
		$this->check('roles: several ok, order kept', \Anchor_FM_User_Admin::valid_roles(['tmj_patient', 'sleep_patient'], ['subscriber', 'sleep_patient', 'tmj_patient']), ['tmj_patient', 'sleep_patient']);
		$this->check('roles: duplicates collapse', \Anchor_FM_User_Admin::valid_roles(['subscriber', 'subscriber'], ['subscriber']), ['subscriber']);
		$this->check('roles: one unlisted refuses all', \Anchor_FM_User_Admin::valid_roles(['subscriber', 'editor'], ['subscriber']), []);
		$this->check('roles: administrator refuses all', \Anchor_FM_User_Admin::valid_roles(['subscriber', 'administrator'], ['subscriber', 'administrator']), []);
		$this->check('roles: none picked is empty', \Anchor_FM_User_Admin::valid_roles([], ['subscriber']), []);
	}

	/** Anchor_FM_User_Admin role naming */
	public function test_anchor_fm_user_admin_role_naming() {
		$this->check('role name: trims and collapses spaces', \Anchor_FM_User_Admin::normalize_role_name("  TMJ   patient \n"), 'TMJ patient');
		$this->check('role name: capped at 60 chars', strlen(\Anchor_FM_User_Admin::normalize_role_name(str_repeat('a', 80))), 60);
		$this->check('role key: from name', \Anchor_FM_User_Admin::role_key_from_name('TMJ Patient'), 'tmj_patient');
		$this->check('role key: punctuation collapses', \Anchor_FM_User_Admin::role_key_from_name('Sleep / Apnea -- Active!'), 'sleep_apnea_active');
		$this->check('role key: accents dropped, not fatal', \Anchor_FM_User_Admin::role_key_from_name('Crânio'), 'cr_nio');
		$this->check('role key: nothing usable is empty', \Anchor_FM_User_Admin::role_key_from_name('!!!'), '');
		$this->check('role key: capped at 40 chars', strlen(\Anchor_FM_User_Admin::role_key_from_name(str_repeat('ab ', 30))) <= 40, true);
		$this->check('role key: no trailing underscore after cap', substr(\Anchor_FM_User_Admin::role_key_from_name(str_repeat('abcdefghi ', 5)), -1) !== '_', true);
	}

	/** Anchor_FM_User_Admin::is_reserved_role_key */
	public function test_anchor_fm_user_admin_is_reserved_role_key() {
		$this->check('reserved: administrator', \Anchor_FM_User_Admin::is_reserved_role_key('administrator'), true);
		$this->check('reserved: subscriber', \Anchor_FM_User_Admin::is_reserved_role_key('subscriber'), true);
		$this->check('reserved: woo customer', \Anchor_FM_User_Admin::is_reserved_role_key('customer'), true);
		$this->check('reserved: empty', \Anchor_FM_User_Admin::is_reserved_role_key(''), true);
		$this->check('reserved: custom ok', \Anchor_FM_User_Admin::is_reserved_role_key('tmj_patient'), false);
	}

	/** Anchor_FM_User_Admin::is_staff_capabilities (staff stay in wp-admin) */
	public function test_anchor_fm_user_admin_is_staff_capabilities_staff_stay_in_wp_() {
		$this->check('staff: administrator caps', \Anchor_FM_User_Admin::is_staff_capabilities(['manage_options' => true, 'read' => true]), true);
		$this->check('staff: editor/author/contributor (edit_posts)', \Anchor_FM_User_Admin::is_staff_capabilities(['edit_posts' => true, 'read' => true]), true);
		$this->check('staff: shop manager (manage_woocommerce)', \Anchor_FM_User_Admin::is_staff_capabilities(['manage_woocommerce' => true]), true);
		$this->check('staff: can list users', \Anchor_FM_User_Admin::is_staff_capabilities(['list_users' => true]), true);
		$this->check('staff: subscriber is not staff', \Anchor_FM_User_Admin::is_staff_capabilities(['read' => true]), false);
		$this->check('staff: customer is not staff', \Anchor_FM_User_Admin::is_staff_capabilities(['read' => true, 'level_0' => true]), false);
		$this->check('staff: a capability explicitly denied does not count', \Anchor_FM_User_Admin::is_staff_capabilities(['read' => true, 'edit_posts' => false]), false);
	}

	/** Anchor_FM_User_Admin::can_delete_role */
	public function test_anchor_fm_user_admin_can_delete_role() {
		$this->check('delete role: owned and empty ok', \Anchor_FM_User_Admin::can_delete_role('tmj_patient', ['tmj_patient'], 0), ['ok' => true, 'error' => '']);
		$this->check('delete role: not owned refused', \Anchor_FM_User_Admin::can_delete_role('editor', ['tmj_patient'], 0)['ok'], false);
		$this->check('delete role: in use refused', \Anchor_FM_User_Admin::can_delete_role('tmj_patient', ['tmj_patient'], 3), ['ok' => false, 'error' => '3 users still have this role. Move them to another role first.']);
		$this->check('delete role: one user wording', \Anchor_FM_User_Admin::can_delete_role('tmj_patient', ['tmj_patient'], 1)['error'], '1 user still has this role. Move them to another role first.');
	}

	/** Anchor_FM_Upload_Types (AFM-06 narrow exception) */
	public function test_anchor_fm_upload_types_afm_06_narrow_exception() {
		$this->check('types: legacy .doc read as OLE container accepted', \Anchor_FM_Upload_Types::is_known_misdetection('doc', 'application/CDFV2'), true);
		$this->check('types: .m4a read as mp4 video accepted', \Anchor_FM_Upload_Types::is_known_misdetection('M4A', 'video/mp4'), true);
		$this->check('types: .csv read as C source accepted', \Anchor_FM_Upload_Types::is_known_misdetection('csv', 'text/x-c'), true);
		$this->check('types: octet-stream never accepted (proves nothing)', \Anchor_FM_Upload_Types::is_known_misdetection('doc', 'application/octet-stream'), false);
		$this->check('types: text claiming to be a PDF rejected', \Anchor_FM_Upload_Types::is_known_misdetection('pdf', 'text/plain'), false);
		$this->check('types: HTML named .txt rejected', \Anchor_FM_Upload_Types::is_known_misdetection('txt', 'text/html'), false);
		$this->check('types: PHP named .csv rejected', \Anchor_FM_Upload_Types::is_known_misdetection('csv', 'text/x-php'), false);
		$this->check('types: empty detection rejected', \Anchor_FM_Upload_Types::is_known_misdetection('doc', ''), false);
	}
}
