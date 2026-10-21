/**
 * Registered list: search across every column and click-to-sort headers.
 *
 * The roster table is rendered whole (no paging), so this works on the rows
 * already in the page. Each `table[data-roster-table]` is independent: a group
 * page renders one table per date. Markup contract (class-roster.php):
 *   - table[data-roster-table][data-i18n]  JSON {all, some, none, sortBy}
 *   - th[data-sort="text|number"]          sortable column (no attribute = not sortable)
 *   - td[data-sort-value]                  optional plain value to sort by
 *   - [data-roster-search], [data-roster-count] inside the same .anchor-event-section
 */
(function () {
	'use strict';

	function norm(s) {
		return String(s || '').replace(/\s+/g, ' ').trim().toLowerCase();
	}

	function fmt(tpl) {
		var args = Array.prototype.slice.call(arguments, 1);
		var i = 0;
		return String(tpl).replace(/%(\d+\$)?[ds]/g, function (m, pos) {
			var idx = pos ? parseInt(pos, 10) - 1 : i++;
			return args[idx] !== undefined ? args[idx] : '';
		});
	}

	function cellValue(row, col) {
		var cell = row.cells[col];
		if (!cell) { return ''; }
		var v = cell.getAttribute('data-sort-value');
		return v !== null ? v : cell.textContent;
	}

	function init(table) {
		if (table.getAttribute('data-roster-ready')) { return; }
		table.setAttribute('data-roster-ready', '1');

		var i18n = {};
		try { i18n = JSON.parse(table.getAttribute('data-i18n') || '{}'); } catch (e) { i18n = {}; }
		var scope = table.closest('.anchor-event-section') || table.parentNode;
		var input = scope.querySelector('[data-roster-search]');
		var count = scope.querySelector('[data-roster-count]');
		var tbody = table.tBodies[0];
		if (!tbody) { return; }

		var rows = Array.prototype.slice.call(tbody.rows);
		rows.forEach(function (r, i) { r._order = i; r._text = norm(r.textContent); });
		var total = rows.length;
		var cols = table.tHead ? table.tHead.rows[0].cells.length : 1;

		var empty = document.createElement('tr');
		empty.className = 'anchor-roster-fe-nomatch';
		empty.style.display = 'none';
		var emptyCell = document.createElement('td');
		emptyCell.colSpan = cols;
		emptyCell.textContent = i18n.none || 'No registrants match your search.';
		empty.appendChild(emptyCell);
		tbody.appendChild(empty);

		function applyFilter() {
			var terms = norm(input ? input.value : '').split(' ').filter(Boolean);
			var shown = 0;
			rows.forEach(function (r) {
				var ok = terms.every(function (t) { return r._text.indexOf(t) !== -1; });
				r.style.display = ok ? '' : 'none';
				if (ok) { shown++; }
			});
			empty.style.display = shown ? 'none' : '';
			if (count) {
				count.textContent = terms.length
					? fmt(i18n.some || 'Showing %1$d of %2$d', shown, total)
					: fmt(i18n.all || '%d registrants', total);
			}
		}

		var current = { col: -1, dir: 1 };
		var headers = table.tHead ? Array.prototype.slice.call(table.tHead.rows[0].cells) : [];

		function sortBy(col, type) {
			current.dir = current.col === col ? -current.dir : 1;
			current.col = col;
			var dir = current.dir;
			var sorted = rows.slice().sort(function (a, b) {
				var x = cellValue(a, col), y = cellValue(b, col), c;
				if (type === 'number') {
					c = (parseFloat(x) || 0) - (parseFloat(y) || 0);
				} else {
					c = norm(x).localeCompare(norm(y), undefined, { numeric: true, sensitivity: 'base' });
				}
				return c !== 0 ? c * dir : a._order - b._order;
			});
			sorted.forEach(function (r) { tbody.insertBefore(r, empty); });
			headers.forEach(function (th, i) {
				if (!th.hasAttribute('data-sort')) { return; }
				th.setAttribute('aria-sort', i === col ? (dir === 1 ? 'ascending' : 'descending') : 'none');
			});
		}

		headers.forEach(function (th, col) {
			var type = th.getAttribute('data-sort');
			if (!type) { return; }
			var label = th.textContent.trim();
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'anchor-roster-fe-sort';
			btn.setAttribute('title', fmt(i18n.sortBy || 'Sort by %s', label));
			btn.textContent = label;
			th.textContent = '';
			th.appendChild(btn);
			th.setAttribute('aria-sort', col === 0 ? 'ascending' : 'none');
			btn.addEventListener('click', function () { sortBy(col, type); });
		});
		// The server already orders by attendee A→Z; reflect that so the first
		// click on Attendee reverses it.
		current.col = 0;

		if (input) {
			input.addEventListener('input', applyFilter);
			input.addEventListener('keydown', function (e) {
				if (e.key === 'Escape') { input.value = ''; applyFilter(); }
			});
		}
		applyFilter();
	}

	function boot() {
		Array.prototype.forEach.call(document.querySelectorAll('table[data-roster-table]'), init);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
}());
