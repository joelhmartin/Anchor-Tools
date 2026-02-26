(function($){
    if (typeof ASF === 'undefined') return;

    var $platformRadios = $('input[name="asf_platform"]');
    var $layoutSelect   = $('select[name="asf_layout"]');
    var previewTimer    = null;

    /* ── Platform conditional fields ── */
    function togglePlatformFields() {
        var val = $('input[name="asf_platform"]:checked').val() || '';
        $('.asf-platform-fields').removeClass('is-active');
        $('.asf-platform-fields[data-platform="' + val + '"]').addClass('is-active');
        toggleSettingVisibility();
    }

    /* ── Layout/platform conditional settings ── */
    function toggleSettingVisibility() {
        var platform = $('input[name="asf_platform"]:checked').val() || '';
        var layout   = $layoutSelect.val() || 'grid';

        $('.asf-setting-row[data-show-for-platform]').each(function(){
            var allowed = $(this).data('show-for-platform').split(',');
            $(this).toggleClass('is-hidden', allowed.indexOf(platform) === -1);
        });
        $('.asf-setting-row[data-show-for-layout]').each(function(){
            var allowed = $(this).data('show-for-layout').split(',');
            $(this).toggleClass('is-hidden', allowed.indexOf(layout) === -1);
        });
    }

    $platformRadios.on('change', togglePlatformFields);
    $layoutSelect.on('change', toggleSettingVisibility);
    togglePlatformFields();

    /* ── AJAX Live Preview ── */
    function refreshPreview() {
        var $wrap = $('#asf-preview-wrap');
        if (!$wrap.length) return;

        $wrap.html('<span class="spinner is-active"></span>');

        var data = {
            action: 'anchor_social_feed_preview',
            nonce:  ASF.nonce,
            post_id: ASF.postId
        };

        // Gather all asf_ fields from the form
        $('#post input[name^="asf_"], #post select[name^="asf_"], #post textarea[name^="asf_"]').each(function(){
            var $el = $(this);
            var name = $el.attr('name');
            if ($el.is(':radio') && !$el.is(':checked')) return;
            if ($el.is(':checkbox')) {
                data[name] = $el.is(':checked') ? '1' : '0';
            } else {
                data[name] = $el.val();
            }
        });

        $.post(ASF.ajaxUrl, data, function(res){
            if (res.success && res.data) {
                $wrap.html(res.data);
            } else {
                $wrap.html('<p class="asf-preview-note">Preview unavailable.</p>');
            }
        }).fail(function(){
            $wrap.html('<p class="asf-preview-note">Preview request failed.</p>');
        });
    }

    // Debounced preview on any setting change
    $('#post').on('change input', 'input[name^="asf_"], select[name^="asf_"], textarea[name^="asf_"]', function(){
        clearTimeout(previewTimer);
        previewTimer = setTimeout(refreshPreview, 800);
    });

    /* ── Fetch Profile from API ── */
    $(document).on('click', '.asf-fetch-profile-btn button', function(e){
        e.preventDefault();
        var $btn = $(this);
        var $spinner = $btn.siblings('.spinner');
        $spinner.addClass('is-active');
        $btn.prop('disabled', true);

        $.post(ASF.ajaxUrl, {
            action:   'anchor_social_feed_fetch_profile',
            nonce:    ASF.nonce,
            post_id:  ASF.postId,
            platform: $('input[name="asf_platform"]:checked').val() || '',
            // Send current platform field values
            asf_yt_channel_id: $('input[name="asf_yt_channel_id"]').val() || '',
            asf_fb_page_id:    $('input[name="asf_fb_page_id"]').val() || '',
            asf_ig_username:   $('input[name="asf_ig_username"]').val() || '',
            asf_tt_username:   $('input[name="asf_tt_username"]').val() || ''
        }, function(res){
            $spinner.removeClass('is-active');
            $btn.prop('disabled', false);
            if (res.success && res.data) {
                var d = res.data;
                if (d.avatar_url)    $('input[name="asf_profile_avatar_url"]').val(d.avatar_url);
                if (d.display_name)  $('input[name="asf_profile_display_name"]').val(d.display_name);
                if (d.handle)        $('input[name="asf_profile_handle"]').val(d.handle);
                if (d.profile_url)   $('input[name="asf_profile_url"]').val(d.profile_url);
                if (d.followers)     $('input[name="asf_profile_followers"]').val(d.followers);
                if (d.posts)         $('input[name="asf_profile_posts"]').val(d.posts);
                if (d.following)     $('input[name="asf_profile_following"]').val(d.following);
            } else {
                alert(res.data || 'Could not fetch profile data.');
            }
        }).fail(function(){
            $spinner.removeClass('is-active');
            $btn.prop('disabled', false);
            alert('Request failed.');
        });
    });

})(jQuery);
