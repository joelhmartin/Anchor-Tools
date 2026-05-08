/**
 * Anchor Slider — admin builder JS.
 *
 * Manages the slide list (add / edit / remove / reorder) and the side
 * panel form. Slide state is stored in hidden inputs as JSON, so the
 * existing $_POST['as_slides'] handler picks them up on save.
 */
(function ($) {
    'use strict';

    var $list = $('#as-slide-list');
    var $panel = $('#as-slide-panel');
    if (!$list.length || !$panel.length) return;

    var mediaFrames = {};

    function blankSlide(type) {
        return {
            type: type || 'html',
            title: '',
            html: '',
            attachment_id: 0,
            url: '',
            fullwidth: false,
            align: 'center',
            vertical: 'middle',
            background: { type: 'none', color: '', attachment_id: 0, overlay: '' },
            link: { url: '', text: '' },
            visibility: { desktop: false, tablet: false, mobile: false }
        };
    }

    function nextIndex() {
        var max = -1;
        $list.find('.anchor-builder__item-card').each(function () {
            var i = parseInt($(this).data('index'), 10);
            if (!isNaN(i) && i > max) max = i;
        });
        return max + 1;
    }

    function reindex() {
        $list.find('.anchor-builder__item-card').each(function (i) {
            var $card = $(this);
            $card.data('index', i).attr('data-index', i);
            $card.find('.as-slide-data').attr('name', 'as_slides[' + i + ']');
        });
    }

    function renderCardHtml(index, slide) {
        var label = slide.title || (slide.type.charAt(0).toUpperCase() + slide.type.slice(1) + ' slide');
        var thumb = '';
        if (slide.background && slide.background.attachment_id) {
            // Will be filled via media frame on edit; for newly-added we leave it blank.
        }
        var html = '<div class="anchor-builder__item-card" data-index="' + index + '" draggable="true">' +
            '<span class="anchor-builder__item-handle">⋮⋮</span>' +
            '<div class="anchor-builder__item-thumb"></div>' +
            '<div class="anchor-builder__item-body">' +
                '<div class="anchor-builder__item-title">' + escapeHtml(label) + '</div>' +
                '<div class="anchor-builder__item-meta">' + escapeHtml(slide.type.charAt(0).toUpperCase() + slide.type.slice(1)) + '</div>' +
            '</div>' +
            '<div class="anchor-builder__item-actions">' +
                '<button type="button" class="button-link" data-action="edit-slide" data-index="' + index + '">Edit</button>' +
                '<button type="button" class="button-link button-link-delete" data-action="remove-slide" data-index="' + index + '">Remove</button>' +
            '</div>' +
            '<input type="hidden" class="as-slide-data" name="as_slides[' + index + ']" value="' + escapeAttr(JSON.stringify(slide)) + '" />' +
        '</div>';
        return html;
    }

    function escapeHtml(s) {
        return $('<div>').text(s == null ? '' : String(s)).html();
    }
    function escapeAttr(s) {
        return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
    }

    function getSlideData($card) {
        try {
            return JSON.parse($card.find('.as-slide-data').val() || '{}');
        } catch (e) {
            return {};
        }
    }
    function setSlideData($card, slide) {
        $card.find('.as-slide-data').val(JSON.stringify(slide));
        var label = slide.title || (slide.type.charAt(0).toUpperCase() + slide.type.slice(1) + ' slide');
        $card.find('.anchor-builder__item-title').text(label);
        $card.find('.anchor-builder__item-meta').text(slide.type.charAt(0).toUpperCase() + slide.type.slice(1));

        // Update thumb if there's a background image — we need to fetch the URL
        if (slide.background && slide.background.attachment_id && slide.__bg_thumb_url) {
            $card.find('.anchor-builder__item-thumb').css('background-image', 'url(' + slide.__bg_thumb_url + ')');
        } else {
            $card.find('.anchor-builder__item-thumb').css('background-image', '');
        }
    }

    // ── Add / Remove ──
    $(document).on('click', '[data-action="add-slide"]', function () {
        var type = $(this).data('type') || 'html';
        var idx = nextIndex();
        var slide = blankSlide(type);
        $list.append(renderCardHtml(idx, slide));
        openPanel(idx, slide);
    });

    $(document).on('click', '[data-action="remove-slide"]', function (e) {
        e.stopPropagation();
        var $card = $(this).closest('.anchor-builder__item-card');
        if (window.confirm('Remove this slide?')) {
            $card.remove();
            reindex();
        }
    });

    // ── Edit ──
    $(document).on('click', '[data-action="edit-slide"]', function () {
        var idx = $(this).data('index');
        var $card = $list.find('.anchor-builder__item-card[data-index="' + idx + '"]');
        var slide = getSlideData($card);
        openPanel(idx, slide);
    });

    function openPanel(index, slide) {
        $('#as-edit-index').val(index);
        $('#as-f-title').val(slide.title || '');
        $('#as-f-type').val(slide.type || 'html');
        $('#as-f-html').val(slide.html || '');
        $('#as-f-image-id').val(slide.attachment_id || 0);
        $('#as-f-video-url').val(slide.url || '');

        var bg = slide.background || {};
        $('#as-f-bg-type').val(bg.type || 'none');
        $('#as-f-bg-color').val(bg.color || '');
        $('#as-f-bg-image-id').val(bg.attachment_id || 0);
        $('#as-f-bg-overlay').val(bg.overlay || '');

        $('#as-f-fullwidth').prop('checked', !!slide.fullwidth);
        $('#as-f-align').val(slide.align || 'center');
        $('#as-f-vertical').val(slide.vertical || 'middle');

        var link = slide.link || {};
        $('#as-f-link-url').val(link.url || '');
        $('#as-f-link-text').val(link.text || '');

        var vis = slide.visibility || {};
        $('#as-f-hide-desktop').prop('checked', !!vis.desktop);
        $('#as-f-hide-tablet').prop('checked', !!vis.tablet);
        $('#as-f-hide-mobile').prop('checked', !!vis.mobile);

        // Image previews
        updateImagePreview('#as-f-image-id', '#as-f-image-preview');
        updateImagePreview('#as-f-bg-image-id', '#as-f-bg-image-preview');

        syncTypeFields();
        syncBgFields();
        $panel.addClass('is-open').attr('aria-hidden', 'false');
    }

    function syncTypeFields() {
        var type = $('#as-f-type').val();
        $panel.find('[data-type-fields]').each(function () {
            $(this).toggle($(this).data('type-fields') === type);
        });
    }
    function syncBgFields() {
        var bg = $('#as-f-bg-type').val();
        $panel.find('[data-bg-fields]').each(function () {
            $(this).toggle($(this).data('bg-fields') === bg);
        });
    }
    $(document).on('change', '#as-f-type', syncTypeFields);
    $(document).on('change', '#as-f-bg-type', syncBgFields);

    function updateImagePreview(idSel, previewSel) {
        var id = parseInt($(idSel).val(), 10);
        var $img = $(previewSel);
        if (!id) {
            $img.attr('src', '').hide();
            return;
        }
        // Use REST API to get attachment URL
        $.get(window.ajaxurl || '/wp-admin/admin-ajax.php', { action: 'as_get_attachment_url', id: id }).done(function (resp) {
            if (resp && resp.url) {
                $img.attr('src', resp.url).show();
            }
        });
    }

    function pickMedia(targetId, previewSel, callback) {
        if (mediaFrames[targetId]) {
            mediaFrames[targetId].open();
            return;
        }
        mediaFrames[targetId] = wp.media({
            title: 'Choose Image',
            multiple: false,
            library: { type: 'image' }
        });
        mediaFrames[targetId].on('select', function () {
            var att = mediaFrames[targetId].state().get('selection').first().toJSON();
            $('#' + targetId).val(att.id);
            if (previewSel) {
                $(previewSel).attr('src', att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url).show();
            }
            if (typeof callback === 'function') callback(att);
        });
        mediaFrames[targetId].open();
    }

    $(document).on('click', '#as-f-image-pick', function () {
        pickMedia('as-f-image-id', '#as-f-image-preview');
    });
    $(document).on('click', '#as-f-image-clear', function () {
        $('#as-f-image-id').val(0);
        $('#as-f-image-preview').attr('src', '').hide();
    });
    $(document).on('click', '#as-f-bg-image-pick', function () {
        pickMedia('as-f-bg-image-id', '#as-f-bg-image-preview');
    });
    $(document).on('click', '#as-f-bg-image-clear', function () {
        $('#as-f-bg-image-id').val(0);
        $('#as-f-bg-image-preview').attr('src', '').hide();
    });

    // ── Apply (save panel back to card) ──
    $(document).on('click', '#as-f-save', function () {
        var idx = parseInt($('#as-edit-index').val(), 10);
        var $card = $list.find('.anchor-builder__item-card[data-index="' + idx + '"]');
        if (!$card.length) return;

        var slide = {
            type: $('#as-f-type').val(),
            title: $('#as-f-title').val(),
            html: $('#as-f-html').val(),
            attachment_id: parseInt($('#as-f-image-id').val(), 10) || 0,
            url: $('#as-f-video-url').val(),
            fullwidth: $('#as-f-fullwidth').is(':checked'),
            align: $('#as-f-align').val(),
            vertical: $('#as-f-vertical').val(),
            background: {
                type: $('#as-f-bg-type').val(),
                color: $('#as-f-bg-color').val(),
                attachment_id: parseInt($('#as-f-bg-image-id').val(), 10) || 0,
                overlay: $('#as-f-bg-overlay').val()
            },
            link: { url: $('#as-f-link-url').val(), text: $('#as-f-link-text').val() },
            visibility: {
                desktop: $('#as-f-hide-desktop').is(':checked'),
                tablet: $('#as-f-hide-tablet').is(':checked'),
                mobile: $('#as-f-hide-mobile').is(':checked')
            }
        };

        if (slide.background.attachment_id) {
            var src = $('#as-f-bg-image-preview').attr('src') || '';
            if (src) slide.__bg_thumb_url = src;
        }

        setSlideData($card, slide);
        $panel.removeClass('is-open').attr('aria-hidden', 'true');
    });

    // ── Reorder ──
    $list.on('anchor-builder:items-reordered dragend', function () {
        reindex();
    });

})(jQuery);
