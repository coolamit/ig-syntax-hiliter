<?php
/**
 * Proves, on a real interpreter, that the plugin refuses to load below its floors.
 *
 * A plain script, not a test case: it needs no WordPress, no Composer and no
 * PHPUnit, so it runs on a PHP that `composer install` refuses.
 *
 * Usage:
 *
 *     php tests/compat/refusal-check.php
 *     php tests/compat/refusal-check.php --wp=6.8
 *     IGSH_COMPAT_WP_VERSION=6.8 php tests/compat/refusal-check.php
 *
 * The WordPress version defaults to the plugin's floor; naming an older one
 * forces the WordPress floor to be the reason for the refusal.
 *
 * Holds no version numbers of its own; both floors are read from the Gatekeeper.
 *
 * Exits 0 on a correct refusal. An environment satisfying both floors is a failure.
 *
 * IMPORTANT: parsed by the old PHP it tests, so no syntax newer than the two
 * files it exercises.
 *
 * @package iG_Syntax_Hiliter
 */

/**
 * Where the plugin is, from this file's own location.
 */
define( 'IG_SYNTAX_HILITER_COMPAT_DIR', dirname( dirname( __DIR__ ) ) );

/**
 * Every hook the plugin registers while this script runs, by hook name.
 *
 * @var array
 */
$GLOBALS['ig_syntax_hiliter_compat_hooks'] = [];

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The four functions below stand in for WordPress's own and must carry WordPress's own names; everything else this file declares is prefixed.

/**
 * Stands in for WordPress's `add_action()`, and records what was hooked.
 *
 * @param string   $hook_name Hook being registered on.
 * @param callable $callback  Callback being registered.
 * @return bool
 */
function add_action( $hook_name, $callback ) {

	$GLOBALS['ig_syntax_hiliter_compat_hooks'][ $hook_name ][] = $callback;

	return true;

}

/**
 * Stands in for WordPress's `plugin_basename()`.
 *
 * @param string $file Plugin file path.
 * @return string
 */
function plugin_basename( $file ) {

	return basename( dirname( $file ) ) . '/' . basename( $file );

}

/**
 * Stands in for WordPress's `__()`.
 *
 * Hands the string back, as WordPress does with no translation loaded.
 *
 * @param string $text   Text to translate.
 * @param string $domain Text domain.
 * @return string
 */
function __( $text, $domain = 'default' ) {    // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.textFound -- WordPress's own signature.

	unset( $domain );

	return $text;

}

/**
 * Stands in for WordPress's `esc_html()`.
 *
 * @param string $text Text to escape.
 * @return string
 */
function esc_html( $text ) {

	return htmlspecialchars( $text, ENT_QUOTES );

}

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound

/**
 * Reports a failed check and stops.
 *
 * @param string $reason What went wrong.
 * @return void
 */
function ig_syntax_hiliter_compat_fail( $reason ) {

	echo 'FAIL: ' . $reason . "\n";

	exit( 1 );

}

/**
 * Reports a check that passed.
 *
 * @param string $claim What was proved.
 * @return void
 */
function ig_syntax_hiliter_compat_ok( $claim ) {

	echo '  ok: ' . $claim . "\n";

}

/**
 * The WordPress version this run is to judge.
 *
 * Taken from `--wp=<version>`, then from `IGSH_COMPAT_WP_VERSION`, and otherwise
 * the plugin's own floor, so that on an old PHP the PHP is the only thing wrong.
 *
 * @param string $fallback Version to use when the run names none.
 * @return string
 */
function ig_syntax_hiliter_compat_wp_version( $fallback ) {

	$arguments = ( isset( $GLOBALS['argv'] ) && is_array( $GLOBALS['argv'] ) ) ? $GLOBALS['argv'] : [];

	foreach ( $arguments as $argument ) {

		if ( 0 === strpos( $argument, '--wp=' ) ) {
			return substr( $argument, strlen( '--wp=' ) );
		}
	}

	$from_environment = getenv( 'IGSH_COMPAT_WP_VERSION' );

	if ( false !== $from_environment && ! empty( $from_environment ) ) {
		return $from_environment;
	}

	return $fallback;

}

/**
 * Whether a file has been loaded by this process.
 *
 * @param string $path Absolute path of the file.
 * @return bool
 */
function ig_syntax_hiliter_compat_is_loaded( $path ) {

	$real = realpath( $path );

	if ( false === $real ) {
		return false;
	}

	return in_array( $real, array_map( 'realpath', get_included_files() ), true );

}

/**
 * Runs every callback on one of the recorded hooks.
 *
 * Fired off the hook rather than called by name, so the wiring is exercised too.
 *
 * @param string $hook_name Hook to fire.
 * @return void
 */
function ig_syntax_hiliter_compat_fire( $hook_name ) {

	foreach ( $GLOBALS['ig_syntax_hiliter_compat_hooks'][ $hook_name ] as $callback ) {
		call_user_func( $callback );
	}

}

/**
 * Runs the check.
 *
 * @return void
 */
function ig_syntax_hiliter_compat_run() {
	// Requiring the Gatekeeper runs nothing; it is where the floors are declared.
	require_once IG_SYNTAX_HILITER_COMPAT_DIR . '/classes/class-ig-syntax-hiliter-gatekeeper.php';

	$min_php = iG_Syntax_Hiliter_Gatekeeper::MIN_PHP_VERSION_REQUIRED;
	$min_wp  = iG_Syntax_Hiliter_Gatekeeper::MIN_WP_VERSION_REQUIRED;

	$wp_version = ig_syntax_hiliter_compat_wp_version( $min_wp );

	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Standing in for the global WordPress sets in wp-includes/version.php, which is the only thing the Gatekeeper reads. There is no WordPress here to set it.
	$GLOBALS['wp_version'] = $wp_version;

	printf(
		"Refusal check: PHP %s, WordPress %s. The plugin requires PHP %s and WordPress %s.\n",
		PHP_VERSION,
		$wp_version,
		$min_php,
		$min_wp
	);

	/*
	 * Refuse to run on a supported environment: a check which cannot fail reads
	 * as coverage.
	 */
	$probe = new iG_Syntax_Hiliter_Gatekeeper();

	if ( $probe->is_environment_supported() ) {
		ig_syntax_hiliter_compat_fail( 'this environment meets both floors, so there is no refusal here to check. Run it on a PHP below the floor, or name a WordPress below it with --wp=' );
	}

	ig_syntax_hiliter_compat_ok( 'the environment is one the plugin must refuse' );

	// From here the plugin's own boot path runs as WordPress would run it.
	require_once IG_SYNTAX_HILITER_COMPAT_DIR . '/ig-syntax-hiliter.php';

	if ( empty( $GLOBALS['ig_syntax_hiliter_compat_hooks']['init'] ) ) {
		ig_syntax_hiliter_compat_fail( 'the plugin file registered nothing on `init`, so it would never boot at all.' );
	}

	ig_syntax_hiliter_compat_ok( 'the loader is registered on `init`' );

	ig_syntax_hiliter_compat_fire( 'init' );

	// 1. the whole point: nothing of the plugin was loaded
	if ( ig_syntax_hiliter_compat_is_loaded( IG_SYNTAX_HILITER_COMPAT_DIR . '/autoloader.php' ) ) {
		ig_syntax_hiliter_compat_fail( 'the autoloader was loaded, so the plugin tried to run on an environment it does not support.' );
	}

	if ( class_exists( 'iG\Syntax_Hiliter\Plugin', false ) ) {
		ig_syntax_hiliter_compat_fail( 'the plugin class exists, so the plugin loaded on an environment it does not support.' );
	}

	ig_syntax_hiliter_compat_ok( 'no part of the plugin was loaded' );

	// 2. the refusal was announced
	if ( empty( $GLOBALS['ig_syntax_hiliter_compat_hooks']['admin_notices'] ) ) {
		ig_syntax_hiliter_compat_fail( 'nothing was hooked to `admin_notices`, so the refusal would be silent and a site owner would be left guessing.' );
	}

	ig_syntax_hiliter_compat_ok( 'the refusal notice is registered on `admin_notices`' );

	// 3. and it says what is wrong, and what is needed
	ob_start();

	ig_syntax_hiliter_compat_fire( 'admin_notices' );

	$notice = ob_get_clean();

	$expected = [
		'the plugin name'           => iG_Syntax_Hiliter_Gatekeeper::PLUGIN_NAME,
		'the PHP it requires'       => $min_php,
		'the WordPress it requires' => $min_wp,
		'the PHP in use'            => PHP_VERSION,
		'the WordPress in use'      => $wp_version,
	];

	foreach ( $expected as $claim => $needle ) {

		if ( false === strpos( $notice, $needle ) ) {
			ig_syntax_hiliter_compat_fail( sprintf( 'the notice does not name %1$s (%2$s). It said: %3$s', $claim, $needle, trim( $notice ) ) );
		}
	}

	ig_syntax_hiliter_compat_ok( 'the notice names both floors and both versions in use' );

	echo "PASS: the plugin refused to load, and said why.\n";

}

ig_syntax_hiliter_compat_run();

exit( 0 );

// EOF
