/**
 * Anchor Courses - quiz runtime.
 *
 * Renders questions fetched from REST, keeps a purely visual countdown, and
 * posts answers back. It NEVER computes a score: every number it displays came
 * from the server (brief rule 4). The countdown always starts from the
 * pinned `deadline` a response returns, never from a live quiz setting - the
 * server, not this file, is the only clock that can close an attempt.
 *
 * A course page can render more than one quiz (e.g. a module quiz and a
 * final exam) - initQuiz() is called once per `.anchor-quiz` element found on
 * the page, and every piece of per-attempt state (attemptId, timerHandle)
 * lives in that call's own closure, scoped to its own $root, so two quizzes
 * on the same page never share an attempt or a timer (Task 26 review,
 * IMPORTANT).
 *
 * Progressive enhancement: templates/quiz.php ships a <noscript> notice, so a
 * visitor with JavaScript disabled sees "This quiz needs JavaScript" instead
 * of a dead button, rather than a silent no-op.
 */
(function ($) {
    'use strict';

    var cfg = window.anchorCoursesQuizRuntime || {};
    var S = cfg.strings || {};

    function esc(t) { return $('<div/>').text(t == null ? '' : String(t)).html(); }

    /**
     * Serialised answer autosave (audit F06).
     *
     * One save request in flight at a time; while one is out, further
     * changes are queued and COALESCED per question (only the latest value
     * of a question is ever sent next), so a learner's own two changes to one
     * answer can never arrive at the server out of order. A failed save puts
     * the queue in an error state (reported through onState) instead of
     * failing silently; drain(cb) lets submit wait until nothing is queued or
     * in flight. The server's compare-and-swap is still the real guard -
     * another tab or a replayed request does not go through this queue.
     *
     * @param {function(string, Array, function(boolean))} send  Sends one
     *        answer and calls back true (saved) or false (failed).
     * @param {function(string)} onState 'saving' | 'saved' | 'error'.
     */
    function createSaveQueue(send, onState) {
        var pending = {};
        var order = [];
        var inFlight = false;
        var failedQuestions = {};
        var waiters = [];

        function hasFailures() { return Object.keys(failedQuestions).length > 0; }

        function settle() {
            if (inFlight || order.length) { return; }
            var ready = waiters;
            waiters = [];
            ready.forEach(function (cb) { cb(!hasFailures()); });
        }

        function next() {
            if (inFlight || !order.length) { settle(); return; }
            var qid = order.shift();
            var values = pending[qid];
            delete pending[qid];
            inFlight = true;
            onState('saving');
            send(qid, values, function (ok) {
                inFlight = false;
                if (ok) {
                    delete failedQuestions[qid];
                } else {
                    failedQuestions[qid] = true;
                }
                onState(hasFailures() ? 'error' : 'saved');
                next();
            });
        }

        return {
            save: function (qid, values) {
                qid = String(qid);
                if (!Object.prototype.hasOwnProperty.call(pending, qid)) { order.push(qid); }
                pending[qid] = values;
                next();
            },
            drain: function (cb) {
                waiters.push(cb);
                next();
            }
        };
    }
    // Exposed for the Node harness (tests/js/quiz-save-queue-harness.js).
    window.AnchorCoursesQuizSaveQueue = createSaveQueue;

    function api(path, method, body) {
        return $.ajax({
            url: cfg.restUrl + path,
            method: method || 'GET',
            data: body ? JSON.stringify(body) : undefined,
            contentType: 'application/json',
            beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', cfg.nonce); }
        });
    }

    function questionMarkup(q, index) {
        var input = q.type === 'multiple_choice' ? 'checkbox' : 'radio';
        var answers = (q.answers || []).map(function (a) {
            return '<li><label><input type="' + input + '" name="q_' + esc(q.id) + '" value="' + esc(a.id) + '" /> ' +
                esc(a.text) + '</label></li>';
        }).join('');

        // q.prompt is rendered as HTML, not escaped: Content\Questions::sanitize()
        // runs every authored prompt through wp_kses_post() server-side, so this
        // is the one field in the payload that is deliberately pre-sanitized
        // safe HTML rather than plain text (an author may bold/link/italicize a
        // question). Everything else here (ids, answer text) is untrusted-as-HTML
        // and goes through esc().
        return '<fieldset class="anchor-quiz-question" data-question="' + esc(q.id) + '">' +
            '<legend>' + (index + 1) + '. ' + q.prompt + '</legend>' +
            '<ul class="anchor-quiz-answers">' + answers + '</ul>' +
            '</fieldset>';
    }

    /** Wire up a single `.anchor-quiz` element. */
    function initQuiz($root) {
        var quizId = $root.data('quiz');
        var courseId = $root.data('course');
        var attemptId = 0;
        var timerHandle = null;
        var saves = createSaveQueue(function (qid, values, done) {
            api('quiz-attempts/' + attemptId + '/answer', 'POST', { question_id: qid, value: values, course_id: courseId })
                .done(function () { done(true); })
                .fail(function () { done(false); });
        }, function (state) {
            var $status = $root.find('.anchor-quiz-save-status');
            if (state === 'error') {
                $status.prop('hidden', false).text(S.saveError || S.error || '');
            } else if (state === 'saved') {
                $status.prop('hidden', true).text('');
            }
        });

        function startCountdown(deadline, serverNow) {
            var skew = Math.floor(Date.now() / 1000) - serverNow;
            var $timer = $root.find('.anchor-quiz-timer');

            if (!deadline) { $timer.prop('hidden', true); return; }
            $timer.prop('hidden', false);

            if (timerHandle) { window.clearInterval(timerHandle); }
            timerHandle = window.setInterval(function () {
                var left = deadline - (Math.floor(Date.now() / 1000) - skew);
                if (left <= 0) {
                    window.clearInterval(timerHandle);
                    $timer.text(S.timeUp || '');
                    // The server decides what an expired attempt means -
                    // this just triggers the same submit the learner would.
                    $root.find('.anchor-quiz-form').trigger('submit');
                    return;
                }
                var m = Math.floor(left / 60);
                var s = left % 60;
                $timer.text(m + ':' + (s < 10 ? '0' : '') + s);
            }, 1000);
        }

        /**
         * A resumed attempt (start() returns the already-open row, answers
         * and all - QuizService::start_attempt()'s resume path) must have its
         * previously-saved choices re-checked in the freshly-rendered form
         * (Task 26 review, MINOR): without this, a reload shows every input
         * unchecked, and submitting from there sends an empty array for a
         * question that was already answered, clearing it server-side even
         * though submit() itself would otherwise have kept it.
         */
        function preCheckSavedAnswers(answers) {
            if (!answers) { return; }
            $root.find('.anchor-quiz-question').each(function () {
                var $q = $(this);
                var saved = answers[$q.data('question')] || [];
                $q.find('input').each(function () {
                    this.checked = saved.indexOf(this.value) !== -1;
                });
            });
        }

        function renderResult(data) {
            var a = data.attempt || {};
            var text;
            if (!data.show_score) {
                text = S.submitted || '';
            } else {
                text = a.passed ? (S.passed || '') : (S.failed || '');
                if (a.score !== null && typeof a.score !== 'undefined') {
                    text += ' ' + a.score + '%';
                }
            }
            $root.find('.anchor-quiz-form').prop('hidden', true).empty();
            $root.find('.anchor-quiz-timer').prop('hidden', true);
            $root.find('.anchor-quiz-result').prop('hidden', false).text(text);
            if (timerHandle) { window.clearInterval(timerHandle); }
        }

        $root.on('click', '.anchor-quiz-start', function () {
            var $btn = $(this).prop('disabled', true);

            api('quizzes/' + quizId + '/attempts', 'POST', { course_id: courseId })
                .done(function (data) {
                    attemptId = data.attempt.id;
                    var html = (data.questions || []).map(questionMarkup).join('') +
                        '<p><button type="submit" class="anchor-courses-button">' + esc(S.submit) + '</button></p>';
                    $root.find('.anchor-quiz-form').html(html).prop('hidden', false);
                    $btn.prop('hidden', true);
                    preCheckSavedAnswers(data.attempt.answers);
                    startCountdown(data.deadline, data.server_now);
                })
                .fail(function (xhr) {
                    $btn.prop('disabled', false);
                    var msg = (xhr.responseJSON && xhr.responseJSON.message) || S.error;
                    $root.find('.anchor-quiz-result').prop('hidden', false).text(msg);
                });
        });

        // Save each answer as it changes, so an expired timer still has them.
        // Through the serialised queue (audit F06), never a free-running
        // request per click.
        $root.on('change', '.anchor-quiz-answers input', function () {
            if (!attemptId) { return; }
            var $q = $(this).closest('.anchor-quiz-question');
            saves.save($q.data('question'), $q.find('input:checked').map(function () { return this.value; }).get());
        });

        $root.on('submit', '.anchor-quiz-form', function (e) {
            e.preventDefault();
            if (!attemptId) { return; }
            $(this).find('button[type="submit"]').prop('disabled', true);

            // Let queued/in-flight saves finish first so none of them races
            // the grade. Submit sends the whole answer map regardless, so a
            // save that failed is still carried by this request.
            saves.drain(function () {
                var answers = {};
                $root.find('.anchor-quiz-question').each(function () {
                    var $q = $(this);
                    answers[$q.data('question')] = $q.find('input:checked').map(function () { return this.value; }).get();
                });

                api('quiz-attempts/' + attemptId + '/submit', 'POST', { answers: answers, course_id: courseId })
                    .done(renderResult)
                    .fail(function (xhr) {
                        $root.find('.anchor-quiz-form button[type="submit"]').prop('disabled', false);
                        var msg = (xhr && xhr.responseJSON && xhr.responseJSON.message) || S.error;
                        $root.find('.anchor-quiz-result').prop('hidden', false).text(msg);
                    });
            });
        });
    }

    $(function () {
        $('.anchor-quiz').each(function () { initQuiz($(this)); });
    });
})(jQuery);
