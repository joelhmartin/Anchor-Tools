<?php
declare(strict_types=1);

namespace Anchor\Courses\Rest;

use Anchor\Courses\Domain\QuizAttempt;
use Anchor\Courses\Services\QuizService;
use Anchor\Courses\Support\Clock;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Quiz attempt endpoints (brief 14).
 *
 * A THIN boundary over QuizService (progress.md ruling): every rule about
 * enrolment, availability, allowance, retry delay, timer and grading lives in
 * the service. This class does auth (permission_callback + the ownership
 * check every handler below runs before touching an attempt), input shaping
 * (pulling typed values off the request), and response shaping (payload()).
 *
 * The client may ONLY send answers. Score, pass/fail, attempt number, timing
 * and question order are all decided server-side; anything else in the body
 * (a submitted score, a passed flag) is ignored by construction (rule 4).
 *
 * No response leaving this class may carry a bare `correct`/`correct_ids` key
 * outside of what QuizAttempt::for_learner( $show_correct ) itself decides to
 * include (rule 8): the questions payload comes from
 * QuizService::questions_for_learner() (which is Content\Questions::for_learner()
 * under the hood - answers with no `correct` field at all), and every attempt
 * in a response is for_learner()'s projection, never to_array().
 */
final class QuizController {

	public function __construct( private QuizService $quizzes ) {}

	public function register_routes(): void {
		\register_rest_route(
			Routes::NAMESPACE,
			'/quizzes/(?P<id>\d+)/attempts',
			[
				'methods'             => 'POST',
				// A nonce proves a session, not ownership - but starting an
				// attempt has no prior owner to check against, so require_login
				// is the whole permission story here. can_start() inside
				// start_attempt() is what refuses a non-enrolled/locked learner.
				'permission_callback' => [ Routes::class, 'require_login' ],
				'callback'            => [ $this, 'start' ],
				'args'                => [
					'id'        => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
					'course_id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
				],
			]
		);

		\register_rest_route(
			Routes::NAMESPACE,
			'/quiz-attempts/(?P<id>\d+)',
			[
				'methods'             => 'GET',
				'permission_callback' => [ Routes::class, 'require_login' ],
				'callback'            => [ $this, 'read' ],
				'args'                => [ 'id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ] ],
			]
		);

		\register_rest_route(
			Routes::NAMESPACE,
			'/quiz-attempts/(?P<id>\d+)/answer',
			[
				'methods'             => 'POST',
				'permission_callback' => [ Routes::class, 'require_login' ],
				'callback'            => [ $this, 'answer' ],
				'args'                => [
					'id'          => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
					'question_id' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
					'value'       => [ 'required' => true ],
				],
			]
		);

		\register_rest_route(
			Routes::NAMESPACE,
			'/quiz-attempts/(?P<id>\d+)/submit',
			[
				'methods'             => 'POST',
				'permission_callback' => [ Routes::class, 'require_login' ],
				'callback'            => [ $this, 'submit' ],
				'args'                => [
					'id'      => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
					// object, not array: question_id => value is a map, and a
					// bare JSON array would collapse string keys.
					'answers' => [ 'required' => false, 'type' => 'object', 'default' => [] ],
				],
			]
		);
	}

	public function start( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id = \get_current_user_id();
		$attempt = $this->quizzes->start_attempt(
			$user_id,
			(int) $request['id'],
			(int) $request['course_id']
		);

		if ( \is_wp_error( $attempt ) ) {
			return Routes::error_response( $attempt );
		}

		return new \WP_REST_Response( $this->payload( $attempt, true ), 201 );
	}

	public function read( \WP_REST_Request $request ): \WP_REST_Response {
		$attempt = $this->owned_attempt( (int) $request['id'] );
		if ( $attempt instanceof \WP_REST_Response ) {
			return $attempt;
		}

		// A read is a good moment to notice the window closed while they were
		// away, so the learner sees a truthful status rather than a stale
		// "in progress" that the timer sweep just hasn't reached yet.
		$attempt = $this->quizzes->enforce_timer( $attempt );

		return new \WP_REST_Response( $this->payload( $attempt, $attempt->is_open() ), 200 );
	}

	public function answer( \WP_REST_Request $request ): \WP_REST_Response {
		$attempt = $this->owned_attempt( (int) $request['id'] );
		if ( $attempt instanceof \WP_REST_Response ) {
			return $attempt;
		}

		$saved = $this->quizzes->save_answer(
			$attempt->id,
			(string) $request['question_id'],
			$request['value'],
			// Defence in depth (Task 25 review ruling): owned_attempt() above
			// already refused a stranger via owns_attempt(), and this repeats
			// the same fact at the service boundary so save_answer() is safe
			// to call directly from anywhere else that forgets to check first.
			\get_current_user_id()
		);

		if ( \is_wp_error( $saved ) ) {
			return Routes::error_response( $saved );
		}

		return new \WP_REST_Response( [ 'saved' => true ], 200 );
	}

	public function submit( \WP_REST_Request $request ): \WP_REST_Response {
		$attempt = $this->owned_attempt( (int) $request['id'] );
		if ( $attempt instanceof \WP_REST_Response ) {
			return $attempt;
		}

		// ONLY `answers` is read from the body. A client-sent score or passed
		// flag is ignored by construction (rule 4).
		$graded = $this->quizzes->submit(
			$attempt->id,
			(array) $request['answers'],
			\get_current_user_id() // Defence in depth - see answer() above.
		);

		if ( \is_wp_error( $graded ) ) {
			return Routes::error_response( $graded );
		}

		return new \WP_REST_Response( $this->payload( $graded, false ), 200 );
	}

	/**
	 * Load an attempt and confirm it belongs to the current user.
	 *
	 * A nonce proves a session, not ownership (progress.md, brief 25) - so
	 * every read/answer/submit callback runs this before touching the
	 * attempt. Uses QuizService::owns_attempt() (the security ruling names
	 * this method specifically) rather than re-deriving the same comparison
	 * inline.
	 *
	 * @return QuizAttempt|\WP_REST_Response
	 */
	private function owned_attempt( int $attempt_id ) {
		$attempt = $this->quizzes->get_attempt( $attempt_id );
		if ( ! $attempt instanceof QuizAttempt ) {
			return new \WP_REST_Response(
				[ 'code' => 'no_attempt', 'message' => \__( 'That attempt does not exist.', 'anchor-schema' ) ],
				404
			);
		}
		if ( ! $this->quizzes->owns_attempt( \get_current_user_id(), $attempt_id ) ) {
			return new \WP_REST_Response(
				[ 'code' => 'attempt_not_yours', 'message' => \__( 'That attempt belongs to someone else.', 'anchor-schema' ) ],
				403
			);
		}
		return $attempt;
	}

	/**
	 * Shape a QuizAttempt into the wire format.
	 *
	 * `attempt` is ALWAYS attempt->for_learner( $show_correct ) - never
	 * to_array() - so grading_data (which can embed correct-answer ids) only
	 * ever appears when the quiz's own show_correct_answers setting says so,
	 * and even then only once the attempt is graded (rule 8, brief 25).
	 *
	 * @param bool $with_questions Include the learner question payload
	 *                             (only on start/read-while-open; a graded
	 *                             submit response has no more questions to
	 *                             answer).
	 */
	private function payload( QuizAttempt $attempt, bool $with_questions ): array {
		$settings     = $this->quizzes->settings( $attempt->quiz_id );
		$show_correct = 1 === (int) $settings['show_correct_answers'];

		$out = [
			'attempt'            => $attempt->for_learner( $show_correct ),
			'deadline'           => $this->quizzes->deadline( $attempt ),
			'server_now'         => Clock::timestamp(),
			'attempts_remaining' => $this->quizzes->attempts_remaining( $attempt->user_id, $attempt->quiz_id ),
			'retry_available_at' => $this->quizzes->retry_available_at( $attempt->user_id, $attempt->quiz_id ),
			'show_score'         => 1 === (int) $settings['show_score'],
		];

		if ( $with_questions ) {
			$out['questions'] = $this->quizzes->questions_for_learner( $attempt->quiz_id, $attempt );
		}

		return $out;
	}
}
