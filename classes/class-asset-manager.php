<?php
/**
 * Conditional loading of the front end highlighting assets.
 *
 * @package iG_Syntax_Hiliter
 */

namespace iG\Syntax_Hiliter;

/**
 * Decides what the browser is asked to download, and whether it is asked at all.
 *
 * Nothing is enqueued unless a snippet was rendered on the page. The renderer
 * raises that signal for every box it renders; the shortcode handler and the block
 * callback may raise it themselves via `snippet_rendered()`.
 *
 * This class and the renderer are the only two which know the highlighting engine
 * is Prism.
 */
class Asset_Manager {

	/**
	 * Prefix shared by every script and style handle the plugin registers.
	 *
	 * @var string
	 */
	const HANDLE_PREFIX = 'ig-syntax-hiliter';

	/**
	 * Filter which supplies the URL the language files are fetched from.
	 *
	 * @var string
	 */
	const FILTER_COMPONENTS_URL = 'ig_syntax_hiliter/prism_components_url';

	/**
	 * Path of the highlighter library, relative to the assets directory.
	 *
	 * @var string
	 */
	const LIBRARY_PATH = 'lib/prism';

	/**
	 * Path of the extra theme collection, relative to the assets directory.
	 *
	 * These are the themes from PrismJS/prism-themes, which is a project of its own
	 * and not part of the Prism release. They live in a directory of their own
	 * because assets/lib/prism/ is Prism's dist and is replaced whole the next time
	 * Prism is upgraded, which would take every one of these with it.
	 *
	 * @var string
	 */
	const THEMES_PATH = 'lib/prism-themes';

	/**
	 * The theme used when the site has not chosen one.
	 *
	 * @var string
	 */
	const DEFAULT_THEME = 'prism-okaidia';

	/**
	 * Theme setting value which means "load no theme stylesheet at all".
	 *
	 * @var string
	 */
	const THEME_NONE = 'none';

	/**
	 * `wp_footer` priority at which the assets are first decided.
	 *
	 * @var int
	 */
	const PRIORITY_DECIDE = 1;

	/**
	 * `wp_footer` priority at which the decision is taken again.
	 *
	 * Core prints the footer scripts and the late styles from `wp_footer` at 20, so
	 * this is the last moment at which enqueuing anything still reaches the page.
	 *
	 * @var int
	 */
	const PRIORITY_DECIDE_AGAIN = 19;

	/**
	 * Singleton instance.
	 *
	 * @var \iG\Syntax_Hiliter\Asset_Manager|null
	 */
	protected static ?self $_instance = null;

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
	 * Whether the hooks have been registered already.
	 *
	 * @var bool
	 */
	protected bool $_hooked = false;

	/**
	 * Method to get the shared asset manager.
	 *
	 * @return \iG\Syntax_Hiliter\Asset_Manager
	 */
	public static function get_instance(): self {

		if ( is_null( static::$_instance ) ) {
			static::$_instance = new static();
		}

		return static::$_instance;

	}    //end get_instance()

	/**
	 * Method to hook the asset manager up to WordPress.
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
	public function register_hooks(): void {

		if ( $this->_hooked ) {
			return;
		}

		$this->_hooked = true;

		add_action( 'wp_footer', [ $this, 'enqueue' ], static::PRIORITY_DECIDE );
		add_action( 'wp_footer', [ $this, 'enqueue' ], static::PRIORITY_DECIDE_AGAIN );

	}    //end register_hooks()

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

		if ( '' !== $language_id && Language_Registry::NO_LANGUAGE !== $language_id ) {
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
	 * The preview's whole purpose is that the toolbar, the copy button and the line
	 * numbers can be switched on and off in front of the reader without a reload, so
	 * all of them have to be on the page already; which of them is *shown* is decided
	 * in the browser.
	 *
	 * @param string $theme Slug of the theme to load, or the "no theme" value.
	 *
	 * @return void
	 */
	public function enqueue_for_preview( string $theme ): void {

		$theme = static::_resolve_theme( $theme );

		if ( static::THEME_NONE !== $theme ) {

			wp_enqueue_style(
				static::_handle( 'theme' ),
				Helper::get_asset_url( static::get_theme_file( $theme ) ),
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

		$this->_enqueue_engine();

		static::_enqueue_toolbar();
		static::_enqueue_copy_button();
		static::_enqueue_line_numbers();

		$this->_enqueue_setup();

	}    //end enqueue_for_preview()

	/**
	 * Method to settle which theme is actually loaded for a stored setting value.
	 *
	 * A stored theme which is not on disk any more — one lost to an upgrade, or a
	 * value written by something other than this plugin — falls back to the default
	 * rather than to no stylesheet at all, because "no theme" is a choice a site owner
	 * makes and not something an accident should look like.
	 *
	 * @param string $theme Theme setting value.
	 *
	 * @return string A theme slug which is on disk, or the "no theme" value.
	 */
	protected static function _resolve_theme( string $theme ): string {

		if ( static::THEME_NONE === $theme ) {
			return static::THEME_NONE;
		}

		return ( isset( static::get_themes()[ $theme ] ) ) ? $theme : static::DEFAULT_THEME;

	}    //end _resolve_theme()

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
	 * Method to get the themes the plugin ships, by the directory each one lives in.
	 *
	 * The keys are directories relative to the assets directory and the values are
	 * slug to human readable title. Prism's own themes come first and the
	 * PrismJS/prism-themes collection second, which is the order they are offered in.
	 *
	 * Every title is the name its own project gives it, so that a site owner reading
	 * the dropdown and a site owner reading either project's documentation see the
	 * same word.
	 *
	 * @return array Directory relative to the assets directory, to slug to title.
	 */
	protected static function _get_theme_titles(): array {

		return [

			static::LIBRARY_PATH . '/themes' => [
				'prism'                => 'Prism',
				'prism-coy'            => 'Coy',
				'prism-dark'           => 'Dark',
				'prism-funky'          => 'Funky',
				'prism-okaidia'        => 'Okaidia',
				'prism-solarizedlight' => 'Solarized Light',
				'prism-tomorrow'       => 'Tomorrow Night',
				'prism-twilight'       => 'Twilight',
			],

			static::THEMES_PATH              => [
				'prism-a11y-dark'                       => 'a11y Dark',
				'prism-atom-dark'                       => 'Atom Dark',
				'prism-base16-ateliersulphurpool.light' => 'Ateliersulphurpool-light',
				'prism-cb'                              => 'CB',
				'prism-coldark-cold'                    => 'Coldark Cold',
				'prism-coldark-dark'                    => 'Coldark Dark',
				'prism-coy-without-shadows'             => 'Coy without shadows',
				'prism-darcula'                         => 'Darcula',
				'prism-dracula'                         => 'Dracula',
				'prism-duotone-dark'                    => 'Duotone Dark',
				'prism-duotone-earth'                   => 'Duotone Earth',
				'prism-duotone-forest'                  => 'Duotone Forest',
				'prism-duotone-light'                   => 'Duotone Light',
				'prism-duotone-sea'                     => 'Duotone Sea',
				'prism-duotone-space'                   => 'Duotone Space',
				'prism-ghcolors'                        => 'GHColors',
				'prism-gruvbox-dark'                    => 'Gruvbox Dark',
				'prism-gruvbox-light'                   => 'Gruvbox Light',
				'prism-holi-theme'                      => 'Holi Theme',
				'prism-lucario'                         => 'Lucario',
				'prism-material-dark'                   => 'Material Dark',
				'prism-material-light'                  => 'Material Light',
				'prism-material-oceanic'                => 'Material Oceanic',
				'prism-night-owl'                       => 'Night Owl',
				'prism-nord'                            => 'Nord',
				'prism-one-dark'                        => 'One Dark',
				'prism-one-light'                       => 'One Light',
				'prism-pojoaque'                        => 'Pojoaque',
				'prism-shades-of-purple'                => 'Shades of Purple',
				'prism-solarized-dark-atom'             => 'Solarized Dark Atom',
				'prism-synthwave84'                     => "Synthwave '84",
				'prism-vs'                              => 'VS',
				'prism-vsc-dark-plus'                   => 'VS Code Dark+',
				'prism-xonokai'                         => 'Xonokai',
				'prism-z-touch'                         => 'Z-Touch',
			],

		];

	}    //end _get_theme_titles()

	/**
	 * Method to get the path of a theme stylesheet, relative to the assets directory.
	 *
	 * Themes come from two directories, so this is the one place which knows which
	 * theme is in which. A slug in neither gets an empty string, never a path which
	 * looks usable.
	 *
	 * @param string $slug Theme slug.
	 *
	 * @return string Path relative to the assets directory, or an empty string for a theme the plugin does not ship.
	 */
	public static function get_theme_file( string $slug ): string {

		foreach ( static::_get_theme_titles() as $directory => $titles ) {

			if ( ! isset( $titles[ $slug ] ) ) {
				continue;
			}

			return sprintf( '%s/%s.min.css', $directory, $slug );

		}

		return '';

	}    //end get_theme_file()

	/**
	 * Method to get the themes bundled with the plugin.
	 *
	 * A theme is only offered if its stylesheet is actually readable, so a slug
	 * mistyped in the map above, or a file lost in an upgrade, drops out of the
	 * dropdown instead of being offered and then 404ing.
	 *
	 * @return array Theme file base name to human readable title.
	 */
	public static function get_themes(): array {

		$themes = [];

		foreach ( static::_get_theme_titles() as $titles ) {

			foreach ( $titles as $slug => $title ) {

				if ( ! is_readable( Helper::get_asset_path( static::get_theme_file( $slug ) ) ) ) {
					continue;
				}

				$themes[ $slug ] = $title;

			}
		}

		return $themes;

	}    //end get_themes()

	/**
	 * Method to enqueue the chosen theme stylesheet, and the plugin's own chrome.
	 *
	 * @return void
	 */
	protected function _enqueue_theme(): void {

		$theme = Shortcode_Handler::get_plugin_option( 'theme', static::DEFAULT_THEME );

		$theme = static::_resolve_theme( $theme );

		if ( static::THEME_NONE !== $theme ) {

			wp_enqueue_style(
				static::_handle( 'theme' ),
				Helper::get_asset_url( static::get_theme_file( $theme ) ),
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

		$version = static::_get_version();

		if ( static::_is_option_on( 'toolbar', 'yes' ) ) {

			static::_enqueue_toolbar();

			if ( static::_is_option_on( 'copy_code', 'yes' ) ) {
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

		if ( $this->_needs_line_highlight ) {

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
	 * Method to check whether a yes/no option is on.
	 *
	 * @param string $name     Option name.
	 * @param string $fallback Value to use when the option is missing, `yes` or `no`.
	 *
	 * @return bool
	 */
	protected static function _is_option_on( string $name, string $fallback ): bool {
		return Shortcode_Handler::is_plugin_option_on( $name, $fallback );
	}    //end _is_option_on()

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
		return ( defined( 'IG_SYNTAX_HILITER_VERSION' ) ) ? (string) IG_SYNTAX_HILITER_VERSION : '0';
	}    //end _get_version()

}    //end of class


//EOF
