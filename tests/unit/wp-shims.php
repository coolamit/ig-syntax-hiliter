<?php
/**
 * The three WordPress functions the domain core cannot avoid, shimmed.
 *
 * `esc_html()` and `esc_attr()` are what the renderer's tests are about, and
 * `apply_filters()` is what the tag list and registry extension points run
 * through. Anything in the domain core needing more of WordPress than this
 * belongs in the integration tier.
 *
 * Required explicitly by the test cases which need it, so the tier's bootstrap
 * stays free of WordPress.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Escapes a string for use in HTML.
	 *
	 * @param string $text Text to escape.
	 * @return string
	 */
	function esc_html( $text ) {  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Shim for the WordPress function of the same name.
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', false );
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
