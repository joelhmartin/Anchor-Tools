(function($){
    function rowHtml(p){
        return `
          <div class="wiz-post" data-id="${p.ID}">
            <div class="wiz-row">
              <strong>#${p.ID}</strong> <a href="${p.edit}">${escapeHtml(p.title || '(no title)')}</a>
              <span class="anchor-schema-badge">${escapeHtml(p.type)}/${escapeHtml(p.status)}</span>
            </div>
            <div class="wiz-row">
              <label>Type</label>
              <select class="wiz-type">
                <option value="Auto">Auto</option>
                <option value="FAQPage">FAQPage</option>
                <option value="Article">Article</option>
                <option value="Product">Product</option>
                <option value="LocalBusiness">LocalBusiness</option>
                <option value="WebPage">WebPage</option>
              </select>
              <button class="button wiz-scan">Scan</button>
              <button class="button button-primary wiz-generate" disabled>Generate</button>
            </div>
            <div class="wiz-box wiz-scan-results" style="display:none"></div>
            <div class="wiz-box wiz-editor-wrap" style="display:none">
              <label>JSON-LD</label>
              <textarea class="wiz-editor"></textarea>
              <div class="wiz-actions">
                <input type="text" class="wiz-label" placeholder="Label for this schema item" style="flex:1; min-width:200px" />
                <button class="button button-primary wiz-save">Validate and save to post</button>
              </div>
              <div class="wiz-messages"></div>
            </div>
          </div>`;
    }
    function escapeHtml(s){ if(s==null) return ''; return String(s).replace(/[&<>"]{1}/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

    $(document).on('click', '#wiz-load', function(){
        const pt = $('#wiz-post-type').val();
        const st = $('#wiz-status').val();
        const limit = $('#wiz-limit').val();
        $('#wiz-posts').html('<p>Loading...</p>');
        $.post(ACG_WIZ.ajax, { action:'acg_list_posts', nonce:ACG_WIZ.nonce, post_type:pt, status:st, limit:limit })
          .done(function(res){
              if(!res.success){ $('#wiz-posts').html('<p>Failed to load.</p>'); return; }
              const posts = res.data.posts || [];
              if(!posts.length){ $('#wiz-posts').html('<p>No posts found.</p>'); return; }
              const html = posts.map(rowHtml).join('');
              $('#wiz-posts').html(html);
          }).fail(function(){ $('#wiz-posts').html('<p>Error.</p>'); });
    });

    $(document).on('click', '.wiz-scan', function(){
        const $row = $(this).closest('.wiz-post');
        const id = $row.data('id');
        const $scan = $row.find('.wiz-scan-results');
        $scan.show().html('<em>Scanning Divi modules and ACF fields...</em>');
        $.post(ACG_WIZ.ajax, { action:'acg_scan_post', nonce:ACG_WIZ.nonce, post_id:id })
         .done(function(res){
            if(!res.success){ $scan.html('<span class="error">Scan failed.</span>'); return; }
            const d = res.data || {};
            $scan.html(`<p><strong>Suggested type:</strong> ${escapeHtml(d.type || '')}</p>
                        <p><strong>Sources:</strong> title=${escapeHtml(d.sources && d.sources.title || '')}, ACF fields=${escapeHtml((d.sources && d.sources.acf_fields||[]).join(', '))}, Divi FAQ=${d.sources && d.sources.has_divi_faq ? 'yes' : 'no'}</p>
                        <details><summary>Raw used for generation</summary><pre style="white-space:pre-wrap">${escapeHtml(d.raw || '')}</pre></details>`);
            $row.find('.wiz-generate').prop('disabled', false).data('scan', d);
            $row.find('.wiz-type').val('Auto');
         }).fail(function(){ $scan.html('<span class="error">Scan error.</span>'); });
    });

    $(document).on('click', '.wiz-generate', function(){
        const $row = $(this).closest('.wiz-post');
        const id = $row.data('id');
        const chosen = $row.find('.wiz-type').val();
        const scan = $(this).data('scan') || {};
        const type = chosen === 'Auto' ? (scan.type || 'WebPage') : chosen;
        const raw = scan.raw || '';
        const $edit = $row.find('.wiz-editor-wrap');
        const $msg = $row.find('.wiz-messages');
        $msg.empty(); $edit.hide();
        if(!raw){ $msg.html('<span class="error">Scan first.</span>'); return; }
        $(this).text('Generating...').prop('disabled', true);
        $.post(ACG_WIZ.ajax, { action:'acg_generate_for_post', nonce:ACG_WIZ.nonce, post_id:id, type:type, raw:raw })
          .done(function(res){
            if(!res.success){ $msg.html('<span class="error">Generation failed.</span>'); return; }
            $edit.show();
            $row.find('.wiz-editor').val(res.data.json || '');
            $row.find('.wiz-label').val(type + ' schema');
          }).fail(function(){ $msg.html('<span class="error">Generation error.</span>'); })
          .always(function(){ $('.wiz-generate').text('Generate').prop('disabled', false); });
    });

    $(document).on('click', '.wiz-save', function(){
        const $row = $(this).closest('.wiz-post');
        const id = $row.data('id');
        const json = $row.find('.wiz-editor').val();
        const label = $row.find('.wiz-label').val();
        const $msg = $row.find('.wiz-messages');
        $msg.html('<em>Validating and saving...</em>');
        $.post(ACG_WIZ.ajax, { action:'acg_save_for_post', nonce:ACG_WIZ.nonce, post_id:id, json:json, label:label })
          .done(function(res){
            if(!res.success){
                let html = '';
                if(res.data && res.data.errors){ html += '<div class="notice notice-error"><ul>' + res.data.errors.map(e=>'<li>'+e+'</li>').join('') + '</ul></div>'; }
                if(res.data && res.data.warnings && res.data.warnings.length){ html += '<div class="notice notice-warning"><ul>' + res.data.warnings.map(e=>'<li>'+e+'</li>').join('') + '</ul></div>'; }
                $msg.html(html || '<span class="error">Validation failed.</span>');
                return;
            }
            $msg.html('<span class="updated">Saved.</span>');
          }).fail(function(){ $msg.html('<span class="error">Save error.</span>'); });
    });
})(jQuery);
