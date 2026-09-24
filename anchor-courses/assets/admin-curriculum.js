/**
 * Anchor Courses - curriculum builder.
 *
 * Drag-and-drop modules and items with jquery-ui-sortable. The whole curriculum
 * is serialised into one hidden JSON field on every change, so the PHP save
 * handler has exactly one input to validate. Module UUIDs round-trip untouched;
 * new modules post an empty id and PHP mints the UUID.
 */
(function ($) {
    'use strict';

    var cfg = window.anchorCoursesCurriculum || {};
    var S = cfg.strings || {};
    var esc = window.AnchorCoursesAdmin.esc;

    function itemMarkup(item) {
        return '' +
            '<li class="anchor-courses-item" data-type="' + esc(item.type) + '" data-id="' + esc(item.id) + '">' +
            '<span class="anchor-courses-item-handle dashicons dashicons-menu"></span>' +
            '<span class="anchor-courses-item-type">' + esc(item.type) + '</span>' +
            '<span class="anchor-courses-item-title">' + esc(item.title || ('#' + item.id)) + '</span>' +
            '<label><input type="checkbox" class="anchor-courses-item-required"' +
            (item.required === false ? '' : ' checked="checked"') + ' /> ' + esc(S.required) + '</label>' +
            '<button type="button" class="button-link anchor-courses-remove-item">' + esc(S.removeItem) + '</button>' +
            '</li>';
    }

    function moduleMarkup(module) {
        var items = (module.items || []).map(itemMarkup).join('');
        return '' +
            '<li class="anchor-courses-module" data-uuid="' + esc(module.id || '') + '">' +
            '<div class="anchor-courses-module-head">' +
            '<span class="anchor-courses-module-handle dashicons dashicons-menu"></span>' +
            '<input type="text" class="anchor-courses-module-title regular-text" value="' + esc(module.title) +
            '" placeholder="' + esc(S.moduleTitle) + '" />' +
            '<button type="button" class="button-link anchor-courses-remove-module">' + esc(S.removeModule) + '</button>' +
            '</div>' +
            '<ul class="anchor-courses-items">' + items + '</ul>' +
            '<p class="anchor-courses-module-actions">' +
            '<input type="text" class="anchor-courses-search" placeholder="' + esc(S.search) + '" />' +
            '<button type="button" class="button anchor-courses-add" data-type="lesson">' + esc(S.addLesson) + '</button> ' +
            '<button type="button" class="button anchor-courses-add" data-type="quiz">' + esc(S.addQuiz) + '</button> ' +
            '<button type="button" class="button anchor-courses-create" data-type="lesson">' + esc(S.createLesson) + '</button> ' +
            '<button type="button" class="button anchor-courses-create" data-type="quiz">' + esc(S.createQuiz) + '</button>' +
            '<span class="anchor-courses-results"></span>' +
            '</p>' +
            '</li>';
    }

    function serialize($root) {
        var modules = [];
        $root.find('.anchor-courses-module').each(function () {
            var $m = $(this);
            var items = [];
            $m.find('.anchor-courses-item').each(function () {
                var $i = $(this);
                items.push({
                    type: $i.data('type'),
                    id: parseInt($i.data('id'), 10),
                    required: $i.find('.anchor-courses-item-required').is(':checked'),
                    title: $i.find('.anchor-courses-item-title').text()
                });
            });
            modules.push({
                id: $m.attr('data-uuid') || '',
                title: $m.find('.anchor-courses-module-title').val(),
                description: '',
                items: items
            });
        });
        $root.find('.anchor-courses-curriculum-data').val(JSON.stringify(modules));
    }

    function bindSortables($root) {
        $root.find('.anchor-courses-modules').sortable({
            handle: '.anchor-courses-module-handle',
            update: function () { serialize($root); }
        });
        $root.find('.anchor-courses-items').sortable({
            handle: '.anchor-courses-item-handle',
            connectWith: '.anchor-courses-items',
            placeholder: 'anchor-courses-item',
            update: function () { serialize($root); }
        });
    }

    $(function () {
        var $root = $('.anchor-courses-curriculum');
        if (!$root.length) { return; }

        var initial = [];
        try { initial = JSON.parse($root.find('.anchor-courses-curriculum-data').val() || '[]'); } catch (e) { initial = []; }
        $root.find('.anchor-courses-modules').html(initial.map(moduleMarkup).join(''));
        bindSortables($root);

        $root.on('click', '.anchor-courses-add-module', function () {
            $root.find('.anchor-courses-modules').append(moduleMarkup({ id: '', title: S.newModule, items: [] }));
            bindSortables($root);
            serialize($root);
        });

        $root.on('click', '.anchor-courses-remove-module', function () {
            if (!window.confirm(S.confirmModule)) { return; }
            $(this).closest('.anchor-courses-module').remove();
            serialize($root);
        });

        $root.on('click', '.anchor-courses-remove-item', function () {
            $(this).closest('.anchor-courses-item').remove();
            serialize($root);
        });

        $root.on('change keyup', '.anchor-courses-module-title, .anchor-courses-item-required', function () {
            serialize($root);
        });

        $root.on('click', '.anchor-courses-add', function () {
            var $btn = $(this);
            var $module = $btn.closest('.anchor-courses-module');
            var $out = $module.find('.anchor-courses-results');
            $.post(cfg.ajaxUrl, {
                action: 'anchor_courses_search_items',
                nonce: cfg.nonce,
                type: $btn.data('type'),
                term: $module.find('.anchor-courses-search').val()
            }).done(function (res) {
                if (!res || !res.success || !res.data.length) { $out.text(S.noResults); return; }
                $out.html(res.data.map(function (row) {
                    return '<button type="button" class="button-link anchor-courses-pick" data-type="' +
                        esc(row.type) + '" data-id="' + esc(row.id) + '">' + esc(row.title) + '</button>';
                }).join(' - '));
            });
        });

        $root.on('click', '.anchor-courses-pick', function () {
            var $btn = $(this);
            $btn.closest('.anchor-courses-module').find('.anchor-courses-items').append(itemMarkup({
                type: $btn.data('type'), id: $btn.data('id'), title: $btn.text(), required: true
            }));
            $btn.closest('.anchor-courses-results').empty();
            bindSortables($root);
            serialize($root);
        });

        $root.on('click', '.anchor-courses-create', function () {
            var $btn = $(this);
            var $module = $btn.closest('.anchor-courses-module');
            $.post(cfg.ajaxUrl, {
                action: 'anchor_courses_create_item',
                nonce: cfg.nonce,
                type: $btn.data('type'),
                title: $module.find('.anchor-courses-search').val()
            }).done(function (res) {
                if (!res || !res.success) { return; }
                $module.find('.anchor-courses-items').append(itemMarkup({
                    type: res.data.type, id: res.data.id, title: res.data.title, required: true
                }));
                bindSortables($root);
                serialize($root);
                window.open(res.data.edit_url, '_blank');
            });
        });
    });
})(jQuery);
