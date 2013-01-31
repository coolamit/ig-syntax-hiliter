<?php
/*
Plugin Name: iG:Syntax Hiliter
Plugin URI: http://blog.igeek.info/wp-plugins/igsyntax-hiliter/
Description: Syntax Highlighter plugin to colourize source code for various supported programming languages. See the MANUAL for more instructions.
Version: 4.1
Author: Amit Gupta
Author URI: http://blog.igeek.info/
*/

if( ! defined('IG_SYNTAX_HILITER_VERSION') ) {
	define( 'IG_SYNTAX_HILITER_VERSION', 4.1 );
}

//set loader to execute on WP init
add_action( 'init', 'ig_syntax_hiliter_loader' );

function ig_syntax_hiliter_loader() {
	$igsh_plugin_dir = dirname( __FILE__ );

	//load up GeSHi
	require_once( $igsh_plugin_dir . "/geshi.php" );

	//load up the plugin base abstract class
	require_once( $igsh_plugin_dir . "/class-ig-syntax-hiliter.php" );

	if( is_admin() ) {
		//load up the plugin admin class
		require_once( $igsh_plugin_dir . "/class-ig-syntax-hiliter-admin.php" );

		if( ! isset($GLOBALS['ig_syntax_hiliter_admin']) || ! is_a( $GLOBALS['ig_syntax_hiliter_admin'], 'iG_Syntax_Hiliter_Admin' ) ) {
			$GLOBALS['ig_syntax_hiliter_admin'] = iG_Syntax_Hiliter_Admin::get_instance();
		}
	}

	if( ! is_admin() ) {
		//load up the plugin front-end class
		require_once( $igsh_plugin_dir . "/class-ig-syntax-hiliter-frontend.php" );

		if( ! isset($GLOBALS['ig_syntax_hiliter_frontend']) || ! is_a( $GLOBALS['ig_syntax_hiliter_frontend'], 'iG_Syntax_Hiliter_Frontend' ) ) {
			$GLOBALS['ig_syntax_hiliter_frontend'] = iG_Syntax_Hiliter_Frontend::get_instance();
		}
	}

	unset( $igsh_plugin_dir );
}


//EOF
