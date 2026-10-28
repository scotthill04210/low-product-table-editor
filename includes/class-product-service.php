<?php
/**
 * Product queries and edits for the table editor.
 *
 * @package LOW\ProductTableEditor
 */

declare( strict_types=1 );

namespace LOW\ProductTableEditor;

defined( 'ABSPATH' ) || exit;

/**
 * Loads parent products and their variations, and saves edited fields.
 *
 * @since 0.1.0
 */
class Product_Service {

	/**
	 * Menu orders captured before the first save of a parent family.
	 *
	 * @var array<int, array<int, int>>
	 */
	private array $menu_order_snapshot = array();

	/**
	 * Term names keyed by taxonomy and slug.
	 *
	 * @var array<string, string>
	 */
	private static array $term_names = array();

	/**
	 * Attribute labels keyed by parent ID and attribute slug.
	 *
	 * @var array<string, string>
	 */
	private static array $attribute_labels = array();

	/**
	 * Page of parent products with nested variations.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array<string, mixed>
	 */
	public function get_products( array $args ): array {
		$page     = max( 1, absint( $args['page'] ?? 1 ) );
		$per_page = $this->per_page( $args['per_page'] ?? 25 );
		$search   = $this->search_term( $args['search'] ?? '' );
		$category = sanitize_title( is_scalar( $args['category'] ?? '' ) ? (string) $args['category'] : '' );
		$type     = $this->choice( $args['type'] ?? '', array( 'simple', 'variable' ) );
		$stock    = $this->choice( $args['stock_status'] ?? '', array( 'instock', 'outofstock', 'onbackorder' ) );
		$status   = $this->choice( $args['status'] ?? '', array( 'publish', 'draft', 'private', 'pending' ) );

		$query = array(
			'status'   => '' !== $status ? array( $status ) : array( 'publish', 'draft', 'private', 'pending' ),
			'type'     => '' !== $type ? array( $type ) : array( 'simple', 'variable' ),
			'limit'    => $per_page,
			'page'     => $page,
			'paginate' => true,
			'return'   => 'ids',
			'orderby'  => 'title',
			'order'    => 'ASC',
		);

		if ( '' !== $category ) {
			$query['category'] = array( $category );
		}

		if ( '' !== $stock ) {
			$query['stock_status'] = $stock;
		}

		if ( '' !== $search ) {
			$include = $this->parent_ids_for_search( $search );
			if ( array() === $include ) {
				return $this->empty_page( $page, $per_page );
			}
			$query['include'] = $include;
		}

		$result = wc_get_products( $query );
		$total  = 0;
		$pages  = 0;
		$ids    = array();

		if ( is_object( $result ) && isset( $result->products ) ) {
			$ids   = array_map( 'absint', (array) $result->products );
			$total = isset( $result->total ) ? (int) $result->total : 0;
			$pages = isset( $result->max_num_pages ) ? (int) $result->max_num_pages : 0;
		} elseif ( is_array( $result ) ) {
			$ids   = array_map( 'absint', $result );
			$total = count( $ids );
			$pages = $total > 0 ? 1 : 0;
		}

		$parent_ids = array_values(
			array_filter(
				$ids,
				static function ( int $id ): bool {
					return $id > 0;
				}
			)
		);

		if ( array() === $parent_ids ) {
			return array(
				'items'       => array(),
				'total'       => $total,
				'total_pages' => $pages,
				'page'        => $page,
				'per_page'    => $per_page,
			);
		}

		$variation_ids = $this->variation_ids_for_parents( $parent_ids );
		$this->prime_caches( array_merge( $parent_ids, $variation_ids ) );

		$variation_products = array();
		foreach ( $variation_ids as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( $variation instanceof \WC_Product ) {
				$variation_products[] = $variation;
			}
		}

		$this->prime_attribute_terms( $variation_products );

		$children_by_parent = array();
		foreach ( $variation_products as $variation ) {
			$parent_id                          = $variation->get_parent_id();
			$children_by_parent[ $parent_id ][] = $this->build_row( $variation );
		}

		$items = array();
		foreach ( $parent_ids as $parent_id ) {
			$parent = wc_get_product( $parent_id );
			if ( ! $parent instanceof \WC_Product ) {
				continue;
			}
			$children = $children_by_parent[ $parent_id ] ?? array();
			$row      = $this->build_row( $parent, count( $children ) );
			if ( $parent->is_type( 'variable' ) ) {
				$row['children'] = $children;
			}
			$items[] = $row;
		}

		return array(
			'items'       => $items,
			'total'       => $total,
			'total_pages' => $pages,
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}

	/**
	 * One product or variation, including the full description fields.
	 *
	 * @since 0.1.1
	 *
	 * @param int $id Product or variation ID.
	 * @return array<string, mixed>|null Null when the ID is not a supported product.
	 */
	public function get_item( int $id ): ?array {
		$product = wc_get_product( $id );
		if ( ! $product instanceof \WC_Product || ! $this->is_supported_product( $product ) ) {
			return null;
		}

		return $this->detail_row( $product );
	}

	/**
	 * Validate and save a batch.
	 *
	 * Every item is checked for a conflict before the first write, so a parent
	 * and its variation in the same request cannot conflict with each other.
	 *
	 * @since 0.1.1
	 *
	 * @param array<int, mixed> $items Requested items.
	 * @return array{results: array<int, array<string, mixed>>, batch_id: string|null, log_failed: bool}
	 */
	public function update_items( array $items ): array {
		$plans = array();

		foreach ( $items as $item ) {
			$plans[] = $this->plan_item( $item );
		}

		$results  = array();
		$entries  = array();
		$batch_id = null;

		foreach ( $plans as $plan ) {
			if ( 'ready' !== $plan['status'] ) {
				$results[] = $plan['result'];
				continue;
			}

			$written   = $this->write_item( $plan['id'], $plan['fields'] );
			$results[] = $written['result'];

			if ( array() === $written['entries'] ) {
				continue;
			}

			if ( null === $batch_id ) {
				$batch_id = ( new Log_Repository() )->new_batch_id();
			}

			foreach ( $written['entries'] as $entry ) {
				$entries[] = $entry;
			}
		}

		$log_failed = false;
		if ( array() !== $entries && is_string( $batch_id ) ) {
			$log_failed = ! $this->log_changes( $batch_id, 'inline', $entries );
		} else {
			$batch_id = null;
		}

		return array(
			'results'    => $results,
			'batch_id'   => $batch_id,
			'log_failed' => $log_failed,
		);
	}

	/**
	 * Record a batch of field changes. A failed insert does not undo the save.
	 *
	 * @since 0.1.1
	 *
	 * @param string            $batch_id Batch identifier.
	 * @param string            $source   inline, bulk, or undo.
	 * @param array<int, mixed> $entries  Changed fields.
	 * @return bool
	 */
	public function log_changes( string $batch_id, string $source, array $entries ): bool {
		$repository = new Log_Repository();
		$saved      = $repository->insert_entries( $batch_id, get_current_user_id(), $source, $entries );

		if ( ! $saved ) {
			global $wpdb;

			$message = isset( $wpdb->last_error ) ? (string) $wpdb->last_error : '';
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- The product save already succeeded. The log failure has to be visible to the site operator.
			error_log( 'LOW Product Table Editor: ' . $message );
		}

		return $saved;
	}

	/**
	 * Product and variation IDs for a History search.
	 *
	 * Uses the same product search as the table, and includes variations of matched parents.
	 * A numeric term also matches that ID directly.
	 *
	 * @since 0.1.1
	 *
	 * @param string $term Search text or a product ID.
	 * @return array<int, int>
	 */
	public function ids_for_log_filter( string $term ): array {
		$term = trim( $term );
		if ( '' === $term ) {
			return array();
		}

		$parents = $this->parent_ids_for_search( $term );
		$ids     = array_merge( $parents, $this->variation_ids_for_parents( $parents ) );

		if ( ctype_digit( $term ) ) {
			$ids[] = (int) $term;
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	/**
	 * Parent IDs that match a search of names, SKUs, or variation attribute values.
	 *
	 * @since 0.1.0
	 *
	 * @param string $term Search text.
	 * @return array<int, int>
	 */
	private function parent_ids_for_search( string $term ): array {
		$store = \WC_Data_Store::load( 'product' );
		$found = $store->search_products( $term, '', true, true );
		$ids   = array_map( 'absint', is_array( $found ) ? $found : array() );
		$ids   = array_merge( $ids, $this->variation_ids_matching_attribute( $term ) );

		return $this->parent_ids_for_hits( $ids );
	}

	/**
	 * Variation IDs whose attribute values match the search text.
	 *
	 * WooCommerce product search covers title, excerpt, content, and SKU.
	 * Attribute values such as "Roll" need this extra query.
	 *
	 * @since 0.1.0
	 *
	 * @param string $term Search text.
	 * @return array<int, int>
	 */
	private function variation_ids_matching_attribute( string $term ): array {
		global $wpdb;

		$attribute_like = $wpdb->esc_like( 'attribute_' ) . '%';
		$value_like     = '%' . $wpdb->esc_like( $term ) . '%';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Attribute values are not in WooCommerce product search. One query per search. Table names are wpdb properties.
		$prepared = $wpdb->prepare(
			"SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = 'product_variation' AND p.post_status IN ( 'publish', 'private' ) AND pm.meta_key LIKE %s AND pm.meta_value LIKE %s",
			$attribute_like,
			$value_like
		);
		$ids      = $wpdb->get_col( $prepared );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! is_array( $ids ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'absint', $ids ) ) );
	}

	/**
	 * Map product and variation IDs onto parent product IDs.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, int> $ids Matching IDs.
	 * @return array<int, int>
	 */
	private function parent_ids_for_hits( array $ids ): array {
		global $wpdb;

		$ids = array_values(
			array_unique(
				array_filter(
					array_map( 'absint', $ids ),
					static function ( int $id ): bool {
						return $id > 0;
					}
				)
			)
		);

		if ( array() === $ids ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One mapping query for a request-scoped ID list. Placeholders match that list. Table name is a wpdb property.
		$prepared = $wpdb->prepare(
			"SELECT ID, post_parent FROM {$wpdb->posts} WHERE ID IN ( {$placeholders} )",
			$ids
		);
		$rows     = $wpdb->get_results( $prepared );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$parents = array();
		foreach ( $rows as $row ) {
			$id     = isset( $row->ID ) ? (int) $row->ID : 0;
			$parent = isset( $row->post_parent ) ? (int) $row->post_parent : 0;
			if ( $id < 1 ) {
				continue;
			}
			$parents[] = $parent > 0 ? $parent : $id;
		}

		return array_values( array_unique( $parents ) );
	}

	/**
	 * All published or private variations for the given parents, in menu order.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, int> $parent_ids Parent product IDs.
	 * @return array<int, int>
	 */
	private function variation_ids_for_parents( array $parent_ids ): array {
		$parent_ids = array_values( array_filter( array_map( 'absint', $parent_ids ) ) );
		if ( array() === $parent_ids ) {
			return array();
		}

		$query = new \WP_Query(
			array(
				'post_type'              => 'product_variation',
				'post_status'            => array( 'publish', 'private' ),
				'post_parent__in'        => $parent_ids,
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'orderby'                => array(
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				),
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'ignore_sticky_posts'    => true,
			)
		);

		$ids = array_map( 'absint', is_array( $query->posts ) ? $query->posts : array() );
		wp_reset_postdata();

		return array_values( array_filter( $ids ) );
	}

	/**
	 * Prime post, meta, and term caches for one page of products.
	 *
	 * Term relationships are included so product type lookups stay one query
	 * instead of one query per variation.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, int> $ids Post IDs.
	 * @return void
	 */
	private function prime_caches( array $ids ): void {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( array() === $ids ) {
			return;
		}

		update_meta_cache( 'post', $ids );
		_prime_post_caches( $ids, false, false );
		update_object_term_cache( $ids, array( 'product', 'product_variation' ) );
	}

	/**
	 * Load attribute term names for a page of variations in one query per taxonomy.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, \WC_Product> $products Variation products.
	 * @return void
	 */
	private function prime_attribute_terms( array $products ): void {
		$needed = array();

		foreach ( $products as $product ) {
			if ( ! $product->is_type( 'variation' ) ) {
				continue;
			}
			$attributes = $product->get_variation_attributes();
			if ( ! is_array( $attributes ) ) {
				continue;
			}
			foreach ( $attributes as $key => $value ) {
				if ( ! is_scalar( $value ) || '' === (string) $value ) {
					continue;
				}
				$taxonomy = $this->attribute_taxonomy( (string) $key );
				if ( ! str_starts_with( $taxonomy, 'pa_' ) ) {
					continue;
				}
				$needed[ $taxonomy ][ (string) $value ] = true;
			}
		}

		foreach ( $needed as $taxonomy => $slugs ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'slug'       => array_keys( $slugs ),
				)
			);

			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					if ( $term instanceof \WP_Term ) {
						self::$term_names[ $taxonomy . ':' . $term->slug ] = $term->name;
					}
				}
			}

			foreach ( array_keys( $slugs ) as $slug ) {
				$cache_key = $taxonomy . ':' . $slug;
				if ( ! array_key_exists( $cache_key, self::$term_names ) ) {
					self::$term_names[ $cache_key ] = '';
				}
			}
		}
	}

	/**
	 * Build one product or variation row.
	 *
	 * Variable parents get empty price strings. The children key is added by
	 * the caller, and only for variable products.
	 *
	 * @since 0.1.0
	 *
	 * @param \WC_Product $product          Product or variation.
	 * @param int         $variation_count  Nested variation count for variable parents.
	 * @return array<string, mixed>
	 */
	public function build_row( \WC_Product $product, int $variation_count = 0 ): array {
		$is_variation = $product->is_type( 'variation' );
		$is_variable  = $product->is_type( 'variable' );
		$link         = get_edit_post_link( $product->get_id(), 'raw' );

		$details = $this->variation_details( $product );

		return array(
			'id'                  => $product->get_id(),
			'parent_id'           => $is_variation ? $product->get_parent_id() : 0,
			'type'                => $product->get_type(),
			'name'                => $details['name'],
			'variation_label'     => $details['variation_label'],
			'sold_by'             => $details['sold_by'],
			'regular_price'       => $this->price_value( $product, 'regular' ),
			'sale_price'          => $this->price_value( $product, 'sale' ),
			'manage_stock'        => $this->manage_stock_value( $product ),
			'stock_quantity'      => $this->stock_quantity_value( $product ),
			'stock_status'        => (string) $product->get_stock_status( 'edit' ),
			'sku'                 => (string) $product->get_sku( 'edit' ),
			'weight'              => (string) $product->get_weight( 'edit' ),
			'description_preview' => $this->description_preview( $product ),
			'status'              => (string) $product->get_status(),
			'catalog_visibility'  => $is_variation ? '' : (string) $product->get_catalog_visibility( 'edit' ),
			'date_modified'       => $this->modified_timestamp( $product ),
			'variation_count'     => $is_variable ? $variation_count : 0,
			'edit_url'            => is_string( $link ) ? $link : '',
		);
	}

	/**
	 * Raw stored price. Variable parents do not have a single editable price.
	 *
	 * @since 0.1.0
	 *
	 * @param \WC_Product $product Product or variation.
	 * @param string      $which   Either regular or sale.
	 * @return string
	 */
	private function price_value( \WC_Product $product, string $which ): string {
		if ( $product->is_type( 'variable' ) ) {
			return '';
		}

		$value = 'sale' === $which ? $product->get_sale_price( 'edit' ) : $product->get_regular_price( 'edit' );
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return (string) $value;
	}

	/**
	 * Stock management flag. Variations may inherit the parent.
	 *
	 * @since 0.1.0
	 *
	 * @param \WC_Product $product Product or variation.
	 * @return bool|string
	 */
	private function manage_stock_value( \WC_Product $product ): bool|string {
		if ( $product->is_type( 'variation' ) && 'parent' === $product->get_manage_stock() ) {
			return 'parent';
		}

		return (bool) $product->get_manage_stock( 'edit' );
	}

	/**
	 * Stored stock quantity, or null when it is not a number.
	 *
	 * @since 0.1.0
	 *
	 * @param \WC_Product $product Product or variation.
	 * @return int|float|null
	 */
	private function stock_quantity_value( \WC_Product $product ): int|float|null {
		$quantity = $product->get_stock_quantity( 'edit' );
		if ( ! is_numeric( $quantity ) ) {
			return null;
		}

		$number = (float) $quantity;
		if ( floor( $number ) === $number ) {
			return (int) $number;
		}

		return $number;
	}

	/**
	 * First 20 words of the description, with markup removed.
	 *
	 * @since 0.1.0
	 *
	 * @param \WC_Product $product Product or variation.
	 * @return string
	 */
	private function description_preview( \WC_Product $product ): string {
		$text = (string) $product->get_description( 'edit' );

		return wp_trim_words( wp_strip_all_tags( $text ), 20, '...' );
	}

	/**
	 * Unix timestamp for conflict checks.
	 *
	 * @since 0.1.0
	 *
	 * @param \WC_Product $product Product or variation.
	 * @return int
	 */
	private function modified_timestamp( \WC_Product $product ): int {
		$modified = $product->get_date_modified();
		if ( $modified instanceof \DateTimeInterface ) {
			return $modified->getTimestamp();
		}

		return 0;
	}

	/**
	 * Parent title, Sold By value, and the other attribute values for one row.
	 *
	 * Sold By is matched by slug first (sold-by or pa_sold-by), then by label.
	 * Other attribute values become variation_label. They are not copied into sold_by.
	 *
	 * @since 0.1.0
	 *
	 * @param \WC_Product $product Product or variation.
	 * @return array{name: string, sold_by: string, variation_label: string}
	 */
	private function variation_details( \WC_Product $product ): array {
		if ( ! $product->is_type( 'variation' ) ) {
			return array(
				'name'            => $product->get_name(),
				'sold_by'         => '',
				'variation_label' => '',
			);
		}

		$parent = wc_get_product( $product->get_parent_id() );
		$name   = $parent instanceof \WC_Product ? $parent->get_name() : $product->get_name();
		$empty  = array(
			'name'            => $name,
			'sold_by'         => '',
			'variation_label' => '',
		);

		$attributes = $product->get_variation_attributes();
		if ( ! is_array( $attributes ) || array() === $attributes ) {
			return $empty;
		}

		$wanted  = strtolower( $this->sold_by_label() );
		$sold_by = null;
		$others  = array();

		foreach ( $attributes as $key => $value ) {
			$key     = (string) $key;
			$display = $this->attribute_value_text( $key, is_scalar( $value ) ? (string) $value : '' );
			$is_sold = $this->is_sold_by_slug( $key ) || strtolower( $this->attribute_label( $key, $product ) ) === $wanted;
			if ( null === $sold_by && $is_sold ) {
				$sold_by = $display;
				continue;
			}
			if ( '' !== $display ) {
				$others[] = $display;
			}
		}

		$empty['sold_by']         = null === $sold_by ? '' : $sold_by;
		$empty['variation_label'] = implode( ' / ', $others );

		return $empty;
	}

	/**
	 * Label used to recognize the Sold By attribute when the slug does not match.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	private function sold_by_label(): string {
		/**
		 * Filters the attribute label that fills the Sold By column.
		 *
		 * @since 0.1.0
		 *
		 * @param string $label Attribute label. Default "Sold By".
		 */
		$label = apply_filters( 'low_pte_sold_by_label', 'Sold By' );
		if ( ! is_string( $label ) || '' === $label ) {
			return 'Sold By';
		}

		return $label;
	}

	/**
	 * Whether the attribute slug is the Sold By attribute.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key Attribute key from get_variation_attributes().
	 * @return bool
	 */
	private function is_sold_by_slug( string $key ): bool {
		$slug = strtolower( $this->attribute_taxonomy( $key ) );

		return in_array( $slug, array( 'sold-by', 'pa_sold-by' ), true );
	}

	/**
	 * Display text for one variation attribute value.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key   Attribute key, with or without the attribute_ prefix.
	 * @param string $value Stored slug or custom value. Empty means any.
	 * @return string
	 */
	private function attribute_value_text( string $key, string $value ): string {
		if ( '' === $value ) {
			return __( 'Any', 'low-product-table-editor' );
		}

		$taxonomy = $this->attribute_taxonomy( $key );
		if ( str_starts_with( $taxonomy, 'pa_' ) ) {
			$name = $this->term_name( $taxonomy, $value );
			if ( '' !== $name ) {
				return $name;
			}
		}

		return $value;
	}

	/**
	 * Human label for an attribute, cached for the request.
	 *
	 * The product is passed through so a custom attribute keeps the name that
	 * was entered, such as "Sold By", instead of the slug "sold-by".
	 *
	 * @since 0.1.0
	 *
	 * @param string      $key     Attribute key.
	 * @param \WC_Product $product Variation or parent product.
	 * @return string
	 */
	private function attribute_label( string $key, \WC_Product $product ): string {
		$taxonomy  = $this->attribute_taxonomy( $key );
		$parent_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		$cache_key = $parent_id . ':' . $taxonomy;
		if ( isset( self::$attribute_labels[ $cache_key ] ) ) {
			return self::$attribute_labels[ $cache_key ];
		}

		$label = function_exists( 'wc_attribute_label' ) ? wc_attribute_label( $taxonomy, $product ) : $taxonomy;
		if ( ! is_string( $label ) || '' === $label ) {
			$label = $taxonomy;
		}

		self::$attribute_labels[ $cache_key ] = $label;

		return $label;
	}

	/**
	 * Taxonomy or custom attribute slug without the attribute_ prefix.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key Attribute key from get_variation_attributes().
	 * @return string
	 */
	private function attribute_taxonomy( string $key ): string {
		if ( str_starts_with( $key, 'attribute_' ) ) {
			return substr( $key, 10 );
		}

		return $key;
	}

	/**
	 * Term name for a taxonomy slug, using the static cache first.
	 *
	 * @since 0.1.0
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @param string $slug     Term slug.
	 * @return string
	 */
	private function term_name( string $taxonomy, string $slug ): string {
		$cache_key = $taxonomy . ':' . $slug;
		if ( array_key_exists( $cache_key, self::$term_names ) ) {
			return self::$term_names[ $cache_key ];
		}

		$term                           = get_term_by( 'slug', $slug, $taxonomy );
		$name                           = $term instanceof \WP_Term ? $term->name : '';
		self::$term_names[ $cache_key ] = $name;

		return $name;
	}

	/**
	 * Empty envelope used when a search matches nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param int $page     Requested page.
	 * @param int $per_page Page size.
	 * @return array<string, mixed>
	 */
	private function empty_page( int $page, int $per_page ): array {
		return array(
			'items'       => array(),
			'total'       => 0,
			'total_pages' => 0,
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}

	/**
	 * Allowed page size, or the default.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Requested page size.
	 * @return int
	 */
	private function per_page( mixed $value ): int {
		$per_page = is_numeric( $value ) ? absint( $value ) : 0;
		if ( in_array( $per_page, array( 10, 25, 50, 100 ), true ) ) {
			return $per_page;
		}

		return 25;
	}

	/**
	 * Trimmed search text, at most 100 characters.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Requested search text.
	 * @return string
	 */
	private function search_term( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$term = trim( sanitize_text_field( (string) $value ) );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $term, 0, 100 );
		}

		return substr( $term, 0, 100 );
	}

	/**
	 * One allowed value, or an empty string.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed              $value   Requested value.
	 * @param array<int, string> $allowed Allowed values.
	 * @return string
	 */
	private function choice( mixed $value, array $allowed ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$text = sanitize_text_field( (string) $value );
		if ( in_array( $text, $allowed, true ) ) {
			return $text;
		}

		return '';
	}

	/**
	 * Simple products, variable parents, and variations only.
	 *
	 * @since 0.1.1
	 *
	 * @param \WC_Product $product Product.
	 * @return bool
	 */
	private function is_supported_product( \WC_Product $product ): bool {
		return in_array( $product->get_type(), array( 'simple', 'variable', 'variation' ), true );
	}

	/**
	 * Grid row plus the description fields the modal edits.
	 *
	 * @since 0.1.1
	 *
	 * @param \WC_Product $product Product or variation.
	 * @return array<string, mixed>
	 */
	private function detail_row( \WC_Product $product ): array {
		$row                = $this->row_for( $product );
		$row['description'] = (string) $product->get_description( 'edit' );

		if ( ! $product->is_type( 'variation' ) ) {
			$row['short_description'] = (string) $product->get_short_description( 'edit' );
		}

		return $row;
	}

	/**
	 * Grid row for one product. Children are not included.
	 *
	 * @since 0.1.1
	 *
	 * @param \WC_Product $product Product or variation.
	 * @return array<string, mixed>
	 */
	private function row_for( \WC_Product $product ): array {
		if ( $product->is_type( 'variation' ) ) {
			$this->prime_attribute_terms( array( $product ) );
		}

		$count = 0;
		if ( $product->is_type( 'variable' ) ) {
			$children = $product->get_children();
			$count    = is_array( $children ) ? count( $children ) : 0;
		}

		return $this->build_row( $product, $count );
	}

	/**
	 * Read a product after its cache has been cleared.
	 *
	 * @since 0.1.1
	 *
	 * @param int $id Product or variation ID.
	 * @return \WC_Product|null
	 */
	private function reload_product( int $id ): ?\WC_Product {
		if ( $id < 1 ) {
			return null;
		}

		clean_post_cache( $id );
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $id );
		}

		$product = wc_get_product( $id );
		if ( ! $product instanceof \WC_Product ) {
			return null;
		}

		return $product;
	}

	/**
	 * Conflict and shape checks for one item, before any write.
	 *
	 * @since 0.1.1
	 *
	 * @param mixed $item Requested item.
	 * @return array<string, mixed>
	 */
	private function plan_item( mixed $item ): array {
		if ( ! is_array( $item ) ) {
			return array(
				'status' => 'error',
				'result' => $this->item_result( 0, 'error', $this->item_shape_message(), array(), null ),
			);
		}

		$id = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
		if ( $id < 1 || ! array_key_exists( 'date_modified', $item ) || ! isset( $item['fields'] ) || ! is_array( $item['fields'] ) || array() === $item['fields'] ) {
			return array(
				'status' => 'error',
				'result' => $this->item_result( $id, 'error', $this->item_shape_message(), array(), null ),
			);
		}

		$product = wc_get_product( $id );
		if ( ! $product instanceof \WC_Product || ! $this->is_supported_product( $product ) ) {
			return array(
				'status' => 'error',
				'result' => $this->item_result( $id, 'error', __( 'Product not found.', 'low-product-table-editor' ), array(), null ),
			);
		}

		$posted = is_numeric( $item['date_modified'] ) ? (int) $item['date_modified'] : -1;
		if ( $posted !== $this->modified_timestamp( $product ) ) {
			return array(
				'status' => 'conflict',
				'result' => $this->item_result(
					$id,
					'conflict',
					__( 'This item was changed by someone else. Reload the row to see the latest values.', 'low-product-table-editor' ),
					array(),
					$this->row_for( $product )
				),
			);
		}

		if ( ! current_user_can( 'edit_post', $id ) ) {
			return array(
				'status' => 'error',
				'result' => $this->item_result( $id, 'error', __( 'You are not allowed to edit this item.', 'low-product-table-editor' ), array(), null ),
			);
		}

		return array(
			'status' => 'ready',
			'id'     => $id,
			'fields' => $item['fields'],
		);
	}

	/**
	 * Message for an item that is missing id, date_modified, or fields.
	 *
	 * @since 0.1.1
	 *
	 * @return string
	 */
	private function item_shape_message(): string {
		return __( 'Each item needs an id, a date modified, and fields.', 'low-product-table-editor' );
	}

	/**
	 * Validate one item, then save it.
	 *
	 * The product is loaded again here so an earlier variation save in this
	 * batch can sync the parent before the parent fields are applied.
	 *
	 * @since 0.1.1
	 *
	 * @param int                  $id     Product or variation ID.
	 * @param array<string, mixed> $fields Edited fields.
	 * @return array{result: array<string, mixed>, entries: array<int, array<string, mixed>>}
	 */
	private function write_item( int $id, array $fields ): array {
		$empty = static function ( array $result ): array {
			return array(
				'result'  => $result,
				'entries' => array(),
			);
		};

		$product = $this->reload_product( $id );
		if ( ! $product instanceof \WC_Product || ! $this->is_supported_product( $product ) ) {
			return $empty( $this->item_result( $id, 'error', __( 'Product not found.', 'low-product-table-editor' ), array(), null ) );
		}

		$checked = $this->validate_fields( $product, $fields );
		if ( array() !== $checked['errors'] ) {
			return $empty(
				$this->item_result(
					$id,
					'error',
					__( 'Some fields could not be saved.', 'low-product-table-editor' ),
					$checked['errors'],
					$this->row_for( $product )
				)
			);
		}

		$before = array();
		foreach ( array_keys( $checked['values'] ) as $field_name ) {
			$before[ $field_name ] = $this->log_value( $field_name, $this->current_field( $product, $field_name ) );
		}

		$changed = false;

		try {
			$changed = $this->apply_fields( $product, $checked['values'] );
			if ( $changed ) {
				$this->save_product( $product );
			}
		} catch ( \WC_Data_Exception $exception ) {
			$reloaded = $this->reload_product( $id );
			$message  = $exception->getMessage();
			$errors   = array();
			if ( array_key_exists( 'sku', $fields ) ) {
				$errors['sku'] = $message;
			}

			return $empty(
				$this->item_result(
					$id,
					'error',
					$message,
					$errors,
					$reloaded instanceof \WC_Product ? $this->row_for( $reloaded ) : null
				)
			);
		} catch ( \Throwable $exception ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Logged only while WP_DEBUG is on.
				error_log( 'LOW Product Table Editor: ' . $exception->getMessage() );
			}

			$reloaded = $this->reload_product( $id );

			return $empty(
				$this->item_result(
					$id,
					'error',
					__( 'The changes could not be saved.', 'low-product-table-editor' ),
					array(),
					$reloaded instanceof \WC_Product ? $this->row_for( $reloaded ) : null
				)
			);
		}

		$fresh  = $this->reload_product( $id );
		$source = $fresh instanceof \WC_Product ? $fresh : $product;
		$parent = null;

		if ( $source->is_type( 'variation' ) ) {
			$parent_product = $this->reload_product( $source->get_parent_id() );
			if ( $parent_product instanceof \WC_Product ) {
				$parent = $this->row_for( $parent_product );
			}
		}

		$entries = array();
		if ( $changed ) {
			$parent_id = $source->is_type( 'variation' ) ? $source->get_parent_id() : 0;
			foreach ( $before as $field_name => $old_value ) {
				$new_value = $this->log_value( $field_name, $this->current_field( $source, $field_name ) );
				if ( $old_value === $new_value ) {
					continue;
				}
				$entries[] = array(
					'product_id' => $id,
					'parent_id'  => $parent_id,
					'field'      => $field_name,
					'old'        => $old_value,
					'new'        => $new_value,
				);
			}
		}

		return array(
			'result'  => $this->item_result( $id, 'ok', '', array(), $this->row_for( $source ), $parent ),
			'entries' => $entries,
		);
	}

	/**
	 * One result object for the PATCH response.
	 *
	 * @since 0.1.1
	 *
	 * @param int                       $id      Product ID.
	 * @param string                    $status  ok, error, or conflict.
	 * @param string                    $message Human-readable message.
	 * @param array<string, string>     $errors  Field messages.
	 * @param array<string, mixed>|null $item    Fresh row, when one exists.
	 * @param array<string, mixed>|null $parent_row Fresh parent row for a variation.
	 * @return array<string, mixed>
	 */
	private function item_result( int $id, string $status, string $message, array $errors, ?array $item, ?array $parent_row = null ): array {
		$result = array(
			'id'      => $id,
			'status'  => $status,
			'message' => $message,
			'errors'  => $errors,
			'item'    => $item,
		);

		if ( null !== $parent_row ) {
			$result['parent'] = $parent_row;
		}

		return $result;
	}

	/**
	 * Fields that can be edited for this product type.
	 *
	 * @since 0.1.1
	 *
	 * @param \WC_Product $product Product or variation.
	 * @return array<int, string>
	 */
	private function editable_fields( \WC_Product $product ): array {
		if ( $product->is_type( 'variable' ) ) {
			return array( 'sku', 'name', 'status', 'catalog_visibility', 'description', 'short_description' );
		}

		if ( $product->is_type( 'variation' ) ) {
			return array( 'regular_price', 'sale_price', 'sku', 'weight', 'manage_stock', 'stock_quantity', 'stock_status', 'description' );
		}

		if ( $product->is_type( 'simple' ) ) {
			return array( 'regular_price', 'sale_price', 'sku', 'weight', 'manage_stock', 'stock_quantity', 'stock_status', 'name', 'status', 'catalog_visibility', 'description', 'short_description' );
		}

		return array();
	}

	/**
	 * Validate every field. Values are returned only when the item is valid.
	 *
	 * @since 0.1.1
	 *
	 * @param \WC_Product          $product Product or variation.
	 * @param array<string, mixed> $fields  Requested fields.
	 * @return array{errors: array<string, string>, values: array<string, mixed>}
	 */
	private function validate_fields( \WC_Product $product, array $fields ): array {
		$allowed = $this->editable_fields( $product );
		$errors  = array();
		$values  = array();

		foreach ( $fields as $field => $value ) {
			$name = (string) $field;
			if ( ! in_array( $name, $allowed, true ) ) {
				$errors[ $name ] = __( 'This field cannot be edited.', 'low-product-table-editor' );
				continue;
			}

			$checked = $this->validate_field( $product, $name, $value, $fields );
			if ( isset( $checked['message'] ) ) {
				$errors[ $name ] = $checked['message'];
				continue;
			}

			$values[ $name ] = $checked['value'];
		}

		return array(
			'errors' => $errors,
			'values' => array() === $errors ? $values : array(),
		);
	}

	/**
	 * Validate one field.
	 *
	 * @since 0.1.1
	 *
	 * @param \WC_Product          $product Product or variation.
	 * @param string               $field   Field name.
	 * @param mixed                $value   Raw value.
	 * @param array<string, mixed> $fields  All requested fields.
	 * @return array{value?: mixed, message?: string}
	 */
	private function validate_field( \WC_Product $product, string $field, mixed $value, array $fields ): array {
		switch ( $field ) {
			case 'regular_price':
				return $this->validate_regular_price( $product, $value, $fields );
			case 'sale_price':
				return $this->validate_sale_price( $product, $value, $fields );
			case 'sku':
				return $this->validate_text( $value );
			case 'weight':
				return $this->validate_weight( $value );
			case 'manage_stock':
				return $this->validate_manage_stock( $product, $value );
			case 'stock_quantity':
				return $this->validate_stock_quantity( $product, $value, $fields );
			case 'stock_status':
				return $this->validate_stock_status( $product, $value, $fields );
			case 'name':
				return $this->validate_name( $value );
			case 'status':
				return $this->validate_choice( $value, array( 'publish', 'draft', 'private' ), __( 'Invalid status.', 'low-product-table-editor' ) );
			case 'catalog_visibility':
				return $this->validate_choice( $value, array( 'visible', 'catalog', 'search', 'hidden' ), __( 'Invalid catalog visibility.', 'low-product-table-editor' ) );
			case 'description':
			case 'short_description':
				return $this->validate_html( $value );
		}

		return array(
			'message' => __( 'This field cannot be edited.', 'low-product-table-editor' ),
		);
	}

	/**
	 * Regular price is required and keeps up to 4 decimal places.
	 *
	 * @since 0.1.1
	 *
	 * @param \WC_Product          $product Product or variation.
	 * @param mixed                $value   Raw value.
	 * @param array<string, mixed> $fields  All requested fields.
	 * @return array{value?: string, message?: string}
	 */
	private function validate_regular_price( \WC_Product $product, mixed $value, array $fields ): array {
		$parsed = $this->parse_price( $value, false );
		if ( isset( $parsed['message'] ) ) {
			return $parsed;
		}

		$sale = $this->resulting_sale_price( $product, $fields );
		if ( '' !== $sale && 0 <= $this->decimal_compare( $sale, $parsed['value'] ) ) {
			return array(
				'message' => __( 'Sale price must be lower than the regular price.', 'low-product-table-editor' ),
			);
		}

		return $parsed;
	}

	/**
	 * Sale price may be cleared. A value must be lower than the resulting regular price.
	 *
	 * @since 0.1.1
	 *
	 * @param \WC_Product          $product Product or variation.
	 * @param mixed                $value   Raw value.
	 * @param array<string, mixed> $fields  All requested fields.
	 * @return array{value?: string, message?: string}
	 */
	private function validate_sale_price( \WC_Product $product, mixed $value, array $fields ): array {
		$parsed = $this->parse_price( $value, true );
		if ( isset( $parsed['message'] ) || '' === $parsed['value'] ) {
			return $parsed;
		}

		$regular = $this->resulting_regular_price( $product, $fields );
		if ( '' === $regular || 0 <= $this->decimal_compare( $parsed['value'], $regular ) ) {
			return array(
				'message' => __( 'Sale price must be lower than the regular price.', 'low-product-table-editor' ),
			);
		}

		return $parsed;
	}

	/**
	 * Parse a price. An empty regular price is rejected.
	 *
	 * @since 0.1.1
	 *
	 * @param mixed $value   Raw value.
	 * @param bool  $allow_empty Whether an empty string clears the price.
	 * @return array{value?: string, message?: string}
	 */
	private function parse_price( mixed $value, bool $allow_empty ): array {
		if ( ! is_scalar( $value ) ) {
			return array(
				'message' => __( 'Use a number with up to 4 decimal places.', 'low-product-table-editor' ),
			);
		}

		$raw = trim( (string) $value );
		if ( '' === $raw ) {
			if ( $allow_empty ) {
				return array(
					'value' => '',
				);
			}

			return array(
				'message' => __( 'Regular price is required.', 'low-product-table-editor' ),
			);
		}

		if ( ! preg_match( '/^\d+(\.\d{1,4})?$/', $raw ) ) {
			return array(
				'message' => __( 'Use a number with up to 4 decimal places.', 'low-product-table-editor' ),
			);
		}

		return array(
			'value' => $this->format_price( $raw ),
		);
	}

	/**
	 * Regular price after this request is applied.
	 *
	 * @since 0.1.1
	 *
	 * @param \WC_Product          $product Product or variation.
	 * @param array<string, mixed> $fields  All requested fields.
	 * @return string
	 */
	private function resulting_regular_price( \WC_Product $product, array $fields ): string {
		if ( array_key_exists( 'regular_price', $fields ) ) {
			$parsed = $this->parse_price( $fields['regular_price'], false );
			if ( isset( $parsed['value'] ) ) {
				return $parsed['value'];
			}
		}

		return $this->price_value( $product, 'regular' );
	}

	/**
	 * Sale price after this request is applied.
	 *
	 * @since 0.1.1
	 *
	 * @param \WC_Product          $product Product or variation.
	 * @param array<string, mixed> $fields  All requested fields.
	 * @return string
	 */
	private function resulting_sale_price( \WC_Product $product, array $fields ): string {
		if ( array_key_exists( 'sale_price', $fields ) ) {
			$parsed = $this->parse_price( $fields['sale_price'], true );
			if ( isset( $parsed['value'] ) ) {
				return $parsed['value'];
			}
		}

		return $this->price_value( $product, 'sale' );
	}

	/**
	 * Store a price with at least 4 decimal places so 0.3299 is not rounded.
	 *
	 * @since 0.1.1
	 *
	 * @param string $value Price text.
	 * @return string
	 */
	private function format_price( string $value ): string {
		return (string) wc_format_decimal( $value, $this->price_decimals() );
	}

	/**
	 * Shop decimals, but never fewer than 4 while this editor saves a price.
	 *
	 * @since 0.1.1
	 *
	 * @return int
	 */
	private function price_decimals(): int {
		$option = get_option( 'woocommerce_price_num_decimals', 2 );
		$count  = is_numeric( $option ) ? (int) $option : 2;

		return max( 4, $count );
	}

	/**
	 * Compare two decimal strings.
	 *
	 * @since 0.1.1
	 *
	 * @param string $left  Left decimal.
	 * @param string $right Right decimal.
	 * @return int -1, 0, or 1.
	 */
	private function decimal_compare( string $left, string $right ): int {
		$scale = $this->price_decimals();
		$left  = (string) wc_format_decimal( $left, $scale );
		$right = (string) wc_format_decimal( $right, $scale );

		if ( function_exists( 'bccomp' ) ) {
			return bccomp( $left, $right, $scale );
		}

		$left_number  = (float) $left;
		$right_number = (float) $right;
		if ( abs( $left_number - $right_number ) < 0.0000001 ) {
			return 0;
		}

		return ( $left_number < $right_number ) ? -1 : 1;
	}

	/**
	 * Clean a short text field such as SKU.
	 *
	 * @since 0.1.1
	 *
	 * @param mixed $value Raw value.
	 * @return array{value?: string, message?: string}
	 */
	private function validate_text( mixed $value ): array {
		if ( ! is_scalar( $value ) ) {
			return array(
				'message' => __( 'This value is not valid.', 'low-product-table-editor' ),
			);
		}

		return array(
			'value' => wc_clean( (string) $value ),
		);
	}

	/**
	 * Weight is empty or a non-negative decimal.
	 *
	 * @since 0.1.1
	 *
	 * @param mixed $value Raw value.
	 * @return array{value?: string, message?: string}
	 */
	private function validate_weight( mixed $value ): array {
		if ( ! is_scalar( $value ) ) {
			return array(
				'message' => __( 'Weight must be zero or greater.', 'low-product-table-editor' ),
			);
		}

		$raw = trim( (string) $value );
		if ( '' === $raw ) {
			return array(
				'value' => '',
			);
		}

		if ( ! preg_match( '/^\d+(\.\d+)?$/', $raw ) ) {
			return array(
				'message' => __( 'Weight must be zero or greater.', 'low-product-table-editor' ),
			);
		}

		return array(
			'value' => (string) wc_format_decimal( $raw ),
		);
	}

	/**
	 * Stock management. Variations may inherit the parent.
	 *
	 * @since 0.1.1
	 *
	 * @param \WC_Product $product Product or variation.
	 * @param mixed       $value   Raw value.
	 * @return array{value?: bool|string, message?: string}
	 */
	private function validate_manage_stock( \WC_Product $product, mixed $value ): array {
		$normalized = $this->normalize_manage_stock( $product, $value );
		if ( null === $normalized ) {
			$message = $product->is_type( 'variation' )
				? __( 'Choose Yes, No, or Inherit from parent.', 'low-product-table-editor' )
				: __( 'Choose Yes or No.', 'low-product-table-editor' );

			return array(
				'message' => $message,
			);
		}

		if ( 'parent' === $normalized && ! $this->parent_manages_stock( $product ) ) {
			return array(
				'message' => __( 'Inherit from parent is only available when the parent product manages stock.', 'low-product-table-editor' ),
			);
		}

		if ( false === $normalized && $product->is_type( 'variation' ) && $this->parent_manages_stock( $product ) ) {
			return array(
				'message' => __( 'Stock is inherited from the parent. Choose Yes to manage stock on this variation.', 'low-product-table-editor' ),
			);
		}

		return array(
			'value' => $normalized,
		);
	}

	/**
	 * Whether the parent product manages its own stock.
	 *
	 * WooCommerce reports a variation as parent only in that case. The stored
	 * value is still the boolean from set_manage_stock().
	 *
	 * @since 0.1.1
	 *
	 * @param \WC_Product $product Variation.
	 * @return bool
	 */
	private function parent_manages_stock( \WC_Product $product ): bool {
		if ( ! $product->is_type( 'variation' ) ) {
			return false;
		}

		$parent = wc_get_product( $product->get_parent_id() );
		if ( ! $parent instanceof \WC_Product ) {
			return false;
		}

		return true === (bool) $parent->get_manage_stock( 'edit' );
	}

	/**
	 * Normalize a stock-management value, or return null when it is invalid.
	 *
	 * @since 0.1.1
	 *
	 * @param \WC_Product $product Product or variation.
	 * @param mixed       $value   Raw value.
	 * @return bool|string|null
	 */
	private function normalize_manage_stock( \WC_Product $product, mixed $value ): bool|string|null {
		if ( $product->is_type( 'variation' ) && 'parent' === $value ) {
			return 'parent';
		}

		if ( true === $value || 1 === $value || '1' === $value || 'true' === $value || 'yes' === $value ) {
			return true;
		}

		if ( false === $value || 0 === $value || '0' === $value || 'false' === $value || 'no' === $value ) {
			return false;
		}

		return null;
	}

	/**
	 * Quantity is a whole number, and only while stock is managed on this item.
	 *
	 * @since 0.1.1
	 *
	 * @param \WC_Product          $product Product or variation.
	 * @param mixed                $value   Raw value.
	 * @param array<string, mixed> $fields  All requested fields.
	 * @return array{value?: int, message?: string}
	 */
	private function validate_stock_quantity( \WC_Product $product, mixed $value, array $fields ): array {
		if ( true !== $this->resulting_manage_stock( $product, $fields ) ) {
			return array(
				'message' => __( 'Stock quantity can only be set when stock is managed.', 'low-product-table-editor' ),
			);
		}

		if ( is_int( $value ) || ( is_string( $value ) && 1 === preg_match( '/^-?\d+$/', $value ) ) ) {
			return array(
				'value' => (int) $value,
			);
		}

		return array(
			'message' => __( 'Stock quantity must be a whole number.', 'low-product-table-editor' ),
		);
	}

	/**
	 * Stock status is not editable while quantity manages it.
	 *
	 * @since 0.1.1
	 *
	 * @param \WC_Product          $product Product or variation.
	 * @param mixed                $value   Raw value.
	 * @param array<string, mixed> $fields  All requested fields.
	 * @return array{value?: string, message?: string}
	 */
	private function validate_stock_status( \WC_Product $product, mixed $value, array $fields ): array {
		if ( true === $this->resulting_manage_stock( $product, $fields ) ) {
			return array(
				'message' => __( 'Stock status is set from the quantity when stock is managed.', 'low-product-table-editor' ),
			);
		}

		return $this->validate_choice(
			$value,
			array( 'instock', 'outofstock', 'onbackorder' ),
			__( 'Invalid stock status.', 'low-product-table-editor' )
		);
	}

	/**
	 * Stock management after this request is applied.
	 *
	 * @since 0.1.1
	 *
	 * @param \WC_Product          $product Product or variation.
	 * @param array<string, mixed> $fields  All requested fields.
	 * @return bool|string
	 */
	private function resulting_manage_stock( \WC_Product $product, array $fields ): bool|string {
		if ( array_key_exists( 'manage_stock', $fields ) ) {
			$normalized = $this->normalize_manage_stock( $product, $fields['manage_stock'] );
			if ( null !== $normalized ) {
				return $normalized;
			}
		}

		return $this->manage_stock_value( $product );
	}

	/**
	 * Product name is required and limited to 200 characters.
	 *
	 * @since 0.1.1
	 *
	 * @param mixed $value Raw value.
	 * @return array{value?: string, message?: string}
	 */
	private function validate_name( mixed $value ): array {
		if ( ! is_scalar( $value ) ) {
			return array(
				'message' => __( 'Name is required.', 'low-product-table-editor' ),
			);
		}

		$name = wc_clean( (string) $value );
		if ( '' === $name ) {
			return array(
				'message' => __( 'Name is required.', 'low-product-table-editor' ),
			);
		}

		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $name ) : strlen( $name );
		if ( 200 < $length ) {
			return array(
				'message' => __( 'Name must be 200 characters or fewer.', 'low-product-table-editor' ),
			);
		}

		return array(
			'value' => $name,
		);
	}

	/**
	 * One of a fixed list of strings.
	 *
	 * @since 0.1.1
	 *
	 * @param mixed              $value   Raw value.
	 * @param array<int, string> $allowed Allowed values.
	 * @param string             $message Error message.
	 * @return array{value?: string, message?: string}
	 */
	private function validate_choice( mixed $value, array $allowed, string $message ): array {
		if ( ! is_scalar( $value ) || ! in_array( (string) $value, $allowed, true ) ) {
			return array(
				'message' => $message,
			);
		}

		return array(
			'value' => (string) $value,
		);
	}

	/**
	 * Post content, with disallowed markup removed.
	 *
	 * @since 0.1.1
	 *
	 * @param mixed $value Raw value.
	 * @return array{value?: string, message?: string}
	 */
	private function validate_html( mixed $value ): array {
		if ( ! is_scalar( $value ) ) {
			return array(
				'message' => __( 'This value is not valid.', 'low-product-table-editor' ),
			);
		}

		$text  = (string) $value;
		$clean = wp_check_invalid_utf8( $text, true );
		if ( ! is_string( $clean ) || ( '' === $clean && '' !== $text ) ) {
			return array(
				'message' => __( 'This value is not valid.', 'low-product-table-editor' ),
			);
		}

		return array(
			'value' => wp_kses_post( str_replace( "\0", '', $clean ) ),
		);
	}

	/**
	 * Apply values that differ from the stored ones.
	 *
	 * @since 0.1.1
	 *
	 * @param \WC_Product          $product Product or variation.
	 * @param array<string, mixed> $values  Normalized values.
	 * @return bool True when save() should run.
	 */
	private function apply_fields( \WC_Product $product, array $values ): bool {
		$order   = array( 'name', 'status', 'catalog_visibility', 'description', 'short_description', 'sku', 'weight', 'regular_price', 'sale_price', 'manage_stock', 'stock_quantity', 'stock_status' );
		$changed = false;

		foreach ( $order as $field ) {
			if ( ! array_key_exists( $field, $values ) ) {
				continue;
			}

			$next = $values[ $field ];
			if ( $this->field_matches( $field, $this->current_field( $product, $field ), $next ) ) {
				continue;
			}

			$this->set_field( $product, $field, $next );
			$changed = true;
		}

		return $changed;
	}

	/**
	 * Whether the stored value already matches the next value.
	 *
	 * @since 0.1.1
	 *
	 * @param string $field   Field name.
	 * @param mixed  $current Stored value.
	 * @param mixed  $next    Next value.
	 * @return bool
	 */
	private function field_matches( string $field, mixed $current, mixed $next ): bool {
		if ( 'manage_stock' === $field ) {
			return $current === $next;
		}

		if ( 'stock_quantity' === $field ) {
			if ( null === $current || null === $next ) {
				return null === $current && null === $next;
			}

			return (int) $current === (int) $next;
		}

		if ( 'regular_price' === $field || 'sale_price' === $field ) {
			$left  = is_scalar( $current ) ? trim( (string) $current ) : '';
			$right = is_scalar( $next ) ? trim( (string) $next ) : '';
			if ( '' === $left || '' === $right ) {
				return '' === $left && '' === $right;
			}

			return 0 === $this->decimal_compare( $left, $right );
		}

		return (string) $current === (string) $next;
	}

	/**
	 * Normalize one field for the audit log.
	 *
	 * Booleans are yes or no. Inherited stock stays parent. Null stays null.
	 *
	 * @since 0.1.1
	 *
	 * @param string $field Field name.
	 * @param mixed  $value Stored value.
	 * @return string|null
	 */
	private function log_value( string $field, mixed $value ): ?string {
		if ( 'manage_stock' === $field ) {
			if ( 'parent' === $value ) {
				return 'parent';
			}

			return $value ? 'yes' : 'no';
		}

		if ( 'stock_quantity' === $field ) {
			if ( null === $value || '' === $value ) {
				return null;
			}

			return is_scalar( $value ) ? (string) $value : null;
		}

		if ( null === $value ) {
			return null;
		}

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Stored value for one editable field.
	 *
	 * @since 0.1.1
	 *
	 * @param \WC_Product $product Product or variation.
	 * @param string      $field   Field name.
	 * @return mixed
	 */
	private function current_field( \WC_Product $product, string $field ): mixed {
		switch ( $field ) {
			case 'regular_price':
				return $this->price_value( $product, 'regular' );
			case 'sale_price':
				return $this->price_value( $product, 'sale' );
			case 'sku':
				return (string) $product->get_sku( 'edit' );
			case 'weight':
				return (string) $product->get_weight( 'edit' );
			case 'manage_stock':
				return $this->manage_stock_value( $product );
			case 'stock_quantity':
				return $this->stock_quantity_value( $product );
			case 'stock_status':
				return (string) $product->get_stock_status( 'edit' );
			case 'name':
				return (string) $product->get_name();
			case 'status':
				return (string) $product->get_status();
			case 'catalog_visibility':
				return (string) $product->get_catalog_visibility( 'edit' );
			case 'description':
				return (string) $product->get_description( 'edit' );
			case 'short_description':
				return (string) $product->get_short_description( 'edit' );
		}

		return null;
	}

	/**
	 * Write one field through WooCommerce setters.
	 *
	 * @since 0.1.1
	 *
	 * @param \WC_Product $product Product or variation.
	 * @param string      $field   Field name.
	 * @param mixed       $value   Normalized value.
	 * @return void
	 */
	private function set_field( \WC_Product $product, string $field, mixed $value ): void {
		switch ( $field ) {
			case 'regular_price':
				$this->set_price( $product, 'regular', (string) $value );
				break;
			case 'sale_price':
				$this->set_price( $product, 'sale', (string) $value );
				break;
			case 'sku':
				$product->set_sku( (string) $value );
				break;
			case 'weight':
				$product->set_weight( (string) $value );
				break;
			case 'manage_stock':
				$product->set_manage_stock( $value );
				break;
			case 'stock_quantity':
				$product->set_stock_quantity( (int) $value );
				break;
			case 'stock_status':
				$product->set_stock_status( (string) $value );
				break;
			case 'name':
				$product->set_name( (string) $value );
				break;
			case 'status':
				$product->set_status( (string) $value );
				break;
			case 'catalog_visibility':
				$product->set_catalog_visibility( (string) $value );
				break;
			case 'description':
				$product->set_description( (string) $value );
				break;
			case 'short_description':
				$product->set_short_description( (string) $value );
				break;
		}
	}

	/**
	 * Set a price without letting a 2-decimal shop setting round 0.3299.
	 *
	 * @since 0.1.1
	 *
	 * @param \WC_Product $product Product or variation.
	 * @param string      $which   regular or sale.
	 * @param string      $value   Formatted price, or an empty string to clear a sale price.
	 * @return void
	 */
	private function set_price( \WC_Product $product, string $which, string $value ): void {
		$decimals = $this->price_decimals();
		$filter   = static function () use ( $decimals ): int {
			return $decimals;
		};

		add_filter( 'woocommerce_price_num_decimals', $filter, 9999 );
		if ( 'sale' === $which ) {
			$product->set_sale_price( $value );
		} else {
			$product->set_regular_price( $value );
		}
		remove_filter( 'woocommerce_price_num_decimals', $filter, 9999 );
	}

	/**
	 * Parent product IDs matching the grid filters, with no paging.
	 *
	 * @since 0.1.1
	 *
	 * @param array<string, mixed> $filters search, category, type, stock_status, status.
	 * @return array<int, int>
	 */
	public function parent_ids_for_filters( array $filters ): array {
		$search   = $this->search_term( $filters['search'] ?? '' );
		$category = sanitize_title( is_scalar( $filters['category'] ?? '' ) ? (string) $filters['category'] : '' );
		$type     = $this->choice( $filters['type'] ?? '', array( 'simple', 'variable' ) );
		$stock    = $this->choice( $filters['stock_status'] ?? '', array( 'instock', 'outofstock', 'onbackorder' ) );
		$status   = $this->choice( $filters['status'] ?? '', array( 'publish', 'draft', 'private', 'pending' ) );

		$query = array(
			'status'  => '' !== $status ? array( $status ) : array( 'publish', 'draft', 'private', 'pending' ),
			'type'    => '' !== $type ? array( $type ) : array( 'simple', 'variable' ),
			'limit'   => -1,
			'return'  => 'ids',
			'orderby' => 'title',
			'order'   => 'ASC',
		);

		if ( '' !== $category ) {
			$query['category'] = array( $category );
		}

		if ( '' !== $stock ) {
			$query['stock_status'] = $stock;
		}

		if ( '' !== $search ) {
			$include = $this->parent_ids_for_search( $search );
			if ( array() === $include ) {
				return array();
			}
			$query['include'] = $include;
		}

		$result = wc_get_products( $query );
		$ids    = array_map( 'absint', is_array( $result ) ? $result : array() );

		return array_values(
			array_filter(
				$ids,
				static function ( int $id ): bool {
					return $id > 0;
				}
			)
		);
	}

	/**
	 * Variations belonging to the given parents, in menu order.
	 *
	 * @since 0.1.1
	 *
	 * @param array<int, int> $parent_ids Parent product IDs.
	 * @return array<int, int>
	 */
	public function variation_ids_for_parent_products( array $parent_ids ): array {
		return $this->variation_ids_for_parents( $parent_ids );
	}

	/**
	 * Prime post caches for a bulk read.
	 *
	 * @since 0.1.1
	 *
	 * @param array<int, int> $ids Product and variation IDs.
	 * @return void
	 */
	public function prime_bulk_caches( array $ids ): void {
		$this->prime_caches( $ids );
	}

	/**
	 * Prime Sold By term names for a set of variations.
	 *
	 * @since 0.1.1
	 *
	 * @param array<int, \WC_Product> $products Variations.
	 * @return void
	 */
	public function prime_bulk_attributes( array $products ): void {
		$this->prime_attribute_terms( $products );
	}

	/**
	 * Name, Sold By, and raw prices for one priced item.
	 *
	 * @since 0.1.1
	 *
	 * @param \WC_Product $product Simple product or variation.
	 * @return array<string, mixed>
	 */
	public function bulk_display( \WC_Product $product ): array {
		$details = $this->variation_details( $product );
		$name    = $details['name'];
		if ( $product->is_type( 'variation' ) && '' !== $details['variation_label'] ) {
			$name .= ' ' . $details['variation_label'];
		}

		return array(
			'id'            => $product->get_id(),
			'parent_id'     => $product->is_type( 'variation' ) ? $product->get_parent_id() : 0,
			'name'          => $name,
			'sold_by'       => $details['sold_by'],
			'regular_price' => $this->price_value( $product, 'regular' ),
			'sale_price'    => $this->price_value( $product, 'sale' ),
			'type'          => $product->get_type(),
		);
	}

	/**
	 * Store one price through the CRUD setter, keeping 4 decimal places.
	 *
	 * @since 0.1.1
	 *
	 * @param \WC_Product $product Product or variation.
	 * @param string      $which   regular or sale.
	 * @param string      $value   Price text.
	 * @return void
	 */
	public function assign_price( \WC_Product $product, string $which, string $value ): void {
		$this->set_price( $product, $which, $value );
	}

	/**
	 * Save a product and put menu_order back if WooCommerce rewrites it.
	 *
	 * A variation save updates the post because date_modified changes, and
	 * that update stores menu_order from the in-memory product. The default
	 * is 0, so a save can move a variation to the front of the list.
	 *
	 * @since 0.1.1
	 *
	 * @param \WC_Product $product Product or variation.
	 * @return void
	 */
	public function save_product( \WC_Product $product ): void {
		$orders     = $this->menu_orders_for_product( $product );
		$product_id = $product->get_id();
		if ( isset( $orders[ $product_id ] ) ) {
			$product->set_menu_order( $orders[ $product_id ] );
		}
		$product->save();
		$this->restore_menu_orders( $orders );
	}

	/**
	 * Stored menu_order for a parent and its variations.
	 *
	 * @since 0.1.1
	 *
	 * @param array<int, int> $parent_ids Parent product IDs.
	 * @return array<int, int>
	 */
	public function menu_orders_for_parents( array $parent_ids ): array {
		$ids = array();
		foreach ( $parent_ids as $parent_id ) {
			$parent_id = (int) $parent_id;
			if ( $parent_id < 1 ) {
				continue;
			}
			$ids[] = $parent_id;
			$ids   = array_merge( $ids, $this->variation_ids_for_parent_products( array( $parent_id ) ) );
		}
		return $this->menu_orders_for_posts( $ids );
	}

	/**
	 * Write menu_order values back when a save changed them.
	 *
	 * @since 0.1.1
	 *
	 * @param array<int, int> $orders Post ID to menu_order.
	 * @return void
	 */
	public function restore_menu_orders( array $orders ): void {
		foreach ( $orders as $post_id => $menu_order ) {
			$this->restore_menu_order( (int) $post_id, (int) $menu_order );
		}
	}

	/**
	 * Menu orders for one product and, for a variation, its family.
	 *
	 * The first lookup in a request is kept so a later save cannot treat a
	 * value this request already changed as the original.
	 *
	 * @param \WC_Product $product Product or variation.
	 * @return array<int, int>
	 */
	private function menu_orders_for_product( \WC_Product $product ): array {
		$product_id = $product->get_id();
		$parent_id  = 0;
		if ( $product->is_type( 'variation' ) ) {
			$parent_id = $product->get_parent_id();
		} elseif ( $product->is_type( 'variable' ) ) {
			$parent_id = $product_id;
		}

		if ( $parent_id < 1 ) {
			return $this->menu_orders_for_posts( array( $product_id ) );
		}

		if ( ! isset( $this->menu_order_snapshot[ $parent_id ] ) ) {
			$ids                                     = array_merge(
				array( $parent_id, $product_id ),
				$this->variation_ids_for_parent_products( array( $parent_id ) )
			);
			$this->menu_order_snapshot[ $parent_id ] = $this->menu_orders_for_posts( $ids );
		}

		return $this->menu_order_snapshot[ $parent_id ];
	}

	/**
	 * Read menu_order from the posts table.
	 *
	 * @param array<int, int> $ids Post IDs.
	 * @return array<int, int>
	 */
	private function menu_orders_for_posts( array $ids ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( array() === $ids ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One read of menu_order so a product save can restore it.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, menu_order FROM {$wpdb->posts} WHERE ID IN ( {$placeholders} )",
				...$ids
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$orders = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$orders[ (int) $row['ID'] ] = (int) $row['menu_order'];
		}
		return $orders;
	}

	/**
	 * Restore one menu_order when the stored value differs.
	 *
	 * @param int $post_id    Post ID.
	 * @param int $menu_order Original menu_order.
	 * @return void
	 */
	private function restore_menu_order( int $post_id, int $menu_order ): void {
		global $wpdb;

		if ( $post_id < 1 ) {
			return;
		}

		$current = $this->menu_orders_for_posts( array( $post_id ) );
		if ( ! isset( $current[ $post_id ] ) || $current[ $post_id ] === $menu_order ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Restore only menu_order. A product save must not change it.
		$wpdb->update(
			$wpdb->posts,
			array( 'menu_order' => $menu_order ),
			array( 'ID' => $post_id ),
			array( '%d' ),
			array( '%d' )
		);
		clean_post_cache( $post_id );
	}
}
