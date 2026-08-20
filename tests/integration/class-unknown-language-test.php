<?php
/**
 * An unknown language degrades to a plain box and asks the browser for nothing.
 *
 * What is checked here is the half markup cannot show: nothing on the page asks
 * for a language file which is not there.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Asset_Manager;
use iG\Syntax_Hiliter\Legacy_Map;
use iG\Syntax_Hiliter\Shortcode_Handler;
use iG\Syntax_Hiliter\Tests\Integration\Traits\Asset_Test_Helpers;
use iG\Syntax_Hiliter\Tests\Integration\Traits\Pipeline_Test_Helpers;
use WP_UnitTestCase;

/**
 * The enqueue state a snippet with an unresolvable language leaves behind.
 */
class Unknown_Language_Test extends WP_UnitTestCase {

	use Pipeline_Test_Helpers;

	use Asset_Test_Helpers;

	/**
	 * A tag the plugin never shipped, claimed here through the tag filter.
	 *
	 * @var string
	 */
	protected const string _UNSHIPPED_TAG = 'madeuptag';

	/**
	 * Registers the pipeline once WordPress is up.
	 *
	 * @return void
	 */
	public function set_up(): void {

		parent::set_up();

		Shortcode_Handler::get_instance();

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

		$tags[] = static::_UNSHIPPED_TAG;

		return $tags;

	}

	/**
	 * Nothing on the page asks for the bogus language, and the box is still given
	 * the engine and a theme to be styled by. The check is against what was
	 * registered, not against markup.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_enqueues_no_component_for_an_unknown_language(): void {

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
	 * A language the registry does know is not enqueued either: the engine's own
	 * loader fetches language files at runtime, and enqueuing them eagerly would put
	 * the 404 risk straight back.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_leaves_a_known_language_to_the_runtime_loader(): void {

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
	 * @test
	 *
	 * @return void
	 */
	public function it_degrades_a_tag_claimed_through_the_filter_rather_than_inventing_a_language(): void {

		add_filter( Legacy_Map::FILTER_TAGS, [ $this, 'claim_unshipped_tag' ] );

		$this->assertContains( static::_UNSHIPPED_TAG, Legacy_Map::get_tags() );

		$output = $this->_filter( 'the_content', sprintf( '[%1$s]xyz[/%1$s]', static::_UNSHIPPED_TAG ) );

		remove_filter( Legacy_Map::FILTER_TAGS, [ $this, 'claim_unshipped_tag' ] );

		$this->assertStringContainsString( '<code class="language-none">', $output );
		$this->assertStringNotContainsString( sprintf( 'language-%s', static::_UNSHIPPED_TAG ), $output );

	}

} // end of class

// EOF
