<?php
/**
 * Schema installer.
 *
 * The log table arrives in phase 4. Do not call this class yet.
 *
 * @package LOW\ProductTableEditor
 */

declare( strict_types=1 );

namespace LOW\ProductTableEditor;

defined( 'ABSPATH' ) || exit;

/**
 * Installs and upgrades plugin schema.
 *
 * The log table arrives in phase 4. Do not call this class yet.
 *
 * @since 0.1.0
 */
class Installer {

	/**
	 * Create or upgrade plugin tables when needed.
	 *
	 * The log table arrives in phase 4.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function maybe_install(): void {
	}
}
