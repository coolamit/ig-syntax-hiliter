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
	 * Directory holding the built block, relative to the plugin directory.
	 *
	 * @var string
	 */
	const BUILD_DIR = 'build/block';

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

		$directory = Plugin::get_instance()->get_path( static::BUILD_DIR );

		if ( ! is_readable( $directory . '/block.json' ) ) {
			return;
		}

		register_block_type(
			$directory,
			[
				'render_callback' => [ $this, 'render' ],
			]
		);

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
			'legacyTags'         => Legacy_Map::get_tags(),
			'genericTag'         => Legacy_Map::GENERIC_TAG,
			'defaultLineNumbers' => Shortcode_Handler::show_line_numbers(),
		];

	}    //end _get_editor_data()

}    //end of class


//EOF
