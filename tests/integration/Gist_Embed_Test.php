<?php
/**
 * Tests for the `[github]` Gist pipeline.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Gist_Embed;
use iG\Syntax_Hiliter\Shortcode_Handler;
use WP_UnitTestCase;

/**
 * Checks that Gist embeds behave exactly as they did before.
 */
class Gist_Embed_Test extends WP_UnitTestCase {

	/**
	 * Registers the pipeline once WordPress is up.
	 *
	 * @return void
	 */
	public function set_up(): void {

		parent::set_up();

		Gist_Embed::get_instance()->register_hooks();

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
	 * Method to build the embed markup a Gist id is expected to produce.
	 *
	 * @param string $id Gist id.
	 *
	 * @return string
	 */
	protected function _expected_embed( string $id ): string {
		return sprintf( '<script src="https://gist.github.com/%s.js"></script>', $id );  // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- A Gist embed is an inline third party script tag, by design.
	}

	/**
	 * The Gist pipeline sits where it has always sat.
	 *
	 * @return void
	 */
	public function test_hooks_are_registered_at_the_legacy_priorities(): void {

		$gist = Gist_Embed::get_instance();

		$this->assertSame( 10, has_filter( 'the_content', [ $gist, 'parse' ] ) );
		$this->assertSame( 9, has_filter( 'the_excerpt', [ $gist, 'parse' ] ) );

		// `gist_in_comments` is off by default, so comments get the link form.
		$this->assertSame( 9, has_filter( 'comment_text', [ $gist, 'parse' ] ) );

	}

	/**
	 * An id becomes an embed script.
	 *
	 * @return void
	 */
	public function test_an_id_becomes_an_embed(): void {

		$output = $this->_filter( 'the_content', '[github id="abc123"]' );

		$this->assertStringContainsString( $this->_expected_embed( 'abc123' ), $output );

	}

	/**
	 * A full Gist URL wins over the id, and only its last segment is used.
	 *
	 * Called directly rather than through `the_content`, because `wptexturize`
	 * shares priority 10 and is registered first, so it curls the attribute quotes
	 * before this pipeline ever sees them. That is v5 behaviour, unchanged here.
	 *
	 * @return void
	 */
	public function test_a_url_wins_over_an_id(): void {

		$output = Gist_Embed::get_instance()->render(
			[
				'id'   => 'ignored',
				'gist' => 'https://gist.github.com/someone/def456/',
			]
		);

		$this->assertSame( $this->_expected_embed( 'def456' ), $output );

	}

	/**
	 * A Gist id with nothing usable in it renders nothing at all.
	 *
	 * @return void
	 */
	public function test_an_unusable_id_renders_nothing(): void {

		$this->assertSame( '', Gist_Embed::get_instance()->render( [] ) );
		$this->assertSame( '', Gist_Embed::get_instance()->render( '' ) );

	}

	/**
	 * A bare `[github]` with no attributes renders nothing, and above all does not
	 * fatal — the v5 signature took `array $atts` and WordPress passes an empty
	 * string when a shortcode has no attributes.
	 *
	 * @return void
	 */
	public function test_a_bare_github_tag_renders_nothing_and_does_not_fatal(): void {

		$output = $this->_filter( 'the_content', 'before [github] after' );

		$this->assertStringNotContainsString( '[github]', $output );
		$this->assertStringNotContainsString( 'gist.github.com', $output );
		$this->assertStringContainsString( 'before', $output );
		$this->assertStringContainsString( 'after', $output );

	}

	/**
	 * Where a script cannot go, a link goes instead.
	 *
	 * @return void
	 */
	public function test_an_excerpt_gets_a_link_instead_of_a_script(): void {

		$output = $this->_filter( 'the_excerpt', '[github id="abc123"]' );

		$this->assertStringNotContainsString( '<script', $output );
		$this->assertStringContainsString( 'class="igsh-gist"', $output );
		$this->assertStringContainsString( 'https://gist.github.com/abc123', $output );

	}

	/**
	 * Comments get the link form while `gist_in_comments` is off.
	 *
	 * @return void
	 */
	public function test_comments_get_a_link_while_the_option_is_off(): void {

		$output = $this->_filter( 'comment_text', '[github id="abc123"]' );

		$this->assertStringNotContainsString( '<script', $output );
		$this->assertStringContainsString( 'class="igsh-gist"', $output );

	}

	/**
	 * The Gist pipeline borrows the shortcode registry and gives it back.
	 *
	 * @return void
	 */
	public function test_the_shortcode_registry_is_left_as_it_was(): void {

		add_shortcode( 'ig_sh_test_tag', '__return_empty_string' );

		$before = $GLOBALS['shortcode_tags'];

		$this->_filter( 'the_content', '[github id="abc123"]' );

		$this->assertSame( $before, $GLOBALS['shortcode_tags'] );

		remove_shortcode( 'ig_sh_test_tag' );

	}

	/**
	 * A Gist reference inside a snippet is code, not a Gist.
	 *
	 * @return void
	 */
	public function test_a_gist_tag_inside_a_snippet_is_left_as_code(): void {

		Shortcode_Handler::get_instance()->register_hooks();

		$output = $this->_filter( 'the_content', '[php][github id="abc123"][/php]' );

		$this->assertStringNotContainsString( '<script', $output );
		$this->assertStringContainsString( '[github id=&quot;abc123&quot;]', $output );

	}

}    //end of class


//EOF
