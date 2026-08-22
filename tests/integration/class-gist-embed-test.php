<?php
/**
 * Tests for the `[github]` Gist pipeline.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Asset_Manager;
use iG\Syntax_Hiliter\Gist_Embed;
use iG\Syntax_Hiliter\Option;
use iG\Syntax_Hiliter\Tests\Integration\Traits\Hook_Test_Helpers;
use iG\Syntax_Hiliter\Tests\Integration\Traits\Pipeline_Test_Helpers;
use ReflectionProperty;
use WP_UnitTestCase;

/**
 * The `[github]` Gist pipeline: its hooks, what it embeds and links, and the
 * stylesheet it loads.
 */
class Gist_Embed_Test extends WP_UnitTestCase {

	use Pipeline_Test_Helpers;

	use Hook_Test_Helpers;

	/**
	 * Services this case rebuilt, to be put back in `tear_down()`.
	 *
	 * A rewired service's registrations land in the global filter registry, and leaving one there hands every later suite a plugin wired differently from the one it booted.
	 *
	 * @var array
	 */
	protected array $_rewired = [];

	/**
	 * Registers the pipeline once WordPress is up.
	 *
	 * @return void
	 */
	public function set_up(): void {

		parent::set_up();

		Gist_Embed::get_instance();

		// The singleton and the style registry both outlive a test; start from a request which has rendered nothing.
		( new ReflectionProperty( Gist_Embed::class, '_has_embeds' ) )->setValue( Gist_Embed::get_instance(), false );

		wp_dequeue_style( Gist_Embed::STYLE_HANDLE );

	}

	/**
	 * Puts back any service this case took apart.
	 *
	 * @return void
	 */
	public function tear_down(): void {

		foreach ( array_reverse( $this->_rewired ) as $class_name => $instance ) {

			$this->_set_singleton( $class_name, $instance );

		}

		if ( ! empty( $this->_rewired ) ) {
			$this->_set_singleton( Option::class, null );
		}

		$this->_rewired = [];

		parent::tear_down();

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
	 * Method to rebuild the Gist embed against a different `gist_in_comments`.
	 *
	 * @param string $value Option value.
	 *
	 * @return Gist_Embed The rebuilt embed.
	 */
	protected function _rewire_gist_with( string $value ): Gist_Embed {

		$this->_store_option( 'gist_in_comments', $value );

		remove_all_filters( 'comment_text' );

		$this->_remember( Gist_Embed::class );

		return Gist_Embed::get_instance();

	}

	/**
	 * Method to note a service's booted instance and clear its slot for a rebuild.
	 *
	 * `Option` goes with it, because the booted one holds the array as it was before
	 * this case wrote to it.
	 *
	 * @param string $class_name Service class name.
	 *
	 * @return void
	 */
	protected function _remember( string $class_name ): void {

		if ( ! array_key_exists( $class_name, $this->_rewired ) ) {
			$this->_rewired[ $class_name ] = $class_name::get_instance();
		}

		$this->_set_singleton( Option::class, null );
		$this->_set_singleton( $class_name, null );

	}

	/**
	 * Method to read the filters a Gist embed hands a link to.
	 *
	 * Read through reflection rather than copied here, because which filters are on
	 * the list is what `gist_in_comments` decides.
	 *
	 * @param Gist_Embed $gist The embed to read.
	 *
	 * @return array Filter names.
	 */
	protected function _gist_link_filters( Gist_Embed $gist ): array {

		return (array) ( new ReflectionProperty( Gist_Embed::class, '_link_filters' ) )->getValue( $gist );

	}

	/**
	 * The Gist embed's six registrations, on the settings it boots with.
	 *
	 * `PRIORITY_EMBED` is 9, ahead of `wptexturize`, which would curl the quotes in the
	 * `gist="…"` URL. The two `wp_footer` registrations are named from the asset
	 * manager's constants because they are the same two moments for the same reason.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_registers_the_gist_embeds_hooks(): void {

		$gist = Gist_Embed::get_instance();

		$this->_assert_hooked(
			'the_content',
			[ $gist, 'parse' ],
			Gist_Embed::PRIORITY_EMBED,
			'The embed goes in ahead of wptexturize, which would curl the quotes in the URL.'
		);

		$this->assertLessThan(
			has_filter( 'the_content', 'wptexturize' ),
			Gist_Embed::PRIORITY_EMBED,
			'The Gist pipeline must parse its attributes before texturize rewrites them.'
		);

		foreach ( $this->_gist_link_filters( $gist ) as $filter ) {

			$this->_assert_hooked(
				$filter,
				[ $gist, 'parse' ],
				Gist_Embed::PRIORITY_LINK,
				sprintf( '%s gets a link rather than a script, because a summary cannot run one.', $filter )
			);

		}

		$this->_assert_hooked(
			'wp_footer',
			[ $gist, 'enqueue' ],
			Asset_Manager::PRIORITY_DECIDE,
			'The Gist stylesheet is decided at the same moment every other asset is.'
		);

		$this->_assert_hooked(
			'wp_footer',
			[ $gist, 'enqueue' ],
			Asset_Manager::PRIORITY_DECIDE_AGAIN,
			'And again, for a Gist rendered by something which itself runs from wp_footer.'
		);

	}

	/**
	 * With `gist_in_comments` on, a comment gets an embed rather than a link.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_embeds_a_gist_in_comments_when_the_setting_is_on(): void {

		$gist = $this->_rewire_gist_with( 'yes' );

		$this->assertSame(
			[ Gist_Embed::PRIORITY_EMBED ],
			$this->_hooked_priorities( 'comment_text', [ $gist, 'parse' ] ),
			'A comment is on the embed list, and on that list only — one registration, at the embed priority.'
		);

	}

	/**
	 * With `gist_in_comments` off, a comment gets a link.
	 *
	 * The priorities are equal and the callback is the same method either way, so the
	 * case asserts the list the constructor appended `comment_text` to, not the number.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_links_to_a_gist_from_comments_when_the_setting_is_off(): void {

		$gist = $this->_rewire_gist_with( 'no' );

		$this->assertContains(
			'comment_text',
			$this->_gist_link_filters( $gist ),
			'A comment joins the link list, which is what makes it a link and not a script.'
		);

		$this->_assert_hooked(
			'comment_text',
			[ $gist, 'parse' ],
			Gist_Embed::PRIORITY_LINK,
			'And is still parsed, because a link has to be put there by something.'
		);

	}

	/**
	 * A full Gist URL wins over the id, and only its last segment is used.
	 *
	 * Run through `the_content` rather than called directly, so that `wptexturize`
	 * gets its chance at the quoted URL.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_lets_a_url_win_over_an_id(): void {

		$output = $this->_filter( 'the_content', '[github id="ignored" gist="https://gist.github.com/someone/def456/"]' );

		$this->assertStringContainsString( $this->_expected_embed( 'def456' ), $output );

	}

	/**
	 * Every documented way of naming a Gist still renders exactly what it rendered.
	 *
	 * The id is hardened against path segments that are not Gist ids, and this is
	 * what says the hardening did not take a real Gist with it. A 32 character hex
	 * id is what GitHub actually hands out, so it is the shape pinned here, on the
	 * embed path and the link path both.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_renders_the_documented_forms_as_they_always_did(): void {

		$id = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

		$this->assertStringContainsString(
			$this->_expected_embed( $id ),
			$this->_filter( 'the_content', sprintf( '[github id="%s"]', $id ) )
		);

		$this->assertStringContainsString(
			$this->_expected_embed( $id ),
			$this->_filter( 'the_content', sprintf( '[github gist="https://gist.github.com/someone/%s"]', $id ) )
		);

		$this->assertStringContainsString(
			sprintf( '<a href="https://gist.github.com/%1$s" rel="nofollow">https://gist.github.com/%1$s</a>', $id ),
			$this->_filter( 'the_excerpt', sprintf( '[github id="%s"]', $id ) )
		);

	}

	/**
	 * An id which cannot be a Gist id prints nothing at all, and no traversal reaches
	 * the page by any other route.
	 *
	 * Not a URL with the offending characters taken out of it: an id with characters
	 * removed names a different Gist, so a refusal is the only honest answer. A
	 * username sanitiser lets `. - _ @` and spaces through, so `..` is the case to
	 * check, on the embed path and the link path both.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_prints_nothing_for_an_id_that_cannot_be_a_gist_id(): void {

		$inputs = [
			'[github id=".."]',
			'[github id="a b"]',
			'[github id="../evil"]',
			'[github gist="https://gist.github.com/someone/.."]',
		];

		foreach ( $inputs as $input ) {

			foreach ( [ 'the_content', 'the_excerpt' ] as $filter ) {

				$output = $this->_filter( $filter, $input );

				$this->assertStringNotContainsString(
					'gist.github.com',
					$output,
					sprintf( '`%1$s` was printed as a Gist URL by `%2$s`.', $input, $filter )
				);

				$this->assertStringNotContainsString(
					'..',
					$output,
					sprintf( '`%1$s` put a traversal into the output of `%2$s`.', $input, $filter )
				);
			}
		}

	}

	/**
	 * A bare `[github]` with no attributes renders nothing and does not fatal.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_renders_nothing_for_a_bare_github_tag_and_does_not_fatal(): void {

		$output = $this->_filter( 'the_content', 'before [github] after' );

		$this->assertStringNotContainsString( '[github]', $output );
		$this->assertStringNotContainsString( 'gist.github.com', $output );
		$this->assertStringContainsString( 'before', $output );
		$this->assertStringContainsString( 'after', $output );

	}

	/**
	 * Where a script cannot go, a link goes instead.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_gives_an_excerpt_a_link_instead_of_a_script(): void {

		$output = $this->_filter( 'the_excerpt', '[github id="abc123"]' );

		$this->assertStringNotContainsString( '<script', $output );
		$this->assertStringContainsString( 'class="igsh-gist"', $output );
		$this->assertStringContainsString( 'https://gist.github.com/abc123', $output );

	}

	/**
	 * The Gist pipeline borrows the shortcode registry and gives it back.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_leaves_the_shortcode_registry_as_it_was(): void {

		add_shortcode( 'ig_sh_test_tag', '__return_empty_string' );

		$before = $GLOBALS['shortcode_tags'];

		$this->_filter( 'the_content', '[github id="abc123"]' );

		$this->assertSame( $before, $GLOBALS['shortcode_tags'] );

		remove_shortcode( 'ig_sh_test_tag' );

	}

	/**
	 * Content with no `[github` in it is handed straight back.
	 *
	 * The guard keeps the parse, which borrows the whole shortcode registry, off every
	 * post with no Gist on it. `&#91;` is the case that comes back changed without it:
	 * `do_shortcodes_in_html_tags()` decodes brackets inside a tag and
	 * `unescape_invalid_shortcodes()` does not put them back. The method is called
	 * directly because the claim is that this method changes nothing.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_returns_content_without_the_tag_untouched(): void {

		$embed = Gist_Embed::get_instance();

		$samples = [
			'A footnote marker [1] and nothing else.',
			'<a href="/x" title="&#91;see this&#93;">a link</a>',
			'[gallery ids="1,2,3"]',
			'[gist] is not this plugin\'s tag.',
		];

		foreach ( $samples as $content ) {

			$this->assertSame(
				$content,
				$embed->parse( $content ),
				'Content with no [github in it is returned byte for byte.'
			);

		}

		// And the guard is not simply refusing everything.
		$this->assertStringContainsString(
			$this->_expected_embed( 'abc123' ),
			$embed->parse( 'before [github id="abc123"] after' )
		);

	}

	/**
	 * The stylesheet which boxes an embed loads only where there is one to box.
	 *
	 * A page carrying nothing but a Gist loads no stylesheet of this plugin's
	 * otherwise, and a page with no embed must not pay for this one.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_loads_the_gist_stylesheet_only_where_a_gist_was_embedded(): void {

		$gist = Gist_Embed::get_instance();

		$this->assertSame( Asset_Manager::PRIORITY_DECIDE, has_action( 'wp_footer', [ $gist, 'enqueue' ] ) );

		$gist->enqueue();

		$this->assertFalse( wp_style_is( Gist_Embed::STYLE_HANDLE, 'enqueued' ), 'Nothing has been embedded yet.' );

		$this->_filter( 'the_content', '[github id="abc123"]' );

		$gist->enqueue();

		$this->assertTrue( wp_style_is( Gist_Embed::STYLE_HANDLE, 'enqueued' ) );

	}

	/**
	 * A link is not an embed, so it needs no stylesheet.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_loads_no_stylesheet_for_a_gist_rendered_as_a_link(): void {

		$gist = Gist_Embed::get_instance();

		$this->_filter( 'the_excerpt', '[github id="abc123"]' );

		$gist->enqueue();

		$this->assertFalse( wp_style_is( Gist_Embed::STYLE_HANDLE, 'enqueued' ) );

	}

	/**
	 * With the setting off, the embed is unchanged and nothing is loaded for it.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_does_not_load_the_stylesheet_while_the_setting_is_off(): void {

		$option   = Option::get_instance();
		$property = new ReflectionProperty( Option::class, '_options' );
		$before   = $property->getValue( $option );

		$property->setValue( $option, array_merge( (array) $before, [ 'gist_limit_height' => 'no' ] ) );

		try {

			$this->_filter( 'the_content', '[github id="abc123"]' );

			Gist_Embed::get_instance()->enqueue();

		} finally {
			$property->setValue( $option, $before );
		}

		$this->assertFalse( wp_style_is( Gist_Embed::STYLE_HANDLE, 'enqueued' ) );

	}

} // end of class

// EOF
