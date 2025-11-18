(function($){
    function renderList($root){
        const $list = $root.find('#anchor-schema-list');
        const items = JSON.parse($list.attr('data-items') || '[]');
        $list.empty();
        if (!items.length){
            $list.append('<p>No schemas yet. Generate your first one above or upload a JSON file.</p>');
            return;
        }
        items.forEach(function(it){
            const enabled = it.enabled ? 'checked' : '';
            const html = `
                <div class="schema-item" data-id="${it.id}">
                  <div class="row">
                    <h4 style="flex:1">${escapeHtml(it.label || (it.type + ' schema'))}</h4>
                    <span class="anchor-schema-badge">${escapeHtml(it.type)}</span>
                    <label style="margin-left:auto"><input type="checkbox" class="anchor-schema-enabled" ${enabled}> Enabled</label>
                  </div>
                  <div class="row">
                    <label>Label</label>
                    <input type="text" class="anchor-schema-label" value="${escapeAttr(it.label || '')}" style="flex:1" />
                  </div>
                  <div class="row">
                    <label>JSON</label>
                  </div>
                  <textarea class="anchor-schema-json">${it.json || ''}</textarea>
                  <div class="anchor-schema-actions" style="margin-top:8px">
                    <button type="button" class="button button-primary anchor-schema-save">Save</button>
                    <button type="button" class="button button-secondary anchor-schema-delete">Delete</button>
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
    function escapeAttr(str){
        return escapeHtml(str).replace(/'/g, '&#039;');
    }

    function getItems($root){
        const raw = $root.find('#anchor-schema-list').attr('data-items') || '[]';
        try { return JSON.parse(raw); } catch(e){ return []; }
    }
    function setItems($root, items){
        $root.find('#anchor-schema-list').attr('data-items', JSON.stringify(items));
        renderList($root);
    }

    $(document).on('click', '#anchor-schema-generate', function(){
        const $root = $('#anchor-schema-root');
        const type = $('#anchor-schema-type').val();
        const custom = $('#anchor-schema-custom-type').val();
        const raw = $('#anchor-schema-raw').val();
        const postId = $root.data('post-id');
        if (!raw){ alert('Please paste some raw text.'); return; }
        $('.anchor-schema-spinner').show();
        $.post(ANCHOR_SCHEMA.ajax, {
            action: 'anchor_schema_generate',
            nonce: ANCHOR_SCHEMA.nonce,
            post_id: postId,
            type: type,
            custom: custom,
            raw: raw
        }).done(function(res){
            if (!res || !res.success){ console.error(res); alert(ANCHOR_SCHEMA.strings.error); return; }
            const items = getItems($root);
            items.push(res.data.item);
            setItems($root, items);
            $('#anchor-schema-raw').val('');
        }).fail(function(xhr){
            console.error(xhr.responseText);
            alert(ANCHOR_SCHEMA.strings.error);
        }).always(function(){
            $('.anchor-schema-spinner').hide();
        });
    });

    $(document).on('click', '.anchor-schema-save', function(){
        const $root = $('#anchor-schema-root');
        const $item = $(this).closest('.schema-item');
        const postId = $root.data('post-id');
        const id = $item.data('id');
        const data = {
            label: $item.find('.anchor-schema-label').val(),
            enabled: $item.find('.anchor-schema-enabled').is(':checked'),
            json: $item.find('.anchor-schema-json').val()
        };
        $(this).text(ANCHOR_SCHEMA.strings.saving);
        $.post(ANCHOR_SCHEMA.ajax, {
            action: 'anchor_schema_update_item',
            nonce: ANCHOR_SCHEMA.nonce,
            post_id: postId,
            id: id,
            data: data
        }).done(function(res){
            if (!res || !res.success){ console.error(res); alert(ANCHOR_SCHEMA.strings.error); return; }
            setItems($root, res.data.items);
        }).fail(function(xhr){
            console.error(xhr.responseText);
            alert(ANCHOR_SCHEMA.strings.error);
        }).always(function(){
            $(this).text('Save');
        }.bind(this));
    });

    $(document).on('click', '.anchor-schema-delete', function(){
        if (!confirm('Delete this schema item')) return;
        const $root = $('#anchor-schema-root');
        const $item = $(this).closest('.schema-item');
        const postId = $root.data('post-id');
        const id = $item.data('id');
        $.post(ANCHOR_SCHEMA.ajax, {
            action: 'anchor_schema_delete_item',
            nonce: ANCHOR_SCHEMA.nonce,
            post_id: postId,
            id: id
        }).done(function(res){
            if (!res || !res.success){ console.error(res); alert(ANCHOR_SCHEMA.strings.error); return; }
            setItems($root, res.data.items);
        }).fail(function(xhr){
            console.error(xhr.responseText);
            alert(ANCHOR_SCHEMA.strings.error);
        });
    });

    $(document).on('click', '#anchor-schema-upload', function(){
        const $root = $('#anchor-schema-root');
        const $file = $('#anchor-schema-file')[0];
        const $msg  = $('#anchor-schema-upload-messages');
        $msg.empty();
        if (!$file.files.length){ $msg.text('Choose a .json file first.'); return; }
        const f = $file.files[0];
        if (f.size > 2 * 1024 * 1024){ $msg.text('File is larger than 2MB.'); return; }
        const reader = new FileReader();
        reader.onload = function(){
            $.post(ANCHOR_SCHEMA.ajax, {
                action: 'anchor_schema_upload',
                nonce: ANCHOR_SCHEMA.nonce,
                post_id: $root.data('post-id'),
                filename: f.name,
                content: reader.result
            }).done(function(res){
                if (!res){ $msg.text('No response.'); return; }
                if (!res.success){
                    let html = '';
                    if (res.data && res.data.errors){
                        html += '<div class="notice notice-error"><p><strong>Errors</strong></p><ul>' + res.data.errors.map(function(e){ return '<li>' + escapeHtml(e) + '</li>'; }).join('') + '</ul></div>';
                    }
                    if (res.data && res.data.warnings && res.data.warnings.length){
                        html += '<div class="notice notice-warning"><p><strong>Warnings</strong></p><ul>' + res.data.warnings.map(function(e){ return '<li>' + escapeHtml(e) + '</li>'; }).join('') + '</ul></div>';
                    }
                    $msg.html(html || 'Validation failed.');
                    return;
                }
                const items = getItems($root);
                items.push(res.data.item);
                setItems($root, items);
                let html = '<div class="notice notice-success"><p>' + ANCHOR_SCHEMA.strings.validOk + '</p></div>';
                if (res.data.warnings && res.data.warnings.length){
                    html += '<div class="notice notice-warning"><p><strong>Warnings</strong></p><ul>' + res.data.warnings.map(function(e){ return '<li>' + escapeHtml(e) + '</li>'; }).join('') + '</ul></div>';
                }
                $msg.html(html);
                $('#anchor-schema-file').val('');
            }).fail(function(xhr){
                console.error(xhr.responseText);
                $msg.text('Upload failed.');
            });
        };
        reader.readAsText(f);
    });

    $(function(){
        const $root = $('#anchor-schema-root');
        if ($root.length){ renderList($root); }
    });
})(jQuery);
