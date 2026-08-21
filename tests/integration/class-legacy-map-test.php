<?php
/**
 * Tests for the legacy tag map.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Legacy_Map;
use iG\Syntax_Hiliter\Shortcode_Handler;
use iG\Syntax_Hiliter\Tests\Integration\Traits\Pipeline_Test_Helpers;
use WP_UnitTestCase;

/**
 * Which tags the plugin claims, and what happens to one it does not: a tag it never
 * shipped is left alone, and one claimed through the filter degrades rather than
 * inventing a language.
 */
class Legacy_Map_Test extends WP_UnitTestCase {

	use Pipeline_Test_Helpers;

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
	 * A tag the plugin never shipped is not the plugin's. It is neither registered
	 * nor rendered nor stripped. `[sourcecode]` always is, whatever language it
	 * names, and an unresolvable one degrades.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_leaves_a_tag_the_plugin_never_shipped_alone(): void {

		$content = '[email]someone@example.com[/email]';

		$this->assertStringContainsString( $content, $this->_filter( 'the_content', $content ) );
		$this->assertStringContainsString( $content, $this->_filter( 'the_excerpt', $content ) );
		$this->assertSame( wp_slash( $content ), $this->_filter( 'content_save_pre', wp_slash( $content ) ) );

		$this->assertNotContains( 'email', Legacy_Map::get_tags() );
		$this->assertArrayNotHasKey( 'email', $GLOBALS['shortcode_tags'] );

		$generic = $this->_filter( 'the_content', '[sourcecode language="email"]a@b.com[/sourcecode]' );

		$this->assertStringContainsString( '<code class="language-none">', $generic );
		$this->assertStringNotContainsString( 'language-email', $generic );

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
