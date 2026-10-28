<?php
/**
 * Products submenu screen.
 *
 * @package LOW\ProductTableEditor
 */

declare( strict_types=1 );

namespace LOW\ProductTableEditor;

defined( 'ABSPATH' ) || exit;

/**
 * Admin page under Products.
 *
 * @since 0.1.0
 */
class Admin_Page {

	/**
	 * Hook suffix returned by add_submenu_page().
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Register menu and asset hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Add the Table Editor submenu under Products.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_menu(): void {
		$hook_suffix = add_submenu_page(
			'edit.php?post_type=product',
			__( 'Table Editor', 'low-product-table-editor' ),
			__( 'Table Editor', 'low-product-table-editor' ),
			'manage_woocommerce', // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
			'low-pte',
			array( $this, 'render' )
		);

		if ( is_string( $hook_suffix ) ) {
			$this->hook_suffix = $hook_suffix;
		}
	}

	/**
	 * Render the editor shell.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
			wp_die(
				esc_html__( 'Sorry, you are not allowed to access this page.', 'low-product-table-editor' ),
				esc_html__( 'Not allowed', 'low-product-table-editor' ),
				array(
					'response' => 403,
				)
			);
		}

		?>
		<div class="wrap low-pte-wrap">
			<h1><?php esc_html_e( 'Table Editor', 'low-product-table-editor' ); ?></h1>
			<div class="low-pte-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Editor sections', 'low-product-table-editor' ); ?>">
				<button type="button" class="low-pte-tab low-pte-tab-active" role="tab" id="low-pte-tab-table" aria-selected="true" aria-controls="low-pte-app">
					<?php esc_html_e( 'Table', 'low-product-table-editor' ); ?>
				</button>
				<span class="low-pte-tab-slot" title="<?php esc_attr_e( 'Available in a later release', 'low-product-table-editor' ); ?>">
					<button type="button" class="low-pte-tab" role="tab" id="low-pte-tab-history" aria-selected="false" aria-disabled="true" aria-controls="low-pte-app" disabled="disabled" title="<?php esc_attr_e( 'Available in a later release', 'low-product-table-editor' ); ?>">
						<?php esc_html_e( 'History', 'low-product-table-editor' ); ?>
					</button>
				</span>
			</div>
			<div id="low-pte-app" class="low-pte-app" aria-live="polite">
				<p class="low-pte-loading"><?php esc_html_e( 'Loading editor...', 'low-product-table-editor' ); ?></p>
			</div>
			<noscript>
				<p class="low-pte-noscript"><?php esc_html_e( 'The table editor requires JavaScript.', 'low-product-table-editor' ); ?></p>
			</noscript>
		</div>
		<?php
	}

	/**
	 * Enqueue Tabulator and the app shell on this screen only.
	 *
	 * @since 0.1.0
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue( string $hook ): void {
		if ( '' === $this->hook_suffix || $hook !== $this->hook_suffix ) {
			return;
		}

		$tabulator_version = $this->tabulator_version();

		wp_enqueue_style(
			'low-pte-tabulator',
			LOW_PTE_URL . 'assets/vendor/tabulator/tabulator.min.css',
			array(),
			$tabulator_version
		);

		wp_enqueue_script(
			'low-pte-tabulator',
			LOW_PTE_URL . 'assets/vendor/tabulator/tabulator.min.js',
			array(),
			$tabulator_version,
			true
		);

		wp_enqueue_style(
			'low-pte-admin',
			LOW_PTE_URL . 'assets/css/admin.css',
			array(),
			LOW_PTE_VERSION
		);

		wp_enqueue_script(
			'low-pte-app',
			LOW_PTE_URL . 'assets/js/app.js',
			array( 'low-pte-tabulator' ),
			LOW_PTE_VERSION,
			true
		);

		$config = wp_json_encode( $this->script_config() );

		if ( ! is_string( $config ) ) {
			return;
		}

		wp_add_inline_script(
			'low-pte-app',
			'window.LOW_PTE_CONFIG = ' . $config . ';',
			'before'
		);
	}

	/**
	 * Data passed to the admin app.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	private function script_config(): array {
		return array(
			'restUrl'        => rest_url( 'low-pte/v1/' ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'perPageOptions' => array( 10, 25, 50, 100 ),
			'defaultPerPage' => 25,
			'version'        => LOW_PTE_VERSION,
			'i18n'           => array(
				'loading'        => __( 'Loading editor...', 'low-product-table-editor' ),
				'placeholder'    => __( 'Table editor loads here in the next phase', 'low-product-table-editor' ),
				'title'          => __( 'Table Editor', 'low-product-table-editor' ),
				'table'          => __( 'Table', 'low-product-table-editor' ),
				'history'        => __( 'History', 'low-product-table-editor' ),
				'historyTooltip' => __( 'Available in a later release', 'low-product-table-editor' ),
				'noscript'       => __( 'The table editor requires JavaScript.', 'low-product-table-editor' ),
				'sections'       => __( 'Editor sections', 'low-product-table-editor' ),
			),
		);
	}

	/**
	 * Pinned Tabulator version from the bundled VERSION.txt file.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	private function tabulator_version(): string {
		$fallback = '6.7.1';
		$path     = LOW_PTE_DIR . 'assets/vendor/tabulator/VERSION.txt';

		if ( ! is_readable( $path ) ) {
			return $fallback;
		}

		// Local version pin shipped with the plugin. Not a remote request.
		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( ! is_string( $contents ) ) {
			return $fallback;
		}

		$version = trim( $contents );

		if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
			return $fallback;
		}

		return $version;
	}
}
