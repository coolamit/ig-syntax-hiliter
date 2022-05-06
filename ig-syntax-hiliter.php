<?php
/*
Plugin Name: iG:Syntax Hiliter
Plugin URI: https://igeek.info/category/wp-plugins/igsyntax-hiliter/
Description: Syntax Highlighter plugin to colourise source code for various supported programming languages. See the <a href="https://github.com/coolamit/ig-syntax-hiliter/blob/master/README.md">documentation</a> for more instructions.
Version: 5.1
Author: Amit Gupta
Author URI: https://igeek.info/
License: GPL v2
*/

define( 'IG_SYNTAX_HILITER_VERSION', 5.1 );
define( 'IG_SYNTAX_HILITER_ROOT', __DIR__ );
define( 'IG_SYNTAX_HILITER_URL', plugins_url( '/' ) );
define( 'IG_SYNTAX_HILITER_BASENAME', plugin_basename( __FILE__ ) );

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
	iG_Syntax_Hiliter_Gatekeeper::activate();

}


//EOF
