/**
 * Bulk price configure, preview, and chunked apply.
 */
(function () {
	'use strict';

	var host = window.LOW_PTE_HOST;
	if (!host) {
		return;
	}

	var t = host.t;
	var modal = null;
	var returnFocus = null;
	var stopAfterChunk = false;
	var applying = false;
	var actionState = {
		operation: 'increase_percent',
		value: '',
		target: 'both',
		rounding: 'none'
	};

	window.LOW_PTE_BULK = {
		open: openConfigure
	};

	function openConfigure(trigger) {
		if (host.dirty()) {
			host.toast(t('bulkBlocked', 'Save or discard changes before using a bulk action.'));
			return;
		}
		returnFocus = trigger || document.activeElement;
		renderConfigure();
	}

	function renderConfigure() {
		closeModal(false);
		var card = openShell(t('bulkTitle', 'Bulk price action'));
		var form = document.createElement('div');
		form.className = 'low-pte-bulk-form';

		form.appendChild(radioGroup(t('operation', 'Operation'), 'operation', [
			['increase_percent', t('increasePercent', 'Increase by percent')],
			['decrease_percent', t('decreasePercent', 'Decrease by percent')],
			['increase_amount', t('increaseAmount', 'Increase by amount')],
			['decrease_amount', t('decreaseAmount', 'Decrease by amount')],
			['set_value', t('setValue', 'Set to value')]
		], actionState.operation, function (value) {
			actionState.operation = value;
			unit.textContent = unitLabel();
		}));

		var valueInput = document.createElement('input');
		valueInput.type = 'text';
		valueInput.inputMode = 'decimal';
		valueInput.value = actionState.value;
		valueInput.className = 'low-pte-bulk-value';
		valueInput.setAttribute('aria-describedby', 'low-pte-bulk-value-error');
		var unit = document.createElement('span');
		unit.className = 'low-pte-bulk-unit';
		unit.textContent = unitLabel();
		var valueError = document.createElement('p');
		valueError.id = 'low-pte-bulk-value-error';
		valueError.className = 'low-pte-bulk-error';
		valueError.hidden = true;
		var valueRow = document.createElement('div');
		valueRow.className = 'low-pte-bulk-value-row';
		valueRow.appendChild(valueInput);
		valueRow.appendChild(unit);
		form.appendChild(labeled(t('bulkValue', 'Value'), valueRow));
		form.appendChild(valueError);
		valueInput.addEventListener('input', function () {
			actionState.value = valueInput.value.trim();
			var valid = actionState.value === '' || valueValid(actionState.value);
			valueError.hidden = valid;
			valueError.textContent = valid ? '' : t('bulkValueInvalid', 'Enter a number with at most 4 decimal places.');
			previewButton.disabled = !valueValid(actionState.value);
		});

		form.appendChild(radioGroup(t('bulkTarget', 'Target'), 'target', [
			['regular', t('targetRegular', 'Regular price')],
			['sale', t('targetSale', 'Sale price')],
			['both', t('targetBoth', 'Regular and sale')]
		], actionState.target, function (value) {
			actionState.target = value;
		}));

		form.appendChild(radioGroup(t('rounding', 'Rounding'), 'rounding', [
			['none', t('roundNone', 'None')],
			['cent', t('roundCent', 'Nearest cent')],
			['0.05', t('roundFive', 'Nearest 0.05')],
			['0.10', t('roundTen', 'Nearest 0.10')]
		], actionState.rounding, function (value) {
			actionState.rounding = value;
		}));

		var previewError = document.createElement('p');
		previewError.className = 'low-pte-bulk-error';
		previewError.hidden = true;
		form.appendChild(previewError);

		var actions = document.createElement('div');
		actions.className = 'low-pte-modal-actions';
		var cancel = document.createElement('button');
		cancel.type = 'button';
		cancel.className = 'button';
		cancel.textContent = t('close', 'Close');
		cancel.addEventListener('click', function () {
			closeModal(true);
		});
		var previewButton = document.createElement('button');
		previewButton.type = 'button';
		previewButton.className = 'button button-primary';
		previewButton.textContent = t('preview', 'Preview');
		previewButton.disabled = !valueValid(actionState.value);
		previewButton.addEventListener('click', function () {
			actionState.value = valueInput.value.trim();
			if (!valueValid(actionState.value)) {
				valueError.hidden = false;
				valueError.textContent = t('bulkValueInvalid', 'Enter a number with at most 4 decimal places.');
				valueInput.focus();
				return;
			}
			previewButton.disabled = true;
			previewButton.textContent = t('previewing', 'Previewing...');
			previewError.hidden = true;
			postJson('bulk/preview', {
				selection: host.selection(),
				action: actionPayload()
			}).then(function (result) {
				if (!result.response.ok) {
					previewError.hidden = false;
					previewError.textContent = messageFrom(result.body) || t('bulkPreviewFailed', 'The preview could not be loaded. Try again.');
					previewButton.disabled = false;
					previewButton.textContent = t('preview', 'Preview');
					return;
				}
				renderPreview(result.body || {});
			}).catch(function () {
				previewError.hidden = false;
				previewError.textContent = t('bulkPreviewFailed', 'The preview could not be loaded. Try again.');
				previewButton.disabled = false;
				previewButton.textContent = t('preview', 'Preview');
			});
		});
		actions.appendChild(cancel);
		actions.appendChild(previewButton);
		form.appendChild(actions);
		card.appendChild(form);
		valueInput.focus();
	}

	function summaryLine(items, fieldCounts) {
		var changed = Number(items && items.changed) || 0;
		var unchanged = Number(items && items.unchanged) || 0;
		var skipped = Number(items && items.skipped) || 0;
		var errors = Number(items && items.errors) || 0;
		var parts = [
			countPhrase(changed, 'bulkItemChanged', '%s item changed', 'bulkItemsChanged', '%s items changed')
		];
		if (unchanged > 0) {
			parts.push(countPhrase(unchanged, 'bulkItemUnchanged', '%s item unchanged', 'bulkItemsUnchanged', '%s items unchanged'));
		}
		if (skipped > 0) {
			parts.push(countPhrase(skipped, 'bulkItemSkipped', '%s item skipped', 'bulkItemsSkipped', '%s items skipped'));
		}
		fieldCountList(fieldCounts).forEach(function (entry) {
			if (entry.status !== 'skipped' && entry.status !== 'error') {
				return;
			}
			var phrase = fieldCountPhrase(entry);
			if (phrase) {
				parts.push(phrase);
			}
		});
		parts.push(countPhrase(errors, 'bulkItemError', '%s error', 'bulkItemsErrors', '%s errors'));
		return parts.join(', ');
	}

	function countPhrase(count, oneKey, oneFallback, manyKey, manyFallback) {
		var template = count === 1 ? t(oneKey, oneFallback) : t(manyKey, manyFallback);
		return template.replace('%s', String(count));
	}

	function fieldCountList(fieldCounts) {
		var list = Array.isArray(fieldCounts) ? fieldCounts.slice() : [];
		list.sort(function (left, right) {
			var rank = function (row) {
				var field = row.field === 'sale_price' ? '0' : '1';
				var status = row.status === 'skipped' ? '0' : (row.status === 'error' ? '1' : '2');
				return field + status + String(row.reason || '');
			};
			var a = rank(left);
			var b = rank(right);
			if (a < b) {
				return -1;
			}
			if (a > b) {
				return 1;
			}
			return 0;
		});
		return list;
	}

	function fieldCountPhrase(entry) {
		var count = Number(entry.count) || 0;
		if (count < 1) {
			return '';
		}
		var noun = priceNoun(entry.field, count);
		if (entry.status === 'skipped' && entry.reason === 'price_empty') {
			return t('bulkFieldSkippedEmpty', '%1$s %2$s skipped (empty)')
				.replace('%1$s', String(count))
				.replace('%2$s', noun);
		}
		var template = entry.status === 'error'
			? t('bulkFieldError', '%1$s %2$s errored (%3$s)')
			: t('bulkFieldSkipped', '%1$s %2$s skipped (%3$s)');
		return template
			.replace('%1$s', String(count))
			.replace('%2$s', noun)
			.replace('%3$s', reasonText(entry.reason));
	}

	function priceNoun(field, count) {
		if (field === 'sale_price') {
			return count === 1 ? t('bulkSalePrice', 'sale price') : t('bulkSalePrices', 'sale prices');
		}
		return count === 1 ? t('bulkRegularPrice', 'regular price') : t('bulkRegularPrices', 'regular prices');
	}

	function addFieldCounts(store, incoming) {
		(incoming || []).forEach(function (entry) {
			if (!entry || !entry.field) {
				return;
			}
			var key = [entry.field, entry.status, entry.reason || ''].join('|');
			if (!store[key]) {
				store[key] = {
					field: entry.field,
					status: entry.status,
					reason: entry.reason || '',
					count: 0
				};
			}
			store[key].count += Number(entry.count) || 0;
		});
	}

	function renderPreview(body) {
		closeModal(false);
		var summary = body.summary || {};
		var changed = Number(summary.changed) || 0;
		var card = openShell(t('bulkTitle', 'Bulk price action'));
		var line = document.createElement('p');
		line.textContent = summaryLine(summary, summary.fields || []);
		card.appendChild(line);

		if (body.truncated) {
			var note = document.createElement('p');
			note.textContent = t('truncatedPreview', 'Showing the first 500 of %s items. The summary covers all of them.')
				.replace('%s', String(summary.total || 0));
			card.appendChild(note);
		}

		var problemsOnly = document.createElement('label');
		problemsOnly.className = 'low-pte-check';
		var problemsInput = document.createElement('input');
		problemsInput.type = 'checkbox';
		problemsOnly.appendChild(problemsInput);
		problemsOnly.appendChild(document.createTextNode(t('showProblems', 'Show only problems')));
		card.appendChild(problemsOnly);

		var tableWrap = document.createElement('div');
		tableWrap.className = 'low-pte-bulk-table-wrap';
		card.appendChild(tableWrap);
		problemsInput.addEventListener('change', function () {
			renderPreviewTable(tableWrap, body.rows || [], problemsInput.checked);
		});
		renderPreviewTable(tableWrap, body.rows || [], false);

		if (changed > 50) {
			var confirmLabel = document.createElement('label');
			confirmLabel.className = 'low-pte-check';
			var confirmInput = document.createElement('input');
			confirmInput.type = 'checkbox';
			confirmLabel.appendChild(confirmInput);
			confirmLabel.appendChild(document.createTextNode(
				t('understandBulk', 'I understand this will change %s items').replace('%s', String(changed))
			));
			card.appendChild(confirmLabel);
			confirmInput.addEventListener('change', function () {
				applyButton.disabled = !confirmInput.checked;
			});
		}

		var actions = document.createElement('div');
		actions.className = 'low-pte-modal-actions';
		var back = document.createElement('button');
		back.type = 'button';
		back.className = 'button';
		back.textContent = t('back', 'Back');
		back.addEventListener('click', renderConfigure);
		var applyButton = document.createElement('button');
		applyButton.type = 'button';
		applyButton.className = 'button button-primary';
		applyButton.textContent = t('applyTo', 'Apply to %s items').replace('%s', String(changed));
		applyButton.disabled = changed === 0 || changed > 50;
		applyButton.addEventListener('click', function () {
			runApply(Array.isArray(body.item_ids) ? body.item_ids : []);
		});
		actions.appendChild(back);
		actions.appendChild(applyButton);
		card.appendChild(actions);
		back.focus();
	}

	function renderPreviewTable(wrap, rows, problemsOnly) {
		wrap.textContent = '';
		var table = document.createElement('table');
		table.className = 'low-pte-bulk-table';
		var head = document.createElement('tr');
		[
			t('columnProduct', 'Product'),
			t('columnSoldBy', 'Sold By'),
			t('logField', 'Field'),
			t('logOld', 'Old'),
			t('logNew', 'New'),
			t('bulkChange', 'Change'),
			t('postStatus', 'Status')
		].forEach(function (label) {
			var cell = document.createElement('th');
			cell.scope = 'col';
			cell.textContent = label;
			head.appendChild(cell);
		});
		table.appendChild(head);
		rows.forEach(function (row) {
			if (problemsOnly && row.status !== 'skipped' && row.status !== 'error') {
				return;
			}
			var line = document.createElement('tr');
			if (row.status === 'error' || row.status === 'skipped') {
				line.className = 'low-pte-bulk-problem';
			}
			appendText(line, row.name);
			appendText(line, row.sold_by);
			appendText(line, fieldLabel(row.field));
			appendText(line, row.old);
			appendText(line, row.new);
			appendText(line, row.change || '');
			var status = document.createElement('td');
			status.appendChild(statusLabel(row.status, row.reason));
			line.appendChild(status);
			table.appendChild(line);
		});
		wrap.appendChild(table);
	}

	function runApply(itemIds) {
		if (applying) {
			return;
		}
		applying = true;
		stopAfterChunk = false;
		var batchId = window.crypto && crypto.randomUUID ? crypto.randomUUID() : '';
		var chunkSize = 25;
		var chunks = Math.max(1, Math.ceil(itemIds.length / chunkSize));
		var processed = 0;
		var totals = { changed: 0, unchanged: 0, skipped: 0, errors: 0, fields: {} };
		var problems = [];
		var logFailed = false;
		var stopped = false;
		var failedMessage = '';

		var card = progressCard(itemIds.length);
		var bar = card.querySelector('progress');
		var label = card.querySelector('.low-pte-bulk-progress-text');
		var stopButton = card.querySelector('.low-pte-bulk-stop');
		stopButton.addEventListener('click', function () {
			stopAfterChunk = true;
			stopButton.disabled = true;
		});

		function next(index) {
			if (stopAfterChunk || index >= chunks) {
				applying = false;
				showReport(totals, problems, itemIds.slice(processed), logFailed, failedMessage, stopped || stopAfterChunk);
				return;
			}
			var slice = itemIds.slice(index * chunkSize, (index + 1) * chunkSize);
			label.textContent = t('appliedProgress', 'Applied %1$s of %2$s')
				.replace('%1$s', String(processed))
				.replace('%2$s', String(itemIds.length));
			sendChunk(batchId, slice, index, chunks, 0).then(function (result) {
				if (!result || !result.response || !result.response.ok) {
					applying = false;
					failedMessage = messageFrom(result && result.body) || t('bulkChunkFailed', 'A chunk could not be applied. The items below were not changed.');
					showReport(totals, problems, itemIds.slice(processed), logFailed, failedMessage, true);
					return;
				}
				var body = result.body || {};
				totals.changed += Number(body.applied) || 0;
				totals.unchanged += Number(body.unchanged) || 0;
				totals.skipped += Number(body.skipped) || 0;
				totals.errors += Number(body.errors) || 0;
				addFieldCounts(totals.fields, body.field_counts || []);
				if (body.log_failed) {
					logFailed = true;
				}
				(body.results || []).forEach(function (row) {
					if (row.status === 'skipped' || row.status === 'error') {
						problems.push(row);
					}
				});
				processed += slice.length;
				if (bar) {
					bar.value = processed;
					bar.setAttribute('aria-valuenow', String(processed));
				}
				label.textContent = t('appliedProgress', 'Applied %1$s of %2$s')
					.replace('%1$s', String(processed))
					.replace('%2$s', String(itemIds.length));
				if (stopAfterChunk) {
					stopped = true;
				}
				next(index + 1);
			}).catch(function () {
				applying = false;
				failedMessage = t('bulkChunkFailed', 'A chunk could not be applied. The items below were not changed.');
				itemIds.slice(index * chunkSize).forEach(function (id) {
					problems.push({ id: id, name: '', field: '', status: 'not_applied', reason: 'not_applied' });
				});
				showReport(totals, problems, [], logFailed, failedMessage, true);
			});
		}

		next(0);
	}

	function sendChunk(batchId, ids, index, total, attempt) {
		return postJson('bulk/apply', {
			batch_id: batchId,
			selection: { mode: 'ids', ids: ids },
			action: actionPayload(),
			chunk_index: index,
			chunk_total: total
		}).then(function (result) {
			var retry = attempt < 1 && (!result.response || result.response.status >= 500);
			if (retry) {
				return sendChunk(batchId, ids, index, total, attempt + 1);
			}
			return result;
		}).catch(function (error) {
			if (attempt < 1) {
				return sendChunk(batchId, ids, index, total, attempt + 1);
			}
			throw error;
		});
	}

	function progressCard(total) {
		closeModal(false);
		var card = openShell(t('bulkTitle', 'Bulk price action'), true);
		var label = document.createElement('p');
		label.className = 'low-pte-bulk-progress-text';
		label.textContent = t('appliedProgress', 'Applied %1$s of %2$s').replace('%1$s', '0').replace('%2$s', String(total));
		var bar = document.createElement('progress');
		bar.max = total || 1;
		bar.value = 0;
		bar.setAttribute('role', 'progressbar');
		bar.setAttribute('aria-valuemin', '0');
		bar.setAttribute('aria-valuemax', String(total));
		bar.setAttribute('aria-valuenow', '0');
		bar.setAttribute('aria-label', label.textContent);
		var stop = document.createElement('button');
		stop.type = 'button';
		stop.className = 'button low-pte-bulk-stop';
		stop.textContent = t('stopChunk', 'Stop after this chunk');
		card.appendChild(label);
		card.appendChild(bar);
		card.appendChild(stop);
		stop.focus();
		return card;
	}

	function showReport(totals, problems, remaining, logFailed, failedMessage, stopped) {
		closeModal(false);
		remaining.forEach(function (id) {
			problems.push({ id: id, name: '', field: '', status: 'not_applied', reason: 'not_applied' });
		});
		var card = openShell(t('bulkReport', 'Bulk price report'));
		var line = document.createElement('p');
		line.textContent = summaryLine(totals, Object.keys(totals.fields || {}).map(function (key) {
			return totals.fields[key];
		}));
		card.appendChild(line);
		if (logFailed) {
			var warn = document.createElement('p');
			warn.textContent = t('bulkLogFailed', 'Saved, but the change could not be logged, so it cannot be undone.');
			card.appendChild(warn);
		}
		if (failedMessage) {
			var fail = document.createElement('p');
			fail.textContent = failedMessage;
			card.appendChild(fail);
		}
		if (problems.length) {
			var wrap = document.createElement('div');
			wrap.className = 'low-pte-bulk-table-wrap';
			renderPreviewTable(wrap, problems, false);
			card.appendChild(wrap);
		}
		var actions = document.createElement('div');
		actions.className = 'low-pte-modal-actions';
		if (problems.length) {
			var download = document.createElement('button');
			download.type = 'button';
			download.className = 'button';
			download.textContent = t('downloadReport', 'Download report as CSV');
			download.addEventListener('click', function () {
				downloadCsv(problems);
			});
			actions.appendChild(download);
		}
		// Phase 6 inserts the Undo this batch button here. Do not render it in this phase.
		var close = document.createElement('button');
		close.type = 'button';
		close.className = 'button button-primary';
		close.textContent = t('close', 'Close');
		close.addEventListener('click', function () {
			closeModal(true);
			host.reload();
		});
		actions.appendChild(close);
		card.appendChild(actions);
		close.focus();
		if (stopped) {
			applying = false;
		}
	}

	function downloadCsv(rows) {
		var lines = ['id,name,field,status,reason'];
		rows.forEach(function (row) {
			lines.push([row.id, row.name, row.field, row.status, reasonText(row.reason)].map(csvCell).join(','));
		});
		var blob = new Blob([lines.join('\n')], { type: 'text/csv' });
		var link = document.createElement('a');
		link.href = URL.createObjectURL(blob);
		link.download = 'low-pte-bulk-report.csv';
		document.body.appendChild(link);
		link.click();
		link.remove();
	}

	function csvCell(value) {
		var text = value === null || value === undefined ? '' : String(value);
		return '"' + text.replace(/"/g, '""') + '"';
	}

	function postJson(path, body) {
		return window.fetch(host.restRoot() + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/json',
				'X-WP-Nonce': host.nonce()
			},
			body: JSON.stringify(body)
		}).then(function (response) {
			return response.json().then(function (payload) {
				return { response: response, body: payload };
			}).catch(function () {
				return { response: response, body: {} };
			});
		});
	}

	function actionPayload() {
		return {
			operation: actionState.operation,
			value: actionState.value,
			target: actionState.target,
			rounding: actionState.rounding
		};
	}

	function unitLabel() {
		return actionState.operation.indexOf('percent') !== -1 ? '%' : '$';
	}

	function valueValid(value) {
		return /^\d+(\.\d{1,4})?$/.test(value);
	}

	function radioGroup(legend, name, options, current, onChange) {
		var group = document.createElement('fieldset');
		var caption = document.createElement('legend');
		caption.textContent = legend;
		group.appendChild(caption);
		options.forEach(function (option) {
			var label = document.createElement('label');
			var input = document.createElement('input');
			input.type = 'radio';
			input.name = 'low-pte-bulk-' + name;
			input.value = option[0];
			input.checked = option[0] === current;
			input.addEventListener('change', function () {
				if (input.checked) {
					onChange(option[0]);
				}
			});
			label.appendChild(input);
			label.appendChild(document.createTextNode(option[1]));
			group.appendChild(label);
		});
		return group;
	}

	function labeled(text, control) {
		var label = document.createElement('label');
		label.className = 'low-pte-field';
		var span = document.createElement('span');
		span.className = 'low-pte-field-label';
		span.textContent = text;
		label.appendChild(span);
		label.appendChild(control);
		return label;
	}

	function appendText(row, value) {
		var cell = document.createElement('td');
		cell.textContent = value === null || value === undefined ? '' : String(value);
		row.appendChild(cell);
	}

	function statusLabel(status, reason) {
		var wrap = document.createElement('span');
		var icon = document.createElement('span');
		icon.setAttribute('aria-hidden', 'true');
		icon.textContent = status === 'error' || status === 'not_applied' ? '! ' : (status === 'skipped' ? '- ' : '');
		wrap.appendChild(icon);
		wrap.appendChild(document.createTextNode(statusText(status) + (reasonText(reason) ? ': ' + reasonText(reason) : '')));
		return wrap;
	}

	function statusText(status) {
		var map = {
			changed: t('statusChanged', 'Changed'),
			unchanged: t('statusUnchanged', 'Unchanged'),
			skipped: t('statusSkipped', 'Skipped'),
			error: t('statusError', 'Error'),
			not_applied: t('statusNotApplied', 'Not applied')
		};
		return map[status] || String(status || '');
	}

	function reasonText(reason) {
		if (!reason) {
			return '';
		}
		var key = 'reason_' + reason;
		var fallback = {
			price_empty: 'Empty price.',
			below_zero: 'The price would be below zero.',
			regular_zero: 'The regular price would be zero.',
			sale_not_lower: 'The sale price must be lower than the regular price.',
			cannot_edit: 'You cannot edit this product.',
			not_found: 'Product not found.',
			unsupported: 'This product type cannot be updated.',
			invalid_price: 'The stored price is not valid.',
			item_blocked: 'This field was not changed because another field on the item failed.',
			save_failed: 'The changes could not be saved.',
			not_applied: 'Stopped before this item was applied.'
		};
		return t(key, fallback[reason] || reason);
	}

	function fieldLabel(field) {
		if (field === 'regular_price') {
			return t('targetRegular', 'Regular price');
		}
		if (field === 'sale_price') {
			return t('targetSale', 'Sale price');
		}
		return field ? String(field) : '';
	}

	function messageFrom(body) {
		if (body && typeof body.message === 'string') {
			return body.message;
		}
		return '';
	}

	function openShell(title, lockEscape) {
		modal = document.createElement('div');
		modal.className = 'low-pte-modal';
		modal.setAttribute('role', 'dialog');
		modal.setAttribute('aria-modal', 'true');
		var card = document.createElement('div');
		card.className = 'low-pte-modal-card low-pte-bulk-card';
		var heading = document.createElement('h2');
		heading.className = 'low-pte-modal-title';
		heading.textContent = title;
		card.appendChild(heading);
		modal.appendChild(card);
		modal.addEventListener('keydown', function (event) {
			if (event.key === 'Escape' && !lockEscape && !applying) {
				event.preventDefault();
				closeModal(true);
			}
		});
		document.body.appendChild(modal);
		return card;
	}

	function closeModal(restore) {
		if (modal && modal.parentNode) {
			modal.parentNode.removeChild(modal);
		}
		modal = null;
		if (restore && returnFocus && typeof returnFocus.focus === 'function') {
			returnFocus.focus();
		}
	}
}());
