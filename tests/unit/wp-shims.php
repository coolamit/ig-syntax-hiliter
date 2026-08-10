<?php
/**
 * The few WordPress functions the domain core cannot avoid, shimmed.
 *
 * `_wp_specialchars()` and `esc_attr()` are what the renderer's tests are about,
 * and `apply_filters()` is what the tag list and registry extension points run
 * through. Anything in the domain core needing more of WordPress than this
 * belongs in the integration tier.
 *
 * Required explicitly by the test cases which need it, so the tier's bootstrap
 * stays free of WordPress.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

if ( ! function_exists( '_wp_specialchars' ) ) {
	/**
	 * Converts the HTML special characters, optionally encoding entities twice.
	 *
	 * The site charset a WordPress install would look up is UTF-8 here.
	 *
	 * @param string $text          Text to convert.
	 * @param int    $quote_style   Which quotes to convert.
	 * @param bool   $charset       Charset, ignored by the shim.
	 * @param bool   $double_encode Whether to encode an existing entity again.
	 * @return string
	 */
	function _wp_specialchars( $text, $quote_style = ENT_NOQUOTES, $charset = false, $double_encode = false ) {  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Shim for the WordPress function of the same name.
		unset( $charset );

		return htmlspecialchars( (string) $text, (int) $quote_style, 'UTF-8', (bool) $double_encode );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * Escapes a string for use in an HTML attribute.
	 *
	 * @param string $text Text to escape.
	 * @return string
	 */
	function esc_attr( $text ) {  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Shim for the WordPress function of the same name.
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', false );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Returns the value unchanged, there being no filters to run.
	 *
	 * @param string $hook_name Name of the filter hook.
	 * @param mixed  $value     Value being filtered.
	 * @return mixed
	 */
	function apply_filters( $hook_name, $value ) {  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Shim for the WordPress function of the same name.
		unset( $hook_name );

		return $value;
	}
}


//EOF
