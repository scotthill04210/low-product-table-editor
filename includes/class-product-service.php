<?php
/**
 * Read-only product queries for the table editor.
 *
 * @package LOW\ProductTableEditor
 */

declare( strict_types=1 );

namespace LOW\ProductTableEditor;

defined( 'ABSPATH' ) || exit;

/**
 * Loads parent products and their variations in batched queries.
 *
 * @since 0.1.0
 */
class Product_Service {

	/**
	 * Term names keyed by taxonomy and slug.
	 *
	 * @var array<string, string>
	 */
	private static array $term_names = array();

	/**
	 * Attribute labels keyed by taxonomy or attribute slug.
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
			$children_by_parent[ $parent_id ][] = $this->to_row( $variation );
		}

		$items = array();
		foreach ( $parent_ids as $parent_id ) {
			$parent = wc_get_product( $parent_id );
			if ( ! $parent instanceof \WC_Product ) {
				continue;
			}
			$children = $children_by_parent[ $parent_id ] ?? array();
			$row      = $this->to_row( $parent, count( $children ) );
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
	private function to_row( \WC_Product $product, int $variation_count = 0 ): array {
		$is_variation = $product->is_type( 'variation' );
		$is_variable  = $product->is_type( 'variable' );
		$link         = get_edit_post_link( $product->get_id(), 'raw' );

		return array(
			'id'                  => $product->get_id(),
			'parent_id'           => $is_variation ? $product->get_parent_id() : 0,
			'type'                => $product->get_type(),
			'name'                => $product->get_name(),
			'sold_by'             => $is_variation ? $this->sold_by( $product ) : '',
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
		$value = $product->get_manage_stock( 'edit' );
		if ( $product->is_type( 'variation' ) && 'parent' === $value ) {
			return 'parent';
		}

		return (bool) $value;
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
	 * Unix timestamp for conflict checks in a later phase.
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
	 * Sold By attribute, or every attribute value when that attribute is absent.
	 *
	 * @since 0.1.0
	 *
	 * @param \WC_Product $product Variation.
	 * @return string
	 */
	private function sold_by( \WC_Product $product ): string {
		if ( ! $product->is_type( 'variation' ) ) {
			return '';
		}

		$attributes = $product->get_variation_attributes();
		if ( ! is_array( $attributes ) || array() === $attributes ) {
			return '';
		}

		/**
		 * Filters the attribute label that fills the Sold By column.
		 *
		 * @since 0.1.0
		 *
		 * @param string $label Attribute label. Default "Sold By".
		 */
		$label = apply_filters( 'low_pte_sold_by_label', 'Sold By' );
		if ( ! is_string( $label ) || '' === $label ) {
			$label = 'Sold By';
		}

		$wanted  = strtolower( $label );
		$values  = array();
		$sold_by = null;

		foreach ( $attributes as $key => $value ) {
			$name    = $this->attribute_label( (string) $key );
			$display = $this->attribute_value_text( (string) $key, is_scalar( $value ) ? (string) $value : '' );
			if ( null === $sold_by && strtolower( $name ) === $wanted ) {
				$sold_by = $display;
			}
			if ( '' !== $display ) {
				$values[] = $display;
			}
		}

		if ( null !== $sold_by ) {
			return $sold_by;
		}

		return implode( ' / ', $values );
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
	 * @since 0.1.0
	 *
	 * @param string $key Attribute key.
	 * @return string
	 */
	private function attribute_label( string $key ): string {
		$taxonomy = $this->attribute_taxonomy( $key );
		if ( isset( self::$attribute_labels[ $taxonomy ] ) ) {
			return self::$attribute_labels[ $taxonomy ];
		}

		$label = function_exists( 'wc_attribute_label' ) ? wc_attribute_label( $taxonomy ) : $taxonomy;
		if ( ! is_string( $label ) || '' === $label ) {
			$label = $taxonomy;
		}

		self::$attribute_labels[ $taxonomy ] = $label;

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
}
