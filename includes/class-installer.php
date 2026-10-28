<?php
/**
 * Schema installer.
 *
 * @package LOW\ProductTableEditor
 */

declare( strict_types=1 );

namespace LOW\ProductTableEditor;

defined( 'ABSPATH' ) || exit;

/**
 * Installs and upgrades plugin schema for the current site.
 *
 * @since 0.1.0
 */
class Installer {

	/**
	 * Create or upgrade plugin tables when the stored schema version differs.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function maybe_install(): void {
		$stored = get_option( 'low_pte_db_version', '' );

		if ( (string) LOW_PTE_DB_VERSION === (string) $stored ) {
			return;
		}

		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table           = $wpdb->prefix . 'low_pte_log';
		$sql             = "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  batch_id CHAR(36) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  parent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  field VARCHAR(40) NOT NULL,
  old_value LONGTEXT NULL,
  new_value LONGTEXT NULL,
  source VARCHAR(10) NOT NULL,
  created_at DATETIME NOT NULL,
  undone_at DATETIME NULL,
  PRIMARY KEY  (id),
  KEY batch_id (batch_id),
  KEY product_id (product_id),
  KEY created_at (created_at)
) {$charset_collate};";

		dbDelta( $sql );
		update_option( 'low_pte_db_version', LOW_PTE_DB_VERSION, false );
	}

	/**
	 * Schedule the daily purge if it is missing.
	 *
	 * @since 0.1.1
	 *
	 * @return void
	 */
	public static function ensure_cron(): void {
		if ( false !== wp_next_scheduled( 'low_pte_daily_purge' ) ) {
			return;
		}

		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'low_pte_daily_purge' );
	}

	/**
	 * Delete log rows older than the retention setting.
	 *
	 * @since 0.1.1
	 *
	 * @return void
	 */
	public static function purge_expired(): void {
		$days = (int) get_option( 'low_pte_retention_days', 180 );

		if ( $days < 1 ) {
			$days = 180;
		}

		if ( $days > 3650 ) {
			$days = 3650;
		}

		$repository = new Log_Repository();
		$repository->purge_older_than( $days );
	}
}
