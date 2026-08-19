<?php
/**
 * Conditional loading of the front end highlighting assets.
 *
 * @package iG_Syntax_Hiliter
 */

namespace iG\Syntax_Hiliter;

use iG\Syntax_Hiliter\Traits\Singleton;

/**
 * Decides what the browser is asked to download, and whether it is asked at all.
 *
 * Nothing is enqueued unless a snippet was rendered on the page. `Renderer` raises
 * that signal for every box it renders, and is the only caller of
 * `snippet_rendered()` — every path which produces a code box goes through it.
 *
 * This class and the renderer know the highlighting engine is Prism, and so does
 * `Themes`, which holds the two directories the theme stylesheets are vendored
 * into. Nothing else does. The catalogues themselves — which themes exist and which
 * fonts are offered — live in `Themes` and `Fonts`; what this class decides is what
 * a given page is asked to download, which is the one question `_handle()` and every
 * `wp_enqueue_*` call below are about.
 */
class Asset_Manager {

	use Singleton;

	/**
	 * Prefix shared by every script and style handle the plugin registers.
	 *
	 * @var string
	 */
	public const string HANDLE_PREFIX = 'ig-syntax-hiliter';

	/**
	 * Filter which supplies the URL the language files are fetched from.
	 *
	 * `public` although nothing outside this class reads it, which is the one place
	 * that rule is bent on purpose. This is one of the plugin's four extension points,
	 * and the other three are public because a test happens to name them — so making
	 * this one protected would leave a single filter that a caller cannot reference
	 * symbolically, for no reason a caller could ever discover.
	 *
	 * @var string
	 */
	public const string FILTER_COMPONENTS_URL = 'ig_syntax_hiliter/prism_components_url';

	/**
	 * Path of the highlighter library, relative to the assets directory.
	 *
	 * @var string
	 */
	public const string LIBRARY_PATH = 'lib/prism';

	/**
	 * `wp_footer` priority at which the assets are first decided.
	 *
	 * @var int
	 */
	public const int PRIORITY_DECIDE = 1;

	/**
	 * `wp_footer` priority at which the decision is taken again.
	 *
	 * Core prints the footer scripts and the late styles from `wp_footer` at 20, so
	 * this is the last moment at which enqueuing anything still reaches the page.
	 *
	 * @var int
	 */
	public const int PRIORITY_DECIDE_AGAIN = 19;

	/**
	 * Whether a code box has been rendered on this page.
	 *
	 * @var bool
	 */
	protected bool $_has_snippets = false;

	/**
	 * Whether any code box on this page shows line numbers.
	 *
	 * @var bool
	 */
	protected bool $_needs_line_numbers = false;

	/**
	 * Whether any code box on this page highlights particular lines.
	 *
	 * @var bool
	 */
	protected bool $_needs_line_highlight = false;

	/**
	 * Canonical ids of the languages used on this page.
	 *
	 * @var array
	 */
	protected array $_languages = [];

	/**
	 * Whether the front end's font values have been added already.
	 *
	 * The decision is taken twice during `wp_footer`, and the enqueue has to run both
	 * times because the second pass is what catches a snippet rendered from the footer
	 * itself. Adding the values twice only prints them twice.
	 *
	 * @var bool
	 */
	protected bool $_font_styled = false;

	/**
	 * Whether the editor's font rule has been added already.
	 *
	 * @var bool
	 */
	protected bool $_editor_font_styled = false;

	/**
	 * Class constructor.
	 */
	protected function __construct() {

		$this->_register_hooks();

	}    //end __construct()

	/**
	 * Method to hook this class up to WordPress.
	 *
	 * Assets are decided at `wp_footer` priority 1, late enough for the whole page to
	 * have rendered and so for the snippet signal to be trustworthy, and early enough
	 * that a theme printing its own scripts part way down the footer still gets them.
	 *
	 * The decision is then taken a second time, just before core prints the footer.
	 * Plenty of things render content from `wp_footer` itself — a modal, a late list
	 * of related posts, a comment list built on demand — and a snippet which appeared
	 * that way used to end up on the page with no highlighting at all and no way of
	 * ever getting any. `enqueue()` is idempotent, so the second pass costs a few
	 * no-op calls when there is nothing new to add.
	 *
	 * @return void
	 */
	protected function _register_hooks(): void {

		add_action( 'wp_footer', [ $this, 'enqueue' ], static::PRIORITY_DECIDE );
		add_action( 'wp_footer', [ $this, 'enqueue' ], static::PRIORITY_DECIDE_AGAIN );

		add_filter( 'body_class', [ $this, 'get_body_classes' ] );

	}    //end _register_hooks()

	/**
	 * Method to put the brace matching classes on the page.
	 *
	 * Prism's `match-braces` plugin settles whether it runs by walking up from the
	 * `code` element looking for a class, so the answer can sit on `<body>` and no
	 * code box has to carry anything. That is what keeps this out of `Renderer`,
	 * which is the plugin's WordPress-free core and has no business reading a site
	 * wide setting — and it hands a site owner a per post opt-out for nothing, since
	 * the walk stops at the nearest `no-match-braces` ancestor.
	 *
	 * The classes go on whether or not the page has a snippet, because `body_class`
	 * is answered in the head and nothing has rendered by then. A class naming a
	 * script which was never loaded does nothing at all.
	 *
	 * **`match-braces` goes on when either setting is on**, because it is the class
	 * which makes the script do anything: the `brace-level-N` classes the rainbow
	 * rules colour are added inside the same hook the class gates, so the colours
	 * without it would be a setting which does nothing.
	 *
	 * **`no-brace-hover` and `no-brace-select` are what keep the two settings apart**
	 * in that case. The plugin defaults both interactions on and turns them off by
	 * exactly those two names, so without them switching the colours on would switch
	 * the hover and the click on with them.
	 *
	 * @param mixed $classes Classes the body element has so far.
	 *
	 * @return mixed
	 */
	public function get_body_classes( mixed $classes ): mixed {

		$classes = ( is_array( $classes ) ) ? $classes : [];

		$matching = Shortcode_Handler::is_plugin_option_on( 'match_braces', 'yes' );
		$rainbow  = Shortcode_Handler::is_plugin_option_on( 'rainbow_braces', 'no' );

		if ( ! $matching && ! $rainbow ) {
			return $classes;
		}

		$classes[] = 'match-braces';

		if ( $rainbow ) {
			$classes[] = 'rainbow-braces';
		}

		if ( ! $matching ) {
			$classes[] = 'no-brace-hover';
			$classes[] = 'no-brace-select';
		}

		return $classes;

	}    //end get_body_classes()

	/**
	 * Method to signal that a snippet is present on the page.
	 *
	 * Call it with no arguments to say only "there is at least one snippet here".
	 *
	 * @param string|null $language_id        Canonical language id of the snippet, if it is known.
	 * @param bool        $has_line_numbers   Whether the snippet shows line numbers.
	 * @param bool        $has_line_highlight Whether the snippet highlights particular lines.
	 *
	 * @return void
	 */
	public function snippet_rendered( ?string $language_id = null, bool $has_line_numbers = false, bool $has_line_highlight = false ): void {

		$this->_has_snippets = true;

		$this->_needs_line_numbers   = ( $this->_needs_line_numbers || $has_line_numbers );
		$this->_needs_line_highlight = ( $this->_needs_line_highlight || $has_line_highlight );

		$language_id = strtolower( trim( (string) $language_id ) );

		if ( ! empty( $language_id ) && Language_Registry::NO_LANGUAGE !== $language_id ) {
			$this->_languages[ $language_id ] = $language_id;
		}

	}    //end snippet_rendered()

	/**
	 * Method to check whether any snippet is present on the page.
	 *
	 * @return bool
	 */
	public function has_snippets(): bool {
		return $this->_has_snippets;
	}    //end has_snippets()

	/**
	 * Method to get the languages used on the page so far.
	 *
	 * @return array Numerically indexed list of canonical language ids.
	 */
	public function get_languages(): array {
		return array_values( $this->_languages );
	}    //end get_languages()

	/**
	 * Method to enqueue the front end assets.
	 *
	 * Safe to call more than once in a request: enqueuing a handle which is already
	 * registered does nothing, and the one call which is not idempotent — handing the
	 * setup script its configuration — is guarded.
	 *
	 * @return void
	 */
	public function enqueue(): void {

		if ( is_admin() || ! $this->_has_snippets ) {
			return;
		}

		$this->_enqueue_theme();
		$this->_enqueue_font( Shortcode_Handler::get_plugin_option( 'font', Fonts::FONT_NONE ) );
		$this->_enqueue_engine();
		$this->_enqueue_plugins();
		$this->_enqueue_setup();

	}    //end enqueue()

	/**
	 * Method to enqueue everything a live preview of a code box needs.
	 *
	 * The settings page shows one, so that a site owner picking from 43 themes can
	 * see what they are picking. This method exists so that the screen can ask for a
	 * code box without knowing that a code box is made of Prism — that is this
	 * class's knowledge and the renderer's, and nowhere else's.
	 *
	 * **Every plugin is enqueued, whatever the settings say.** The front end loads
	 * only what its page needs, because a reader cannot change the settings from it.
	 * The preview's whole purpose is that the toolbar, the copy button, the line
	 * numbers and the brace matching can be switched on and off in front of the
	 * reader without a reload, so all of them have to be on the page already; which
	 * of them is *shown* is decided in the browser.
	 *
	 * Line highlighting is the one of the five with no setting beside it, and it is
	 * loaded for the opposite reason: it is decided per code box, by the block's
	 * highlight field or the shortcode's `highlight` attribute, so the preview
	 * snippet asks for it and it is shown from the moment the page opens. A reader
	 * who cannot switch it is exactly the reader who has never seen it.
	 *
	 * @param string $theme Slug of the theme to load, or the "no theme" value.
	 * @param string $font  Slug of the font to load, or the "no font" value.
	 *
	 * @return void
	 */
	public function enqueue_for_preview( string $theme, string $font = Fonts::FONT_NONE ): void {

		$this->_enqueue_theme( $theme );

		$this->_enqueue_font( $font );

		$this->_enqueue_engine();

		static::_enqueue_toolbar();
		static::_enqueue_copy_button();
		static::_enqueue_line_numbers();
		static::_enqueue_line_highlight();
		static::_enqueue_match_braces();

		$this->_enqueue_setup();

	}    //end enqueue_for_preview()

	/**
	 * Method to get the element id of the theme stylesheet's `link` tag.
	 *
	 * The preview swaps that tag's `href` as the dropdown is changed, so it has to be
	 * able to find it. WordPress builds the id from the handle, and the handle is
	 * this class's business, so the id is answered here rather than spelled out in
	 * JavaScript where it could go stale without a word.
	 *
	 * @return string
	 */
	public static function get_theme_style_id(): string {
		return sprintf( '%s-css', static::_handle( 'theme' ) );
	}    //end get_theme_style_id()

	/**
	 * Method to get the element id of the webfont stylesheet's `link` tag.
	 *
	 * The counterpart of `get_theme_style_id()`, and there for the same reason: the
	 * preview swaps that tag's `href` as the dropdown is changed, and the handle the id
	 * is built from is this class's business.
	 *
	 * @return string
	 */
	public static function get_font_style_id(): string {
		return sprintf( '%s-css', static::_handle( 'font' ) );
	}    //end get_font_style_id()

	/**
	 * Method to get the URL the language files are fetched from at runtime.
	 *
	 * @return string URL with a trailing slash.
	 */
	public function get_components_url(): string {

		$url = trailingslashit(
			Helper::get_asset_url( sprintf( '%s/components', static::LIBRARY_PATH ) )
		);

		/**
		 * Filters the URL the highlighter fetches language files from.
		 *
		 * Only one directory can be served this way.
		 *
		 * @param string $url URL with a trailing slash.
		 */
		return (string) apply_filters( static::FILTER_COMPONENTS_URL, $url );    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook name is the prefixed class constant above.

	}    //end get_components_url()

	/**
	 * Method to enqueue the chosen theme stylesheet, and the plugin's own chrome.
	 *
	 * The preview passes its theme in, because the settings page is showing a theme
	 * which is being picked rather than the one which is stored. Everything else about
	 * the two is the same, which is why they are one method: this pair of stylesheets
	 * is what a code box is dressed in, and a preview dressing itself a second way
	 * would be a preview of something the front end never renders.
	 *
	 * @param string|null $theme Optional. Theme slug to load. The stored setting when none is named.
	 *
	 * @return void
	 */
	protected function _enqueue_theme( ?string $theme = null ): void {

		$theme = $theme ?? Shortcode_Handler::get_plugin_option( 'theme', Themes::DEFAULT_THEME );

		$theme = Themes::resolve_theme( $theme );

		if ( Themes::THEME_NONE !== $theme ) {

			wp_enqueue_style(
				static::_handle( 'theme' ),
				Helper::get_asset_url( Themes::get_theme_file( $theme ) ),
				[],
				static::_get_version()
			);

		}

		wp_enqueue_style(
			static::_handle( 'chrome' ),
			Helper::get_asset_url( 'build/css/frontend-chrome.css' ),
			[],
			static::_get_version()
		);

	}    //end _enqueue_theme()

	/**
	 * Method to enqueue the chosen webfont, and the rule which applies it.
	 *
	 * Nothing at all happens where no font is chosen, which is the default: the page
	 * makes no request to another host and the code boxes keep the font the chrome
	 * stylesheet has always given them.
	 *
	 * @param string $font Font setting value.
	 *
	 * @return void
	 */
	protected function _enqueue_font( string $font ): void {

		$font = Fonts::resolve_font( $font );

		if ( Fonts::FONT_NONE === $font ) {
			return;
		}

		/*
		 * No version. This URL belongs to somebody else and a `?ver=` of ours on the
		 * end of it is both meaningless there and a second cache key for the same file.
		 */
		wp_enqueue_style(
			static::_handle( 'font' ),
			Fonts::get_font_url( $font ),
			[],
			null  // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Deliberate. This URL is not this plugin's, so a version of this plugin's on the end of it is meaningless there and a second cache key for the same file.
		);

		if ( $this->_font_styled ) {
			return;
		}

		$this->_font_styled = true;

		wp_add_inline_style( static::_handle( 'chrome' ), Fonts::get_font_css( $font ) );

	}    //end _enqueue_font()

	/**
	 * Method to enqueue the chosen webfont for the block editor.
	 *
	 * **The editor canvas is an iframe, and a font loaded by the page around it does
	 * not exist inside it** — each document keeps its own fonts. So the stylesheet has
	 * to be enqueued from `enqueue_block_assets`, which core fires again while it
	 * builds the iframe's own markup, and not from `enqueue_block_editor_assets`,
	 * which it does not.
	 *
	 * That is also why the inline rule is guarded and the enqueue is not: this runs
	 * twice in an editor request, once for the page and once for the iframe, and the
	 * two share the registered style objects but keep separate queues. Adding the rule
	 * both times would print it twice.
	 *
	 * @param string $font Font setting value.
	 *
	 * @return void
	 */
	public function enqueue_for_editor( string $font ): void {

		$font = Fonts::resolve_font( $font );

		if ( Fonts::FONT_NONE === $font ) {
			return;
		}

		wp_enqueue_style(
			static::_handle( 'editor-font' ),
			Fonts::get_font_url( $font ),
			[],
			null  // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Deliberate, for the reason `_enqueue_font()` gives.
		);

		if ( $this->_editor_font_styled ) {
			return;
		}

		$this->_editor_font_styled = true;

		wp_add_inline_style( static::_handle( 'editor-font' ), Fonts::get_editor_font_css( $font ) );

	}    //end enqueue_for_editor()

	/**
	 * Method to enqueue the highlighting engine and its language loader.
	 *
	 * @return void
	 */
	protected function _enqueue_engine(): void {

		wp_enqueue_script(
			static::_handle( 'engine' ),
			static::_get_library_url( 'components/prism-core.min.js' ),
			[],
			static::_get_version(),
			true
		);

		wp_enqueue_script(
			static::_handle( 'autoloader' ),
			static::_get_library_url( 'plugins/autoloader/prism-autoloader.min.js' ),
			[ static::_handle( 'engine' ) ],
			static::_get_version(),
			true
		);

	}    //end _enqueue_engine()

	/**
	 * Method to enqueue the engine plugins the page actually needs.
	 *
	 * @return void
	 */
	protected function _enqueue_plugins(): void {

		if ( Shortcode_Handler::is_plugin_option_on( 'toolbar', 'yes' ) ) {

			static::_enqueue_toolbar();

			if ( Shortcode_Handler::is_plugin_option_on( 'copy_code', 'yes' ) ) {
				static::_enqueue_copy_button();
			}
		}

		/*
		 * Line highlighting reads the rendered line numbers when they are present, so
		 * the two load together to keep the order deterministic.
		 */
		if ( $this->_needs_line_numbers || $this->_needs_line_highlight ) {
			static::_enqueue_line_numbers();
		}

		/*
		 * Either setting needs the script. The spans the rainbow colours are painted
		 * on are the ones this script creates, so the stylesheet alone paints nothing.
		 */
		if (
			Shortcode_Handler::is_plugin_option_on( 'match_braces', 'yes' )
			|| Shortcode_Handler::is_plugin_option_on( 'rainbow_braces', 'no' )
		) {
			static::_enqueue_match_braces();
		}

		if ( $this->_needs_line_highlight ) {
			static::_enqueue_line_highlight();
		}

	}    //end _enqueue_plugins()

	/**
	 * Method to enqueue the toolbar plugin, and the language name it shows.
	 *
	 * The language name is a toolbar item, so it shows on hover beside the copy
	 * button and shows nothing at all when the toolbar is off. It carries its own
	 * title for every language the library has, read from the same manifest the
	 * language registry reads, and it needs no stylesheet of its own.
	 *
	 * @return void
	 */
	protected static function _enqueue_toolbar(): void {

		$version = static::_get_version();

		wp_enqueue_style(
			static::_handle( 'toolbar' ),
			static::_get_library_url( 'plugins/toolbar/prism-toolbar.min.css' ),
			[],
			$version
		);

		wp_enqueue_script(
			static::_handle( 'toolbar' ),
			static::_get_library_url( 'plugins/toolbar/prism-toolbar.min.js' ),
			[ static::_handle( 'engine' ) ],
			$version,
			true
		);

		wp_enqueue_script(
			static::_handle( 'show-language' ),
			static::_get_library_url( 'plugins/show-language/prism-show-language.min.js' ),
			[ static::_handle( 'toolbar' ) ],
			$version,
			true
		);

	}    //end _enqueue_toolbar()

	/**
	 * Method to enqueue the copy to clipboard button.
	 *
	 * It is a toolbar item, so the toolbar has to be enqueued as well — which is
	 * what the dependency below says rather than merely assumes.
	 *
	 * @return void
	 */
	protected static function _enqueue_copy_button(): void {

		wp_enqueue_script(
			static::_handle( 'copy-to-clipboard' ),
			static::_get_library_url( 'plugins/copy-to-clipboard/prism-copy-to-clipboard.min.js' ),
			[ static::_handle( 'toolbar' ) ],
			static::_get_version(),
			true
		);

	}    //end _enqueue_copy_button()

	/**
	 * Method to enqueue the line numbers plugin.
	 *
	 * @return void
	 */
	protected static function _enqueue_line_numbers(): void {

		$version = static::_get_version();

		wp_enqueue_style(
			static::_handle( 'line-numbers' ),
			static::_get_library_url( 'plugins/line-numbers/prism-line-numbers.min.css' ),
			[],
			$version
		);

		wp_enqueue_script(
			static::_handle( 'line-numbers' ),
			static::_get_library_url( 'plugins/line-numbers/prism-line-numbers.min.js' ),
			[ static::_handle( 'engine' ) ],
			$version,
			true
		);

	}    //end _enqueue_line_numbers()

	/**
	 * Method to enqueue the line highlight plugin.
	 *
	 * The script asks for the line numbers handle rather than the engine's, because
	 * the plugin reads the rendered numbers to place its band when a box carries
	 * them. Every caller loads the line numbers plugin first, so this is a statement
	 * of the order and not a way of arranging it.
	 *
	 * @return void
	 */
	protected static function _enqueue_line_highlight(): void {

		$version = static::_get_version();

		wp_enqueue_style(
			static::_handle( 'line-highlight' ),
			static::_get_library_url( 'plugins/line-highlight/prism-line-highlight.min.css' ),
			[],
			$version
		);

		wp_enqueue_script(
			static::_handle( 'line-highlight' ),
			static::_get_library_url( 'plugins/line-highlight/prism-line-highlight.min.js' ),
			[ static::_handle( 'line-numbers' ) ],
			$version,
			true
		);

	}    //end _enqueue_line_highlight()

	/**
	 * Method to enqueue the brace matching plugin.
	 *
	 * It carries its own stylesheet, which holds the outline drawn around a hovered
	 * or selected pair and the twelve nesting colours. Four of the bundled themes
	 * colour the nesting themselves and win wherever they are loaded, because they
	 * say `.token.token.punctuation.brace-level-N` at 0-4-0 against this file's
	 * 0-3-0 — deliberately, and the doubled class is upstream's own doing. The
	 * `opacity` this file sets goes on applying, since a theme sets only the colour.
	 *
	 * @return void
	 */
	protected static function _enqueue_match_braces(): void {

		$version = static::_get_version();

		wp_enqueue_style(
			static::_handle( 'match-braces' ),
			static::_get_library_url( 'plugins/match-braces/prism-match-braces.min.css' ),
			[],
			$version
		);

		wp_enqueue_script(
			static::_handle( 'match-braces' ),
			static::_get_library_url( 'plugins/match-braces/prism-match-braces.min.js' ),
			[ static::_handle( 'engine' ) ],
			$version,
			true
		);

	}    //end _enqueue_match_braces()

	/**
	 * Method to enqueue the plugin's own front end script.
	 *
	 * @return void
	 */
	protected function _enqueue_setup(): void {

		$handle       = static::_handle( 'setup' );
		$dependencies = [ static::_handle( 'autoloader' ) ];

		if ( wp_script_is( static::_handle( 'toolbar' ), 'enqueued' ) ) {
			$dependencies[] = static::_handle( 'toolbar' );
		}

		wp_enqueue_script(
			$handle,
			Helper::get_asset_url( 'build/js/ig-prism-setup.js' ),
			$dependencies,
			static::_get_version(),
			true
		);

		/*
		 * Localising a handle twice does not replace the configuration it already
		 * carries, it prepends to it, and a page which decided its assets more than
		 * once would end up with two copies of the same object. The handle itself is
		 * asked whether it has been given one, rather than a flag of our own, so that
		 * the answer cannot outlive the registration it is about.
		 */
		if ( false !== wp_scripts()->get_data( $handle, 'data' ) ) {
			return;
		}

		wp_localize_script(
			$handle,
			'igSyntaxHiliter',
			[
				'componentsUrl' => $this->get_components_url(),
			]
		);

	}    //end _enqueue_setup()

	/**
	 * Method to build a script or style handle.
	 *
	 * @param string $name Handle suffix.
	 *
	 * @return string
	 */
	protected static function _handle( string $name ): string {
		return sprintf( '%s-%s', static::HANDLE_PREFIX, $name );
	}    //end _handle()

	/**
	 * Method to get the URL of a file in the bundled library.
	 *
	 * @param string $path Path relative to the library directory.
	 *
	 * @return string
	 */
	protected static function _get_library_url( string $path ): string {

		return Helper::get_asset_url(
			sprintf( '%s/%s', static::LIBRARY_PATH, ltrim( $path, '/' ) )
		);

	}    //end _get_library_url()

	/**
	 * Method to get the version string used to bust asset caches.
	 *
	 * @return string
	 */
	protected static function _get_version(): string {
		return Helper::get_version( '0' );
	}    //end _get_version()

}    //end of class


//EOF
