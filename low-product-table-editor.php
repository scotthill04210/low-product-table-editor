<?php
/**
 * Plugin Name:       LOW Product Table Editor
 * Description:       Edit WooCommerce products and variations in one spreadsheet-style table.
 * Version:           0.1.1
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            Scott Hill
 * Update URI:        https://github.com/scotthill04210/low-product-table-editor
 * Text Domain:       low-product-table-editor
 * Domain Path:       /languages
 *
 * @package LOW\ProductTableEditor
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'LOW_PTE_VERSION', '0.1.1' );
define( 'LOW_PTE_DB_VERSION', '1' );
define( 'LOW_PTE_FILE', __FILE__ );
define( 'LOW_PTE_DIR', plugin_dir_path( __FILE__ ) );
define( 'LOW_PTE_URL', plugin_dir_url( __FILE__ ) );
define( 'LOW_PTE_MIN_PHP', '8.1' );
define( 'LOW_PTE_GITHUB_REPO', 'scotthill04210/low-product-table-editor' );

/**
 * Admin notice when PHP is older than this plugin supports.
 *
 * @return void
 */
function low_pte_php_version_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	$message = sprintf(
		/* translators: 1: required PHP version, 2: current PHP version. */
		__( 'LOW Product Table Editor requires PHP %1$s or newer. This site is running PHP %2$s.', 'low-product-table-editor' ),
		LOW_PTE_MIN_PHP,
		PHP_VERSION
	);

	echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
}

if ( version_compare( PHP_VERSION, LOW_PTE_MIN_PHP, '<' ) ) {
	add_action( 'admin_notices', 'low_pte_php_version_notice' );
	return;
}

/**
 * Autoload LOW\ProductTableEditor classes.
 *
 * Class_Name maps to includes/class-class-name.php.
 *
 * @param string $class_name Fully qualified class name.
 * @return void
 */
function low_pte_autoload( string $class_name ): void {
	$prefix = 'LOW\\ProductTableEditor\\';

	if ( ! str_starts_with( $class_name, $prefix ) ) {
		return;
	}

	$relative = substr( $class_name, strlen( $prefix ) );

	if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $relative ) ) {
		return;
	}

	$file = LOW_PTE_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';

	if ( is_readable( $file ) ) {
		require_once $file;
	}
}

spl_autoload_register( 'low_pte_autoload' );

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage.
 *
 * @return void
 */
function low_pte_declare_hpos_compatibility(): void {
	if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		return;
	}

	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', LOW_PTE_FILE, true );
}

/**
 * Boot the plugin after other plugins have loaded.
 *
 * @return void
 */
function low_pte_boot(): void {
	\LOW\ProductTableEditor\Plugin::instance()->boot();
}

add_action( 'before_woocommerce_init', 'low_pte_declare_hpos_compatibility' );
add_action( 'plugins_loaded', 'low_pte_boot', 20 );

register_activation_hook( __FILE__, array( \LOW\ProductTableEditor\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \LOW\ProductTableEditor\Plugin::class, 'deactivate' ) );
