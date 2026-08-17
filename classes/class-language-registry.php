<?php
/**
 * The registry of languages this plugin can highlight.
 *
 * @package iG_Syntax_Hiliter
 */

namespace iG\Syntax_Hiliter;

/**
 * Every language the highlighter can load, and every alias for it.
 *
 * `parse_manifest()` and `merge()` are pure and take their paths as arguments.
 * `get_instance()` is the only method which touches WordPress: it resolves the
 * paths, caches the result and applies the extension filter.
 */
class Language_Registry {

	/**
	 * Language id which means "show this as code but do not highlight it".
	 *
	 * @var string
	 */
	const NO_LANGUAGE = 'none';

	/**
	 * Filter applied to the finished registry.
	 *
	 * @var string
	 */
	const FILTER_LANGUAGES = 'ig_syntax_hiliter/languages';

	/**
	 * Path of the highlighter library, relative to the plugin directory.
	 *
	 * @var string
	 */
	const LIBRARY_DIR = 'assets/lib/prism';

	/**
	 * How long a built registry is cached for, in seconds.
	 *
	 * The plugin version is part of the cache key, and the bundled library can only
	 * change when the plugin is updated, so this is not what picks a new language up;
	 * it is only a backstop. Rebuilding costs a couple of milliseconds, once a day.
	 *
	 * @var int
	 */
	const CACHE_EXPIRY = 86400;

	/**
	 * Singleton instance.
	 *
	 * @var \iG\Syntax_Hiliter\Language_Registry|null
	 */
	protected static ?self $_instance = null;

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
	 * @param array $registry Registry array in the shape `parse_manifest()` returns.
	 */
	public function __construct( array $registry = [] ) {

		$this->_ingest( $registry );

	}    //end __construct()

	/**
	 * Method to read a registry array into this object, replacing whatever it held.
	 *
	 * Separate from the constructor so that `get_instance()` can publish an instance
	 * before the extension filter runs and then update that same object afterwards,
	 * rather than swapping it for a second one which anything holding a reference to
	 * the first would never see.
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

			if ( '' === $id || ! is_array( $language ) ) {
				continue;
			}

			$this->_languages[ $id ] = [
				'title' => (string) ( $language['title'] ?? $id ),
				'file'  => (string) ( $language['file'] ?? '' ),
			];

		}

		foreach ( $aliases as $alias => $id ) {

			$alias = strtolower( trim( (string) $alias ) );
			$id    = strtolower( trim( (string) $id ) );

			if ( '' === $alias || ! isset( $this->_languages[ $id ] ) ) {
				continue;
			}

			$this->_aliases[ $alias ] = $id;

		}

	}    //end _ingest()

	/**
	 * Method to get the shared registry instance.
	 *
	 * @return \iG\Syntax_Hiliter\Language_Registry
	 */
	public static function get_instance(): self {

		if ( ! is_null( static::$_instance ) ) {
			return static::$_instance;
		}

		$registry = Cache::create( static::_get_cache_key() )
						->expires_in( static::CACHE_EXPIRY )
						->updates_with( [ static::class, 'build' ] )
						->get();

		if ( ! is_array( $registry ) ) {
			/*
			 * Nothing usable came back, which covers a first run with no option to read
			 * as well as a build which failed inside the cache. Build it once more here,
			 * but do not let a failure take the page down with it: an empty registry
			 * means every snippet renders as unhighlighted code, which is a far cheaper
			 * outcome than a fatal part way through `the_content`.
			 */
			try {
				$registry = static::build();
			} catch ( \Throwable $e ) {
				$registry = [];
			}
		}

		/*
		 * The instance is published before the filter runs. A callback which asks the
		 * registry what it currently holds — the obvious thing to do when deciding what
		 * to change — would otherwise re-enter this method with nothing memoised and
		 * recurse until the process died. It is filled in again below, in place, so
		 * that anything the callback took a reference to ends up holding the filtered
		 * registry rather than the one it was shown.
		 */
		static::$_instance = new static( (array) $registry );

		/**
		 * Filters the finished language registry.
		 *
		 * Runs after the cache, so a callback is never baked into the cached value.
		 *
		 * @param array $registry Two keys: `languages`, keyed by canonical id and holding `title` and `file`; and `aliases`, mapping alias to canonical id.
		 */
		$filtered = apply_filters( static::FILTER_LANGUAGES, $registry );    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook name is the prefixed class constant above.

		/*
		 * Reading three hundred languages in takes long enough to be worth not doing
		 * twice for nothing. With no callback listening, the filter hands back the very
		 * array it was given and this comparison is a pointer check.
		 *
		 * Anything but an array is ignored rather than cast. A callback which forgets to
		 * return, or returns early, hands back NULL — and casting that would empty the
		 * registry, leaving every language on the site unknown and every snippet
		 * unhighlighted. Keeping what came out of the cache is the far cheaper reading of
		 * a callback that plainly did not mean to replace anything.
		 */
		if ( $filtered !== $registry && is_array( $filtered ) ) {
			static::$_instance->_ingest( $filtered );
		}

		return static::$_instance;

	}    //end get_instance()

	/**
	 * Method to build the registry from the bundled library.
	 *
	 * Public because the cache calls it back.
	 *
	 * @return array
	 */
	public static function build(): array {

		$library_dir = sprintf( '%s/%s', dirname( __DIR__ ), static::LIBRARY_DIR );

		return static::merge(
			static::parse_manifest(
				sprintf( '%s/components.json', $library_dir ),
				sprintf( '%s/components', $library_dir )
			)
		);

	}    //end build()

	/**
	 * Method to parse the library's language manifest.
	 *
	 * Only languages whose file is readable in `$components_dir` are kept, so the
	 * registry can never promise a language the browser would then fail to fetch.
	 *
	 * @param string $manifest_path  Absolute path of the manifest JSON file.
	 * @param string $components_dir Absolute path of the directory holding the language files.
	 *
	 * @return array Registry array with `languages` and `aliases` keys.
	 */
	public static function parse_manifest( string $manifest_path, string $components_dir ): array {

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

			if ( '' === $id || ! is_readable( sprintf( '%s/%s', $components_dir, $file ) ) ) {
				continue;
			}

			$registry['languages'][ $id ] = [
				'title' => (string) ( $language['title'] ?? $id ),
				'file'  => $file,
			];

			$aliases = $language['alias'] ?? [];
			$aliases = ( is_array( $aliases ) ) ? $aliases : [ $aliases ];

			foreach ( $aliases as $alias ) {

				$alias = strtolower( trim( (string) $alias ) );

				if ( '' === $alias ) {
					continue;
				}

				$registry['aliases'][ $alias ] = $id;

			}
		}

		return $registry;

	}    //end parse_manifest()

	/**
	 * Method to overlay one registry on top of another and tidy the result.
	 *
	 * The overlay wins, so a caller of the extension filter can replace a bundled
	 * language with its own. Called with no overlay it does the tidying alone, which
	 * is what `build()` wants of it: an alias pointing at no language, or shadowing a
	 * language id, is dropped, and both lists come back sorted.
	 *
	 * @param array $base    Registry to overlay on to.
	 * @param array $overlay Optional. Registry to overlay.
	 *
	 * @return array
	 */
	public static function merge( array $base, array $overlay = [] ): array {

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
			static function ( $id, $alias ) use ( $languages ): bool {
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

	}    //end merge()

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

		$lang = strtolower( trim( $lang ) );

		if ( '' === $lang ) {
			return null;
		}

		if ( isset( $this->_languages[ $lang ] ) ) {
			return $lang;
		}

		return $this->_aliases[ $lang ] ?? null;

	}    //end resolve()

	/**
	 * Method to check whether a canonical language id is in the registry.
	 *
	 * @param string $id Canonical language id.
	 *
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->_languages[ strtolower( trim( $id ) ) ] );
	}    //end has()

	/**
	 * Method to get the human readable title of a language.
	 *
	 * @param string $id Canonical language id.
	 *
	 * @return string|null
	 */
	public function get_title( string $id ): ?string {
		return $this->_languages[ strtolower( trim( $id ) ) ]['title'] ?? null;
	}    //end get_title()

	/**
	 * Method to get the base name of the file which defines a language.
	 *
	 * @param string $id Canonical language id.
	 *
	 * @return string|null
	 */
	public function get_file( string $id ): ?string {

		$file = $this->_languages[ strtolower( trim( $id ) ) ]['file'] ?? '';

		return ( '' === $file ) ? null : $file;

	}    //end get_file()

	/**
	 * Method to get every language in the registry.
	 *
	 * @return array Canonical language id to language data.
	 */
	public function get_languages(): array {
		return $this->_languages;
	}    //end get_languages()

	/**
	 * Method to get every alias in the registry.
	 *
	 * `resolve()` answers one name at a time, which is all the server ever needs. The
	 * editor needs the whole table: its language dropdown is built from canonical ids
	 * alone, so it has to be able to turn an alias into the id the dropdown holds
	 * before it ever puts a language into a block attribute.
	 *
	 * @return array Alias to canonical language id.
	 */
	public function get_aliases(): array {
		return $this->_aliases;
	}    //end get_aliases()

	/**
	 * Method to get the languages as a list fit for a dropdown.
	 *
	 * @return array List of arrays with `id` and `title` keys, sorted by title.
	 */
	public function get_choices(): array {

		$choices = [];

		foreach ( $this->_languages as $id => $language ) {
			$choices[] = [
				'id'    => $id,
				'title' => $language['title'],
			];
		}

		usort(
			$choices,
			static function ( array $one, array $two ): int {
				return strcasecmp( $one['title'], $two['title'] );
			}
		);

		return $choices;

	}    //end get_choices()

	/**
	 * Method to build the cache key.
	 *
	 * The plugin version is the whole of the key. The registry is built from the
	 * bundled library and from nothing else, so it can only change when the plugin
	 * is updated — and that is what moves the version.
	 *
	 * @return string
	 */
	protected static function _get_cache_key(): string {

		$version = ( defined( 'IG_SYNTAX_HILITER_VERSION' ) ) ? (string) IG_SYNTAX_HILITER_VERSION : '0';

		return sprintf( 'ig-syntax-hiliter-languages-%s', $version );

	}    //end _get_cache_key()

}    //end of class


//EOF
