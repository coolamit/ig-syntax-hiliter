<?php
/**
 * Bootstrap for the unit test tier.
 *
 * Loads no WordPress: the domain code has no WordPress dependency and this tier
 * is what keeps it that way.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

if ( ! defined( 'IG_SYNTAX_HILITER_TESTS_PLUGIN_DIR' ) ) {
	fwrite( STDERR, 'tests/unit/bootstrap.php must be loaded through tests/bootstrap.php.' . PHP_EOL );
	exit( 1 );
}

// The plugin's autoloader resolves paths against this constant, normally defined by the main plugin file.
if ( ! defined( 'IG_SYNTAX_HILITER_ROOT' ) ) {
	define( 'IG_SYNTAX_HILITER_ROOT', IG_SYNTAX_HILITER_TESTS_PLUGIN_DIR );
}

require_once IG_SYNTAX_HILITER_TESTS_PLUGIN_DIR . '/autoloader.php';

// EOF
