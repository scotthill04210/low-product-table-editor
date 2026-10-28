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
 * Product list and edit routes.
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
	 * Bulk price actions.
	 *
	 * @var Bulk_Service
	 */
	private Bulk_Service $bulk;

	/**
	 * Bind the product service.
	 *
	 * @since 0.1.0
	 *
	 * @param Product_Service $products Product query service.
	 */
	public function __construct( Product_Service $products ) {
		$this->products = $products;
		$this->bulk     = new Bulk_Service( $products );
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
	 * Register the product routes.
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

		register_rest_route(
			'low-pte/v1',
			'/items/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'id' => array(
						'description' => __( 'Product or variation ID.', 'low-product-table-editor' ),
						'type'        => 'integer',
						'required'    => true,
					),
				),
			)
		);

		register_rest_route(
			'low-pte/v1',
			'/items',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( $this, 'update_items' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			'low-pte/v1',
			'/log',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_log' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			'low-pte/v1',
			'/bulk/preview',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'preview_bulk' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			'low-pte/v1',
			'/bulk/apply',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'apply_bulk' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			'low-pte/v1',
			'/undo/(?P<batch_id>[0-9a-fA-F-]{36})',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'undo_batch' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'batch_id' => array(
						'description'       => __( 'Batch to undo.', 'low-product-table-editor' ),
						'type'              => 'string',
						'required'          => true,
						'validate_callback' => array( $this, 'validate_batch_id' ),
					),
				),
			)
		);

		register_rest_route(
			'low-pte/v1',
			'/nonce',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_nonce' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}

	/**
	 * Return a fresh REST nonce for the current user.
	 *
	 * @since 1.0.0
	 *
	 * @return \WP_REST_Response
	 */
	public function get_nonce(): \WP_REST_Response {
		$response = rest_ensure_response(
			array(
				'nonce' => wp_create_nonce( 'wp_rest' ),
			)
		);
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private' );

		return $response;
	}

	/**
	 * Accept a version 4 UUID.
	 *
	 * @since 0.1.1
	 *
	 * @param mixed $value Candidate batch id.
	 * @return bool
	 */
	public function validate_batch_id( mixed $value ): bool {
		if ( ! is_string( $value ) ) {
			return false;
		}

		return 1 === preg_match(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			strtolower( $value )
		);
	}

	/**
	 * Undo one batch. Anything changed since the edit is left alone.
	 *
	 * @since 0.1.1
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function undo_batch( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$batch_id = strtolower( (string) $request->get_param( 'batch_id' ) );
		$result   = $this->products->undo_batch( $batch_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Preview a bulk price action without writing.
	 *
	 * @since 0.1.1
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function preview_bulk( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || ! isset( $body['selection'], $body['action'] ) || ! is_array( $body['selection'] ) || ! is_array( $body['action'] ) ) {
			return new \WP_Error(
				'rest_invalid_param',
				__( 'A selection and an action are required.', 'low-product-table-editor' ),
				array( 'status' => 400 )
			);
		}

		$result = $this->bulk->preview( $body['selection'], $body['action'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Apply one chunk of a bulk price action.
	 *
	 * @since 0.1.1
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function apply_bulk( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || ! isset( $body['selection'], $body['action'] ) || ! is_array( $body['selection'] ) || ! is_array( $body['action'] ) ) {
			return new \WP_Error(
				'rest_invalid_param',
				__( 'A selection and an action are required.', 'low-product-table-editor' ),
				array( 'status' => 400 )
			);
		}

		$batch_id = isset( $body['batch_id'] ) && is_string( $body['batch_id'] ) ? strtolower( trim( $body['batch_id'] ) ) : '';
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $batch_id ) ) {
			return new \WP_Error(
				'rest_invalid_param',
				__( 'Batch ID is not valid.', 'low-product-table-editor' ),
				array( 'status' => 400 )
			);
		}

		$chunk_index = $body['chunk_index'] ?? null;
		$chunk_total = $body['chunk_total'] ?? null;
		if ( ! is_int( $chunk_index ) || ! is_int( $chunk_total ) || $chunk_index < 0 || $chunk_total < 1 || $chunk_index >= $chunk_total ) {
			return new \WP_Error(
				'rest_invalid_param',
				__( 'Chunk index is not valid.', 'low-product-table-editor' ),
				array( 'status' => 400 )
			);
		}

		$selection = $body['selection'];
		$mode      = isset( $selection['mode'] ) ? (string) $selection['mode'] : '';
		$ids       = isset( $selection['ids'] ) && is_array( $selection['ids'] ) ? $selection['ids'] : array();
		if ( 'ids' !== $mode ) {
			return new \WP_Error(
				'rest_invalid_param',
				__( 'Apply a list of product IDs.', 'low-product-table-editor' ),
				array( 'status' => 400 )
			);
		}

		if ( 25 < count( $ids ) ) {
			return new \WP_Error(
				'low_pte_too_many_items',
				__( 'A bulk request can include at most 25 items.', 'low-product-table-editor' ),
				array( 'status' => 400 )
			);
		}

		$repository = new Log_Repository();
		if ( 'foreign' === $repository->bulk_batch_status( $batch_id, get_current_user_id() ) ) {
			return new \WP_Error(
				'low_pte_batch_forbidden',
				__( 'This batch belongs to another change.', 'low-product-table-editor' ),
				array( 'status' => 403 )
			);
		}

		$result = $this->bulk->apply( $batch_id, $ids, $body['action'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Return one page of log batches, or the entries in one batch.
	 *
	 * @since 0.1.1
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_log( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$batch_id = $request->get_param( 'batch_id' );
		$batch_id = is_string( $batch_id ) ? trim( $batch_id ) : '';

		if ( '' !== $batch_id ) {
			if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $batch_id ) ) {
				return new \WP_Error(
					'rest_invalid_param',
					__( 'Batch ID is not valid.', 'low-product-table-editor' ),
					array( 'status' => 400 )
				);
			}

			$repository = new Log_Repository();

			return rest_ensure_response(
				array(
					'batch_id' => $batch_id,
					'entries'  => $repository->get_batch_entries( $batch_id ),
				)
			);
		}

		$date_from = $this->date_param( $request, 'date_from' );
		$date_to   = $this->date_param( $request, 'date_to' );

		if ( is_wp_error( $date_from ) ) {
			return $date_from;
		}

		if ( is_wp_error( $date_to ) ) {
			return $date_to;
		}

		$per_page = (int) $request->get_param( 'per_page' );
		if ( ! in_array( $per_page, array( 10, 20, 50 ), true ) ) {
			$per_page = 20;
		}

		$page    = max( 1, (int) $request->get_param( 'page' ) );
		$user_id = absint( $request->get_param( 'user' ) );
		$product = $request->get_param( 'product' );
		$product = is_scalar( $product ) ? trim( (string) $product ) : '';

		$filters = array(
			'user_id'   => $user_id,
			'date_from' => $date_from,
			'date_to'   => $date_to,
			'page'      => $page,
			'per_page'  => $per_page,
		);

		if ( '' !== $product ) {
			$product_ids = $this->products->ids_for_log_filter( $product );
			if ( array() === $product_ids ) {
				$repository = new Log_Repository();

				return rest_ensure_response(
					array(
						'batches'  => array(),
						'total'    => 0,
						'page'     => $page,
						'per_page' => $per_page,
						'users'    => $repository->users(),
					)
				);
			}
			$filters['product_ids'] = $product_ids;
		}

		$repository       = new Log_Repository();
		$data             = $repository->get_batches( $filters );
		$data['page']     = $page;
		$data['per_page'] = $per_page;

		return rest_ensure_response( $data );
	}

	/**
	 * Validate an optional Y-m-d query value.
	 *
	 * @since 0.1.1
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string           $key     Parameter name.
	 * @return string|\WP_Error Empty string when the parameter is omitted.
	 */
	private function date_param( \WP_REST_Request $request, string $key ): string|\WP_Error {
		$value = $request->get_param( $key );
		if ( null === $value || '' === $value ) {
			return '';
		}

		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		$parts = preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches ) ? $matches : array();

		if ( array() === $parts || ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) {
			return new \WP_Error(
				'rest_invalid_param',
				__( 'Use dates in YYYY-MM-DD format.', 'low-product-table-editor' ),
				array( 'status' => 400 )
			);
		}

		return $value;
	}

	/**
	 * Return one product or variation, including description fields.
	 *
	 * @since 0.1.1
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id  = (int) $request->get_param( 'id' );
		$row = $this->products->get_item( $id );

		if ( null === $row ) {
			return new \WP_Error(
				'low_pte_not_found',
				__( 'Product not found.', 'low-product-table-editor' ),
				array( 'status' => 404 )
			);
		}

		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new \WP_Error(
				'low_pte_forbidden',
				__( 'You are not allowed to edit this item.', 'low-product-table-editor' ),
				array( 'status' => 403 )
			);
		}

		return rest_ensure_response( $row );
	}

	/**
	 * Save a batch of at most 50 items.
	 *
	 * @since 0.1.1
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_items( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body  = $request->get_json_params();
		$items = ( is_array( $body ) && isset( $body['items'] ) && is_array( $body['items'] ) ) ? $body['items'] : null;

		if ( null === $items ) {
			return new \WP_Error(
				'rest_invalid_param',
				__( 'Items are required.', 'low-product-table-editor' ),
				array( 'status' => 400 )
			);
		}

		if ( 50 < count( $items ) ) {
			return new \WP_Error(
				'low_pte_too_many_items',
				__( 'A request can include at most 50 items.', 'low-product-table-editor' ),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response( $this->products->update_items( $items ) );
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
			$result->header( 'X-LOW-PTE-Memory', (string) memory_get_peak_usage( true ) );
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
