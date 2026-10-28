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
	$low_pte_delete_log = 'yes' === get_option( 'low_pte_delete_data_on_uninstall', 'no' );

	delete_option( 'low_pte_version' );
	delete_option( 'low_pte_retention_days' );
	delete_option( 'low_pte_delete_data_on_uninstall' );
	delete_transient( 'low_pte_github_release' );
	delete_transient( 'low_pte_github_release_failed' );
	wp_clear_scheduled_hook( 'low_pte_daily_purge' );

	if ( ! $low_pte_delete_log ) {
		return;
	}

	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table name is the current site prefix. Dropped only when the site opted in.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}low_pte_log" );
	delete_option( 'low_pte_db_version' );
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
