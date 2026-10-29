<?php
/**
 * CSV export and import.
 *
 * @package LOW\ProductTableEditor
 */

declare( strict_types=1 );

namespace LOW\ProductTableEditor;

defined( 'ABSPATH' ) || exit;

/**
 * Export catalog rows and preview or apply a CSV import.
 *
 * Import matches on SKU and never creates or deletes products.
 *
 * @since 1.1.0
 */
class Import_Export_Service {

	private const MAX_BYTES = 5242880;

	private const MAX_ROWS = 5000;

	private const PREVIEW_ROWS = 500;

	private const CHUNK_ROWS = 25;

	/**
	 * Product reads and saves.
	 *
	 * @var Product_Service
	 */
	private Product_Service $products;

	/**
	 * Bind the product service.
	 *
	 * @since 1.1.0
	 *
	 * @param Product_Service $products Product service.
	 */
	public function __construct( Product_Service $products ) {
		$this->products = $products;
	}

	/**
	 * Column keys for an export.
	 *
	 * @since 1.1.0
	 *
	 * @param bool $include_descriptions Whether description columns are included.
	 * @return array<int, string>
	 */
	public function export_columns( bool $include_descriptions ): array {
		$columns = array( 'sku', 'type', 'parent_sku', 'name', 'variation_label', 'sold_by', 'regular_price', 'sale_price', 'manage_stock', 'stock_quantity', 'stock_status', 'weight', 'status', 'catalog_visibility' );
		if ( $include_descriptions ) {
			$columns[] = 'description';
			$columns[] = 'short_description';
		}
		$columns[] = 'modified';
		$columns[] = 'id';

		return $columns;
	}

	/**
	 * Catalog rows for a CSV download, one parent page at a time.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $args scope, filters, ids, include_descriptions.
	 * @return \Generator<int, array<int, string>>
	 */
	public function export_rows( array $args ): \Generator {
		$descriptions = ! empty( $args['include_descriptions'] );
		$columns      = $this->export_columns( $descriptions );
		$scope        = isset( $args['scope'] ) ? (string) $args['scope'] : 'all';

		if ( 'selection' === $scope ) {
			$ids = isset( $args['ids'] ) && is_array( $args['ids'] ) ? $args['ids'] : array();
			yield from $this->export_ids( $ids, $columns, $descriptions );
			return;
		}

		$filters = isset( $args['filters'] ) && is_array( $args['filters'] ) ? $args['filters'] : array();
		if ( 'all' === $scope ) {
			$filters = array();
		}

		$page  = 1;
		$pages = 1;
		do {
			$query = array_merge(
				$filters,
				array(
					'page'     => $page,
					'per_page' => 50,
				)
			);
			$body  = $this->products->get_products( $query );
			$pages = max( 1, (int) ( $body['total_pages'] ?? 1 ) );
			foreach ( $body['items'] ?? array() as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				yield $this->export_item( $item, '', $columns, $descriptions );
				$parent_sku = (string) ( $item['sku'] ?? '' );
				foreach ( $item['children'] ?? array() as $child ) {
					if ( is_array( $child ) ) {
						yield $this->export_item( $child, $parent_sku, $columns, $descriptions );
					}
				}
			}
			++$page;
		} while ( $page <= $pages );
	}

	/**
	 * Parse an uploaded CSV and store the change set.
	 *
	 * @since 1.1.0
	 *
	 * @param string              $path    Temporary upload path.
	 * @param string              $name    Original file name.
	 * @param array<string, bool> $options skip_changed_since_export and allow_clear.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function preview_file( string $path, string $name, array $options ) {
		$parsed = $this->read_upload( $path, $name );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$skip_changed = ! array_key_exists( 'skip_changed_since_export', $options ) || $options['skip_changed_since_export'];
		$allow_clear  = ! empty( $options['allow_clear'] );
		$built        = $this->build_preview( $parsed['headers'], $parsed['rows'], $parsed['notices'], $skip_changed, $allow_clear );
		$stored       = $this->store_preview( $built['apply'] );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		return array(
			'token'     => $stored,
			'summary'   => $built['summary'],
			'notices'   => $built['notices'],
			'rows'      => $built['rows'],
			'truncated' => $built['truncated'],
		);
	}

	/**
	 * Apply one 25-row chunk from the stored preview.
	 *
	 * @since 1.1.0
	 *
	 * @param string $token       Preview token.
	 * @param string $batch_id    UUID shared by every chunk.
	 * @param int    $chunk_index Zero-based chunk.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function apply_chunk( string $token, string $batch_id, int $chunk_index ) {
		$payload = $this->load_preview( $token );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$stored_batch = (string) ( $payload['batch_id'] ?? '' );
		if ( '' === $stored_batch ) {
			$payload['batch_id'] = $batch_id;
			$saved               = $this->write_preview( $token, $payload );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
		} elseif ( $stored_batch !== $batch_id ) {
			return new \WP_Error(
				'low_pte_import_batch',
				__( 'This preview is already being applied.', 'low-product-table-editor' ),
				array( 'status' => 409 )
			);
		}

		$rows   = isset( $payload['rows'] ) && is_array( $payload['rows'] ) ? array_values( $payload['rows'] ) : array();
		$total  = count( $rows );
		$offset = $chunk_index * self::CHUNK_ROWS;
		$slice  = array_slice( $rows, $offset, self::CHUNK_ROWS );
		$result = $this->write_slice( $slice, $batch_id );

		$done = ( $offset + self::CHUNK_ROWS ) >= $total || array() === $slice;
		if ( $done ) {
			$this->delete_preview( $token );
		}

		return array(
			'results'    => $result['results'],
			'done'       => $done,
			'log_failed' => $result['log_failed'],
			'batch_id'   => $batch_id,
			'processed'  => min( $total, $offset + count( $slice ) ),
			'total'      => $total,
		);
	}

	/**
	 * Export selected IDs in chunks.
	 *
	 * @param array<int, mixed>  $ids          Product or variation IDs.
	 * @param array<int, string> $columns     Column keys.
	 * @param bool               $descriptions Whether descriptions are included.
	 * @return \Generator<int, array<int, string>>
	 */
	private function export_ids( array $ids, array $columns, bool $descriptions ): \Generator {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		foreach ( array_chunk( $ids, 50 ) as $chunk ) {
			_prime_post_caches( $chunk, false, false );
			update_meta_cache( 'post', $chunk );
			foreach ( $chunk as $id ) {
				$product = wc_get_product( $id );
				if ( ! $product instanceof \WC_Product ) {
					continue;
				}
				$parent_sku = '';
				if ( $product->is_type( 'variation' ) ) {
					$parent     = wc_get_product( $product->get_parent_id() );
					$parent_sku = $parent instanceof \WC_Product ? (string) $parent->get_sku( 'edit' ) : '';
				}
				$row = $this->products->build_row( $product );
				yield $this->export_item( $row, $parent_sku, $columns, $descriptions, $product );
			}
		}
	}

	/**
	 * One CSV record.
	 *
	 * @param array<string, mixed> $item         Row from the product service.
	 * @param string               $parent_sku   Parent SKU for a variation.
	 * @param array<int, string>   $columns      Column keys.
	 * @param bool                 $descriptions Whether descriptions are included.
	 * @param \WC_Product|null     $product      Loaded product when the row has no description text.
	 * @return array<int, string>
	 */
	private function export_item( array $item, string $parent_sku, array $columns, bool $descriptions, ?\WC_Product $product = null ): array {
		$quantity = $item['stock_quantity'] ?? '';
		$values   = array(
			'sku'                => (string) ( $item['sku'] ?? '' ),
			'type'               => (string) ( $item['type'] ?? '' ),
			'parent_sku'         => $parent_sku,
			'name'               => (string) ( $item['name'] ?? '' ),
			'variation_label'    => (string) ( $item['variation_label'] ?? '' ),
			'sold_by'            => (string) ( $item['sold_by'] ?? '' ),
			'regular_price'      => (string) ( $item['regular_price'] ?? '' ),
			'sale_price'         => (string) ( $item['sale_price'] ?? '' ),
			'manage_stock'       => (string) ( $item['manage_stock'] ?? '' ),
			'stock_quantity'     => null === $quantity ? '' : (string) $quantity,
			'stock_status'       => (string) ( $item['stock_status'] ?? '' ),
			'weight'             => (string) ( $item['weight'] ?? '' ),
			'status'             => (string) ( $item['status'] ?? '' ),
			'catalog_visibility' => (string) ( $item['catalog_visibility'] ?? '' ),
			'modified'           => (string) ( $item['date_modified'] ?? '' ),
			'id'                 => (string) ( $item['id'] ?? '' ),
		);

		if ( $descriptions ) {
			if ( ! $product instanceof \WC_Product ) {
				$product = wc_get_product( (int) ( $item['id'] ?? 0 ) );
			}
			$values['description']       = $product instanceof \WC_Product ? (string) $product->get_description( 'edit' ) : '';
			$values['short_description'] = $product instanceof \WC_Product && ! $product->is_type( 'variation' ) ? (string) $product->get_short_description( 'edit' ) : '';
		}

		$line = array();
		foreach ( $columns as $column ) {
			$text = $values[ $column ] ?? '';
			if ( in_array( $column, array( 'name', 'variation_label', 'sold_by', 'description', 'short_description' ), true ) ) {
				$text = $this->protect_spreadsheet( $text );
			}
			$line[] = $text;
		}

		return $line;
	}

	/**
	 * Read and validate an upload.
	 *
	 * @param string $path Temporary path.
	 * @param string $name Original name.
	 * @return array{headers: array<int, string>, rows: array<int, array<string, string>>, notices: array<int, string>}|\WP_Error
	 */
	private function read_upload( string $path, string $name ) {
		if ( ! is_readable( $path ) ) {
			return new \WP_Error( 'low_pte_import_file', __( 'The file could not be read.', 'low-product-table-editor' ), array( 'status' => 400 ) );
		}

		$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( 'csv' !== $extension ) {
			return new \WP_Error( 'low_pte_import_type', __( 'Upload a .csv file. Excel workbooks are not accepted yet.', 'low-product-table-editor' ), array( 'status' => 400 ) );
		}

		$size = filesize( $path );
		if ( false === $size || $size > self::MAX_BYTES ) {
			return new \WP_Error( 'low_pte_import_size', __( 'This file is larger than 5 MB. Split it into smaller CSV files.', 'low-product-table-editor' ), array( 'status' => 400 ) );
		}

		$mime    = $this->mime_type( $path );
		$allowed = array( 'text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/csv', 'text/comma-separated-values' );
		if ( ! in_array( $mime, $allowed, true ) && 'application/octet-stream' !== $mime ) {
			return new \WP_Error( 'low_pte_import_type', __( 'That file is not a CSV.', 'low-product-table-editor' ), array( 'status' => 400 ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- The path is the PHP upload temporary file, not a remote URL.
		$raw = file_get_contents( $path );
		if ( ! is_string( $raw ) ) {
			return new \WP_Error( 'low_pte_import_file', __( 'The file could not be read.', 'low-product-table-editor' ), array( 'status' => 400 ) );
		}

		$text = $this->to_utf8( $raw );
		if ( is_wp_error( $text ) ) {
			return $text;
		}

		$delimiter = $this->delimiter( $text );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Temporary memory stream for fgetcsv.
		$handle = fopen( 'php://temp', 'w+b' );
		if ( false === $handle ) {
			return new \WP_Error( 'low_pte_import_file', __( 'The file could not be read.', 'low-product-table-editor' ), array( 'status' => 400 ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writing into the memory stream.
		fwrite( $handle, $text );
		rewind( $handle );

		$header = fgetcsv( $handle, 0, $delimiter );
		if ( ! is_array( $header ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the memory stream.
			fclose( $handle );
			return new \WP_Error( 'low_pte_import_sku', __( 'The file needs a sku column.', 'low-product-table-editor' ), array( 'status' => 400 ) );
		}

		$mapped = $this->map_headers( $header );
		if ( is_wp_error( $mapped ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the memory stream.
			fclose( $handle );
			return $mapped;
		}

		$rows  = array();
		$count = 0;
		// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- fgetcsv returns the next row or false.
		while ( false !== ( $record = fgetcsv( $handle, 0, $delimiter ) ) ) {
			if ( ! is_array( $record ) || array( null ) === $record || array( '' ) === $record ) {
				continue;
			}
			++$count;
			if ( $count > self::MAX_ROWS ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the memory stream.
				fclose( $handle );
				return new \WP_Error( 'low_pte_import_rows', __( 'This file has more than 5,000 rows. Split it into smaller CSV files.', 'low-product-table-editor' ), array( 'status' => 400 ) );
			}
			$assoc = array();
			foreach ( $mapped['columns'] as $index => $column ) {
				if ( '' === $column || ! array_key_exists( $index, $record ) ) {
					continue;
				}
				$value            = is_string( $record[ $index ] ) ? $record[ $index ] : '';
				$assoc[ $column ] = $this->unprotect_spreadsheet( $value );
			}
			$has_value = false;
			foreach ( $assoc as $value ) {
				if ( '' !== trim( $value ) ) {
					$has_value = true;
					break;
				}
			}
			if ( ! $has_value ) {
				--$count;
				continue;
			}
			$rows[] = $assoc;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the memory stream.
		fclose( $handle );

		return array(
			'headers' => $mapped['columns'],
			'rows'    => $rows,
			'notices' => $mapped['notices'],
		);
	}

	/**
	 * Turn parsed rows into a preview and an apply list.
	 *
	 * @param array<int, string>                $headers Column keys, empty when unknown.
	 * @param array<int, array<string, string>> $records Data rows.
	 * @param array<int, string>                $notices File notices.
	 * @param bool                              $skip_changed Skip rows changed since export.
	 * @param bool                              $allow_clear  Allow blank cells to clear values.
	 * @return array{summary: array<string, int>, notices: array<int, string>, rows: array<int, array<string, mixed>>, truncated: bool, apply: array<int, array<string, mixed>>}
	 */
	private function build_preview( array $headers, array $records, array $notices, bool $skip_changed, bool $allow_clear ): array {
		$present = array_values( array_filter( $headers ) );
		$skus    = array();
		foreach ( $records as $record ) {
			$sku = $this->clean_sku( (string) ( $record['sku'] ?? '' ) );
			if ( '' !== $sku ) {
				$skus[] = $sku;
			}
		}
		$found = $this->ids_for_skus( $skus );
		$seen  = array();
		foreach ( $records as $record ) {
			$sku = strtolower( $this->clean_sku( (string) ( $record['sku'] ?? '' ) ) );
			if ( '' === $sku ) {
				continue;
			}
			$seen[ $sku ] = ( $seen[ $sku ] ?? 0 ) + 1;
		}

		$summary = array(
			'rows'                 => count( $records ),
			'matched'              => 0,
			'will_change'          => 0,
			'changes'              => 0,
			'clears'               => 0,
			'unchanged'            => 0,
			'not_found'            => 0,
			'errors'               => 0,
			'changed_since_export' => 0,
			'duplicates'           => 0,
		);
		$details = array();
		$apply   = array();
		$ids     = array();
		foreach ( $found as $matches ) {
			foreach ( $matches as $id ) {
				$ids[] = $id;
			}
		}
		foreach ( array_chunk( $ids, 50 ) as $chunk ) {
			_prime_post_caches( $chunk, false, false );
			update_meta_cache( 'post', $chunk );
		}

		foreach ( $records as $index => $record ) {
			$line = $index + 2;
			$sku  = $this->clean_sku( (string) ( $record['sku'] ?? '' ) );
			$key  = strtolower( $sku );
			if ( '' === $sku ) {
				++$summary['errors'];
				$details[] = $this->detail( $line, '', '', '', '', '', 'error', __( 'Each row needs a SKU.', 'low-product-table-editor' ) );
				continue;
			}
			if ( ( $seen[ $key ] ?? 0 ) > 1 ) {
				++$summary['duplicates'];
				$details[] = $this->detail( $line, $sku, '', '', '', '', 'duplicate_in_file', __( 'This SKU is repeated in the file. Both rows were skipped.', 'low-product-table-editor' ) );
				continue;
			}
			if ( $this->sku_looks_scientific( $sku ) ) {
				$notices[] = sprintf(
					/* translators: %s: SKU text from the file. */
					__( 'SKU %s looks like a number Excel shortened. Check it before applying.', 'low-product-table-editor' ),
					$sku
				);
			}
			$matches = $found[ $key ] ?? array();
			if ( array() === $matches ) {
				++$summary['not_found'];
				$details[] = $this->detail( $line, $sku, '', '', '', '', 'not_found', __( 'No product uses this SKU. Import does not create products.', 'low-product-table-editor' ) );
				continue;
			}
			if ( count( $matches ) > 1 ) {
				++$summary['errors'];
				$details[] = $this->detail( $line, $sku, '', '', '', '', 'error', __( 'This SKU matches more than one product.', 'low-product-table-editor' ) );
				continue;
			}

			$product = wc_get_product( $matches[0] );
			if ( ! $product instanceof \WC_Product ) {
				++$summary['not_found'];
				$details[] = $this->detail( $line, $sku, '', '', '', '', 'not_found', __( 'No product uses this SKU. Import does not create products.', 'low-product-table-editor' ) );
				continue;
			}
			++$summary['matched'];
			if ( ! current_user_can( 'edit_post', $product->get_id() ) ) {
				++$summary['errors'];
				$details[] = $this->detail( $line, $sku, $product->get_name(), '', '', '', 'error', __( 'You are not allowed to edit this item.', 'low-product-table-editor' ) );
				continue;
			}

			$planned = $this->plan_row( $product, $record, $present, $allow_clear, $notices );
			if ( array() !== $planned['errors'] ) {
				++$summary['errors'];
				foreach ( $planned['errors'] as $field => $message ) {
					$details[] = $this->detail( $line, $sku, $product->get_name(), (string) $field, (string) ( $planned['old'][ $field ] ?? '' ), (string) ( $planned['attempted'][ $field ] ?? '' ), 'error', $message );
				}
				continue;
			}

			$changed_since = $this->changed_since_export( $product, $record, $present );
			if ( $changed_since ) {
				++$summary['changed_since_export'];
			}
			if ( array() === $planned['fields'] ) {
				++$summary['unchanged'];
				$message   = $changed_since ? __( 'Changed since export, and none of the imported values differ.', 'low-product-table-editor' ) : '';
				$details[] = $this->detail( $line, $sku, $product->get_name(), '', '', '', 'unchanged', $message );
				continue;
			}

			if ( $changed_since && $skip_changed ) {
				$details[] = $this->detail( $line, $sku, $product->get_name(), '', '', '', 'changed_since_export', __( 'Changed since export. This row will be skipped.', 'low-product-table-editor' ) );
				continue;
			}

			++$summary['will_change'];
			foreach ( $planned['fields'] as $field => $value ) {
				$old      = $planned['old'][ $field ] ?? '';
				$is_clear = ( '' === $value || null === $value ) && '' !== (string) $old && null !== $old;
				if ( $is_clear ) {
					++$summary['clears'];
				} else {
					++$summary['changes'];
				}
				$status    = $changed_since ? 'changed_since_export' : ( $is_clear ? 'clear' : 'will_change' );
				$message   = $changed_since ? __( 'Changed since export. It will still be applied.', 'low-product-table-editor' ) : '';
				$details[] = $this->detail( $line, $sku, $product->get_name(), (string) $field, (string) $old, $this->display_value( $value ), $status, $message );
			}
			$apply[] = array(
				'line'     => $line,
				'sku'      => $sku,
				'id'       => $product->get_id(),
				'name'     => $product->get_name(),
				'modified' => $this->products->modified_unix( $product ),
				'fields'   => $planned['fields'],
				'old'      => $planned['old'],
			);
		}

		$notices   = array_values( array_unique( $notices ) );
		$truncated = count( $details ) > self::PREVIEW_ROWS;

		return array(
			'summary'   => $summary,
			'notices'   => $notices,
			'rows'      => array_slice( $details, 0, self::PREVIEW_ROWS ),
			'truncated' => $truncated,
			'apply'     => $apply,
		);
	}

	/**
	 * Fields that should change on one product.
	 *
	 * @param \WC_Product           $product     Product or variation.
	 * @param array<string, string> $record      CSV row.
	 * @param array<int, string>    $present     Columns present in the file.
	 * @param bool                  $allow_clear Whether blanks clear values.
	 * @param array<int, string>    $notices     File notices, updated by reference.
	 * @return array{fields: array<string, mixed>, old: array<string, string>, errors: array<string, string>, attempted: array<string, string>}
	 */
	private function plan_row( \WC_Product $product, array $record, array $present, bool $allow_clear, array &$notices ): array {
		$editable  = $this->products->editable_field_names( $product );
		$ignored   = array( 'sku', 'type', 'parent_sku', 'variation_label', 'sold_by', 'modified', 'id' );
		$fields    = array();
		$attempted = array();
		$blocked   = array();

		foreach ( $present as $column ) {
			if ( in_array( $column, $ignored, true ) || ! array_key_exists( $column, $record ) ) {
				continue;
			}
			$raw = trim( str_replace( "\xC2\xA0", ' ', $record[ $column ] ) );
			if ( ! in_array( $column, $editable, true ) ) {
				if ( '' !== $raw && $product->is_type( 'variable' ) && in_array( $column, array( 'regular_price', 'sale_price' ), true ) ) {
					$notices[] = __( 'Price columns on variable products were ignored.', 'low-product-table-editor' );
				}
				if ( '' !== $raw && $product->is_type( 'variation' ) && 'name' === $column ) {
					$notices[] = __( 'Names on variations were ignored.', 'low-product-table-editor' );
				}
				continue;
			}

			if ( '' === $raw ) {
				if ( in_array( $column, array( 'regular_price', 'name' ), true ) ) {
					$blocked[ $column ]   = '';
					$attempted[ $column ] = '';
					continue;
				}
				if ( ! $allow_clear || ! in_array( $column, array( 'sale_price', 'weight', 'stock_quantity', 'description', 'short_description' ), true ) ) {
					continue;
				}
				$fields[ $column ]    = 'stock_quantity' === $column ? null : '';
				$attempted[ $column ] = '';
				continue;
			}

			if ( in_array( $column, array( 'regular_price', 'sale_price', 'weight' ), true ) && str_starts_with( $raw, '$' ) ) {
				$raw = trim( substr( $raw, 1 ) );
			}
			$fields[ $column ]    = $raw;
			$attempted[ $column ] = $raw;
		}

		if ( array() !== $blocked ) {
			$fields = array_merge( $blocked, $fields );
		}

		if ( array() === $fields ) {
			return array(
				'fields'    => array(),
				'old'       => array(),
				'errors'    => array(),
				'attempted' => array(),
			);
		}

		$checked = $this->products->validate_import_fields( $product, $fields );
		if ( array() !== $checked['errors'] ) {
			$old = array();
			foreach ( array_keys( $checked['errors'] ) as $field ) {
				$old[ $field ] = (string) $this->products->logged_field( $product, (string) $field );
			}
			return array(
				'fields'    => array(),
				'old'       => $old,
				'errors'    => $checked['errors'],
				'attempted' => $attempted,
			);
		}

		$changes = array();
		$old     = array();
		foreach ( $checked['values'] as $field => $value ) {
			if ( $this->products->field_is_same( $product, (string) $field, $value ) ) {
				continue;
			}
			$changes[ $field ] = $value;
			$logged            = $this->products->logged_field( $product, (string) $field );
			$old[ $field ]     = null === $logged ? '' : $logged;
		}

		return array(
			'fields'    => $changes,
			'old'       => $old,
			'errors'    => array(),
			'attempted' => $attempted,
		);
	}

	/**
	 * Whether the file timestamp differs from the product.
	 *
	 * @param \WC_Product           $product Product or variation.
	 * @param array<string, string> $record  CSV row.
	 * @param array<int, string>    $present Columns present in the file.
	 * @return bool
	 */
	private function changed_since_export( \WC_Product $product, array $record, array $present ): bool {
		if ( ! in_array( 'modified', $present, true ) ) {
			return false;
		}
		$raw = trim( (string) ( $record['modified'] ?? '' ) );
		if ( '' === $raw || ! ctype_digit( $raw ) ) {
			return false;
		}

		return (int) $raw !== $this->products->modified_unix( $product );
	}

	/**
	 * Save one slice and log it as a single import batch.
	 *
	 * @param array<int, array<string, mixed>> $slice    Apply rows.
	 * @param string                           $batch_id Shared batch id.
	 * @return array{results: array<int, array<string, mixed>>, log_failed: bool}
	 */
	private function write_slice( array $slice, string $batch_id ): array {
		$results = array();
		$entries = array();
		$parents = array();

		foreach ( $slice as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id = (int) ( $row['id'] ?? 0 );
			if ( ! current_user_can( 'edit_post', $id ) ) {
				$results[] = $this->apply_result( $row, 'error', __( 'You are not allowed to edit this item.', 'low-product-table-editor' ) );
				continue;
			}
			$product = wc_get_product( $id );
			if ( ! $product instanceof \WC_Product ) {
				$results[] = $this->apply_result( $row, 'error', __( 'Product not found.', 'low-product-table-editor' ) );
				continue;
			}
			if ( $this->products->modified_unix( $product ) !== (int) ( $row['modified'] ?? -1 ) || $this->moved_since_preview( $product, $row ) ) {
				$results[] = $this->apply_result( $row, 'changed_since_preview', __( 'This item changed after the preview, so it was left as it is.', 'low-product-table-editor' ) );
				continue;
			}
			$fields  = isset( $row['fields'] ) && is_array( $row['fields'] ) ? $row['fields'] : array();
			$checked = $this->products->validate_import_fields( $product, $fields );
			if ( array() !== $checked['errors'] ) {
				$results[] = $this->apply_result( $row, 'error', implode( ' ', $checked['errors'] ) );
				continue;
			}
			$same = true;
			foreach ( $checked['values'] as $field => $value ) {
				if ( ! $this->products->field_is_same( $product, (string) $field, $value ) ) {
					$same = false;
					break;
				}
			}
			if ( $same ) {
				$results[] = $this->apply_result( $row, 'unchanged', '' );
				continue;
			}
			$written = $this->products->commit_import_fields( $id, $checked['values'] );
			if ( 'ok' !== $written['status'] ) {
				$results[] = $this->apply_result( $row, 'error', $written['message'] );
				continue;
			}
			foreach ( $written['entries'] as $entry ) {
				$entries[] = $entry;
			}
			if ( $written['parent_id'] > 0 ) {
				$parents[] = $written['parent_id'];
			}
			$results[] = $this->apply_result( $row, 'ok', '' );
		}

		$this->products->sync_variable_parents( $parents );
		$log_failed = false;
		if ( array() !== $entries ) {
			$log_failed = ! $this->products->log_changes( $batch_id, 'import', $entries );
		}

		return array(
			'results'    => $results,
			'log_failed' => $log_failed,
		);
	}

	/**
	 * Whether a stored old value no longer matches and the new value is not already there.
	 *
	 * @param \WC_Product          $product Product or variation.
	 * @param array<string, mixed> $row     Stored apply row.
	 * @return bool
	 */
	private function moved_since_preview( \WC_Product $product, array $row ): bool {
		$fields = isset( $row['fields'] ) && is_array( $row['fields'] ) ? $row['fields'] : array();
		$old    = isset( $row['old'] ) && is_array( $row['old'] ) ? $row['old'] : array();
		foreach ( $fields as $field => $value ) {
			$field = (string) $field;
			if ( $this->products->field_is_same( $product, $field, $old[ $field ] ?? '' ) ) {
				continue;
			}
			if ( $this->products->field_is_same( $product, $field, $value ) ) {
				continue;
			}
			return true;
		}

		return false;
	}

	/**
	 * Map lowercase SKUs to product IDs.
	 *
	 * @param array<int, string> $skus SKUs from the file.
	 * @return array<string, array<int, int>>
	 */
	private function ids_for_skus( array $skus ): array {
		global $wpdb;

		$skus = array_values( array_unique( array_map( 'strtolower', array_filter( $skus ) ) ) );
		$map  = array();
		if ( array() === $skus ) {
			return $map;
		}

		$table = $wpdb->prefix . 'wc_product_meta_lookup';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Existence check for the WooCommerce lookup table.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		foreach ( array_chunk( $skus, 500 ) as $chunk ) {
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );
			if ( is_string( $exists ) && '' !== $exists ) {
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SKU lookup in chunks of 500. The table name is the current site prefix.
				$sql  = "SELECT product_id, sku FROM {$table} WHERE LOWER(sku) IN ( {$placeholders} )";
				$rows = $wpdb->get_results( $wpdb->prepare( $sql, $chunk ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholders are built above for this chunk.
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$id_key  = 'product_id';
				$sku_key = 'sku';
			} else {
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fallback when the lookup table is missing.
				$sql  = "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND LOWER(meta_value) IN ( {$placeholders} )";
				$rows = $wpdb->get_results( $wpdb->prepare( $sql, $chunk ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholders are built above for this chunk.
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$id_key  = 'post_id';
				$sku_key = 'meta_value';
			}
			if ( ! is_array( $rows ) ) {
				continue;
			}
			foreach ( $rows as $row ) {
				$sku = strtolower( trim( (string) ( $row[ $sku_key ] ?? '' ) ) );
				$id  = (int) ( $row[ $id_key ] ?? 0 );
				if ( '' === $sku || $id < 1 ) {
					continue;
				}
				$map[ $sku ][] = $id;
			}
		}

		return $map;
	}

	/**
	 * Canonical headers and unknown-column notices.
	 *
	 * @param array<int, string|null> $header Header cells.
	 * @return array{columns: array<int, string>, notices: array<int, string>}|\WP_Error
	 */
	private function map_headers( array $header ) {
		$columns = array();
		$known   = array();
		$notices = array();
		$aliases = array(
			'sku'                => 'sku',
			'type'               => 'type',
			'parent_sku'         => 'parent_sku',
			'name'               => 'name',
			'variation_label'    => 'variation_label',
			'sold_by'            => 'sold_by',
			'regular_price'      => 'regular_price',
			'sale_price'         => 'sale_price',
			'manage_stock'       => 'manage_stock',
			'stock_quantity'     => 'stock_quantity',
			'stock_status'       => 'stock_status',
			'weight'             => 'weight',
			'status'             => 'status',
			'catalog_visibility' => 'catalog_visibility',
			'description'        => 'description',
			'short_description'  => 'short_description',
			'modified'           => 'modified',
			'id'                 => 'id',
		);

		foreach ( $header as $index => $cell ) {
			$label = is_string( $cell ) ? $cell : '';
			$label = preg_replace( '/^\xEF\xBB\xBF/', '', $label );
			$key   = strtolower( trim( (string) $label ) );
			$key   = str_replace( array( ' ', '-' ), '_', $key );
			if ( '' === $key ) {
				$columns[ $index ] = '';
				continue;
			}
			if ( ! isset( $aliases[ $key ] ) ) {
				$columns[ $index ] = '';
				$notices[]         = sprintf(
					/* translators: %s: column title from the file. */
					__( 'Column "%s" was ignored.', 'low-product-table-editor' ),
					trim( (string) $label )
				);
				continue;
			}
			$canonical = $aliases[ $key ];
			if ( isset( $known[ $canonical ] ) ) {
				return new \WP_Error(
					'low_pte_import_header',
					sprintf(
						/* translators: %s: column name. */
						__( 'The column %s is repeated.', 'low-product-table-editor' ),
						$canonical
					),
					array( 'status' => 400 )
				);
			}
			$known[ $canonical ] = true;
			$columns[ $index ]   = $canonical;
		}

		if ( ! isset( $known['sku'] ) ) {
			return new \WP_Error( 'low_pte_import_sku', __( 'The file needs a sku column.', 'low-product-table-editor' ), array( 'status' => 400 ) );
		}

		return array(
			'columns' => $columns,
			'notices' => $notices,
		);
	}

	/**
	 * Store the apply list for this user, replacing any earlier preview.
	 *
	 * @param array<int, array<string, mixed>> $rows Apply rows.
	 * @return string|\WP_Error Token.
	 */
	private function store_preview( array $rows ) {
		$user_id = get_current_user_id();
		$token   = bin2hex( random_bytes( 16 ) );
		$pointer = 'low_pte_import_user_' . $user_id;
		$old     = get_transient( $pointer );
		if ( is_string( $old ) && '' !== $old ) {
			delete_transient( $this->preview_key( $user_id, $old ) );
		}

		$payload = array(
			'user_id'  => $user_id,
			'batch_id' => '',
			'rows'     => $rows,
		);
		$saved   = $this->write_preview( $token, $payload );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		set_transient( $pointer, $token, HOUR_IN_SECONDS );

		return $token;
	}

	/**
	 * Persist a preview payload.
	 *
	 * @param string               $token   Token.
	 * @param array<string, mixed> $payload Payload.
	 * @return true|\WP_Error
	 */
	private function write_preview( string $token, array $payload ) {
		$json = wp_json_encode( $payload );
		if ( ! is_string( $json ) ) {
			return new \WP_Error( 'low_pte_import_store', __( 'The preview could not be stored.', 'low-product-table-editor' ), array( 'status' => 500 ) );
		}
		$packed = gzencode( $json, 6 );
		if ( ! is_string( $packed ) ) {
			return new \WP_Error( 'low_pte_import_store', __( 'The preview could not be stored.', 'low-product-table-editor' ), array( 'status' => 500 ) );
		}
		if ( strlen( $packed ) > 900000 ) {
			return new \WP_Error( 'low_pte_import_store', __( 'This import is too large to preview. Split the file.', 'low-product-table-editor' ), array( 'status' => 400 ) );
		}
		$key = $this->preview_key( get_current_user_id(), $token );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Transient storage for gzip binary, not obfuscation.
		set_transient( $key, base64_encode( $packed ), HOUR_IN_SECONDS );

		return true;
	}

	/**
	 * Load a preview owned by the current user.
	 *
	 * @param string $token Token.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function load_preview( string $token ) {
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			return new \WP_Error( 'low_pte_import_token', __( 'The preview expired. Upload the file again.', 'low-product-table-editor' ), array( 'status' => 400 ) );
		}
		$stored = get_transient( $this->preview_key( get_current_user_id(), $token ) );
		if ( ! is_string( $stored ) || '' === $stored ) {
			return new \WP_Error( 'low_pte_import_token', __( 'The preview expired. Upload the file again.', 'low-product-table-editor' ), array( 'status' => 400 ) );
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Reverses the gzip binary stored in the transient.
		$json = gzdecode( base64_decode( $stored ) );
		$data = is_string( $json ) ? json_decode( $json, true ) : null;
		if ( ! is_array( $data ) || (int) ( $data['user_id'] ?? 0 ) !== get_current_user_id() ) {
			return new \WP_Error( 'low_pte_import_token', __( 'The preview expired. Upload the file again.', 'low-product-table-editor' ), array( 'status' => 400 ) );
		}

		return $data;
	}

	/**
	 * Delete the current user's preview.
	 *
	 * @param string $token Token.
	 * @return void
	 */
	private function delete_preview( string $token ): void {
		$user_id = get_current_user_id();
		delete_transient( $this->preview_key( $user_id, $token ) );
		$pointer = get_transient( 'low_pte_import_user_' . $user_id );
		if ( $pointer === $token ) {
			delete_transient( 'low_pte_import_user_' . $user_id );
		}
	}

	/**
	 * Transient name for one preview.
	 *
	 * @param int    $user_id User ID.
	 * @param string $token   Token.
	 * @return string
	 */
	private function preview_key( int $user_id, string $token ): string {
		return 'low_pte_import_' . $user_id . '_' . $token;
	}

	/**
	 * One preview or apply result row.
	 *
	 * @param int    $line    File line.
	 * @param string $sku     SKU.
	 * @param string $name    Product name.
	 * @param string $field   Field name.
	 * @param string $old     Old value.
	 * @param string $new_value New value.
	 * @param string $status  Status.
	 * @param string $message Message.
	 * @return array<string, mixed>
	 */
	private function detail( int $line, string $sku, string $name, string $field, string $old, string $new_value, string $status, string $message ): array {
		return array(
			'line'    => $line,
			'sku'     => $sku,
			'name'    => $name,
			'field'   => $field,
			'old'     => $old,
			'new'     => $new_value,
			'status'  => $status,
			'message' => $message,
		);
	}

	/**
	 * One apply result.
	 *
	 * @param array<string, mixed> $row     Apply row.
	 * @param string               $status  Status.
	 * @param string               $message Message.
	 * @return array<string, mixed>
	 */
	private function apply_result( array $row, string $status, string $message ): array {
		return array(
			'line'    => (int) ( $row['line'] ?? 0 ),
			'sku'     => (string) ( $row['sku'] ?? '' ),
			'id'      => (int) ( $row['id'] ?? 0 ),
			'name'    => (string) ( $row['name'] ?? '' ),
			'status'  => $status,
			'message' => $message,
		);
	}

	/**
	 * Decode Excel Unicode text and strip a UTF-8 BOM.
	 *
	 * @param string $raw File bytes.
	 * @return string|\WP_Error
	 */
	private function to_utf8( string $raw ) {
		if ( str_starts_with( $raw, "\xEF\xBB\xBF" ) ) {
			$raw = substr( $raw, 3 );
		} elseif ( str_starts_with( $raw, "\xFF\xFE" ) ) {
			$raw = mb_convert_encoding( substr( $raw, 2 ), 'UTF-8', 'UTF-16LE' );
		} elseif ( str_starts_with( $raw, "\xFE\xFF" ) ) {
			$raw = mb_convert_encoding( substr( $raw, 2 ), 'UTF-8', 'UTF-16BE' );
		} elseif ( str_contains( $raw, "\0" ) ) {
			$raw = mb_convert_encoding( $raw, 'UTF-8', 'UTF-16LE' );
		}

		if ( ! is_string( $raw ) || ! mb_check_encoding( $raw, 'UTF-8' ) || str_contains( $raw, "\0" ) ) {
			return new \WP_Error( 'low_pte_import_encoding', __( 'The file is not UTF-8 text. In Excel, use Save As and choose CSV UTF-8.', 'low-product-table-editor' ), array( 'status' => 400 ) );
		}

		return $raw;
	}

	/**
	 * Pick a delimiter from the first line.
	 *
	 * @param string $text CSV text.
	 * @return string
	 */
	private function delimiter( string $text ): string {
		$line   = strtok( $text, "\r\n" );
		$line   = is_string( $line ) ? $line : '';
		$counts = array(
			','  => substr_count( $line, ',' ),
			';'  => substr_count( $line, ';' ),
			"\t" => substr_count( $line, "\t" ),
		);
		arsort( $counts );
		$best = (int) reset( $counts );
		if ( $best < 1 ) {
			return ',';
		}
		$key = array_key_first( $counts );

		return is_string( $key ) ? $key : ',';
	}

	/**
	 * MIME type from the file contents.
	 *
	 * @param string $path File path.
	 * @return string
	 */
	private function mime_type( string $path ): string {
		if ( class_exists( '\finfo' ) ) {
			$info = new \finfo( FILEINFO_MIME_TYPE );
			$type = $info->file( $path );
			return is_string( $type ) ? $type : '';
		}

		return '';
	}

	/**
	 * Trim SKU whitespace, including non-breaking spaces.
	 *
	 * @param string $sku Raw SKU.
	 * @return string
	 */
	private function clean_sku( string $sku ): string {
		$sku = str_replace( "\xC2\xA0", ' ', $sku );

		return trim( $sku );
	}

	/**
	 * Whether Excel likely turned a SKU into scientific notation.
	 *
	 * @param string $sku SKU.
	 * @return bool
	 */
	private function sku_looks_scientific( string $sku ): bool {
		return 1 === preg_match( '/^[0-9]+(\.[0-9]+)?[eE][+\-]?[0-9]+$/', $sku );
	}

	/**
	 * Prefix a quote so a spreadsheet does not treat text as a formula.
	 *
	 * @param string $value Cell text.
	 * @return string
	 */
	private function protect_spreadsheet( string $value ): string {
		if ( preg_match( '/^[=+\-@\t\r]/', $value ) ) {
			return "'" . $value;
		}

		return $value;
	}

	/**
	 * Remove the export quote only when it guards a formula character.
	 *
	 * @param string $value Cell text.
	 * @return string
	 */
	private function unprotect_spreadsheet( string $value ): string {
		if ( strlen( $value ) > 1 && str_starts_with( $value, "'" ) && preg_match( '/^[=+\-@\t\r]/', substr( $value, 1 ) ) ) {
			return substr( $value, 1 );
		}

		return $value;
	}

	/**
	 * Text for a preview cell.
	 *
	 * @param mixed $value Stored value.
	 * @return string
	 */
	private function display_value( mixed $value ): string {
		if ( null === $value ) {
			return '';
		}

		return is_scalar( $value ) ? (string) $value : '';
	}
}
