<?php
/**
 * Audit log storage.
 *
 * @package LOW\ProductTableEditor
 */

declare( strict_types=1 );

namespace LOW\ProductTableEditor;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the per-site audit log.
 *
 * @since 0.1.1
 */
class Log_Repository {

	/**
	 * Field order used in batch summaries.
	 *
	 * @return array<int, string>
	 */
	private function field_order(): array {
		return array(
			'regular_price',
			'sale_price',
			'sku',
			'weight',
			'manage_stock',
			'stock_quantity',
			'stock_status',
			'name',
			'status',
			'catalog_visibility',
			'description',
			'short_description',
		);
	}

	/**
	 * Log table for the current site.
	 *
	 * @return string
	 */
	private function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'low_pte_log';
	}

	/**
	 * New batch identifier.
	 *
	 * @since 0.1.1
	 *
	 * @return string
	 */
	public function new_batch_id(): string {
		return wp_generate_uuid4();
	}

	/**
	 * Whether a bulk chunk may append to this batch.
	 *
	 * An unused id is allowed. An existing batch is allowed only when every
	 * row belongs to this user and was written by a bulk action.
	 *
	 * @since 0.1.1
	 *
	 * @param string $batch_id Batch identifier.
	 * @param int    $user_id  Current user.
	 * @return string empty, ok, or foreign.
	 */
	public function bulk_batch_status( string $batch_id, int $user_id ): string {
		global $wpdb;

		$table = $this->table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is the current site prefix.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS total_rows, SUM(CASE WHEN user_id = %d AND source = 'bulk' THEN 1 ELSE 0 END) AS owned_rows FROM {$table} WHERE batch_id = %s",
				$user_id,
				$batch_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$total = is_array( $row ) ? (int) $row['total_rows'] : 0;
		$owned = is_array( $row ) ? (int) $row['owned_rows'] : 0;

		if ( $total < 1 ) {
			return 'empty';
		}

		if ( $owned === $total ) {
			return 'ok';
		}

		return 'foreign';
	}

	/**
	 * Insert log rows for one batch.
	 *
	 * @since 0.1.1
	 *
	 * @param string            $batch_id Batch identifier.
	 * @param int               $user_id  User who made the change.
	 * @param string            $source   inline, bulk, or undo.
	 * @param array<int, mixed> $entries  Rows with product_id, parent_id, field, old, and new.
	 * @return bool
	 */
	public function insert_entries( string $batch_id, int $user_id, string $source, array $entries ): bool {
		if ( ! in_array( $source, array( 'inline', 'bulk', 'undo' ), true ) || array() === $entries ) {
			return false;
		}

		$created = current_time( 'mysql', true );
		$chunks  = array_chunk( $entries, 200 );

		foreach ( $chunks as $chunk ) {
			if ( ! is_array( $chunk ) || ! $this->insert_chunk( $batch_id, $user_id, $source, $created, $chunk ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * One page of batches matching the filters.
	 *
	 * Query count stays fixed for a page. It does not grow with the number of batches shown.
	 *
	 * @since 0.1.1
	 *
	 * @param array<string, mixed> $filters user_id, product_id, product_ids, date_from, date_to, page, per_page.
	 * @return array{batches: array<int, array<string, mixed>>, total: int, users: array<int, array<string, mixed>>}
	 */
	public function get_batches( array $filters ): array {
		global $wpdb;

		$page     = isset( $filters['page'] ) ? max( 1, (int) $filters['page'] ) : 1;
		$per_page = isset( $filters['per_page'] ) ? (int) $filters['per_page'] : 20;
		$offset   = ( $page - 1 ) * $per_page;
		$where    = $this->where_clause( $filters );
		$table    = $this->table();

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is the current site prefix. Filters are prepared.
		$count_sql = "SELECT COUNT( DISTINCT batch_id ) FROM {$table} WHERE {$where['sql']}";
		$total     = $where['args'] ? $wpdb->get_var( $wpdb->prepare( $count_sql, $where['args'] ) ) : $wpdb->get_var( $count_sql );

		$list_sql  = "SELECT batch_id, MIN(user_id) AS user_id, MIN(source) AS source, MIN(created_at) AS created_at, COUNT(*) AS change_count, COUNT(DISTINCT product_id) AS item_count, SUM(CASE WHEN undone_at IS NULL THEN 0 ELSE 1 END) AS undone_count FROM {$table} WHERE {$where['sql']} GROUP BY batch_id ORDER BY MIN(created_at) DESC LIMIT %d OFFSET %d";
		$list_args = array_merge( $where['args'], array( $per_page, $offset ) );
		$batches   = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_args ), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! is_array( $batches ) ) {
			$batches = array();
		}

		$batch_ids = array();
		$user_ids  = array();
		foreach ( $batches as $batch ) {
			$batch_ids[] = (string) $batch['batch_id'];
			$user_ids[]  = (int) $batch['user_id'];
		}

		$summaries = $this->summaries_for( $batch_ids );
		$names     = $this->user_names( $user_ids );
		$rows      = array();

		foreach ( $batches as $batch ) {
			$batch_id = (string) $batch['batch_id'];
			$user_id  = (int) $batch['user_id'];
			$changes  = (int) $batch['change_count'];
			$undone   = (int) $batch['undone_count'];
			$source   = (string) $batch['source'];
			$state    = 'none';
			if ( $changes > 0 && $undone >= $changes ) {
				$state = 'full';
			} elseif ( $undone > 0 ) {
				$state = 'partial';
			}
			$rows[] = array(
				'batch_id'     => $batch_id,
				'user_id'      => $user_id,
				'user_name'    => $names[ $user_id ] ?? '',
				'source'       => $source,
				'created_at'   => (string) $batch['created_at'],
				'item_count'   => (int) $batch['item_count'],
				'change_count' => $changes,
				'summary'      => $summaries[ $batch_id ] ?? '',
				'undone_count' => $undone,
				'total_count'  => $changes,
				'can_undo'     => 'undo' !== $source && $undone < $changes,
				'undo_state'   => $state,
			);
		}

		return array(
			'batches' => $rows,
			'total'   => (int) $total,
			'users'   => $this->log_users(),
		);
	}

	/**
	 * Entries in one batch, with product names resolved in batched queries.
	 *
	 * @since 0.1.1
	 *
	 * @param string $batch_id Batch identifier.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_batch_entries( string $batch_id ): array {
		global $wpdb;

		$table = $this->table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is the current site prefix.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, product_id, parent_id, field, old_value, new_value, undone_at, created_at FROM {$table} WHERE batch_id = %s ORDER BY id ASC",
				$batch_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$product_ids = array();
		foreach ( $rows as $row ) {
			$product_ids[] = (int) $row['product_id'];
			if ( (int) $row['parent_id'] > 0 ) {
				$product_ids[] = (int) $row['parent_id'];
			}
		}

		$names   = $this->product_names( $product_ids );
		$entries = array();

		foreach ( $rows as $row ) {
			$product_id = (int) $row['product_id'];
			$entries[]  = array(
				'id'           => (int) $row['id'],
				'product_id'   => $product_id,
				'parent_id'    => (int) $row['parent_id'],
				'product_name' => $names[ $product_id ] ?? '',
				'field'        => (string) $row['field'],
				'old_value'    => $row['old_value'],
				'new_value'    => $row['new_value'],
				'undone_at'    => $row['undone_at'],
				'created_at'   => (string) $row['created_at'],
			);
		}

		return $entries;
	}

	/**
	 * Delete rows older than the cutoff, 1000 at a time.
	 *
	 * @since 0.1.1
	 *
	 * @param int $days Retention in days.
	 * @return int Rows deleted.
	 */
	public function purge_older_than( int $days ): int {
		global $wpdb;

		if ( $days < 1 ) {
			return 0;
		}

		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$table   = $this->table();
		$deleted = 0;

		do {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is the current site prefix. Limit is a fixed chunk size.
			$rows = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE created_at < %s LIMIT 1000",
					$cutoff
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( ! is_int( $rows ) || $rows < 1 ) {
				break;
			}

			$deleted += $rows;
		} while ( 1000 === $rows );

		return $deleted;
	}

	/**
	 * Insert one chunk of a batch.
	 *
	 * @param string            $batch_id Batch identifier.
	 * @param int               $user_id  User ID.
	 * @param string            $source   Source value.
	 * @param string            $created  GMT mysql datetime.
	 * @param array<int, mixed> $entries  Chunk of entries.
	 * @return bool
	 */
	private function insert_chunk( string $batch_id, int $user_id, string $source, string $created, array $entries ): bool {
		global $wpdb;

		$rows = array();
		$args = array();

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$field = isset( $entry['field'] ) ? (string) $entry['field'] : '';
			if ( ! in_array( $field, $this->field_order(), true ) ) {
				continue;
			}

			$old       = $entry['old'] ?? null;
			$new       = $entry['new'] ?? null;
			$old_token = null === $old ? 'NULL' : '%s';
			$new_token = null === $new ? 'NULL' : '%s';
			$rows[]    = "( %s, %d, %d, %d, %s, {$old_token}, {$new_token}, %s, %s )";
			$args[]    = $batch_id;
			$args[]    = $user_id;
			$args[]    = isset( $entry['product_id'] ) ? absint( $entry['product_id'] ) : 0;
			$args[]    = isset( $entry['parent_id'] ) ? absint( $entry['parent_id'] ) : 0;
			$args[]    = $field;

			if ( null !== $old ) {
				$args[] = (string) $old;
			}

			if ( null !== $new ) {
				$args[] = (string) $new;
			}

			$args[] = $source;
			$args[] = $created;
		}

		if ( array() === $rows ) {
			return true;
		}

		$table = $this->table();
		$sql   = "INSERT INTO {$table} ( batch_id, user_id, product_id, parent_id, field, old_value, new_value, source, created_at ) VALUES " . implode( ', ', $rows );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Placeholders are built with the chunk. Table name is the current site prefix.
		$result = $wpdb->query( $wpdb->prepare( $sql, $args ) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return false !== $result;
	}

	/**
	 * Prepared WHERE fragment shared by the count and page queries.
	 *
	 * @param array<string, mixed> $filters Request filters.
	 * @return array{sql: string, args: array<int, int|string>}
	 */
	private function where_clause( array $filters ): array {
		$sql  = array( '1=1' );
		$args = array();

		$user_id = isset( $filters['user_id'] ) ? absint( $filters['user_id'] ) : 0;
		if ( $user_id > 0 ) {
			$sql[]  = 'user_id = %d';
			$args[] = $user_id;
		}

		$product_ids = array();
		if ( isset( $filters['product_ids'] ) && is_array( $filters['product_ids'] ) ) {
			$product_ids = array_values( array_filter( array_map( 'absint', $filters['product_ids'] ) ) );
		} elseif ( isset( $filters['product_id'] ) && absint( $filters['product_id'] ) > 0 ) {
			$product_ids = array( absint( $filters['product_id'] ) );
		}

		if ( array() !== $product_ids ) {
			$placeholders = implode( ', ', array_fill( 0, count( $product_ids ), '%d' ) );
			$sql[]        = "product_id IN ( {$placeholders} )";
			foreach ( $product_ids as $product_id ) {
				$args[] = $product_id;
			}
		}

		if ( isset( $filters['date_from'] ) && is_string( $filters['date_from'] ) && '' !== $filters['date_from'] ) {
			$sql[]  = 'created_at >= %s';
			$args[] = $filters['date_from'] . ' 00:00:00';
		}

		if ( isset( $filters['date_to'] ) && is_string( $filters['date_to'] ) && '' !== $filters['date_to'] ) {
			$sql[]  = 'created_at <= %s';
			$args[] = $filters['date_to'] . ' 23:59:59';
		}

		return array(
			'sql'  => implode( ' AND ', $sql ),
			'args' => $args,
		);
	}

	/**
	 * Per-field counts for the batches on this page.
	 *
	 * @param array<int, string> $batch_ids Batch identifiers.
	 * @return array<string, string>
	 */
	private function summaries_for( array $batch_ids ): array {
		global $wpdb;

		$batch_ids = array_values( array_filter( array_map( 'strval', $batch_ids ) ) );
		if ( array() === $batch_ids ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $batch_ids ), '%s' ) );
		$table        = $this->table();

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One summary query for the current page of batch IDs.
		$prepared = $wpdb->prepare(
			"SELECT batch_id, field, COUNT(*) AS field_count FROM {$table} WHERE batch_id IN ( {$placeholders} ) GROUP BY batch_id, field",
			$batch_ids
		);
		$rows     = $wpdb->get_results( $prepared, ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ (string) $row['batch_id'] ][ (string) $row['field'] ] = (int) $row['field_count'];
		}

		$summaries = array();
		foreach ( $counts as $batch_id => $fields ) {
			$parts = array();
			foreach ( $this->field_order() as $field ) {
				if ( ! isset( $fields[ $field ] ) ) {
					continue;
				}
				$parts[] = $field . ' x ' . $fields[ $field ];
			}
			$summaries[ $batch_id ] = implode( ', ', $parts );
		}

		return $summaries;
	}

	/**
	 * Display names for the users on this page.
	 *
	 * @param array<int, int> $user_ids User IDs.
	 * @return array<int, string>
	 */
	private function user_names( array $user_ids ): array {
		$user_ids = array_values( array_unique( array_filter( array_map( 'absint', $user_ids ) ) ) );
		if ( array() === $user_ids ) {
			return array();
		}

		$users = get_users(
			array(
				'include' => $user_ids,
				'fields'  => array( 'ID', 'display_name' ),
			)
		);

		$names = array();
		foreach ( $users as $user ) {
			if ( $user instanceof \WP_User ) {
				$names[ (int) $user->ID ] = (string) $user->display_name;
			}
		}

		return $names;
	}

	/**
	 * Users who have at least one log row.
	 *
	 * @since 0.1.1
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function users(): array {
		return $this->log_users();
	}

	/**
	 * Users who have at least one log row.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function log_users(): array {
		global $wpdb;

		$table = $this->table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is the current site prefix. One distinct-user query for the filter.
		$ids = $wpdb->get_col( "SELECT DISTINCT user_id FROM {$table}" );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! is_array( $ids ) ) {
			return array();
		}

		$names = $this->user_names( array_map( 'absint', $ids ) );
		$rows  = array();
		foreach ( $names as $user_id => $name ) {
			$rows[] = array(
				'id'   => $user_id,
				'name' => $name,
			);
		}

		usort(
			$rows,
			static function ( array $left, array $right ): int {
				return strcasecmp( (string) $left['name'], (string) $right['name'] );
			}
		);

		return $rows;
	}

	/**
	 * Product and variation labels for one batch, loaded without per-row queries.
	 *
	 * @param array<int, int> $ids Product and parent IDs.
	 * @return array<int, string>
	 */
	private function product_names( array $ids ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( array() === $ids ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One post query for the batch.
		$prepared = $wpdb->prepare(
			"SELECT ID, post_title, post_parent, post_type FROM {$wpdb->posts} WHERE ID IN ( {$placeholders} )",
			$ids
		);
		$posts    = $wpdb->get_results( $prepared, ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! is_array( $posts ) ) {
			return array();
		}

		$by_id         = array();
		$variation_ids = array();
		foreach ( $posts as $post ) {
			$id           = (int) $post['ID'];
			$by_id[ $id ] = $post;
			if ( 'product_variation' === $post['post_type'] ) {
				$variation_ids[] = $id;
			}
		}

		if ( array() !== $variation_ids ) {
			update_meta_cache( 'post', $variation_ids );
		}

		$names = array();
		foreach ( $by_id as $id => $post ) {
			$title = (string) $post['post_title'];
			if ( 'product_variation' !== $post['post_type'] ) {
				$names[ $id ] = $title;
				continue;
			}

			$parent_id    = (int) $post['post_parent'];
			$parent_title = isset( $by_id[ $parent_id ] ) ? (string) $by_id[ $parent_id ]['post_title'] : $title;
			$attributes   = $this->variation_attributes( $id );
			$label        = '' !== $parent_title ? $parent_title : $title;
			if ( array() !== $attributes ) {
				$label .= ' (' . implode( ', ', $attributes ) . ')';
			}
			$names[ $id ] = $label;
		}

		return $names;
	}

	/**
	 * Cached variation attribute values.
	 *
	 * @param int $variation_id Variation ID.
	 * @return array<int, string>
	 */
	private function variation_attributes( int $variation_id ): array {
		$meta = get_post_meta( $variation_id );
		if ( ! is_array( $meta ) ) {
			return array();
		}

		$values = array();
		foreach ( $meta as $key => $stored ) {
			if ( ! is_string( $key ) || ! str_starts_with( $key, 'attribute_' ) ) {
				continue;
			}
			$value = is_array( $stored ) ? (string) reset( $stored ) : '';
			if ( '' !== $value ) {
				$values[] = $value;
			}
		}

		return $values;
	}

	/**
	 * Raw rows for one batch, including source and values that are still undone.
	 *
	 * @since 0.1.1
	 *
	 * @param string $batch_id Batch identifier.
	 * @return array<int, array<string, mixed>>
	 */
	public function raw_batch( string $batch_id ): array {
		global $wpdb;

		$table = $this->table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is the current site prefix.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, product_id, parent_id, field, old_value, new_value, source, undone_at FROM {$table} WHERE batch_id = %s ORDER BY id ASC",
				$batch_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$entries = array();
		foreach ( $rows as $row ) {
			$undone_at = $row['undone_at'];
			$entries[] = array(
				'id'         => (int) $row['id'],
				'product_id' => (int) $row['product_id'],
				'parent_id'  => (int) $row['parent_id'],
				'field'      => (string) $row['field'],
				'old_value'  => $row['old_value'],
				'new_value'  => $row['new_value'],
				'source'     => (string) $row['source'],
				'undone_at'  => null === $undone_at || '' === $undone_at ? null : (string) $undone_at,
			);
		}

		return $entries;
	}

	/**
	 * Mark restored log rows undone, 500 ids at a time.
	 *
	 * @since 0.1.1
	 *
	 * @param array<int, int> $ids Log row IDs.
	 * @return void
	 */
	public function mark_undone( array $ids ): void {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( array() === $ids ) {
			return;
		}

		$when  = current_time( 'mysql', true );
		$table = $this->table();

		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );
			$args         = array_merge( array( $when ), $chunk );
			$sql          = "UPDATE {$table} SET undone_at = %s WHERE undone_at IS NULL AND id IN ( {$placeholders} )";

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Placeholders match the chunk. Table name is the current site prefix.
			$wpdb->query( $wpdb->prepare( $sql, $args ) );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}
	}
}
