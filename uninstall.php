<?php
/**
 * Removes everything the plugin stored, when it is deleted from WordPress.
 *
 * WordPress runs this file on its own, with the plugin not loaded — no
 * autoloader, no constants and none of the plugin's classes. It is therefore
 * plain PHP, and the option names it deletes are spelled out here rather than
 * read from the classes that write them.
 *
 * @package iG_Syntax_Hiliter
 *
 * @author Amit Gupta <https://amitgupta.in/>
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Deletes the plugin's options from the site which is currently active.
 *
 * @return void
 */
function ig_syntax_hiliter_uninstall_site(): void {

	global $wpdb;

	$options = [
		'ig-syntax-hiliter-options',
		'ig-syntax-hiliter-version',
		'ig-syntax-hiliter-migrated-from',
		'ig-syntax-hiliter-lang-time',
		'igsh_options',    //the option name used up to v3.5
	];

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	/*
	 * Cache option names carry an MD5 of the cache key, so they can only be
	 * found by their prefix.
	 */

	//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One off lookup of option names by prefix, which no WordPress API offers.
	$cache_keys = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'igsh-cache-' ) . '%'
		)
	);

	foreach ( (array) $cache_keys as $cache_key ) {
		delete_option( $cache_key );
	}

}

if ( ! is_multisite() ) {

	ig_syntax_hiliter_uninstall_site();

	return;

}

/*
 * Options are per site, so every site in the network has its own copy to
 * remove. Sites are walked in batches, because a large network will not fit
 * into one query's worth of memory.
 */

$ig_syntax_hiliter_batch_size = 200;
$ig_syntax_hiliter_offset     = 0;

do {

	$ig_syntax_hiliter_site_ids = get_sites(
		[
			'fields'                 => 'ids',
			'number'                 => $ig_syntax_hiliter_batch_size,
			'offset'                 => $ig_syntax_hiliter_offset,
			'orderby'                => 'id',
			'update_site_meta_cache' => false,
		]
	);

	$ig_syntax_hiliter_site_count = count( $ig_syntax_hiliter_site_ids );

	foreach ( $ig_syntax_hiliter_site_ids as $ig_syntax_hiliter_site_id ) {

		switch_to_blog( (int) $ig_syntax_hiliter_site_id );

		ig_syntax_hiliter_uninstall_site();

		restore_current_blog();

	}

	$ig_syntax_hiliter_offset += $ig_syntax_hiliter_batch_size;

} while ( $ig_syntax_hiliter_site_count === $ig_syntax_hiliter_batch_size );

unset(
	$ig_syntax_hiliter_batch_size,
	$ig_syntax_hiliter_offset,
	$ig_syntax_hiliter_site_ids,
	$ig_syntax_hiliter_site_count,
	$ig_syntax_hiliter_site_id
);

//EOF
