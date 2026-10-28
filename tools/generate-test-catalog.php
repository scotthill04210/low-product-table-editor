<?php
/**
 * Create or remove a throwaway catalog for load tests.
 *
 * Usage:
 *   wp eval-file tools/generate-test-catalog.php
 *   wp eval-file tools/generate-test-catalog.php 40 30
 *   wp eval-file tools/generate-test-catalog.php cleanup
 *
 * The first form creates 40 draft variable products with 30 variations each.
 * Cleanup deletes only posts flagged with the _low_pte_test meta.
 * The script refuses to run unless the environment is local, development, or staging.
 *
 * @package LOW\ProductTableEditor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

$low_pte_environment = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : '';
if ( ! in_array( $low_pte_environment, array( 'local', 'development', 'staging' ), true ) ) {
	WP_CLI::error( 'Refusing to change the catalog outside local, development, or staging.' );
}

if ( ! class_exists( 'WooCommerce' ) ) {
	WP_CLI::error( 'WooCommerce is not active.' );
}

/**
 * Delete products and variations created by this script.
 *
 * @return int
 */
function low_pte_delete_test_catalog(): int {
	$ids = get_posts(
		array(
			'post_type'      => array( 'product', 'product_variation' ),
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => '_low_pte_test',
			'meta_value'     => '1',
		)
	);

	$deleted = 0;
	foreach ( $ids as $id ) {
		if ( wp_delete_post( (int) $id, true ) ) {
			++$deleted;
		}
	}

	return $deleted;
}

$low_pte_args    = isset( $args ) && is_array( $args ) ? $args : array();
$low_pte_command = isset( $low_pte_args[0] ) ? (string) $low_pte_args[0] : '';
if ( 'cleanup' === $low_pte_command ) {
	$low_pte_deleted = low_pte_delete_test_catalog();
	WP_CLI::success( 'Deleted ' . $low_pte_deleted . ' test posts.' );
	return;
}

$low_pte_parents    = isset( $low_pte_args[0] ) ? max( 1, (int) $low_pte_args[0] ) : 40;
$low_pte_variations = isset( $low_pte_args[1] ) ? max( 1, (int) $low_pte_args[1] ) : 30;
$low_pte_labels     = array( 'Roll', 'Case', 'Each', 'Square Foot', 'Foot' );

$low_pte_attribute_id = 0;
foreach ( wc_get_attribute_taxonomies() as $low_pte_attribute ) {
	if ( 'sold-by' === $low_pte_attribute->attribute_name || 'Sold By' === $low_pte_attribute->attribute_label ) {
		$low_pte_attribute_id = (int) $low_pte_attribute->attribute_id;
		break;
	}
}

if ( $low_pte_attribute_id < 1 ) {
	$low_pte_created_attribute = wc_create_attribute(
		array(
			'name'         => 'Sold By',
			'slug'         => 'sold-by',
			'type'         => 'select',
			'order_by'     => 'menu_order',
			'has_archives' => false,
		)
	);
	if ( is_wp_error( $low_pte_created_attribute ) ) {
		WP_CLI::error( $low_pte_created_attribute->get_error_message() );
	}
	$low_pte_attribute_id = (int) $low_pte_created_attribute;
	delete_transient( 'wc_attribute_taxonomies' );
}

$low_pte_taxonomy = 'pa_sold-by';
if ( ! taxonomy_exists( $low_pte_taxonomy ) ) {
	register_taxonomy(
		$low_pte_taxonomy,
		array( 'product' ),
		array(
			'hierarchical' => false,
			'label'        => 'Sold By',
			'query_var'    => true,
			'rewrite'      => false,
		)
	);
}

$low_pte_term_ids = array();
foreach ( $low_pte_labels as $low_pte_label ) {
	$low_pte_term = term_exists( $low_pte_label, $low_pte_taxonomy );
	if ( ! $low_pte_term ) {
		$low_pte_term = wp_insert_term( $low_pte_label, $low_pte_taxonomy );
	}
	if ( is_wp_error( $low_pte_term ) ) {
		WP_CLI::error( $low_pte_term->get_error_message() );
	}
	$low_pte_term_ids[] = (int) ( is_array( $low_pte_term ) ? $low_pte_term['term_id'] : $low_pte_term );
}

$low_pte_started = microtime( true );
for ( $low_pte_parent_index = 1; $low_pte_parent_index <= $low_pte_parents; $low_pte_parent_index++ ) {
	$low_pte_product = new WC_Product_Variable();
	$low_pte_product->set_name( 'LOW PTE test ' . $low_pte_parent_index );
	$low_pte_product->set_status( 'draft' );
	$low_pte_product->set_catalog_visibility( 'hidden' );
	$low_pte_product->set_sku( 'LOWPTE-T-' . $low_pte_parent_index );

	$low_pte_product_attribute = new WC_Product_Attribute();
	$low_pte_product_attribute->set_id( $low_pte_attribute_id );
	$low_pte_product_attribute->set_name( $low_pte_taxonomy );
	$low_pte_product_attribute->set_options( $low_pte_term_ids );
	$low_pte_product_attribute->set_visible( true );
	$low_pte_product_attribute->set_variation( true );
	$low_pte_product->set_attributes( array( $low_pte_product_attribute ) );

	$low_pte_parent_id = $low_pte_product->save();
	update_post_meta( $low_pte_parent_id, '_low_pte_test', '1' );
	wp_set_object_terms( $low_pte_parent_id, $low_pte_term_ids, $low_pte_taxonomy );

	for ( $low_pte_child_index = 1; $low_pte_child_index <= $low_pte_variations; $low_pte_child_index++ ) {
		$low_pte_label    = $low_pte_labels[ ( $low_pte_child_index - 1 ) % count( $low_pte_labels ) ];
		$low_pte_slug     = sanitize_title( $low_pte_label );
		$low_pte_price    = ( 0 === $low_pte_child_index % 3 ) ? '0.3299' : (string) ( 10 + $low_pte_child_index );
		$low_pte_variation = new WC_Product_Variation();
		$low_pte_variation->set_parent_id( $low_pte_parent_id );
		$low_pte_variation->set_status( 'publish' );
		$low_pte_variation->set_sku( 'LOWPTE-T-' . $low_pte_parent_index . '-' . $low_pte_child_index );
		$low_pte_variation->set_regular_price( $low_pte_price );
		$low_pte_variation->set_attributes( array( $low_pte_taxonomy => $low_pte_slug ) );

		if ( 0 === $low_pte_child_index % 4 ) {
			$low_pte_variation->set_sale_price( '0.2500' );
		}

		if ( 0 === $low_pte_child_index % 5 ) {
			$low_pte_variation->set_manage_stock( 'parent' );
		} elseif ( 0 === $low_pte_child_index % 2 ) {
			$low_pte_variation->set_manage_stock( true );
			$low_pte_variation->set_stock_quantity( $low_pte_child_index );
		} else {
			$low_pte_variation->set_manage_stock( false );
			$low_pte_variation->set_stock_status( 'instock' );
		}

		$low_pte_variation_id = $low_pte_variation->save();
		update_post_meta( $low_pte_variation_id, '_low_pte_test', '1' );
	}

	WC_Product_Variable::sync( $low_pte_parent_id );
}

$low_pte_seconds = round( microtime( true ) - $low_pte_started, 2 );
WP_CLI::success(
	'Created ' . $low_pte_parents . ' draft variable products with ' . ( $low_pte_parents * $low_pte_variations ) . ' variations in ' . $low_pte_seconds . ' seconds. Peak memory ' . size_format( memory_get_peak_usage( true ) ) . '.'
);
