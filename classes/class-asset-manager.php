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
 * Nothing is enqueued unless a snippet was rendered; `Renderer` is the only caller of
 * `snippet_rendered()`. This class, `Renderer` and `Themes` are the only things that
 * know the engine is Prism.
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
	 * One of the plugin's extension points, so it is public and referenceable.
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

	}

	/**
	 * Method to hook this class up to WordPress.
	 *
	 * Decided at `wp_footer` 1 — late enough for the snippet signal to be trustworthy,
	 * early enough for a theme printing scripts mid-footer. Taken again just before core
	 * prints the footer, to catch a snippet rendered from `wp_footer` itself. `enqueue()`
	 * is idempotent.
	 *
	 * @return void
	 */
	protected function _register_hooks(): void {

		add_action( 'wp_footer', [ $this, 'enqueue' ], static::PRIORITY_DECIDE );
		add_action( 'wp_footer', [ $this, 'enqueue' ], static::PRIORITY_DECIDE_AGAIN );

		add_filter( 'body_class', [ $this, 'get_body_classes' ] );

	}

	/**
	 * Method to put the brace matching classes on the page.
	 *
	 * Prism's `match-braces` settles by walking up for a class, so it can sit on `<body>`
	 * — which keeps this out of `Renderer` and gives a per-post opt-out via `no-match-braces`.
	 * Classes go on whether or not the page has a snippet, because `body_class` is answered
	 * in the head. `match-braces` goes on when either setting is on, because the
	 * `brace-level-N` classes are added inside the hook it gates; `no-brace-hover` and
	 * `no-brace-select` keep the two settings apart, since the plugin defaults both
	 * interactions on.
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

	}

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

	}

	/**
	 * Method to check whether any snippet is present on the page.
	 *
	 * @return bool
	 */
	public function has_snippets(): bool {
		return $this->_has_snippets;
	}

	/**
	 * Method to get the languages used on the page so far.
	 *
	 * @return array Numerically indexed list of canonical language ids.
	 */
	public function get_languages(): array {
		return array_values( $this->_languages );
	}

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

	}

	/**
	 * Method to enqueue everything a live preview of a code box needs.
	 *
	 * Lets the settings screen ask for a code box without knowing it is made of Prism.
	 * Every plugin is enqueued whatever the settings say, because the preview toggles
	 * them in the browser without a reload. Line highlighting has no setting, so it is
	 * loaded and shown from the start.
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

	}

	/**
	 * Method to get the element id of the theme stylesheet's `link` tag.
	 *
	 * The preview swaps that tag's `href`; the id is built from the handle, which is this
	 * class's business.
	 *
	 * @return string
	 */
	public static function get_theme_style_id(): string {
		return sprintf( '%s-css', static::_handle( 'theme' ) );
	}

	/**
	 * Method to get the element id of the webfont stylesheet's `link` tag.
	 *
	 * The counterpart of `get_theme_style_id()`, for the same reason.
	 *
	 * @return string
	 */
	public static function get_font_style_id(): string {
		return sprintf( '%s-css', static::_handle( 'font' ) );
	}

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

	}

	/**
	 * Method to enqueue the chosen theme stylesheet, and the plugin's own chrome.
	 *
	 * The preview passes its theme in because it is showing a theme being picked rather
	 * than the one stored.
	 *
	 * @param string|null $theme Optional. Theme slug to load. The stored setting when none is
	 *                           named.
	 *
	 * @return void
	 */
	protected function _enqueue_theme( ?string $theme = null ): void {

		$theme = $theme ?? Shortcode_Handler::get_plugin_option( 'theme', Themes::DEFAULT_THEME );

		$theme = Themes::get_instance()->resolve_theme( $theme );

		if ( Themes::THEME_NONE !== $theme ) {

			wp_enqueue_style(
				static::_handle( 'theme' ),
				Helper::get_asset_url( Themes::get_instance()->get_theme_file( $theme ) ),
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

	}

	/**
	 * Method to enqueue the chosen webfont, and the rule which applies it.
	 *
	 * With no font chosen, the default, the page makes no request to another host.
	 *
	 * @param string $font Font setting value.
	 *
	 * @return void
	 */
	protected function _enqueue_font( string $font ): void {

		$font = Fonts::get_instance()->resolve_font( $font );

		if ( Fonts::FONT_NONE === $font ) {
			return;
		}

		wp_enqueue_style(
			static::_handle( 'font' ),
			Fonts::get_instance()->get_font_url( $font ),
			[],
			null  // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- This URL is not this plugin's.
		);

		if ( $this->_font_styled ) {
			return;
		}

		$this->_font_styled = true;

		wp_add_inline_style( static::_handle( 'chrome' ), Fonts::get_instance()->get_font_css( $font ) );

	}

	/**
	 * Method to enqueue the chosen webfont for the block editor.
	 *
	 * The editor canvas is an iframe with its own fonts, so the stylesheet is enqueued
	 * from `enqueue_block_assets`, which core fires again for the iframe. That runs twice
	 * per editor request; the enqueue is idempotent, the inline rule is guarded because
	 * adding it twice prints it twice.
	 *
	 * @param string $font Font setting value.
	 *
	 * @return void
	 */
	public function enqueue_for_editor( string $font ): void {

		$font = Fonts::get_instance()->resolve_font( $font );

		if ( Fonts::FONT_NONE === $font ) {
			return;
		}

		wp_enqueue_style(
			static::_handle( 'editor-font' ),
			Fonts::get_instance()->get_font_url( $font ),
			[],
			null  // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- This URL is not this plugin's.
		);

		if ( $this->_editor_font_styled ) {
			return;
		}

		$this->_editor_font_styled = true;

		wp_add_inline_style( static::_handle( 'editor-font' ), Fonts::get_instance()->get_editor_font_css( $font ) );

	}

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

	}

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

		// Line highlighting reads the rendered line numbers, so the two load together.
		if ( $this->_needs_line_numbers || $this->_needs_line_highlight ) {
			static::_enqueue_line_numbers();
		}

		// Either setting needs the script; the rainbow colours paint spans this script creates.
		if (
			Shortcode_Handler::is_plugin_option_on( 'match_braces', 'yes' )
			|| Shortcode_Handler::is_plugin_option_on( 'rainbow_braces', 'no' )
		) {
			static::_enqueue_match_braces();
		}

		if ( $this->_needs_line_highlight ) {
			static::_enqueue_line_highlight();
		}

	}

	/**
	 * Method to enqueue the toolbar plugin, and the language name it shows.
	 *
	 * The language name is a toolbar item and carries its own titles; it needs no stylesheet.
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

	}

	/**
	 * Method to enqueue the copy to clipboard button.
	 *
	 * It is a toolbar item, so it depends on the toolbar handle.
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

	}

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

	}

	/**
	 * Method to enqueue the line highlight plugin.
	 *
	 * Depends on the line-numbers handle, because the plugin reads rendered numbers to
	 * place its band.
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

	}

	/**
	 * Method to enqueue the brace matching plugin.
	 *
	 * Its stylesheet holds the pair outline and the nesting colours. Four bundled themes
	 * colour the nesting themselves at 0-4-0 against this file's 0-3-0, so they win where loaded.
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

	}

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
		 * Localising twice prepends rather than replaces, so the handle itself is asked
		 * whether it already carries data.
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

	}

	/**
	 * Method to build a script or style handle.
	 *
	 * @param string $name Handle suffix.
	 *
	 * @return string
	 */
	protected static function _handle( string $name ): string {
		return sprintf( '%s-%s', static::HANDLE_PREFIX, $name );
	}

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

	}

	/**
	 * Method to get the version string used to bust asset caches.
	 *
	 * @return string
	 */
	protected static function _get_version(): string {
		return Helper::get_version( '0' );
	}

} // end of class

// EOF
