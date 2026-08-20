<?php
/**
 * Tests that every service registers the hooks it says it registers.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Admin;
use iG\Syntax_Hiliter\Asset_Manager;
use iG\Syntax_Hiliter\Base;
use iG\Syntax_Hiliter\Block;
use iG\Syntax_Hiliter\Block_Converter;
use iG\Syntax_Hiliter\Gist_Embed;
use iG\Syntax_Hiliter\Option;
use iG\Syntax_Hiliter\Shortcode_Handler;
use iG\Syntax_Hiliter\Tests\Integration\Traits\Hook_Test_Helpers;
use iG\Syntax_Hiliter\Tests\Integration\Traits\Pipeline_Test_Helpers;
use ReflectionProperty;
use WP_UnitTestCase;

/**
 * One case per service, asserting every `add_action()` and `add_filter()` it makes.
 *
 * Every priority is named by the class constant rather than by the number behind it,
 * so an assertion travels with the constant it is about. A test which spelled `1` out
 * would go on passing after `PRIORITY_PROTECT` moved.
 */
class Hook_Registration_Test extends WP_UnitTestCase {

	use Hook_Test_Helpers;
	use Pipeline_Test_Helpers;

	/**
	 * Services this case rebuilt, to be put back in `tear_down()`.
	 *
	 * A rewired service's registrations land in the global filter registry, and leaving one there hands every later suite a plugin wired differently from the one it booted.
	 *
	 * @var array
	 */
	protected array $_rewired = [];

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
	 * The asset manager decides twice, and one `has_action()` cannot see the second.
	 *
	 * A snippet rendered from `wp_footer` itself reaches the page with no Prism if the
	 * registration at `PRIORITY_DECIDE_AGAIN` goes missing; core prints the footer
	 * scripts at 20, so 19 is the last moment which still reaches the page.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_registers_the_asset_managers_hooks(): void {

		$assets = Asset_Manager::get_instance();

		$this->_assert_hooked(
			'wp_footer',
			[ $assets, 'enqueue' ],
			Asset_Manager::PRIORITY_DECIDE,
			'Assets are decided once the page has rendered, so the snippet signal is trustworthy.'
		);

		$this->_assert_hooked(
			'wp_footer',
			[ $assets, 'enqueue' ],
			Asset_Manager::PRIORITY_DECIDE_AGAIN,
			'And again just before core prints the footer, for a snippet rendered from wp_footer itself.'
		);

		$this->assertSame(
			[ Asset_Manager::PRIORITY_DECIDE, Asset_Manager::PRIORITY_DECIDE_AGAIN ],
			$this->_hooked_priorities( 'wp_footer', [ $assets, 'enqueue' ] ),
			'Two passes and no more — a third would be a decision nothing asked for.'
		);

		$this->_assert_hooked(
			'body_class',
			[ $assets, 'get_body_classes' ],
			10,
			'The brace matching classes go on the body, which is the ancestor the engine walks up to.'
		);

	}

	/**
	 * The shortcode handler's eleven registrations, on the settings it boots with.
	 *
	 * `PRIORITY_STRIP_BODY` is 0, which `has_filter()` reports as a falsy `0` and an
	 * absent callback as `false`; it is the earlier of the two strips the automatic
	 * excerpt needs, since core's `strip_shortcodes()` cannot be trusted with source code.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_registers_the_shortcode_handlers_hooks(): void {

		$handler = Shortcode_Handler::get_instance();

		$this->_assert_hooked(
			'the_content',
			[ $handler, 'strip_for_excerpt' ],
			Shortcode_Handler::PRIORITY_STRIP_BODY,
			'The automatic excerpt gets its own strip of the post body, ahead of the protect pass.'
		);

		$this->_assert_hooked(
			'the_content',
			[ $handler, 'protect_display' ],
			Shortcode_Handler::PRIORITY_PROTECT,
			'Code is lifted out before any other filter runs.'
		);

		$this->_assert_hooked(
			'the_content',
			[ $handler, 'restore_display' ],
			Shortcode_Handler::PRIORITY_RESTORE,
			'And put back after the last one has finished.'
		);

		foreach ( Shortcode_Handler::EXCERPT_FILTERS as $filter ) {

			$this->_assert_hooked(
				$filter,
				[ $handler, 'strip' ],
				Shortcode_Handler::PRIORITY_STRIP,
				sprintf( 'A code box makes no sense in a summary, so %s strips instead of rendering.', $filter )
			);

		}

		$this->_assert_hooked(
			'strip_shortcodes_tagnames',
			[ $handler, 'claim_stripped_tags' ],
			null,
			'The tags are never registered with add_shortcode(), so core is told about them here.'
		);

		foreach ( Shortcode_Handler::SAVE_FILTERS as $filter ) {

			$this->_assert_hooked(
				$filter,
				[ $handler, 'protect_save' ],
				Shortcode_Handler::PRIORITY_PROTECT,
				sprintf( 'Code is lifted out of %s before KSES can reach it.', $filter )
			);

			$this->_assert_hooked(
				$filter,
				[ $handler, 'restore_save' ],
				Shortcode_Handler::PRIORITY_RESTORE,
				sprintf( 'And the author\'s original bytes go back into %s, byte for byte.', $filter )
			);

		}

	}

	/**
	 * With `hilite_comments` on, comments render code and are not stripped.
	 *
	 * The setting is read once, when the handler registers, so the wiring is taken down
	 * and put back up, and the assertions go against the new object whose callbacks
	 * are distinct from the booted one's. `_assert_not_hooked()` pins the other half.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_renders_code_in_comments_when_the_setting_is_on(): void {

		$handler = $this->_rewire_handler_with( 'hilite_comments', 'yes' );

		$this->_assert_hooked(
			'comment_text',
			[ $handler, 'protect_display' ],
			Shortcode_Handler::PRIORITY_PROTECT,
			'A comment is on the display list, so its code is protected like a post\'s.'
		);

		$this->_assert_hooked(
			'comment_text',
			[ $handler, 'restore_display' ],
			Shortcode_Handler::PRIORITY_RESTORE,
			'And restored as a rendered code box.'
		);

		$this->_assert_not_hooked(
			'comment_text',
			[ $handler, 'strip' ],
			'A comment which renders its code must not also have it stripped.'
		);

	}

	/**
	 * With `hilite_comments` off, comments are stripped and render nothing.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_strips_comments_when_the_setting_is_off(): void {

		$handler = $this->_rewire_handler_with( 'hilite_comments', 'no' );

		$this->_assert_hooked(
			'comment_text',
			[ $handler, 'strip' ],
			Shortcode_Handler::PRIORITY_STRIP,
			'A comment joins the strip list, exactly as an excerpt does.'
		);

		$this->_assert_not_hooked(
			'comment_text',
			[ $handler, 'protect_display' ],
			'And is not protected for display, because nothing is going to render it.'
		);

		$this->_assert_not_hooked(
			'comment_text',
			[ $handler, 'restore_display' ],
			'Nor restored.'
		);

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
	 * The block registers the hooks the rest of the block suite calls by hand.
	 *
	 * The `init` priority is asserted and not merely the registration. The plugin boots
	 * on `init` at 10, and a callback added at a priority which does not yet exist is
	 * picked up in that same run; one appended to the priority currently running is
	 * never reached, and the block silently vanishes.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_registers_the_blocks_hooks(): void {

		$block = Block::get_instance();

		$this->_assert_hooked(
			'enqueue_block_editor_assets',
			[ $block, 'add_editor_data' ],
			null,
			'The editor is handed the tag list and the language map.'
		);

		$this->_assert_hooked(
			'enqueue_block_assets',
			[ $block, 'enqueue_editor_font' ],
			null,
			'The chosen font reaches the editor canvas, which is an iframe that enqueue_block_editor_assets does not reach.'
		);

		$this->_assert_hooked(
			'init',
			[ $block, 'register_block' ],
			Block::PRIORITY_REGISTER,
			'The block registers behind the priority the plugin boots at, which is what gets it into the same run.'
		);

		$this->assertGreaterThan(
			10,
			Block::PRIORITY_REGISTER,
			'The plugin boots on init at priority 10, so anything at 10 or earlier is appended to a priority already running and never reached.'
		);

	}

	/**
	 * The settings screen's five registrations.
	 *
	 * `plugin_action_links` is the plugin's only multi-argument registration:
	 * `get_action_links()` takes two parameters, so a registration asking for one
	 * fatals on every admin screen with the hook and the priority both correct.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_registers_the_admins_hooks(): void {

		$admin = Admin::get_instance();

		$this->_assert_hooked(
			'rest_api_init',
			[ $admin, 'register_rest_routes' ],
			null,
			'The settings routes exist, which is what the screen saves through.'
		);

		$this->_assert_hooked(
			'admin_menu',
			[ $admin, 'add_menu' ],
			null,
			'The settings page is reachable under Settings.'
		);

		$this->_assert_hooked(
			'admin_enqueue_scripts',
			[ $admin, 'enqueue_assets' ],
			null,
			'The screen gets its CSS and its JavaScript.'
		);

		$this->_assert_hooked(
			'admin_notices',
			[ $admin, 'maybe_show_migration_message' ],
			null,
			'A site which has just migrated is told so.'
		);

		$this->_assert_hooked(
			'plugin_action_links',
			[ $admin, 'get_action_links' ],
			10,
			'The Settings link appears beside the plugin on the plugins screen.'
		);

		$this->assertSame(
			2,
			$this->_hooked_accepted_args( 'plugin_action_links', [ $admin, 'get_action_links' ], 10 ),
			'get_action_links() takes the links and the plugin file, so registering for one argument fatals.'
		);

	}

	/**
	 * The revert tool's one registration.
	 *
	 * The settings screen drives the tool entirely over REST, so without this the
	 * Uninstall section's buttons answer 404.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_registers_the_block_converters_hooks(): void {

		$converter = Block_Converter::get_instance();

		$this->_assert_hooked(
			'rest_api_init',
			[ $converter, 'register_rest_routes' ],
			null,
			'The revert routes exist, which is the only way the tool can be reached.'
		);

	}

	/**
	 * Method to rebuild the shortcode handler against a different option value.
	 *
	 * The old filters are removed first, because a stale registration of the booted
	 * handler would be indistinguishable from a fresh one at the same priority.
	 *
	 * @param string $name  Option name.
	 * @param string $value Option value.
	 *
	 * @return Shortcode_Handler The rebuilt handler, whose callbacks are what the
	 *                           caller asserts against.
	 */
	protected function _rewire_handler_with( string $name, string $value ): Shortcode_Handler {

		$this->_store_option( $name, $value );

		$filters = array_merge(
			[ 'the_content', 'comment_text' ],
			Shortcode_Handler::EXCERPT_FILTERS,
			Shortcode_Handler::SAVE_FILTERS
		);

		foreach ( $filters as $filter ) {
			remove_all_filters( $filter );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Core hooks the plugin registers on.
		}

		$this->_remember( Shortcode_Handler::class );

		return Shortcode_Handler::get_instance();

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
	 * Method to write one plugin setting straight into the stored option array.
	 *
	 * @param string $name  Option name.
	 * @param string $value Option value.
	 *
	 * @return void
	 */
	protected function _store_option( string $name, string $value ): void {

		$options          = (array) get_option( Base::PLUGIN_ID . '-options', [] );
		$options[ $name ] = $value;

		update_option( Base::PLUGIN_ID . '-options', $options );

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

} // end of class

// EOF
