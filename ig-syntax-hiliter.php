<?php
/**
 * Plugin Name:       iG:Syntax Hiliter
 * Plugin URI:        https://igeek.info/category/wp-plugins/igsyntax-hiliter/
 * Description:       Present source code on your site with syntax highlighting and formatting. See the <a href="https://github.com/coolamit/ig-syntax-hiliter/blob/master/README.md">documentation</a> for instructions.
 * Version:           6.0-beta-1
 * Requires at least: 6.9
 * Requires PHP:      8.4
 * Author:            Amit Gupta
 * Author URI:        https://igeek.info/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       igsyntax-hiliter
 *
 * Parsed by whatever PHP the site runs, as is the Gatekeeper it loads, so
 * neither holds syntax newer than the Gatekeeper's floor.
 *
 * @package iG_Syntax_Hiliter
 */

/**
 * Plugin version. A semantic version string — compare with version_compare(),
 * never numerically.
 */
define( 'IG_SYNTAX_HILITER_VERSION', '6.0-beta-1' );

/**
 * Absolute path of the plugin directory, without a trailing slash.
 */
define( 'IG_SYNTAX_HILITER_ROOT', __DIR__ );

/**
 * Plugin basename, ie. the plugin directory name plus this file name.
 */
define( 'IG_SYNTAX_HILITER_BASENAME', plugin_basename( __FILE__ ) );

add_action( 'init', 'ig_syntax_hiliter_loader' );

/**
 * Hands control to the Gatekeeper, which loads the plugin only when the
 * environment satisfies the plugin's minimum PHP and WordPress versions.
 *
 * Calling this twice is harmless: the Gatekeeper loads through singletons.
 *
 * @return void
 */
function ig_syntax_hiliter_loader() {

	require_once __DIR__ . '/classes/class-ig-syntax-hiliter-gatekeeper.php';

	iG_Syntax_Hiliter_Gatekeeper::activate();

}

// EOF
