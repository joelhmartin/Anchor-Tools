/**
 * Shared admin chrome JS for Anchor Tools builder UIs.
 *
 * Handles: tab switching, device toggle, preset apply, conditional fields,
 * item card drag-reorder, side panel open/close, copy button.
 */
(function ($) {
    'use strict';

    function initBuilder($root) {
        if (!$root.length) return;

        // ── Tabs ──────────────────────────
        $root.on('click', '.anchor-builder__tab', function () {
            var tab = $(this).data('tab');
            $root.find('.anchor-builder__tab').removeClass('is-active');
            $(this).addClass('is-active');
            $root.find('.anchor-builder__pane').addClass('hidden');
            $root.find('[data-pane="' + tab + '"]').removeClass('hidden');
            $root.attr('data-active-tab', tab);
        });

        // ── Device toggle ─────────────────
        $root.on('click', '.anchor-builder__device', function () {
            var $btn = $(this);
            if ($btn.data('action') === 'refresh') {
                $root.trigger('anchor-builder:refresh-preview');
                return;
            }
            var device = $btn.data('device');
            var target = $btn.parent().data('target');
            $btn.parent().find('.anchor-builder__device').removeClass('is-active');
            $btn.addClass('is-active');
            if (target) {
                $('#' + target).attr('data-device', device);
            }
        });

        // ── Conditional fields based on layout/type ─
        function syncConditional(scope) {
            var $scope = scope || $root;
            var $layout = $scope.find('select[name$="_layout"], select[name="avg_layout"], select[name="as_height_mode"]').first();
            // Generic: any field with [data-show-for] checks the value of a sibling .anchor-builder__layout-driver
            $scope.find('.anchor-builder__field--conditional').each(function () {
                var $f = $(this);
                var showFor = ($f.data('show-for') || '').toString().split(',').map(function (s) { return s.trim(); });
                var driver = $f.closest('form').find('select[name="avg_layout"]').val()
                    || $f.closest('form').find('select[name="as_height_mode"]').val();
                if (!driver) {
                    $f.prop('hidden', false);
                    return;
                }
                $f.prop('hidden', showFor.indexOf(driver) === -1);
            });
        }
        syncConditional();
        $(document).on('change', 'select[name="avg_layout"], select[name="as_height_mode"]', function () {
            syncConditional();
        });

        // ── Preset apply ──────────────────
        $root.on('click', '.anchor-builder__preset', function () {
            var $btn = $(this);
            var overrides = $btn.data('overrides') || {};
            $root.find('.anchor-builder__preset').removeClass('is-active');
            $btn.addClass('is-active');
            $root.find('.anchor-builder__preset-selected').val($btn.data('preset'));

            Object.keys(overrides).forEach(function (key) {
                var val = overrides[key];
                var $input = $('[name="' + key + '"]');
                if (!$input.length) return;
                if ($input.is(':checkbox')) {
                    $input.prop('checked', !!val).trigger('change');
                } else {
                    $input.val(val).trigger('change');
                }
            });
            syncConditional();
            $root.trigger('anchor-builder:refresh-preview');
        });

        // ── Copy button ───────────────────
        $root.on('click', '.anchor-builder__copy', function () {
            var text = $(this).data('copy');
            if (!text) return;
            var $temp = $('<textarea>').val(text).appendTo('body').select();
            try { document.execCommand('copy'); } catch (e) {}
            $temp.remove();
            var $btn = $(this);
            var orig = $btn.text();
            $btn.text('Copied!');
            setTimeout(function () { $btn.text(orig); }, 1200);
        });

        // ── Item drag-reorder (HTML5) ─────
        $root.on('dragstart', '.anchor-builder__item-card', function (e) {
            var $card = $(this);
            $card.addClass('is-dragging');
            e.originalEvent.dataTransfer.effectAllowed = 'move';
            e.originalEvent.dataTransfer.setData('text/plain', $card.data('index') || '0');
            window.__anchorDraggedCard = $card;
        });
        $root.on('dragend', '.anchor-builder__item-card', function () {
            $(this).removeClass('is-dragging');
            window.__anchorDraggedCard = null;
            $root.trigger('anchor-builder:items-reordered');
        });
        $root.on('dragover', '.anchor-builder__item-list', function (e) {
            e.preventDefault();
            var $dragging = window.__anchorDraggedCard;
            if (!$dragging) return;
            var $list = $(this);
            var afterEl = getDragAfterElement($list[0], e.originalEvent.clientY);
            if (afterEl == null) {
                $list.append($dragging[0]);
            } else {
                $list[0].insertBefore($dragging[0], afterEl);
            }
        });

        function getDragAfterElement(container, y) {
            var draggableEls = [].slice.call(container.querySelectorAll('.anchor-builder__item-card:not(.is-dragging)'));
            return draggableEls.reduce(function (closest, child) {
                var box = child.getBoundingClientRect();
                var offset = y - box.top - box.height / 2;
                if (offset < 0 && offset > closest.offset) {
                    return { offset: offset, element: child };
                }
                return closest;
            }, { offset: Number.NEGATIVE_INFINITY }).element;
        }

        // ── Side panel close ──────────────
        $(document).on('click', '.anchor-builder__side-panel-close, [data-action="close-panel"]', function () {
            $('.anchor-builder__side-panel').removeClass('is-open');
        });
        $(document).on('keydown', function (e) {
            if (e.key === 'Escape') {
                $('.anchor-builder__side-panel').removeClass('is-open');
            }
        });
    }

    $(function () {
        $('.anchor-builder').each(function () {
            initBuilder($(this));
        });
    });

    // Expose for module-specific extensions
    window.AnchorBuilder = window.AnchorBuilder || {};
    window.AnchorBuilder.openSidePanel = function (id) {
        $('#' + id).addClass('is-open');
    };
    window.AnchorBuilder.closeSidePanel = function () {
        $('.anchor-builder__side-panel').removeClass('is-open');
    };

})(jQuery);
