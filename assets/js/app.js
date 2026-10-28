/**
 * LOW Product Table Editor admin app.
 *
 * Editable Tabulator grid. dataTree is used instead of groupBy because
 * parent rows carry their own cells. Prices stay raw strings.
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
	var saveBar;
	var saveCount;
	var saveButton;
	var discardButton;
	var toastEl;
	var toastTimer = 0;
	var toastRemaining = 0;
	var toastStarted = 0;
	var toastHover = false;
	var toastFocus = false;
	var historyFocusBatch = '';
	var saving = false;
	var dirty = new Map();
	var selectedIds = new Set();
	var selectionMode = 'ids';
	var selectionNote = '';
	var pageTotal = 0;
	var pageCount = 0;
	var selectionBanner;
	var selectionBannerText;
	var selectionToolbar;
	var selectionCount;
	var selectionButton;
	var selectionBlocked;
	var selectionMatchButton;
	var selectionClearButton;
	var cellMessages = new Map();
	var conflicts = new Map();
	var rowStatus = new Map();
	var rowStatusMessage = new Map();
	var modalEl = null;
	var modalReturn = null;
	var tablePanel;
	var historyPanel;
	var historyRequest = 0;

	buildShell();
	syncControls();
	writeUrl('replace');
	load();
	if (state.tab === 'history') {
		loadHistory();
	}

	window.addEventListener('popstate', function () {
		var next = readState();
		if (dirty.size && tableFiltersChanged(state, next)) {
			writeUrl('replace');
			showToast(t('saveOrDiscard', 'Save or discard changes before leaving this page of results.'));
			return;
		}
		var tableChanged = tableFiltersChanged(state, next);
		var historyChanged = historyFiltersChanged(state, next);
		var tabChanged = next.tab !== state.tab;
		window.clearTimeout(searchTimer);
		state = next;
		syncControls();
		syncHistoryControls();
		applyTab();
		if (tableChanged) {
			clearSelectionForNavigation();
			load();
		}
		if (state.tab === 'history' && (historyChanged || tabChanged)) {
			loadHistory();
		}
	});

	window.addEventListener('beforeunload', function (event) {
		if (dirty.size === 0) {
			return;
		}
		event.preventDefault();
		event.returnValue = '';
	});

	function t(key, fallback) {
		return typeof i18n[key] === 'string' && i18n[key] !== '' ? i18n[key] : fallback;
	}

	function readState() {
		var params = new URLSearchParams(window.location.search);
		var perPageValue = parseInt(params.get('per_page') || '', 10);
		var pageValue = parseInt(params.get('paged') || '1', 10);

		var logPage = parseInt(params.get('log_page') || '1', 10);
		var logPerPage = parseInt(params.get('log_per_page') || '20', 10);

		return {
			search: (params.get('search') || '').slice(0, 100),
			category: params.get('category') || '',
			type: oneOf(params.get('type'), ['simple', 'variable']),
			stock_status: oneOf(params.get('stock_status'), ['instock', 'outofstock', 'onbackorder']),
			status: oneOf(params.get('status'), ['publish', 'draft', 'private', 'pending']),
			page: Number.isFinite(pageValue) && pageValue > 0 ? pageValue : 1,
			per_page: perPageOptions.indexOf(perPageValue) !== -1 ? perPageValue : defaultPerPage,
			tab: params.get('tab') === 'history' ? 'history' : 'table',
			log_user: (params.get('log_user') || '').replace(/\D/g, ''),
			log_product: (params.get('log_product') || '').slice(0, 100),
			log_from: dateParam(params.get('log_from')),
			log_to: dateParam(params.get('log_to')),
			log_page: Number.isFinite(logPage) && logPage > 0 ? logPage : 1,
			log_per_page: [10, 20, 50].indexOf(logPerPage) !== -1 ? logPerPage : 20
		};
	}

	function dateParam(value) {
		return /^\d{4}-\d{2}-\d{2}$/.test(value || '') ? value : '';
	}

	function tableFiltersChanged(left, right) {
		return left.search !== right.search || left.category !== right.category || left.type !== right.type || left.stock_status !== right.stock_status || left.status !== right.status || left.page !== right.page || left.per_page !== right.per_page;
	}

	function historyFiltersChanged(left, right) {
		return left.log_user !== right.log_user || left.log_product !== right.log_product || left.log_from !== right.log_from || left.log_to !== right.log_to || left.log_page !== right.log_page || left.log_per_page !== right.log_per_page;
	}

	function oneOf(value, allowed) {
		return allowed.indexOf(value) !== -1 ? value : '';
	}

	function writeUrl(mode) {
		var next = new URLSearchParams(window.location.search);
		['search', 'category', 'type', 'stock_status', 'status', 'per_page', 'paged', 'tab', 'log_user', 'log_product', 'log_from', 'log_to', 'log_page', 'log_per_page'].forEach(function (key) {
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
		if (state.tab === 'history') {
			next.set('tab', 'history');
		}
		if (state.log_user) {
			next.set('log_user', state.log_user);
		}
		if (state.log_product) {
			next.set('log_product', state.log_product);
		}
		if (state.log_from) {
			next.set('log_from', state.log_from);
		}
		if (state.log_to) {
			next.set('log_to', state.log_to);
		}
		if (state.log_page > 1) {
			next.set('log_page', String(state.log_page));
		}
		if (state.log_per_page !== 20) {
			next.set('log_per_page', String(state.log_per_page));
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
		tablePanel = document.createElement('div');
		tablePanel.id = 'low-pte-table-panel';
		tablePanel.className = 'low-pte-table-panel';

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
		tablePanel.appendChild(toolbar);
		buildSelectionBar();

		messageEl = document.createElement('div');
		messageEl.className = 'low-pte-message';
		messageEl.setAttribute('role', 'status');
		messageEl.setAttribute('aria-live', 'polite');
		messageEl.hidden = true;
		tablePanel.appendChild(messageEl);

		skeletonEl = document.createElement('div');
		skeletonEl.className = 'low-pte-skeleton';
		skeletonEl.hidden = true;
		skeletonEl.setAttribute('aria-hidden', 'true');
		for (var i = 0; i < 8; i += 1) {
			var bar = document.createElement('div');
			bar.className = 'low-pte-skeleton-bar';
			skeletonEl.appendChild(bar);
		}
		tablePanel.appendChild(skeletonEl);

		gridEl = document.createElement('div');
		gridEl.className = 'low-pte-grid';
		gridEl.hidden = true;
		gridEl.addEventListener('paste', onPaste, true);
		tablePanel.appendChild(gridEl);

		pagerEl = document.createElement('nav');
		pagerEl.className = 'low-pte-pager';
		pagerEl.setAttribute('aria-label', t('pagination', 'Pagination'));
		pagerEl.hidden = true;
		tablePanel.appendChild(pagerEl);
		mount.appendChild(tablePanel);

		saveBar = document.createElement('div');
		saveBar.className = 'low-pte-savebar';
		saveBar.hidden = true;
		saveCount = document.createElement('p');
		saveCount.className = 'low-pte-save-count';
		saveBar.appendChild(saveCount);
		discardButton = document.createElement('button');
		discardButton.type = 'button';
		discardButton.className = 'button';
		discardButton.textContent = t('discard', 'Discard');
		discardButton.addEventListener('click', discardChanges);
		saveBar.appendChild(discardButton);
		saveButton = document.createElement('button');
		saveButton.type = 'button';
		saveButton.className = 'button button-primary';
		saveButton.textContent = t('saveChanges', 'Save changes');
		saveButton.addEventListener('click', saveChanges);
		saveBar.appendChild(saveButton);
		buildHistory();
		mount.appendChild(saveBar);

		toastEl = document.createElement('div');
		toastEl.className = 'low-pte-toast';
		toastEl.setAttribute('role', 'status');
		toastEl.setAttribute('aria-live', 'polite');
		toastEl.hidden = true;
		mount.appendChild(toastEl);
		bindTabs();
		applyTab();

		document.addEventListener('copy', onCopy, true);
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
		if (blockWhileDirty()) {
			syncControls();
			return;
		}
		window.clearTimeout(searchTimer);
		searchTimer = window.setTimeout(function () {
			state.search = searchInput.value.trim();
			state.page = 1;
			updateFilterBadge();
			commit('replace');
		}, 300);
	}

	function onFilterChange() {
		if (blockWhileDirty()) {
			syncControls();
			return;
		}
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
		if (blockWhileDirty()) {
			syncControls();
			return;
		}
		window.clearTimeout(searchTimer);
		var next = parseInt(perPageSelect.value, 10);
		state.per_page = perPageOptions.indexOf(next) !== -1 ? next : defaultPerPage;
		state.page = 1;
		commit('replace');
	}

	function clearFilters() {
		if (blockWhileDirty()) {
			syncControls();
			return;
		}
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
		clearSelectionForNavigation();
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
		pageTotal = total;
		pageCount = totalPages;

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
				height: 'calc(100vh - 420px)',
				minHeight: 360,
				renderVertical: 'virtual',
				dataTree: true,
				dataTreeChildField: 'children',
				dataTreeElementColumn: 'name',
				dataTreeStartExpanded: true,
				selectableRows: false,
				selectableRange: true,
				selectableRangeColumns: true,
				clipboard: 'copy',
				editTriggerEvent: 'click',
				columnDefaults: {
					headerSort: false,
					resizable: true
				},
				rowFormatter: function (row) {
					var element = row.getElement();
					var type = row.getData().type;
					element.classList.toggle('low-pte-parent-row', type !== 'variation');
					element.classList.toggle('low-pte-variation-row', type === 'variation');
					paintRow(row);
				},
				columns: [
					selectionColumn(),
					column(t('columnProduct', 'Product'), 'name', 360, 2, true, productFormatter, textEditor),
					column(t('columnSoldBy', 'Sold By'), 'sold_by', 120, 1, false, textFormatter),
					column(t('columnRegularPrice', 'Regular price'), 'regular_price', 120, 1, false, textFormatter, textEditor),
					column(t('columnSalePrice', 'Sale price'), 'sale_price', 110, 1, false, textFormatter, textEditor),
					column(t('columnManageStock', 'Manage stock'), 'manage_stock', 160, 1, false, manageFormatter, selectEditor(manageOptions)),
					column(t('columnQuantity', 'Quantity'), 'stock_quantity', 110, 1, false, quantityFormatter, textEditor),
					column(t('columnStock', 'Stock status'), 'stock_status', 140, 1, false, stockStatusFormatter, selectEditor(stockStatusOptions)),
					column(t('columnSku', 'SKU'), 'sku', 120, 1, false, textFormatter, textEditor),
					column(t('columnWeight', 'Weight'), 'weight', 90, 1, false, textFormatter, textEditor),
					column(t('columnDescription', 'Description'), 'description_preview', 180, 2, false, descriptionFormatter),
					column(t('columnVisibility', 'Catalog visibility'), 'catalog_visibility', 150, 1, false, visibilityFormatter, selectEditor(visibilityOptions)),
					column(t('columnStatus', 'Status'), 'status', 120, 1, false, statusFormatter, selectEditor(statusOptions))
				]
			});

			setTreeButtons(true);
			updateSelectionUi();
			reapplyDirty();
		} catch (error) {
			table = null;
			gridEl.replaceChildren();
			showError();
		}
	}

	function buildSelectionBar() {
		selectionBanner = document.createElement('div');
		selectionBanner.className = 'low-pte-select-banner';
		selectionBanner.hidden = true;
		selectionBannerText = document.createElement('p');
		selectionBanner.appendChild(selectionBannerText);
		selectionMatchButton = document.createElement('button');
		selectionMatchButton.type = 'button';
		selectionMatchButton.className = 'button-link';
		selectionMatchButton.textContent = t('selectAllMatching', 'Select all matching');
		selectionMatchButton.addEventListener('click', function () {
			selectionMode = 'filter';
			selectionNote = '';
			updateSelectionUi();
			refreshSelectionChecks();
		});
		selectionClearButton = document.createElement('button');
		selectionClearButton.type = 'button';
		selectionClearButton.className = 'button-link';
		selectionClearButton.textContent = t('clearSelection', 'Clear selection');
		selectionClearButton.addEventListener('click', function () {
			selectedIds.clear();
			selectionMode = 'ids';
			selectionNote = '';
			updateSelectionUi();
			refreshSelectionChecks();
		});
		selectionBanner.appendChild(selectionMatchButton);
		selectionBanner.appendChild(selectionClearButton);
		tablePanel.appendChild(selectionBanner);

		selectionToolbar = document.createElement('div');
		selectionToolbar.className = 'low-pte-select-toolbar';
		selectionToolbar.hidden = true;
		selectionCount = document.createElement('span');
		selectionButton = document.createElement('button');
		selectionButton.type = 'button';
		selectionButton.className = 'button button-primary';
		selectionButton.textContent = t('bulkPrice', 'Bulk price action');
		selectionButton.addEventListener('click', function () {
			if (window.LOW_PTE_BULK && !selectionButton.disabled) {
				window.LOW_PTE_BULK.open(selectionButton);
			}
		});
		selectionBlocked = document.createElement('p');
		selectionBlocked.className = 'low-pte-select-blocked';
		selectionBlocked.hidden = true;
		selectionBlocked.textContent = t('bulkBlocked', 'Save or discard changes before using a bulk action.');
		selectionToolbar.appendChild(selectionCount);
		selectionToolbar.appendChild(selectionButton);
		selectionToolbar.appendChild(selectionBlocked);
		tablePanel.appendChild(selectionToolbar);
	}

	function clearSelectionForNavigation() {
		if (selectedIds.size === 0 && selectionMode !== 'filter') {
			return;
		}
		selectedIds.clear();
		selectionMode = 'ids';
		selectionNote = t('selectionCleared', 'The selection was cleared because the page or filters changed.');
		updateSelectionUi();
	}

	function pageTargetIds() {
		var ids = [];
		if (!table) {
			return ids;
		}
		table.getData().forEach(function (row) {
			if (row.type === 'simple') {
				ids.push(row.id);
			}
			(row.children || []).forEach(function (child) {
				ids.push(child.id);
			});
		});
		return ids;
	}

	function rowTargetIds(data) {
		if (!data) {
			return [];
		}
		if (data.type === 'variable') {
			return (data.children || []).map(function (child) {
				return child.id;
			});
		}
		if (data.type === 'simple' || data.type === 'variation') {
			return [data.id];
		}
		return [];
	}

	function isRowSelected(id) {
		return selectionMode === 'filter' || selectedIds.has(id);
	}

	function setPageSelection(on) {
		if (selectionMode === 'filter' && !on) {
			selectionMode = 'ids';
			pageTargetIds().forEach(function (id) {
				selectedIds.add(id);
			});
		}
		pageTargetIds().forEach(function (id) {
			if (on) {
				selectedIds.add(id);
			} else {
				selectedIds.delete(id);
			}
		});
		if (!on) {
			selectionMode = 'ids';
		}
		selectionNote = '';
	}

	function toggleRow(data, on) {
		var ids = rowTargetIds(data);
		if (selectionMode === 'filter') {
			selectionMode = 'ids';
			pageTargetIds().forEach(function (id) {
				selectedIds.add(id);
			});
		}
		ids.forEach(function (id) {
			if (on) {
				selectedIds.add(id);
			} else {
				selectedIds.delete(id);
			}
		});
		selectionNote = '';
	}

	function selectionColumn() {
		return {
			title: '',
			field: '_select',
			width: 48,
			hozAlign: 'center',
			headerSort: false,
			frozen: true,
			titleFormatter: function () {
				var input = document.createElement('input');
				input.type = 'checkbox';
				input.className = 'low-pte-select-page';
				input.tabIndex = 0;
				input.setAttribute('aria-label', t('selectPage', 'Select all rows on this page'));
				var ids = pageTargetIds();
				var selected = ids.filter(isRowSelected).length;
				input.checked = ids.length > 0 && (selectionMode === 'filter' || selected === ids.length);
				input.indeterminate = selectionMode !== 'filter' && selected > 0 && selected < ids.length;
				input.addEventListener('mousedown', function (event) {
					event.stopPropagation();
				});
				input.addEventListener('change', function () {
					setPageSelection(input.checked);
					refreshSelectionChecks();
					updateSelectionUi();
				});
				return input;
			},
			formatter: function (cell) {
				var data = cell.getRow().getData();
				var ids = rowTargetIds(data);
				var input = document.createElement('input');
				input.type = 'checkbox';
				input.className = 'low-pte-select';
				input.tabIndex = 0;
				input.setAttribute('aria-label', t('selectRow', 'Select row'));
				var selected = ids.filter(isRowSelected).length;
				input.checked = ids.length > 0 && selected === ids.length;
				input.indeterminate = selected > 0 && selected < ids.length;
				input.disabled = ids.length === 0;
				input.addEventListener('mousedown', function (event) {
					event.stopPropagation();
				});
				input.addEventListener('click', function (event) {
					event.stopPropagation();
				});
				input.addEventListener('change', function () {
					toggleRow(data, input.checked);
					refreshSelectionChecks();
					updateSelectionUi();
				});
				return input;
			}
		};
	}

	function refreshSelectionChecks() {
		if (!table) {
			return;
		}
		table.getRows().forEach(function (row) {
			reformatSelectionRow(row);
			if (typeof row.getTreeChildren === 'function') {
				row.getTreeChildren().forEach(reformatSelectionRow);
			}
		});
		syncHeaderCheckbox();
	}

	function reformatSelectionRow(row) {
		try {
			row.reformat();
		} catch (error) {
			return;
		}
	}

	function syncHeaderCheckbox() {
		if (!gridEl) {
			return;
		}
		var input = gridEl.querySelector('.low-pte-select-page');
		if (!input) {
			return;
		}
		var ids = pageTargetIds();
		var selected = ids.filter(isRowSelected).length;
		input.checked = ids.length > 0 && (selectionMode === 'filter' || selected === ids.length);
		input.indeterminate = selectionMode !== 'filter' && selected > 0 && selected < ids.length;
	}

	function updateSelectionUi() {
		if (!selectionToolbar) {
			return;
		}
		var count = selectionMode === 'filter' ? pageTargetIds().length : selectedIds.size;
		var active = selectionMode === 'filter' || selectedIds.size > 0;
		selectionToolbar.hidden = !active;
		selectionCount.textContent = selectionMode === 'filter'
			? t('allMatchingSelected', 'All items matching the current filters are selected')
			: t('itemsSelected', '%s items selected').replace('%s', String(selectedIds.size));
		var blocked = dirty.size > 0;
		selectionButton.disabled = !active || blocked;
		selectionBlocked.hidden = !blocked || !active;
		var pageIds = pageTargetIds();
		var showPageBanner = selectionMode !== 'filter' && pageCount > 1 && pageIds.length > 0 && pageIds.every(isRowSelected);
		selectionBanner.hidden = !showPageBanner && !selectionNote;
		if (selectionMatchButton) {
			selectionMatchButton.hidden = !showPageBanner;
			selectionClearButton.hidden = !showPageBanner;
		}
		if (showPageBanner) {
			selectionBannerText.textContent = t('selectPageBanner', '%1$s items on this page are selected. Select all %2$s items matching the current filters.')
				.replace('%1$s', String(count))
				.replace('%2$s', String(pageTotal));
		} else {
			selectionBannerText.textContent = selectionNote;
		}
		syncHeaderCheckbox();
	}

	function column(title, field, minWidth, grow, frozen, formatter, editor) {
		var definition = {
			title: title,
			field: field,
			minWidth: minWidth,
			widthGrow: grow,
			frozen: frozen,
			headerSort: false,
			formatter: formatter
		};

		if (editor) {
			definition.editor = editor;
			definition.editable = function (cell) {
				return canEdit(cell.getRow().getData(), field);
			};
		}

		if (field === 'description_preview') {
			definition.cellClick = function (event, cell) {
				openDescription(cell);
			};
		}

		return definition;
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

		var icon = rowStatusIcon(data);
		if (icon) {
			wrap.insertBefore(icon, name);
		}

		if (conflicts.has(data.id)) {
			var reload = document.createElement('button');
			reload.type = 'button';
			reload.className = 'low-pte-reload';
			reload.textContent = t('reloadRow', 'Reload row');
			reload.addEventListener('mousedown', function (event) {
				event.preventDefault();
				event.stopPropagation();
			});
			reload.addEventListener('click', function (event) {
				event.preventDefault();
				event.stopPropagation();
				reloadRow(data.id);
			});
			wrap.appendChild(reload);
		}

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

	function manageFormatter(cell) {
		var span = document.createElement('span');
		span.textContent = manageLabel(effective(cell.getRow().getData(), 'manage_stock'));
		return span;
	}

	function quantityFormatter(cell) {
		var data = cell.getRow().getData();
		var manage = effective(data, 'manage_stock');
		var span = document.createElement('span');
		if (manage === 'parent') {
			span.textContent = t('inheritedStock', 'Inherited');
		} else if (manage !== true) {
			span.textContent = t('notManaged', '-');
		} else {
			var quantity = effective(data, 'stock_quantity');
			span.textContent = quantity === null || quantity === undefined || quantity === '' ? '' : String(quantity);
		}
		return span;
	}

	function stockStatusFormatter(cell) {
		var span = document.createElement('span');
		span.textContent = stockStatusLabel(effective(cell.getRow().getData(), 'stock_status'));
		return span;
	}

	function visibilityFormatter(cell) {
		var span = document.createElement('span');
		span.textContent = visibilityLabel(cell.getValue());
		return span;
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
		if (blockWhileDirty()) {
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

	function blockWhileDirty() {
		if (dirty.size === 0) {
			return false;
		}
		showToast(t('saveOrDiscard', 'Save or discard changes before leaving this page of results.'));
		return true;
	}

	function showToast(message, duration) {
		window.clearTimeout(toastTimer);
		toastTimer = 0;
		toastHover = false;
		toastFocus = false;
		toastEl.onmouseenter = null;
		toastEl.onmouseleave = null;
		toastEl.onfocusin = null;
		toastEl.onfocusout = null;
		toastEl.hidden = false;
		toastEl.textContent = message;
		toastRemaining = duration || 5000;
		armToast(toastRemaining);
	}

	function armToast(ms) {
		toastRemaining = ms;
		toastStarted = Date.now();
		window.clearTimeout(toastTimer);
		toastTimer = window.setTimeout(function () {
			toastTimer = 0;
			if (toastHover || toastFocus) {
				return;
			}
			toastEl.hidden = true;
		}, ms);
	}

	function pauseToast() {
		if (!toastTimer) {
			return;
		}
		toastRemaining = Math.max(0, toastRemaining - (Date.now() - toastStarted));
		window.clearTimeout(toastTimer);
		toastTimer = 0;
	}

	function resumeToast() {
		if (toastEl.hidden || toastHover || toastFocus) {
			return;
		}
		if (toastRemaining <= 0) {
			toastEl.hidden = true;
			return;
		}
		armToast(toastRemaining);
	}

	function showUndoToast(savedCount, batchIds) {
		window.clearTimeout(toastTimer);
		toastTimer = 0;
		toastEl.replaceChildren();
		toastEl.hidden = false;
		var text = document.createElement('span');
		text.textContent = t('savedItems', 'Saved %s items.').replace('%s', String(savedCount));
		var button = document.createElement('button');
		button.type = 'button';
		button.className = 'low-pte-toast-undo';
		button.textContent = t('undo', 'Undo');
		button.addEventListener('click', function () {
			button.disabled = true;
			runUndo(batchIds, { toast: true });
		});
		toastEl.appendChild(text);
		toastEl.appendChild(button);
		toastEl.onmouseenter = function () {
			toastHover = true;
			pauseToast();
		};
		toastEl.onmouseleave = function () {
			toastHover = false;
			resumeToast();
		};
		toastEl.onfocusin = function () {
			toastFocus = true;
			pauseToast();
		};
		toastEl.onfocusout = function () {
			toastFocus = false;
			resumeToast();
		};
		armToast(15000);
	}

	function restRoot() {
		return String(config.restUrl || '').replace(/\/?$/, '/');
	}

	function canEdit(data, field) {
		if (!data) {
			return false;
		}
		var manage = effective(data, 'manage_stock');
		if (field === 'stock_quantity' && manage !== true) {
			return false;
		}
		if (field === 'stock_status' && manage === true) {
			return false;
		}
		var allowed = [];
		if (data.type === 'variation') {
			allowed = ['regular_price', 'sale_price', 'sku', 'weight', 'manage_stock', 'stock_quantity', 'stock_status', 'description'];
		} else if (data.type === 'variable') {
			allowed = ['sku', 'name', 'status', 'catalog_visibility', 'description', 'short_description'];
		} else if (data.type === 'simple') {
			allowed = ['regular_price', 'sale_price', 'sku', 'weight', 'manage_stock', 'stock_quantity', 'stock_status', 'name', 'status', 'catalog_visibility', 'description', 'short_description'];
		}
		return allowed.indexOf(field) !== -1;
	}

	function effective(data, field) {
		var entry = dirty.get(data.id);
		if (entry && Object.prototype.hasOwnProperty.call(entry.fields, field)) {
			return entry.fields[field].value;
		}
		return data[field];
	}

	function paintRow(row) {
		if (!row || typeof row.getCells !== 'function') {
			return;
		}
		var data = row.getData();
		var cells;
		try {
			cells = row.getCells();
		} catch (error) {
			return;
		}
		cells.forEach(function (cell) {
			var element = cell.getElement();
			if (!element) {
				return;
			}
			var field = cell.getField();
			var interactive = field === 'description_preview' ? canEdit(data, 'description') : canEdit(data, field);
			element.classList.toggle('low-pte-readonly', !interactive);
			element.classList.toggle('low-pte-description', field === 'description_preview' && interactive);
			element.classList.toggle('low-pte-dirty', isDirtyField(data.id, field));
			var message = messageFor(data.id, field);
			element.classList.toggle('low-pte-error', message !== '');
			if (message) {
				element.title = message;
				element.setAttribute('data-low-pte-tip', '1');
			} else if (element.getAttribute('data-low-pte-tip') === '1') {
				element.removeAttribute('title');
				element.removeAttribute('data-low-pte-tip');
			}
			if (field === 'description_preview' && canEdit(data, 'description')) {
				element.tabIndex = 0;
			}
		});
	}

	function isDirtyField(id, field) {
		var entry = dirty.get(id);
		if (!entry) {
			return false;
		}
		if (field === 'description_preview') {
			return !!(entry.fields.description || entry.fields.short_description);
		}
		return Object.prototype.hasOwnProperty.call(entry.fields, field);
	}

	function messageFor(id, field) {
		if (field === 'description_preview') {
			return cellMessages.get(id + ':description') || cellMessages.get(id + ':short_description') || '';
		}
		return cellMessages.get(id + ':' + field) || '';
	}

	function textEditor(cell, onRendered, success, cancel) {
		var input = document.createElement('input');
		input.type = 'text';
		input.className = 'low-pte-cell-input';
		input.setAttribute('aria-label', cell.getColumn().getDefinition().title || '');
		var current = cell.getValue();
		input.value = current === null || current === undefined ? '' : String(current);
		return finishEditor(cell, input, function () {
			return input.value;
		}, onRendered, success, cancel, true);
	}

	function selectEditor(optionBuilder) {
		return function (cell, onRendered, success, cancel) {
			var select = document.createElement('select');
			select.className = 'low-pte-cell-input';
			select.setAttribute('aria-label', cell.getColumn().getDefinition().title || '');
			optionBuilder(cell).forEach(function (option) {
				var node = document.createElement('option');
				node.value = option.value;
				node.textContent = option.label;
				select.appendChild(node);
			});
			var field = cell.getField();
			var current = effective(cell.getRow().getData(), field);
			if (field === 'manage_stock') {
				current = current === true ? 'true' : current === 'parent' ? 'parent' : 'false';
			}
			select.value = current === null || current === undefined ? '' : String(current);
			return finishEditor(cell, select, function () {
				return select.value;
			}, onRendered, success, cancel, false);
		};
	}

	function finishEditor(cell, control, readValue, onRendered, success, cancel, selectText) {
		var closed = false;

		function commit(move) {
			if (closed) {
				return;
			}
			closed = true;
			var row = cell.getRow();
			var field = cell.getField();
			var result = applyEdit(row, field, readValue());
			if (!result.ok) {
				cancel();
				paintRow(row);
				return;
			}
			success(result.value);
			window.setTimeout(function () {
				if (field === 'manage_stock') {
					row.reformat();
				} else {
					paintRow(row);
				}
				if (move) {
					moveFrom(row, field, move);
				}
			}, 0);
		}

		control.addEventListener('keydown', function (event) {
			if (event.key === 'Enter') {
				event.preventDefault();
				event.stopPropagation();
				commit('down');
			} else if (event.key === 'Tab') {
				event.preventDefault();
				event.stopPropagation();
				commit(event.shiftKey ? 'left' : 'right');
			} else if (event.key === 'Escape') {
				event.preventDefault();
				event.stopPropagation();
				closed = true;
				cancel();
			}
		});
		control.addEventListener('blur', function () {
			commit(null);
		});
		onRendered(function () {
			control.focus();
			if (selectText && typeof control.select === 'function') {
				control.select();
			}
		});
		return control;
	}

	function manageOptions(cell) {
		var options = [
			{ value: 'true', label: t('yes', 'Yes') },
			{ value: 'false', label: t('no', 'No') }
		];
		if (cell.getRow().getData().type === 'variation') {
			options.push({ value: 'parent', label: t('manageParent', 'Inherit from parent') });
		}
		return options;
	}

	function stockStatusOptions() {
		return [
			{ value: 'instock', label: t('inStock', 'In stock') },
			{ value: 'outofstock', label: t('outOfStock', 'Out of stock') },
			{ value: 'onbackorder', label: t('onBackorder', 'On backorder') }
		];
	}

	function statusOptions() {
		return [
			{ value: 'publish', label: t('statusPublish', 'Published') },
			{ value: 'draft', label: t('statusDraft', 'Draft') },
			{ value: 'private', label: t('statusPrivate', 'Private') }
		];
	}

	function visibilityOptions() {
		return [
			{ value: 'visible', label: t('visibilityVisible', 'Catalog and search') },
			{ value: 'catalog', label: t('visibilityCatalog', 'Catalog') },
			{ value: 'search', label: t('visibilitySearch', 'Search') },
			{ value: 'hidden', label: t('visibilityHidden', 'Hidden') }
		];
	}

	function applyEdit(row, field, rawValue) {
		var data = row.getData();
		var normalized = normalizeInput(field, rawValue);
		var message = validateInput(data, field, normalized);
		var key = data.id + ':' + field;
		if (message) {
			cellMessages.set(key, message);
			return { ok: false, message: message, value: normalized };
		}
		cellMessages.delete(key);
		storeEdit(data, field, normalized);
		rowStatus.delete(data.id);
		rowStatusMessage.delete(data.id);
		updateSaveBar();
		return { ok: true, message: '', value: data[field] };
	}

	function stripLoneSurrogates(value) {
		var text = String(value);
		var output = '';
		var index;
		for (index = 0; index < text.length; index += 1) {
			var code = text.charCodeAt(index);
			if (code >= 0xD800 && code <= 0xDBFF) {
				var next = text.charCodeAt(index + 1);
				if (next >= 0xDC00 && next <= 0xDFFF) {
					output += text.charAt(index) + text.charAt(index + 1);
					index += 1;
				}
				continue;
			}
			if (code >= 0xDC00 && code <= 0xDFFF) {
				continue;
			}
			output += text.charAt(index);
		}
		return output;
	}

	function normalizeInput(field, rawValue) {
		if (field === 'description' || field === 'short_description') {
			return rawValue === null || rawValue === undefined ? '' : stripLoneSurrogates(rawValue);
		}
		var text = rawValue === null || rawValue === undefined ? '' : String(rawValue).trim();
		if (field === 'manage_stock') {
			if (text === 'parent') {
				return 'parent';
			}
			if (text === 'true' || text === '1') {
				return true;
			}
			if (text === 'false' || text === '0') {
				return false;
			}
			return text;
		}
		if (field === 'stock_quantity' && /^-?\d+$/.test(text)) {
			return Number(text);
		}
		return text;
	}

	function validateInput(data, field, value) {
		if (field === 'regular_price') {
			if (value === '') {
				return t('regularRequired', 'Regular price is required.');
			}
			if (!/^\d+(\.\d{1,4})?$/.test(value)) {
				return t('priceFormat', 'Use a number with up to 4 decimal places.');
			}
			var sale = String(effective(data, 'sale_price') || '');
			if (sale && !priceLess(sale, value)) {
				return t('saleLower', 'Sale price must be lower than the regular price.');
			}
			return '';
		}
		if (field === 'sale_price') {
			if (value === '') {
				return '';
			}
			if (!/^\d+(\.\d{1,4})?$/.test(value)) {
				return t('priceFormat', 'Use a number with up to 4 decimal places.');
			}
			var regular = String(effective(data, 'regular_price') || '');
			if (!regular || !priceLess(value, regular)) {
				return t('saleLower', 'Sale price must be lower than the regular price.');
			}
			return '';
		}
		if (field === 'weight') {
			if (value === '') {
				return '';
			}
			if (!/^\d+(\.\d+)?$/.test(value)) {
				return t('weightInvalid', 'Weight must be zero or greater.');
			}
			return '';
		}
		if (field === 'name') {
			if (!value) {
				return t('nameRequired', 'Name is required.');
			}
			if (value.length > 200) {
				return t('nameTooLong', 'Name must be 200 characters or fewer.');
			}
			return '';
		}
		if (field === 'stock_quantity' && !/^-?\d+$/.test(String(value))) {
			return t('quantityInvalid', 'Stock quantity must be a whole number.');
		}
		return '';
	}

	function priceLess(sale, regular) {
		var left = Number(sale);
		var right = Number(regular);
		return Number.isFinite(left) && Number.isFinite(right) && left < right;
	}

	function storeEdit(data, field, normalized) {
		var entry = dirty.get(data.id);
		var original = entry && Object.prototype.hasOwnProperty.call(entry.fields, field) ? entry.fields[field].original : data[field];
		if (valuesEqual(field, original, normalized)) {
			data[field] = original;
			if (field === 'description' && entry) {
				data.description_preview = entry.previewOriginal;
			}
			if (entry && entry.fields[field]) {
				delete entry.fields[field];
				if (Object.keys(entry.fields).length === 0) {
					dirty.delete(data.id);
				}
			}
			return;
		}
		if (!entry) {
			entry = {
				date_modified: Number(data.date_modified) || 0,
				fields: {},
				previewOriginal: data.description_preview
			};
			dirty.set(data.id, entry);
		}
		if (!entry.fields[field]) {
			entry.fields[field] = { original: original, value: normalized };
		} else {
			entry.fields[field].value = normalized;
		}
		data[field] = normalized;
		if (field === 'description') {
			data.description_preview = previewText(normalized);
		}
	}

	function valuesEqual(field, left, right) {
		if (field === 'manage_stock') {
			return left === right;
		}
		if (field === 'stock_quantity') {
			if (left === null || left === undefined || left === '') {
				return right === null || right === undefined || right === '';
			}
			return Number(left) === Number(right);
		}
		var leftText = left === null || left === undefined ? '' : String(left);
		var rightText = right === null || right === undefined ? '' : String(right);
		return leftText === rightText;
	}

	function previewText(html) {
		var text = String(html).replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
		var words = text ? text.split(' ') : [];
		if (words.length <= 20) {
			return text;
		}
		return words.slice(0, 20).join(' ') + '...';
	}

	function changeCount() {
		var count = 0;
		dirty.forEach(function (entry) {
			count += Object.keys(entry.fields).length;
		});
		return count;
	}

	function updateSaveBar() {
		var count = changeCount();
		saveBar.hidden = count === 0;
		var template = count === 1 ? t('unsavedChange', '%s unsaved change') : t('unsavedChanges', '%s unsaved changes');
		saveCount.textContent = template.replace('%s', String(count));
		saveButton.disabled = saving || count === 0;
		discardButton.disabled = saving;
		updateSelectionUi();
	}

	function discardChanges() {
		if (saving) {
			return;
		}
		dirty.forEach(function (entry, id) {
			var row = findRow(id);
			if (!row) {
				return;
			}
			var data = row.getData();
			Object.keys(entry.fields).forEach(function (field) {
				data[field] = entry.fields[field].original;
			});
			if (entry.fields.description || entry.fields.short_description) {
				data.description_preview = entry.previewOriginal;
			}
			row.update(data);
		});
		dirty.clear();
		cellMessages.clear();
		conflicts.clear();
		rowStatus.clear();
		rowStatusMessage.clear();
		updateSaveBar();
		repaintRows();
	}

	function saveChanges() {
		if (saving || dirty.size === 0) {
			return;
		}
		saving = true;
		updateSaveBar();
		sendBatch(collectItems(), 0, 0);
	}

	function collectItems() {
		var items = [];
		dirty.forEach(function (entry, id) {
			var fields = {};
			Object.keys(entry.fields).forEach(function (field) {
				fields[field] = entry.fields[field].value;
			});
			items.push({
				id: id,
				date_modified: entry.date_modified,
				fields: fields
			});
		});
		return items;
	}

	function firstFailureMessage(body) {
		var results = body && Array.isArray(body.results) ? body.results : [];
		var index;
		for (index = 0; index < results.length; index += 1) {
			var result = results[index];
			if (!result || result.status === 'ok') {
				continue;
			}
			if (result.errors) {
				var fields = Object.keys(result.errors);
				if (fields.length && result.errors[fields[0]]) {
					return String(result.errors[fields[0]]);
				}
			}
			if (result.message) {
				return String(result.message);
			}
		}
		return '';
	}

	function sendBatch(items, index, savedCount, failureText, logFailed, batchIds) {
		var ids = batchIds || [];
		if (index >= items.length) {
			saving = false;
			updateSaveBar();
			if (savedCount > 0 && logFailed) {
				showToast(t('logFailed', 'Saved, but the change could not be logged, so it cannot be undone.'));
			} else if (savedCount > 0 && ids.length) {
				showUndoToast(savedCount, ids);
			} else if (savedCount > 0) {
				showToast(t('savedItems', 'Saved %s items.').replace('%s', String(savedCount)));
			} else {
				showToast(failureText || t('saveFailed', 'The changes could not be saved.'));
			}
			return;
		}
		patchItems(items.slice(index, index + 50)).then(function (result) {
			if (!result.response.ok) {
				saving = false;
				updateSaveBar();
				var code = result.body && result.body.code ? String(result.body.code) : '';
				if (result.response.status === 401 || result.response.status === 403 || code === 'rest_cookie_invalid_nonce') {
					showToast(t('sessionExpired', 'Your session expired. Reload the page.'));
					return;
				}
				showToast(result.body && result.body.message ? String(result.body.message) : (failureText || t('saveFailed', 'The changes could not be saved.')));
				return;
			}
			var added = applyResults(result.body);
			var nextFailure = failureText || '';
			if (added === 0) {
				nextFailure = nextFailure || firstFailureMessage(result.body);
			}
			var nextLogFailed = !!logFailed || !!(result.body && result.body.log_failed);
			var nextIds = ids.slice();
			if (result.body && result.body.batch_id && added > 0) {
				nextIds.push(String(result.body.batch_id));
			}
			sendBatch(items, index + 50, savedCount + added, nextFailure, nextLogFailed, nextIds);
		}).catch(function () {
			saving = false;
			updateSaveBar();
			showToast(failureText || t('saveFailed', 'The changes could not be saved.'));
		});
	}

	function patchItems(items) {
		return window.fetch(restRoot() + 'items', {
			method: 'PATCH',
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': config.nonce || '',
				Accept: 'application/json',
				'Content-Type': 'application/json'
			},
			body: JSON.stringify({ items: items }, function (key, value) {
				return typeof value === 'string' ? stripLoneSurrogates(value) : value;
			})
		}).then(function (response) {
			return response.json().then(function (body) {
				return { response: response, body: body };
			}).catch(function () {
				return { response: response, body: {} };
			});
		});
	}

	function applyResults(body) {
		var results = body && Array.isArray(body.results) ? body.results : [];
		var saved = 0;
		results.forEach(function (result) {
			if (!result || !result.id) {
				return;
			}
			if (result.status === 'ok') {
				saved += 1;
				dirty.delete(result.id);
				clearFieldMessages(result.id);
				conflicts.delete(result.id);
				rowStatus.set(result.id, 'saved');
				rowStatusMessage.set(result.id, t('saved', 'Saved'));
				try {
					applyServerRow(result.id, result.item);
					if (result.parent) {
						applyServerRow(result.parent.id, result.parent);
					}
				} catch (error) {
					// The server save succeeded. A row redraw must not report a failed save.
				}
				return;
			}
			if (result.status === 'conflict') {
				conflicts.set(result.id, true);
				rowStatus.set(result.id, 'error');
				rowStatusMessage.set(result.id, result.message || '');
				repaintRow(result.id);
				return;
			}
			rowStatus.set(result.id, 'error');
			rowStatusMessage.set(result.id, result.message || t('rowError', 'This row has an error.'));
			if (result.errors) {
				Object.keys(result.errors).forEach(function (field) {
					cellMessages.set(result.id + ':' + field, String(result.errors[field]));
				});
			}
			repaintRow(result.id);
		});
		updateSaveBar();
		return saved;
	}

	function applyServerRow(id, item) {
		var row = findRow(id);
		if (!row || !item) {
			return;
		}
		var data = row.getData();
		var children = data.children;
		var next = Object.assign({}, data, item);
		if (children) {
			next.children = children;
		}
		var entry = dirty.get(id);
		if (entry) {
			entry.date_modified = Number(item.date_modified) || 0;
			Object.keys(entry.fields).forEach(function (field) {
				next[field] = entry.fields[field].value;
			});
			if (entry.fields.description) {
				next.description_preview = previewText(entry.fields.description.value);
			} else if (entry.previewOriginal) {
				next.description_preview = entry.previewOriginal;
			}
		}
		row.update(next);
		if (typeof row.reformat === 'function') {
			row.reformat();
		}
		paintRow(row);
	}

	function reapplyDirty() {
		if (!table || dirty.size === 0) {
			return;
		}
		dirty.forEach(function (entry, id) {
			var row = findRow(id);
			if (!row) {
				return;
			}
			applyServerRow(id, row.getData());
		});
		updateSaveBar();
	}

	function clearFieldMessages(id) {
		Array.from(cellMessages.keys()).forEach(function (key) {
			if (key.indexOf(id + ':') === 0) {
				cellMessages.delete(key);
			}
		});
	}

	function reloadRow(id) {
		fetchItem(id).then(function (item) {
			dirty.delete(id);
			conflicts.delete(id);
			clearFieldMessages(id);
			rowStatus.delete(id);
			rowStatusMessage.delete(id);
			applyServerRow(id, item);
			updateSaveBar();
			showToast(t('rowReloaded', 'Row reloaded.'));
		}).catch(function (error) {
			if (error && error.session) {
				showToast(t('sessionExpired', 'Your session expired. Reload the page.'));
				return;
			}
			showToast(t('rowReloadFailed', 'The row could not be reloaded.'));
		});
	}

	function fetchItem(id) {
		return window.fetch(restRoot() + 'items/' + id, {
			method: 'GET',
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': config.nonce || '',
				Accept: 'application/json'
			}
		}).then(function (response) {
			return response.json().then(function (body) {
				return { response: response, body: body };
			}).catch(function () {
				return { response: response, body: {} };
			});
		}).then(function (result) {
			var code = result.body && result.body.code ? String(result.body.code) : '';
			if (result.response.status === 401 || result.response.status === 403 || code === 'rest_cookie_invalid_nonce') {
				var sessionError = new Error('session');
				sessionError.session = true;
				throw sessionError;
			}
			if (!result.response.ok) {
				throw new Error('load');
			}
			return result.body;
		});
	}

	function findRow(id) {
		if (!table) {
			return null;
		}
		var found = null;
		try {
			found = table.getRow(id);
		} catch (error) {
			found = null;
		}
		if (found) {
			return found;
		}
		visibleRows().some(function (row) {
			if (row.getData().id === id) {
				found = row;
				return true;
			}
			return false;
		});
		return found;
	}

	function repaintRow(id) {
		var row = findRow(id);
		if (!row) {
			return;
		}
		row.reformat();
		paintRow(row);
	}

	function repaintRows() {
		visibleRows().forEach(function (row) {
			row.reformat();
		});
	}

	function visibleRows() {
		var rows = [];
		if (!table) {
			return rows;
		}
		function walk(list) {
			list.forEach(function (row) {
				rows.push(row);
				if (typeof row.getTreeChildren !== 'function') {
					return;
				}
				var children = row.getTreeChildren() || [];
				var expanded = typeof row.isTreeExpanded === 'function' ? row.isTreeExpanded() : true;
				if (expanded && children.length) {
					walk(children);
				}
			});
		}
		walk(table.getRows());
		return rows;
	}

	function moveFrom(row, field, direction) {
		window.setTimeout(function () {
			var rows = visibleRows();
			var rowIndex = -1;
			rows.forEach(function (item, index) {
				if (item.getData().id === row.getData().id) {
					rowIndex = index;
				}
			});
			if (rowIndex < 0) {
				return;
			}
			var fields = row.getCells().map(function (cell) {
				return cell.getField();
			});
			var colIndex = fields.indexOf(field);
			var target = null;
			if (direction === 'left' || direction === 'right') {
				var step = direction === 'right' ? 1 : -1;
				var nextRow = rowIndex;
				var nextCol = colIndex + step;
				while (nextRow >= 0 && nextRow < rows.length && !target) {
					var rowFields = rows[nextRow].getCells().map(function (cell) {
						return cell.getField();
					});
					while (nextCol >= 0 && nextCol < rowFields.length) {
						if (canEdit(rows[nextRow].getData(), rowFields[nextCol])) {
							target = rows[nextRow].getCell(rowFields[nextCol]);
							break;
						}
						nextCol += step;
					}
					if (!target) {
						nextRow += step;
						nextCol = step > 0 ? 0 : rowFields.length - 1;
					}
				}
			} else {
				var rowStep = direction === 'down' ? 1 : -1;
				var cursor = rowIndex + rowStep;
				while (cursor >= 0 && cursor < rows.length) {
					if (canEdit(rows[cursor].getData(), field)) {
						target = rows[cursor].getCell(field);
						break;
					}
					cursor += rowStep;
				}
			}
			if (target && typeof target.edit === 'function') {
				target.edit();
			}
		}, 0);
	}

	function onCopy(event) {
		var target = event.target;
		if (target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.isContentEditable)) {
			return;
		}
		if (!table || !gridEl || gridEl.hidden) {
			return;
		}
		var text = rangeToTsv();
		if (!text) {
			return;
		}
		event.preventDefault();
		event.stopImmediatePropagation();
		if (event.clipboardData) {
			event.clipboardData.setData('text/plain', text);
		}
	}

	function rangeToTsv() {
		if (!table || typeof table.getRanges !== 'function') {
			return '';
		}
		var ranges = table.getRanges();
		if (!ranges || !ranges.length || typeof ranges[0].getCells !== 'function') {
			return '';
		}
		var cells = ranges[0].getCells();
		if (!cells || !cells.length || !Array.isArray(cells[0])) {
			return '';
		}
		return cells.map(function (rowCells) {
			return rowCells.map(function (cell) {
				return copyValue(cell);
			}).join('\t');
		}).join('\n');
	}

	function copyValue(cell) {
		var data = cell.getRow().getData();
		var field = cell.getField();
		if (field === 'name' && data.type === 'variation') {
			return data.variation_label ? String(data.variation_label) : '';
		}
		if (field === 'manage_stock') {
			var manage = effective(data, 'manage_stock');
			if (manage === true) {
				return 'true';
			}
			if (manage === 'parent') {
				return 'parent';
			}
			return 'false';
		}
		var value = effective(data, field);
		return value === null || value === undefined ? '' : String(value);
	}

	function onPaste(event) {
		var target = event.target;
		if (!table || !event.clipboardData) {
			return;
		}
		var text = event.clipboardData.getData('text/plain');
		if (!text) {
			return;
		}
		var block = text.indexOf('\t') !== -1 || text.indexOf('\n') !== -1;
		var inField = target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA');
		if (inField && !block) {
			return;
		}
		var start = activeCell();
		if (!start) {
			return;
		}
		event.preventDefault();
		event.stopPropagation();
		if (inField && typeof target.blur === 'function') {
			target.blur();
		}
		pasteBlock(text, start);
	}

	function pasteBlock(text, start) {
		if (!start) {
			return;
		}
		var matrix = parseTsv(text);
		if (!matrix.length) {
			return;
		}
		var rows = visibleRows();
		var startDataId = start.getRow().getData().id;
		var startRowIndex = -1;
		rows.forEach(function (row, index) {
			if (row.getData().id === startDataId) {
				startRowIndex = index;
			}
		});
		if (startRowIndex < 0) {
			return;
		}
		var fields = start.getRow().getCells().map(function (cell) {
			return cell.getField();
		});
		var startCol = fields.indexOf(start.getField());
		if (startCol < 0) {
			return;
		}
		var width = 0;
		matrix.forEach(function (line) {
			width = Math.max(width, line.length);
		});
		var rejected = [];
		var columnIndex;
		for (columnIndex = 0; columnIndex < width; columnIndex += 1) {
			var field = fields[startCol + columnIndex];
			if (!field) {
				break;
			}
			var valueIndex = 0;
			var rowIndex = startRowIndex;
			while (valueIndex < matrix.length && rowIndex < rows.length) {
				var row = rows[rowIndex];
				if (canEdit(row.getData(), field)) {
					var raw = matrix[valueIndex][columnIndex] === undefined ? '' : matrix[valueIndex][columnIndex];
					var result = applyEdit(row, field, raw);
					if (!result.ok) {
						rejected.push(result.message);
					} else {
						var cell = row.getCell(field);
						if (cell) {
							cell.setValue(result.value);
						}
					}
					valueIndex += 1;
				}
				rowIndex += 1;
			}
		}
		repaintRows();
		updateSaveBar();
		if (rejected.length) {
			var unique = [];
			rejected.forEach(function (message) {
				if (unique.indexOf(message) === -1) {
					unique.push(message);
				}
			});
			showToast(t('pasteRejected', '%1$s values were rejected. %2$s').replace('%1$s', String(rejected.length)).replace('%2$s', unique.join(' ')));
		}
	}

	function activeCell() {
		if (!table) {
			return null;
		}
		if (typeof table.getEditedCells === 'function') {
			var edited = table.getEditedCells();
			if (edited && edited.length) {
				return edited[0];
			}
		}
		if (typeof table.getRanges !== 'function') {
			return null;
		}
		var ranges = table.getRanges();
		if (!ranges || !ranges.length || typeof ranges[0].getCells !== 'function') {
			return null;
		}
		var cells = ranges[0].getCells();
		if (cells && cells[0] && cells[0][0]) {
			return cells[0][0];
		}
		return null;
	}

	function parseTsv(text) {
		var lines = String(text).replace(/\r\n/g, '\n').replace(/\r/g, '\n').split('\n');
		if (lines.length && lines[lines.length - 1] === '') {
			lines.pop();
		}
		return lines.map(function (line) {
			return line.split('\t');
		});
	}

	function openDescription(cell) {
		var data = cell.getRow().getData();
		if (!canEdit(data, 'description')) {
			return;
		}
		modalReturn = { id: data.id, field: 'description_preview' };
		renderModal(data, null, true);
		fetchItem(data.id).then(function (item) {
			if (!modalEl || modalEl.getAttribute('data-item-id') !== String(data.id)) {
				return;
			}
			renderModal(data, item, false);
		}).catch(function (error) {
			if (!modalEl) {
				return;
			}
			if (error && error.session) {
				closeModal();
				showToast(t('sessionExpired', 'Your session expired. Reload the page.'));
				return;
			}
			renderModal(data, null, false, true);
		});
	}

	function renderModal(data, item, loading, failed) {
		var description = descriptionDraft(data, item, 'description');
		var shortDescription = descriptionDraft(data, item, 'short_description');
		var showShort = data.type !== 'variation';
		closeModal(false);
		modalEl = document.createElement('div');
		modalEl.className = 'low-pte-modal';
		modalEl.setAttribute('role', 'dialog');
		modalEl.setAttribute('aria-modal', 'true');
		modalEl.setAttribute('aria-labelledby', 'low-pte-modal-title');
		modalEl.setAttribute('data-item-id', String(data.id));

		var card = document.createElement('div');
		card.className = 'low-pte-modal-card';
		var title = document.createElement('h2');
		title.id = 'low-pte-modal-title';
		title.className = 'low-pte-modal-title';
		title.textContent = data.name ? String(data.name) : t('descriptionTitle', 'Description');
		card.appendChild(title);

		if (loading) {
			var loadingText = document.createElement('p');
			loadingText.textContent = t('loadingDescription', 'Loading the description.');
			card.appendChild(loadingText);
		} else if (failed && !dirty.get(data.id)) {
			var errorText = document.createElement('p');
			errorText.className = 'low-pte-message-error';
			errorText.textContent = t('descriptionError', 'The description could not be loaded.');
			card.appendChild(errorText);
		}

		var descriptionInput = labeledArea(t('descriptionTitle', 'Description'), description, 'low-pte-description-input');
		card.appendChild(descriptionInput.wrap);
		var shortInput = null;
		if (showShort) {
			shortInput = labeledArea(t('shortDescription', 'Short description'), shortDescription, 'low-pte-short-input');
			card.appendChild(shortInput.wrap);
		}

		var actions = document.createElement('div');
		actions.className = 'low-pte-modal-actions';
		var cancelButton = document.createElement('button');
		cancelButton.type = 'button';
		cancelButton.className = 'button';
		cancelButton.textContent = t('cancel', 'Cancel');
		cancelButton.addEventListener('click', function () {
			closeModal(true);
		});
		var applyButton = document.createElement('button');
		applyButton.type = 'button';
		applyButton.className = 'button button-primary';
		applyButton.textContent = t('apply', 'Apply');
		applyButton.disabled = !!loading || !!failed;
		applyButton.addEventListener('click', function () {
			var row = findRow(data.id);
			if (!row) {
				closeModal(true);
				return;
			}
			var rowData = row.getData();
			if (item && Number.isFinite(Number(item.date_modified)) && !dirty.get(rowData.id)) {
				rowData.date_modified = Number(item.date_modified);
			}
			seedOriginal(rowData, item, 'description');
			applyEdit(row, 'description', descriptionInput.area.value);
			if (shortInput) {
				seedOriginal(rowData, item, 'short_description');
				applyEdit(row, 'short_description', shortInput.area.value);
			}
			try {
				if (typeof row.reformat === 'function') {
					row.reformat();
				}
			} catch (error) {
				// The edit is already stored. Closing the modal still lets Save run.
			}
			closeModal(true);
		});
		actions.appendChild(cancelButton);
		actions.appendChild(applyButton);
		card.appendChild(actions);
		modalEl.appendChild(card);
		modalEl.addEventListener('keydown', onModalKey);
		document.body.appendChild(modalEl);
		window.setTimeout(function () {
			if (loading) {
				cancelButton.focus();
				return;
			}
			descriptionInput.area.focus();
		}, 0);
	}

	function seedOriginal(rowData, item, field) {
		if (!item || typeof item[field] !== 'string') {
			return;
		}
		var entry = dirty.get(rowData.id);
		if (entry && Object.prototype.hasOwnProperty.call(entry.fields, field)) {
			return;
		}
		if (rowData[field] === undefined) {
			rowData[field] = item[field];
		}
	}

	function descriptionDraft(data, item, field) {
		var entry = dirty.get(data.id);
		if (entry && entry.fields[field]) {
			return String(entry.fields[field].value);
		}
		if (item && typeof item[field] === 'string') {
			return item[field];
		}
		return '';
	}

	function labeledArea(label, value, className) {
		var wrap = document.createElement('label');
		wrap.className = 'low-pte-modal-field';
		var text = document.createElement('span');
		text.className = 'low-pte-field-label';
		text.textContent = label;
		var area = document.createElement('textarea');
		area.className = 'low-pte-modal-area ' + className;
		area.value = value;
		wrap.appendChild(text);
		wrap.appendChild(area);
		return { wrap: wrap, area: area };
	}

	function onModalKey(event) {
		if (event.key === 'Escape') {
			event.preventDefault();
			closeModal(true);
			return;
		}
		if (event.key !== 'Tab' || !modalEl) {
			return;
		}
		var focusable = modalEl.querySelectorAll('textarea, button, input');
		if (!focusable.length) {
			return;
		}
		var first = focusable[0];
		var last = focusable[focusable.length - 1];
		if (event.shiftKey && document.activeElement === first) {
			event.preventDefault();
			last.focus();
		} else if (!event.shiftKey && document.activeElement === last) {
			event.preventDefault();
			first.focus();
		}
	}

	function closeModal(restoreFocus) {
		if (modalEl && modalEl.parentNode) {
			modalEl.parentNode.removeChild(modalEl);
		}
		modalEl = null;
		if (restoreFocus) {
			focusReturnedCell();
		}
	}

	function focusReturnedCell() {
		if (!modalReturn) {
			return;
		}
		var row = findRow(modalReturn.id);
		modalReturn = null;
		if (!row) {
			return;
		}
		var cell = row.getCell('description_preview');
		if (cell && cell.getElement()) {
			cell.getElement().focus();
		}
	}

	function rowStatusIcon(data) {
		var status = rowStatus.get(data.id);
		if (!status) {
			return null;
		}
		var icon = document.createElement('span');
		icon.className = 'low-pte-row-status low-pte-row-status-' + status;
		var mark = document.createElement('span');
		mark.setAttribute('aria-hidden', 'true');
		mark.textContent = status === 'saved' ? '\u2713' : '!';
		var text = document.createElement('span');
		text.className = 'screen-reader-text';
		text.textContent = rowStatusMessage.get(data.id) || (status === 'saved' ? t('saved', 'Saved') : t('rowError', 'This row has an error.'));
		icon.title = text.textContent;
		icon.appendChild(mark);
		icon.appendChild(text);
		return icon;
	}

	function manageLabel(value) {
		if (value === 'parent') {
			return t('manageParent', 'Inherit from parent');
		}
		if (value === true) {
			return t('yes', 'Yes');
		}
		return t('no', 'No');
	}

	function stockStatusLabel(status) {
		if (status === 'outofstock') {
			return t('outOfStock', 'Out of stock');
		}
		if (status === 'onbackorder') {
			return t('onBackorder', 'On backorder');
		}
		if (status === 'instock') {
			return t('inStock', 'In stock');
		}
		return status ? String(status) : '';
	}

	function visibilityLabel(value) {
		var map = {
			visible: t('visibilityVisible', 'Catalog and search'),
			catalog: t('visibilityCatalog', 'Catalog'),
			search: t('visibilitySearch', 'Search'),
			hidden: t('visibilityHidden', 'Hidden')
		};
		return Object.prototype.hasOwnProperty.call(map, value) ? map[value] : (value ? String(value) : '');
	}

	var historyUserSelect;
	var historyProductInput;
	var historyFromInput;
	var historyToInput;
	var historyPerPageSelect;
	var historyList;
	var historyMessage;
	var historyPager;

	function bindTabs() {
		var tableTab = document.getElementById('low-pte-tab-table');
		var historyTab = document.getElementById('low-pte-tab-history');
		if (tableTab) {
			tableTab.addEventListener('click', function () {
				selectTab('table');
			});
		}
		if (historyTab) {
			historyTab.addEventListener('click', function () {
				selectTab('history');
			});
		}
	}

	function selectTab(tab) {
		var next = tab === 'history' ? 'history' : 'table';
		if (next === state.tab) {
			return;
		}
		state.tab = next;
		applyTab();
		writeUrl('push');
		if (next === 'history') {
			loadHistory();
		}
	}

	function applyTab() {
		var history = state.tab === 'history';
		if (tablePanel) {
			tablePanel.hidden = history;
		}
		if (historyPanel) {
			historyPanel.hidden = !history;
		}
		var tableTab = document.getElementById('low-pte-tab-table');
		var historyTab = document.getElementById('low-pte-tab-history');
		if (tableTab) {
			tableTab.classList.toggle('low-pte-tab-active', !history);
			tableTab.setAttribute('aria-selected', history ? 'false' : 'true');
		}
		if (historyTab) {
			historyTab.classList.toggle('low-pte-tab-active', history);
			historyTab.setAttribute('aria-selected', history ? 'true' : 'false');
		}
	}

	function buildHistory() {
		historyPanel = document.createElement('div');
		historyPanel.id = 'low-pte-history';
		historyPanel.className = 'low-pte-history';
		historyPanel.hidden = true;

		var toolbar = document.createElement('div');
		toolbar.className = 'low-pte-toolbar';

		historyUserSelect = document.createElement('select');
		historyUserSelect.addEventListener('change', function () {
			state.log_user = historyUserSelect.value;
			state.log_page = 1;
			writeUrl('push');
			loadHistory();
		});
		toolbar.appendChild(field(t('user', 'User'), historyUserSelect));

		historyProductInput = document.createElement('input');
		historyProductInput.type = 'search';
		historyProductInput.className = 'low-pte-search';
		historyProductInput.maxLength = 100;
		historyProductInput.autocomplete = 'off';
		historyProductInput.addEventListener('input', function () {
			window.clearTimeout(historyProductInput.timer);
			historyProductInput.timer = window.setTimeout(function () {
				state.log_product = historyProductInput.value.trim().slice(0, 100);
				state.log_page = 1;
				writeUrl('push');
				loadHistory();
			}, 300);
		});
		toolbar.appendChild(field(t('product', 'Product'), historyProductInput));

		historyFromInput = document.createElement('input');
		historyFromInput.type = 'date';
		historyFromInput.addEventListener('change', onHistoryDateChange);
		toolbar.appendChild(field(t('dateFrom', 'Date from'), historyFromInput));

		historyToInput = document.createElement('input');
		historyToInput.type = 'date';
		historyToInput.addEventListener('change', onHistoryDateChange);
		toolbar.appendChild(field(t('dateTo', 'Date to'), historyToInput));

		historyPerPageSelect = document.createElement('select');
		fillOptions(historyPerPageSelect, [['10', '10'], ['20', '20'], ['50', '50']]);
		historyPerPageSelect.addEventListener('change', function () {
			state.log_per_page = parseInt(historyPerPageSelect.value, 10) || 20;
			state.log_page = 1;
			writeUrl('push');
			loadHistory();
		});
		toolbar.appendChild(field(t('perPage', 'Per page'), historyPerPageSelect));

		var clearHistory = document.createElement('button');
		clearHistory.type = 'button';
		clearHistory.className = 'button';
		clearHistory.textContent = t('clearFilters', 'Clear filters');
		clearHistory.addEventListener('click', function () {
			state.log_user = '';
			state.log_product = '';
			state.log_from = '';
			state.log_to = '';
			state.log_page = 1;
			syncHistoryControls();
			writeUrl('push');
			loadHistory();
		});
		toolbar.appendChild(clearHistory);
		historyPanel.appendChild(toolbar);

		historyMessage = document.createElement('div');
		historyMessage.className = 'low-pte-message';
		historyMessage.setAttribute('role', 'status');
		historyMessage.hidden = true;
		historyPanel.appendChild(historyMessage);

		historyList = document.createElement('div');
		historyList.className = 'low-pte-history-list';
		historyPanel.appendChild(historyList);

		historyPager = document.createElement('nav');
		historyPager.className = 'low-pte-pager';
		historyPager.setAttribute('aria-label', t('pagination', 'Pagination'));
		historyPager.hidden = true;
		historyPanel.appendChild(historyPager);
		historyPanel.appendChild(buildLogSettings());
		mount.appendChild(historyPanel);
		syncHistoryControls();
	}

	function onHistoryDateChange() {
		state.log_from = dateParam(historyFromInput.value);
		state.log_to = dateParam(historyToInput.value);
		state.log_page = 1;
		writeUrl('push');
		loadHistory();
	}

	function syncHistoryControls() {
		if (!historyUserSelect) {
			return;
		}
		historyProductInput.value = state.log_product;
		historyFromInput.value = state.log_from;
		historyToInput.value = state.log_to;
		historyPerPageSelect.value = String(state.log_per_page);
		if (historyUserSelect.options.length) {
			historyUserSelect.value = state.log_user;
		}
	}

	function buildLogSettings() {
		var settings = config.settings || {};
		var form = document.createElement('form');
		form.className = 'low-pte-log-settings';
		form.method = 'post';
		form.action = settings.actionUrl || '';

		var title = document.createElement('h2');
		title.textContent = t('logSettings', 'Log settings');
		form.appendChild(title);

		var action = document.createElement('input');
		action.type = 'hidden';
		action.name = 'action';
		action.value = 'low_pte_save_settings';
		form.appendChild(action);

		var nonce = document.createElement('input');
		nonce.type = 'hidden';
		nonce.name = 'low_pte_settings_nonce';
		nonce.value = settings.nonce || '';
		form.appendChild(nonce);

		var days = document.createElement('input');
		days.type = 'number';
		days.name = 'low_pte_retention_days';
		days.min = '1';
		days.max = '3650';
		days.required = true;
		days.value = String(settings.retentionDays || 180);
		form.appendChild(field(t('retentionDays', 'Keep log entries for this many days'), days));

		var checkLabel = document.createElement('label');
		checkLabel.className = 'low-pte-check';
		var check = document.createElement('input');
		check.type = 'checkbox';
		check.name = 'low_pte_delete_data_on_uninstall';
		check.value = 'yes';
		check.checked = !!settings.deleteData;
		checkLabel.appendChild(check);
		checkLabel.appendChild(document.createTextNode(t('deleteLogOnUninstall', 'Delete the log table when the plugin is deleted')));
		form.appendChild(checkLabel);

		var submit = document.createElement('button');
		submit.type = 'submit';
		submit.className = 'button button-primary';
		submit.textContent = t('saveSettings', 'Save settings');
		form.appendChild(submit);
		return form;
	}

	function loadHistory() {
		var serial = historyRequest + 1;
		historyRequest = serial;
		historyMessage.hidden = false;
		historyMessage.textContent = t('logLoading', 'Loading history...');
		historyList.textContent = '';
		fetchLog({
			user: state.log_user,
			product: state.log_product,
			date_from: state.log_from,
			date_to: state.log_to,
			page: state.log_page,
			per_page: state.log_per_page
		}).then(function (result) {
			if (serial !== historyRequest) {
				return;
			}
			if (!result.response.ok) {
				showHistoryError();
				return;
			}
			renderHistory(result.body || {});
		}).catch(function () {
			if (serial === historyRequest) {
				showHistoryError();
			}
		});
	}

	function showHistoryError() {
		historyMessage.hidden = false;
		historyMessage.textContent = '';
		var paragraph = document.createElement('p');
		paragraph.className = 'low-pte-message-text';
		paragraph.textContent = t('logError', 'The history could not be loaded. Try again.');
		var button = document.createElement('button');
		button.type = 'button';
		button.className = 'button';
		button.textContent = t('retry', 'Retry');
		button.addEventListener('click', loadHistory);
		historyMessage.appendChild(paragraph);
		historyMessage.appendChild(button);
		historyPager.hidden = true;
	}

	function fetchLog(params) {
		var query = new URLSearchParams();
		Object.keys(params).forEach(function (key) {
			if (params[key] !== '' && params[key] !== null && params[key] !== undefined) {
				query.set(key, String(params[key]));
			}
		});
		return window.fetch(restRoot() + 'log?' + query.toString(), {
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': config.nonce || '',
				Accept: 'application/json'
			}
		}).then(function (response) {
			return response.json().then(function (body) {
				return { response: response, body: body };
			}).catch(function () {
				return { response: response, body: {} };
			});
		});
	}

	function renderHistory(body) {
		fillHistoryUsers(body.users || []);
		var batches = Array.isArray(body.batches) ? body.batches : [];
		historyList.textContent = '';
		if (!batches.length) {
			historyMessage.hidden = false;
			historyMessage.textContent = t('logEmpty', 'No changes have been logged.');
			historyPager.hidden = true;
			return;
		}
		historyMessage.hidden = true;
		historyMessage.textContent = '';
		var table = document.createElement('table');
		table.className = 'low-pte-history-table';
		var head = document.createElement('thead');
		var headRow = document.createElement('tr');
		[t('logWhen', 'When'), t('logWho', 'Who'), t('logSource', 'Source'), t('logItems', 'Items'), t('logChanges', 'Changes'), t('logSummary', 'Summary'), ''].forEach(function (label) {
			var cell = document.createElement('th');
			cell.textContent = label;
			cell.scope = 'col';
			headRow.appendChild(cell);
		});
		head.appendChild(headRow);
		table.appendChild(head);
		var bodyEl = document.createElement('tbody');
		batches.forEach(function (batch) {
			bodyEl.appendChild(renderBatchRow(batch));
		});
		table.appendChild(bodyEl);
		historyList.appendChild(table);
		renderHistoryPager(Number(body.total) || 0, Number(body.page) || state.log_page);
	}

	function fillHistoryUsers(users) {
		var current = state.log_user;
		historyUserSelect.textContent = '';
		var all = document.createElement('option');
		all.value = '';
		all.textContent = t('allUsers', 'All users');
		historyUserSelect.appendChild(all);
		users.forEach(function (user) {
			var option = document.createElement('option');
			option.value = String(user.id);
			option.textContent = user.name || t('unknownUser', 'Unknown user');
			historyUserSelect.appendChild(option);
		});
		if (current && !historyUserSelect.querySelector('option[value="' + current + '"]')) {
			var missing = document.createElement('option');
			missing.value = current;
			missing.textContent = t('unknownUser', 'Unknown user');
			historyUserSelect.appendChild(missing);
		}
		historyUserSelect.value = current;
	}

	function renderBatchRow(batch) {
		var row = document.createElement('tr');
		appendCell(row, formatWhen(batch.created_at));
		appendCell(row, batch.user_name || t('unknownUser', 'Unknown user'));
		var sourceCell = document.createElement('td');
		sourceCell.textContent = sourceLabel(batch.source);
		if (batch.undo_state === 'full') {
			sourceCell.appendChild(undoBadge(t('logUndone', 'Undone')));
		} else if (batch.undo_state === 'partial') {
			sourceCell.appendChild(undoBadge(t('partlyUndone', 'Partly undone')));
		}
		row.appendChild(sourceCell);
		appendCell(row, String(batch.item_count));
		appendCell(row, String(batch.change_count));
		appendCell(row, batch.summary || '');
		var action = document.createElement('td');
		var button = document.createElement('button');
		button.type = 'button';
		button.className = 'button';
		button.textContent = t('expandBatch', 'Show changes');
		button.setAttribute('aria-expanded', 'false');
		var detail = document.createElement('tr');
		detail.hidden = true;
		var detailCell = document.createElement('td');
		detailCell.colSpan = 7;
		detail.appendChild(detailCell);
		button.addEventListener('click', function () {
			var open = detail.hidden;
			detail.hidden = !open;
			button.setAttribute('aria-expanded', open ? 'true' : 'false');
			button.textContent = open ? t('collapseBatch', 'Hide changes') : t('expandBatch', 'Show changes');
			if (open && !detailCell.childNodes.length) {
				loadBatch(batch.batch_id, detailCell);
			}
		});
		action.className = 'low-pte-log-actions';
		action.appendChild(button);
		if (batch.can_undo) {
			var undo = document.createElement('button');
			undo.type = 'button';
			undo.className = 'button';
			undo.textContent = t('undoBatch', 'Undo batch');
			undo.addEventListener('click', function () {
				var remaining = Math.max(0, Number(batch.change_count) - Number(batch.undone_count || 0));
				requestUndo(batch.batch_id, remaining, Number(batch.item_count) || 0, undo);
			});
			action.appendChild(undo);
		}
		row.appendChild(action);
		var fragment = document.createDocumentFragment();
		fragment.appendChild(row);
		fragment.appendChild(detail);
		if (historyFocusBatch && historyFocusBatch === batch.batch_id) {
			historyFocusBatch = '';
			detail.hidden = false;
			button.setAttribute('aria-expanded', 'true');
			button.textContent = t('collapseBatch', 'Hide changes');
			loadBatch(batch.batch_id, detailCell);
		}
		return fragment;
	}

	function undoBadge(label) {
		var badge = document.createElement('span');
		badge.className = 'low-pte-undo-badge';
		badge.textContent = label;
		return badge;
	}

	function requestUndo(batchId, changes, items, returnTo) {
		closeModal(false);
		modalReturn = returnTo || document.activeElement;
		modalEl = document.createElement('div');
		modalEl.className = 'low-pte-modal';
		modalEl.setAttribute('role', 'dialog');
		modalEl.setAttribute('aria-modal', 'true');
		var card = document.createElement('div');
		card.className = 'low-pte-modal-card';
		var heading = document.createElement('h2');
		heading.className = 'low-pte-modal-title';
		heading.id = 'low-pte-undo-title';
		heading.textContent = t('undoConfirmTitle', 'Undo this batch?');
		modalEl.setAttribute('aria-labelledby', 'low-pte-undo-title');
		var body = document.createElement('p');
		body.textContent = t('undoConfirmBody', 'This restores %1$s changes across %2$s items. Anything changed since will be left alone and reported.')
			.replace('%1$s', String(changes))
			.replace('%2$s', String(items));
		var actions = document.createElement('div');
		actions.className = 'low-pte-modal-actions';
		var confirm = document.createElement('button');
		confirm.type = 'button';
		confirm.className = 'button button-primary';
		confirm.textContent = t('undoBatch', 'Undo batch');
		var cancel = document.createElement('button');
		cancel.type = 'button';
		cancel.className = 'button';
		cancel.textContent = t('cancel', 'Cancel');
		function dismiss() {
			var back = modalReturn;
			closeModal(false);
			if (back && typeof back.focus === 'function') {
				back.focus();
			}
		}
		cancel.addEventListener('click', dismiss);
		confirm.addEventListener('click', function () {
			confirm.disabled = true;
			cancel.disabled = true;
			runUndo([batchId], { modal: true, returnTo: modalReturn });
		});
		actions.appendChild(confirm);
		actions.appendChild(cancel);
		card.appendChild(heading);
		card.appendChild(body);
		card.appendChild(actions);
		modalEl.appendChild(card);
		modalEl.addEventListener('keydown', function (event) {
			if (event.key === 'Escape') {
				event.preventDefault();
				dismiss();
			}
		});
		document.body.appendChild(modalEl);
		confirm.focus();
	}

	function runUndo(batchIds, options) {
		var settings = options || {};
		var aggregate = {
			restored: 0,
			skipped: [],
			failed: [],
			restored_ids: [],
			undo_batch_id: '',
			log_failed: false,
			message: ''
		};
		var chain = Promise.resolve();
		batchIds.forEach(function (batchId) {
			chain = chain.then(function () {
				if (aggregate.message) {
					return null;
				}
				return postUndo(batchId).then(function (result) {
					if (!result.response.ok) {
						aggregate.message = result.body && result.body.message ? String(result.body.message) : t('undoFailed', 'The undo could not be completed.');
						return null;
					}
					var body = result.body || {};
					aggregate.restored += Number(body.restored) || 0;
					aggregate.skipped = aggregate.skipped.concat(Array.isArray(body.skipped) ? body.skipped : []);
					aggregate.failed = aggregate.failed.concat(Array.isArray(body.failed) ? body.failed : []);
					aggregate.restored_ids = aggregate.restored_ids.concat(Array.isArray(body.restored_ids) ? body.restored_ids : []);
					aggregate.log_failed = aggregate.log_failed || !!body.log_failed;
					if (body.undo_batch_id) {
						aggregate.undo_batch_id = String(body.undo_batch_id);
					}
					return null;
				});
			});
		});
		return chain.then(function () {
			return finishUndo(aggregate, settings);
		}).catch(function () {
			showToast(t('undoFailed', 'The undo could not be completed.'));
		});
	}

	function postUndo(batchId) {
		return window.fetch(restRoot() + 'undo/' + encodeURIComponent(batchId), {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': config.nonce || '',
				Accept: 'application/json'
			}
		}).then(function (response) {
			return response.json().then(function (body) {
				return { response: response, body: body };
			}).catch(function () {
				return { response: response, body: {} };
			});
		});
	}

	function finishUndo(result, settings) {
		var dropped = dropRestoredEdits(result.restored_ids);
		var parts = [];
		if (result.message) {
			parts.push(result.message);
		} else {
			parts.push(t('restoredChanges', 'Restored %s changes.').replace('%s', String(result.restored)));
			if (result.skipped.length) {
				parts.push(t('undoSkippedSince', '%s could not be restored because they changed since. See History.').replace('%s', String(result.skipped.length)));
			}
		}
		if (result.log_failed) {
			parts.push(t('logFailed', 'Saved, but the change could not be logged, so it cannot be undone.'));
		}
		if (dropped) {
			parts.push(t('undoDirtyDropped', 'Affected rows reloaded. Unsaved edits on those rows were dropped.'));
		}
		if (settings.modal) {
			closeModal(false);
		}
		if (settings.toast) {
			showToast(parts.join(' '), 10000);
		}
		if (settings.modal && !result.message) {
			openUndoResult(result, parts, settings.returnTo);
		} else if (settings.modal && result.message) {
			showToast(parts.join(' '));
		}
		load();
		if (state.tab === 'history') {
			loadHistory();
		}
	}

	function dropRestoredEdits(ids) {
		var dropped = false;
		(ids || []).forEach(function (id) {
			var key = Number(id);
			if (dirty.has(key) || dirty.has(id)) {
				dirty.delete(key);
				dirty.delete(id);
				dropped = true;
			}
		});
		if (dropped) {
			updateSaveBar();
		}
		return dropped;
	}

	function openUndoResult(result, parts, returnTo) {
		closeModal(false);
		modalReturn = returnTo || null;
		modalEl = document.createElement('div');
		modalEl.className = 'low-pte-modal';
		modalEl.setAttribute('role', 'dialog');
		modalEl.setAttribute('aria-modal', 'true');
		var card = document.createElement('div');
		card.className = 'low-pte-modal-card';
		var heading = document.createElement('h2');
		heading.className = 'low-pte-modal-title';
		heading.id = 'low-pte-undo-result-title';
		heading.textContent = t('undoResultTitle', 'Undo result');
		modalEl.setAttribute('aria-labelledby', 'low-pte-undo-result-title');
		card.appendChild(heading);
		parts.forEach(function (line) {
			var paragraph = document.createElement('p');
			paragraph.textContent = line;
			card.appendChild(paragraph);
		});
		var problems = result.skipped.map(function (row) {
			return {
				name: row.name || String(row.product_id || ''),
				field: fieldLabel(row.field),
				reason: undoReason(row.reason)
			};
		}).concat(result.failed.map(function (row) {
			return {
				name: row.name || String(row.product_id || ''),
				field: fieldLabel(row.field),
				reason: row.message || t('undoFailed', 'The undo could not be completed.')
			};
		}));
		if (problems.length) {
			var tableWrap = document.createElement('div');
			tableWrap.className = 'low-pte-bulk-table-wrap';
			var problemTable = document.createElement('table');
			problemTable.className = 'low-pte-undo-table';
			var head = document.createElement('tr');
			[t('undoProduct', 'Product'), t('undoField', 'Field'), t('undoReason', 'Reason')].forEach(function (label) {
				var cell = document.createElement('th');
				cell.scope = 'col';
				cell.textContent = label;
				head.appendChild(cell);
			});
			problemTable.appendChild(head);
			problems.forEach(function (row) {
				var line = document.createElement('tr');
				[row.name, row.field, row.reason].forEach(function (value) {
					var cell = document.createElement('td');
					cell.textContent = value;
					line.appendChild(cell);
				});
				problemTable.appendChild(line);
			});
			tableWrap.appendChild(problemTable);
			card.appendChild(tableWrap);
		}
		var actions = document.createElement('div');
		actions.className = 'low-pte-modal-actions';
		if (result.undo_batch_id) {
			var view = document.createElement('button');
			view.type = 'button';
			view.className = 'button';
			view.textContent = t('undoViewHistory', 'View this undo in History');
			view.addEventListener('click', function () {
				showUndoInHistory(result.undo_batch_id);
			});
			actions.appendChild(view);
		}
		var close = document.createElement('button');
		close.type = 'button';
		close.className = 'button button-primary';
		close.textContent = t('close', 'Close');
		close.addEventListener('click', function () {
			var back = modalReturn;
			closeModal(false);
			if (back && typeof back.focus === 'function') {
				back.focus();
			}
		});
		actions.appendChild(close);
		card.appendChild(actions);
		modalEl.appendChild(card);
		modalEl.addEventListener('keydown', function (event) {
			if (event.key === 'Escape') {
				event.preventDefault();
				close.click();
			}
		});
		document.body.appendChild(modalEl);
		close.focus();
	}

	function undoReason(reason) {
		if (reason === 'changed_since') {
			return t('reasonChangedSince', 'Changed since this edit, left as is');
		}
		if (reason === 'missing') {
			return t('reasonMissing', 'Item no longer exists');
		}
		if (reason === 'forbidden') {
			return t('reasonForbidden', 'You cannot edit this item');
		}
		return reason ? String(reason) : '';
	}

	function showUndoInHistory(batchId) {
		closeModal(false);
		historyFocusBatch = batchId;
		state.tab = 'history';
		state.log_page = 1;
		state.log_user = '';
		state.log_search = '';
		applyTab();
		syncHistoryControls();
		writeUrl('push');
		loadHistory();
	}

	function loadBatch(batchId, cell) {
		cell.textContent = t('logLoading', 'Loading history...');
		fetchLog({ batch_id: batchId }).then(function (result) {
			cell.textContent = '';
			if (!result.response.ok) {
				cell.textContent = t('logError', 'The history could not be loaded. Try again.');
				return;
			}
			renderEntries(cell, result.body && result.body.entries ? result.body.entries : []);
		}).catch(function () {
			cell.textContent = t('logError', 'The history could not be loaded. Try again.');
		});
	}

	function renderEntries(cell, entries) {
		var table = document.createElement('table');
		table.className = 'low-pte-history-entries';
		var head = document.createElement('tr');
		[t('columnProduct', 'Product'), t('logField', 'Field'), t('logOld', 'Old'), t('logNew', 'New'), ''].forEach(function (label) {
			var th = document.createElement('th');
			th.textContent = label;
			th.scope = 'col';
			head.appendChild(th);
		});
		table.appendChild(head);
		entries.forEach(function (entry) {
			var row = document.createElement('tr');
			if (entry.undone_at) {
				row.className = 'low-pte-log-undone';
			}
			appendCell(row, entry.product_name || String(entry.product_id));
			appendCell(row, fieldLabel(entry.field));
			row.appendChild(valueCell(entry.field, entry.old_value));
			row.appendChild(valueCell(entry.field, entry.new_value));
			var marker = document.createElement('td');
			marker.textContent = entry.undone_at ? t('logUndone', 'Undone') : '';
			row.appendChild(marker);
			table.appendChild(row);
		});
		cell.appendChild(table);
	}

	function valueCell(fieldName, value) {
		var cell = document.createElement('td');
		if (value === null || value === undefined || value === '') {
			cell.textContent = '';
			return cell;
		}
		var text = String(value);
		var longField = fieldName === 'description' || fieldName === 'short_description';
		if (!longField || text.length <= 80) {
			cell.textContent = text;
			return cell;
		}
		cell.textContent = text.slice(0, 80) + '... ';
		var view = document.createElement('button');
		view.type = 'button';
		view.className = 'button-link';
		view.textContent = t('viewValue', 'View');
		view.addEventListener('click', function () {
			openValueModal(fieldLabel(fieldName), text, view);
		});
		cell.appendChild(view);
		return cell;
	}

	function openValueModal(title, text, returnTo) {
		closeModal(false);
		modalEl = document.createElement('div');
		modalEl.className = 'low-pte-modal';
		modalEl.setAttribute('role', 'dialog');
		modalEl.setAttribute('aria-modal', 'true');
		var card = document.createElement('div');
		card.className = 'low-pte-modal-card';
		var heading = document.createElement('h2');
		heading.className = 'low-pte-modal-title';
		heading.textContent = title;
		var body = document.createElement('pre');
		body.className = 'low-pte-log-value';
		body.textContent = text;
		var close = document.createElement('button');
		close.type = 'button';
		close.className = 'button';
		close.textContent = t('close', 'Close');
		close.addEventListener('click', function () {
			closeModal(false);
			if (returnTo) {
				returnTo.focus();
			}
		});
		card.appendChild(heading);
		card.appendChild(body);
		card.appendChild(close);
		modalEl.appendChild(card);
		modalEl.addEventListener('keydown', function (event) {
			if (event.key === 'Escape') {
				close.click();
			}
		});
		document.body.appendChild(modalEl);
		close.focus();
	}

	function renderHistoryPager(total, page) {
		var pages = Math.max(1, Math.ceil(total / state.log_per_page));
		historyPager.textContent = '';
		if (pages < 2) {
			historyPager.hidden = true;
			return;
		}
		historyPager.hidden = false;
		var prev = document.createElement('button');
		prev.type = 'button';
		prev.className = 'button';
		prev.textContent = t('prev', 'Previous');
		prev.disabled = page <= 1;
		prev.addEventListener('click', function () {
			state.log_page = Math.max(1, page - 1);
			writeUrl('push');
			loadHistory();
		});
		var label = document.createElement('span');
		label.textContent = t('pageNumber', 'Page %s').replace('%s', String(page));
		var next = document.createElement('button');
		next.type = 'button';
		next.className = 'button';
		next.textContent = t('next', 'Next');
		next.disabled = page >= pages;
		next.addEventListener('click', function () {
			state.log_page = page + 1;
			writeUrl('push');
			loadHistory();
		});
		historyPager.appendChild(prev);
		historyPager.appendChild(label);
		historyPager.appendChild(next);
	}

	function appendCell(row, text) {
		var cell = document.createElement('td');
		cell.textContent = text;
		row.appendChild(cell);
	}

	function formatWhen(value) {
		var parsed = Date.parse(String(value || '').replace(' ', 'T') + 'Z');
		if (!Number.isFinite(parsed)) {
			return value ? String(value) : '';
		}
		return new Date(parsed).toLocaleString();
	}

	function sourceLabel(source) {
		if (source === 'bulk') {
			return t('sourceBulk', 'Bulk');
		}
		if (source === 'undo') {
			return t('sourceUndo', 'Undo');
		}
		return t('sourceInline', 'Inline');
	}

	function fieldLabel(fieldName) {
		var map = {
			regular_price: t('columnRegularPrice', 'Regular price'),
			sale_price: t('columnSalePrice', 'Sale price'),
			sku: t('columnSku', 'SKU'),
			weight: t('columnWeight', 'Weight'),
			manage_stock: t('columnManageStock', 'Manage stock'),
			stock_quantity: t('columnQuantity', 'Quantity'),
			stock_status: t('columnStock', 'Stock'),
			name: t('columnProduct', 'Product'),
			status: t('columnStatus', 'Status'),
			catalog_visibility: t('columnVisibility', 'Catalog visibility'),
			description: t('descriptionTitle', 'Description'),
			short_description: t('shortDescription', 'Short description')
		};
		return Object.prototype.hasOwnProperty.call(map, fieldName) ? map[fieldName] : String(fieldName || '');
	}

	window.LOW_PTE_HOST = {
		t: t,
		restRoot: restRoot,
		nonce: function () {
			return config.nonce || '';
		},
		filters: function () {
			return {
				search: state.search,
				category: state.category,
				type: state.type,
				stock_status: state.stock_status,
				status: state.status
			};
		},
		selection: function () {
			if (selectionMode === 'filter') {
				return { mode: 'filter', filters: this.filters() };
			}
			return { mode: 'ids', ids: Array.from(selectedIds) };
		},
		dirty: function () {
			return dirty.size > 0;
		},
		reload: function () {
			load();
		},
		toast: showToast,
		requestUndo: requestUndo
	};
}());
