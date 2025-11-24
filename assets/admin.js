(function($){
    function renderList($root){
        const $list = $root.find('#acg-list');
        const items = JSON.parse($list.attr('data-items') || '[]');
        $list.empty();
        if (!items.length){
            $list.append('<p>No schemas yet. Generate or upload your first one above.</p>');
            return;
        }
        items.forEach(function(it){
            const enabled = it.enabled ? 'checked' : '';
            const html = `
                <div class="schema-item" data-id="${it.id}">
                  <div class="row">
                    <h4 style="flex:1">${escapeHtml(it.label || (it.type + ' schema'))}</h4>
                    <span class="anchor-schema-badge">${escapeHtml(it.type)}</span>
                    <label style="margin-left:auto"><input type="checkbox" class="acg-enabled" ${enabled}> Enabled</label>
                  </div>
                  <div class="row">
                    <label>Label</label>
                    <input type="text" class="acg-label" value="${escapeAttr(it.label || '')}" style="flex:1" />
                  </div>
                  <div class="row">
                    <label>JSON</label>
                  </div>
                  <textarea class="acg-json">${it.json || ''}</textarea>
                  <div class="anchor-schema-actions" style="margin-top:8px">
                    <button type="button" class="button button-primary acg-save">Save</button>
                    <button type="button" class="button button-secondary acg-delete">Delete</button>
                    <span class="updated-at" style="margin-left:auto; opacity:0.7">Updated: ${escapeHtml(it.updated || '')}</span>
                  </div>
                </div>`;
            $list.append(html);
        });
    }
    function escapeHtml(str){
        if (str === null || str === undefined) return '';
        return String(str).replace(/[&<>"]{1}/g, function(s){
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[s]);
        });
    }
    function escapeAttr(str){ return escapeHtml(str).replace(/'/g, '&#039;'); }
    function getItems($root){ try { return JSON.parse($root.find('#acg-list').attr('data-items')||'[]'); } catch(e){ return []; } }
    function setItems($root, items){ $root.find('#acg-list').attr('data-items', JSON.stringify(items)); renderList($root); }

    $(document).on('click', '#acg-generate', function(){
        const $root = $('#acg-root');
        const type = $('#acg-type').val();
        const custom = $('#acg-custom-type').val();
        const raw = $('#acg-raw').val();
        const postId = $root.data('post-id');
        if (!raw){ alert('Please paste some raw text.'); return; }
        $('.anchor-schema-spinner').show();
        $.post(ACG.ajax, { action: 'acg_generate', nonce: ACG.nonce, post_id: postId, type, custom, raw })
        .done(function(res){
            if (!res || !res.success){ console.error(res); alert(ACG.strings.error); return; }
            const items = getItems($root); items.push(res.data.item); setItems($root, items); $('#acg-raw').val('');
        }).fail(function(xhr){ console.error(xhr.responseText); alert(ACG.strings.error); })
        .always(function(){ $('.anchor-schema-spinner').hide(); });
    });

    $(document).on('click', '.acg-save', function(){
        const $root = $('#acg-root');
        const $item = $(this).closest('.schema-item');
        const postId = $root.data('post-id');
        const id = $item.data('id');
        const data = { label: $item.find('.acg-label').val(), enabled: $item.find('.acg-enabled').is(':checked'), json: $item.find('.acg-json').val() };
        const $btn = $(this); $btn.text(ACG.strings.saving);
        $.post(ACG.ajax, { action:'acg_update_item', nonce: ACG.nonce, post_id: postId, id, data })
         .done(function(res){ if (!res || !res.success){ console.error(res); alert(ACG.strings.error); return; } setItems($root, res.data.items); })
         .fail(function(xhr){ console.error(xhr.responseText); alert(ACG.strings.error); })
         .always(function(){ $btn.text('Save'); });
    });

    $(document).on('click', '.acg-delete', function(){
        if (!confirm('Delete this schema item')) return;
        const $root = $('#acg-root');
        const $item = $(this).closest('.schema-item');
        const postId = $root.data('post-id');
        const id = $item.data('id');
        $.post(ACG.ajax, { action:'acg_delete_item', nonce: ACG.nonce, post_id: postId, id })
         .done(function(res){ if (!res || !res.success){ console.error(res); alert(ACG.strings.error); return; } setItems($root, res.data.items); })
         .fail(function(xhr){ console.error(xhr.responseText); alert(ACG.strings.error); });
    });

    // Upload and validate
    $(document).on('click', '#acg-upload', function(){
        const $root = $('#acg-root');
        const $file = $('#acg-file')[0];
        const $msg  = $('#acg-upload-messages');
        $msg.empty();
        if (!$file.files.length){ $msg.text('Choose a .json file first.'); return; }
        const f = $file.files[0];
        if (f.size > 2 * 1024 * 1024){ $msg.text('File is larger than 2MB.'); return; }
        const reader = new FileReader();
        reader.onload = function(){
            $.post(ACG.ajax, { action:'acg_upload', nonce:ACG.nonce, post_id:$root.data('post-id'), filename:f.name, content:reader.result })
              .done(function(res){
                if (!res){ $msg.text('No response.'); return; }
                if (!res.success){
                    let html = '';
                    if (res.data && res.data.errors){
                        html += '<div class="notice notice-error"><p><strong>Errors</strong></p><ul>' + res.data.errors.map(function(e){ return '<li>' + e + '</li>'; }).join('') + '</ul></div>';
                    }
                    if (res.data && res.data.warnings && res.data.warnings.length){
                        html += '<div class="notice notice-warning"><p><strong>Warnings</strong></p><ul>' + res.data.warnings.map(function(e){ return '<li>' + e + '</li>'; }).join('') + '</ul></div>';
                    }
                    $msg.html(html || 'Validation failed.');
                    return;
                }
                const items = getItems($root);
                items.push(res.data.item);
                setItems($root, items);
                let html = '<div class="notice notice-success"><p>' + ACG.strings.validOk + '</p></div>';
                if (res.data.warnings && res.data.warnings.length){
                    html += '<div class="notice notice-warning"><p><strong>Warnings</strong></p><ul>' + res.data.warnings.map(function(e){ return '<li>' + e + '</li>'; }).join('') + '</ul></div>';
                }
                $msg.html(html);
                $('#acg-file').val('');
            }).fail(function(xhr){
                console.error(xhr.responseText);
                $msg.text('Upload failed.');
            });
        };
        reader.readAsText(f);
    });

    $(function(){ const $root = $('#acg-root'); if ($root.length){ renderList($root); } });
})(jQuery);
