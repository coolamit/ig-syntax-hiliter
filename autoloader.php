<?php
/**
 * Autoloader for the PHP classes of this plugin.
 *
 * No WordPress dependency: the unit tier loads it with no WordPress present.
 *
 * @package iG_Syntax_Hiliter
 *
 * @author Amit Gupta <https://amitgupta.in/>
 */

spl_autoload_register( 'ig_syntax_hiliter_autoloader' );

/**
 * The function that makes on-demand autoloading of files for this plugin
 * possible. It is registered with spl_autoload_register() and must not be
 * called directly.
 *
 * @param string $class_name Fully qualified name of the resource that is to be loaded.
 * @return void
 */
function ig_syntax_hiliter_autoloader( string $class_name = '' ): void {

	$namespace_root = 'iG\Syntax_Hiliter';

	$class_name = trim( $class_name, '\\' );

	if ( empty( $class_name ) || false === strpos( $class_name, '\\' ) || 0 !== strpos( $class_name, $namespace_root ) ) {
		// not our namespace, bail out
		return;
	}

	$parts = array_slice( explode( '\\', $class_name ), 2 );

	$name = strtolower( str_replace( '_', '-', (string) array_pop( $parts ) ) );

	$directory = ( empty( $parts ) ) ? '' : strtolower( str_replace( '_', '-', implode( '/', $parts ) ) ) . '/';

	/*
	 * WordPress names files `class-<name>.php` or `trait-<name>.php`; which one
	 * cannot be known before reading, so both are tried. rtrim() and not
	 * untrailingslashit(): no WordPress dependency.
	 */
	$root = rtrim( IG_SYNTAX_HILITER_ROOT, '/\\' );

	foreach ( [ 'class', 'trait' ] as $prefix ) {

		$path = sprintf( '%s/classes/%s%s-%s.php', $root, $directory, $prefix, $name );

		if ( file_exists( $path ) ) {

			require_once $path;

			return;

		}
	}
}

// EOF
