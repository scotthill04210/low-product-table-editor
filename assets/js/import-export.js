/**
 * CSV export and import for the table editor.
 */
(function () {
	'use strict';

	var host = window.LOW_PTE_HOST;
	if (!host) {
		return;
	}

	var t = host.t;
	var settings = (window.LOW_PTE_CONFIG && window.LOW_PTE_CONFIG.settings) || {};
	var fileInput;
	var previewButton;
	var downloadButton;
	var scopeSelect;
	var descriptionInput;
	var skipInput;
	var clearInput;
	var blockedNote;
	var live;
	var modal = null;
	var applying = false;
	var stopAfter = false;

	var mount = document.getElementById('low-pte-app');
	if (!mount) {
		return;
	}

	var panel = document.createElement('div');
	panel.id = 'low-pte-import';
	panel.className = 'low-pte-import';
	panel.hidden = true;
	panel.appendChild(buildLayout());
	mount.appendChild(panel);
	if (host.refreshTab) {
		host.refreshTab();
	}

	function buildLayout() {
		var grid = document.createElement('div');
		grid.className = 'low-pte-import-grid';
		grid.appendChild(exportCard());
		grid.appendChild(importCard());
		live = document.createElement('div');
		live.className = 'low-pte-live';
		live.setAttribute('role', 'status');
		live.setAttribute('aria-live', 'polite');
		grid.appendChild(live);
		return grid;
	}

	function exportCard() {
		var card = document.createElement('section');
		card.className = 'low-pte-card';
		var heading = document.createElement('h2');
		heading.textContent = t('exportTitle', 'Export');
		var form = document.createElement('form');
		form.method = 'post';
		form.action = settings.actionUrl || '';
		form.appendChild(hidden('action', 'low_pte_export'));
		form.appendChild(hidden('_wpnonce', settings.exportNonce || ''));
		scopeSelect = document.createElement('select');
		[
			['all', t('exportAll', 'Everything')],
			['filters', t('exportFilters', 'Current table filters')],
			['selection', t('exportSelection', 'Currently selected rows')]
		].forEach(function (option) {
			var item = document.createElement('option');
			item.value = option[0];
			item.textContent = option[1];
			scopeSelect.appendChild(item);
		});
		form.appendChild(field(t('exportScope', 'Rows'), scopeSelect));
		descriptionInput = document.createElement('input');
		descriptionInput.type = 'checkbox';
		descriptionInput.name = 'include_descriptions';
		descriptionInput.value = '1';
		form.appendChild(checkField(t('includeDescriptions', 'Include descriptions'), descriptionInput));
		var filterBag = document.createElement('div');
		form.appendChild(filterBag);
		downloadButton = document.createElement('button');
		downloadButton.type = 'submit';
		downloadButton.className = 'button button-primary';
		downloadButton.textContent = t('downloadExport', 'Download export');
		form.appendChild(downloadButton);
		form.addEventListener('submit', function (event) {
			if (blocked()) {
				event.preventDefault();
				return;
			}
			filterBag.textContent = '';
			var filters = host.filters();
			Object.keys(filters).forEach(function (key) {
				if (filters[key]) {
					filterBag.appendChild(hidden(key, filters[key]));
				}
			});
			var selection = host.selection();
			if (scopeSelect.value === 'selection') {
				if (selection.mode === 'ids' && (!selection.ids || !selection.ids.length)) {
					event.preventDefault();
					host.toast(t('exportNeedsSelection', 'Select at least one row to export.'));
					return;
				}
				if (selection.mode === 'ids') {
					filterBag.appendChild(hidden('ids', selection.ids.join(',')));
				}
			}
		});
		card.appendChild(heading);
		card.appendChild(form);
		return card;
	}

	function importCard() {
		var card = document.createElement('section');
		card.className = 'low-pte-card';
		var heading = document.createElement('h2');
		heading.textContent = t('importTitle', 'Import');
		fileInput = document.createElement('input');
		fileInput.type = 'file';
		fileInput.accept = '.csv,text/csv';
		fileInput.className = 'low-pte-file-input';
		var drop = document.createElement('div');
		drop.className = 'low-pte-drop';
		var choose = document.createElement('button');
		choose.type = 'button';
		choose.className = 'button';
		choose.textContent = t('chooseFile', 'Choose file');
		choose.addEventListener('click', function () {
			fileInput.click();
		});
		var hint = document.createElement('p');
		hint.textContent = t('dropFile', 'Drop a CSV file here, or choose a file.');
		drop.appendChild(hint);
		drop.appendChild(choose);
		drop.addEventListener('dragover', function (event) {
			event.preventDefault();
		});
		drop.addEventListener('drop', function (event) {
			event.preventDefault();
			if (event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files[0]) {
				fileInput.files = event.dataTransfer.files;
				hint.textContent = event.dataTransfer.files[0].name;
			}
		});
		fileInput.addEventListener('change', function () {
			hint.textContent = fileInput.files && fileInput.files[0] ? fileInput.files[0].name : t('dropFile', 'Drop a CSV file here, or choose a file.');
		});
		skipInput = document.createElement('input');
		skipInput.type = 'checkbox';
		skipInput.checked = true;
		clearInput = document.createElement('input');
		clearInput.type = 'checkbox';
		previewButton = document.createElement('button');
		previewButton.type = 'button';
		previewButton.className = 'button button-primary';
		previewButton.textContent = t('previewImport', 'Preview import');
		previewButton.addEventListener('click', preview);
		blockedNote = document.createElement('p');
		blockedNote.className = 'low-pte-select-blocked';
		blockedNote.hidden = true;
		blockedNote.textContent = t('bulkBlocked', 'Save or discard changes before using a bulk action.');
		card.appendChild(heading);
		card.appendChild(drop);
		card.appendChild(fileInput);
		card.appendChild(checkField(t('skipChanged', 'Skip rows changed since export'), skipInput));
		card.appendChild(checkField(t('allowClear', 'Allow clearing values'), clearInput));
		card.appendChild(previewButton);
		card.appendChild(blockedNote);
		window.setInterval(syncBlocked, 400);
		return card;
	}

	function preview() {
		if (blocked()) {
			return;
		}
		if (!fileInput.files || !fileInput.files[0]) {
			host.toast(t('chooseCsv', 'Choose a CSV file to preview.'));
			return;
		}
		var body = new FormData();
		body.append('file', fileInput.files[0]);
		body.append('skip_changed_since_export', skipInput.checked ? '1' : '0');
		body.append('allow_clear', clearInput.checked ? '1' : '0');
		previewButton.disabled = true;
		fetch(host.restRoot() + 'import/preview', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': host.nonce(), Accept: 'application/json' },
			body: body
		}).then(function (response) {
			return response.json().then(function (payload) {
				return { ok: response.ok, payload: payload };
			});
		}).then(function (result) {
			previewButton.disabled = false;
			if (!result.ok) {
				host.toast((result.payload && result.payload.message) || t('importFailed', 'The import could not be previewed.'));
				return;
			}
			openPreview(result.payload, previewButton);
		}).catch(function () {
			previewButton.disabled = false;
			host.toast(t('importFailed', 'The import could not be previewed.'));
		});
	}

	function openPreview(payload, trigger) {
		closeModal();
		var summary = payload.summary || {};
		var changes = Number(summary.changes) || 0;
		var clears = Number(summary.clears) || 0;
		var items = Number(summary.will_change) || 0;
		modal = document.createElement('div');
		modal.className = 'low-pte-modal';
		modal.setAttribute('role', 'dialog');
		modal.setAttribute('aria-modal', 'true');
		modal.setAttribute('aria-labelledby', 'low-pte-import-title');
		var card = document.createElement('div');
		card.className = 'low-pte-modal-card low-pte-import-preview';
		var heading = document.createElement('h2');
		heading.id = 'low-pte-import-title';
		heading.className = 'low-pte-modal-title';
		heading.textContent = t('importPreviewTitle', 'Import preview');
		card.appendChild(heading);
		card.appendChild(summaryList(summary));
		(payload.notices || []).forEach(function (notice) {
			var line = document.createElement('p');
			line.textContent = notice;
			card.appendChild(line);
		});
		if (payload.truncated) {
			var note = document.createElement('p');
			note.textContent = t('importTruncated', 'Showing the first 500 rows. The counts cover the whole file.');
			card.appendChild(note);
		}
		var rows = payload.rows || [];
		var problemsOnly = document.createElement('input');
		problemsOnly.type = 'checkbox';
		var statusFilter = document.createElement('select');
		['', 'will_change', 'clear', 'unchanged', 'error', 'not_found', 'changed_since_export', 'duplicate_in_file'].forEach(function (value) {
			var option = document.createElement('option');
			option.value = value;
			option.textContent = value ? statusText(value) : t('allStatuses', 'All statuses');
			statusFilter.appendChild(option);
		});
		var filters = document.createElement('div');
		filters.className = 'low-pte-toolbar';
		filters.appendChild(checkField(t('showProblems', 'Show only problems'), problemsOnly));
		filters.appendChild(statusFilter);
		card.appendChild(filters);
		var tableHolder = document.createElement('div');
		card.appendChild(tableHolder);
		function paint() {
			tableHolder.textContent = '';
			tableHolder.appendChild(changeTable(rows.filter(function (row) {
				if (problemsOnly.checked && !isProblem(row.status)) {
					return false;
				}
				if (statusFilter.value && row.status !== statusFilter.value) {
					return false;
				}
				return true;
			})));
		}
		problemsOnly.addEventListener('change', paint);
		statusFilter.addEventListener('change', paint);
		paint();

		var confirmItems = document.createElement('input');
		confirmItems.type = 'checkbox';
		var confirmClears = document.createElement('input');
		confirmClears.type = 'checkbox';
		if (items > 50) {
			card.appendChild(checkField(t('confirmItems', 'I understand this will change %s items.').replace('%s', String(items)), confirmItems));
		}
		if (clears > 0) {
			card.appendChild(checkField(t('confirmClears', 'This file clears %s values.').replace('%s', String(clears)), confirmClears));
		}
		var apply = document.createElement('button');
		apply.type = 'button';
		apply.className = 'button button-primary';
		apply.textContent = t('applyChanges', 'Apply %1$s changes to %2$s items').replace('%1$s', String(changes + clears)).replace('%2$s', String(items));
		apply.disabled = items < 1;
		function syncApply() {
			apply.disabled = items < 1 || (items > 50 && !confirmItems.checked) || (clears > 0 && !confirmClears.checked) || applying;
		}
		confirmItems.addEventListener('change', syncApply);
		confirmClears.addEventListener('change', syncApply);
		var stop = document.createElement('button');
		stop.type = 'button';
		stop.className = 'button';
		stop.textContent = t('stopChunk', 'Stop after this chunk');
		stop.hidden = true;
		var progress = document.createElement('div');
		progress.className = 'low-pte-progress';
		progress.hidden = true;
		progress.setAttribute('role', 'progressbar');
		progress.setAttribute('aria-valuemin', '0');
		progress.setAttribute('aria-valuemax', '100');
		var bar = document.createElement('span');
		progress.appendChild(bar);
		var report = document.createElement('div');
		apply.addEventListener('click', function () {
			runApply(payload.token, progress, bar, stop, report, apply, live);
		});
		var cancel = document.createElement('button');
		cancel.type = 'button';
		cancel.className = 'button';
		cancel.textContent = t('close', 'Close');
		cancel.addEventListener('click', function () {
			if (!applying) {
				closeModal();
				if (trigger) {
					trigger.focus();
				}
			}
		});
		var actions = document.createElement('p');
		actions.className = 'low-pte-import-actions';
		actions.appendChild(apply);
		actions.appendChild(stop);
		actions.appendChild(cancel);
		card.appendChild(progress);
		card.appendChild(report);
		card.appendChild(actions);
		modal.appendChild(card);
		document.body.appendChild(modal);
		if (host.trapModal) {
			host.trapModal(modal, function () {
				if (!applying) {
					closeModal();
					if (trigger) {
						trigger.focus();
					}
				}
			});
		}
		cancel.focus();
		live.textContent = apply.textContent;
	}

	function runApply(token, progress, bar, stop, report, apply, announcer) {
		if (!window.crypto || !crypto.randomUUID) {
			host.toast(t('importFailed', 'The import could not be previewed.'));
			return;
		}
		applying = true;
		stopAfter = false;
		apply.disabled = true;
		stop.hidden = false;
		progress.hidden = false;
		stop.addEventListener('click', function () {
			stopAfter = true;
		});
		var batchId = crypto.randomUUID();
		var index = 0;
		var collected = [];
		var counts = { ok: 0, unchanged: 0, changed_since_preview: 0, error: 0 };

		function finish(logFailed) {
			applying = false;
			stop.hidden = true;
			renderReport(report, counts, collected, batchId, logFailed);
			if (host.reload) {
				host.reload();
			}
			announcer.textContent = t('importDone', 'Import finished.');
		}

		function send(retried) {
			if (stopAfter) {
				finish(false);
				return;
			}
			fetch(host.restRoot() + 'import/apply', {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'X-WP-Nonce': host.nonce(),
					Accept: 'application/json',
					'Content-Type': 'application/json'
				},
				body: JSON.stringify({
					token: token,
					batch_id: batchId,
					chunk_index: index,
					chunk_total: 0,
					options: {}
				})
			}).then(function (response) {
				return response.json().then(function (body) {
					return { ok: response.ok, body: body };
				});
			}).then(function (result) {
				if (!result.ok) {
					if (!retried) {
						send(true);
						return;
					}
					host.toast((result.body && result.body.message) || t('importFailed', 'The import could not be previewed.'));
					finish(false);
					return;
				}
				(result.body.results || []).forEach(function (row) {
					counts[row.status] = (counts[row.status] || 0) + 1;
					if (row.status === 'error' || row.status === 'changed_since_preview') {
						collected.push(row);
					}
				});
				var total = Number(result.body.total) || 1;
				var processed = Number(result.body.processed) || 0;
				var percent = Math.round((processed / total) * 100);
				bar.style.width = percent + '%';
				progress.setAttribute('aria-valuenow', String(percent));
				announcer.textContent = t('importProgress', 'Applied %1$s of %2$s rows.').replace('%1$s', String(processed)).replace('%2$s', String(total));
				if (result.body.done || stopAfter) {
					finish(!!result.body.log_failed);
					return;
				}
				index += 1;
				send(false);
			}).catch(function () {
				if (!retried) {
					send(true);
					return;
				}
				host.toast(t('importOffline', 'The network failed. Applied rows were kept. Preview the file again to continue.'));
				finish(false);
			});
		}
		send(false);
	}

	function renderReport(report, counts, problems, batchId, logFailed) {
		report.textContent = '';
		var list = document.createElement('ul');
		[
			[t('changed', 'Changed'), counts.ok || 0],
			[t('unchanged', 'Unchanged'), counts.unchanged || 0],
			[t('skipped', 'Skipped'), counts.changed_since_preview || 0],
			[t('errors', 'Errors'), counts.error || 0]
		].forEach(function (pair) {
			var item = document.createElement('li');
			item.textContent = pair[0] + ': ' + pair[1];
			list.appendChild(item);
		});
		report.appendChild(list);
		if (logFailed) {
			var warning = document.createElement('p');
			warning.textContent = t('logFailed', 'Saved, but the change could not be logged, so it cannot be undone.');
			report.appendChild(warning);
		}
		if (problems.length) {
			report.appendChild(changeTable(problems));
			var download = document.createElement('button');
			download.type = 'button';
			download.className = 'button';
			download.textContent = t('downloadProblems', 'Download problems as CSV');
			download.addEventListener('click', function () {
				downloadCsv(problems);
			});
			report.appendChild(download);
		}
		if ((counts.ok || 0) > 0) {
			var undo = document.createElement('button');
			undo.type = 'button';
			undo.className = 'button';
			undo.textContent = t('undoThisBatch', 'Undo this batch');
			undo.addEventListener('click', function () {
				host.requestUndo(batchId, counts.ok, counts.ok, undo);
			});
			report.appendChild(undo);
		}
	}

	function summaryList(summary) {
		var list = document.createElement('ul');
		list.className = 'low-pte-import-summary';
		[
			['rows', t('rowsInFile', 'Rows in file')],
			['matched', t('matched', 'Matched')],
			['will_change', t('willChange', 'Items that will change')],
			['changes', t('cellChanges', 'Cell changes')],
			['clears', t('clears', 'Clears')],
			['unchanged', t('unchanged', 'Unchanged')],
			['not_found', t('notFound', 'Not found')],
			['errors', t('errors', 'Errors')],
			['changed_since_export', t('changedSince', 'Changed since export')],
			['duplicates', t('duplicates', 'Duplicates')]
		].forEach(function (pair) {
			var item = document.createElement('li');
			item.textContent = pair[1] + ': ' + (Number(summary[pair[0]]) || 0);
			list.appendChild(item);
		});
		return list;
	}

	function changeTable(rows) {
		var table = document.createElement('table');
		table.className = 'low-pte-history-table';
		var head = document.createElement('tr');
		[t('line', 'Line'), t('columnSku', 'SKU'), t('columnProduct', 'Product'), t('logField', 'Field'), t('logOld', 'Old'), t('logNew', 'New'), t('columnStatus', 'Status')].forEach(function (label) {
			var cell = document.createElement('th');
			cell.scope = 'col';
			cell.textContent = label;
			head.appendChild(cell);
		});
		table.appendChild(head);
		rows.forEach(function (row) {
			var line = document.createElement('tr');
			if (isProblem(row.status)) {
				line.className = 'low-pte-problem';
			}
			[row.line, row.sku, row.name, row.field ? host.fieldLabel(row.field) : '', row.old, row.new, statusText(row.status) + (row.message ? ' ' + row.message : '')].forEach(function (value) {
				var cell = document.createElement('td');
				cell.textContent = value === undefined || value === null ? '' : String(value);
				line.appendChild(cell);
			});
			table.appendChild(line);
		});
		return table;
	}

	function downloadCsv(rows) {
		var header = ['line', 'sku', 'name', 'field', 'old', 'new', 'status', 'message'];
		var lines = [header.join(',')];
		rows.forEach(function (row) {
			lines.push(header.map(function (key) {
				return csvCell(row[key]);
			}).join(','));
		});
		var blob = new Blob(['\uFEFF' + lines.join('\n')], { type: 'text/csv;charset=utf-8' });
		var link = document.createElement('a');
		link.href = URL.createObjectURL(blob);
		link.download = 'low-pte-import-problems.csv';
		link.click();
		URL.revokeObjectURL(link.href);
	}

	function csvCell(value) {
		var text = value === undefined || value === null ? '' : String(value);
		if (/^[=+\-@\t\r]/.test(text)) {
			text = "'" + text;
		}
		if (/[",\n]/.test(text)) {
			return '"' + text.replace(/"/g, '""') + '"';
		}
		return text;
	}

	function statusText(status) {
		var map = {
			will_change: t('willChange', 'Will change'),
			clear: t('clears', 'Clear'),
			unchanged: t('unchanged', 'Unchanged'),
			error: t('errors', 'Error'),
			not_found: t('notFound', 'Not found'),
			changed_since_export: t('changedSince', 'Changed since export'),
			changed_since_preview: t('skipped', 'Skipped'),
			duplicate_in_file: t('duplicates', 'Duplicate in file'),
			ok: t('changed', 'Changed')
		};
		return map[status] || status || '';
	}

	function isProblem(status) {
		return status === 'error' || status === 'not_found' || status === 'duplicate_in_file' || status === 'changed_since_export' || status === 'changed_since_preview';
	}

	function blocked() {
		if (host.dirty()) {
			host.toast(t('bulkBlocked', 'Save or discard changes before using a bulk action.'));
			return true;
		}
		return false;
	}

	function syncBlocked() {
		var dirty = host.dirty();
		if (downloadButton) {
			downloadButton.disabled = dirty;
		}
		if (previewButton) {
			previewButton.disabled = dirty || applying;
		}
		if (blockedNote) {
			blockedNote.hidden = !dirty;
		}
	}

	function hidden(name, value) {
		var input = document.createElement('input');
		input.type = 'hidden';
		input.name = name;
		input.value = value;
		return input;
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

	function checkField(label, control) {
		var wrap = document.createElement('label');
		wrap.className = 'low-pte-check';
		wrap.appendChild(control);
		wrap.appendChild(document.createTextNode(' ' + label));
		return wrap;
	}

	function closeModal() {
		if (modal) {
			modal.remove();
			modal = null;
		}
	}
}());
