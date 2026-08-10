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
	 * The theme used when the site has not chosen one.
	 *
	 * @var string
	 */
	const DEFAULT_THEME = 'prism';

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
		$this->_enqueue_dropins();
		$this->_enqueue_setup();

	}    //end enqueue()

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
		 * Only one directory can be served this way; drop-in languages are enqueued
		 * directly and are unaffected.
		 *
		 * @param string $url URL with a trailing slash.
		 */
		return (string) apply_filters( static::FILTER_COMPONENTS_URL, $url );    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook name is the prefixed class constant above.

	}    //end get_components_url()

	/**
	 * Method to get the themes bundled with the plugin.
	 *
	 * @return array Theme file base name to human readable title.
	 */
	public static function get_themes(): array {

		$titles = [
			'prism'                => 'Default',
			'prism-coy'            => 'Coy',
			'prism-dark'           => 'Dark',
			'prism-funky'          => 'Funky',
			'prism-okaidia'        => 'Okaidia',
			'prism-solarizedlight' => 'Solarized Light',
			'prism-tomorrow'       => 'Tomorrow Night',
			'prism-twilight'       => 'Twilight',
		];

		$themes = [];

		foreach ( $titles as $slug => $title ) {

			if ( ! is_readable( static::_get_library_path( sprintf( 'themes/%s.min.css', $slug ) ) ) ) {
				continue;
			}

			$themes[ $slug ] = $title;

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

		if ( static::THEME_NONE !== $theme ) {

			$themes = static::get_themes();
			$theme  = ( isset( $themes[ $theme ] ) ) ? $theme : static::DEFAULT_THEME;

			wp_enqueue_style(
				static::_handle( 'theme' ),
				static::_get_library_url( sprintf( 'themes/%s.min.css', $theme ) ),
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
		$engine  = [ static::_handle( 'engine' ) ];

		if ( static::_is_option_on( 'toolbar', 'yes' ) ) {

			wp_enqueue_style(
				static::_handle( 'toolbar' ),
				static::_get_library_url( 'plugins/toolbar/prism-toolbar.min.css' ),
				[],
				$version
			);

			wp_enqueue_script(
				static::_handle( 'toolbar' ),
				static::_get_library_url( 'plugins/toolbar/prism-toolbar.min.js' ),
				$engine,
				$version,
				true
			);

			if ( static::_is_option_on( 'copy_code', 'yes' ) ) {
				wp_enqueue_script(
					static::_handle( 'copy-to-clipboard' ),
					static::_get_library_url( 'plugins/copy-to-clipboard/prism-copy-to-clipboard.min.js' ),
					[ static::_handle( 'toolbar' ) ],
					$version,
					true
				);
			}
		}

		/*
		 * Line highlighting reads the rendered line numbers when they are present, so
		 * the two load together to keep the order deterministic.
		 */
		if ( $this->_needs_line_numbers || $this->_needs_line_highlight ) {

			wp_enqueue_style(
				static::_handle( 'line-numbers' ),
				static::_get_library_url( 'plugins/line-numbers/prism-line-numbers.min.css' ),
				[],
				$version
			);

			wp_enqueue_script(
				static::_handle( 'line-numbers' ),
				static::_get_library_url( 'plugins/line-numbers/prism-line-numbers.min.js' ),
				$engine,
				$version,
				true
			);

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

		// Off by default: it strips common leading indentation, which is often deliberate in a snippet.
		if ( static::_is_option_on( 'normalize_whitespace', 'no' ) ) {
			wp_enqueue_script(
				static::_handle( 'normalize-whitespace' ),
				static::_get_library_url( 'plugins/normalize-whitespace/prism-normalize-whitespace.min.js' ),
				$engine,
				$version,
				true
			);
		}

	}    //end _enqueue_plugins()

	/**
	 * Method to enqueue language files supplied by the site itself.
	 *
	 * The engine's language loader can only be pointed at one directory, so drop-ins
	 * are enqueued directly. They define their grammar before highlighting starts,
	 * which also stops the loader looking for them and getting a 404.
	 *
	 * @return void
	 */
	protected function _enqueue_dropins(): void {

		$registry = Language_Registry::get_instance();
		$base_url = '';

		foreach ( $this->get_languages() as $language ) {

			if ( ! $registry->is_dropin( $language ) ) {
				continue;
			}

			$file = $registry->get_file( $language );

			if ( is_null( $file ) ) {
				continue;
			}

			$base_url = ( '' === $base_url ) ? Language_Registry::get_dropin_url() : $base_url;

			if ( '' === $base_url ) {
				return;
			}

			/*
			 * The registry is filterable, so the file name reaching this line is not
			 * guaranteed to be one `scan_dropins()` vetted. Encoding it keeps whatever
			 * it holds inside a single path segment of the drop-in directory instead of
			 * letting a `#` cut the URL short or a `/` walk out of it.
			 */
			wp_enqueue_script(
				static::_handle( sprintf( 'language-%s', $language ) ),
				sprintf( '%s/%s', $base_url, rawurlencode( $file ) ),
				[ static::_handle( 'engine' ) ],
				static::_get_version(),
				true
			);

		}

	}    //end _enqueue_dropins()

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
				'fileLabel'     => __( 'File', 'igsyntax-hiliter' ),
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
		return ( 'yes' === Shortcode_Handler::get_plugin_option( $name, $fallback ) );
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
	 * Method to get the absolute path of a file in the bundled library.
	 *
	 * @param string $path Path relative to the library directory.
	 *
	 * @return string
	 */
	protected static function _get_library_path( string $path ): string {

		return sprintf(
			'%s/assets/%s/%s',
			dirname( __DIR__ ),
			static::LIBRARY_PATH,
			ltrim( $path, '/' )
		);

	}    //end _get_library_path()

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
