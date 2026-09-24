(function($){
	'use strict';

	var cfg = window.ANCHOR_TESTIMONIALS_ADMIN || {};
	var searchTimer;

	/* YouTube ids can be computed client side from the URL; Vimeo needs an
	   oEmbed round trip, so its preview is only refreshed server side on
	   save (Anchor_Video_URL::thumbnail) and left as-is here. */
	function ytIdFromUrl(url){
		var m = url.match(/(?:youtu\.be\/|youtube(?:-nocookie)?\.com\/(?:embed\/|shorts\/|live\/|v\/))([A-Za-z0-9_-]{6,})/);
		if (m) return m[1];
		m = url.match(/[?&]v=([A-Za-z0-9_-]{6,})/);
		if (m && url.indexOf('youtube.com/') !== -1) return m[1];
		return '';
	}

	function updateVideoPreview(){
		var url = $('#at_video_url').val() || '';
		var $img = $('#at_video_thumb');
		var id = ytIdFromUrl(url);
		if (id) {
			$img.attr('src', 'https://i.ytimg.com/vi/' + encodeURIComponent(id) + '/hqdefault.jpg').show();
		} else if (url.indexOf('vimeo.com') === -1) {
			$img.hide();
		}
	}

	function escapeHtml(str){
		return $('<div>').text(str == null ? '' : str).html();
	}

	function addRelatedChip(id, title){
		id = parseInt(id, 10) || 0;
		if (!id || $('#at_related_chips .anchor-testimonial-chip[data-id="' + id + '"]').length) return;
		var $chip = $(
			'<span class="anchor-testimonial-chip" data-id="' + id + '">' +
				escapeHtml(title) +
				'<input type="hidden" name="anchor_testimonial[related][]" value="' + id + '" />' +
				'<button type="button" class="anchor-testimonial-chip-remove" aria-label="Remove">&times;</button>' +
			'</span>'
		);
		$('#at_related_chips').append($chip);
	}

	function renderResults(items){
		var $results = $('#at_related_results');
		$results.empty();
		if (!items || !items.length) {
			$results.hide();
			return;
		}
		items.forEach(function(item){
			var $row = $('<div class="anchor-testimonial-result" tabindex="0"></div>');
			$row.text(item.title + ' ');
			$row.append($('<span class="anchor-testimonial-result-type"></span>').text('(' + item.type_label + ')'));
			$row.attr('data-id', item.id);
			$row.attr('data-title', item.title);
			$results.append($row);
		});
		$results.show();
	}

	function searchRelated(term){
		if (!cfg.ajaxUrl) return;
		$.get(cfg.ajaxUrl, {
			action: 'anchor_testimonials_search_posts',
			nonce: cfg.nonce,
			s: term
		}).done(function(resp){
			renderResults(Array.isArray(resp) ? resp : []);
		}).fail(function(){
			renderResults([]);
		});
	}

	$(function(){
		$('#at_video_url').on('input', updateVideoPreview);

		$('#at_related_search').on('input', function(){
			var term = $(this).val();
			clearTimeout(searchTimer);
			if (term.length < 2) {
				$('#at_related_results').empty().hide();
				return;
			}
			searchTimer = setTimeout(function(){
				searchRelated(term);
			}, 300);
		});

		$(document).on('click', '#at_related_results .anchor-testimonial-result', function(){
			var $row = $(this);
			addRelatedChip($row.attr('data-id'), $row.attr('data-title'));
			$('#at_related_search').val('');
			$('#at_related_results').empty().hide();
		});

		$(document).on('click', '.anchor-testimonial-chip-remove', function(){
			$(this).closest('.anchor-testimonial-chip').remove();
		});
	});

})(jQuery);
