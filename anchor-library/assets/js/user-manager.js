/*
 * Users panel (administrators only): list, search, filter and page portal
 * users; add one person; import a CSV; change role, set password, email a
 * reset link, remove; create, rename and delete portal roles. Reuses
 * file-manager.js's modal and helpers through window.AnchorFMUI rather than
 * carrying copies.
 *
 * AnchorFM.userRoles is what this tab may assign (no staff roles — those stay
 * in wp-admin); AnchorFM.roles is every role, used for labels here and by
 * file-manager.js's folder permissions.
 *
 * AnchorFM.roles is the page's one role list: file-manager.js's permission
 * pickers read it live, so role changes update it in place (never reassign)
 * and every dropdown offers the change immediately.
 */
jQuery(function ($) {
    const $root = $('[data-afm]');
    const $panel = $root.find('[data-afm-users]');
    const UI = window.AnchorFMUI;
    if (!$panel.length || !UI || !window.AnchorFM || !AnchorFM.isAdmin) return;

    const { api, esc, toast, errMessage, modal } = UI;
    if (!Array.isArray(AnchorFM.roles)) AnchorFM.roles = [];
    const roles = AnchorFM.roles;
    if (!Array.isArray(AnchorFM.userRoles)) AnchorFM.userRoles = [];
    const userRoles = AnchorFM.userRoles;
    const roleLabel = key => (roles.find(r => r.key === key) || {}).label || key;
    const st = { search: '', role: '', page: 1, pages: 1, loaded: false, users: [], seq: 0, total: 0 };
    let searchTimer = null;

    function roleOptions(selected) {
        return userRoles.map(r => `<option value="${esc(r.key)}"${r.key === selected ? ' selected' : ''}>${esc(r.label)}</option>`).join('');
    }

    /** One checkbox per assignable role; `checked` is the list of keys to tick. */
    function roleChecks(checked) {
        return userRoles.map(r => `<label class="afm__check afm__roleCheck"><input type="checkbox" value="${esc(r.key)}"${checked.indexOf(r.key) !== -1 ? ' checked' : ''}> ${esc(r.label)}</label>`).join('');
    }

    /** The role keys ticked in a popup's role picker. */
    function pickedRoles($scope) {
        return $scope.find('[data-um-role] input:checked').map(function () { return this.value; }).get();
    }

    /**
     * Role picker used by every popup: tick one or more roles, with an inline
     * "New role" that ticks what it creates.
     */
    function roleField(checked) {
        return `<div class="afm__formRow">
            <label class="afm__label">Roles</label>
            <div class="afm__roleChecks" data-um-role>${roleChecks(checked)}</div>
            <button type="button" class="afm__linkBtn" data-um-newrole>+ New role</button>
            <div class="afm__newRole" data-um-newrole-row hidden>
                <input type="text" class="afm__input" data-um-newrole-name placeholder="e.g. TMJ patient" maxlength="60">
                <button type="button" class="afm__btn afm__btn--secondary" data-um-newrole-save>Add role</button>
            </div>
        </div>`;
    }

    /** What a new person starts with ticked: the site's default role, if the tab may assign it. */
    function defaultRoles() {
        const d = AnchorFM.defaultRole || '';
        return userRoles.some(r => r.key === d) ? [d] : [];
    }

    /**
     * Swap in the server's role list and refresh everything that shows roles:
     * the filter, any open popup's pickers, the table's role column and the
     * roles popup itself.
     */
    function applyRoles(data) {
        if (!data || !Array.isArray(data.roles)) return;
        roles.splice(0, roles.length, ...data.roles);
        if (Array.isArray(data.userRoles)) userRoles.splice(0, userRoles.length, ...data.userRoles);
        const keys = userRoles.map(r => r.key);

        const $filter = $panel.find('[data-afm-users-role]');
        const filterGone = st.role && keys.indexOf(st.role) === -1;
        $filter.html('<option value="">All roles</option>' + roleOptions(filterGone ? '' : st.role));
        if (filterGone) { st.role = ''; st.page = 1; load(); }

        $root.find('[data-um-role]').each(function () {
            let picked = pickedRoles($(this).parent()).filter(k => keys.indexOf(k) !== -1);
            if (!picked.length && userRoles.length) picked = [userRoles[0].key];
            $(this).html(roleChecks(picked));
        });
        if (!filterGone && st.users.length) renderTable(st.total);
        if (Array.isArray(data.portalRoles)) renderRolesBody(data.portalRoles);
        $root.trigger('anchorfm:rolesChanged', [roles]);
    }

    function createRole(name) {
        return api('anchor_fm_role_create', { name }).then(res => {
            if (!res || !res.success) return $.Deferred().reject(null, res).promise();
            applyRoles(res.data);
            return res.data;
        });
    }

    function genPassword() {
        const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        const buf = new Uint32Array(16);
        window.crypto.getRandomValues(buf);
        return Array.from(buf, n => chars[n % chars.length]).join('');
    }

    function passwordField(label, help) {
        return `<div class="afm__formRow">
            <label class="afm__label">${esc(label)}</label>
            <div class="afm__pwRow">
                <input type="text" class="afm__input" data-um-password autocomplete="new-password">
                <button type="button" class="afm__btn afm__btn--ghost" data-um-generate>Generate</button>
            </div>
            <div class="afm__help">${esc(help)}</div>
        </div>`;
    }

    function fmtDate(s) {
        if (!s) return '—';
        const d = new Date(String(s).replace(' ', 'T'));
        return isNaN(d) ? '—' : d.toLocaleDateString();
    }

    function modalError($body, msg) {
        let $n = $body.find('[data-um-notice]');
        if (!$n.length) $n = $('<div class="afm__notice afm__notice--error" data-um-notice></div>').appendTo($body);
        $n.text(msg).prop('hidden', false);
    }

    function load() {
        // Only the latest request may render: an older one landing last would
        // otherwise show results for a search or filter that is no longer set.
        const seq = ++st.seq;
        $panel.find('[data-afm-users-table]').html('<div class="afm__skeleton"></div>');
        api('anchor_fm_users_list', { search: st.search, role: st.role, page: st.page })
            .done(res => {
                if (seq !== st.seq) return;
                if (!res || !res.success) { renderError(errMessage(null, res, 'Could not load users.')); return; }
                st.users = res.data.users || [];
                st.pages = res.data.pages || 1;
                st.page = res.data.page || 1;
                st.total = res.data.total || 0;
                renderTable(st.total);
            })
            .fail(xhr => { if (seq === st.seq) renderError(errMessage(xhr, null, 'Could not load users.')); });
    }

    function renderError(msg) {
        $panel.find('[data-afm-users-table]').html(`<div class="afm__empty">${esc(msg)}</div>`);
        $panel.find('[data-afm-users-pager]').empty();
    }

    function renderTable(total) {
        if (!st.users.length) {
            renderError(st.search || st.role ? 'No users match.' : 'No users yet.');
            return;
        }
        const rows = st.users.map(u => `
            <tr data-um-id="${u.id}">
                <td data-label="Name"><strong>${esc(u.displayName || u.username)}</strong><div class="afm__muted">${esc(u.username)}</div></td>
                <td data-label="Email">${esc(u.email)}</td>
                <td data-label="Role">${esc((u.roles || []).map(roleLabel).join(', ') || '—')}</td>
                <td data-label="Added">${esc(fmtDate(u.registered))}</td>
                <td data-label="Last watched">${esc(u.lastWatched ? fmtDate(u.lastWatched) : 'Never')}</td>
                <td class="afm__usersActions">${u.manageable ? `
                    <button type="button" class="afm__linkBtn" data-um-act="role">Role</button>
                    <button type="button" class="afm__linkBtn" data-um-act="password">Password</button>
                    <button type="button" class="afm__linkBtn" data-um-act="reset">Email reset</button>
                    <button type="button" class="afm__linkBtn afm__linkBtn--danger" data-um-act="remove">Remove</button>`
                    : '<span class="afm__muted">—</span>'}</td>
            </tr>`).join('');
        $panel.find('[data-afm-users-table]').html(`
            <table class="afm__table">
                <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Added</th><th>Last watched</th><th></th></tr></thead>
                <tbody>${rows}</tbody>
            </table>`);
        $panel.find('[data-afm-users-pager]').html(`
            <button type="button" class="afm__btn afm__btn--ghost" data-um-page="-1" ${st.page <= 1 ? 'disabled' : ''}>Prev</button>
            <span>Page ${st.page} of ${st.pages} · ${total} user${total === 1 ? '' : 's'}</span>
            <button type="button" class="afm__btn afm__btn--ghost" data-um-page="1" ${st.page >= st.pages ? 'disabled' : ''}>Next</button>`);
    }

    function userById(id) { return st.users.find(u => u.id === id); }

    // --- Add person ---
    function openAdd() {
        modal.open('Add person', `
            <div class="afm__formRow"><label class="afm__label">First name</label><input type="text" class="afm__input" data-um-first></div>
            <div class="afm__formRow"><label class="afm__label">Last name</label><input type="text" class="afm__input" data-um-last></div>
            <div class="afm__formRow"><label class="afm__label">Email</label><input type="email" class="afm__input" data-um-email></div>
            <div class="afm__formRow"><label class="afm__label">Username (optional)</label><input type="text" class="afm__input" data-um-username placeholder="e.g. j.smith"></div>
            ${roleField(defaultRoles())}
            ${passwordField('Password (optional)', 'At least 10 characters. Leave blank to generate one they never see.')}
            <label class="afm__check"><input type="checkbox" data-um-send checked> Email a welcome / set-password link</label>
        `, 'Add person', $b => {
            const picked = pickedRoles($b);
            if (!picked.length) { modalError($b, 'Please choose at least one role.'); return; }
            modal.busy(true, 'Adding…');
            api('anchor_fm_user_create', {
                first_name: $b.find('[data-um-first]').val(),
                last_name: $b.find('[data-um-last]').val(),
                email: $b.find('[data-um-email]').val(),
                username: $b.find('[data-um-username]').val(),
                roles: picked,
                password: $b.find('[data-um-password]').val(),
                send_email: $b.find('[data-um-send]').is(':checked') ? '1' : '0',
            }).done(res => {
                if (!res || !res.success) { modalError($b, errMessage(null, res, 'Could not add this person.')); return; }
                modal.close();
                toast(`Added ${res.data.user.displayName || res.data.user.email}`);
                st.page = 1; load();
            }).fail(xhr => modalError($b, errMessage(xhr, null, 'Could not add this person.')))
              .always(() => modal.busy(false, 'Add person'));
        });
    }

    // --- Import CSV ---
    function openImport() {
        let imported = false;
        modal.open('Import users from CSV', `
            <p class="afm__importHint">Columns in this order: username, first name, last name, email, password. A header row is optional; username and password are optional.</p>
            <div class="afm__formRow"><label class="afm__label">CSV file</label><input type="file" accept=".csv,text/csv,text/plain" data-um-file></div>
            ${roleField(defaultRoles())}
            ${passwordField('Password for everyone without one (optional)', 'Rows with their own password keep it. If both are blank, a password is generated.')}
            <label class="afm__check"><input type="checkbox" data-um-send checked> Email new users a link to set their password</label>
            <div class="afm__importResults" data-um-results hidden></div>
        `, 'Import', $b => {
            if (imported) { modal.close(); return; }
            const file = ($b.find('[data-um-file]')[0] || {}).files;
            if (!file || !file[0]) { modalError($b, 'Please choose a CSV file first.'); return; }
            const picked = pickedRoles($b);
            if (!picked.length) { modalError($b, 'Please choose at least one role.'); return; }
            const data = new FormData();
            data.append('action', 'anchor_fm_bulk_import_users');
            data.append('nonce', AnchorFM.nonce);
            picked.forEach(r => data.append('roles[]', r));
            data.append('default_password', $b.find('[data-um-password]').val() || '');
            data.append('send_email', $b.find('[data-um-send]').is(':checked') ? '1' : '0');
            data.append('csv', file[0], file[0].name);
            modal.busy(true, 'Importing…');
            $.ajax({ url: AnchorFM.ajax, method: 'POST', data, processData: false, contentType: false })
                .done(res => {
                    if (!res || !res.success) { modalError($b, errMessage(null, res, 'Import failed.')); return; }
                    renderImportResults($b, res.data);
                    load();
                    imported = true;
                })
                .fail(xhr => modalError($b, errMessage(xhr, null, 'Import failed.')))
                .always(() => modal.busy(false, imported ? 'Done' : 'Import'));
        });
    }

    function renderImportResults($b, res) {
        const rows = Array.isArray(res.rows) ? res.rows : [];
        const body = rows.map(r => `<tr class="afm__importRow afm__importRow--${esc(r.status)}"><td>${esc(String(r.line || ''))}</td><td>${esc(r.username || '')}</td><td>${esc(r.email || '')}</td><td>${esc(r.status || '')}</td><td>${esc(r.message || '')}</td></tr>`).join('');
        $b.find('[data-um-notice]').prop('hidden', true);
        $b.find('[data-um-results]').html(
            `<div class="afm__importSummary">${esc(`${res.created || 0} created, ${res.skipped || 0} skipped, ${res.errors || 0} error(s)`)}</div>`
            + `<table class="afm__importTable"><thead><tr><th>#</th><th>Username</th><th>Email</th><th>Status</th><th>Message</th></tr></thead><tbody>${body}</tbody></table>`
        ).prop('hidden', false);
    }

    // --- Row actions ---
    function openRole(u) {
        const current = u.roles || [];
        modal.open(`Change roles — ${u.displayName}`, `
            ${roleField(current)}
            <div class="afm__help">Folder access follows role, so this changes what they can see.</div>
        `, 'Save', $b => {
            const picked = pickedRoles($b);
            if (!picked.length) { modalError($b, 'Please choose at least one role.'); return; }
            modal.busy(true, 'Saving…');
            api('anchor_fm_user_set_role', { user_id: u.id, roles: picked })
                .done(res => {
                    if (!res || !res.success) { modalError($b, errMessage(null, res, 'Could not change the role.')); return; }
                    modal.close(); toast('Role updated');
                    const i = st.users.findIndex(x => x.id === u.id);
                    if (i !== -1 && res.data && res.data.user) st.users[i] = res.data.user;
                    // A filtered list may no longer include them; otherwise redraw in place.
                    if (st.role) load(); else renderTable(st.total);
                })
                .fail(xhr => modalError($b, errMessage(xhr, null, 'Could not change the role.')))
                .always(() => modal.busy(false, 'Save'));
        });
    }

    function openPassword(u) {
        modal.open(`Set password — ${u.displayName}`, passwordField('New password', 'At least 10 characters. Share it with them yourself; it is not emailed.'), 'Set password', $b => {
            modal.busy(true, 'Saving…');
            api('anchor_fm_user_set_password', { user_id: u.id, password: $b.find('[data-um-password]').val() })
                .done(res => {
                    if (!res || !res.success) { modalError($b, errMessage(null, res, 'Could not set the password.')); return; }
                    modal.close(); toast('Password set');
                })
                .fail(xhr => modalError($b, errMessage(xhr, null, 'Could not set the password.')))
                .always(() => modal.busy(false, 'Set password'));
        });
    }

    function sendReset(u) {
        api('anchor_fm_user_send_reset', { user_id: u.id })
            .done(res => toast(res && res.success ? `Reset link sent to ${u.email}` : errMessage(null, res, 'Could not send the email.')))
            .fail(xhr => toast(errMessage(xhr, null, 'Could not send the email.')));
    }

    function openRemove(u) {
        modal.open('Remove user', `<div class="afm__help">Remove <strong>${esc(u.displayName)}</strong> (${esc(u.email)})? Their login, folder access and watch history are deleted, and anything they authored on the site is reassigned to you. This cannot be undone.</div>`, 'Remove', $b => {
            modal.busy(true, 'Removing…');
            api('anchor_fm_user_delete', { user_id: u.id })
                .done(res => {
                    if (!res || !res.success) { modalError($b, errMessage(null, res, 'Could not remove the user.')); return; }
                    modal.close(); toast('User removed');
                    if (st.users.length === 1 && st.page > 1) st.page--;
                    load();
                })
                .fail(xhr => modalError($b, errMessage(xhr, null, 'Could not remove the user.')))
                .always(() => modal.busy(false, 'Remove'));
        });
    }

    // --- Roles popup ---
    const $rolesHost = () => $root.find('[data-rm-body]');

    function renderRolesBody(portalRoles) {
        const $host = $rolesHost();
        if (!$host.length) return;
        const rows = portalRoles.length ? portalRoles.map(r => `
            <div class="afm__roleRow" data-rm-key="${esc(r.key)}">
                <div class="afm__roleName" data-rm-name>${esc(r.label)}</div>
                <div class="afm__muted">${r.users} user${r.users === 1 ? '' : 's'}</div>
                <div class="afm__roleActions">
                    <button type="button" class="afm__linkBtn" data-rm-act="rename">Rename</button>
                    <button type="button" class="afm__linkBtn afm__linkBtn--danger" data-rm-act="delete"
                        ${r.users ? `disabled title="Move its ${r.users} user${r.users === 1 ? '' : 's'} to another role first"` : ''}>Delete</button>
                </div>
            </div>`).join('') : '<div class="afm__empty afm__empty--tight">No roles created here yet.</div>';
        $host.html(`
            <div class="afm__help">Roles group people so folders can be shared with the whole group from a folder's Permissions. New roles can log in and see the portal, nothing else.</div>
            <div class="afm__roleList">${rows}</div>
            <div class="afm__newRole">
                <input type="text" class="afm__input" data-rm-new placeholder="New role name, e.g. Sleep patient" maxlength="60">
                <button type="button" class="afm__btn afm__btn--primary" data-rm-add>Add role</button>
            </div>
            <div class="afm__notice afm__notice--error" data-rm-notice hidden></div>`);
    }

    function rolesError(msg) {
        $rolesHost().find('[data-rm-notice]').text(msg).prop('hidden', false);
    }

    function openRoles() {
        modal.open('Roles', '<div data-rm-body><div class="afm__skeleton"></div></div>', 'Done', () => modal.close());
        api('anchor_fm_roles_list', {})
            .done(res => {
                if (!res || !res.success) { rolesError(errMessage(null, res, 'Could not load roles.')); return; }
                applyRoles(res.data);
            })
            .fail(xhr => rolesError(errMessage(xhr, null, 'Could not load roles.')));
    }

    function roleMutation(action, data, okMsg) {
        return api(action, data)
            .done(res => {
                if (!res || !res.success) { rolesError(errMessage(null, res, 'That did not work.')); return; }
                applyRoles(res.data);
                toast(okMsg);
            })
            .fail(xhr => rolesError(errMessage(xhr, null, 'That did not work.')));
    }

    $root.on('click', '[data-rm-add]', function () {
        const $in = $rolesHost().find('[data-rm-new]');
        const name = String($in.val() || '').trim();
        if (!name) { $in.trigger('focus'); return; }
        const $btn = $(this).prop('disabled', true);
        createRole(name)
            .done(() => toast(`Role "${name}" added`))
            .fail((xhr, res) => rolesError(errMessage(xhr, res, 'Could not create the role.')))
            .always(() => $btn.prop('disabled', false));
    });
    $root.on('keydown', '[data-rm-new], [data-rm-rename], [data-um-newrole-name]', function (e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        e.stopPropagation();
        const $scope = $(this).parent();
        $scope.find('[data-rm-add], [data-rm-save], [data-um-newrole-save]').first().trigger('click');
    });
    $root.on('click', '[data-rm-act="rename"]', function () {
        const $row = $(this).closest('[data-rm-key]');
        const current = $row.find('[data-rm-name]').text();
        $row.find('[data-rm-name]').html(`<input type="text" class="afm__input" data-rm-rename maxlength="60" value="${esc(current)}">
            <button type="button" class="afm__btn afm__btn--secondary" data-rm-save>Save</button>`);
        $row.find('[data-rm-rename]').trigger('focus').trigger('select');
    });
    $root.on('click', '[data-rm-save]', function () {
        const $row = $(this).closest('[data-rm-key]');
        const name = String($row.find('[data-rm-rename]').val() || '').trim();
        if (!name) return;
        roleMutation('anchor_fm_role_rename', { key: $row.data('rm-key'), name }, 'Role renamed');
    });
    $root.on('click', '[data-rm-act="delete"]', function () {
        const $row = $(this).closest('[data-rm-key]');
        const label = $row.find('[data-rm-name]').text();
        const $btn = $(this);
        if ($btn.data('rm-confirm')) {
            roleMutation('anchor_fm_role_delete', { key: $row.data('rm-key') }, `Role "${label}" deleted`);
            return;
        }
        // Second click confirms: keeps the confirmation inside this popup.
        $btn.data('rm-confirm', true).text('Click again to delete');
    });

    // Inline "New role" inside Add person / Import / Change role.
    $root.on('click', '[data-um-newrole]', function () {
        const $row = $(this).siblings('[data-um-newrole-row]');
        $row.prop('hidden', !$row.prop('hidden'));
        $row.find('[data-um-newrole-name]').trigger('focus');
    });
    $root.on('click', '[data-um-newrole-save]', function () {
        const $field = $(this).closest('.afm__formRow');
        const $in = $field.find('[data-um-newrole-name]');
        const name = String($in.val() || '').trim();
        if (!name) { $in.trigger('focus'); return; }
        const $btn = $(this).prop('disabled', true);
        createRole(name)
            .done(data => {
                $field.find(`[data-um-role] input[value="${CSS.escape(data.created)}"]`).prop('checked', true);
                $field.find('[data-um-newrole-row]').prop('hidden', true);
                $in.val('');
                toast(`Role "${name}" added`);
            })
            .fail((xhr, res) => modalError(modal.body(), errMessage(xhr, res, 'Could not create the role.')))
            .always(() => $btn.prop('disabled', false));
    });

    // --- Wiring ---
    $panel.find('[data-afm-users-role]').html('<option value="">All roles</option>' + roleOptions(''));

    $root.on('anchorfm:showUsers', () => { if (!st.loaded) { st.loaded = true; load(); } });
    $panel.on('input', '[data-afm-users-search]', function () {
        clearTimeout(searchTimer);
        const v = String($(this).val() || '').trim();
        searchTimer = setTimeout(() => { st.search = v; st.page = 1; load(); }, 300);
    });
    $panel.on('change', '[data-afm-users-role]', function () { st.role = String($(this).val() || ''); st.page = 1; load(); });
    $panel.on('click', '[data-um-page]', function () {
        st.page = Math.min(st.pages, Math.max(1, st.page + Number($(this).data('um-page'))));
        load();
    });
    $panel.on('click', '[data-afm-action="users-add"]', openAdd);
    $panel.on('click', '[data-afm-action="users-import"]', openImport);
    $panel.on('click', '[data-afm-action="users-roles"]', openRoles);
    $panel.on('click', '[data-um-act]', function () {
        const u = userById(Number($(this).closest('[data-um-id]').data('um-id')));
        if (!u) return;
        const act = String($(this).data('um-act'));
        if (act === 'role') openRole(u);
        else if (act === 'password') openPassword(u);
        else if (act === 'reset') sendReset(u);
        else if (act === 'remove') openRemove(u);
    });
    // Generate buttons live inside the shared modal, outside $panel.
    $root.on('click', '[data-um-generate]', function () {
        $(this).closest('.afm__pwRow').find('[data-um-password]').val(genPassword()).trigger('select');
    });
});
