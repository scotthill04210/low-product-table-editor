<?php
/**
 * Bulk price preview and apply.
 *
 * @package LOW\ProductTableEditor
 */

declare( strict_types=1 );

namespace LOW\ProductTableEditor;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves a selection, previews price changes, and applies them in chunks.
 *
 * @since 0.1.1
 */
class Bulk_Service {

	private const SCALE = 10000;

	/**
	 * Product reads and price setters.
	 *
	 * @var Product_Service
	 */
	private Product_Service $products;

	/**
	 * Parent sync calls made by the latest apply chunk.
	 *
	 * @var int
	 */
	private int $sync_count = 0;

	/**
	 * Bind the product service.
	 *
	 * @since 0.1.1
	 *
	 * @param Product_Service $products Product service.
	 */
	public function __construct( Product_Service $products ) {
		$this->products = $products;
	}

	/**
	 * Parent sync calls in the latest apply chunk.
	 *
	 * @since 0.1.1
	 *
	 * @return int
	 */
	public function sync_count(): int {
		return $this->sync_count;
	}

	/**
	 * Priced targets for a selection, plus rows the user cannot edit.
	 *
	 * Variable parents expand to their variations. Parents themselves are not targets.
	 *
	 * @since 0.1.1
	 *
	 * @param array<string, mixed> $selection mode ids or filter.
	 * @return array{targets: array<int, array<string, mixed>>, skipped: array<int, array<string, mixed>>}
	 */
	public function resolve_selection( array $selection ): array {
		$mode    = isset( $selection['mode'] ) ? (string) $selection['mode'] : '';
		$skipped = array();
		$ordered = array();

		if ( 'filter' === $mode ) {
			$filters = isset( $selection['filters'] ) && is_array( $selection['filters'] ) ? $selection['filters'] : array();
			$parents = $this->products->parent_ids_for_filters( $filters );
			$ordered = $this->expand_parents( $parents );
		} elseif ( 'ids' === $mode ) {
			$ids     = isset( $selection['ids'] ) && is_array( $selection['ids'] ) ? $selection['ids'] : array();
			$grouped = $this->classify_ids( $ids );
			$skipped = $grouped['skipped'];
			$ordered = $this->expand_parents( $grouped['parents'] );
			foreach ( $grouped['variations'] as $variation_id => $parent_id ) {
				$ordered[] = array(
					'id'        => (int) $variation_id,
					'parent_id' => (int) $parent_id,
				);
			}
		}

		$seen    = array();
		$targets = array();
		foreach ( $ordered as $row ) {
			$id = (int) $row['id'];
			if ( $id < 1 || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			if ( ! current_user_can( 'edit_post', $id ) ) {
				$skipped[] = array(
					'id'        => $id,
					'parent_id' => (int) $row['parent_id'],
					'reason'    => 'cannot_edit',
				);
				continue;
			}
			$targets[] = array(
				'id'        => $id,
				'parent_id' => (int) $row['parent_id'],
			);
		}

		return array(
			'targets' => $targets,
			'skipped' => $skipped,
		);
	}

	/**
	 * New price for one stored price string.
	 *
	 * Integer math uses ten-thousandths of a currency unit. Percent results
	 * are half-up rounded to 4 decimals before the selected rounding step.
	 *
	 * @since 0.1.1
	 *
	 * @param string               $old    Stored price, or an empty string.
	 * @param array<string, mixed> $action Validated action with value_units.
	 * @return array{new: string, status: string, reason: string}
	 */
	public function calculate( string $old, array $action ): array {
		$operation = isset( $action['operation'] ) ? (string) $action['operation'] : '';
		$value     = isset( $action['value_units'] ) ? (int) $action['value_units'] : 0;
		$rounding  = isset( $action['rounding'] ) ? (string) $action['rounding'] : 'none';
		$old       = trim( $old );

		if ( '' === $old && 'set_value' !== $operation ) {
			return $this->calc_row( '', 'skipped', 'price_empty' );
		}

		$old_units = '' === $old ? 0 : $this->to_units( $old );
		if ( '' !== $old && null === $old_units ) {
			return $this->calc_row( $old, 'error', 'invalid_price' );
		}

		$next = $this->apply_operation( (int) $old_units, $value, $operation );
		if ( null === $next ) {
			return $this->calc_row( '', 'error', 'below_zero' );
		}

		$next = $this->round_units( $next, $rounding );
		if ( $next < 0 ) {
			return $this->calc_row( $this->format_units( $next ), 'error', 'below_zero' );
		}

		$formatted = $this->format_units( $next );
		if ( '' !== $old && $next === $old_units ) {
			return $this->calc_row( $formatted, 'unchanged', '' );
		}

		return $this->calc_row( $formatted, 'changed', '' );
	}

	/**
	 * Preview every targeted item. Nothing is saved.
	 *
	 * @since 0.1.1
	 *
	 * @param array<string, mixed> $selection Selection payload.
	 * @param array<string, mixed> $action    Action payload.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function preview( array $selection, array $action ): array|\WP_Error {
		$parsed = $this->parse_action( $action );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$resolved = $this->resolve_selection( $selection );
		$displays = $this->displays_for( $resolved['targets'] );
		$summary  = array(
			'total'     => 0,
			'changed'   => 0,
			'unchanged' => 0,
			'skipped'   => 0,
			'errors'    => 0,
		);
		$rows     = array();
		$item_ids = array();
		$shown    = 0;
		$fields   = array();

		foreach ( $resolved['skipped'] as $skipped ) {
			++$summary['total'];
			++$summary['skipped'];
			if ( $shown < 500 ) {
				$rows[] = $this->field_row(
					(int) $skipped['id'],
					(int) ( $skipped['parent_id'] ?? 0 ),
					'',
					'',
					'',
					'',
					'',
					null,
					'skipped',
					(string) $skipped['reason']
				);
				++$shown;
			}
		}

		foreach ( $resolved['targets'] as $target ) {
			$id          = (int) $target['id'];
			$display     = $displays[ $id ] ?? array(
				'parent_id'     => (int) $target['parent_id'],
				'name'          => '',
				'sold_by'       => '',
				'regular_price' => '',
				'sale_price'    => '',
			);
			$evaluated   = $this->evaluate_item( $display, $parsed );
			$item_status = (string) $evaluated['status'];
			++$summary['total'];
			if ( 'error' === $item_status ) {
				++$summary['errors'];
			} elseif ( isset( $summary[ $item_status ] ) ) {
				++$summary[ $item_status ];
			}
			$item_ids[] = $id;
			$this->tally_fields( $fields, $evaluated['fields'] );

			if ( $shown >= 500 ) {
				continue;
			}

			foreach ( $evaluated['fields'] as $field ) {
				$rows[] = $this->field_row(
					$id,
					(int) $display['parent_id'],
					(string) $display['name'],
					(string) $display['sold_by'],
					(string) $field['field'],
					(string) $field['old'],
					(string) $field['new'],
					$field['change'],
					(string) $field['status'],
					(string) $field['reason']
				);
			}
			++$shown;
		}

		$summary['fields'] = $this->field_count_list( $fields );

		return array(
			'summary'   => $summary,
			'item_ids'  => $item_ids,
			'rows'      => $rows,
			'truncated' => count( $resolved['targets'] ) + count( $resolved['skipped'] ) > 500,
		);
	}

	/**
	 * Apply one chunk. Prices are recomputed from the current stored values.
	 *
	 * @since 0.1.1
	 *
	 * @param string               $batch_id Batch UUID.
	 * @param array<int, int>      $ids      At most 25 priced item IDs.
	 * @param array<string, mixed> $action   Action payload.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function apply( string $batch_id, array $ids, array $action ): array|\WP_Error {
		$this->sync_count = 0;
		$parsed           = $this->parse_action( $action );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( 25 < count( $ids ) ) {
			return new \WP_Error(
				'low_pte_too_many_items',
				__( 'A bulk request can include at most 25 items.', 'low-product-table-editor' ),
				array( 'status' => 400 )
			);
		}

		$resolved = $this->resolve_selection(
			array(
				'mode' => 'ids',
				'ids'  => $ids,
			)
		);
		$displays = $this->displays_for( $resolved['targets'] );
		$results  = array();
		$entries  = array();
		$parents  = array();
		$counts   = array(
			'applied'   => 0,
			'unchanged' => 0,
			'skipped'   => 0,
			'errors'    => 0,
		);
		$fields   = array();

		foreach ( $resolved['skipped'] as $skipped ) {
			++$counts['skipped'];
			$results[] = array(
				'id'      => (int) $skipped['id'],
				'status'  => 'skipped',
				'reason'  => (string) $skipped['reason'],
				'message' => $this->reason_message( (string) $skipped['reason'] ),
			);
		}

		foreach ( $resolved['targets'] as $target ) {
			$id      = (int) $target['id'];
			$display = $displays[ $id ] ?? array(
				'parent_id'     => (int) $target['parent_id'],
				'regular_price' => '',
				'sale_price'    => '',
			);
			$written = $this->write_item( $id, (int) $display['parent_id'], $parsed );
			$this->tally_fields( $fields, $written['fields'] );

			if ( 'changed' === $written['status'] ) {
				++$counts['applied'];
				foreach ( $written['entries'] as $entry ) {
					$entries[] = $entry;
				}
				$parent_id = (int) $display['parent_id'];
				if ( $parent_id > 0 ) {
					$parents[ $parent_id ] = true;
				}
			} elseif ( 'unchanged' === $written['status'] ) {
				++$counts['unchanged'];
			} elseif ( 'skipped' === $written['status'] ) {
				++$counts['skipped'];
			} else {
				++$counts['errors'];
			}

			$results[] = array(
				'id'      => $id,
				'status'  => $written['status'],
				'reason'  => $written['reason'],
				'message' => $written['message'],
			);
		}

		$this->sync_parents( array_keys( $parents ) );

		$log_failed = false;
		if ( array() !== $entries ) {
			$log_failed = ! $this->products->log_changes( $batch_id, 'bulk', $entries );
		}

		return array(
			'batch_id'     => $batch_id,
			'results'      => $results,
			'applied'      => $counts['applied'],
			'unchanged'    => $counts['unchanged'],
			'skipped'      => $counts['skipped'],
			'errors'       => $counts['errors'],
			'log_failed'   => $log_failed,
			'sync_count'   => $this->sync_count,
			'field_counts' => $this->field_count_list( $fields ),
		);
	}

	/**
	 * Validate an action and convert its value to integer units.
	 *
	 * @param array<string, mixed> $action Raw action.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function parse_action( array $action ): array|\WP_Error {
		$operation = isset( $action['operation'] ) ? (string) $action['operation'] : '';
		$target    = isset( $action['target'] ) ? (string) $action['target'] : '';
		$rounding  = isset( $action['rounding'] ) ? (string) $action['rounding'] : 'none';
		$value     = isset( $action['value'] ) && is_scalar( $action['value'] ) ? trim( (string) $action['value'] ) : '';

		$operations = array( 'increase_percent', 'decrease_percent', 'increase_amount', 'decrease_amount', 'set_value' );
		if ( ! in_array( $operation, $operations, true ) || ! in_array( $target, array( 'regular', 'sale', 'both' ), true ) ) {
			return new \WP_Error(
				'rest_invalid_param',
				__( 'The bulk action is not valid.', 'low-product-table-editor' ),
				array( 'status' => 400 )
			);
		}

		if ( ! in_array( $rounding, array( 'none', 'cent', '0.05', '0.10' ), true ) ) {
			return new \WP_Error(
				'rest_invalid_param',
				__( 'The rounding option is not valid.', 'low-product-table-editor' ),
				array( 'status' => 400 )
			);
		}

		if ( ! preg_match( '/^\d+(\.\d{1,4})?$/', $value ) ) {
			return new \WP_Error(
				'rest_invalid_param',
				__( 'Enter a price or percent with at most 4 decimal places.', 'low-product-table-editor' ),
				array( 'status' => 400 )
			);
		}

		$units = $this->to_units( $value );
		if ( null === $units ) {
			return new \WP_Error(
				'rest_invalid_param',
				__( 'Enter a price or percent with at most 4 decimal places.', 'low-product-table-editor' ),
				array( 'status' => 400 )
			);
		}

		return array(
			'operation'   => $operation,
			'target'      => $target,
			'rounding'    => $rounding,
			'value'       => $value,
			'value_units' => $units,
		);
	}

	/**
	 * Evaluate regular and sale together for one item.
	 *
	 * @param array<string, mixed> $display Display row with stored prices.
	 * @param array<string, mixed> $action  Parsed action.
	 * @return array{status: string, reason: string, fields: array<int, array<string, mixed>>}
	 */
	private function evaluate_item( array $display, array $action ): array {
		$targets = 'both' === $action['target'] ? array( 'regular', 'sale' ) : array( (string) $action['target'] );
		$fields  = array();

		foreach ( $targets as $which ) {
			$key              = 'sale' === $which ? 'sale_price' : 'regular_price';
			$calc             = $this->calculate( (string) ( $display[ $key ] ?? '' ), $action );
			$fields[ $which ] = array(
				'field'  => $key,
				'old'    => (string) ( $display[ $key ] ?? '' ),
				'new'    => $calc['new'],
				'status' => $calc['status'],
				'reason' => $calc['reason'],
			);
		}

		if ( isset( $fields['regular'] ) && in_array( $fields['regular']['status'], array( 'changed', 'unchanged' ), true ) ) {
			if ( '' === $fields['regular']['new'] || 0 === $this->to_units( (string) $fields['regular']['new'] ) ) {
				$fields['regular']['status'] = 'error';
				$fields['regular']['reason'] = 'regular_zero';
			}
		}

		$regular_text = isset( $fields['regular'] ) && in_array( $fields['regular']['status'], array( 'changed', 'unchanged' ), true )
			? (string) $fields['regular']['new']
			: (string) ( $display['regular_price'] ?? '' );

		if ( isset( $fields['sale'] ) && in_array( $fields['sale']['status'], array( 'changed', 'unchanged' ), true ) ) {
			$sale_units    = $this->to_units( (string) $fields['sale']['new'] );
			$regular_units = '' === $regular_text ? null : $this->to_units( $regular_text );
			if ( null === $sale_units || null === $regular_units || $sale_units >= $regular_units ) {
				$fields['sale']['status'] = 'error';
				$fields['sale']['reason'] = 'sale_not_lower';
			}
		}

		$errored = false;
		foreach ( $fields as $field ) {
			if ( 'error' === $field['status'] ) {
				$errored = true;
				break;
			}
		}

		if ( $errored ) {
			foreach ( $fields as $which => $field ) {
				if ( 'error' !== $field['status'] ) {
					$fields[ $which ]['status'] = 'error';
					$fields[ $which ]['reason'] = 'item_blocked';
				}
			}
		}

		foreach ( $fields as $which => $field ) {
			$fields[ $which ]['change'] = $this->signed_change( (string) $field['old'], (string) $field['new'] );
		}

		return array(
			'status' => $this->rollup( $fields ),
			'reason' => $this->item_reason( $fields ),
			'fields' => array_values( $fields ),
		);
	}

	/**
	 * Save the changed prices for one item.
	 *
	 * @param int                  $id        Product or variation ID.
	 * @param int                  $parent_id Parent ID, or 0.
	 * @param array<string, mixed> $action Parsed action.
	 * @return array{status: string, reason: string, message: string, entries: array<int, array<string, mixed>>, fields: array<int, array<string, mixed>>}
	 */
	private function write_item( int $id, int $parent_id, array $action ): array {
		$product = wc_get_product( $id );
		if ( ! $product instanceof \WC_Product ) {
			return array(
				'status'  => 'error',
				'reason'  => 'not_found',
				'message' => $this->reason_message( 'not_found' ),
				'entries' => array(),
				'fields'  => array(),
			);
		}

		$fresh   = $this->products->bulk_display( $product );
		$checked = $this->evaluate_item( $fresh, $action );
		if ( 'changed' !== $checked['status'] ) {
			return array(
				'status'  => (string) $checked['status'],
				'reason'  => (string) $checked['reason'],
				'message' => $this->reason_message( (string) $checked['reason'] ),
				'entries' => array(),
				'fields'  => $checked['fields'],
			);
		}

		$before = array();
		try {
			foreach ( $checked['fields'] as $field ) {
				if ( 'changed' !== $field['status'] ) {
					continue;
				}
				$which            = 'sale_price' === $field['field'] ? 'sale' : 'regular';
				$before[ $which ] = (string) $field['old'];
				$this->products->assign_price( $product, $which, (string) $field['new'] );
			}
			$this->products->save_product( $product );
		} catch ( \Throwable $exception ) {
			return array(
				'status'  => 'error',
				'reason'  => 'save_failed',
				'message' => __( 'The changes could not be saved.', 'low-product-table-editor' ),
				'entries' => array(),
				'fields'  => array(),
			);
		}

		$saved   = wc_get_product( $id );
		$after   = $saved instanceof \WC_Product ? $this->products->bulk_display( $saved ) : $fresh;
		$entries = array();
		foreach ( $before as $which => $old_value ) {
			$key       = 'sale' === $which ? 'sale_price' : 'regular_price';
			$new_value = (string) ( $after[ $key ] ?? '' );
			if ( $old_value === $new_value ) {
				continue;
			}
			$entries[] = array(
				'product_id' => $id,
				'parent_id'  => $parent_id,
				'field'      => $key,
				'old'        => '' === $old_value ? null : $old_value,
				'new'        => '' === $new_value ? null : $new_value,
			);
		}

		if ( array() === $entries ) {
			return array(
				'status'  => 'unchanged',
				'reason'  => '',
				'message' => '',
				'entries' => array(),
				'fields'  => $checked['fields'],
			);
		}

		return array(
			'status'  => 'changed',
			'reason'  => '',
			'message' => '',
			'entries' => $entries,
			'fields'  => $checked['fields'],
		);
	}

	/**
	 * Sync each affected parent once.
	 *
	 * @param array<int, int> $parent_ids Parent IDs.
	 * @return void
	 */
	private function sync_parents( array $parent_ids ): void {
		$parent_ids = array_values( array_filter( array_map( 'absint', $parent_ids ) ) );
		$orders     = $this->products->menu_orders_for_parents( $parent_ids );
		foreach ( $parent_ids as $parent_id ) {
			if ( $parent_id < 1 || ! class_exists( '\WC_Product_Variable' ) ) {
				continue;
			}
			++$this->sync_count;
			\WC_Product_Variable::sync( $parent_id );
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $parent_id );
			}
		}
		$this->products->restore_menu_orders( $orders );
	}

	/**
	 * Load display rows for the targets with caches primed first.
	 *
	 * @param array<int, array<string, mixed>> $targets Target rows.
	 * @return array<int, array<string, mixed>>
	 */
	private function displays_for( array $targets ): array {
		$ids = array();
		foreach ( $targets as $target ) {
			$ids[] = (int) $target['id'];
			if ( (int) $target['parent_id'] > 0 ) {
				$ids[] = (int) $target['parent_id'];
			}
		}
		$this->products->prime_bulk_caches( $ids );

		$products = array();
		foreach ( $targets as $target ) {
			$product = wc_get_product( (int) $target['id'] );
			if ( $product instanceof \WC_Product ) {
				$products[] = $product;
			}
		}
		$this->products->prime_bulk_attributes( $products );

		$displays = array();
		foreach ( $products as $product ) {
			$row                            = $this->products->bulk_display( $product );
			$displays[ $product->get_id() ] = $row;
		}

		return $displays;
	}

	/**
	 * Turn parent IDs into simple products and variations.
	 *
	 * @param array<int, int> $parent_ids Parent IDs in display order.
	 * @return array<int, array{id: int, parent_id: int}>
	 */
	private function expand_parents( array $parent_ids ): array {
		$parent_ids = array_values( array_unique( array_filter( array_map( 'absint', $parent_ids ) ) ) );
		if ( array() === $parent_ids ) {
			return array();
		}

		$this->products->prime_bulk_caches( $parent_ids );
		$variable = array();
		$ordered  = array();

		foreach ( $parent_ids as $parent_id ) {
			$product = wc_get_product( $parent_id );
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			if ( $product->is_type( 'variable' ) ) {
				$variable[] = $parent_id;
				continue;
			}
			if ( $product->is_type( 'simple' ) ) {
				$ordered[] = array(
					'id'        => $parent_id,
					'parent_id' => 0,
				);
			}
		}

		$variation_ids = $this->products->variation_ids_for_parent_products( $variable );
		$this->products->prime_bulk_caches( $variation_ids );
		foreach ( $variation_ids as $variation_id ) {
			$product = wc_get_product( $variation_id );
			if ( ! $product instanceof \WC_Product || ! $product->is_type( 'variation' ) ) {
				continue;
			}
			$ordered[] = array(
				'id'        => $variation_id,
				'parent_id' => $product->get_parent_id(),
			);
		}

		return $ordered;
	}

	/**
	 * Split a list of IDs into parents, variations, and skipped rows.
	 *
	 * @param array<int, mixed> $ids Requested IDs.
	 * @return array{parents: array<int, int>, variations: array<int, int>, skipped: array<int, array<string, mixed>>}
	 */
	private function classify_ids( array $ids ): array {
		global $wpdb;

		$ids   = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		$empty = array(
			'parents'    => array(),
			'variations' => array(),
			'skipped'    => array(),
		);
		if ( array() === $ids ) {
			return $empty;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One post lookup for the submitted ID list.
		$posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_type, post_parent FROM {$wpdb->posts} WHERE ID IN ( {$placeholders} )",
				$ids
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$by_id = array();
		if ( is_array( $posts ) ) {
			foreach ( $posts as $post ) {
				$by_id[ (int) $post['ID'] ] = $post;
			}
		}

		$product_ids = array();
		foreach ( $ids as $id ) {
			if ( ! isset( $by_id[ $id ] ) ) {
				$empty['skipped'][] = array(
					'id'        => $id,
					'parent_id' => 0,
					'reason'    => 'not_found',
				);
				continue;
			}
			if ( 'product' === $by_id[ $id ]['post_type'] ) {
				$product_ids[] = $id;
			}
		}

		$this->products->prime_bulk_caches( $product_ids );

		foreach ( $ids as $id ) {
			if ( ! isset( $by_id[ $id ] ) ) {
				continue;
			}
			$type = (string) $by_id[ $id ]['post_type'];
			if ( 'product_variation' === $type ) {
				$empty['variations'][ $id ] = (int) $by_id[ $id ]['post_parent'];
				continue;
			}
			if ( 'product' !== $type ) {
				$empty['skipped'][] = array(
					'id'        => $id,
					'parent_id' => 0,
					'reason'    => 'unsupported',
				);
				continue;
			}
			$product = wc_get_product( $id );
			if ( ! $product instanceof \WC_Product ) {
				$empty['skipped'][] = array(
					'id'        => $id,
					'parent_id' => 0,
					'reason'    => 'not_found',
				);
				continue;
			}
			if ( $product->is_type( 'simple' ) || $product->is_type( 'variable' ) ) {
				$empty['parents'][] = $id;
				continue;
			}
			$empty['skipped'][] = array(
				'id'        => $id,
				'parent_id' => 0,
				'reason'    => 'unsupported',
			);
		}

		return $empty;
	}

	/**
	 * Apply one operation in integer units.
	 *
	 * @param int    $old       Old price in ten-thousandths.
	 * @param int    $value     Action value in ten-thousandths.
	 * @param string $operation Operation name.
	 * @return int|null Null when the result is below zero.
	 */
	private function apply_operation( int $old, int $value, string $operation ): ?int {
		$percent = 100 * self::SCALE;

		switch ( $operation ) {
			case 'increase_percent':
				return $this->div_half_up( $old * ( $percent + $value ), $percent );
			case 'decrease_percent':
				if ( $value > $percent ) {
					return null;
				}
				return $this->div_half_up( $old * ( $percent - $value ), $percent );
			case 'increase_amount':
				return $old + $value;
			case 'decrease_amount':
				$result = $old - $value;
				return $result < 0 ? null : $result;
			case 'set_value':
				return $value;
			default:
				return null;
		}
	}

	/**
	 * Half-up integer division.
	 *
	 * @param int $numerator   Numerator.
	 * @param int $denominator Positive denominator.
	 * @return int
	 */
	private function div_half_up( int $numerator, int $denominator ): int {
		if ( $denominator < 1 ) {
			return 0;
		}

		$half = intdiv( $denominator, 2 );
		if ( $numerator >= 0 ) {
			return intdiv( $numerator + $half, $denominator );
		}

		return -intdiv( ( -$numerator ) + $half, $denominator );
	}

	/**
	 * Round ten-thousandths to the selected step.
	 *
	 * @param int    $units    Price units.
	 * @param string $rounding none, cent, 0.05, or 0.10.
	 * @return int
	 */
	private function round_units( int $units, string $rounding ): int {
		$steps = array(
			'none' => 1,
			'cent' => 100,
			'0.05' => 500,
			'0.10' => 1000,
		);
		$step  = $steps[ $rounding ] ?? 1;
		if ( $step <= 1 ) {
			return $units;
		}

		$negative = $units < 0;
		$units    = abs( $units );
		$rounded  = intdiv( $units + intdiv( $step, 2 ), $step ) * $step;

		return $negative ? -$rounded : $rounded;
	}

	/**
	 * Parse a non-negative decimal into ten-thousandths.
	 *
	 * @param string $decimal Decimal text.
	 * @return int|null
	 */
	private function to_units( string $decimal ): ?int {
		$decimal = trim( $decimal );
		if ( ! preg_match( '/^(\d+)(?:\.(\d+))?$/', $decimal, $matches ) ) {
			return null;
		}

		$fraction = $matches[2] ?? '';
		if ( strlen( $fraction ) > 4 ) {
			return null;
		}

		$fraction = str_pad( $fraction, 4, '0' );

		return ( (int) $matches[1] * self::SCALE ) + (int) $fraction;
	}

	/**
	 * Format ten-thousandths with trailing zeros removed.
	 *
	 * @param int $units Price units.
	 * @return string
	 */
	private function format_units( int $units ): string {
		$negative  = $units < 0;
		$units     = abs( $units );
		$whole     = intdiv( $units, self::SCALE );
		$fraction  = str_pad( (string) ( $units % self::SCALE ), 4, '0', STR_PAD_LEFT );
		$text      = $whole . '.' . $fraction;
		$formatted = (string) wc_format_decimal( $text, 4, true );

		if ( $negative && '0' !== $formatted ) {
			return '-' . $formatted;
		}

		return $formatted;
	}

	/**
	 * Signed difference between two price strings.
	 *
	 * @param string $old Old price.
	 * @param string $new_price New price.
	 * @return string|null
	 */
	private function signed_change( string $old, string $new_price ): ?string {
		$old_units = $this->to_units( $old );
		$new_units = $this->to_units( $new_price );
		if ( null === $old_units || null === $new_units ) {
			return null;
		}

		$diff = $new_units - $old_units;
		$text = $this->format_units( abs( $diff ) );
		if ( $diff > 0 ) {
			return '+' . $text;
		}
		if ( $diff < 0 ) {
			return '-' . $text;
		}

		return '0';
	}

	/**
	 * Roll field statuses up to one item status.
	 *
	 * @param array<string, array<string, mixed>> $fields Fields keyed by regular or sale.
	 * @return string
	 */
	private function rollup( array $fields ): string {
		$statuses = array();
		foreach ( $fields as $field ) {
			$statuses[] = (string) $field['status'];
		}

		if ( in_array( 'error', $statuses, true ) ) {
			return 'error';
		}
		if ( in_array( 'changed', $statuses, true ) ) {
			return 'changed';
		}
		if ( in_array( 'unchanged', $statuses, true ) ) {
			return 'unchanged';
		}

		return 'skipped';
	}

	/**
	 * Count field rows by field, status, and reason.
	 *
	 * @param array<string, array<string, mixed>> $tallies Running counts.
	 * @param array<int, array<string, mixed>>    $fields  Evaluated fields.
	 * @return void
	 */
	private function tally_fields( array &$tallies, array $fields ): void {
		foreach ( $fields as $field ) {
			$name   = (string) ( $field['field'] ?? '' );
			$status = (string) ( $field['status'] ?? '' );
			$reason = (string) ( $field['reason'] ?? '' );
			if ( '' === $name || '' === $status ) {
				continue;
			}
			$key = $name . '|' . $status . '|' . $reason;
			if ( ! isset( $tallies[ $key ] ) ) {
				$tallies[ $key ] = array(
					'field'  => $name,
					'status' => $status,
					'reason' => $reason,
					'count'  => 0,
				);
			}
			++$tallies[ $key ]['count'];
		}
	}

	/**
	 * Field counts in a stable order.
	 *
	 * @param array<string, array<string, mixed>> $tallies Running counts.
	 * @return array<int, array<string, mixed>>
	 */
	private function field_count_list( array $tallies ): array {
		$list = array_values( $tallies );
		usort(
			$list,
			static function ( array $left, array $right ): int {
				$rank = static function ( array $row ): string {
					$field  = 'sale_price' === $row['field'] ? '0' : '1';
					$status = 'skipped' === $row['status'] ? '0' : ( 'error' === $row['status'] ? '1' : '2' );
					return $field . $status . (string) $row['reason'];
				};
				return $rank( $left ) <=> $rank( $right );
			}
		);
		return $list;
	}

	/**
	 * First field reason that explains an item that did not change.
	 *
	 * @param array<string, array<string, mixed>> $fields Fields.
	 * @return string
	 */
	private function item_reason( array $fields ): string {
		foreach ( $fields as $field ) {
			if ( 'error' === $field['status'] && 'item_blocked' !== $field['reason'] && '' !== $field['reason'] ) {
				return (string) $field['reason'];
			}
		}
		foreach ( $fields as $field ) {
			if ( '' !== $field['reason'] ) {
				return (string) $field['reason'];
			}
		}

		return '';
	}

	/**
	 * Human text for a reason code.
	 *
	 * @param string $reason Reason code.
	 * @return string
	 */
	private function reason_message( string $reason ): string {
		$messages = array(
			'price_empty'    => __( 'Empty price.', 'low-product-table-editor' ),
			'below_zero'     => __( 'The price would be below zero.', 'low-product-table-editor' ),
			'regular_zero'   => __( 'The regular price would be zero.', 'low-product-table-editor' ),
			'sale_not_lower' => __( 'The sale price must be lower than the regular price.', 'low-product-table-editor' ),
			'cannot_edit'    => __( 'You cannot edit this product.', 'low-product-table-editor' ),
			'not_found'      => __( 'Product not found.', 'low-product-table-editor' ),
			'unsupported'    => __( 'This product type cannot be updated.', 'low-product-table-editor' ),
			'invalid_price'  => __( 'The stored price is not valid.', 'low-product-table-editor' ),
			'item_blocked'   => __( 'This field was not changed because another field on the item failed.', 'low-product-table-editor' ),
			'save_failed'    => __( 'The changes could not be saved.', 'low-product-table-editor' ),
		);

		return $messages[ $reason ] ?? '';
	}

	/**
	 * One calculation result.
	 *
	 * @param string $new_price New price text.
	 * @param string $status Status.
	 * @param string $reason Reason code.
	 * @return array{new: string, status: string, reason: string}
	 */
	private function calc_row( string $new_price, string $status, string $reason ): array {
		return array(
			'new'    => $new_price,
			'status' => $status,
			'reason' => $reason,
		);
	}

	/**
	 * One preview row.
	 *
	 * @param int         $id        Item ID.
	 * @param int         $parent_id Parent ID.
	 * @param string      $name      Product name.
	 * @param string      $sold_by   Sold By.
	 * @param string      $field     Field name.
	 * @param string      $old       Old price.
	 * @param string      $new_price New price.
	 * @param string|null $change    Signed change.
	 * @param string      $status    Status.
	 * @param string      $reason    Reason code.
	 * @return array<string, mixed>
	 */
	private function field_row( int $id, int $parent_id, string $name, string $sold_by, string $field, string $old, string $new_price, ?string $change, string $status, string $reason ): array {
		return array(
			'id'        => $id,
			'parent_id' => $parent_id,
			'name'      => $name,
			'sold_by'   => $sold_by,
			'field'     => $field,
			'old'       => $old,
			'new'       => $new_price,
			'change'    => $change,
			'status'    => $status,
			'reason'    => $reason,
		);
	}
}
