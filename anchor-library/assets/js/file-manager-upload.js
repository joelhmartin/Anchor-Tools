/**
 * File manager: uploads. Owns the whole upload path and sets
 * AnchorFMCore.uploadFiles (file-manager-dnd.js and the toolbar call it).
 *
 *  - Files over the server's per-file limit are rejected before sending.
 *  - The rest go in sequential batches, each under 80% of post_max_size and at
 *    most 20 files, with one progress bar over the whole job and a Cancel.
 *  - A desktop folder (drop via webkitGetAsEntry, or the "Upload folder" input)
 *    is recreated folder by folder, reusing a same-name child that already exists.
 *  - Nothing is skipped silently: the result toast names every file that did not
 *    go up and why. File names are never logged.
 *
 * Runs on window.AnchorFMCore, so it must be enqueued after file-manager.js.
 */
(function ($) {
    $(function () {
        const C = window.AnchorFMCore;
        if (!C) return;
        const AnchorFM = window.AnchorFM || {};
        const state = C.state;
        const $root = C.$root;

        const MAX_BATCH_FILES = 20;
        const BATCH_FRACTION = 0.8;      // of post_max_size
        const POST_OVERHEAD = 16 * 1024; // multipart framing around the bytes
        const MAX_NAMES_IN_TOAST = 5;
        const OS_JUNK = /^(\.ds_store|thumbs\.db|desktop\.ini)$/i;
        const REASON_TEXT = { too_large: 'too large', type: 'type not allowed', server: 'server error', busy: 'another upload is running' };

        const uploadMax = Number(AnchorFM.uploadMaxBytes) || 0; // 0 = unknown, the server decides
        const postMax = Number(AnchorFM.postMaxBytes) || 0;     // 0 = PHP has no limit

        let running = null; // { cancelled, xhr } while a job is in flight

        // ---- progress UI ----------------------------------------------------

        function showProgress(job) {
            const $p = $('<div class="afm__uploadProgress" data-afm-upload-progress>' +
                '<div class="afm__uploadInfo"><span class="afm__uploadLabel"></span>' +
                '<div class="afm__uploadBar"><div class="afm__uploadBarFill"></div></div></div>' +
                '<span class="afm__uploadPct">0%</span>' +
                '<button type="button" class="afm__btn afm__uploadCancel">Cancel</button></div>').appendTo($root);
            $p.find('.afm__uploadCancel').on('click', function () {
                job.cancelled = true;
                $(this).prop('disabled', true).text('Cancelling…');
                if (job.xhr) job.xhr.abort();
            });
            return $p;
        }

        function setProgress($p, pct, label) {
            const v = Math.max(0, Math.min(100, Math.round(pct)));
            $p.find('.afm__uploadBarFill').css('width', v + '%');
            $p.find('.afm__uploadPct').text(v + '%');
            if (label !== undefined) $p.find('.afm__uploadLabel').text(label);
        }

        // ---- limits and batching ---------------------------------------------

        // Reason a single file can never go up, or '' when it may be sent.
        function sizeProblem(file) {
            if (uploadMax > 0 && file.size > uploadMax) return 'too_large';
            if (postMax > 0 && file.size > postMax - POST_OVERHEAD) return 'too_large';
            return '';
        }

        // Jobs are {file, folderId}. A batch holds files for one folder only (the
        // endpoint takes a single folder_id), stays under the byte budget and the
        // file cap; a lone file bigger than the budget still goes alone.
        function makeBatches(jobs) {
            const budget = postMax > 0 ? postMax * BATCH_FRACTION : Infinity;
            const batches = [];
            let cur = null;
            jobs.forEach(function (j) {
                const fits = cur && cur.folderId === j.folderId && cur.items.length < MAX_BATCH_FILES &&
                    cur.bytes + j.file.size <= budget;
                if (!fits) { cur = { folderId: j.folderId, items: [], bytes: 0 }; batches.push(cur); }
                cur.items.push(j);
                cur.bytes += j.file.size;
            });
            return batches;
        }

        function sendBatch(batch, job, onProgress) {
            return new Promise(function (resolve) {
                const data = new FormData();
                data.append('action', 'anchor_fm_upload');
                data.append('nonce', AnchorFM.nonce);
                data.append('folder_id', String(batch.folderId));
                batch.items.forEach(function (j) { data.append('files[]', j.file, j.file.name); });
                job.xhr = $.ajax({
                    url: AnchorFM.ajax, method: 'POST', data: data, processData: false, contentType: false,
                    xhr: function () {
                        const x = new window.XMLHttpRequest();
                        x.upload.addEventListener('progress', function (evt) {
                            if (evt.lengthComputable) onProgress(Math.min(evt.loaded, batch.bytes));
                        }, false);
                        return x;
                    },
                }).done(function (res) {
                    const d = (res && res.data) || {};
                    resolve({ uploaded: d.uploaded || [], rejected: d.rejected || [] });
                }).fail(function (xhr, status) {
                    if (status === 'abort') return resolve({ aborted: true });
                    // The whole request failed: every file in it is unconfirmed.
                    resolve({
                        failed: true,
                        expired: C.isExpired(xhr),
                        rejected: batch.items.map(function (j) { return { name: j.file.name, reason: 'server' }; }),
                    });
                });
            });
        }

        // ---- the engine ---------------------------------------------------------

        // Sends every job. Resolves {uploaded:[], rejected:[{name,reason}], cancelled, notSent}.
        async function sendJobs(jobs, job, $p) {
            const out = { uploaded: [], rejected: [], cancelled: false, notSent: 0, expired: false };
            const sendable = [];
            jobs.forEach(function (j) {
                const why = sizeProblem(j.file);
                if (why) out.rejected.push({ name: j.file.name, reason: why }); else sendable.push(j);
            });
            sendable.sort(function (a, b) { return a.folderId - b.folderId; }); // stable in modern engines
            const batches = makeBatches(sendable);
            const total = Math.max(1, batches.reduce(function (n, b) { return n + b.bytes; }, 0));
            let done = 0, filesDone = 0, stopped = false;
            const count = sendable.length;

            for (let i = 0; i < batches.length; i++) {
                const b = batches[i];
                if (job.cancelled || stopped) { out.notSent += b.items.length; continue; }
                setProgress($p, (done / total) * 100, 'Uploading ' + Math.min(filesDone + 1, count) + ' of ' + count);
                const r = await sendBatch(b, job, function (loaded) {
                    setProgress($p, ((done + loaded) / total) * 100);
                });
                if (r.aborted) { out.cancelled = true; out.notSent += b.items.length; continue; }
                out.uploaded = out.uploaded.concat(r.uploaded);
                out.rejected = out.rejected.concat(r.rejected);
                if (r.expired) { stopped = true; out.expired = true; } // every later batch would fail the same way
                done += b.bytes;
                filesDone += b.items.length;
            }
            if (job.cancelled) out.cancelled = true;
            return out;
        }

        // ---- desktop folders ---------------------------------------------------

        // Child folder of parentId called `name` in what the tree already knows (the server compares
        // names case-insensitively, as does this).
        function knownChild(parentId, name) {
            const want = name.toLowerCase();
            const nodes = parentId === 0 ? state.tree : findNode(state.tree, parentId);
            const hit = (nodes || []).find(function (n) { return String(n.name).trim().toLowerCase() === want; });
            return hit ? Number(hit.id) : 0;
        }
        function findNode(nodes, id) {
            for (let i = 0; i < (nodes || []).length; i++) {
                if (Number(nodes[i].id) === id) return nodes[i].children || [];
                const sub = findNode(nodes[i].children, id);
                if (sub) return sub;
            }
            return null;
        }

        // The tree can be older than the server (another admin, another tab): ask for the listing.
        async function listedChild(parentId, name) {
            try {
                const res = await C.api('anchor_fm_list', { folder_id: parentId }, { quiet: true });
                const want = name.toLowerCase();
                const hit = ((res && res.data && res.data.folders) || []).find(function (f) {
                    return String(f.name).trim().toLowerCase() === want;
                });
                return hit ? Number(hit.id) : 0;
            } catch (e) { return 0; }
        }

        // Reuse a same-name child or create it. Resolves the folder id, or 0 with the reason in `fail`.
        async function ensureChild(parentId, name, fail) {
            const known = knownChild(parentId, name);
            if (known) return known;
            try {
                const res = await C.api('anchor_fm_create_folder', { parent_id: parentId, name: name }, { quiet: true });
                fail.created = true;
                return Number(res && res.data && res.data.folderId) || 0;
            } catch (xhr) {
                const again = await listedChild(parentId, name);
                if (again) return again;
                fail.message = C.errMessage(xhr, null, 'Could not create a folder.');
                return 0;
            }
        }

        // entries: [{dirs:[...names], file:File|null}] (file null = an empty folder). Creates the folders,
        // resolves with {jobs, rejected, created}. Files whose folder could not be made are rejected.
        async function buildFolderJobs(entries, rootId, job, $p) {
            const cache = {};
            const jobs = [], rejected = [], fail = { created: false, message: '' };
            let unbuilt = 0;
            for (let i = 0; i < entries.length; i++) {
                const e = entries[i];
                if (job.cancelled) { if (e.file) unbuilt++; continue; }
                setProgress($p, 0, 'Preparing folders…');
                let parent = rootId;
                for (let d = 0; d < e.dirs.length && parent; d++) {
                    const key = parent + '/' + e.dirs[d].toLowerCase();
                    if (!Object.prototype.hasOwnProperty.call(cache, key)) {
                        cache[key] = await ensureChild(parent, e.dirs[d], fail);
                    }
                    parent = cache[key];
                }
                if (!e.file) continue;
                if (parent) jobs.push({ file: e.file, folderId: parent });
                else rejected.push({ name: e.file.name, reason: 'server' });
            }
            return { jobs: jobs, rejected: rejected, created: fail.created, message: fail.message, unbuilt: unbuilt };
        }

        // ---- result toast -------------------------------------------------------

        function summarize(out, folderMessage) {
            const parts = [];
            const n = out.uploaded.length;
            if (n) parts.push(n + ' uploaded');
            if (out.expired) parts.push('Your session expired. Reload the page');
            if (out.cancelled) parts.push('Cancelled');
            if (out.notSent) parts.push(out.notSent + ' not sent');
            const rej = out.rejected;
            if (rej.length) {
                const shown = rej.slice(0, MAX_NAMES_IN_TOAST).map(function (r) {
                    return (r && r.name ? r.name : 'A file') + ' (' + (REASON_TEXT[r && r.reason] || REASON_TEXT.server) + ')';
                });
                let s = 'Not uploaded: ' + shown.join(', ');
                if (rej.length > shown.length) s += ' and ' + (rej.length - shown.length) + ' more';
                parts.push(s);
            }
            if (folderMessage) parts.push(folderMessage);
            if (!parts.length) parts.push('Nothing to upload');
            return parts.join('. ') + '.';
        }

        // ---- public entry points -----------------------------------------------

        // Where an upload goes: an explicit folder, else the open one. '' + a toast when there is none.
        function resolveTarget(folderId) {
            if (!AnchorFM.isAdmin || !C.filesTabActive()) return 0;
            const explicit = Number(folderId) > 0 ? Number(folderId) : 0;
            const searching = !!(state.search && state.search.length >= 2);
            if (!explicit && (state.currentFolderId <= 0 || searching)) {
                C.toast('Open a folder to upload into it');
                return 0;
            }
            if (!explicit && !C.canUploadHere()) return 0;
            return explicit || state.currentFolderId;
        }

        // entries (optional): [{dirs, file}] for a folder upload; otherwise `files` go straight into `target`.
        async function start(target, files, entries, preRejected) {
            if (running) {
                const names = (entries ? entries.filter(function (r) { return r.file; }).map(function (r) { return r.file; }) : files)
                    .map(function (f) { return { name: f.name, reason: 'busy' }; });
                C.toast(summarize({ uploaded: [], rejected: names, cancelled: false, notSent: 0 }, ''), null, 9000);
                return { uploaded: [], rejected: names };
            }
            const job = { cancelled: false, xhr: null };
            running = job;
            const $p = showProgress(job);
            $root.addClass('afm--busy');
            let out, folderMessage = '', created = false;
            try {
                let jobs, pre = preRejected || [], unbuilt = 0;
                if (entries) {
                    const built = await buildFolderJobs(entries, target, job, $p);
                    jobs = built.jobs; pre = built.rejected; created = built.created; folderMessage = built.message; unbuilt = built.unbuilt;
                } else {
                    jobs = files.map(function (f) { return { file: f, folderId: target }; });
                }
                out = await sendJobs(jobs, job, $p);
                out.rejected = pre.concat(out.rejected);
                out.notSent += unbuilt;
                if (job.cancelled) out.cancelled = true;
            } catch (err) {
                out = { uploaded: [], rejected: [], cancelled: false, notSent: 0 };
                folderMessage = 'The upload stopped unexpectedly';
            } finally {
                running = null;
                $p.remove();
                $root.removeClass('afm--busy');
            }
            if (created && !out.uploaded.length && !out.rejected.length && !out.cancelled && !folderMessage) folderMessage = 'Folders created';
            C.toast(summarize(out, folderMessage), null, (out.rejected.length || out.cancelled || out.expired || out.notSent || folderMessage) ? 9000 : 0);
            // New subfolders change the tree (and the open folder's list); otherwise reload only if the
            // folder that got the files is still the one on screen.
            if (created) C.bootstrap({ stay: true });
            else if ((out.uploaded.length || out.cancelled) && state.currentFolderId === target) C.loadFolder(target);
            return { uploaded: out.uploaded, rejected: out.rejected };
        }

        // uploadFiles(fileList|File[], folderId?) -> Promise<{uploaded, rejected}>
        function uploadFiles(files, folderId) {
            const list = Array.from(files || []);
            if (!list.length) return Promise.resolve({ uploaded: [], rejected: [] });
            const target = resolveTarget(folderId);
            if (!target) return Promise.resolve({ uploaded: [], rejected: [] });
            return start(target, list, null);
        }

        // ---- folder sources ------------------------------------------------------

        function readAll(reader) {
            return new Promise(function (resolve, reject) {
                const all = [];
                (function pull() {
                    reader.readEntries(function (batch) {
                        if (!batch.length) return resolve(all);
                        all.push.apply(all, batch); // Chrome hands back at most 100 per call
                        pull();
                    }, reject);
                })();
            });
        }
        function fileOf(entry) {
            return new Promise(function (resolve, reject) { entry.file(resolve, reject); });
        }

        // Flatten one FileSystemEntry into [{dirs, file}] (dirs includes the entry's own folder name).
        async function walk(entry, dirs, out) {
            if (entry.isFile) {
                if (OS_JUNK.test(entry.name)) return;
                try { out.push({ dirs: dirs, file: await fileOf(entry) }); }
                catch (e) { out.unreadable.push(entry.name); }
                return;
            }
            const here = dirs.concat(entry.name);
            const kids = await readAll(entry.createReader());
            if (!kids.length) out.push({ dirs: here, file: null }); // keep empty folders
            for (let i = 0; i < kids.length; i++) await walk(kids[i], here, out);
        }

        // dropEntries(dataTransfer.items, folderId?, dataTransfer.files?) -- call synchronously from the
        // drop handler (the browser empties the DataTransfer once it returns). Plain files take the
        // ordinary path; any folder is walked recursively.
        function dropEntries(items, folderId, fallbackFiles) {
            const target = resolveTarget(folderId);
            if (!target) return Promise.resolve({ uploaded: [], rejected: [] });
            const entries = [];
            Array.from(items || []).forEach(function (it) {
                if (it.kind !== 'file') return;
                const en = typeof it.webkitGetAsEntry === 'function' ? it.webkitGetAsEntry() : null;
                if (en) entries.push(en);
            });
            if (!entries.some(function (e) { return e.isDirectory; })) {
                return uploadFiles(fallbackFiles && fallbackFiles.length ? fallbackFiles : filesOf(items), target);
            }
            return flattenAndStart(entries, target);
        }
        function filesOf(items) {
            return Array.from(items || []).map(function (it) { return it.getAsFile && it.getAsFile(); }).filter(Boolean);
        }
        async function flattenAndStart(entries, target) {
            const flat = [];
            flat.unreadable = [];
            for (let i = 0; i < entries.length; i++) {
                try { await walk(entries[i], [], flat); } catch (e) { flat.unreadable.push(entries[i].name); }
            }
            const unreadable = flat.unreadable.map(function (n) { return { name: n, reason: 'server' }; });
            return start(target, null, flat, unreadable);
        }

        // <input webkitdirectory>: every File carries webkitRelativePath "Root/Sub/name".
        function entriesFromInput(files) {
            const rows = [];
            Array.from(files || []).forEach(function (f) {
                const rel = String(f.webkitRelativePath || '');
                const segs = rel ? rel.split('/').filter(Boolean) : [];
                const dirs = segs.slice(0, -1);
                if (OS_JUNK.test(f.name)) return;
                rows.push({ dirs: dirs, file: f });
            });
            return rows;
        }

        // ---- wiring ---------------------------------------------------------------

        const $fileInput = $root.find('[data-afm-file-input]');
        const $folderInput = $root.find('[data-afm-folder-input]');

        $root.on('click', '[data-afm-action="upload"]', function (e) {
            if (!C.filesTabActive()) { e.preventDefault(); return; }
            $fileInput.trigger('click');
        });
        $root.on('click', '[data-afm-action="upload-folder"]', function (e) {
            if (!C.filesTabActive()) { e.preventDefault(); return; }
            $folderInput.trigger('click');
        });
        $fileInput.on('change', function () {
            const files = Array.from(this.files || []);
            $(this).val('');
            if (C.filesTabActive()) uploadFiles(files);
        });
        $folderInput.on('change', function () {
            const files = Array.from(this.files || []);
            $(this).val('');
            if (!files.length || !C.filesTabActive()) return;
            const target = resolveTarget(0);
            if (target) start(target, null, entriesFromInput(files));
        });

        C.uploadFiles = uploadFiles;
        window.AnchorFMUpload = { uploadFiles: uploadFiles, dropEntries: dropEntries };
    });
})(jQuery);
