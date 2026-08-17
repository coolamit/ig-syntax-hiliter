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

	//the resource's own name, which is the only segment carrying a file name prefix
	$name = strtolower( str_replace( '_', '-', (string) array_pop( $parts ) ) );

	$directory = ( empty( $parts ) ) ? '' : strtolower( str_replace( '_', '-', implode( '/', $parts ) ) ) . '/';

	/*
	 * WordPress file naming: a file declaring a class is `class-<name>.php` and one
	 * declaring a trait is `trait-<name>.php`. Which of the two a resource is cannot
	 * be known before the file is read, so both names are tried in turn rather than
	 * this function being taught which namespaces hold traits.
	 *
	 * rtrim() rather than untrailingslashit(): this autoloader must have no
	 * WordPress dependency at all, so that the unit test tier can exercise the
	 * plugin's classes with no WordPress loaded and nothing shimmed.
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


//EOF
