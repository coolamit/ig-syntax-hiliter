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
 * This file exists because the tool for it was built and then pointed at one class.
 * `Hook_Test_Helpers` arrived in session 29 for exactly this job — it sees every
 * priority a callback sits at, where `has_action()` reports only the first, and it
 * tells a priority-0 registration apart from an absent one. It was then used on
 * `Block` alone, which left `Asset_Manager`'s two registrations, `Shortcode_Handler`'s
 * eleven, `Gist_Embed`'s six, `Admin`'s five and `Block_Converter`'s one unasserted:
 * any one of them could have been deleted with all three tiers green.
 *
 * That is not hypothetical. The registrations were moved out of `Plugin` and into the
 * classes which own them, and then moved again inside those classes, with nothing
 * watching either move.
 *
 * Everything here is named by the class constant rather than by the number behind it,
 * so an assertion travels with the constant it is about. A test which spelled `1` out
 * would go on passing after `PRIORITY_PROTECT` moved, and would be asserting a number
 * nothing in the plugin uses any more.
 */
class Hook_Registration_Test extends WP_UnitTestCase {

	use Hook_Test_Helpers;
	use Pipeline_Test_Helpers;

	/**
	 * Services this case rebuilt, to be put back in `tear_down()`.
	 *
	 * A rewired service is a different object with different callbacks, and its
	 * registrations land in the global filter registry. Leaving one there hands every
	 * later suite in the process a plugin whose wiring is not the wiring it booted
	 * with.
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
	 * Both priorities are asserted because the second pass is the whole point of the
	 * arrangement: plenty of things render content from `wp_footer` itself, and a
	 * snippet which appeared that way reaches the page with no Prism at all if the
	 * registration at `PRIORITY_DECIDE_AGAIN` goes missing. Core prints the footer
	 * scripts at 20, so 19 is the last moment which still reaches the page.
	 *
	 * @return void
	 */
	public function test_the_asset_manager_registers_its_hooks(): void {

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

	}

	/**
	 * The shortcode handler's eleven registrations, on the settings it boots with.
	 *
	 * `PRIORITY_STRIP_BODY` is 0, which `has_filter()` reports as a falsy `0` and an
	 * absent callback as `false`. That is the registration this file is least able to
	 * lose quietly, and it is the earlier of the two strips the automatic excerpt
	 * needs — core's own `strip_shortcodes()` cannot be trusted with source code.
	 *
	 * @return void
	 */
	public function test_the_shortcode_handler_registers_its_hooks(): void {

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
	 * The setting is read when the handler registers and never again, so the only way
	 * to exercise the other branch is to take the wiring down and put it back up —
	 * the pattern `Backward_Compatibility_Test::_rewire_with_option()` already proves.
	 * The assertions go against the **new** object, whose callbacks are distinct
	 * identities from the booted one's, so the two cannot be confused.
	 *
	 * `_assert_not_hooked()` is what pins the other half. Without it a handler which
	 * registered `comment_text` on both lists would pass, and a comment would be
	 * stripped and rendered at once.
	 *
	 * @return void
	 */
	public function test_comments_render_code_when_the_setting_is_on(): void {

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
	 * @return void
	 */
	public function test_comments_are_stripped_when_the_setting_is_off(): void {

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
	 * `PRIORITY_EMBED` is 9, which is ahead of `wptexturize` — that would curl the
	 * quotes in the `gist="…"` URL and the address would stop resolving.
	 *
	 * The two `wp_footer` registrations are the asset manager's own priorities and
	 * are named from that class, because they are the same two moments and for the
	 * same reason. A page carrying nothing but a Gist loads no stylesheet of this
	 * plugin's otherwise, which is why this class does its own enqueuing at all.
	 *
	 * @return void
	 */
	public function test_the_gist_embed_registers_its_hooks(): void {

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
	 * @return void
	 */
	public function test_comments_embed_a_gist_when_the_setting_is_on(): void {

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
	 * The priorities are equal, so `parse()` cannot be asked which list it is on by
	 * priority alone — the callback is the same method either way. What separates the
	 * two branches is the property the constructor appends `comment_text` to, so this
	 * case asserts the list rather than the number.
	 *
	 * @return void
	 */
	public function test_comments_link_to_a_gist_when_the_setting_is_off(): void {

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
	 * Every case in `Block_Editor_Assets_Test` reaches for `Block::get_instance()->…()`
	 * directly. That is fast and it keeps each assertion on what the method does, but
	 * it leaves the `add_action()` lines themselves invisible: delete the one on
	 * `enqueue_block_assets` and the editor silently loses its font while that whole
	 * file stays green.
	 *
	 * The `init` priority is asserted and not merely the registration, because the
	 * priority is the whole of why this works. The plugin boots on `init` at priority
	 * 10, and a callback added at a priority which does not yet exist is picked up in
	 * that same run — `WP_Hook::resort_active_iterations()` rebuilds the live
	 * iteration array and moves the pointer past what has already run. Register the
	 * block at priority 10 instead and it is appended to the priority currently
	 * running, which the loop never reaches: the block silently vanishes, which looks
	 * exactly like a checkout that was never built.
	 *
	 * @return void
	 */
	public function test_the_block_registers_its_hooks(): void {

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
	 * `plugin_action_links` is the plugin's only multi-argument registration, and it
	 * is the one thing here which no priority assertion can see: `get_action_links()`
	 * takes two parameters, so a registration asking for one fatals on every admin
	 * screen while the hook and the priority are both perfectly correct.
	 *
	 * @return void
	 */
	public function test_the_admin_registers_its_hooks(): void {

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
	 * It is one line and it is the whole of the tool's reachability: the settings
	 * screen drives it entirely over REST, so without this the Uninstall section's
	 * buttons answer 404 and a site cannot get its snippets back out of blocks.
	 *
	 * @return void
	 */
	public function test_the_block_converter_registers_its_hooks(): void {

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
	 * The handler reads `hilite_comments` once, when it registers, so the only way to
	 * exercise the other setting is to take the wiring down and put it back up. The
	 * old filters are removed first, because the booted handler is registered on every
	 * one of them and a stale registration would be indistinguishable from a fresh
	 * one at the same priority.
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
	 * `Option` goes with it, because the service reads its settings through that
	 * singleton and the booted one is holding the array as it was before this case
	 * wrote to it.
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
	 * The list is protected, and it is protected for the right reason — nothing
	 * outside the class has any business changing it. It is read through reflection
	 * rather than duplicated here because which filters are on it is exactly what
	 * `gist_in_comments` decides, so a copy in this file would agree with itself
	 * whatever the plugin did.
	 *
	 * @param Gist_Embed $gist The embed to read.
	 *
	 * @return array Filter names.
	 */
	protected function _gist_link_filters( Gist_Embed $gist ): array {

		return (array) ( new ReflectionProperty( Gist_Embed::class, '_link_filters' ) )->getValue( $gist );

	}

}    //end of class


//EOF
