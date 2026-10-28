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
		add_action( 'admin_post_low_pte_save_settings', array( $this, 'save_settings' ) );
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

		$low_pte_settings_saved = isset( $_GET['low_pte_settings'] ) && 'saved' === sanitize_key( wp_unslash( (string) $_GET['low_pte_settings'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display flag set after a nonce-checked settings save.

		?>
		<div class="wrap low-pte-wrap">
			<h1><?php esc_html_e( 'Table Editor', 'low-product-table-editor' ); ?></h1>
			<?php if ( $low_pte_settings_saved ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Log settings saved.', 'low-product-table-editor' ); ?></p>
				</div>
			<?php endif; ?>
			<div class="low-pte-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Editor sections', 'low-product-table-editor' ); ?>">
				<button type="button" class="low-pte-tab low-pte-tab-active" role="tab" id="low-pte-tab-table" aria-selected="true" aria-controls="low-pte-table-panel">
					<?php esc_html_e( 'Table', 'low-product-table-editor' ); ?>
				</button>
				<button type="button" class="low-pte-tab" role="tab" id="low-pte-tab-history" aria-selected="false" aria-controls="low-pte-history">
					<?php esc_html_e( 'History', 'low-product-table-editor' ); ?>
				</button>
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
			array( 'low-pte-tabulator' ),
			$this->asset_version( 'assets/css/admin.css' )
		);

		wp_enqueue_script(
			'low-pte-app',
			LOW_PTE_URL . 'assets/js/app.js',
			array( 'low-pte-tabulator' ),
			$this->asset_version( 'assets/js/app.js' ),
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
	 * Flattened product categories for the filter select.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function categories(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		$by_parent = array();
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$by_parent[ (int) $term->parent ][] = $term;
		}

		foreach ( $by_parent as $parent_id => $group ) {
			usort(
				$group,
				static function ( \WP_Term $left, \WP_Term $right ): int {
					return strcasecmp( $left->name, $right->name );
				}
			);
			$by_parent[ $parent_id ] = $group;
		}

		$flat = array();
		$this->append_category_branch( $by_parent, 0, 0, $flat );

		return $flat;
	}

	/**
	 * Append one category branch in parent-then-child order.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array<int, \WP_Term>> $by_parent      Terms grouped by parent ID.
	 * @param int                              $parent_term_id Parent term ID. Zero is the root.
	 * @param int                              $depth          Nesting depth.
	 * @param array<int, array<string, mixed>> $flat           Collected rows.
	 * @return void
	 */
	private function append_category_branch( array $by_parent, int $parent_term_id, int $depth, array &$flat ): void {
		if ( ! isset( $by_parent[ $parent_term_id ] ) ) {
			return;
		}

		foreach ( $by_parent[ $parent_term_id ] as $term ) {
			$flat[] = array(
				'slug'  => $term->slug,
				'name'  => $term->name,
				'depth' => $depth,
			);
			$this->append_category_branch( $by_parent, (int) $term->term_id, $depth + 1, $flat );
		}
	}

	/**
	 * Save retention and uninstall options, then return to History.
	 *
	 * @since 0.1.1
	 *
	 * @return void
	 */
	public function save_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
			wp_die(
				esc_html__( 'Sorry, you are not allowed to access this page.', 'low-product-table-editor' ),
				esc_html__( 'Not allowed', 'low-product-table-editor' ),
				array(
					'response' => 403,
				)
			);
		}

		check_admin_referer( 'low_pte_save_settings', 'low_pte_settings_nonce' );

		$raw_days = isset( $_POST['low_pte_retention_days'] ) ? sanitize_text_field( wp_unslash( $_POST['low_pte_retention_days'] ) ) : '180';
		$days     = absint( is_scalar( $raw_days ) ? $raw_days : 180 );
		if ( $days < 1 || $days > 3650 ) {
			$days = 180;
		}

		$delete_log = isset( $_POST['low_pte_delete_data_on_uninstall'] ) ? 'yes' : 'no';

		update_option( 'low_pte_retention_days', $days, false );
		update_option( 'low_pte_delete_data_on_uninstall', $delete_log, false );

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'        => 'product',
					'page'             => 'low-pte',
					'tab'              => 'history',
					'low_pte_settings' => 'saved',
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * Data passed to the admin app.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	private function script_config(): array {
		$retention = (int) get_option( 'low_pte_retention_days', 180 );
		if ( $retention < 1 || $retention > 3650 ) {
			$retention = 180;
		}

		return array(
			'restUrl'        => rest_url( 'low-pte/v1/' ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'perPageOptions' => array( 10, 25, 50, 100 ),
			'defaultPerPage' => 25,
			'version'        => LOW_PTE_VERSION,
			'categories'     => $this->categories(),
			'settings'       => array(
				'actionUrl'     => admin_url( 'admin-post.php' ),
				'nonce'         => wp_create_nonce( 'low_pte_save_settings' ),
				'retentionDays' => $retention,
				'deleteData'    => 'yes' === get_option( 'low_pte_delete_data_on_uninstall', 'no' ),
			),
			'i18n'           => array(
				'loading'              => __( 'Loading editor...', 'low-product-table-editor' ),
				'title'                => __( 'Table Editor', 'low-product-table-editor' ),
				'table'                => __( 'Table', 'low-product-table-editor' ),
				'history'              => __( 'History', 'low-product-table-editor' ),
				'historyTooltip'       => __( 'Available in a later release', 'low-product-table-editor' ),
				'logWhen'              => __( 'When', 'low-product-table-editor' ),
				'logWho'               => __( 'Who', 'low-product-table-editor' ),
				'logSource'            => __( 'Source', 'low-product-table-editor' ),
				'logItems'             => __( 'Items', 'low-product-table-editor' ),
				'logChanges'           => __( 'Changes', 'low-product-table-editor' ),
				'logSummary'           => __( 'Summary', 'low-product-table-editor' ),
				'logField'             => __( 'Field', 'low-product-table-editor' ),
				'logOld'               => __( 'Old', 'low-product-table-editor' ),
				'logNew'               => __( 'New', 'low-product-table-editor' ),
				'logUndone'            => __( 'Undone', 'low-product-table-editor' ),
				'logEmpty'             => __( 'No changes have been logged.', 'low-product-table-editor' ),
				'logError'             => __( 'The history could not be loaded. Try again.', 'low-product-table-editor' ),
				'logLoading'           => __( 'Loading history...', 'low-product-table-editor' ),
				'sourceInline'         => __( 'Inline', 'low-product-table-editor' ),
				'sourceBulk'           => __( 'Bulk', 'low-product-table-editor' ),
				'sourceUndo'           => __( 'Undo', 'low-product-table-editor' ),
				'allUsers'             => __( 'All users', 'low-product-table-editor' ),
				'user'                 => __( 'User', 'low-product-table-editor' ),
				'product'              => __( 'Product', 'low-product-table-editor' ),
				'dateFrom'             => __( 'Date from', 'low-product-table-editor' ),
				'dateTo'               => __( 'Date to', 'low-product-table-editor' ),
				'unknownUser'          => __( 'Unknown user', 'low-product-table-editor' ),
				'viewValue'            => __( 'View', 'low-product-table-editor' ),
				'close'                => __( 'Close', 'low-product-table-editor' ),
				'logSettings'          => __( 'Log settings', 'low-product-table-editor' ),
				'retentionDays'        => __( 'Keep log entries for this many days', 'low-product-table-editor' ),
				'deleteLogOnUninstall' => __( 'Delete the log table when the plugin is deleted', 'low-product-table-editor' ),
				'saveSettings'         => __( 'Save settings', 'low-product-table-editor' ),
				'expandBatch'          => __( 'Show changes', 'low-product-table-editor' ),
				'collapseBatch'        => __( 'Hide changes', 'low-product-table-editor' ),
				'logFailed'            => __( 'Saved, but the change could not be logged, so it cannot be undone.', 'low-product-table-editor' ),
				'noscript'             => __( 'The table editor requires JavaScript.', 'low-product-table-editor' ),
				'sections'             => __( 'Editor sections', 'low-product-table-editor' ),
				'search'               => __( 'Search', 'low-product-table-editor' ),
				'category'             => __( 'Category', 'low-product-table-editor' ),
				'productType'          => __( 'Type', 'low-product-table-editor' ),
				'stockStatus'          => __( 'Stock status', 'low-product-table-editor' ),
				'postStatus'           => __( 'Status', 'low-product-table-editor' ),
				'perPage'              => __( 'Per page', 'low-product-table-editor' ),
				'allCategories'        => __( 'All categories', 'low-product-table-editor' ),
				'allTypes'             => __( 'All types', 'low-product-table-editor' ),
				'allStock'             => __( 'All stock statuses', 'low-product-table-editor' ),
				'allStatuses'          => __( 'All statuses', 'low-product-table-editor' ),
				'simple'               => __( 'Simple', 'low-product-table-editor' ),
				'variable'             => __( 'Variable', 'low-product-table-editor' ),
				'inStock'              => __( 'In stock', 'low-product-table-editor' ),
				'outOfStock'           => __( 'Out of stock', 'low-product-table-editor' ),
				'onBackorder'          => __( 'On backorder', 'low-product-table-editor' ),
				'inherited'            => __( 'Inherited (parent)', 'low-product-table-editor' ),
				'statusPublish'        => __( 'Published', 'low-product-table-editor' ),
				'statusDraft'          => __( 'Draft', 'low-product-table-editor' ),
				'statusPrivate'        => __( 'Private', 'low-product-table-editor' ),
				'statusPending'        => __( 'Pending', 'low-product-table-editor' ),
				'clearFilters'         => __( 'Clear filters', 'low-product-table-editor' ),
				'expandAll'            => __( 'Expand all', 'low-product-table-editor' ),
				'collapseAll'          => __( 'Collapse all', 'low-product-table-editor' ),
				'columnProduct'        => __( 'Product', 'low-product-table-editor' ),
				'columnSoldBy'         => __( 'Sold By', 'low-product-table-editor' ),
				'columnRegularPrice'   => __( 'Regular price', 'low-product-table-editor' ),
				'columnSalePrice'      => __( 'Sale price', 'low-product-table-editor' ),
				'columnStock'          => __( 'Stock', 'low-product-table-editor' ),
				'columnSku'            => __( 'SKU', 'low-product-table-editor' ),
				'columnWeight'         => __( 'Weight', 'low-product-table-editor' ),
				'columnDescription'    => __( 'Description', 'low-product-table-editor' ),
				'columnStatus'         => __( 'Status', 'low-product-table-editor' ),
				// translators: %s: number of variations.
				'variationCount'       => __( '%s variations', 'low-product-table-editor' ),
				// translators: %s: number of variations.
				'variationCountSingle' => __( '%s variation', 'low-product-table-editor' ),
				// translators: 1: first product number, 2: last product number, 3: total products.
				'showing'              => __( 'Showing %1$s to %2$s of %3$s products', 'low-product-table-editor' ),
				'prev'                 => __( 'Previous', 'low-product-table-editor' ),
				'next'                 => __( 'Next', 'low-product-table-editor' ),
				// translators: %s: page number.
				'pageNumber'           => __( 'Page %s', 'low-product-table-editor' ),
				'pagination'           => __( 'Pagination', 'low-product-table-editor' ),
				'empty'                => __( 'No products match these filters', 'low-product-table-editor' ),
				'retry'                => __( 'Retry', 'low-product-table-editor' ),
				'loadError'            => __( 'Products could not be loaded. Try again.', 'low-product-table-editor' ),
				'sessionExpired'       => __( 'Your session expired. Reload the page.', 'low-product-table-editor' ),
				'yes'                  => __( 'Yes', 'low-product-table-editor' ),
				'no'                   => __( 'No', 'low-product-table-editor' ),
				'manageParent'         => __( 'Inherit from parent', 'low-product-table-editor' ),
				'inheritedStock'       => __( 'Inherited', 'low-product-table-editor' ),
				'notManaged'           => __( '-', 'low-product-table-editor' ),
				'columnManageStock'    => __( 'Manage stock', 'low-product-table-editor' ),
				'columnQuantity'       => __( 'Quantity', 'low-product-table-editor' ),
				'columnVisibility'     => __( 'Catalog visibility', 'low-product-table-editor' ),
				'visibilityVisible'    => __( 'Catalog and search', 'low-product-table-editor' ),
				'visibilityCatalog'    => __( 'Catalog', 'low-product-table-editor' ),
				'visibilitySearch'     => __( 'Search', 'low-product-table-editor' ),
				'visibilityHidden'     => __( 'Hidden', 'low-product-table-editor' ),
				// translators: %s: number of unsaved changes.
				'unsavedChange'        => __( '%s unsaved change', 'low-product-table-editor' ),
				// translators: %s: number of unsaved changes.
				'unsavedChanges'       => __( '%s unsaved changes', 'low-product-table-editor' ),
				'saveChanges'          => __( 'Save changes', 'low-product-table-editor' ),
				'discard'              => __( 'Discard', 'low-product-table-editor' ),
				// translators: %s: number of saved items.
				'savedItems'           => __( 'Saved %s items.', 'low-product-table-editor' ),
				'saveOrDiscard'        => __( 'Save or discard changes before leaving this page of results.', 'low-product-table-editor' ),
				'saveFailed'           => __( 'The changes could not be saved.', 'low-product-table-editor' ),
				'regularRequired'      => __( 'Regular price is required.', 'low-product-table-editor' ),
				'priceFormat'          => __( 'Use a number with up to 4 decimal places.', 'low-product-table-editor' ),
				'saleLower'            => __( 'Sale price must be lower than the regular price.', 'low-product-table-editor' ),
				'weightInvalid'        => __( 'Weight must be zero or greater.', 'low-product-table-editor' ),
				'nameRequired'         => __( 'Name is required.', 'low-product-table-editor' ),
				'nameTooLong'          => __( 'Name must be 200 characters or fewer.', 'low-product-table-editor' ),
				'quantityInvalid'      => __( 'Stock quantity must be a whole number.', 'low-product-table-editor' ),
				// translators: 1: number of rejected values, 2: reasons.
				'pasteRejected'        => __( '%1$s values were rejected. %2$s', 'low-product-table-editor' ),
				'reloadRow'            => __( 'Reload row', 'low-product-table-editor' ),
				'saved'                => __( 'Saved', 'low-product-table-editor' ),
				'rowError'             => __( 'This row has an error.', 'low-product-table-editor' ),
				'rowReloaded'          => __( 'Row reloaded.', 'low-product-table-editor' ),
				'rowReloadFailed'      => __( 'The row could not be reloaded.', 'low-product-table-editor' ),
				'descriptionTitle'     => __( 'Description', 'low-product-table-editor' ),
				'shortDescription'     => __( 'Short description', 'low-product-table-editor' ),
				'apply'                => __( 'Apply', 'low-product-table-editor' ),
				'cancel'               => __( 'Cancel', 'low-product-table-editor' ),
				'loadingDescription'   => __( 'Loading the description.', 'low-product-table-editor' ),
				'descriptionError'     => __( 'The description could not be loaded.', 'low-product-table-editor' ),
			),
		);
	}

	/**
	 * Plugin version plus the asset file time, so a new grid loads without a release.
	 *
	 * @since 0.1.0
	 *
	 * @param string $relative Path relative to the plugin root.
	 * @return string
	 */
	private function asset_version( string $relative ): string {
		$path  = LOW_PTE_DIR . ltrim( $relative, '/' );
		$mtime = is_readable( $path ) ? filemtime( $path ) : false;

		if ( false === $mtime ) {
			return LOW_PTE_VERSION;
		}

		return LOW_PTE_VERSION . '.' . $mtime;
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
