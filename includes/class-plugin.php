<?php
/**
 * Plugin bootstrap.
 *
 * @package LOW\ProductTableEditor
 */

declare( strict_types=1 );

namespace LOW\ProductTableEditor;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin controller.
 *
 * @since 0.1.0
 */
class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @since 0.1.0
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Prevent direct construction.
	 */
	private function __construct() {
	}

	/**
	 * Register hooks when dependencies are met.
	 *
	 * The updater is registered even when WooCommerce is missing so the
	 * plugin can still be updated from GitHub.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		$updater = new Updater();
		$updater->register();

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}

		$admin_page = new Admin_Page();
		$admin_page->register();

		$rest = new Rest_Controller( new Product_Service() );
		$rest->register();
	}

	/**
	 * Load translations.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'low-product-table-editor',
			false,
			dirname( plugin_basename( LOW_PTE_FILE ) ) . '/languages'
		);
	}

	/**
	 * Admin notice when WooCommerce is not active.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function woocommerce_missing_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		esc_html_e( 'LOW Product Table Editor requires WooCommerce to be installed and active.', 'low-product-table-editor' );
		echo '</p></div>';
	}

	/**
	 * Store the plugin version for the current site.
	 *
	 * Activation is per site. This plugin does not use network activation.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function activate(): void {
		update_option( 'low_pte_version', LOW_PTE_VERSION, false );
	}

	/**
	 * Run on deactivation.
	 *
	 * Stored data is removed from uninstall.php.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function deactivate(): void {
	}
}
