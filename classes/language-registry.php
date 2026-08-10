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
 * `parse_manifest()`, `scan_dropins()` and `merge()` are pure and take their paths
 * as arguments. `get_instance()` is the only method which touches WordPress: it
 * resolves the paths, caches the result and applies the extension filter.
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
	 * Drop-in language directory, relative to the uploads directory.
	 *
	 * It lives in uploads, and is named after the plugin slug rather than the plugin
	 * directory, so that it survives a plugin update.
	 *
	 * @var string
	 */
	const DROPIN_DIR = 'igsyntax-hiliter/components';

	/**
	 * Path of the highlighter library, relative to the plugin directory.
	 *
	 * @var string
	 */
	const LIBRARY_DIR = 'assets/lib/prism';

	/**
	 * How long a built registry is cached for, in seconds.
	 *
	 * A drop-in appearing or disappearing changes the cache key, so this is not what
	 * picks those up; it is only a backstop for a filesystem whose directory times
	 * cannot be trusted. Rebuilding costs a couple of milliseconds, once a day.
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
				'title'  => (string) ( $language['title'] ?? $id ),
				'file'   => (string) ( $language['file'] ?? '' ),
				'dropin' => (bool) ( $language['dropin'] ?? false ),
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
		 * @param array $registry Two keys: `languages`, keyed by canonical id and holding `title`, `file` and `dropin`; and `aliases`, mapping alias to canonical id.
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
	 * Method to build the registry from the bundled library and the drop-in directory.
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
			),
			static::scan_dropins( static::get_dropin_dir() )
		);

	}    //end build()

	/**
	 * Method to get the absolute path of the drop-in language directory.
	 *
	 * The directory is never created; it exists only if a site owner made it.
	 *
	 * @return string Absolute path with no trailing slash, or an empty string when uploads are unavailable.
	 */
	public static function get_dropin_dir(): string {

		$uploads = wp_upload_dir( null, false );

		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}

		return sprintf( '%s/%s', untrailingslashit( $uploads['basedir'] ), static::DROPIN_DIR );

	}    //end get_dropin_dir()

	/**
	 * Method to get the URL of the drop-in language directory.
	 *
	 * @return string URL with no trailing slash, or an empty string when uploads are unavailable.
	 */
	public static function get_dropin_url(): string {

		$uploads = wp_upload_dir( null, false );

		if ( ! empty( $uploads['error'] ) || empty( $uploads['baseurl'] ) ) {
			return '';
		}

		return sprintf( '%s/%s', untrailingslashit( $uploads['baseurl'] ), static::DROPIN_DIR );

	}    //end get_dropin_url()

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
				'title'  => (string) ( $language['title'] ?? $id ),
				'file'   => $file,
				'dropin' => false,
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
	 * Method to discover drop-in language files supplied by the site.
	 *
	 * A drop-in is any `prism-{id}.js` or `prism-{id}.min.js` file in the given
	 * directory. The directory is only read, never created.
	 *
	 * @param string $dropin_dir Absolute path of the drop-in directory.
	 *
	 * @return array Registry array with `languages` and `aliases` keys.
	 */
	public static function scan_dropins( string $dropin_dir ): array {

		$registry = [
			'languages' => [],
			'aliases'   => [],
		];

		$dropin_dir = rtrim( $dropin_dir, '/' );

		if ( '' === $dropin_dir || ! is_dir( $dropin_dir ) || ! is_readable( $dropin_dir ) ) {
			return $registry;
		}

		$files = glob( sprintf( '%s/prism-*.js', $dropin_dir ) );

		if ( empty( $files ) ) {
			return $registry;
		}

		foreach ( $files as $file ) {

			$name = basename( $file );

			/*
			 * The id is held to what the highlighter itself will accept. Its own class
			 * matcher reads `language-([\w-]+)`, so an id carrying anything else — `#`
			 * and `+` being the tempting ones — never reaches the grammar it names, and
			 * `#` would truncate the file URL at a fragment on the way there as well.
			 * No bundled component id uses either character; `csharp` and `cpp` do.
			 */
			if ( ! preg_match( '/^prism-([a-z0-9_-]+?)(\.min)?\.js$/i', $name, $matches ) ) {
				continue;
			}

			$id = strtolower( $matches[1] );

			if ( 'core' === $id || static::NO_LANGUAGE === $id ) {
				continue;
			}

			// A minified drop-in wins, so shipping both files does not depend on glob order.
			if ( isset( $registry['languages'][ $id ] ) && empty( $matches[2] ) ) {
				continue;
			}

			$registry['languages'][ $id ] = [
				'title'  => $id,
				'file'   => $name,
				'dropin' => true,
			];

		}

		return $registry;

	}    //end scan_dropins()

	/**
	 * Method to overlay one registry on top of another.
	 *
	 * The overlay wins, so a site can replace a bundled language with its own.
	 *
	 * @param array $base    Registry to overlay on to.
	 * @param array $overlay Registry to overlay.
	 *
	 * @return array
	 */
	public static function merge( array $base, array $overlay ): array {

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
	 * Method to check whether a language comes from the drop-in directory.
	 *
	 * @param string $id Canonical language id.
	 *
	 * @return bool
	 */
	public function is_dropin( string $id ): bool {
		return (bool) ( $this->_languages[ strtolower( trim( $id ) ) ]['dropin'] ?? false );
	}    //end is_dropin()

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
	 * The plugin version is part of the key, so a plugin update invalidates the
	 * cache and nothing else has to. So is a signature of the drop-in directory,
	 * because a site owner who drops a language file in has done everything the
	 * settings screen asks of them and expects to see it, not to wait out an expiry.
	 *
	 * @return string
	 */
	protected static function _get_cache_key(): string {

		$version = ( defined( 'IG_SYNTAX_HILITER_VERSION' ) ) ? (string) IG_SYNTAX_HILITER_VERSION : '0';

		return sprintf( 'ig-syntax-hiliter-languages-%s-%s', $version, static::_get_dropin_signature() );

	}    //end _get_cache_key()

	/**
	 * Method to get a signature which changes whenever the drop-in directory does.
	 *
	 * A directory's modification time moves when a file inside it is created, removed
	 * or renamed, and the registry is built from nothing but those names — what is
	 * inside a drop-in never reaches it. One `stat` is cheap enough to spend on every
	 * request which renders a snippet, which listing the directory would not be.
	 *
	 * @return string
	 */
	protected static function _get_dropin_signature(): string {

		$dir = static::get_dropin_dir();

		if ( '' === $dir || ! is_dir( $dir ) ) {
			return 'none';
		}

		return (string) (int) filemtime( $dir );

	}    //end _get_dropin_signature()

}    //end of class


//EOF
