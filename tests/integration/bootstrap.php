<?php
/**
 * Bootstrap for the WordPress integration test tier.
 *
 * Loads the WordPress PHPUnit test library shipped by wp-phpunit/wp-phpunit —
 * NOT the wordpress-develop checkout that VVV provisions, which is years out of
 * date — mounts the plugin as if it were a must use plugin, and then hands over
 * to the library's own bootstrap, which installs a throwaway WordPress into the
 * test database.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

if ( ! defined( 'IG_SYNTAX_HILITER_TESTS_PLUGIN_DIR' ) ) {
	fwrite( STDERR, 'tests/integration/bootstrap.php must be loaded through tests/bootstrap.php.' . PHP_EOL );
	exit( 1 );
}

/*
 * wp-phpunit puts its own location in the environment through a Composer
 * autoload file, so it is picked up from there when available.
 */
$ig_syntax_hiliter_wp_phpunit_dir = (string) getenv( 'WP_PHPUNIT__DIR' );

if ( '' === $ig_syntax_hiliter_wp_phpunit_dir ) {
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

/*
 * Point the test library at this plugin's config file instead of letting it
 * hunt for a wp-tests-config.php next to a WordPress checkout.
 */
define( 'WP_TESTS_CONFIG_FILE_PATH', IG_SYNTAX_HILITER_TESTS_DIR . '/integration/wp-tests-config.php' );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Name is fixed by the WordPress test library.

require_once $ig_syntax_hiliter_wp_phpunit_dir . '/includes/functions.php';

/**
 * Loads the plugin into the test install.
 *
 * `muplugins_loaded` is the earliest hook the test library offers, and loading
 * here means the plugin is present for the whole of the WordPress bootstrap,
 * exactly as an active plugin would be.
 *
 * @return void
 */
function ig_syntax_hiliter_tests_load_plugin(): void {
	require_once IG_SYNTAX_HILITER_TESTS_PLUGIN_DIR . '/ig-syntax-hiliter.php';
}

tests_add_filter( 'muplugins_loaded', 'ig_syntax_hiliter_tests_load_plugin' );

require_once $ig_syntax_hiliter_wp_phpunit_dir . '/includes/bootstrap.php';

unset( $ig_syntax_hiliter_wp_phpunit_dir );


//EOF
