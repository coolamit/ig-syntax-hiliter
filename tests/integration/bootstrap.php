<?php
/**
 * Bootstrap for the WordPress integration test tier.
 *
 * Loads the test library from wp-phpunit/wp-phpunit, not VVV's wordpress-develop
 * checkout, and mounts the plugin as a must use plugin.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

if ( ! defined( 'IG_SYNTAX_HILITER_TESTS_PLUGIN_DIR' ) ) {
	fwrite( STDERR, 'tests/integration/bootstrap.php must be loaded through tests/bootstrap.php.' . PHP_EOL );
	exit( 1 );
}

// wp-phpunit publishes its location through a Composer autoload file.
$ig_syntax_hiliter_wp_phpunit_dir = (string) getenv( 'WP_PHPUNIT__DIR' );

if ( empty( $ig_syntax_hiliter_wp_phpunit_dir ) ) {
	$ig_syntax_hiliter_wp_phpunit_dir = IG_SYNTAX_HILITER_TESTS_PLUGIN_DIR . '/vendor/wp-phpunit/wp-phpunit';
}

$ig_syntax_hiliter_wp_phpunit_dir = rtrim( $ig_syntax_hiliter_wp_phpunit_dir, '/\\' );

if ( ! is_readable( $ig_syntax_hiliter_wp_phpunit_dir . '/includes/functions.php' ) ) {
	fwrite(
		STDERR,
		sprintf( 'Error: the WordPress test library was not found in "%s". Run `make install-php`.%s', $ig_syntax_hiliter_wp_phpunit_dir, PHP_EOL )
	);
	exit( 1 );
}

// Point the test library at this plugin's config file.
define( 'WP_TESTS_CONFIG_FILE_PATH', IG_SYNTAX_HILITER_TESTS_DIR . '/integration/wp-tests-config.php' );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Name is fixed by the WordPress test library.

require_once $ig_syntax_hiliter_wp_phpunit_dir . '/includes/functions.php';

/**
 * Loads the plugin into the test install.
 *
 * `muplugins_loaded` is the earliest hook the library offers.
 *
 * @return void
 */
function ig_syntax_hiliter_tests_load_plugin(): void {
	require_once IG_SYNTAX_HILITER_TESTS_PLUGIN_DIR . '/ig-syntax-hiliter.php';
}

tests_add_filter( 'muplugins_loaded', 'ig_syntax_hiliter_tests_load_plugin' );

require_once $ig_syntax_hiliter_wp_phpunit_dir . '/includes/bootstrap.php';

unset( $ig_syntax_hiliter_wp_phpunit_dir );

// EOF
