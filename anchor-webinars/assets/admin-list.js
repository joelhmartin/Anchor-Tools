/**
 * Anchor Webinars — list table.
 * Prefills the Quick Edit access controls from the row's hidden data, and
 * toggles the role checklist (Quick + Bulk Edit) based on the selected mode.
 */
(function ($) {
    'use strict';

    function toggleRoles($select) {
        var $fieldset = $select.closest('fieldset');
        $fieldset.find('.anchor-webinar-roles-wrap').toggle($select.val() === 'roles');
    }

    // Toggle role checklist whenever the access mode changes (Quick or Bulk).
    $(document).on('change', 'select.anchor-webinar-access-select', function () {
        toggleRoles($(this));
    });

    // Prefill Quick Edit from the row's hidden data block.
    if (typeof inlineEditPost !== 'undefined' && inlineEditPost.edit) {
        var wpEdit = inlineEditPost.edit;
        inlineEditPost.edit = function (id) {
            wpEdit.apply(this, arguments);

            var postId = 0;
            if (typeof id === 'object') {
                postId = parseInt(this.getId(id), 10);
            }
            if (!postId) { return; }

            var $row = $('#post-' + postId);
            var $editRow = $('#edit-' + postId);
            var $data = $row.find('.anchor-access-data');
            if (!$data.length || !$editRow.length) { return; }

            var mode = $data.data('access') || 'public';
            var roles = ($data.data('roles') || '').toString().split(',').filter(Boolean);

            var $select = $editRow.find('select[name="anchor_webinar_access"]');
            $select.val(mode);
            $editRow.find('input[name="anchor_webinar_roles[]"]').each(function () {
                $(this).prop('checked', roles.indexOf($(this).val()) !== -1);
            });
            toggleRoles($select);
        };
    }
})(jQuery);
