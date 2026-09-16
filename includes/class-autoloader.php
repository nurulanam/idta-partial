<?php
/**
 * Namespace autoloader following the WordPress file-naming convention.
 *
 * @package IDTA\Partial
 */

declare( strict_types=1 );

namespace IDTA\Partial;

defined( 'ABSPATH' ) || exit;

/**
 * Maps IDTA\Partial\Some_Class to includes/class-some-class.php.
 */
final class Autoloader {

	/**
	 * Namespace prefix handled by this autoloader.
	 */
	private const PREFIX = __NAMESPACE__ . '\\';

	/**
	 * Filename prefixes tried in order, per WordPress conventions.
	 */
	private const PREFIXES = array( 'class-', 'interface-', 'abstract-class-', 'trait-' );

	/**
	 * Absolute path to the class directory.
	 *
	 * @var string
	 */
	private string $base_dir;

	/**
	 * Constructor.
	 *
	 * @param string $base_dir Absolute path to the class directory.
	 */
	private function __construct( string $base_dir ) {
		$this->base_dir = rtrim( $base_dir, '/\\' ) . '/';
	}

	/**
	 * Register the autoloader with SPL.
	 *
	 * @param string $base_dir Absolute path to the class directory.
	 */
	public static function register( string $base_dir ): void {
		$loader = new self( $base_dir );

		spl_autoload_register( array( $loader, 'load' ) );
	}

	/**
	 * Resolve and require a class file.
	 *
	 * @param string $class_name Fully qualified class name.
	 */
	public function load( string $class_name ): void {
		if ( ! str_starts_with( $class_name, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( self::PREFIX ) );
		$segments = explode( '\\', $relative );
		$leaf     = array_pop( $segments );

		$sub_path = '';

		foreach ( $segments as $segment ) {
			$sub_path .= strtolower( str_replace( '_', '-', $segment ) ) . '/';
		}

		$slug = strtolower( str_replace( '_', '-', $leaf ) );

		foreach ( self::PREFIXES as $prefix ) {
			$file = $this->base_dir . $sub_path . $prefix . $slug . '.php';

			if ( is_readable( $file ) ) {
				require_once $file;

				return;
			}
		}
	}
}
