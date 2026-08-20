<?php
/**
 * Configuration consumed by the WordPress PHPUnit test library.
 *
 * Must be a real file on disk: the library hands the path to its `install.php`
 * sub process. Every value is overridable through the environment.
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

	if ( false === $value || empty( trim( $value ) ) ) {
		return $fallback;
	}

	return trim( $value );
}

// The WordPress install the tests run against: the one this plugin lives in, five levels up, unless CI names another.
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

// Test database, separate from the sandbox site database.
define( 'DB_NAME', ig_syntax_hiliter_tests_env( 'WP_TESTS_DB_NAME', 'wordpress_unit_tests' ) );
define( 'DB_USER', ig_syntax_hiliter_tests_env( 'WP_TESTS_DB_USER', 'wp' ) );
define( 'DB_PASSWORD', ig_syntax_hiliter_tests_env( 'WP_TESTS_DB_PASSWORD', 'wp' ) );
define( 'DB_HOST', ig_syntax_hiliter_tests_env( 'WP_TESTS_DB_HOST', 'localhost' ) );
define( 'DB_CHARSET', ig_syntax_hiliter_tests_env( 'WP_TESTS_DB_CHARSET', 'utf8mb4' ) );
define( 'DB_COLLATE', ig_syntax_hiliter_tests_env( 'WP_TESTS_DB_COLLATE', '' ) );

// Table prefix; this global name is part of the WordPress bootstrap contract.
$table_prefix = ig_syntax_hiliter_tests_env( 'WP_TESTS_TABLE_PREFIX', 'wptests_' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Required by the WordPress test library.

define( 'WP_TESTS_DOMAIN', ig_syntax_hiliter_tests_env( 'WP_TESTS_DOMAIN', 'example.org' ) );
define( 'WP_TESTS_EMAIL', ig_syntax_hiliter_tests_env( 'WP_TESTS_EMAIL', 'admin@example.org' ) );
define( 'WP_TESTS_TITLE', ig_syntax_hiliter_tests_env( 'WP_TESTS_TITLE', 'iG:Syntax Hiliter Tests' ) );

define( 'WP_PHP_BINARY', ig_syntax_hiliter_tests_env( 'WP_PHP_BINARY', 'php' ) );

define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', true );
define( 'WP_TESTS_MULTISITE', ( '1' === ig_syntax_hiliter_tests_env( 'WP_TESTS_MULTISITE', '0' ) ) );

unset( $ig_syntax_hiliter_abspath );

// EOF
