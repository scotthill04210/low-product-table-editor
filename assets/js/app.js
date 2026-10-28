/**
 * LOW Product Table Editor admin shell.
 *
 * Phase 1 replaces the loading placeholder. The grid arrives in a later phase.
 */
( function () {
	'use strict';

	const mount = document.getElementById( 'low-pte-app' );
	const config = window.LOW_PTE_CONFIG;

	if ( ! mount ) {
		return;
	}

	let message = 'Table editor loads here in the next phase';

	if (
		config &&
		config.i18n &&
		typeof config.i18n.placeholder === 'string' &&
		config.i18n.placeholder !== ''
	) {
		message = config.i18n.placeholder;
	}

	const note = document.createElement( 'p' );
	note.className = 'low-pte-placeholder';
	note.textContent = message;
	mount.replaceChildren( note );
}() );
