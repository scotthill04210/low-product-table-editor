<?php
/**
 * Uninstall LOW Product Table Editor.
 *
 * @package LOW\ProductTableEditor
 */

declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Delete plugin data for the current site.
 *
 * @return void
 */
function low_pte_uninstall_current_site(): void {
	delete_option( 'low_pte_version' );
	delete_transient( 'low_pte_github_release' );
	delete_transient( 'low_pte_github_release_failed' );

	// TODO (phase 4): drop the audit log table for this site.
}

if ( is_multisite() ) {
	$low_pte_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $low_pte_site_ids as $low_pte_site_id ) {
		switch_to_blog( (int) $low_pte_site_id );

		if ( false !== get_option( 'low_pte_version', false ) ) {
			low_pte_uninstall_current_site();
		}

		restore_current_blog();
	}
} else {
	low_pte_uninstall_current_site();
}
