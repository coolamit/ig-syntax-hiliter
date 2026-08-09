<?php
/**
 * Configuration consumed by the WordPress PHPUnit test library.
 *
 * The library requires a real file on disk (it passes the path straight to its
 * own `install.php` sub process), so this cannot simply be a set of constants
 * defined in the bootstrap.
 *
 * Every value is overridable through the environment, with defaults that match
 * a stock VVV box. CI sets the same variables against its MySQL service
 * container, so this one file serves both.
 *
 * WARNING: every table in the configured database is dropped and rebuilt on
 * each run. Never point this at a database with anything in it worth keeping.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

/**
 * Reads an environment variable, falling back to a default.
 *
 * @param string $name     Environment variable name.
 * @param string $fallback Value to use when the variable is unset or empty.
 * @return string
 */
function ig_syntax_hiliter_tests_env( string $name, string $fallback ): string {
	$value = getenv( $name );

	if ( false === $value || '' === trim( $value ) ) {
		return $fallback;
	}

	return trim( $value );
}

/*
 * Path to the WordPress install the tests run against.
 *
 * The plugin lives inside a WordPress install already — in the VVV sandbox that
 * is `public_html/`, five levels up from this file — so no second copy of core
 * is vendored through Composer. CI, where no such install exists, sets
 * WP_TESTS_ABSPATH to whatever WordPress it checked out.
 */
$ig_syntax_hiliter_abspath = ig_syntax_hiliter_tests_env( 'WP_TESTS_ABSPATH', dirname( __DIR__, 5 ) );
$ig_syntax_hiliter_abspath = rtrim( $ig_syntax_hiliter_abspath, '/\\' ) . '/';

if ( ! is_readable( $ig_syntax_hiliter_abspath . 'wp-settings.php' ) ) {
	fwrite(
		STDERR,
		sprintf(
			'Error: no WordPress install found at "%s". Set WP_TESTS_ABSPATH to a WordPress root.%s',
			$ig_syntax_hiliter_abspath,
			PHP_EOL
		)
	);
	exit( 1 );
}

define( 'ABSPATH', $ig_syntax_hiliter_abspath );

/*
 * Test database. Separate from the sandbox site database on purpose.
 */
define( 'DB_NAME', ig_syntax_hiliter_tests_env( 'WP_TESTS_DB_NAME', 'wordpress_unit_tests' ) );
define( 'DB_USER', ig_syntax_hiliter_tests_env( 'WP_TESTS_DB_USER', 'wp' ) );
define( 'DB_PASSWORD', ig_syntax_hiliter_tests_env( 'WP_TESTS_DB_PASSWORD', 'wp' ) );
define( 'DB_HOST', ig_syntax_hiliter_tests_env( 'WP_TESTS_DB_HOST', 'localhost' ) );
define( 'DB_CHARSET', ig_syntax_hiliter_tests_env( 'WP_TESTS_DB_CHARSET', 'utf8mb4' ) );
define( 'DB_COLLATE', ig_syntax_hiliter_tests_env( 'WP_TESTS_DB_COLLATE', '' ) );

/*
 * Table prefix for the test install. This global is part of the WordPress
 * bootstrap contract and cannot be renamed or prefixed.
 */
$table_prefix = ig_syntax_hiliter_tests_env( 'WP_TESTS_TABLE_PREFIX', 'wptests_' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Required by the WordPress test library.

define( 'WP_TESTS_DOMAIN', ig_syntax_hiliter_tests_env( 'WP_TESTS_DOMAIN', 'example.org' ) );
define( 'WP_TESTS_EMAIL', ig_syntax_hiliter_tests_env( 'WP_TESTS_EMAIL', 'admin@example.org' ) );
define( 'WP_TESTS_TITLE', ig_syntax_hiliter_tests_env( 'WP_TESTS_TITLE', 'iG:Syntax Hiliter Tests' ) );

define( 'WP_PHP_BINARY', ig_syntax_hiliter_tests_env( 'WP_PHP_BINARY', 'php' ) );

define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', true );
define( 'WP_TESTS_MULTISITE', ( '1' === ig_syntax_hiliter_tests_env( 'WP_TESTS_MULTISITE', '0' ) ) );

unset( $ig_syntax_hiliter_abspath );


//EOF
