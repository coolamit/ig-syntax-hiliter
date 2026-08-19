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
 * This class and the renderer are the only two which know the highlighting engine
 * is Prism.
 */
class Asset_Manager {

	use Singleton;

	/**
	 * Prefix shared by every script and style handle the plugin registers.
	 *
	 * @var string
	 */
	const string HANDLE_PREFIX = 'ig-syntax-hiliter';

	/**
	 * Filter which supplies the URL the language files are fetched from.
	 *
	 * @var string
	 */
	const string FILTER_COMPONENTS_URL = 'ig_syntax_hiliter/prism_components_url';

	/**
	 * Path of the highlighter library, relative to the assets directory.
	 *
	 * @var string
	 */
	const string LIBRARY_PATH = 'lib/prism';

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
	const string THEMES_PATH = 'lib/prism-themes';

	/**
	 * The theme used when the site has not chosen one.
	 *
	 * @var string
	 */
	const string DEFAULT_THEME = 'prism-okaidia';

	/**
	 * Theme setting value which means "load no theme stylesheet at all".
	 *
	 * @var string
	 */
	const string THEME_NONE = 'none';

	/**
	 * Cache key the built theme list is stored under.
	 *
	 * Fixed rather than keyed on the plugin version, which is what the language
	 * registry does. Nothing accumulates either way: `Migrate::_clean_up()` deletes
	 * every option named `igsh-cache-%` when the stored version changes, and a plugin
	 * update is the only thing which can change what is on disk here.
	 *
	 * @var string
	 */
	const string THEMES_CACHE_KEY = 'ig-syntax-hiliter-themes';

	/**
	 * How long the built theme list is cached for, in seconds. Seven days.
	 *
	 * Long, because the answer can only change when the plugin's own files change,
	 * and a site owner who has just put a theme there by hand has the refresh button
	 * on the settings page rather than a wait. The expiry is the backstop under that
	 * button, not the mechanism.
	 *
	 * @var int
	 */
	const int THEMES_CACHE_LIFE = 604800;

	/**
	 * Font setting value which means "load no webfont at all".
	 *
	 * This is the default, and it is the only value which costs a reader nothing: a
	 * chosen font is fetched from another host, and a plugin which did that without
	 * being asked would be making that decision on a site owner's behalf.
	 *
	 * @var string
	 */
	const string FONT_NONE = 'none';

	/**
	 * Where the webfont stylesheets are fetched from.
	 *
	 * Bunny Fonts, which serves the same API shape as Google Fonts and states that it
	 * stores no personal data and no logs. That is the whole reason it was chosen over
	 * Google's own service.
	 *
	 * @var string
	 */
	const string FONTS_URL = 'https://fonts.bunny.net/css';

	/**
	 * What a chosen font falls back to.
	 *
	 * The same stack `assets/src/scss/frontend-chrome.scss` sets on a code box, so a
	 * font which fails to load leaves a reader exactly where they would have been with
	 * no font chosen at all.
	 *
	 * @var string
	 */
	const string FONT_STACK = 'Consolas, Monaco, "Andale Mono", "Ubuntu Mono", monospace';

	/**
	 * `wp_footer` priority at which the assets are first decided.
	 *
	 * @var int
	 */
	const int PRIORITY_DECIDE = 1;

	/**
	 * `wp_footer` priority at which the decision is taken again.
	 *
	 * Core prints the footer scripts and the late styles from `wp_footer` at 20, so
	 * this is the last moment at which enqueuing anything still reaches the page.
	 *
	 * @var int
	 */
	const int PRIORITY_DECIDE_AGAIN = 19;

	/**
	 * The theme map, once it has been built in this request.
	 *
	 * A compile-time constant which `get_theme_file()` used to rebuild on every call,
	 * so one `get_themes()` built it forty-four times.
	 *
	 * @var array|null
	 */
	protected static ?array $_theme_titles = null;

	/**
	 * The themes on disk, once they have been read in this request.
	 *
	 * The persistent cache below is an option read, and this is called several times
	 * in one request — by the enqueue, by the settings screen and by the validator.
	 *
	 * @var array|null
	 */
	protected static ?array $_themes = null;

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

	}    //end _register_hooks()

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
		$this->_enqueue_font( Shortcode_Handler::get_plugin_option( 'font', static::FONT_NONE ) );
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
	 * @param string $font  Slug of the font to load, or the "no font" value.
	 *
	 * @return void
	 */
	public function enqueue_for_preview( string $theme, string $font = self::FONT_NONE ): void {

		$this->_enqueue_theme( $theme );

		$this->_enqueue_font( $font );

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
	 * Method to settle which font is actually loaded for a stored setting value.
	 *
	 * **This falls back the other way from `_resolve_theme()`, deliberately.** A theme
	 * this plugin does not ship falls back to the default theme, because a code box
	 * with no colours at all looks broken. A font this plugin does not offer falls back
	 * to loading nothing: the only thing worse than the wrong font is fetching a file
	 * from another host that nobody asked for.
	 *
	 * @param string $font Font setting value.
	 *
	 * @return string A font slug this plugin offers, or the "no font" value.
	 */
	protected static function _resolve_font( string $font ): string {
		return ( isset( static::_get_font_titles()[ $font ] ) ) ? $font : static::FONT_NONE;
	}    //end _resolve_font()

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

		if ( is_array( static::$_theme_titles ) ) {
			return static::$_theme_titles;
		}

		static::$_theme_titles = [

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

		return static::$_theme_titles;

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
	 * Method to read the themes bundled with the plugin off the disk.
	 *
	 * A theme is only offered if its stylesheet is actually readable, so a slug
	 * mistyped in the map above, or a file lost in an upgrade, drops out of the
	 * dropdown instead of being offered and then 404ing.
	 *
	 * Public because it is the callback the cache in `get_themes()` refills from, and
	 * a callback has to be reachable. Call `get_themes()` rather than this: forty-odd
	 * `is_readable()` calls is what the cache exists to stop happening on every page.
	 *
	 * @return array Theme file base name to human readable title.
	 */
	public static function build_themes(): array {

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

	}    //end build_themes()

	/**
	 * Method to get the themes bundled with the plugin.
	 *
	 * The answer is a directory listing in all but name, and it is asked for several
	 * times in a request — twice by the front end's enqueue, by the settings screen,
	 * and by the validator on every REST request — so it is cached rather than read
	 * each time. Two layers: a static for the rest of this request, and an option for
	 * a week after that.
	 *
	 * `$force_rebuild` is what the refresh button on the settings page sends. It is a
	 * yes/no string rather than a boolean because that is what this plugin's settings
	 * have always been and what arrives over REST; anything which is not the word
	 * `yes` or the word `no` is read as `no`, so a rebuild is never triggered by
	 * accident.
	 *
	 * A cache which cannot produce a list falls back to reading the disk. An empty
	 * dropdown would be indistinguishable from a plugin with no themes in it, and the
	 * files are right there.
	 *
	 * **An empty list is a failure and not an answer**, which is why `empty()` is
	 * tested and not only `is_array()`. `Cache` writes `[]` down as happily as it
	 * writes a real list, and `Cache::get()` hands it back — `isset( $cache['data'] )`
	 * is true for an empty array — so a moment when nothing on disk was readable, a
	 * deploy swapping `assets/lib/` in place or an rsync caught half way, would
	 * otherwise be served for the whole of `THEMES_CACHE_LIFE`. What a site owner sees
	 * then is a theme dropdown holding nothing but "None" and a settings screen
	 * answering 400 for every real theme, with the refresh button the only way out.
	 *
	 * So such an entry is deleted rather than merely stepped over: stepping over it
	 * would leave it there to be stepped over again on every request for the next
	 * seven days, each one paying for a full directory scan. Deleting it means this
	 * request reads the disk and the next one caches what it finds, in the ordinary
	 * way. And an empty rebuild is not memoised either, so a site which really has
	 * lost its theme files starts working again the moment they come back.
	 *
	 * @param string $force_rebuild `yes` to throw the cached list away and read the disk again.
	 *
	 * @return array Theme file base name to human readable title.
	 */
	public static function get_themes( string $force_rebuild = 'no' ): array {

		$validate      = Validate::get_instance();
		$force_rebuild = ( $validate->is_yesno( $force_rebuild ) ) ? strtolower( trim( $force_rebuild ) ) : 'no';

		if ( 'yes' === $force_rebuild ) {
			static::$_themes = null;
		}

		if ( is_array( static::$_themes ) ) {
			return static::$_themes;
		}

		$cache = Cache::create( static::THEMES_CACHE_KEY );

		if ( 'yes' === $force_rebuild ) {
			$cache->delete();
		}

		$themes = $cache->updates_with( [ static::class, 'build_themes' ] )
						->expires_in( static::THEMES_CACHE_LIFE )
						->get();

		if ( ! is_array( $themes ) || empty( $themes ) ) {

			$cache->delete();    //do not let an unusable answer stand for a week

			$themes = static::build_themes();

		}

		if ( empty( $themes ) ) {
			return [];    //nothing readable, and not memoised, so the next request asks again
		}

		static::$_themes = $themes;

		return static::$_themes;

	}    //end get_themes()

	/**
	 * Method to get the fonts the plugin offers, keyed by the name Bunny Fonts knows.
	 *
	 * The single declaration of what a font is here. The title is the family's real
	 * name, which is both what the dropdown shows **and** what the CSS asks for, so
	 * there is one string and not two which could disagree.
	 *
	 * Every value below was read out of the font files Bunny actually serves rather
	 * than from a catalogue page, and two of those readings matter:
	 *
	 * - `weight` is the one weight fetched. Bunny drops a weight a family does not
	 *   have without complaining, so this can never fail a request — but a font asked
	 *   for at a weight it does not have would be synthesised by the browser, which is
	 *   why each one is the weight its own family really ships.
	 * - `ligatures` says the family's `GSUB` table genuinely carries `liga` or `calt`
	 *   lookups. Only three of the ten do. A browser may switch contextual alternates
	 *   off for a face it treats as fixed pitch, so the fonts which have them ask for
	 *   them by name; the rest say nothing, because a declaration which does nothing
	 *   reads as though it does.
	 *
	 * @return array Font slug to title, weight and whether it carries code ligatures.
	 */
	protected static function _get_font_titles(): array {

		return [
			'azeret-mono'       => [
				'title'     => 'Azeret Mono',
				'weight'    => 300,
				'ligatures' => true,
			],
			'fira-code'         => [
				'title'     => 'Fira Code',
				'weight'    => 400,
				'ligatures' => true,
			],
			'fira-mono'         => [
				'title'     => 'Fira Mono',
				'weight'    => 400,
				'ligatures' => false,
			],
			'google-sans-code'  => [
				'title'     => 'Google Sans Code',
				'weight'    => 400,
				'ligatures' => false,    //the name says otherwise; its GSUB has ccmp, locl and ss01 and nothing else
			],
			'jetbrains-mono'    => [
				'title'     => 'JetBrains Mono',
				'weight'    => 400,
				'ligatures' => true,
			],
			'm-plus-code-latin' => [
				'title'     => 'M PLUS Code Latin',
				'weight'    => 400,
				'ligatures' => false,
			],
			'nova-mono'         => [
				'title'     => 'Nova Mono',
				'weight'    => 400,
				'ligatures' => false,
			],
			'roboto-mono'       => [
				'title'     => 'Roboto Mono',
				'weight'    => 400,
				'ligatures' => false,
			],
			'source-code-pro'   => [
				'title'     => 'Source Code Pro',
				'weight'    => 400,
				'ligatures' => false,
			],
			'ubuntu-mono'       => [
				'title'     => 'Ubuntu Mono',
				'weight'    => 400,
				'ligatures' => false,
			],
		];

	}    //end _get_font_titles()

	/**
	 * Method to get the fonts the plugin offers.
	 *
	 * The counterpart of `get_themes()`, without its readability check: a theme is a
	 * file on disk which an upgrade can lose, and a font is a name in the map above.
	 *
	 * @return array Font slug to human readable title.
	 */
	public static function get_fonts(): array {

		return array_map(
			static fn ( array $font ): string => $font['title'],
			static::_get_font_titles()
		);

	}    //end get_fonts()

	/**
	 * Method to get the stylesheet URL for a font.
	 *
	 * Built by hand rather than with `add_query_arg()`, which would encode the colon
	 * the family and its weight are joined with. Nothing here needs escaping: the slug
	 * is a key of the map above and the weight is an integer from it, so a caller
	 * cannot get a string of its own into this URL.
	 *
	 * @param string $slug Font slug.
	 *
	 * @return string URL, or an empty string where no font is to be loaded.
	 */
	public static function get_font_url( string $slug ): string {

		$fonts = static::_get_font_titles();

		if ( ! isset( $fonts[ $slug ] ) ) {
			return '';
		}

		/*
		 * `display=swap` so that a reader is shown the code in the fallback font while
		 * the webfont is still on its way, rather than being shown nothing at all.
		 */
		return sprintf(
			'%s?family=%s:%d&display=swap',
			static::FONTS_URL,
			$slug,
			$fonts[ $slug ]['weight']
		);

	}    //end get_font_url()

	/**
	 * Method to get the CSS which puts a font on the code boxes.
	 *
	 * **Values and never a rule.** The selectors and the fallbacks live in
	 * `frontend-chrome.scss`, which reads these custom properties; all that is not
	 * known until a site owner has picked a font is what the values are. Keeping it
	 * that way means the cascade is legible where a reader of CSS would look for it,
	 * and adding a font is still an edit to `_get_font_titles()` and nothing else.
	 *
	 * The properties are set on `:root` because they are read on the code element and
	 * on every token span inside it, and a custom property is inherited.
	 *
	 * @param string $slug Font slug.
	 *
	 * @return string CSS, or an empty string where no font is to be loaded.
	 */
	public static function get_font_css( string $slug ): string {

		$declarations = static::_get_font_declarations( $slug );

		if ( '' === $declarations ) {
			return '';
		}

		return sprintf( ':root { %s }', $declarations );

	}    //end get_font_css()

	/**
	 * Method to get the custom property values which describe a font.
	 *
	 * The family, and for a family which really has the lookups for them, the
	 * ligatures — plus the one thing that has to go with ligatures and would look
	 * arbitrary anywhere else:
	 *
	 * **A non-zero `letter-spacing` suppresses ligatures outright.** That is specified
	 * behaviour and not a quirk, the property is inherited, and a theme setting it on
	 * its article text — `letter-spacing: 0.013rem` on `.entry-content` is a real
	 * example — reaches inside the code box and silently switches off the ligatures a
	 * site owner chose the font for. So a ligature font zeroes it and nothing else
	 * does: a site running one of the other seven, or no font at all, keeps whatever
	 * its theme asks for.
	 *
	 * @param string $slug Font slug.
	 *
	 * @return string Declarations, or an empty string for a font this plugin does not offer.
	 */
	protected static function _get_font_declarations( string $slug ): string {

		$fonts = static::_get_font_titles();

		if ( ! isset( $fonts[ $slug ] ) ) {
			return '';
		}

		$declarations = sprintf(
			'--igsh-code-font: "%s", %s;',
			$fonts[ $slug ]['title'],
			static::FONT_STACK
		);

		if ( ! empty( $fonts[ $slug ]['ligatures'] ) ) {
			$declarations .= ' --igsh-code-ligatures: common-ligatures contextual; --igsh-code-letter-spacing: 0;';
		}

		return $declarations;

	}    //end _get_font_declarations()

	/**
	 * Method to get the CSS which puts a font on the block being edited.
	 *
	 * **Custom properties rather than the properties themselves, deliberately.**
	 * `editor.scss` already sets a font on that textarea, at the same specificity this
	 * rule can reach, and inside the editor's iframe this rule is enqueued *before* the
	 * block's own stylesheet — core fires `enqueue_block_assets` first and enqueues the
	 * blocks' editor styles second. Setting the same property would therefore lose.
	 * Nothing else declares that variable, so there is no cascade to win.
	 *
	 * The block wrapper is the whole of the selector, so nothing else a site owner is
	 * editing can be reached by it.
	 *
	 * **The family and nothing else. Ligatures are never asked for here**, and
	 * `editor.scss` switches them off outright: the block is edited in a textarea,
	 * which is where somebody counts characters and puts a caret between them, and a
	 * caret cannot sit inside one glyph standing for two. Typing `__construct` and
	 * reading back what looks like ` _construct` is alarming enough to make an author
	 * correct code which was never wrong. The rendered box and the preview keep their
	 * ligatures, because nobody edits those.
	 *
	 * @param string $slug Font slug.
	 *
	 * @return string CSS, or an empty string where no font is to be loaded.
	 */
	public static function get_editor_font_css( string $slug ): string {

		$fonts = static::_get_font_titles();

		if ( ! isset( $fonts[ $slug ] ) ) {
			return '';
		}

		return sprintf(
			'.wp-block-igsyntax-hiliter-code { --igsh-editor-font: "%1$s", %2$s; }',
			$fonts[ $slug ]['title'],
			static::FONT_STACK
		);

	}    //end get_editor_font_css()

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

		$theme = $theme ?? Shortcode_Handler::get_plugin_option( 'theme', static::DEFAULT_THEME );

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

		$font = static::_resolve_font( $font );

		if ( static::FONT_NONE === $font ) {
			return;
		}

		/*
		 * No version. This URL belongs to somebody else and a `?ver=` of ours on the
		 * end of it is both meaningless there and a second cache key for the same file.
		 */
		wp_enqueue_style(
			static::_handle( 'font' ),
			static::get_font_url( $font ),
			[],
			null  // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Deliberate. This URL is not this plugin's, so a version of this plugin's on the end of it is meaningless there and a second cache key for the same file.
		);

		if ( $this->_font_styled ) {
			return;
		}

		$this->_font_styled = true;

		wp_add_inline_style( static::_handle( 'chrome' ), static::get_font_css( $font ) );

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

		$font = static::_resolve_font( $font );

		if ( static::FONT_NONE === $font ) {
			return;
		}

		wp_enqueue_style(
			static::_handle( 'editor-font' ),
			static::get_font_url( $font ),
			[],
			null  // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Deliberate, for the reason `_enqueue_font()` gives.
		);

		if ( $this->_editor_font_styled ) {
			return;
		}

		$this->_editor_font_styled = true;

		wp_add_inline_style( static::_handle( 'editor-font' ), static::get_editor_font_css( $font ) );

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

		$version = static::_get_version();

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
