<?php
/**
 * AC-6 — an unknown language degrades to a plain box and asks the browser for nothing.
 *
 * What a plain box looks like is `Renderer_Test`'s business. What is checked here is
 * the half of the criterion that markup cannot show: that nothing on the page ever
 * asks for a language file which is not there.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Asset_Manager;
use iG\Syntax_Hiliter\Legacy_Map;
use iG\Syntax_Hiliter\Shortcode_Handler;
use WP_UnitTestCase;

/**
 * The enqueue state a snippet with an unresolvable language leaves behind.
 */
class Unknown_Language_Test extends WP_UnitTestCase {

	use Asset_Test_Helpers;

	/**
	 * A tag the plugin never shipped, claimed here through the tag filter.
	 *
	 * @var string
	 */
	const UNSHIPPED_TAG = 'madeuptag';

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
	 * Adds a tag the plugin never shipped to the claimed set.
	 *
	 * @param array $tags Claimed tags.
	 *
	 * @return array
	 */
	public function claim_unshipped_tag( $tags ) {

		$tags[] = static::UNSHIPPED_TAG;

		return $tags;

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
	 * AC-6 — nothing on the page asks for the bogus language, and the box is still
	 * given the engine and a theme to be styled by.
	 *
	 * This is the criterion: no request for a language file that is not there, so no
	 * 404. The check is against what was actually registered, not against markup.
	 *
	 * @return void
	 */
	public function test_no_component_is_enqueued_for_an_unknown_language(): void {

		$this->_filter( 'the_content', '[sourcecode language="madeuplang"]xyz[/sourcecode]' );

		$manager = Asset_Manager::get_instance();

		$this->assertTrue( $manager->has_snippets(), 'A plain box is still a box, so the engine loads.' );
		$this->assertSame( [], $manager->get_languages(), 'An unresolvable language is not a language.' );

		$this->_fire_footer();

		foreach ( $this->_all_asset_urls() as $asset ) {
			$this->assertStringNotContainsString( 'madeuplang', $asset );
			$this->assertStringNotContainsString( 'prism-none', $asset );
		}

		$this->assertTrue( wp_script_is( 'ig-syntax-hiliter-engine', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'ig-syntax-hiliter-theme', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'ig-syntax-hiliter-chrome', 'enqueued' ) );

	}

	/**
	 * AC-6 — a language the registry does know is not enqueued either.
	 *
	 * Language files are fetched at runtime by the engine's own loader, which only
	 * asks for what the registry confirmed. Enqueuing components eagerly would put
	 * the 404 risk straight back.
	 *
	 * @return void
	 */
	public function test_a_known_language_is_left_to_the_runtime_loader(): void {

		$this->_filter( 'the_content', '[php]echo 1;[/php]' );

		$this->assertSame( [ 'php' ], Asset_Manager::get_instance()->get_languages() );

		$this->_fire_footer();

		foreach ( $this->_all_asset_urls() as $asset ) {
			$this->assertStringNotContainsString( 'prism-php', $asset );
		}

		$this->assertTrue( wp_script_is( 'ig-syntax-hiliter-autoloader', 'enqueued' ) );

	}

	/**
	 * A site can claim extra tags through the tag filter. A claimed tag whose name
	 * means nothing to the registry must degrade rather than invent a language.
	 *
	 * @return void
	 */
	public function test_a_tag_claimed_through_the_filter_degrades_rather_than_inventing_a_language(): void {

		add_filter( Legacy_Map::FILTER_TAGS, [ $this, 'claim_unshipped_tag' ] );

		$this->assertContains( static::UNSHIPPED_TAG, Legacy_Map::get_tags() );

		$output = $this->_filter( 'the_content', sprintf( '[%1$s]xyz[/%1$s]', static::UNSHIPPED_TAG ) );

		remove_filter( Legacy_Map::FILTER_TAGS, [ $this, 'claim_unshipped_tag' ] );

		$this->assertStringContainsString( '<code class="language-none">', $output );
		$this->assertStringNotContainsString( sprintf( 'language-%s', static::UNSHIPPED_TAG ), $output );

	}

}    //end of class


//EOF
