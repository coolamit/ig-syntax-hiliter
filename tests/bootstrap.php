<?php
/**
 * PHPUnit bootstrap for the iG:Syntax Hiliter test suites.
 *
 * The plugin has two tiers of tests and they need very different environments,
 * so this file works out which tier is being run and hands off to that tier's
 * own bootstrap:
 *
 *  - unit        : plain PHP, no WordPress, no database.
 *  - integration : a real WordPress install plus the WordPress test library.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests;

/**
 * Absolute path of the tests directory, with no trailing slash.
 */
define( 'IG_SYNTAX_HILITER_TESTS_DIR', __DIR__ );

/**
 * Absolute path of the plugin directory, with no trailing slash.
 */
define( 'IG_SYNTAX_HILITER_TESTS_PLUGIN_DIR', dirname( __DIR__ ) );

$ig_syntax_hiliter_autoloader = IG_SYNTAX_HILITER_TESTS_PLUGIN_DIR . '/vendor/autoload.php';

if ( ! is_readable( $ig_syntax_hiliter_autoloader ) ) {
	fwrite( STDERR, 'Composer dependencies are not installed. Run `make install-php` first.' . PHP_EOL );
	exit( 1 );
}

require_once $ig_syntax_hiliter_autoloader;

/**
 * Work out which test suite is running.
 *
 * PHPUnit does not expose the selected suite to the bootstrap, so the command
 * line is read directly. An explicit environment variable wins, which keeps
 * this usable from CI or an IDE runner that builds its own command line.
 *
 * @return string Suite name, or an empty string when it could not be determined.
 */
function ig_syntax_hiliter_get_test_suite(): string {
	$suite = (string) getenv( 'IG_SYNTAX_HILITER_TEST_SUITE' );

	if ( ! empty( $suite ) ) {
		return strtolower( trim( $suite ) );
	}

	$args  = (array) ( $_SERVER['argv'] ?? [] );  // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- CLI arguments of the test runner, not web input.
	$count = count( $args );

	for ( $i = 0; $i < $count; $i++ ) {
		$arg = (string) $args[ $i ];

		if ( '--testsuite' === $arg ) {
			return strtolower( trim( (string) ( $args[ $i + 1 ] ?? '' ) ) );
		}

		if ( str_starts_with( $arg, '--testsuite=' ) ) {
			return strtolower( trim( substr( $arg, strlen( '--testsuite=' ) ) ) );
		}
	}

	return '';
}

$ig_syntax_hiliter_suite = ig_syntax_hiliter_get_test_suite();

switch ( $ig_syntax_hiliter_suite ) {

	case 'unit':
		require_once IG_SYNTAX_HILITER_TESTS_DIR . '/unit/bootstrap.php';
		break;

	case 'integration':
		require_once IG_SYNTAX_HILITER_TESTS_DIR . '/integration/bootstrap.php';
		break;

	default:
		fwrite(
			STDERR,
			sprintf(
				'The %s test tier needs its own bootstrap, so a suite must be named.%s',
				( empty( $ig_syntax_hiliter_suite ) ) ? 'each' : sprintf( '"%s"', $ig_syntax_hiliter_suite ),
				PHP_EOL
			)
		);
		fwrite( STDERR, 'Run `make test-unit`, `make test-integration`, or `make test` for both.' . PHP_EOL );
		exit( 1 );

}

unset( $ig_syntax_hiliter_autoloader, $ig_syntax_hiliter_suite );


//EOF
