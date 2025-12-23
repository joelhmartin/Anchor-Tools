(function($){
    if (typeof ANCHOR_REVIEWS === 'undefined') {
        return;
    }

    var $search = $('#anchor-reviews-search');
    if (!$search.length) {
        return;
    }

    var $results = $('#anchor-reviews-results');
    var $placeId = $('#anchor-reviews-place-id');
    var $selected = $('#anchor-reviews-selected');
    var timer = null;

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str).replace(/[&<>"]/g, function(s){
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[s]);
        });
    }

    function escapeAttr(str) {
        return escapeHtml(str).replace(/'/g, '&#039;');
    }

    function showMessage(msg) {
        $results.html('<div class="anchor-reviews-empty">' + escapeHtml(msg) + '</div>').show();
    }

    function renderResults(items) {
        if (!items || !items.length) {
            showMessage(ANCHOR_REVIEWS.strings.empty);
            return;
        }
        var html = '';
        items.forEach(function(it){
            var name = it.name || '';
            var address = it.address || '';
            var status = it.business_status || '';
            var statusText = status ? status.replace(/_/g, ' ') : '';
            html += '<div class="anchor-reviews-result" data-place-id="' + escapeAttr(it.place_id || '') + '" data-place-name="' + escapeAttr(name) + '" data-place-address="' + escapeAttr(address) + '">';
            html += '<div class="anchor-reviews-meta">';
            html += '<div class="anchor-reviews-name">' + escapeHtml(name) + '</div>';
            if (address) {
                html += '<div class="anchor-reviews-address">' + escapeHtml(address) + '</div>';
            }
            if (statusText) {
                html += '<div class="anchor-reviews-status">' + escapeHtml(statusText) + '</div>';
            }
            html += '</div>';
            html += '<button type="button" class="button anchor-reviews-select">Select</button>';
            html += '</div>';
        });
        $results.html(html).show();
    }

    function runSearch(query) {
        $results.html('<div class="anchor-reviews-loading">Searching...</div>').show();
        $.post(ANCHOR_REVIEWS.ajax, {
            action: 'anchor_reviews_place_search',
            nonce: ANCHOR_REVIEWS.nonce,
            query: query
        }).done(function(res){
            if (!res || !res.success) {
                var msg = (res && res.data && res.data.message) ? res.data.message : '';
                if (msg === 'missing_key') {
                    showMessage(ANCHOR_REVIEWS.strings.noKey);
                } else {
                    showMessage(ANCHOR_REVIEWS.strings.error);
                }
                return;
            }
            renderResults(res.data.results || []);
        }).fail(function(){
            showMessage(ANCHOR_REVIEWS.strings.error);
        });
    }

    $search.on('input', function(){
        var q = $.trim($search.val());
        if (!q.length) {
            $results.hide().empty();
            return;
        }
        if (q.length < 3) {
            showMessage(ANCHOR_REVIEWS.strings.short);
            return;
        }
        if (timer) {
            clearTimeout(timer);
        }
        timer = setTimeout(function(){
            runSearch(q);
        }, 350);
    });

    $results.on('click', '.anchor-reviews-select', function(){
        var $row = $(this).closest('.anchor-reviews-result');
        var placeId = $row.data('place-id') || '';
        var name = $row.data('place-name') || '';
        var address = $row.data('place-address') || '';
        if (placeId) {
            $placeId.val(placeId);
        }
        if ($selected.length) {
            var label = name || placeId;
            if (address) {
                label += ' - ' + address;
            }
            $selected.text(ANCHOR_REVIEWS.strings.selected + ' ' + label);
        }
        $results.hide().empty();
    });
})(jQuery);
