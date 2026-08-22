<?php
/**
 * The registry of languages this plugin can highlight.
 *
 * @package iG_Syntax_Hiliter
 */

namespace iG\Syntax_Hiliter;

use iG\Syntax_Hiliter\Traits\Singleton;
use Throwable;

/**
 * Every language the highlighter can load, and every alias for it.
 *
 * `parse_manifest()` and `merge()` are pure. `_load()` is the only method which
 * touches WordPress, and it runs on first use rather than on construction.
 */
class Language_Registry {

	use Singleton;

	/**
	 * Language id which means "show this as code but do not highlight it".
	 *
	 * @var string
	 */
	public const string NO_LANGUAGE = 'none';

	/**
	 * Filter applied to the finished registry.
	 *
	 * @var string
	 */
	public const string FILTER_LANGUAGES = 'ig_syntax_hiliter/languages';

	/**
	 * Path of the highlighter library, relative to the plugin directory.
	 *
	 * @var string
	 */
	protected const string _LIBRARY_DIR = 'assets/lib/prism';

	/**
	 * How long a built registry is cached for, in seconds.
	 *
	 * Only a backstop: the version in the cache key is what picks up a new library.
	 *
	 * @var int
	 */
	protected const int _CACHE_EXPIRY = 86400;

	/**
	 * Whether the dataset has been loaded.
	 *
	 * An object built with a registry in hand is loaded already; one built with
	 * nothing loads on first use.
	 *
	 * @var bool
	 */
	protected bool $_loaded = false;

	/**
	 * Canonical language id to language data.
	 *
	 * @var array
	 */
	protected array $_languages = [];

	/**
	 * Alias to canonical language id.
	 *
	 * @var array
	 */
	protected array $_aliases = [];

	/**
	 * Class constructor.
	 *
	 * A registry handed in is the whole dataset, so such an object never reads a
	 * cache or fires a filter.
	 *
	 * @param array $registry Registry array in the shape `parse_manifest()` returns. Loads
	 *                        itself on first use when this is empty.
	 */
	public function __construct( array $registry = [] ) {

		$this->_ingest( $registry );

		$this->_loaded = ( ! empty( $registry ) );

	}

	/**
	 * Method to read a registry array into this object, replacing whatever it held.
	 *
	 * Separate from the constructor so `_load()` can refill this object in place
	 * after the filter has run.
	 *
	 * @param array $registry Registry array in the shape `parse_manifest()` returns.
	 *
	 * @return void
	 */
	protected function _ingest( array $registry ): void {

		$this->_languages = [];
		$this->_aliases   = [];

		$languages = ( is_array( $registry['languages'] ?? null ) ) ? $registry['languages'] : [];
		$aliases   = ( is_array( $registry['aliases'] ?? null ) ) ? $registry['aliases'] : [];

		foreach ( $languages as $id => $language ) {

			$id = strtolower( trim( (string) $id ) );

			if ( empty( $id ) || ! is_array( $language ) ) {
				continue;
			}

			$this->_languages[ $id ] = [
				'title' => (string) ( $language['title'] ?? $id ),
			];

		}

		foreach ( $aliases as $alias => $id ) {

			$alias = strtolower( trim( (string) $alias ) );
			$id    = strtolower( trim( (string) $id ) );

			if ( empty( $alias ) || ! isset( $this->_languages[ $id ] ) ) {
				continue;
			}

			$this->_aliases[ $alias ] = $id;

		}

	}

	/**
	 * Method to fill the registry in, the first time it is asked anything.
	 *
	 * Loading on first use, not in the constructor or `get_instance()`: a callback
	 * on the filter will likely ask this class what it holds, and while `new` has
	 * not returned `static::$_instance` is unassigned, so the callback would build a
	 * second registry, fire the filter again, and recurse. The flag is set before the
	 * filter runs, so a callback which asks sees the cached dataset rather than
	 * re-entering. `_ingest()` refills in place so a reference a callback took ends up
	 * holding the filtered registry.
	 *
	 * @return void
	 */
	protected function _load(): void {

		if ( $this->_loaded ) {
			return;
		}

		$registry = Cache::create( $this->_get_cache_key() )
						->updates_with( [ $this, 'build' ] )
						->expires_in( static::_CACHE_EXPIRY )
						->get();

		if ( ! is_array( $registry ) ) {
			/*
			 * Build once more here, but never let a failure take the page down: an empty
			 * registry renders every snippet unhighlighted, which beats a fatal.
			 */
			try {
				$registry = $this->build();
			} catch ( Throwable $e ) {
				$registry = [];
			}
		}

		$this->_ingest( $registry );

		$this->_loaded = true;

		/**
		 * Filters the finished language registry.
		 *
		 * Runs after the cache, so a callback is never baked into the cached value.
		 *
		 * @param array $registry Two keys: `languages`, keyed by canonical id and holding a
		 *                        `title`; and `aliases`, mapping alias to canonical id.
		 */
		$filtered = apply_filters( static::FILTER_LANGUAGES, $registry );    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook name is the prefixed class constant above.

		/*
		 * With no callback listening the filter hands back the same array, so this is a
		 * pointer check. Anything but an array is ignored rather than cast: a callback
		 * which forgets to return hands back NULL, and casting would empty the registry.
		 */
		if ( $filtered !== $registry && is_array( $filtered ) ) {
			$this->_ingest( $filtered );
		}

	}

	/**
	 * Method to build the registry from the bundled library.
	 *
	 * Public because the cache calls it back.
	 *
	 * @return array
	 */
	public function build(): array {

		$library_dir = sprintf( '%s/%s', dirname( __DIR__ ), static::_LIBRARY_DIR );

		return $this->merge(
			$this->parse_manifest(
				sprintf( '%s/components.json', $library_dir ),
				sprintf( '%s/components', $library_dir )
			)
		);

	}

	/**
	 * Method to parse the library's language manifest.
	 *
	 * Only languages whose file is readable in `$components_dir` are kept.
	 *
	 * @param string $manifest_path  Absolute path of the manifest JSON file.
	 * @param string $components_dir Absolute path of the directory holding the language files.
	 *
	 * @return array Registry array with `languages` and `aliases` keys.
	 */
	public function parse_manifest( string $manifest_path, string $components_dir ): array {

		$registry = [
			'languages' => [],
			'aliases'   => [],
		];

		if ( ! is_readable( $manifest_path ) ) {
			return $registry;
		}

		$manifest = json_decode( (string) file_get_contents( $manifest_path ), true );    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- This method must stay free of WordPress.

		if ( ! is_array( $manifest ) || ! is_array( $manifest['languages'] ?? null ) ) {
			return $registry;
		}

		$components_dir = rtrim( $components_dir, '/' );

		foreach ( $manifest['languages'] as $id => $language ) {

			// `meta` is the manifest's own configuration, not a language.
			if ( 'meta' === $id || ! is_array( $language ) ) {
				continue;
			}

			$id   = strtolower( trim( (string) $id ) );
			$file = sprintf( 'prism-%s.min.js', $id );

			if ( empty( $id ) || ! is_readable( sprintf( '%s/%s', $components_dir, $file ) ) ) {
				continue;
			}

			// The file name is not kept: the browser resolves it from
			// `Asset_Manager::get_components_url()`.
			$registry['languages'][ $id ] = [
				'title' => (string) ( $language['title'] ?? $id ),
			];

			$aliases = $language['alias'] ?? [];
			$aliases = ( is_array( $aliases ) ) ? $aliases : [ $aliases ];

			foreach ( $aliases as $alias ) {

				$alias = strtolower( trim( (string) $alias ) );

				if ( empty( $alias ) ) {
					continue;
				}

				$registry['aliases'][ $alias ] = $id;

			}
		}

		return $registry;

	}

	/**
	 * Method to overlay one registry on top of another and tidy the result.
	 *
	 * The overlay wins. With no overlay it tidies alone: an alias pointing at no
	 * language, or shadowing a language id, is dropped, and both lists come back sorted.
	 *
	 * @param array $base    Registry to overlay on to.
	 * @param array $overlay Optional. Registry to overlay.
	 *
	 * @return array
	 */
	public function merge( array $base, array $overlay = [] ): array {

		$languages = array_merge(
			( is_array( $base['languages'] ?? null ) ) ? $base['languages'] : [],
			( is_array( $overlay['languages'] ?? null ) ) ? $overlay['languages'] : []
		);

		$aliases = array_merge(
			( is_array( $base['aliases'] ?? null ) ) ? $base['aliases'] : [],
			( is_array( $overlay['aliases'] ?? null ) ) ? $overlay['aliases'] : []
		);

		// Drop aliases which point nowhere, and aliases which shadow a language id.
		$aliases = array_filter(
			$aliases,
			function ( $id, $alias ) use ( $languages ): bool {
				return ( isset( $languages[ $id ] ) && ! isset( $languages[ $alias ] ) );
			},
			ARRAY_FILTER_USE_BOTH
		);

		ksort( $languages );
		ksort( $aliases );

		return [
			'languages' => $languages,
			'aliases'   => $aliases,
		];

	}

	/**
	 * Method to resolve a language name to its canonical id.
	 *
	 * Case insensitive and whitespace tolerant.
	 *
	 * @param string $lang Language name or alias as typed by the author.
	 *
	 * @return string|null Canonical language id, or NULL when nothing matches.
	 */
	public function resolve( string $lang ): ?string {

		$this->_load();

		$lang = strtolower( trim( $lang ) );

		if ( empty( $lang ) ) {
			return null;
		}

		if ( isset( $this->_languages[ $lang ] ) ) {
			return $lang;
		}

		return $this->_aliases[ $lang ] ?? null;

	}

	/**
	 * Method to check whether a canonical language id is in the registry.
	 *
	 * @param string $id Canonical language id.
	 *
	 * @return bool
	 */
	public function has( string $id ): bool {

		$this->_load();

		return isset( $this->_languages[ strtolower( trim( $id ) ) ] );

	}

	/**
	 * Method to get every language in the registry.
	 *
	 * @return array Canonical language id to language data.
	 */
	public function get_languages(): array {

		$this->_load();

		return $this->_languages;

	}

	/**
	 * Method to get every alias in the registry.
	 *
	 * The editor needs the whole table to turn an alias into the canonical id its
	 * dropdown holds.
	 *
	 * @return array Alias to canonical language id.
	 */
	public function get_aliases(): array {

		$this->_load();

		return $this->_aliases;

	}

	/**
	 * Method to get the languages as a list fit for a dropdown.
	 *
	 * @return array List of arrays with `id` and `title` keys, sorted by title.
	 */
	public function get_choices(): array {

		$this->_load();

		$choices = [];

		foreach ( $this->_languages as $id => $language ) {
			$choices[] = [
				'id'    => $id,
				'title' => $language['title'],
			];
		}

		usort(
			$choices,
			function ( array $one, array $two ): int {
				return strcasecmp( $one['title'], $two['title'] );
			}
		);

		return $choices;

	}

	/**
	 * Method to build the cache key.
	 *
	 * The plugin version is the whole key: the registry is built from the bundled
	 * library alone, which only changes when the plugin is updated.
	 *
	 * @return string
	 */
	protected function _get_cache_key(): string {

		$version = Helper::get_version( '0' );

		return sprintf( 'ig-syntax-hiliter-languages-%s', $version );

	}

} // end of class

// EOF
