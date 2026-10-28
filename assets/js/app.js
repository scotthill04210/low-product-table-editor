/**
 * LOW Product Table Editor admin app.
 *
 * Read-only Tabulator grid. dataTree is used instead of groupBy because
 * parent rows must carry their own editable cells for simple products in
 * phase 3.
 *
 * The admin screen URL already uses page=low-pte. The product page number
 * is stored as paged so it does not replace that screen slug. Requests to
 * the REST route still send page.
 */
(function () {
	'use strict';

	var config = window.LOW_PTE_CONFIG || {};
	var i18n = config.i18n || {};
	var mount = document.getElementById('low-pte-app');

	if (!mount) {
		return;
	}

	var perPageOptions = Array.isArray(config.perPageOptions) ? config.perPageOptions.map(Number) : [10, 25, 50, 100];
	var defaultPerPage = perPageOptions.indexOf(Number(config.defaultPerPage)) !== -1 ? Number(config.defaultPerPage) : 25;
	var state = readState();
	var table = null;
	var requestController = null;
	var requestSerial = 0;
	var searchTimer = 0;

	var searchInput;
	var categorySelect;
	var typeSelect;
	var stockSelect;
	var statusSelect;
	var perPageSelect;
	var clearButton;
	var clearBadge;
	var expandButton;
	var collapseButton;
	var messageEl;
	var skeletonEl;
	var gridEl;
	var pagerEl;

	buildShell();
	syncControls();
	writeUrl('replace');
	load();

	window.addEventListener('popstate', function () {
		window.clearTimeout(searchTimer);
		state = readState();
		syncControls();
		load();
	});

	function t(key, fallback) {
		return typeof i18n[key] === 'string' && i18n[key] !== '' ? i18n[key] : fallback;
	}

	function readState() {
		var params = new URLSearchParams(window.location.search);
		var perPageValue = parseInt(params.get('per_page') || '', 10);
		var pageValue = parseInt(params.get('paged') || '1', 10);

		return {
			search: (params.get('search') || '').slice(0, 100),
			category: params.get('category') || '',
			type: oneOf(params.get('type'), ['simple', 'variable']),
			stock_status: oneOf(params.get('stock_status'), ['instock', 'outofstock', 'onbackorder']),
			status: oneOf(params.get('status'), ['publish', 'draft', 'private', 'pending']),
			page: Number.isFinite(pageValue) && pageValue > 0 ? pageValue : 1,
			per_page: perPageOptions.indexOf(perPageValue) !== -1 ? perPageValue : defaultPerPage
		};
	}

	function oneOf(value, allowed) {
		return allowed.indexOf(value) !== -1 ? value : '';
	}

	function writeUrl(mode) {
		var next = new URLSearchParams(window.location.search);
		['search', 'category', 'type', 'stock_status', 'status', 'per_page', 'paged'].forEach(function (key) {
			next.delete(key);
		});

		if (state.search) {
			next.set('search', state.search);
		}
		if (state.category) {
			next.set('category', state.category);
		}
		if (state.type) {
			next.set('type', state.type);
		}
		if (state.stock_status) {
			next.set('stock_status', state.stock_status);
		}
		if (state.status) {
			next.set('status', state.status);
		}
		if (state.per_page !== defaultPerPage) {
			next.set('per_page', String(state.per_page));
		}
		if (state.page > 1) {
			next.set('paged', String(state.page));
		}

		var query = next.toString();
		var url = window.location.pathname + (query ? '?' + query : '');
		var historyState = { lowPte: true };

		if (mode === 'push') {
			window.history.pushState(historyState, '', url);
		} else {
			window.history.replaceState(historyState, '', url);
		}
	}

	function buildShell() {
		mount.textContent = '';

		var toolbar = document.createElement('div');
		toolbar.className = 'low-pte-toolbar';

		searchInput = document.createElement('input');
		searchInput.type = 'search';
		searchInput.className = 'low-pte-search';
		searchInput.maxLength = 100;
		searchInput.autocomplete = 'off';
		searchInput.addEventListener('input', onSearchInput);
		toolbar.appendChild(field(t('search', 'Search'), searchInput, 'low-pte-search'));

		categorySelect = document.createElement('select');
		fillCategories(categorySelect);
		categorySelect.addEventListener('change', onFilterChange);
		toolbar.appendChild(field(t('category', 'Category'), categorySelect));

		typeSelect = document.createElement('select');
		fillOptions(typeSelect, [
			['', t('allTypes', 'All types')],
			['simple', t('simple', 'Simple')],
			['variable', t('variable', 'Variable')]
		]);
		typeSelect.addEventListener('change', onFilterChange);
		toolbar.appendChild(field(t('productType', 'Type'), typeSelect));

		stockSelect = document.createElement('select');
		fillOptions(stockSelect, [
			['', t('allStock', 'All stock statuses')],
			['instock', t('inStock', 'In stock')],
			['outofstock', t('outOfStock', 'Out of stock')],
			['onbackorder', t('onBackorder', 'On backorder')]
		]);
		stockSelect.addEventListener('change', onFilterChange);
		toolbar.appendChild(field(t('stockStatus', 'Stock status'), stockSelect));

		statusSelect = document.createElement('select');
		fillOptions(statusSelect, [
			['', t('allStatuses', 'All statuses')],
			['publish', t('statusPublish', 'Published')],
			['draft', t('statusDraft', 'Draft')],
			['private', t('statusPrivate', 'Private')],
			['pending', t('statusPending', 'Pending')]
		]);
		statusSelect.addEventListener('change', onFilterChange);
		toolbar.appendChild(field(t('postStatus', 'Status'), statusSelect));

		perPageSelect = document.createElement('select');
		fillOptions(perPageSelect, perPageOptions.map(function (count) {
			return [String(count), String(count)];
		}));
		perPageSelect.addEventListener('change', onPerPageChange);
		toolbar.appendChild(field(t('perPage', 'Per page'), perPageSelect));

		var actions = document.createElement('div');
		actions.className = 'low-pte-toolbar-actions';

		clearButton = document.createElement('button');
		clearButton.type = 'button';
		clearButton.className = 'button low-pte-clear';
		clearButton.textContent = t('clearFilters', 'Clear filters');
		clearBadge = document.createElement('span');
		clearBadge.className = 'low-pte-filter-count';
		clearBadge.hidden = true;
		clearButton.appendChild(clearBadge);
		clearButton.addEventListener('click', clearFilters);
		actions.appendChild(clearButton);

		expandButton = document.createElement('button');
		expandButton.type = 'button';
		expandButton.className = 'button';
		expandButton.textContent = t('expandAll', 'Expand all');
		expandButton.disabled = true;
		expandButton.addEventListener('click', function () {
			rootRows().forEach(function (row) {
				row.treeExpand();
			});
		});
		actions.appendChild(expandButton);

		collapseButton = document.createElement('button');
		collapseButton.type = 'button';
		collapseButton.className = 'button';
		collapseButton.textContent = t('collapseAll', 'Collapse all');
		collapseButton.disabled = true;
		collapseButton.addEventListener('click', function () {
			rootRows().forEach(function (row) {
				row.treeCollapse();
			});
		});
		actions.appendChild(collapseButton);

		toolbar.appendChild(actions);
		mount.appendChild(toolbar);

		messageEl = document.createElement('div');
		messageEl.className = 'low-pte-message';
		messageEl.setAttribute('role', 'status');
		messageEl.setAttribute('aria-live', 'polite');
		messageEl.hidden = true;
		mount.appendChild(messageEl);

		skeletonEl = document.createElement('div');
		skeletonEl.className = 'low-pte-skeleton';
		skeletonEl.hidden = true;
		skeletonEl.setAttribute('aria-hidden', 'true');
		for (var i = 0; i < 8; i += 1) {
			var bar = document.createElement('div');
			bar.className = 'low-pte-skeleton-bar';
			skeletonEl.appendChild(bar);
		}
		mount.appendChild(skeletonEl);

		gridEl = document.createElement('div');
		gridEl.className = 'low-pte-grid';
		gridEl.hidden = true;
		mount.appendChild(gridEl);

		pagerEl = document.createElement('nav');
		pagerEl.className = 'low-pte-pager';
		pagerEl.setAttribute('aria-label', t('pagination', 'Pagination'));
		pagerEl.hidden = true;
		mount.appendChild(pagerEl);
	}

	function field(label, control) {
		var wrap = document.createElement('label');
		wrap.className = 'low-pte-field';
		var text = document.createElement('span');
		text.className = 'low-pte-field-label';
		text.textContent = label;
		wrap.appendChild(text);
		wrap.appendChild(control);
		return wrap;
	}

	function fillOptions(select, pairs) {
		pairs.forEach(function (pair) {
			var option = document.createElement('option');
			option.value = pair[0];
			option.textContent = pair[1];
			select.appendChild(option);
		});
	}

	function fillCategories(select) {
		var all = document.createElement('option');
		all.value = '';
		all.textContent = t('allCategories', 'All categories');
		select.appendChild(all);

		var categories = Array.isArray(config.categories) ? config.categories : [];
		categories.forEach(function (category) {
			if (!category || typeof category.slug !== 'string') {
				return;
			}
			var option = document.createElement('option');
			option.value = category.slug;
			var depth = Number(category.depth) || 0;
			var indent = depth > 0 ? '\u00A0'.repeat(depth * 3) : '';
			option.textContent = indent + (category.name ? String(category.name) : category.slug);
			select.appendChild(option);
		});
	}

	function syncControls() {
		searchInput.value = state.search;
		categorySelect.value = hasOption(categorySelect, state.category) ? state.category : '';
		typeSelect.value = state.type;
		stockSelect.value = state.stock_status;
		statusSelect.value = state.status;
		perPageSelect.value = String(state.per_page);
		state.category = categorySelect.value;
		updateFilterBadge();
	}

	function hasOption(select, value) {
		return Array.prototype.some.call(select.options, function (option) {
			return option.value === value;
		});
	}

	function activeFilterCount() {
		var count = 0;
		if (state.search) {
			count += 1;
		}
		if (state.category) {
			count += 1;
		}
		if (state.type) {
			count += 1;
		}
		if (state.stock_status) {
			count += 1;
		}
		if (state.status) {
			count += 1;
		}
		return count;
	}

	function updateFilterBadge() {
		var count = activeFilterCount();
		clearBadge.hidden = count === 0;
		clearBadge.textContent = String(count);
		clearButton.disabled = count === 0;
	}

	function onSearchInput() {
		window.clearTimeout(searchTimer);
		searchTimer = window.setTimeout(function () {
			state.search = searchInput.value.trim();
			state.page = 1;
			updateFilterBadge();
			commit('replace');
		}, 300);
	}

	function onFilterChange() {
		window.clearTimeout(searchTimer);
		state.search = searchInput.value.trim();
		state.category = categorySelect.value;
		state.type = typeSelect.value;
		state.stock_status = stockSelect.value;
		state.status = statusSelect.value;
		state.page = 1;
		updateFilterBadge();
		commit('replace');
	}

	function onPerPageChange() {
		window.clearTimeout(searchTimer);
		var next = parseInt(perPageSelect.value, 10);
		state.per_page = perPageOptions.indexOf(next) !== -1 ? next : defaultPerPage;
		state.page = 1;
		commit('replace');
	}

	function clearFilters() {
		window.clearTimeout(searchTimer);
		state.search = '';
		state.category = '';
		state.type = '';
		state.stock_status = '';
		state.status = '';
		state.page = 1;
		syncControls();
		commit('replace');
	}

	function commit(mode) {
		writeUrl(mode);
		load();
	}

	function load() {
		if (requestController) {
			requestController.abort();
		}
		requestController = new AbortController();
		requestSerial += 1;
		var serial = requestSerial;

		showLoading();

		var params = new URLSearchParams();
		params.set('page', String(state.page));
		params.set('per_page', String(state.per_page));
		if (state.search) {
			params.set('search', state.search);
		}
		if (state.category) {
			params.set('category', state.category);
		}
		if (state.type) {
			params.set('type', state.type);
		}
		if (state.stock_status) {
			params.set('stock_status', state.stock_status);
		}
		if (state.status) {
			params.set('status', state.status);
		}

		var root = String(config.restUrl || '').replace(/\/?$/, '/');

		window.fetch(root + 'products?' + params.toString(), {
			method: 'GET',
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': config.nonce || '',
				Accept: 'application/json'
			},
			signal: requestController.signal
		}).then(function (response) {
			return response.json().then(function (body) {
				return { response: response, body: body };
			}).catch(function () {
				return { response: response, body: {} };
			});
		}).then(function (result) {
			if (serial !== requestSerial) {
				return;
			}
			var response = result.response;
			var body = result.body || {};
			var code = body && body.code ? String(body.code) : '';
			if (!response.ok) {
				if (response.status === 401 || response.status === 403 || code === 'rest_cookie_invalid_nonce') {
					showSession();
					return;
				}
				showError();
				return;
			}
			renderSuccess(body);
		}).catch(function (error) {
			if (error && error.name === 'AbortError') {
				return;
			}
			if (serial !== requestSerial) {
				return;
			}
			showError();
		});
	}

	function showLoading() {
		destroyTable();
		setTreeButtons(false);
		messageEl.hidden = true;
		messageEl.textContent = '';
		skeletonEl.hidden = false;
		gridEl.hidden = true;
		pagerEl.hidden = true;
		mount.setAttribute('aria-busy', 'true');
	}

	function showSession() {
		destroyTable();
		setTreeButtons(false);
		skeletonEl.hidden = true;
		gridEl.hidden = true;
		pagerEl.hidden = true;
		setMessage(t('sessionExpired', 'Your session expired. Reload the page.'), 'session');
		mount.setAttribute('aria-busy', 'false');
	}

	function showError() {
		destroyTable();
		setTreeButtons(false);
		skeletonEl.hidden = true;
		gridEl.hidden = true;
		pagerEl.hidden = true;
		setMessage(t('loadError', 'Products could not be loaded. Try again.'), 'error', {
			label: t('retry', 'Retry'),
			onClick: load
		});
		mount.setAttribute('aria-busy', 'false');
	}

	function showEmpty() {
		destroyTable();
		setTreeButtons(false);
		skeletonEl.hidden = true;
		gridEl.hidden = true;
		setMessage(t('empty', 'No products match these filters'), 'empty', {
			label: t('clearFilters', 'Clear filters'),
			onClick: clearFilters
		});
		mount.setAttribute('aria-busy', 'false');
	}

	function setMessage(text, kind, action) {
		messageEl.replaceChildren();
		messageEl.hidden = false;
		messageEl.className = 'low-pte-message' + (kind === 'error' || kind === 'session' ? ' low-pte-message-error' : '');

		var paragraph = document.createElement('p');
		paragraph.className = 'low-pte-message-text';
		paragraph.textContent = text;
		messageEl.appendChild(paragraph);

		if (action) {
			var button = document.createElement('button');
			button.type = 'button';
			button.className = 'button';
			button.textContent = action.label;
			button.addEventListener('click', action.onClick);
			messageEl.appendChild(button);
		}
	}

	function renderSuccess(body) {
		var items = Array.isArray(body.items) ? body.items : [];
		var total = Number(body.total) || 0;
		var totalPages = Number(body.total_pages) || 0;

		skeletonEl.hidden = true;
		mount.setAttribute('aria-busy', 'false');
		renderPager(total, totalPages);

		if (items.length === 0) {
			showEmpty();
			pagerEl.hidden = false;
			return;
		}

		messageEl.hidden = true;
		messageEl.textContent = '';
		gridEl.hidden = false;

		if (typeof window.Tabulator !== 'function') {
			showError();
			return;
		}

		try {
			table = new window.Tabulator(gridEl, {
				data: items,
				index: 'id',
				layout: 'fitColumns',
				height: 'calc(100vh - 360px)',
				minHeight: 360,
				renderVertical: 'virtual',
				dataTree: true,
				dataTreeChildField: 'children',
				dataTreeStartExpanded: true,
				selectableRows: false,
				columnDefaults: {
					headerSort: false,
					resizable: true
				},
				rowFormatter: function (row) {
					var element = row.getElement();
					var type = row.getData().type;
					element.classList.toggle('low-pte-parent-row', type !== 'variation');
					element.classList.toggle('low-pte-variation-row', type === 'variation');
				},
				columns: [
					column(t('columnProduct', 'Product'), 'name', 360, 2, true, productFormatter),
					column(t('columnSoldBy', 'Sold By'), 'sold_by', 120, 1, false, textFormatter),
					column(t('columnRegularPrice', 'Regular price'), 'regular_price', 110, 1, false, textFormatter),
					column(t('columnSalePrice', 'Sale price'), 'sale_price', 110, 1, false, textFormatter),
					column(t('columnStock', 'Stock'), 'stock_status', 140, 1, false, stockFormatter),
					column(t('columnSku', 'SKU'), 'sku', 110, 1, false, textFormatter),
					column(t('columnWeight', 'Weight'), 'weight', 90, 1, false, textFormatter),
					column(t('columnDescription', 'Description'), 'description_preview', 180, 2, false, descriptionFormatter),
					column(t('columnStatus', 'Status'), 'status', 110, 1, false, statusFormatter)
				]
			});

			setTreeButtons(true);
		} catch (error) {
			table = null;
			gridEl.replaceChildren();
			showError();
		}
	}

	function column(title, field, minWidth, grow, frozen, formatter) {
		return {
			title: title,
			field: field,
			minWidth: minWidth,
			widthGrow: grow,
			frozen: frozen,
			headerSort: false,
			formatter: formatter
		};
	}

	function textFormatter(cell) {
		var span = document.createElement('span');
		var value = cell.getValue();
		span.textContent = value === null || value === undefined ? '' : String(value);
		return span;
	}

	function descriptionFormatter(cell) {
		var span = textFormatter(cell);
		span.className = 'low-pte-ellipsis';
		span.title = span.textContent;
		return span;
	}

	function productFormatter(cell) {
		var data = cell.getRow().getData();
		var wrap = document.createElement('span');
		wrap.className = 'low-pte-product-cell';

		var name = document.createElement('span');
		var isVariation = data.type === 'variation';
		var text = isVariation
			? (data.variation_label ? String(data.variation_label) : '')
			: (data.name ? String(data.name) : '');
		name.className = isVariation ? 'low-pte-variation-name' : 'low-pte-product-name';
		name.textContent = text;
		if (text) {
			name.title = text;
		}
		wrap.appendChild(name);

		if (data.type === 'variable') {
			var count = Number(data.variation_count) || 0;
			var badge = document.createElement('span');
			badge.className = 'low-pte-variation-count';
			var template = count === 1 ? t('variationCountSingle', '%s variation') : t('variationCount', '%s variations');
			badge.textContent = template.replace('%s', String(count));
			wrap.appendChild(badge);
		}

		return wrap;
	}

	function stockFormatter(cell) {
		var span = document.createElement('span');
		span.textContent = stockText(cell.getRow().getData());
		return span;
	}

	function stockText(data) {
		if (data.manage_stock === 'parent') {
			return t('inherited', 'Inherited (parent)');
		}
		if (data.manage_stock === true && data.stock_quantity !== null && data.stock_quantity !== undefined && data.stock_quantity !== '') {
			return String(data.stock_quantity);
		}
		if (data.stock_status === 'outofstock') {
			return t('outOfStock', 'Out of stock');
		}
		if (data.stock_status === 'onbackorder') {
			return t('onBackorder', 'On backorder');
		}
		return t('inStock', 'In stock');
	}

	function statusFormatter(cell) {
		var span = document.createElement('span');
		span.textContent = statusLabel(cell.getValue());
		return span;
	}

	function statusLabel(status) {
		var map = {
			publish: t('statusPublish', 'Published'),
			draft: t('statusDraft', 'Draft'),
			private: t('statusPrivate', 'Private'),
			pending: t('statusPending', 'Pending')
		};
		return Object.prototype.hasOwnProperty.call(map, status) ? map[status] : (status ? String(status) : '');
	}

	function rootRows() {
		if (!table) {
			return [];
		}
		return table.getRows().filter(function (row) {
			if (typeof row.getTreeParent === 'function') {
				return !row.getTreeParent();
			}
			return row.getData().type !== 'variation';
		});
	}

	function setTreeButtons(enabled) {
		expandButton.disabled = !enabled;
		collapseButton.disabled = !enabled;
	}

	function destroyTable() {
		if (table) {
			table.destroy();
			table = null;
		}
	}

	function renderPager(total, totalPages) {
		pagerEl.replaceChildren();
		pagerEl.hidden = false;

		var start = total === 0 ? 0 : ((state.page - 1) * state.per_page) + 1;
		var end = Math.min(state.page * state.per_page, total);
		var summary = document.createElement('span');
		summary.className = 'low-pte-pager-status';
		summary.textContent = t('showing', 'Showing %1$s to %2$s of %3$s products')
			.replace('%1$s', String(start))
			.replace('%2$s', String(end))
			.replace('%3$s', String(total));
		pagerEl.appendChild(summary);

		if (totalPages < 2) {
			return;
		}

		addPagerButton(t('prev', 'Previous'), state.page <= 1, function () {
			goToPage(state.page - 1);
		});

		pageItems(state.page, totalPages).forEach(function (item) {
			if (item === 'ellipsis') {
				var gap = document.createElement('span');
				gap.className = 'low-pte-pager-ellipsis';
				gap.textContent = '...';
				gap.setAttribute('aria-hidden', 'true');
				pagerEl.appendChild(gap);
				return;
			}
			addPageNumber(item);
		});

		addPagerButton(t('next', 'Next'), state.page >= totalPages, function () {
			goToPage(state.page + 1);
		});
	}

	function addPagerButton(label, disabled, onClick) {
		var button = document.createElement('button');
		button.type = 'button';
		button.className = 'button';
		button.textContent = label;
		button.disabled = disabled;
		if (!disabled) {
			button.addEventListener('click', onClick);
		}
		pagerEl.appendChild(button);
	}

	function addPageNumber(pageNumber) {
		var button = document.createElement('button');
		button.type = 'button';
		button.className = pageNumber === state.page ? 'button button-primary' : 'button';
		button.textContent = String(pageNumber);
		button.setAttribute('aria-label', t('pageNumber', 'Page %s').replace('%s', String(pageNumber)));
		if (pageNumber === state.page) {
			button.setAttribute('aria-current', 'page');
		} else {
			button.addEventListener('click', function () {
				goToPage(pageNumber);
			});
		}
		pagerEl.appendChild(button);
	}

	function goToPage(pageNumber) {
		if (pageNumber < 1 || pageNumber === state.page) {
			return;
		}
		state.page = pageNumber;
		commit('push');
	}

	function pageItems(current, total) {
		if (total <= 7) {
			var all = [];
			var index;
			for (index = 1; index <= total; index += 1) {
				all.push(index);
			}
			return all;
		}

		var items = [1];
		var start = Math.max(2, current - 1);
		var end = Math.min(total - 1, current + 1);
		if (start > 2) {
			items.push('ellipsis');
		}
		var page;
		for (page = start; page <= end; page += 1) {
			items.push(page);
		}
		if (end < total - 1) {
			items.push('ellipsis');
		}
		items.push(total);
		return items;
	}
}());
