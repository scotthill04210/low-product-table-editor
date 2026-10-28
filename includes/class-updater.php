<?php
/**
 * GitHub Releases updater.
 *
 * @package LOW\ProductTableEditor
 */

declare( strict_types=1 );

namespace LOW\ProductTableEditor;

defined( 'ABSPATH' ) || exit;

/**
 * Updates the plugin from GitHub Releases.
 *
 * @since 0.1.0
 */
class Updater {

	/**
	 * Cached latest release.
	 */
	private const RELEASE_TRANSIENT = 'low_pte_github_release';

	/**
	 * Short-lived marker stored when GitHub cannot be read.
	 */
	private const FAILURE_TRANSIENT = 'low_pte_github_release_failed';

	/**
	 * Plugin slug.
	 */
	private const SLUG = 'low-product-table-editor';

	/**
	 * Release asset file name.
	 */
	private const PACKAGE_NAME = 'low-product-table-editor.zip';

	/**
	 * Nonce action for the manual update check.
	 */
	private const NONCE_ACTION = 'low_pte_check_update';

	/**
	 * Whether admin hooks have been registered for this request.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Register updater hooks on admin and cron requests.
	 *
	 * Frontend requests register nothing and make no remote calls.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		if ( $this->registered ) {
			return;
		}

		$doing_cron = function_exists( 'wp_doing_cron' ) && wp_doing_cron();

		if ( ! is_admin() && ! $doing_cron ) {
			return;
		}

		$this->registered = true;

		$basename = plugin_basename( LOW_PTE_FILE );

		add_filter( 'update_plugins_github.com', array( $this, 'filter_plugin_update' ), 10, 4 );
		add_filter( 'plugins_api', array( $this, 'filter_plugin_information' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'filter_source_selection' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'clear_update_cache' ), 10, 2 );
		add_filter( 'plugin_action_links_' . $basename, array( $this, 'filter_action_links' ) );
		add_filter( 'network_admin_plugin_action_links_' . $basename, array( $this, 'filter_action_links' ) );
		add_filter( 'plugin_row_meta', array( $this, 'filter_row_meta' ), 10, 2 );
		add_filter( 'http_request_args', array( $this, 'filter_http_request_args' ), 10, 2 );
		add_action( 'admin_post_' . self::NONCE_ACTION, array( $this, 'handle_check_for_updates' ) );
		add_action( 'admin_notices', array( $this, 'render_check_notice' ) );
	}

	/**
	 * Offer a GitHub release when it is newer than this install.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed    $update      Update payload from earlier filters.
	 * @param array    $plugin_data Plugin headers.
	 * @param string   $plugin_file Plugin basename.
	 * @param string[] $locales     Installed locales.
	 * @return array<string, string>|false|mixed
	 */
	public function filter_plugin_update( $update, $plugin_data, $plugin_file, $locales ) {
		unset( $plugin_data, $locales );

		if ( plugin_basename( LOW_PTE_FILE ) !== $plugin_file ) {
			return $update;
		}

		$release = $this->get_latest_release();

		if ( ! is_array( $release ) || ! $this->is_newer( $release ) ) {
			return false;
		}

		$payload = array(
			'id'           => 'https://github.com/' . LOW_PTE_GITHUB_REPO,
			'slug'         => self::SLUG,
			'plugin'       => $plugin_file,
			'version'      => $release['version'],
			'url'          => $release['html_url'],
			'package'      => $release['package'],
			'tested'       => $release['tested'],
			'requires'     => $release['requires'],
			'requires_php' => $release['requires_php'],
		);

		return $payload;
	}

	/**
	 * Build the "View details" modal from the cached release.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed  $result Existing API result.
	 * @param string $action Requested API action.
	 * @param mixed  $args   Request arguments.
	 * @return mixed
	 */
	public function filter_plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || self::SLUG !== $this->requested_slug( $args ) ) {
			return $result;
		}

		$release = $this->get_latest_release();

		if ( ! is_array( $release ) ) {
			return $result;
		}

		$headers = $this->plugin_headers();
		$info    = new \stdClass();

		$info->name          = '' !== $headers['Name'] ? $headers['Name'] : 'LOW Product Table Editor';
		$info->slug          = self::SLUG;
		$info->version       = $release['version'];
		$info->author        = wp_kses_post( $headers['Author'] );
		$info->homepage      = 'https://github.com/' . LOW_PTE_GITHUB_REPO;
		$info->requires      = $release['requires'];
		$info->requires_php  = $release['requires_php'];
		$info->tested        = $release['tested'];
		$info->download_link = $release['package'];
		$info->last_updated  = $this->format_published_date( $release['published'] );
		$info->sections      = array(
			'description' => wp_kses_post( wpautop( $headers['Description'] ) ),
			'changelog'   => $this->markdown_to_html( $release['body'] ),
		);

		return $info;
	}

	/**
	 * Rename a GitHub zipball folder to the plugin slug.
	 *
	 * @since 0.1.0
	 *
	 * @param string|mixed       $source        Extracted source path.
	 * @param string|mixed       $remote_source Remote upgrade directory.
	 * @param mixed              $upgrader      Upgrader instance.
	 * @param array<mixed>|mixed $hook_extra   Extra hook arguments.
	 * @return string|\WP_Error|mixed
	 */
	public function filter_source_selection( $source, $remote_source, $upgrader, $hook_extra ) {
		unset( $upgrader );

		if ( is_wp_error( $source ) || ! is_string( $source ) || ! is_string( $remote_source ) ) {
			return $source;
		}

		if ( ! $this->hook_extra_includes_plugin( $hook_extra ) ) {
			return $source;
		}

		$source = trailingslashit( $source );

		if ( self::SLUG === basename( untrailingslashit( $source ) ) ) {
			return $source;
		}

		global $wp_filesystem;

		if ( ! is_object( $wp_filesystem ) || ! method_exists( $wp_filesystem, 'move' ) ) {
			return new \WP_Error(
				'low_pte_rename_failed',
				esc_html__( 'Could not rename the update folder.', 'low-product-table-editor' )
			);
		}

		$destination = trailingslashit( $remote_source ) . self::SLUG . '/';

		if ( $wp_filesystem->exists( $destination ) ) {
			$wp_filesystem->delete( untrailingslashit( $destination ), true );
		}

		$moved = $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $destination ) );

		if ( ! $moved ) {
			return new \WP_Error(
				'low_pte_rename_failed',
				esc_html__( 'Could not rename the update folder.', 'low-product-table-editor' )
			);
		}

		return trailingslashit( $destination );
	}

	/**
	 * Drop cached release data after this plugin updates.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed              $upgrader   Upgrader instance.
	 * @param array<mixed>|mixed $hook_extra Extra hook arguments.
	 * @return void
	 */
	public function clear_update_cache( $upgrader, $hook_extra ): void {
		unset( $upgrader );

		if ( ! $this->hook_extra_includes_plugin( $hook_extra ) ) {
			return;
		}

		delete_transient( self::RELEASE_TRANSIENT );
		delete_transient( self::FAILURE_TRANSIENT );
	}

	/**
	 * Add the manual check link next to Deactivate.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string>|mixed $links Existing action links.
	 * @return array<string, string>|mixed
	 */
	public function filter_action_links( $links ) {
		if ( ! is_array( $links ) || ! current_user_can( 'update_plugins' ) ) {
			return $links;
		}

		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::NONCE_ACTION ),
			self::NONCE_ACTION
		);

		$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Check for updates', 'low-product-table-editor' ) . '</a>';

		return $links;
	}

	/**
	 * Link to the GitHub release when WordPress already knows an update exists.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, string>|mixed $links Existing row meta.
	 * @param string                   $file  Plugin basename.
	 * @return array<int, string>|mixed
	 */
	public function filter_row_meta( $links, $file ) {
		if ( ! is_array( $links ) || plugin_basename( LOW_PTE_FILE ) !== $file ) {
			return $links;
		}

		$url = $this->available_update_url();

		if ( '' === $url ) {
			return $links;
		}

		$links[] = sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url( $url ),
			esc_html__( 'View release', 'low-product-table-editor' )
		);

		return $links;
	}

	/**
	 * Send the GitHub token only when downloading our package.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $args Request arguments.
	 * @param string               $url  Request URL.
	 * @return array<string, mixed>
	 */
	public function filter_http_request_args( $args, $url ) {
		if ( ! is_array( $args ) || ! is_string( $url ) || ! $this->is_allowed_package_url( $url ) || ! $this->is_our_package_url( $url ) ) {
			return $args;
		}

		$token = $this->github_token();

		if ( '' === $token ) {
			return $args;
		}

		if ( ! isset( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
			$args['headers'] = array();
		}

		$args['headers']['Authorization'] = 'Bearer ' . $token;

		if ( str_contains( $url, '/releases/assets/' ) ) {
			$args['headers']['Accept'] = 'application/octet-stream';
		}

		return $args;
	}

	/**
	 * Clear caches, refresh WordPress updates, and return to the Plugins screen.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function handle_check_for_updates(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die(
				esc_html__( 'Sorry, you are not allowed to update plugins for this site.', 'low-product-table-editor' ),
				esc_html__( 'Not allowed', 'low-product-table-editor' ),
				array(
					'response' => 403,
				)
			);
		}

		check_admin_referer( self::NONCE_ACTION );

		delete_transient( self::RELEASE_TRANSIENT );
		delete_transient( self::FAILURE_TRANSIENT );
		delete_site_transient( 'update_plugins' );

		$release = $this->get_latest_release();
		$status  = 'error';

		if ( is_array( $release ) ) {
			$status = $this->is_newer( $release ) ? 'update' : 'current';
		}

		if ( function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}

		wp_safe_redirect(
			add_query_arg(
				'low_pte_update_checked',
				$status,
				admin_url( 'plugins.php' )
			)
		);
		exit;
	}

	/**
	 * Show the result of a manual update check on the Plugins screen.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function render_check_notice(): void {
		if ( ! is_admin() || ! current_user_can( 'update_plugins' ) || ! $this->is_plugins_screen() ) {
			return;
		}

		// Display-only status from a redirect that already verified the nonce and capability.
		$raw_status = isset( $_GET['low_pte_update_checked'] ) ? wp_unslash( $_GET['low_pte_update_checked'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( ! is_string( $raw_status ) ) {
			return;
		}

		$status = sanitize_key( $raw_status );

		if ( ! in_array( $status, array( 'update', 'current', 'error' ), true ) ) {
			return;
		}

		$message = $this->check_notice_message( $status );

		if ( '' === $message ) {
			return;
		}

		$classes = array(
			'update'  => 'notice-warning',
			'current' => 'notice-success',
			'error'   => 'notice-error',
		);

		echo '<div class="notice ' . esc_attr( $classes[ $status ] ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Latest parsed GitHub release, from cache when possible.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>|null
	 */
	public function get_latest_release(): ?array {
		$cached = get_transient( self::RELEASE_TRANSIENT );

		if ( $this->is_release_array( $cached ) ) {
			return $cached;
		}

		if ( false !== get_transient( self::FAILURE_TRANSIENT ) ) {
			return null;
		}

		$release = $this->fetch_latest_release();

		if ( ! is_array( $release ) ) {
			set_transient( self::FAILURE_TRANSIENT, '1', HOUR_IN_SECONDS );
			return null;
		}

		set_transient( self::RELEASE_TRANSIENT, $release, 12 * HOUR_IN_SECONDS );
		delete_transient( self::FAILURE_TRANSIENT );

		return $release;
	}

	/**
	 * Request and parse the latest stable GitHub release.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>|null
	 */
	private function fetch_latest_release(): ?array {
		if ( ! defined( 'LOW_PTE_GITHUB_REPO' ) || ! is_string( LOW_PTE_GITHUB_REPO ) ) {
			return null;
		}

		if ( ! preg_match( '/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', LOW_PTE_GITHUB_REPO ) ) {
			return null;
		}

		$args = array(
			'timeout' => 10,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'LOW-Product-Table-Editor/' . LOW_PTE_VERSION,
			),
		);

		$token = $this->github_token();

		if ( '' !== $token ) {
			$args['headers']['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_remote_get( 'https://api.github.com/repos/' . LOW_PTE_GITHUB_REPO . '/releases/latest', $args );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			return null;
		}

		$payload = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $payload ) ) {
			return null;
		}

		return $this->parse_release( $payload );
	}

	/**
	 * Normalize a GitHub release payload.
	 *
	 * @since 0.1.0
	 *
	 * @param array<mixed> $payload Release JSON.
	 * @return array<string, string>|null
	 */
	private function parse_release( array $payload ): ?array {
		if ( ! empty( $payload['draft'] ) || ! empty( $payload['prerelease'] ) ) {
			return null;
		}

		$tag = isset( $payload['tag_name'] ) && is_string( $payload['tag_name'] ) ? $payload['tag_name'] : '';

		if ( ! preg_match( '/^v?\d+\.\d+\.\d+$/', $tag ) ) {
			return null;
		}

		$version = preg_replace( '/^v/', '', $tag );

		if ( ! is_string( $version ) || ! preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
			return null;
		}

		$package = $this->package_url( $payload );

		if ( '' === $package ) {
			return null;
		}

		$body      = isset( $payload['body'] ) && is_string( $payload['body'] ) ? $payload['body'] : '';
		$headers   = $this->plugin_headers();
		$published = isset( $payload['published_at'] ) && is_string( $payload['published_at'] ) ? $payload['published_at'] : '';
		$html_url  = isset( $payload['html_url'] ) && is_string( $payload['html_url'] ) ? $payload['html_url'] : '';
		$fallback  = 'https://github.com/' . LOW_PTE_GITHUB_REPO . '/releases/tag/' . rawurlencode( $tag );

		return array(
			'tag'          => $tag,
			'version'      => $version,
			'package'      => $package,
			'body'         => $body,
			'published'    => $published,
			'html_url'     => $this->github_web_url( $html_url, $fallback ),
			'requires'     => $this->first_version( $this->note_value( $body, 'Requires at least' ), $headers['RequiresAtLeast'] ),
			'requires_php' => $this->first_version( $this->note_value( $body, 'Requires PHP' ), $headers['RequiresPHP'] ),
			'tested'       => $this->first_version( $this->note_value( $body, 'Tested up to' ), $headers['TestedUpTo'] ),
		);
	}

	/**
	 * Choose the release zip URL.
	 *
	 * Prefer the named asset. Use the GitHub zipball only when the release has no assets.
	 *
	 * @since 0.1.0
	 *
	 * @param array<mixed> $payload Release JSON.
	 * @return string
	 */
	private function package_url( array $payload ): string {
		$assets = isset( $payload['assets'] ) && is_array( $payload['assets'] ) ? $payload['assets'] : array();

		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) || ( $asset['name'] ?? '' ) !== self::PACKAGE_NAME ) {
				continue;
			}

			$token = $this->github_token();

			if ( '' !== $token && isset( $asset['url'] ) && is_string( $asset['url'] ) && $this->is_allowed_package_url( $asset['url'] ) ) {
				return $asset['url'];
			}

			if ( isset( $asset['browser_download_url'] ) && is_string( $asset['browser_download_url'] ) && $this->is_allowed_package_url( $asset['browser_download_url'] ) ) {
				return $asset['browser_download_url'];
			}

			return '';
		}

		if ( array() !== $assets ) {
			return '';
		}

		$zipball = isset( $payload['zipball_url'] ) && is_string( $payload['zipball_url'] ) ? $payload['zipball_url'] : '';

		if ( $this->is_allowed_package_url( $zipball ) ) {
			return $zipball;
		}

		return '';
	}

	/**
	 * Whether the cached release is newer than this install.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string> $release Parsed release.
	 * @return bool
	 */
	private function is_newer( array $release ): bool {
		return isset( $release['version'], $release['package'] )
			&& '' !== $release['version']
			&& '' !== $release['package']
			&& version_compare( $release['version'], LOW_PTE_VERSION, '>' );
	}

	/**
	 * Read a version-like line from release notes.
	 *
	 * @since 0.1.0
	 *
	 * @param string $body  Release notes.
	 * @param string $label Line label.
	 * @return string
	 */
	private function note_value( string $body, string $label ): string {
		$pattern = '/^' . preg_quote( $label, '/' ) . ':\s*(\S+)/mi';

		if ( ! preg_match( $pattern, $body, $matches ) ) {
			return '';
		}

		return $this->clean_version( $matches[1] );
	}

	/**
	 * Keep the first usable version string.
	 *
	 * @since 0.1.0
	 *
	 * @param string $preferred Value from the release notes.
	 * @param string $fallback  Value from the plugin header.
	 * @return string
	 */
	private function first_version( string $preferred, string $fallback ): string {
		if ( '' !== $preferred ) {
			return $preferred;
		}

		return $this->clean_version( $fallback );
	}

	/**
	 * Reduce a version token to numbers and dots.
	 *
	 * @since 0.1.0
	 *
	 * @param string $version Raw version.
	 * @return string
	 */
	private function clean_version( string $version ): string {
		if ( ! preg_match( '/^\d+(?:\.\d+){0,2}/', $version, $matches ) ) {
			return '';
		}

		return $matches[0];
	}

	/**
	 * Plugin header fields used in the update modal.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	private function plugin_headers(): array {
		$headers = array(
			'Name'            => 'LOW Product Table Editor',
			'Description'     => '',
			'Author'          => '',
			'RequiresAtLeast' => '6.4',
			'RequiresPHP'     => LOW_PTE_MIN_PHP,
			'TestedUpTo'      => '',
		);

		if ( ! function_exists( 'get_file_data' ) ) {
			return $headers;
		}

		$from_file = get_file_data(
			LOW_PTE_FILE,
			array(
				'Name'            => 'Plugin Name',
				'Description'     => 'Description',
				'Author'          => 'Author',
				'RequiresAtLeast' => 'Requires at least',
				'RequiresPHP'     => 'Requires PHP',
				'TestedUpTo'      => 'Tested up to',
			),
			'plugin'
		);

		foreach ( $headers as $key => $default ) {
			if ( isset( $from_file[ $key ] ) && is_string( $from_file[ $key ] ) && '' !== $from_file[ $key ] ) {
				$headers[ $key ] = $from_file[ $key ];
			}
		}

		return $headers;
	}

	/**
	 * Token from wp-config.php, when a private repository needs it.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	private function github_token(): string {
		if ( ! defined( 'LOW_PTE_GITHUB_TOKEN' ) || ! is_string( LOW_PTE_GITHUB_TOKEN ) ) {
			return '';
		}

		return trim( LOW_PTE_GITHUB_TOKEN );
	}

	/**
	 * Whether a URL is the package recorded for this plugin.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url Request URL.
	 * @return bool
	 */
	private function is_our_package_url( string $url ): bool {
		$cached = get_transient( self::RELEASE_TRANSIENT );

		if ( $this->is_release_array( $cached ) && $url === $cached['package'] ) {
			return true;
		}

		$updates = get_site_transient( 'update_plugins' );
		$file    = plugin_basename( LOW_PTE_FILE );

		if ( ! is_object( $updates ) || ! isset( $updates->response[ $file ] ) ) {
			return false;
		}

		$update  = $updates->response[ $file ];
		$package = '';

		if ( is_object( $update ) && isset( $update->package ) && is_string( $update->package ) ) {
			$package = $update->package;
		} elseif ( is_array( $update ) && isset( $update['package'] ) && is_string( $update['package'] ) ) {
			$package = $update['package'];
		}

		return '' !== $package && $url === $package;
	}

	/**
	 * Release page URL from the WordPress update transient.
	 *
	 * Does not call GitHub.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	private function available_update_url(): string {
		$updates = get_site_transient( 'update_plugins' );
		$file    = plugin_basename( LOW_PTE_FILE );

		if ( ! is_object( $updates ) || ! isset( $updates->response[ $file ] ) ) {
			return '';
		}

		$update = $updates->response[ $file ];
		$url    = '';

		if ( is_object( $update ) && isset( $update->url ) && is_string( $update->url ) ) {
			$url = $update->url;
		} elseif ( is_array( $update ) && isset( $update['url'] ) && is_string( $update['url'] ) ) {
			$url = $update['url'];
		}

		return $this->github_web_url( $url, '' );
	}

	/**
	 * Keep a GitHub web URL, or use the fallback.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url      Candidate URL.
	 * @param string $fallback Safe fallback. Empty string leaves the URL blank.
	 * @return string
	 */
	private function github_web_url( string $url, string $fallback ): string {
		$parts = wp_parse_url( $url );
		$host  = is_array( $parts ) ? strtolower( (string) ( $parts['host'] ?? '' ) ) : '';

		if ( is_array( $parts ) && 'https' === ( $parts['scheme'] ?? '' ) && 'github.com' === $host ) {
			return $url;
		}

		return $fallback;
	}

	/**
	 * Whether a download URL points at GitHub.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url Candidate URL.
	 * @return bool
	 */
	private function is_allowed_package_url( string $url ): bool {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) ) {
			return false;
		}

		$host = strtolower( (string) ( $parts['host'] ?? '' ) );

		return in_array( $host, array( 'github.com', 'api.github.com', 'codeload.github.com' ), true );
	}

	/**
	 * Slug from a plugins_api request.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $args Request arguments.
	 * @return string
	 */
	private function requested_slug( $args ): string {
		if ( is_object( $args ) && isset( $args->slug ) && is_string( $args->slug ) ) {
			return $args->slug;
		}

		if ( is_array( $args ) && isset( $args['slug'] ) && is_string( $args['slug'] ) ) {
			return $args['slug'];
		}

		return '';
	}

	/**
	 * Whether an upgrader hook refers to this plugin.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $hook_extra Extra hook arguments.
	 * @return bool
	 */
	private function hook_extra_includes_plugin( $hook_extra ): bool {
		if ( ! is_array( $hook_extra ) ) {
			return false;
		}

		$file = plugin_basename( LOW_PTE_FILE );

		if ( isset( $hook_extra['plugin'] ) && $file === $hook_extra['plugin'] ) {
			return true;
		}

		return isset( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) && in_array( $file, $hook_extra['plugins'], true );
	}

	/**
	 * Whether a transient value is a parsed release.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $release Transient value.
	 * @return bool
	 */
	private function is_release_array( $release ): bool {
		if ( ! is_array( $release ) ) {
			return false;
		}

		$keys = array( 'tag', 'version', 'package', 'body', 'published', 'html_url', 'requires', 'requires_php', 'tested' );

		foreach ( $keys as $key ) {
			if ( ! isset( $release[ $key ] ) || ! is_string( $release[ $key ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Plugins screen, including the network plugins screen.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	private function is_plugins_screen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		if ( ! is_object( $screen ) || ! isset( $screen->id ) ) {
			return false;
		}

		return in_array( $screen->id, array( 'plugins', 'plugins-network' ), true );
	}

	/**
	 * Notice text for a manual check result.
	 *
	 * @since 0.1.0
	 *
	 * @param string $status update, current, or error.
	 * @return string
	 */
	private function check_notice_message( string $status ): string {
		if ( 'error' === $status ) {
			return __( 'Could not reach GitHub to check for updates. Try again later.', 'low-product-table-editor' );
		}

		if ( 'current' === $status ) {
			return sprintf(
				/* translators: %s: installed version number. */
				__( 'LOW Product Table Editor is up to date (version %s).', 'low-product-table-editor' ),
				LOW_PTE_VERSION
			);
		}

		$release = get_transient( self::RELEASE_TRANSIENT );
		$version = $this->is_release_array( $release ) ? $release['version'] : '';

		if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
			return '';
		}

		return sprintf(
			/* translators: %s: available version number. */
			__( 'A new version (%s) is available. Update from the Plugins screen.', 'low-product-table-editor' ),
			$version
		);
	}

	/**
	 * Format a GitHub timestamp for the details modal.
	 *
	 * @since 0.1.0
	 *
	 * @param string $published ISO 8601 timestamp.
	 * @return string
	 */
	private function format_published_date( string $published ): string {
		$timestamp = strtotime( $published );

		if ( false === $timestamp ) {
			return '';
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Turn a small slice of Markdown into HTML.
	 *
	 * @since 0.1.0
	 *
	 * @param string $markdown Release notes.
	 * @return string
	 */
	private function markdown_to_html( string $markdown ): string {
		$markdown = str_replace( array( "\r\n", "\r" ), "\n", $markdown );
		$lines    = explode( "\n", $markdown );
		$html     = '';
		$items    = array();

		foreach ( $lines as $line ) {
			$trim = trim( $line );

			if ( preg_match( '/^[-*] (.+)$/', $trim, $matches ) ) {
				$items[] = $this->markdown_inline( $matches[1] );
				continue;
			}

			$html .= $this->markdown_list( $items );
			$items = array();

			if ( '' === $trim ) {
				continue;
			}

			if ( preg_match( '/^(#{1,3}) (.+)$/', $trim, $matches ) ) {
				$level = min( 4, strlen( $matches[1] ) + 1 );
				$html .= '<h' . $level . '>' . $this->markdown_inline( $matches[2] ) . '</h' . $level . '>';
				continue;
			}

			$html .= '<p>' . $this->markdown_inline( $trim ) . '</p>';
		}

		$html .= $this->markdown_list( $items );

		return wp_kses_post( $html );
	}

	/**
	 * Render a list and clear the caller-owned item array via the return value.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, string> $items Escaped list items.
	 * @return string
	 */
	private function markdown_list( array $items ): string {
		if ( array() === $items ) {
			return '';
		}

		$html = '<ul>';

		foreach ( $items as $item ) {
			$html .= '<li>' . $item . '</li>';
		}

		$html .= '</ul>';

		return $html;
	}

	/**
	 * Escape a Markdown line and restore a few inline marks.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Raw line text.
	 * @return string
	 */
	private function markdown_inline( string $text ): string {
		$text = esc_html( $text );

		$linked = preg_replace_callback(
			'/\[([^]]+)\]\((https?:\/\/[^)\s]+)\)/',
			static function ( array $matches ): string {
				$url = esc_url( $matches[2] );

				if ( '' === $url ) {
					return $matches[1];
				}

				return '<a href="' . $url . '">' . $matches[1] . '</a>';
			},
			$text
		);

		if ( ! is_string( $linked ) ) {
			return $text;
		}

		$code = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $linked );

		if ( ! is_string( $code ) ) {
			return $linked;
		}

		$bold = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $code );

		if ( ! is_string( $bold ) ) {
			return $code;
		}

		return $bold;
	}
}
