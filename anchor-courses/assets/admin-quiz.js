/**
 * Anchor Courses - quiz question editor.
 *
 * Questions and answers are edited in the DOM and serialised into one hidden
 * JSON field, mirroring admin-curriculum.js's builder pattern (one hidden
 * field, one save handler). Ids round-trip so grading references survive
 * reordering. The editor never computes scores or exposes which answer is
 * correct after the fact; it only authors data for Content\Questions to
 * sanitise server-side.
 */
(function ($) {
    'use strict';

    var S = (window.anchorCoursesQuiz || {}).strings || {};
    var esc = window.AnchorCoursesAdmin.esc;

    function answerMarkup(answer, type) {
        var input = type === 'multiple_choice' ? 'checkbox' : 'radio';
        var readonly = type === 'true_false' ? ' readonly="readonly"' : '';
        return '<li class="anchor-courses-answer" data-id="' + esc(answer.id || '') + '">' +
            '<span class="anchor-courses-item-handle dashicons dashicons-menu"></span>' +
            '<input type="text" class="anchor-courses-answer-text regular-text" value="' + esc(answer.text) +
            '" placeholder="' + esc(S.answer) + '"' + readonly + ' />' +
            '<label><input type="' + input + '" class="anchor-courses-answer-correct"' +
            (answer.correct ? ' checked="checked"' : '') + ' /> ' + esc(S.correct) + '</label>' +
            (type === 'true_false' ? '' :
                '<button type="button" class="button-link anchor-courses-remove-answer">' + esc(S.remove) + '</button>') +
            '</li>';
    }

    function questionMarkup(q) {
        var type = q.type || 'single_choice';
        var answers = (q.answers && q.answers.length) ? q.answers :
            (type === 'true_false'
                ? [{ id: 'true', text: 'True', correct: true }, { id: 'false', text: 'False', correct: false }]
                : [{ id: '', text: '', correct: true }, { id: '', text: '', correct: false }]);

        return '<li class="anchor-courses-question" data-id="' + esc(q.id || '') + '" data-type="' + esc(type) + '">' +
            '<div class="anchor-courses-module-head">' +
            '<span class="anchor-courses-module-handle dashicons dashicons-menu"></span>' +
            '<span class="anchor-courses-item-type">' + esc(type) + '</span>' +
            '<input type="text" class="anchor-courses-question-prompt regular-text" value="' + esc(q.prompt) +
            '" placeholder="' + esc(S.prompt) + '" />' +
            '<label>' + esc(S.points) + ' <input type="number" step="0.5" min="0" class="small-text anchor-courses-question-points" value="' +
            esc(q.points == null ? 1 : q.points) + '" /></label>' +
            '<button type="button" class="button-link anchor-courses-remove-question">' + esc(S.remove) + '</button>' +
            '</div>' +
            '<ul class="anchor-courses-answers">' + answers.map(function (a) { return answerMarkup(a, type); }).join('') + '</ul>' +
            (type === 'true_false' ? '' :
                '<p><button type="button" class="button anchor-courses-add-answer">' + esc(S.addAnswer) + '</button></p>') +
            '</li>';
    }

    function serialize($root) {
        var questions = [];
        $root.find('.anchor-courses-question').each(function () {
            var $q = $(this);
            var answers = [];
            $q.find('.anchor-courses-answer').each(function () {
                var $a = $(this);
                answers.push({
                    id: $a.attr('data-id') || '',
                    text: $a.find('.anchor-courses-answer-text').val(),
                    correct: $a.find('.anchor-courses-answer-correct').is(':checked')
                });
            });
            questions.push({
                id: $q.attr('data-id') || '',
                type: $q.attr('data-type'),
                prompt: $q.find('.anchor-courses-question-prompt').val(),
                points: parseFloat($q.find('.anchor-courses-question-points').val() || '1'),
                answers: answers
            });
        });
        $root.find('.anchor-courses-questions-data').val(JSON.stringify(questions));
    }

    function bind($root) {
        $root.find('.anchor-courses-question-list').sortable({
            handle: '.anchor-courses-module-handle',
            update: function () { serialize($root); }
        });
        $root.find('.anchor-courses-answers').sortable({
            handle: '.anchor-courses-item-handle',
            update: function () { serialize($root); }
        });
    }

    $(function () {
        var $root = $('.anchor-courses-questions');
        if (!$root.length) { return; }

        var initial = [];
        try { initial = JSON.parse($root.find('.anchor-courses-questions-data').val() || '[]'); } catch (e) { initial = []; }
        $root.find('.anchor-courses-question-list').html(initial.map(questionMarkup).join(''));
        bind($root);

        $root.on('click', '.anchor-courses-add-question', function () {
            $root.find('.anchor-courses-question-list')
                .append(questionMarkup({ type: $(this).data('type'), prompt: '', points: 1, answers: [] }));
            bind($root);
            serialize($root);
        });

        $root.on('click', '.anchor-courses-remove-question', function () {
            if (!window.confirm(S.confirmDel)) { return; }
            $(this).closest('.anchor-courses-question').remove();
            serialize($root);
        });

        $root.on('click', '.anchor-courses-add-answer', function () {
            var $q = $(this).closest('.anchor-courses-question');
            $q.find('.anchor-courses-answers').append(answerMarkup({ id: '', text: '', correct: false }, $q.attr('data-type')));
            bind($root);
            serialize($root);
        });

        $root.on('click', '.anchor-courses-remove-answer', function () {
            $(this).closest('.anchor-courses-answer').remove();
            serialize($root);
        });

        // single_choice and true_false have one key: checking one clears siblings.
        $root.on('change', '.anchor-courses-answer-correct', function () {
            var $q = $(this).closest('.anchor-courses-question');
            if ($q.attr('data-type') !== 'multiple_choice' && $(this).is(':checked')) {
                $q.find('.anchor-courses-answer-correct').not(this).prop('checked', false);
            }
            serialize($root);
        });

        $root.on('change keyup', 'input', function () { serialize($root); });
    });
})(jQuery);
