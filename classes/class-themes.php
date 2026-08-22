<?php
/**
 * The themes the plugin ships, and where each one lives on disk.
 *
 * @package iG_Syntax_Hiliter
 */

namespace iG\Syntax_Hiliter;

use iG\Syntax_Hiliter\Traits\Singleton;

/**
 * The theme catalogue.
 *
 * This and `Asset_Manager` are the only two that know where the vendored library is;
 * this is the only one that knows the themes come from two directories.
 */
class Themes {

	use Singleton;

	/**
	 * Path of the extra theme collection, relative to the assets directory.
	 *
	 * PrismJS/prism-themes is a separate project, kept in its own directory because
	 * `assets/lib/prism/` is Prism's dist and is replaced whole on upgrade.
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
	 * Fixed rather than keyed on the plugin version: `Migrate::_clean_up()` deletes every
	 * `igsh-cache-%` option on a version change, and a plugin update is the only thing that
	 * changes the disk.
	 *
	 * @var string
	 */
	public const string CACHE_KEY = 'ig-syntax-hiliter-themes';

	/**
	 * How long the built theme list is cached for, in seconds. Seven days.
	 *
	 * The answer only changes when the plugin's files change; the settings page refresh
	 * button is the mechanism and this is the backstop.
	 *
	 * @var int
	 */
	protected const int _CACHE_LIFE = 604800;

	/**
	 * The theme map, once it has been built in this request.
	 *
	 * @var array|null
	 */
	protected ?array $_theme_titles = null;

	/**
	 * The themes on disk, once they have been read in this request.
	 *
	 * The persistent cache is an option read, and this is called several times in one request.
	 *
	 * @var array|null
	 */
	protected ?array $_themes = null;

	/**
	 * Method to settle which theme is actually loaded for a stored setting value.
	 *
	 * A stored theme no longer on disk falls back to the default rather than to no
	 * stylesheet: "no theme" is a choice a site owner makes, not something an accident
	 * should look like.
	 *
	 * @param string $theme Theme setting value.
	 *
	 * @return string A theme slug which is on disk, or the "no theme" value.
	 */
	public function resolve_theme( string $theme ): string {

		if ( static::THEME_NONE === $theme ) {
			return static::THEME_NONE;
		}

		return ( isset( $this->get_themes()[ $theme ] ) ) ? $theme : static::DEFAULT_THEME;

	}

	/**
	 * Method to get the themes the plugin ships, by the directory each one lives in.
	 *
	 * Keys are directories relative to the assets directory, values are slug to title.
	 * Every title is the name its own project gives it.
	 *
	 * @return array Directory relative to the assets directory, to slug to title.
	 */
	protected function _get_theme_titles(): array {

		if ( is_array( $this->_theme_titles ) ) {
			return $this->_theme_titles;
		}

		$this->_theme_titles = [

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

		return $this->_theme_titles;

	}

	/**
	 * Method to get the path of a theme stylesheet, relative to the assets directory.
	 *
	 * The one place which knows which theme is in which directory; a slug in neither gets
	 * an empty string.
	 *
	 * @param string $slug Theme slug.
	 *
	 * @return string Path relative to the assets directory, or an empty string for a theme the
	 *                plugin does not ship.
	 */
	public function get_theme_file( string $slug ): string {

		foreach ( $this->_get_theme_titles() as $directory => $titles ) {

			if ( ! isset( $titles[ $slug ] ) ) {
				continue;
			}

			return sprintf( '%s/%s.min.css', $directory, $slug );

		}

		return '';

	}

	/**
	 * Method to read the themes bundled with the plugin off the disk.
	 *
	 * A theme is only offered if its stylesheet is readable. Public because it is the
	 * callback the cache in `get_themes()` refills from; call `get_themes()` rather than this.
	 *
	 * @return array Theme file base name to human readable title.
	 */
	public function build_themes(): array {

		$themes = [];

		foreach ( $this->_get_theme_titles() as $titles ) {

			foreach ( $titles as $slug => $title ) {

				if ( ! is_readable( Helper::get_asset_path( $this->get_theme_file( $slug ) ) ) ) {
					continue;
				}

				$themes[ $slug ] = $title;

			}
		}

		return $themes;

	}

	/**
	 * Method to get the themes bundled with the plugin.
	 *
	 * Cached in two layers: a property for this request, an option for a week.
	 * `$force_rebuild` is a yes/no string because that is what arrives over REST. An
	 * empty list is a failure and not an answer — `Cache` writes `[]` down and hands it
	 * back, so a deploy caught mid-rsync would otherwise be served for `_CACHE_LIFE`.
	 * Such an entry is deleted rather than stepped over — stepping over would mean a full
	 * directory scan on every request for a week — and an empty rebuild is not memoised.
	 *
	 * @param string $force_rebuild `yes` to throw the cached list away and read the disk again.
	 *
	 * @return array Theme file base name to human readable title.
	 */
	public function get_themes( string $force_rebuild = 'no' ): array {

		$validate      = Validate::get_instance();
		$force_rebuild = ( $validate->is_yesno( $force_rebuild ) ) ? strtolower( trim( $force_rebuild ) ) : 'no';

		if ( 'yes' === $force_rebuild ) {
			$this->_themes = null;
		}

		if ( is_array( $this->_themes ) ) {
			return $this->_themes;
		}

		$cache = Cache::create( static::CACHE_KEY );

		if ( 'yes' === $force_rebuild ) {
			$cache->delete();
		}

		$themes = $cache->updates_with( [ $this, 'build_themes' ] )
						->expires_in( static::_CACHE_LIFE )
						->get();

		if ( ! is_array( $themes ) || empty( $themes ) ) {

			$cache->delete();    // do not let an unusable answer stand for a week

			$themes = $this->build_themes();

		}

		if ( empty( $themes ) ) {
			return [];    // nothing readable, and not memoised, so the next request asks again
		}

		$this->_themes = $themes;

		return $this->_themes;

	}

} // end of class

// EOF
