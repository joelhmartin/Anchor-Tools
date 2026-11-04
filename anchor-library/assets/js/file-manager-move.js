/**
 * File manager: move. Owns the post-move announcement (toast plus Undo).
 * Runs on window.AnchorFMCore, so it must be enqueued after file-manager.js.
 */
(function ($) {
    $(function () {
        const C = window.AnchorFMCore;
        if (!C) return;

        // Undo sends each moved item back to the folder it came from, grouped by
        // that folder so one request covers every item that shared an origin.
        function undo(moved) {
            const groups = {};
            moved.forEach(function (i) {
                (groups[i.fromFolderId] = groups[i.fromFolderId] || []).push({ kind: i.kind, id: i.sourceId });
            });
            const calls = Object.keys(groups).map(function (from) {
                return C.api('anchor_fm_move_items', {
                    items: JSON.stringify(groups[from]),
                    target_folder_id: Number(from)
                }, { fallback: 'Undo failed' });
            });
            $.when.apply($, calls).done(function () {
                C.toast('Move undone');
                C.bootstrap({ stay: true });
            });
        }

        /**
         * res is the anchor_fm_move_items response; targetId the destination.
         * Shows "Moved N item(s) to <Folder>" with an Undo button, and the
         * first per-item failure message when some items were refused.
         */
        window.AnchorFMMove = {
            announce: function (res, targetId) {
                const d = (res && res.data) || {};
                const moved = (d.items || []).filter(function (i) { return i.status === 'moved'; });
                const bad = (d.items || []).find(function (i) { return i.status === 'error' && i.message; });
                const where = Number(targetId) > 0 ? (C.folderName(targetId) || 'folder') : 'Home';
                let msg = moved.length
                    ? 'Moved ' + moved.length + ' item' + (moved.length === 1 ? '' : 's') + ' to ' + where
                    : 'Nothing was moved';
                if (d.errors) msg += ', ' + d.errors + ' failed' + (bad ? ': ' + bad.message : '');
                C.toast(msg, moved.length ? { label: 'Undo', onClick: function () { undo(moved); } } : null);
            }
        };
    });
})(jQuery);
