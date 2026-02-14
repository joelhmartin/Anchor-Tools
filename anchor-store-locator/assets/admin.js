(function($){
    var cfg = window.ANCHOR_STORE_LOCATOR_ADMIN || null;
    if (!cfg) {
        return;
    }

    var $search = $('#anchor-store-search');
    var $results = $('#anchor-store-results');
    var $status = $('#anchor-store-status');
    var debounce = null;

    var isPostEditor = !!$('#anchor_store_address').length;

    var fields = {
        placeId: $('#anchor-store-place-id'),
        title: $('#anchor-store-title'),
        address: $('#anchor-store-address'),
        lat: $('#anchor-store-lat'),
        lng: $('#anchor-store-lng'),
        website: $('#anchor-store-website'),
        phone: $('#anchor-store-phone'),
        email: $('#anchor-store-email'),
        mapsUrl: $('#anchor-store-maps')
    };

    if (isPostEditor) {
        fields = {
            placeId: $('#anchor-store-place-id'),
            title:   null,
            address: $('#anchor_store_address'),
            lat:     $('#anchor_store_lat'),
            lng:     $('#anchor_store_lng'),
            website: $('#anchor_store_website'),
            phone:   $('#anchor_store_phone'),
            email:   $('#anchor_store_email'),
            mapsUrl: $('#anchor_store_maps_url')
        };
    }

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str).replace(/[&<>"]/g, function(s){
            return ({'&':'&amp;','<':'&lt;','>':'&gt;'}[s]);
        });
    }

    function escapeAttr(str) {
        return escapeHtml(str).replace(/'/g, '&#039;');
    }

    function setStatus(msg, type) {
        if (!$status.length) return;
        $status.removeClass('is-error is-success');
        if (!msg) {
            $status.text('');
            return;
        }
        if (type) {
            $status.addClass('is-' + type);
        }
        $status.text(msg);
    }

    function renderResults(items) {
        if (!$results.length) return;
        if (!items || !items.length) {
            $results.html('<div class="anchor-store-empty">' + escapeHtml(cfg.strings.noResults || '') + '</div>').show();
            return;
        }
        var html = '';
        items.forEach(function(item){
            var placeId = item.place_id || '';
            var name = item.name || '';
            var address = item.address || '';
            var status = item.business_status || '';
            var statusText = status ? status.replace(/_/g, ' ') : '';
            html += '<div class="anchor-store-result" data-place-id="' + escapeAttr(placeId) + '">';
            html += '<div class="anchor-store-meta">';
            html += '<div class="anchor-store-name">' + escapeHtml(name) + '</div>';
            if (address) {
                html += '<div class="anchor-store-address">' + escapeHtml(address) + '</div>';
            }
            if (statusText) {
                html += '<span class="anchor-store-badge">' + escapeHtml(statusText) + '</span>';
            }
            html += '</div>';
            html += '<button type="button" class="button anchor-store-result-select">' + escapeHtml(cfg.strings.useListing || 'Use listing') + '</button>';
            html += '</div>';
        });
        $results.html(html).show();
    }

    function runSearch(query) {
        setStatus(cfg.strings.searching || '', null);
        $results.hide().empty();

        $.post(cfg.ajax, {
            action: 'anchor_store_locator_place_search',
            nonce: cfg.nonce,
            query: query
        }).done(function(res){
            if (!res || !res.success) {
                var msg = res && res.data && res.data.message;
                if (msg === 'missing_key') {
                    setStatus(cfg.strings.missingKey || '', 'error');
                } else if (msg === 'too_short') {
                    setStatus(cfg.strings.tooShort || '', 'error');
                } else {
                    setStatus(cfg.strings.searchError || '', 'error');
                }
                return;
            }
            renderResults(res.data.results || []);
            setStatus('', null);
        }).fail(function(){
            setStatus(cfg.strings.searchError || '', 'error');
        });
    }

    function fetchDetails(placeId) {
        if (!placeId) {
            return;
        }
        setStatus(cfg.strings.loadingDetails || '', null);
        $.post(cfg.ajax, {
            action: 'anchor_store_locator_place_details',
            nonce: cfg.nonce,
            place_id: placeId
        }).done(function(res){
            if (!res || !res.success || !res.data || !res.data.details) {
                setStatus(cfg.strings.detailsError || '', 'error');
                return;
            }
            fillForm(res.data.details);
            setStatus(cfg.strings.prefilled || '', 'success');
        }).fail(function(){
            setStatus(cfg.strings.detailsError || '', 'error');
        });
    }

    function fillForm(details) {
        if (fields.placeId && fields.placeId.length) fields.placeId.val(details.place_id || '');
        if (fields.title && fields.title.length && details.name) fields.title.val(details.name);
        if (fields.address && fields.address.length && details.address) fields.address.val(details.address);
        if (fields.lat && fields.lat.length && details.lat) fields.lat.val(details.lat);
        if (fields.lng && fields.lng.length && details.lng) fields.lng.val(details.lng);
        if (fields.website && fields.website.length) fields.website.val(details.website || '');
        if (fields.phone && fields.phone.length) fields.phone.val(details.phone || '');
        if (fields.mapsUrl && fields.mapsUrl.length) fields.mapsUrl.val(details.maps_url || '');

        if (isPostEditor && details.name) {
            var $wpTitle = $('#title');
            if ($wpTitle.length) $wpTitle.val(details.name).trigger('change');
        }
    }

    $search.on('input', function(){
        var q = $.trim($search.val());
        if (!q.length) {
            $results.hide().empty();
            setStatus('', null);
            return;
        }
        if (q.length < 3) {
            setStatus(cfg.strings.tooShort || '', 'error');
            return;
        }
        if (debounce) {
            clearTimeout(debounce);
        }
        debounce = setTimeout(function(){
            runSearch(q);
        }, 350);
    });

    $results.on('click', '.anchor-store-result-select', function(){
        var $row = $(this).closest('.anchor-store-result');
        var placeId = $row.data('place-id') || '';
        fetchDetails(placeId);
    });
})(jQuery);
