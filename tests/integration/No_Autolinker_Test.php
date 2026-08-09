<?php
/**
 * AC-10 — a URL inside code is text, not a link.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Shortcode_Handler;
use WP_UnitTestCase;

/**
 * Prism's Autolinker plugin is deliberately not bundled. This checks that nothing
 * else — core's own `make_clickable`, the embed handlers, the plugin itself —
 * turns a URL inside a code box into a link.
 */
class No_Autolinker_Test extends WP_UnitTestCase {

	use Asset_Test_Helpers;

	/**
	 * Code carrying URLs of the kinds an autolinker looks for.
	 *
	 * @var string
	 */
	const CODE_WITH_URLS = "\$api = 'https://example.com/v1/thing?a=1&b=2';\n// see http://example.org/docs\nwww.example.net/plain\nsomeone@example.com";

	/**
	 * Registers the pipeline once WordPress is up.
	 *
	 * @return void
	 */
	public function set_up(): void {

		parent::set_up();

		Shortcode_Handler::get_instance()->register_hooks();

		$this->_reset_asset_state();

	}

	/**
	 * Puts the asset state back for whatever runs next.
	 *
	 * @return void
	 */
	public function tear_down(): void {

		$this->_reset_asset_state();

		parent::tear_down();

	}

	/**
	 * Method to run content through one of WordPress' own filters.
	 *
	 * @param string $filter  Filter name.
	 * @param string $content Content to filter.
	 *
	 * @return string
	 */
	protected function _filter( string $filter, string $content ): string {
		return (string) apply_filters( $filter, $content );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Running content through core's own hooks is what an integration test does.
	}

	/**
	 * Method to assert that no code box in some markup holds a link.
	 *
	 * @param string $html    Rendered markup.
	 * @param string $context Name of the context, for failure messages.
	 *
	 * @return void
	 */
	protected function _assert_no_links_in_code( string $html, string $context ): void {

		preg_match_all( '#<code class="language-[^"]*">(.*?)</code>#s', $html, $matches );

		$this->assertNotEmpty( $matches[1], sprintf( '%s: there should be a code box to look inside.', $context ) );

		foreach ( $matches[1] as $code ) {
			$this->assertStringNotContainsString( '<a ', $code, $context );
			$this->assertStringNotContainsString( 'href', $code, $context );
			$this->assertStringNotContainsString( '</a>', $code, $context );
		}

	}

	/**
	 * AC-10 — a URL in a post stays plain text even when a theme or plugin puts
	 * `make_clickable` on the content, while prose around the snippet is still
	 * linked, so this is measuring the snippet and not a dead filter chain.
	 *
	 * @return void
	 */
	public function test_urls_survive_make_clickable_on_the_content(): void {

		add_filter( 'the_content', 'make_clickable', 10 );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, core callback, added the way a theme would.

		$output = $this->_filter(
			'the_content',
			sprintf( "Read https://example.com/docs first.\n\n[php]\n%s\n[/php]", self::CODE_WITH_URLS )
		);

		remove_filter( 'the_content', 'make_clickable', 10 );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, core callback.

		$this->_assert_no_links_in_code( $output, 'make_clickable at 10' );
		$this->assertStringContainsString( esc_html( self::CODE_WITH_URLS ), $output );
		$this->assertStringContainsString( '<a href="https://example.com/docs"', $output );

	}

	/**
	 * AC-10 — and in a comment, where core hangs `make_clickable` by default.
	 *
	 * @return void
	 */
	public function test_urls_in_a_comment_snippet_stay_plain_text(): void {

		$priority = has_filter( 'comment_text', 'make_clickable' );

		$this->assertNotFalse( $priority, 'Core autolinks comments out of the box.' );
		$this->assertGreaterThan( Shortcode_Handler::PRIORITY_PROTECT, $priority );
		$this->assertLessThan( Shortcode_Handler::PRIORITY_RESTORE, $priority );

		$output = $this->_filter( 'comment_text', sprintf( "[php]\n%s\n[/php]", self::CODE_WITH_URLS ) );

		$this->_assert_no_links_in_code( $output, 'comment_text' );

	}

	/**
	 * AC-10 — the Autolinker component is not bundled and is never loaded.
	 *
	 * @return void
	 */
	public function test_the_autolinker_component_is_not_bundled_or_enqueued(): void {

		$this->assertDirectoryDoesNotExist( dirname( __DIR__, 2 ) . '/assets/lib/prism/plugins/autolinker' );

		$this->_filter( 'the_content', sprintf( "[php]\n%s\n[/php]", self::CODE_WITH_URLS ) );

		$this->_fire_footer();

		foreach ( $this->_all_asset_urls() as $asset ) {
			$this->assertStringNotContainsString( 'autolinker', $asset );
		}

	}

}    //end of class


//EOF
