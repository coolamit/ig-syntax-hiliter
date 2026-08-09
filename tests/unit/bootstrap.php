<?php
/**
 * Bootstrap for the unit test tier.
 *
 * Nothing here loads WordPress. The v6 domain code is written to have no
 * WordPress dependency, and this tier exists to keep it that way — if a class
 * under test starts needing WordPress, its unit tests break loudly instead of
 * quietly leaning on a global that happened to be defined.
 *
 * All this tier needs is the Composer autoloader (loaded by the parent
 * bootstrap) and the plugin's own hand rolled autoloader.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

if ( ! defined( 'IG_SYNTAX_HILITER_TESTS_PLUGIN_DIR' ) ) {
	fwrite( STDERR, 'tests/unit/bootstrap.php must be loaded through tests/bootstrap.php.' . PHP_EOL );
	exit( 1 );
}

/*
 * The plugin's autoloader resolves class names against this constant, which is
 * normally defined by the plugin's main file when WordPress loads it.
 */
if ( ! defined( 'IG_SYNTAX_HILITER_ROOT' ) ) {
	define( 'IG_SYNTAX_HILITER_ROOT', IG_SYNTAX_HILITER_TESTS_PLUGIN_DIR );
}

/*
 * `untrailingslashit()` is the one WordPress function the autoloader calls. It
 * is shimmed rather than pulled in with the rest of WordPress so that this tier
 * stays WordPress free. This shim goes away when the autoloader is rewritten
 * for v6 and stops depending on WordPress.
 */
if ( ! function_exists( 'untrailingslashit' ) ) {
	/**
	 * Removes trailing forward slashes and backslashes if they exist.
	 *
	 * @param string $value Value to strip trailing slashes from.
	 * @return string
	 */
	function untrailingslashit( $value ) {  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Shim for the WordPress function of the same name.
		return rtrim( (string) $value, '/\\' );
	}
}

require_once IG_SYNTAX_HILITER_TESTS_PLUGIN_DIR . '/autoloader.php';


//EOF
