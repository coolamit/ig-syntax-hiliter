<?php
/**
 * The code snippet block.
 *
 * @package iG_Syntax_Hiliter
 */

namespace iG\Syntax_Hiliter;

use iG\Syntax_Hiliter\Traits\Singleton;

/**
 * Registers the block and renders it.
 *
 * The block is dynamic: its code lives in the block delimiter as JSON and never
 * as inner HTML, and the markup is built by the same renderer every other path
 * uses. Nothing here escapes anything — that belongs to the renderer alone.
 */
class Block {

	use Singleton;

	/**
	 * Name of the block.
	 *
	 * @var string
	 */
	public const string NAME = 'igsyntax-hiliter/code';

	/**
	 * Name of the Gist block.
	 *
	 * @var string
	 */
	public const string GIST_NAME = 'igsyntax-hiliter/gist';

	/**
	 * Directory holding the built block, relative to the plugin directory.
	 *
	 * @var string
	 */
	public const string BUILD_DIR = 'assets/build/block';

	/**
	 * Directory holding the built Gist block, relative to the plugin directory.
	 *
	 * The build mirrors the source tree, so this follows `assets/src/block/gist/`.
	 *
	 * @var string
	 */
	protected const string _GIST_BUILD_DIR = 'assets/build/block/gist';

	/**
	 * Name of the JavaScript object carrying the editor's data.
	 *
	 * @var string
	 */
	public const string EDITOR_DATA_OBJECT = 'igSyntaxHiliterEditor';

	/**
	 * Priority the block is registered at.
	 *
	 * One step behind the plugin's own `init` priority. A callback added at a priority
	 * that does not yet exist makes `WP_Hook::resort_active_iterations()` rebuild the
	 * live iteration, so a later priority added during a run is still reached; only the
	 * priority currently running is missed.
	 *
	 * @var int
	 */
	public const int PRIORITY_REGISTER = 11;

	/**
	 * Class constructor.
	 */
	protected function __construct() {

		$this->_register_hooks();

	}

	/**
	 * Method to hook this class up to WordPress.
	 *
	 * The block registers on `init` at `PRIORITY_REGISTER`, one step behind the plugin's boot.
	 *
	 * @return void
	 */
	protected function _register_hooks(): void {

		add_action( 'init', [ $this, 'register_block' ], static::PRIORITY_REGISTER );

		add_action( 'enqueue_block_editor_assets', [ $this, 'add_editor_data' ] );

		// `enqueue_block_assets` and not `enqueue_block_editor_assets`: the editor canvas is
		// an iframe and only the former is fired again while core builds what goes inside it.
		add_action( 'enqueue_block_assets', [ $this, 'enqueue_editor_font' ] );

	}

	/**
	 * Method to put the chosen font on the block while it is being edited.
	 *
	 * Only in the editor: on the front end the asset manager decides during `wp_footer`
	 * and loads nothing until a code box has rendered.
	 *
	 * @return void
	 */
	public function enqueue_editor_font(): void {

		if ( ! is_admin() ) {
			return;
		}

		Asset_Manager::get_instance()->enqueue_for_editor(
			(string) Option::get_instance()->get( 'font' )
		);

	}

	/**
	 * Method to register the block from its built metadata.
	 *
	 * An unbuilt checkout has no `assets/build/`, so a missing `block.json` is passed over quietly.
	 *
	 * @return void
	 */
	public function register_block(): void {

		$blocks = [
			static::BUILD_DIR       => [ $this, 'render' ],
			static::_GIST_BUILD_DIR => [ $this, 'render_gist' ],
		];

		foreach ( $blocks as $build_dir => $callback ) {

			$directory = Helper::get_path( $build_dir );

			if ( ! is_readable( $directory . '/block.json' ) ) {
				continue;
			}

			register_block_type(
				$directory,
				[
					'render_callback' => $callback,
				]
			);

		}

	}

	/**
	 * Method to render one block as a code box.
	 *
	 * `do_blocks()` runs at `the_content` priority 9, after the protector has lifted the
	 * shortcodes out and before the filters it protects against run, so during a
	 * protected run the markup is stashed and comes back once they have finished.
	 *
	 * @param mixed $attributes Block attributes.
	 *
	 * @return string HTML markup for the code box.
	 */
	public function render( mixed $attributes = [] ): string {

		if ( static::_is_excerpt_context() ) {
			return '';
		}

		$snippet = Snippet::from_block_attributes(
			( is_array( $attributes ) ) ? $attributes : [],
			'',
			Shortcode_Handler::show_line_numbers()
		);

		// `'' ===` and not `empty()`: `0` is code.
		if ( '' === trim( $snippet->code ) ) {
			return '';
		}

		$markup = Renderer::get_instance()->render_snippet( $snippet );

		$protector = Content_Protector::get_instance();

		if ( $protector->is_protecting() ) {
			return $protector->stash_markup( $markup );
		}

		return $markup;

	}

	/**
	 * Method to render one Gist block.
	 *
	 * Handed straight to `Gist_Embed`, so the block and the `[github]` shortcode behave
	 * alike. A Gist carries no code, so none of the protect/restore machinery applies.
	 *
	 * @param mixed $attributes Block attributes.
	 *
	 * @return string HTML markup for the embed.
	 */
	public function render_gist( mixed $attributes = [] ): string {

		$attributes = ( is_array( $attributes ) ) ? $attributes : [];
		$url        = $attributes['url'] ?? '';
		$url        = ( is_scalar( $url ) ) ? trim( (string) $url ) : '';

		if ( empty( $url ) ) {
			return '';
		}

		return Gist_Embed::get_instance()->render( [ 'gist' => $url ] );

	}

	/**
	 * Method to check whether the block is being rendered into a summary.
	 *
	 * `wp_trim_excerpt()` renders the blocks `excerpt_allowed_blocks` names and strips the
	 * markup off, so a code box built there would reach the page as prose. This block is
	 * not on that list by default; the check holds if a site puts it there.
	 *
	 * @return bool
	 */
	protected static function _is_excerpt_context(): bool {

		foreach ( Shortcode_Handler::EXCERPT_FILTERS as $filter ) {

			if ( doing_filter( $filter ) ) {
				return true;
			}
		}

		return false;

	}

	/**
	 * Method to hand the editor the data it cannot work out for itself.
	 *
	 * Attached to the script handle the block registration generated.
	 *
	 * @return void
	 */
	public function add_editor_data(): void {

		$block_type = \WP_Block_Type_Registry::get_instance()->get_registered( static::NAME );

		if ( ! $block_type instanceof \WP_Block_Type ) {
			return;
		}

		$handle = $block_type->editor_script_handles[0] ?? '';

		if ( empty( $handle ) ) {
			return;
		}

		wp_add_inline_script(
			$handle,
			sprintf(
				'var %1$s = %2$s;',
				static::EDITOR_DATA_OBJECT,
				wp_json_encode( $this->_get_editor_data() )
			),
			'before'
		);

	}

	/**
	 * Method to collect the data the editor needs.
	 *
	 * The tag list is sent from PHP so the editor claims exactly the tags PHP claims,
	 * the `ig_syntax_hiliter/shortcode_tags` filter included.
	 *
	 * @return array
	 */
	protected function _get_editor_data(): array {

		return [
			'languages'          => Language_Registry::get_instance()->get_choices(),
			'languageAliases'    => $this->_get_language_aliases(),
			'noLanguage'         => Language_Registry::NO_LANGUAGE,
			'legacyTags'         => Legacy_Map::get_tags(),
			'genericTag'         => Legacy_Map::GENERIC_TAG,
			'defaultLineNumbers' => Shortcode_Handler::show_line_numbers(),
		];

	}

	/**
	 * Method to build the map the editor resolves a language name with.
	 *
	 * The dropdown is built from canonical ids alone, so a converted `[html]` snippet
	 * would hold a name no option carries, and writing that back would destroy a working
	 * language.
	 *
	 * @return array Alias or legacy tag to canonical language id.
	 */
	protected function _get_language_aliases(): array {

		$registry = Language_Registry::get_instance();

		// The plugin's own legacy tags go on top of the highlighter's aliases, so a tag this
		// plugin has always owned keeps its meaning: `text` means "show it, do not highlight it".
		$aliases = array_merge( $registry->get_aliases(), Legacy_Map::get_language_map() );
		$map     = [];

		foreach ( $aliases as $alias => $id ) {

			$alias = strtolower( trim( (string) $alias ) );
			$id    = strtolower( trim( (string) $id ) );

			if ( empty( $alias ) ) {
				continue;
			}

			// An entry pointing at a language this site cannot load is dropped: it would put an
			// unhighlightable id into a block attribute and throw away the author's own word.
			// The sentinel is kept; it names no language, which is why `has()` says no to it.
			if ( Language_Registry::NO_LANGUAGE !== $id && ! $registry->has( $id ) ) {
				continue;
			}

			$map[ $alias ] = $id;

		}

		return $map;

	}

} // end of class

// EOF
