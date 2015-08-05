<?php
/*
Plugin Name: iG:Syntax Hiliter
Plugin URI: http://blog.igeek.info/wp-plugins/igsyntax-hiliter/
Description: Syntax Highlighter plugin to colourize source code for various supported programming languages. See the MANUAL for more instructions.
Version: 5.0
Author: Amit Gupta
Author URI: http://blog.igeek.info/
License: GPL v2
*/

define( 'IG_SYNTAX_HILITER_ROOT', __DIR__ );

if ( ! defined( 'IG_SYNTAX_HILITER_VERSION' ) ) {
	define( 'IG_SYNTAX_HILITER_VERSION', 5.0 );
}

//set loader to execute on WP init
add_action( 'init', 'ig_syntax_hiliter_loader' );

function ig_syntax_hiliter_loader() {
	/*
	 * Load the Gatekeeper
	 */
	require_once __DIR__ . '/classes/ig-syntax-hiliter-gatekeeper.php';

	/*
	 * Activate the Gatekeeper
	 */
	new iG_Syntax_Hiliter_Gatekeeper();
}


//EOF