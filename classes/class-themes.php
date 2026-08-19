<?php
/**
 * The themes the plugin ships, and where each one lives on disk.
 *
 * @package iG_Syntax_Hiliter
 */

namespace iG\Syntax_Hiliter;

/**
 * The theme catalogue.
 *
 * **This class and `Asset_Manager` are the only two which know where the vendored
 * highlighting library is**, and this is the only one which knows that the themes
 * come out of two directories rather than one. `Asset_Manager` asks it for a file
 * and enqueues the answer; nothing else on the server has any business knowing that
 * a theme is a stylesheet at all.
 *
 * All static and deliberately not a service: every answer here is a reading of a
 * literal map or of the disk, and neither needs an instance to hold. `Legacy_Map`
 * and `Helper` are the same shape for the same reason.
 */
class Themes {

	/**
	 * Path of the extra theme collection, relative to the assets directory.
	 *
	 * These are the themes from PrismJS/prism-themes, which is a project of its own
	 * and not part of the Prism release. They live in a directory of their own
	 * because assets/lib/prism/ is Prism's dist and is replaced whole the next time
	 * Prism is upgraded, which would take every one of these with it.
	 *
	 * **Named for the collection and not for the themes**, because this class reads
	 * two directories and the other one is Prism's own dist themes, under
	 * `Asset_Manager::LIBRARY_PATH . '/themes'`. A name saying only "themes" inside a
	 * class called `Themes` reads as though it were the only one.
	 *
	 * @var string
	 */
	protected const string _COLLECTION_PATH = 'lib/prism-themes';

	/**
	 * The theme used when the site has not chosen one.
	 *
	 * @var string
	 */
	public const string DEFAULT_THEME = 'prism-okaidia';

	/**
	 * Theme setting value which means "load no theme stylesheet at all".
	 *
	 * @var string
	 */
	public const string THEME_NONE = 'none';

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
	public const string CACHE_KEY = 'ig-syntax-hiliter-themes';

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
	protected const int _CACHE_LIFE = 604800;

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
	public static function resolve_theme( string $theme ): string {

		if ( static::THEME_NONE === $theme ) {
			return static::THEME_NONE;
		}

		return ( isset( static::get_themes()[ $theme ] ) ) ? $theme : static::DEFAULT_THEME;

	}    //end resolve_theme()

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

			Asset_Manager::LIBRARY_PATH . '/themes' => [
				'prism'                => 'Prism',
				'prism-coy'            => 'Coy',
				'prism-dark'           => 'Dark',
				'prism-funky'          => 'Funky',
				'prism-okaidia'        => 'Okaidia',
				'prism-solarizedlight' => 'Solarized Light',
				'prism-tomorrow'       => 'Tomorrow Night',
				'prism-twilight'       => 'Twilight',
			],

			static::_COLLECTION_PATH                => [
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
	 * otherwise be served for the whole of `_CACHE_LIFE`. What a site owner sees
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

		$cache = Cache::create( static::CACHE_KEY );

		if ( 'yes' === $force_rebuild ) {
			$cache->delete();
		}

		$themes = $cache->updates_with( [ static::class, 'build_themes' ] )
						->expires_in( static::_CACHE_LIFE )
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

}    //end of class

//EOF
