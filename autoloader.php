<?php
/**
 * Autoloader for the PHP classes of this plugin.
 *
 * This file has no WordPress dependency, on purpose — the unit test tier loads
 * it with no WordPress present at all.
 *
 * @package iG_Syntax_Hiliter
 *
 * @author Amit Gupta <https://amitgupta.in/>
 */

/*
 * Register resource autoloader
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
function ig_syntax_hiliter_autoloader( $class_name = '' ) {

	$namespace_root = 'iG\Syntax_Hiliter';

	$class_name = trim( $class_name, '\\' );

	if ( empty( $class_name ) || false === strpos( $class_name, '\\' ) || 0 !== strpos( $class_name, $namespace_root ) ) {
		//not our namespace, bail out
		return;
	}

	//remove the namespace root and grab the actual resource
	$parts = array_slice( explode( '\\', $class_name ), 2 );

	$path = str_replace( '_', '-', implode( '/', $parts ) );

	/*
	 * rtrim() rather than untrailingslashit(): this autoloader must have no
	 * WordPress dependency at all, so that the unit test tier can exercise the
	 * plugin's classes with no WordPress loaded and nothing shimmed.
	 */
	$path = sprintf( '%s/classes/%s.php', rtrim( IG_SYNTAX_HILITER_ROOT, '/\\' ), strtolower( $path ) );

	if ( file_exists( $path ) ) {
		require_once $path;
	}
}


//EOF
