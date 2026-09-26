/*
 * Node harness for the answer-save queue in anchor-courses/assets/quiz.js
 * (audit F06; driven by Test_Courses_Quiz_Autosave). quiz.js is loaded into a
 * VM with a do-nothing jQuery - only window.AnchorCoursesQuizSaveQueue, the
 * jQuery-free queue it exposes, is exercised. Each "send" is held until the
 * scenario completes it, so overlap and reordering are fully controlled.
 *
 * Usage: node quiz-save-queue-harness.js <quiz.js path>
 * Prints { scenario: result, ... } as JSON.
 */
'use strict';
const fs = require('fs');
const vm = require('vm');

const [, , quizPath] = process.argv;
const noop$ = function () { return noop$; };
const context = { window: {}, jQuery: noop$ };
vm.createContext(context);
vm.runInContext(fs.readFileSync(quizPath, 'utf8'), context);
const createSaveQueue = context.window.AnchorCoursesQuizSaveQueue;

function rig() {
  const sent = [];      // every request, in send order
  const open = [];      // requests not yet answered
  const states = [];
  let maxInFlight = 0;
  const queue = createSaveQueue(function (qid, values, done) {
    const req = { qid, values: values.slice(), done };
    sent.push(req);
    open.push(req);
    maxInFlight = Math.max(maxInFlight, open.length);
  }, function (s) { states.push(s); });
  function finish(ok) { const req = open.shift(); req.done(ok); }
  return { queue, sent, open, states, finish, max: () => maxInFlight };
}

const out = {};

// Two questions changed back to back: one request at a time, both sent.
(function () {
  const r = rig();
  r.queue.save('q1', ['a']);
  r.queue.save('q2', ['b']);
  const inFlightAfterClicks = r.open.length;
  r.finish(true);
  r.finish(true);
  out.two_questions = { inFlightAfterClicks, maxInFlight: r.max(), sent: r.sent.map(q => q.qid + '=' + q.values.join('|')) };
})();

// One question changed three times while the first save is out: coalesced to the latest, in order.
(function () {
  const r = rig();
  r.queue.save('q1', ['a']);
  r.queue.save('q1', ['b']);
  r.queue.save('q1', ['c']);
  r.finish(true);
  r.finish(true);
  out.same_question = { maxInFlight: r.max(), sent: r.sent.map(q => q.qid + '=' + q.values.join('|')) };
})();

// A failed save reports an error state; a later successful re-save clears it.
(function () {
  const r = rig();
  r.queue.save('q1', ['a']);
  r.finish(false);
  const afterFailure = r.states[r.states.length - 1];
  r.queue.save('q1', ['a']);
  r.finish(true);
  out.failure = { afterFailure, afterRetry: r.states[r.states.length - 1] };
})();

// Submit waits: drain() fires only once the queue is empty, and says whether everything saved.
(function () {
  const r = rig();
  const drained = [];
  r.queue.save('q1', ['a']);
  r.queue.save('q2', ['b']);
  r.queue.drain(function (allSaved) { drained.push(allSaved); });
  const beforeFinish = drained.length;
  r.finish(true);
  const afterOne = drained.length;
  r.finish(false);
  const idle = rig();
  let immediate = null;
  idle.queue.drain(function (allSaved) { immediate = allSaved; });
  out.drain = { beforeFinish, afterOne, result: drained, immediate };
})();

process.stdout.write(JSON.stringify(out));
