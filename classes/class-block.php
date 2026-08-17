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
	const NAME = 'igsyntax-hiliter/code';

	/**
	 * Name of the Gist block.
	 *
	 * @var string
	 */
	const GIST_NAME = 'igsyntax-hiliter/gist';

	/**
	 * Directory holding the built block, relative to the plugin directory.
	 *
	 * @var string
	 */
	const BUILD_DIR = 'build/block';

	/**
	 * Directory holding the built Gist block, relative to the plugin directory.
	 *
	 * The build mirrors the source tree under `build/`, so this follows
	 * `src/block/gist/` rather than sitting beside the block above.
	 *
	 * @var string
	 */
	const GIST_BUILD_DIR = 'build/block/gist';

	/**
	 * Name of the JavaScript object carrying the editor's data.
	 *
	 * @var string
	 */
	const EDITOR_DATA_OBJECT = 'igSyntaxHiliterEditor';

	/**
	 * Whether the hooks have been registered already.
	 *
	 * @var bool
	 */
	protected bool $_hooked = false;

	/**
	 * Method to hook the block up to WordPress.
	 *
	 * @return void
	 */
	public function register_hooks(): void {

		if ( $this->_hooked ) {
			return;
		}

		$this->_hooked = true;

		/*
		 * The plugin boots on `init` itself, so hooking `init` here would append a
		 * callback to the priority already running, which the loop iterating it
		 * never reaches. Register straight away in that case.
		 */
		if ( did_action( 'init' ) ) {
			$this->register_block();
		} else {
			add_action( 'init', [ $this, 'register_block' ] );
		}

		add_action( 'enqueue_block_editor_assets', [ $this, 'add_editor_data' ] );

	}    //end register_hooks()

	/**
	 * Method to register the block from its built metadata.
	 *
	 * A checkout which has never been built has no `build/` directory. That is a
	 * perfectly ordinary state for a source tree, so it is passed over quietly
	 * rather than fataling.
	 *
	 * @return void
	 */
	public function register_block(): void {

		$plugin = Plugin::get_instance();

		$blocks = [
			static::BUILD_DIR      => [ $this, 'render' ],
			static::GIST_BUILD_DIR => [ $this, 'render_gist' ],
		];

		foreach ( $blocks as $build_dir => $callback ) {

			$directory = $plugin->get_path( $build_dir );

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

	}    //end register_block()

	/**
	 * Method to render one block as a code box.
	 *
	 * Blocks are rendered by `do_blocks()` at `the_content` priority 9, which is
	 * after the protector has lifted the shortcodes out and before the filters it
	 * protects against have run. Markup handed back as-is there would face
	 * `wptexturize`, `wpautop` and every priority 10 filter a site has, so during
	 * a protected run it is stashed and comes back once they have all finished.
	 *
	 * @param mixed $attributes Block attributes.
	 *
	 * @return string HTML markup for the code box.
	 */
	public function render( $attributes = [] ): string {

		if ( static::_is_excerpt_context() ) {
			return '';
		}

		$snippet = Snippet::from_block_attributes(
			( is_array( $attributes ) ) ? $attributes : [],
			'',
			Shortcode_Handler::show_line_numbers()
		);

		// An empty snippet renders as nothing on the shortcode path; the two must agree.
		if ( '' === trim( $snippet->code ) ) {
			return '';
		}

		$markup = Renderer::get_instance()->render_snippet( $snippet );

		$protector = Content_Protector::get_instance();

		if ( $protector->is_protecting() ) {
			return $protector->stash_markup( $markup );
		}

		return $markup;

	}    //end render()

	/**
	 * Method to render one Gist block.
	 *
	 * Handed straight to `Gist_Embed`, which is the one place a Gist becomes an
	 * embed. That is what keeps the block and the twenty year old `[github]`
	 * shortcode behaving alike: the same id sanitising, the same link instead of a
	 * script where a script cannot go, and the same setting deciding whether an
	 * embed is allowed in a comment.
	 *
	 * A Gist carries no code of its own, only a reference to one, so none of the
	 * protect then restore machinery around the code block applies here.
	 *
	 * @param mixed $attributes Block attributes.
	 *
	 * @return string HTML markup for the embed.
	 */
	public function render_gist( $attributes = [] ): string {

		$attributes = ( is_array( $attributes ) ) ? $attributes : [];
		$url        = $attributes['url'] ?? '';
		$url        = ( is_scalar( $url ) ) ? trim( (string) $url ) : '';

		if ( '' === $url ) {
			return '';
		}

		return Gist_Embed::get_instance()->render( [ 'gist' => $url ] );

	}    //end render_gist()

	/**
	 * Method to check whether the block is being rendered into a summary.
	 *
	 * `wp_trim_excerpt()` builds an automatic excerpt by rendering the blocks
	 * `excerpt_allowed_blocks` names and then taking the markup off whatever comes
	 * back, so a code box built there would reach the page as prose. This block is
	 * not on that list by default; the check holds the line if a site puts it
	 * there.
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

	}    //end _is_excerpt_context()

	/**
	 * Method to hand the editor the data it cannot work out for itself.
	 *
	 * Attached to the script handle the block registration generated, so nothing
	 * here has to guess what that handle is called.
	 *
	 * @return void
	 */
	public function add_editor_data(): void {

		$block_type = \WP_Block_Type_Registry::get_instance()->get_registered( static::NAME );

		if ( ! $block_type instanceof \WP_Block_Type ) {
			return;
		}

		$handle = $block_type->editor_script_handles[0] ?? '';

		if ( '' === $handle ) {
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

	}    //end add_editor_data()

	/**
	 * Method to collect the data the editor needs.
	 *
	 * The tag list is sent over rather than written into the JavaScript, so that
	 * the editor claims exactly the tags PHP claims — including whatever the
	 * `ig_syntax_hiliter/shortcode_tags` filter has made of them.
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

	}    //end _get_editor_data()

	/**
	 * Method to build the map the editor resolves a language name with.
	 *
	 * The language dropdown is built from canonical ids alone, so a snippet converted
	 * from `[html]` would sit there holding a name no option carries — the control
	 * would show the first option instead, and writing that back would destroy a
	 * language which was highlighting perfectly well. Resolving before the name ever
	 * reaches a block attribute is what closes that, and this is the table it resolves
	 * against.
	 *
	 * @return array Alias or legacy tag to canonical language id.
	 */
	protected function _get_language_aliases(): array {

		$registry = Language_Registry::get_instance();

		/*
		 * The plugin's own legacy tags go on top of the highlighter's aliases, so that a
		 * tag this plugin has always owned keeps the meaning this plugin gave it. `text`
		 * is the case that matters: here it has meant "show it, do not highlight it"
		 * since 2004, whatever the library may one day decide it means.
		 */
		$aliases = array_merge( $registry->get_aliases(), Legacy_Map::get_language_map() );
		$map     = [];

		foreach ( $aliases as $alias => $id ) {

			$alias = strtolower( trim( (string) $alias ) );
			$id    = strtolower( trim( (string) $id ) );

			if ( '' === $alias ) {
				continue;
			}

			/*
			 * An entry pointing at a language this site cannot load is dropped rather than
			 * offered. Taking it would put an id into a block attribute which nothing on
			 * the site can highlight, and would throw away the author's own word — which
			 * the `ig_syntax_hiliter/languages` filter may yet make good. The sentinel is
			 * kept: it names no language, which is exactly why `has()` says no to it.
			 */
			if ( Language_Registry::NO_LANGUAGE !== $id && ! $registry->has( $id ) ) {
				continue;
			}

			$map[ $alias ] = $id;

		}

		return $map;

	}    //end _get_language_aliases()

}    //end of class


//EOF
