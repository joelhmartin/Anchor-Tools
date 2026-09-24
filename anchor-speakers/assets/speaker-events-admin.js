(function ($) {
  'use strict';

  // Ordered add/remove/up/down list for the "Speakers" metabox on the event
  // editor. No sortable dependency: the list is short and the up/down
  // buttons are enough, and keep this metabox self-contained.

  function addRow($list, id, name) {
    if (!id) return;
    // No duplicates: moving an already-linked speaker is done with up/down.
    if ($list.find('input[value="' + id + '"]').length) return;

    var $row = $(
      '<li class="anchor-speaker-events__row">' +
        '<span class="anchor-speaker-events__name"></span>' +
        '<input type="hidden" name="anchor_event_speaker_ids[]" />' +
        '<button type="button" class="button-link anchor-speaker-events__up" aria-label="Move up">&#8593;</button>' +
        '<button type="button" class="button-link anchor-speaker-events__down" aria-label="Move down">&#8595;</button>' +
        '<button type="button" class="button-link anchor-speaker-events__remove" aria-label="Remove">&times;</button>' +
      '</li>'
    );
    $row.find('.anchor-speaker-events__name').text(name);
    $row.find('input[type="hidden"]').val(id);
    $list.append($row);
  }

  $(document).on('click', '.anchor-speaker-events__add', function (e) {
    e.preventDefault();
    var $wrap   = $(this).closest('.anchor-speaker-events');
    var $picker = $wrap.find('.anchor-speaker-events__picker');
    var $option = $picker.find('option:selected');
    var id      = $option.val();
    if (!id) return;
    addRow($wrap.find('.anchor-speaker-events__list'), id, $option.data('name') || $option.text());
    $picker.val('');
  });

  $(document).on('click', '.anchor-speaker-events__remove', function (e) {
    e.preventDefault();
    $(this).closest('.anchor-speaker-events__row').remove();
  });

  $(document).on('click', '.anchor-speaker-events__up', function (e) {
    e.preventDefault();
    var $row = $(this).closest('.anchor-speaker-events__row');
    var $prev = $row.prev('.anchor-speaker-events__row');
    if ($prev.length) $row.insertBefore($prev);
  });

  $(document).on('click', '.anchor-speaker-events__down', function (e) {
    e.preventDefault();
    var $row = $(this).closest('.anchor-speaker-events__row');
    var $next = $row.next('.anchor-speaker-events__row');
    if ($next.length) $row.insertAfter($next);
  });

})(jQuery);
