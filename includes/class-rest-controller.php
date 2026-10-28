<?php
/**
 * REST routes for the table editor.
 *
 * @package LOW\ProductTableEditor
 */

declare( strict_types=1 );

namespace LOW\ProductTableEditor;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only products route.
 *
 * @since 0.1.0
 */
class Rest_Controller {

	/**
	 * Product query service.
	 *
	 * @var Product_Service
	 */
	private Product_Service $products;

	/**
	 * Query count at the start of a plugin REST request.
	 *
	 * @var int
	 */
	private int $query_start = 0;

	/**
	 * Microtime at the start of a plugin REST request.
	 *
	 * @var float
	 */
	private float $time_start = 0.0;

	/**
	 * Bind the product service.
	 *
	 * @since 0.1.0
	 *
	 * @param Product_Service $products Product query service.
	 */
	public function __construct( Product_Service $products ) {
		$this->products = $products;
	}

	/**
	 * Register REST hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_pre_dispatch', array( $this, 'mark_request_start' ), 10, 3 );
		add_filter( 'rest_post_dispatch', array( $this, 'send_cache_headers' ), 10, 3 );
	}

	/**
	 * Register GET /products.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			'low-pte/v1',
			'/products',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_products' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => $this->query_args(),
			)
		);
	}

	/**
	 * Return one page of products.
	 *
	 * @since 0.1.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_products( \WP_REST_Request $request ): \WP_REST_Response {
		$data = $this->products->get_products(
			array(
				'page'         => $request->get_param( 'page' ),
				'per_page'     => $request->get_param( 'per_page' ),
				'search'       => $request->get_param( 'search' ),
				'category'     => $request->get_param( 'category' ),
				'type'         => $request->get_param( 'type' ),
				'stock_status' => $request->get_param( 'stock_status' ),
				'status'       => $request->get_param( 'status' ),
			)
		);

		return rest_ensure_response( $data );
	}

	/**
	 * Shop managers and administrators only.
	 *
	 * @since 0.1.0
	 *
	 * @return true|\WP_Error
	 */
	public function permissions_check(): bool|\WP_Error {
		if ( current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
			return true;
		}

		return new \WP_Error(
			'low_pte_forbidden',
			__( 'Sorry, you are not allowed to view products.', 'low-product-table-editor' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Record the query count and start time for plugin routes.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed            $result  Response to short-circuit, or null.
	 * @param \WP_REST_Server  $server  Server instance.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function mark_request_start( mixed $result, \WP_REST_Server $server, \WP_REST_Request $request ): mixed {
		unset( $server );

		if ( null !== $result || ! $this->is_plugin_route( $request ) ) {
			return $result;
		}

		global $wpdb;

		$this->query_start = isset( $wpdb->num_queries ) ? (int) $wpdb->num_queries : 0;
		$this->time_start  = microtime( true );

		return $result;
	}

	/**
	 * Keep plugin responses out of caches, and expose query timing when debugging.
	 *
	 * @since 0.1.0
	 *
	 * @param \WP_HTTP_Response $result  Response.
	 * @param \WP_REST_Server   $server  Server instance.
	 * @param \WP_REST_Request  $request Request.
	 * @return \WP_HTTP_Response
	 */
	public function send_cache_headers( \WP_HTTP_Response $result, \WP_REST_Server $server, \WP_REST_Request $request ): \WP_HTTP_Response {
		unset( $server );

		if ( ! $this->is_plugin_route( $request ) || ! $result instanceof \WP_REST_Response ) {
			return $result;
		}

		$result->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private' );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && $this->time_start > 0 ) {
			global $wpdb;

			$queries = isset( $wpdb->num_queries ) ? (int) $wpdb->num_queries : 0;
			$delta   = max( 0, $queries - $this->query_start );
			$elapsed = ( microtime( true ) - $this->time_start ) * 1000;

			$result->header( 'X-LOW-PTE-Queries', (string) $delta );
			$result->header( 'Server-Timing', 'total;dur=' . (string) round( $elapsed, 1 ) );
		}

		return $result;
	}

	/**
	 * Whether the request targets this plugin's namespace.
	 *
	 * @since 0.1.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	private function is_plugin_route( \WP_REST_Request $request ): bool {
		$route = $request->get_route();

		return is_string( $route ) && str_starts_with( $route, '/low-pte/v1' );
	}

	/**
	 * Query argument schema.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function query_args(): array {
		return array(
			'page'         => array(
				'description'       => __( 'Page of parent products.', 'low-product-table-editor' ),
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => array( $this, 'sanitize_page' ),
				'validate_callback' => array( $this, 'validate_page' ),
			),
			'per_page'     => array(
				'description'       => __( 'Parent products per page.', 'low-product-table-editor' ),
				'type'              => 'integer',
				'default'           => 25,
				'enum'              => array( 10, 25, 50, 100 ),
				'sanitize_callback' => array( $this, 'sanitize_per_page' ),
				'validate_callback' => array( $this, 'validate_per_page' ),
			),
			'search'       => array(
				'description'       => __( 'Search names, SKUs, and variation attribute values.', 'low-product-table-editor' ),
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => array( $this, 'sanitize_search' ),
				'validate_callback' => array( $this, 'validate_search' ),
			),
			'category'     => array(
				'description'       => __( 'Product category slug.', 'low-product-table-editor' ),
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => array( $this, 'sanitize_category' ),
				'validate_callback' => array( $this, 'validate_category' ),
			),
			'type'         => array(
				'description'       => __( 'Product type.', 'low-product-table-editor' ),
				'type'              => 'string',
				'default'           => '',
				'enum'              => array( '', 'simple', 'variable' ),
				'sanitize_callback' => array( $this, 'sanitize_text' ),
				'validate_callback' => array( $this, 'validate_type' ),
			),
			'stock_status' => array(
				'description'       => __( 'Stock status.', 'low-product-table-editor' ),
				'type'              => 'string',
				'default'           => '',
				'enum'              => array( '', 'instock', 'outofstock', 'onbackorder' ),
				'sanitize_callback' => array( $this, 'sanitize_text' ),
				'validate_callback' => array( $this, 'validate_stock_status' ),
			),
			'status'       => array(
				'description'       => __( 'Post status.', 'low-product-table-editor' ),
				'type'              => 'string',
				'default'           => '',
				'enum'              => array( '', 'publish', 'draft', 'private', 'pending' ),
				'sanitize_callback' => array( $this, 'sanitize_text' ),
				'validate_callback' => array( $this, 'validate_status' ),
			),
		);
	}

	/**
	 * Sanitize a page number.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public function sanitize_page( mixed $value ): int {
		if ( is_array( $value ) || is_object( $value ) || ! is_numeric( $value ) ) {
			return 0;
		}

		return absint( $value );
	}

	/**
	 * Require a page number of at least 1.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Sanitized value.
	 * @return true|\WP_Error
	 */
	public function validate_page( mixed $value ): bool|\WP_Error {
		if ( is_numeric( $value ) && (int) $value >= 1 ) {
			return true;
		}

		return $this->invalid_param();
	}

	/**
	 * Sanitize a page size.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public function sanitize_per_page( mixed $value ): int {
		if ( is_array( $value ) || is_object( $value ) || ! is_numeric( $value ) ) {
			return 0;
		}

		return absint( $value );
	}

	/**
	 * Require a supported page size.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Sanitized value.
	 * @return true|\WP_Error
	 */
	public function validate_per_page( mixed $value ): bool|\WP_Error {
		if ( in_array( (int) $value, array( 10, 25, 50, 100 ), true ) ) {
			return true;
		}

		return $this->invalid_param();
	}

	/**
	 * Sanitize search text without truncating it.
	 *
	 * Length is enforced by validate_search() so an over-long value is rejected.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_search( mixed $value ): string {
		return $this->sanitize_text( $value );
	}

	/**
	 * Require search text of at most 100 characters.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Sanitized value.
	 * @return true|\WP_Error
	 */
	public function validate_search( mixed $value ): bool|\WP_Error {
		if ( ! is_string( $value ) ) {
			return $this->invalid_param();
		}

		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
		if ( $length > 100 ) {
			return $this->invalid_param();
		}

		return true;
	}

	/**
	 * Sanitize a category slug.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_category( mixed $value ): string {
		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}

		return sanitize_title( (string) $value );
	}

	/**
	 * Require an empty category or a slug.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Sanitized value.
	 * @return true|\WP_Error
	 */
	public function validate_category( mixed $value ): bool|\WP_Error {
		if ( ! is_string( $value ) ) {
			return $this->invalid_param();
		}

		if ( '' === $value ) {
			return true;
		}

		if ( strlen( $value ) > 200 ) {
			return $this->invalid_param();
		}

		if ( (bool) preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value ) ) {
			return true;
		}

		return $this->invalid_param();
	}

	/**
	 * Sanitize a short text parameter.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_text( mixed $value ): string {
		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}

		return sanitize_text_field( (string) $value );
	}

	/**
	 * Require an empty type or simple or variable.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Sanitized value.
	 * @return true|\WP_Error
	 */
	public function validate_type( mixed $value ): bool|\WP_Error {
		return $this->validate_choice( $value, array( '', 'simple', 'variable' ) );
	}

	/**
	 * Require an empty stock status or a WooCommerce stock status.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Sanitized value.
	 * @return true|\WP_Error
	 */
	public function validate_stock_status( mixed $value ): bool|\WP_Error {
		return $this->validate_choice( $value, array( '', 'instock', 'outofstock', 'onbackorder' ) );
	}

	/**
	 * Require an empty status or a supported post status.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Sanitized value.
	 * @return true|\WP_Error
	 */
	public function validate_status( mixed $value ): bool|\WP_Error {
		return $this->validate_choice( $value, array( '', 'publish', 'draft', 'private', 'pending' ) );
	}

	/**
	 * Require a value from a fixed list.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed              $value   Sanitized value.
	 * @param array<int, string> $allowed Allowed values.
	 * @return true|\WP_Error
	 */
	private function validate_choice( mixed $value, array $allowed ): bool|\WP_Error {
		if ( is_string( $value ) && in_array( $value, $allowed, true ) ) {
			return true;
		}

		return $this->invalid_param();
	}

	/**
	 * Generic invalid parameter error.
	 *
	 * @since 0.1.0
	 *
	 * @return \WP_Error
	 */
	private function invalid_param(): \WP_Error {
		return new \WP_Error(
			'rest_invalid_param',
			__( 'Invalid parameter.', 'low-product-table-editor' ),
			array( 'status' => 400 )
		);
	}
}
