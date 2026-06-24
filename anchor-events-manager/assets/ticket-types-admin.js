/**
 * Anchor Events — Tickets / Pricing metabox.
 *
 * Repeatable ticket-tier rows: add from a hidden <script> template, remove a
 * row, and drag-reorder via jQuery UI sortable. Row field names use the index
 * scheme anchor_event_tickets[<index>][...]; new rows get a monotonically
 * increasing index so names never collide. Stable tier ids live in the hidden
 * `[id]` input (blank for new rows — the server mints one on save).
 */
(function ($) {
    'use strict';

    $(function () {
        var $wrap = $('#anchor-event-tickets');
        if (!$wrap.length) {
            return;
        }

        var $rows = $wrap.find('.anchor-event-tickets-rows');
        var template = $('#anchor-event-ticket-template').html() || '';

        // Seed the next index above any rows rendered server-side.
        var nextIndex = $rows.find('.anchor-event-ticket-row').length;

        function makeSortable() {
            if (typeof $rows.sortable !== 'function') {
                return;
            }
            $rows.sortable({
                items: '.anchor-event-ticket-row',
                handle: '.anchor-ticket-handle',
                axis: 'y',
                helper: function (e, tr) {
                    // Keep cell widths while dragging.
                    var $originals = tr.children();
                    var $helper = tr.clone();
                    $helper.children().each(function (i) {
                        $(this).width($originals.eq(i).width());
                    });
                    return $helper;
                }
            });
        }

        // Add row.
        $wrap.on('click', '.anchor-event-ticket-add', function (e) {
            e.preventDefault();
            if (!template) {
                return;
            }
            var html = template.replace(/__INDEX__/g, String(nextIndex));
            nextIndex++;
            $rows.append(html);
        });

        // Remove row.
        $wrap.on('click', '.anchor-event-ticket-remove', function (e) {
            e.preventDefault();
            $(this).closest('.anchor-event-ticket-row').remove();
        });

        makeSortable();
    });
})(jQuery);
