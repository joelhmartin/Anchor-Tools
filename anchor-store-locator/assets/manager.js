/**
 * Front-end Store Manager — [anchor_store_manager]
 *
 * All row/tab/pagination markup is rendered by PHP and swapped in wholesale,
 * so there is deliberately no client-side templating here: one renderer, no
 * drift between the initial page load and subsequent AJAX refreshes.
 */
(function ($) {
	'use strict';

	if (!window.ANCHOR_STORE_MGR) { return; }

	var cfg = window.ANCHOR_STORE_MGR;
	var i18n = cfg.i18n || {};

	function t(key, fallback) {
		return i18n[key] || fallback || '';
	}

	function StoreManager(el) {
		this.$wrap = $(el);
		this.uid = this.$wrap.attr('id');

		this.$list = this.$wrap.find('[data-asm-list]');
		this.$form = this.$wrap.find('[data-asm-form]');
		this.$formEl = this.$wrap.find('[data-asm-form-el]');
		this.$formTitle = this.$wrap.find('#' + this.uid + '-form-title');
		this.$formError = this.$wrap.find('[data-asm-form-error]');
		this.$toast = this.$wrap.find('[data-asm-toast]');
		this.$tableWrap = this.$wrap.find('[data-asm-table-wrap]');
		this.$rows = this.$wrap.find('[data-asm-rows]');
		this.$search = this.$wrap.find('[data-asm-search]');

		this.columns = this.$wrap.data('columns') || '';
		this.mediaFrame = null;
		this.request = null;
		this.placesRequest = null;
		this.searchTimer = null;
		this.placesTimer = null;
		this.undoTimer = null;
		this.formSnapshot = null;
		this.lastTrigger = null;

		this.state = {
			status: this.$wrap.find('.asm-tab.is-current').data('asm-status') || 'any',
			orderby: 'title',
			order: 'ASC',
			s: this.$search.val() || '',
			paged: 1,
			per_page: this.$wrap.data('per-page') || 20
		};

		this.readSortFromDom();
		this.bind();
	}

	/* ---------------------------------------------------------------
	   State
	   --------------------------------------------------------------- */

	StoreManager.prototype.readSortFromDom = function () {
		var $current = this.$wrap.find('.asm-sort.is-current');
		if (!$current.length) { return; }
		this.state.orderby = $current.data('asm-sort');
		var sort = $current.closest('th').attr('aria-sort');
		this.state.order = sort === 'descending' ? 'DESC' : 'ASC';
	};

	StoreManager.prototype.syncUrl = function () {
		if (!window.history || !window.history.replaceState) { return; }

		var url = new URL(window.location.href);
		var params = url.searchParams;
		var s = this.state;

		function setOrDrop(key, value, defaultValue) {
			if (value === defaultValue || value === '' || value === null) {
				params.delete(key);
			} else {
				params.set(key, value);
			}
		}

		setOrDrop('asm_status', s.status, 'any');
		setOrDrop('asm_orderby', s.orderby, 'title');
		setOrDrop('asm_order', s.order, 'ASC');
		setOrDrop('asm_s', s.s, '');
		setOrDrop('asm_paged', s.paged, 1);

		window.history.replaceState({}, '', url.toString());
	};

	/* ---------------------------------------------------------------
	   AJAX
	   --------------------------------------------------------------- */

	StoreManager.prototype.post = function (action, data) {
		data = data || {};
		data.action = action;
		data.nonce = cfg.nonce;

		return $.post(cfg.ajaxUrl, data).then(function (resp) {
			if (resp && resp.success) {
				return resp.data;
			}
			var message = (resp && resp.data && resp.data.message) || t('genericError');
			return $.Deferred().reject({ message: message, code: resp && resp.data && resp.data.code }).promise();
		}, function (xhr) {
			var payload = xhr && xhr.responseJSON && xhr.responseJSON.data;
			return $.Deferred().reject({
				message: (payload && payload.message) || t('requestFailed'),
				code: payload && payload.code
			}).promise();
		});
	};

	StoreManager.prototype.refresh = function () {
		var self = this;

		if (this.request) { this.request.abort(); }

		this.$tableWrap.attr('aria-busy', 'true').addClass('is-loading');

		var payload = $.extend({}, this.state, {
			action: 'anchor_store_manager_list',
			nonce: cfg.nonce,
			columns: this.columns,
			uid: this.uid
		});

		this.request = $.post(cfg.ajaxUrl, payload);

		this.request.then(function (resp) {
			if (!resp || !resp.success) {
				self.toast((resp && resp.data && resp.data.message) || t('genericError'), 'error');
				return;
			}
			var d = resp.data;
			self.$wrap.find('[data-asm-head]').html(d.head);
			self.$rows.html(d.rows);
			self.$wrap.find('[data-asm-tabs-wrap]').html(d.tabs);
			self.$wrap.find('[data-asm-bulk-wrap]').html(d.bulk);
			self.$wrap.find('[data-asm-pagination]').html(d.pagination);
			self.state.paged = d.paged;
			self.updateBulkCount();
			self.syncUrl();
		}).fail(function (xhr) {
			if (xhr.statusText === 'abort') { return; }
			var payloadData = xhr && xhr.responseJSON && xhr.responseJSON.data;
			self.toast((payloadData && payloadData.message) || t('requestFailed'), 'error');
		}).always(function () {
			self.$tableWrap.attr('aria-busy', 'false').removeClass('is-loading');
			self.request = null;
		});
	};

	/* ---------------------------------------------------------------
	   Toast
	   --------------------------------------------------------------- */

	StoreManager.prototype.toast = function (message, type, undoFn) {
		var self = this;

		clearTimeout(this.undoTimer);
		this.$toast
			.attr('class', 'asm-toast asm-toast--' + (type || 'success'))
			.empty()
			.append($('<span/>').text(message));

		if (undoFn) {
			var $undo = $('<button type="button" class="asm-toast-undo"/>').text(t('undo', 'Undo'));
			$undo.on('click', function () {
				self.$toast.attr('hidden', true);
				undoFn();
			});
			this.$toast.append($undo);
		}

		this.$toast.removeAttr('hidden');
		this.undoTimer = setTimeout(function () {
			self.$toast.attr('hidden', true);
		}, undoFn ? 8000 : 3500);
	};

	/* ---------------------------------------------------------------
	   Form
	   --------------------------------------------------------------- */

	StoreManager.prototype.field = function (name) {
		return this.$wrap.find('[data-asm-field="' + name + '"]');
	};

	StoreManager.prototype.formValues = function () {
		var values = {};
		this.$formEl.find('[data-asm-field]').each(function () {
			values[$(this).data('asm-field')] = $(this).val();
		});
		return values;
	};

	StoreManager.prototype.isDirty = function () {
		if (this.formSnapshot === null) { return false; }
		return JSON.stringify(this.formValues()) !== this.formSnapshot;
	};

	StoreManager.prototype.resetForm = function () {
		this.$formEl[0].reset();
		this.$formEl.find('[data-asm-field]').val('');
		this.field('status').val('draft');
		this.$wrap.find('[data-asm-image-preview]').empty();
		this.$wrap.find('[data-asm-action="remove-image"]').attr('hidden', true);
		this.$wrap.find('[data-asm-places-results]').attr('hidden', true).empty();
		this.$wrap.find('[data-asm-places]').val('').attr('aria-expanded', 'false');
		this.$formError.attr('hidden', true).text('');
	};

	StoreManager.prototype.showForm = function (title) {
		this.$list.attr('hidden', true);
		this.$formTitle.text(title);
		this.$form.removeAttr('hidden');
		this.formSnapshot = JSON.stringify(this.formValues());
		this.$formTitle.trigger('focus');
	};

	StoreManager.prototype.showList = function (force) {
		if (!force && this.isDirty() && !window.confirm(t('unsaved'))) {
			return false;
		}
		this.$form.attr('hidden', true);
		this.$list.removeAttr('hidden');
		this.formSnapshot = null;
		if (this.lastTrigger && $.contains(document, this.lastTrigger)) {
			$(this.lastTrigger).trigger('focus');
		}
		return true;
	};

	/* ---------------------------------------------------------------
	   Bulk selection
	   --------------------------------------------------------------- */

	StoreManager.prototype.selectedIds = function () {
		return this.$wrap.find('[data-asm-select]:checked').map(function () {
			return parseInt(this.value, 10);
		}).get();
	};

	StoreManager.prototype.updateBulkCount = function () {
		var count = this.selectedIds().length;
		var $label = this.$wrap.find('[data-asm-bulk-count]');
		$label.text(count ? count + ' selected' : '');

		var total = this.$wrap.find('[data-asm-select]').length;
		this.$wrap.find('[data-asm-select-all]').prop({
			checked: total > 0 && count === total,
			indeterminate: count > 0 && count < total
		});
	};

	/* ---------------------------------------------------------------
	   Bindings
	   --------------------------------------------------------------- */

	StoreManager.prototype.bind = function () {
		var self = this;
		var $wrap = this.$wrap;

		/* --- Search (debounced) --- */
		this.$search.on('input', function () {
			var value = $(this).val();
			$wrap.find('[data-asm-search-clear]').attr('hidden', value === '' ? true : null);

			clearTimeout(self.searchTimer);
			self.searchTimer = setTimeout(function () {
				self.state.s = value;
				self.state.paged = 1;
				self.refresh();
			}, 300);
		});

		$wrap.on('click', '[data-asm-search-clear], [data-asm-action="clear-search"]', function () {
			self.$search.val('');
			$wrap.find('[data-asm-search-clear]').attr('hidden', true);
			self.state.s = '';
			self.state.paged = 1;
			self.refresh();
		});

		/* --- Status tabs --- */
		$wrap.on('click', '[data-asm-status]', function () {
			self.state.status = $(this).data('asm-status');
			self.state.paged = 1;
			self.refresh();
		});

		/* --- Sorting --- */
		$wrap.on('click', '[data-asm-sort]', function () {
			var column = $(this).data('asm-sort');
			if (self.state.orderby === column) {
				self.state.order = self.state.order === 'ASC' ? 'DESC' : 'ASC';
			} else {
				self.state.orderby = column;
				self.state.order = 'ASC';
			}
			self.state.paged = 1;
			self.refresh();
		});

		/* --- Pagination --- */
		$wrap.on('click', '[data-asm-page]', function () {
			var page = parseInt($(this).data('asm-page'), 10);
			if (isNaN(page) || page < 1 || $(this).is(':disabled')) { return; }
			self.state.paged = page;
			self.refresh();
			self.$tableWrap[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
		});

		/* --- Selection --- */
		$wrap.on('change', '[data-asm-select-all]', function () {
			$wrap.find('[data-asm-select]').prop('checked', this.checked);
			self.updateBulkCount();
		});

		$wrap.on('change', '[data-asm-select]', function () {
			self.updateBulkCount();
		});

		/* --- Bulk apply --- */
		$wrap.on('click', '[data-asm-action="bulk-apply"]', function () {
			var action = $wrap.find('[data-asm-bulk-action]').val();
			var ids = self.selectedIds();

			if (!action) { return; }
			if (!ids.length) {
				self.toast(t('noSelection'), 'error');
				return;
			}
			if (!window.confirm(t('confirmBulk'))) { return; }

			self.post('anchor_store_manager_bulk', {
				bulk_action: action,
				ids: ids
			}).then(function (data) {
				var message = data.done + ' updated';
				if (data.skipped) {
					message += ', ' + data.skipped + ' skipped (no permission)';
				}
				self.toast(message, data.skipped ? 'warning' : 'success');
				self.refresh();
			}, function (err) {
				self.toast(err.message, 'error');
			});
		});

		/* --- Add --- */
		$wrap.on('click', '[data-asm-action="add"]', function () {
			self.lastTrigger = this;
			self.resetForm();
			self.showForm(t('addStore'));
		});

		/* --- Cancel --- */
		$wrap.on('click', '[data-asm-action="cancel"]', function () {
			self.showList();
		});

		/* --- Escape closes the form --- */
		$wrap.on('keydown', function (e) {
			if (e.key === 'Escape' && !self.$form.attr('hidden')) {
				self.showList();
			}
		});

		/* --- Edit --- */
		$wrap.on('click', '[data-asm-action="edit"]', function () {
			var id = $(this).data('id');
			self.lastTrigger = this;
			self.resetForm();

			self.post('anchor_store_manager_get', { store_id: id }).then(function (data) {
				self.field('store_id').val(data.id);
				self.field('place_id').val(data.place_id || '');
				self.field('title').val(data.title);
				self.field('owner').val(data.owner);
				self.field('address').val(data.address);
				self.field('lat').val(data.lat);
				self.field('lng').val(data.lng);
				self.field('website').val(data.website);
				self.field('email').val(data.email);
				self.field('phone').val(data.phone);
				self.field('maps_url').val(data.maps_url);
				self.field('status').val(data.status);

				if (data.thumbnail_id && data.thumbnail_url) {
					self.field('thumbnail_id').val(data.thumbnail_id);
					self.$wrap.find('[data-asm-image-preview]').html(
						$('<img/>').attr({ src: data.thumbnail_url, alt: '' })
					);
					self.$wrap.find('[data-asm-action="remove-image"]').removeAttr('hidden');
				}

				self.showForm(t('editStore'));
			}, function (err) {
				self.toast(err.message, 'error');
			});
		});

		/* --- Duplicate --- */
		$wrap.on('click', '[data-asm-action="duplicate"]', function () {
			self.post('anchor_store_manager_duplicate', { store_id: $(this).data('id') })
				.then(function () {
					self.toast(t('duplicated'));
					self.refresh();
				}, function (err) {
					self.toast(err.message, 'error');
				});
		});

		/* --- Delete (trash, with undo) --- */
		$wrap.on('click', '[data-asm-action="delete"]', function () {
			var id = $(this).data('id');

			self.post('anchor_store_manager_delete', { store_id: id }).then(function () {
				self.refresh();
				self.toast(t('trashed'), 'success', function () {
					self.post('anchor_store_manager_restore', { store_id: id }).then(function () {
						self.toast(t('restored'));
						self.refresh();
					}, function (err) {
						self.toast(err.message, 'error');
					});
				});
			}, function (err) {
				self.toast(err.message, 'error');
			});
		});

		/* --- Delete permanently --- */
		$wrap.on('click', '[data-asm-action="delete-permanently"]', function () {
			if (!window.confirm(t('confirmDelete'))) { return; }

			self.post('anchor_store_manager_delete', {
				store_id: $(this).data('id'),
				permanent: 1
			}).then(function () {
				self.refresh();
			}, function (err) {
				self.toast(err.message, 'error');
			});
		});

		/* --- Restore --- */
		$wrap.on('click', '[data-asm-action="restore"]', function () {
			self.post('anchor_store_manager_restore', { store_id: $(this).data('id') })
				.then(function () {
					self.toast(t('restored'));
					self.refresh();
				}, function (err) {
					self.toast(err.message, 'error');
				});
		});

		/* --- Save --- */
		this.$formEl.on('submit', function (e) {
			e.preventDefault();

			var $btn = self.$formEl.find('[type="submit"]');
			var payload = self.formValues();

			self.$formError.attr('hidden', true).text('');
			$btn.prop('disabled', true).text(t('saving'));

			self.post('anchor_store_manager_save', payload).then(function (data) {
				var message = data.is_new ? t('created') : t('updated');
				var type = 'success';

				if (data.geocode === 'no_key') {
					message = t('geocodeNoKey');
					type = 'warning';
				} else if (data.geocode === 'failed') {
					message = t('geocodeFailed');
					type = 'warning';
				}

				self.formSnapshot = JSON.stringify(self.formValues());
				self.showList(true);
				self.refresh();
				self.toast(message, type);
			}, function (err) {
				self.$formError.text(err.message).removeAttr('hidden');
				if (err.field) {
					self.field(err.field).trigger('focus');
				}
				self.toast(err.message, 'error');
			}).always(function () {
				$btn.prop('disabled', false).text(t('saveStore'));
			});
		});

		/* --- Featured image --- */
		$wrap.on('click', '[data-asm-action="upload-image"]', function (e) {
			e.preventDefault();
			if (!cfg.canUpload || typeof wp === 'undefined' || !wp.media) { return; }

			if (!self.mediaFrame) {
				self.mediaFrame = wp.media({
					title: t('selectImage'),
					button: { text: t('useImage') },
					multiple: false
				});

				self.mediaFrame.on('select', function () {
					var attachment = self.mediaFrame.state().get('selection').first().toJSON();
					var url = (attachment.sizes && attachment.sizes.medium)
						? attachment.sizes.medium.url
						: attachment.url;

					self.field('thumbnail_id').val(attachment.id);
					self.$wrap.find('[data-asm-image-preview]').html(
						$('<img/>').attr({ src: url, alt: '' })
					);
					self.$wrap.find('[data-asm-action="remove-image"]').removeAttr('hidden');
				});
			}

			self.mediaFrame.open();
		});

		$wrap.on('click', '[data-asm-action="remove-image"]', function () {
			self.field('thumbnail_id').val('');
			self.$wrap.find('[data-asm-image-preview]').empty();
			$(this).attr('hidden', true);
		});

		/* --- Google Places autocomplete --- */
		$wrap.on('input', '[data-asm-places]', function () {
			var query = $(this).val();
			clearTimeout(self.placesTimer);

			if (query.length < 3) {
				$wrap.find('[data-asm-places-results]').attr('hidden', true).empty();
				$wrap.find('[data-asm-places]').attr('aria-expanded', 'false');
				return;
			}

			self.placesTimer = setTimeout(function () {
				self.searchPlaces(query);
			}, 350);
		});

		$wrap.on('click', '[data-asm-place-pick]', function () {
			self.pickPlace($(this).data('asm-place-pick'));
		});

		// Typing a different address by hand invalidates the picked listing.
		$wrap.on('input', '[data-asm-field="address"]', function () {
			self.field('place_id').val('');
		});

		/* --- Unsaved-changes guard on navigation away --- */
		$(window).on('beforeunload.' + this.uid, function () {
			if (!self.$form.attr('hidden') && self.isDirty()) {
				return t('unsaved');
			}
		});

		/* --- Heartbeat nonce refresh --- */
		$(document).on('heartbeat-send', function (e, data) {
			data.anchor_store_manager = true;
		});

		$(document).on('heartbeat-tick', function (e, data) {
			if (data.anchor_store_manager) {
				cfg.nonce = data.anchor_store_manager.nonce;
				cfg.placesNonce = data.anchor_store_manager.placesNonce;
			}
		});
	};

	/* ---------------------------------------------------------------
	   Places
	   --------------------------------------------------------------- */

	StoreManager.prototype.searchPlaces = function (query) {
		var self = this;
		var $results = this.$wrap.find('[data-asm-places-results]');

		if (this.placesRequest) { this.placesRequest.abort(); }

		$results.removeAttr('hidden').html($('<div class="asm-places-status"/>').text(t('searching')));
		this.$wrap.find('[data-asm-places]').attr('aria-expanded', 'true');

		this.placesRequest = $.post(cfg.ajaxUrl, {
			action: 'anchor_store_locator_place_search',
			nonce: cfg.placesNonce,
			query: query
		});

		this.placesRequest.then(function (resp) {
			if (!resp || !resp.success) {
				$results.html($('<div class="asm-places-status"/>').text(t('noPlaces')));
				return;
			}

			var results = (resp.data && resp.data.results) || [];
			if (!results.length) {
				$results.html($('<div class="asm-places-status"/>').text(t('noPlaces')));
				return;
			}

			$results.empty();
			results.forEach(function (row) {
				var $btn = $('<button type="button" class="asm-place-result" role="option"/>')
					.attr('data-asm-place-pick', row.place_id);
				$btn.append($('<strong/>').text(row.name || ''));
				$btn.append($('<span/>').text(row.address || ''));
				$results.append($btn);
			});
		}).fail(function (xhr) {
			if (xhr.statusText === 'abort') { return; }
			$results.html($('<div class="asm-places-status"/>').text(t('noPlaces')));
		}).always(function () {
			self.placesRequest = null;
		});
	};

	StoreManager.prototype.pickPlace = function (placeId) {
		var self = this;
		var $results = this.$wrap.find('[data-asm-places-results]');

		$.post(cfg.ajaxUrl, {
			action: 'anchor_store_locator_place_details',
			nonce: cfg.placesNonce,
			place_id: placeId
		}).then(function (resp) {
			if (!resp || !resp.success) {
				self.toast((resp && resp.data && resp.data.message) || t('genericError'), 'error');
				return;
			}

			var d = resp.data.details || {};

			if (!self.field('title').val() && d.name) { self.field('title').val(d.name); }
			if (d.address) { self.field('address').val(d.address); }
			if (d.lat) { self.field('lat').val(d.lat); }
			if (d.lng) { self.field('lng').val(d.lng); }
			if (d.website) { self.field('website').val(d.website); }
			if (d.phone) { self.field('phone').val(d.phone); }
			if (d.maps_url) { self.field('maps_url').val(d.maps_url); }
			self.field('place_id').val(d.place_id || '');

			$results.attr('hidden', true).empty();
			self.$wrap.find('[data-asm-places]').val('').attr('aria-expanded', 'false');
		}).fail(function () {
			self.toast(t('requestFailed'), 'error');
		});
	};

	/* --------------------------------------------------------------- */

	$(function () {
		$('.asm-wrap').each(function () {
			new StoreManager(this);
		});
	});

})(jQuery);
