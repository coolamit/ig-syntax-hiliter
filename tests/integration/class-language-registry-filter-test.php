<?php
/**
 * The half of the language registry which needs WordPress: the extension filter,
 * and the cache the registry is built through.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Cache;
use iG\Syntax_Hiliter\Language_Registry;
use iG\Syntax_Hiliter\Renderer;
use iG\Syntax_Hiliter\Tests\Integration\Traits\Pipeline_Test_Helpers;
use ReflectionMethod;
use ReflectionProperty;
use WP_UnitTestCase;

/**
 * The registry's load end to end — the extension filter running over an instance
 * which has already been handed out, and the cache it is built through.
 *
 * The unit tier covers `parse_manifest()` and `merge()`, neither of which goes
 * anywhere near a cache or a filter. Everything here does, because that is where
 * the ordering and the caching live.
 */
class Language_Registry_Filter_Test extends WP_UnitTestCase {

	use Pipeline_Test_Helpers;

	/**
	 * Id of the language these tests add through the filter.
	 *
	 * @var string
	 */
	protected const string _LANGUAGE = 'igshprobelang';

	/**
	 * Registry cache keys written during a test, deleted afterwards.
	 *
	 * @var array
	 */
	protected array $_cache_keys = [];

	/**
	 * Starts every test from the state a request which has not yet built a registry
	 * starts in.
	 *
	 * @return void
	 */
	public function set_up(): void {

		parent::set_up();

		$this->_reset_registry();

	}

	/**
	 * Takes away the cache entries and the memoised objects, so that whatever runs
	 * next sees the registry the plugin actually ships.
	 *
	 * @return void
	 */
	public function tear_down(): void {

		foreach ( $this->_cache_keys as $key ) {
			Cache::create( $key )->delete();
		}

		$this->_cache_keys = [];

		$this->_reset_registry();

		parent::tear_down();

	}

	/**
	 * Method to put the registry and its one collaborator back to their unbuilt state.
	 *
	 * Both are memoised for the life of the process, and the thing under test is what
	 * happens the first time a request asks for them.
	 *
	 * @return void
	 */
	protected function _reset_registry(): void {

		$this->_set_singleton( Language_Registry::class, null );
		$this->_set_singleton( Renderer::class, null );

	}

	/**
	 * Method to note the cache key the registry would use right now, so that the
	 * entry written under it can be cleared away again.
	 *
	 * @return void
	 */
	protected function _remember_cache_key(): void {

		$key = (string) ( new ReflectionMethod( Language_Registry::class, '_get_cache_key' ) )->invoke( null );

		$this->_cache_keys[ $key ] = $key;

	}

	/**
	 * A filter callback may ask the registry what it currently holds.
	 *
	 * That is the obvious first move for a callback which means to adjust the
	 * registry rather than replace it, and it used to be fatal: the instance was
	 * published only after the filter had returned, so the callback re-entered a
	 * build which had not finished and went round until the stack ran out — an
	 * Xdebug loop error with the debugger on, a segmentation fault without it.
	 *
	 * The callback counts its own entries and stops calling after the second, so
	 * that a regression here reports a number instead of taking the process down.
	 *
	 * The registry is read after it is fetched, because the filter now runs the
	 * first time the object is asked a question rather than while it is being built.
	 * That is what put the recursion out of reach: the instance has been handed out
	 * before any callback can run, so a callback asking for it gets the same object
	 * back instead of starting a second build.
	 *
	 * @return void
	 */
	public function test_a_filter_callback_may_ask_the_registry_what_it_holds(): void {

		$entries = 0;
		$seen    = null;

		$callback = static function ( array $registry ) use ( &$entries, &$seen ): array {

			++$entries;

			if ( 2 > $entries ) {
				$seen = Language_Registry::get_instance();
			}

			return $registry;

		};

		add_filter( Language_Registry::FILTER_LANGUAGES, $callback );

		$this->_remember_cache_key();

		$registry = Language_Registry::get_instance();

		//the load, and with it the filter, runs on the first question asked of it
		$registry->has( 'php' );

		remove_filter( Language_Registry::FILTER_LANGUAGES, $callback );

		$this->assertSame( 1, $entries, 'A callback which reads the registry does not send the build round again.' );
		$this->assertSame( $registry, $seen, 'What the callback was shown is the registry the request goes on to use.' );

	}

	/**
	 * A filter callback may ask the registry a question, and not merely ask for it.
	 *
	 * The sharper half of the case above, and it is what makes this arrangement safe
	 * to rearrange again. Fetching the object is now cheap and cannot recurse; the
	 * load is what runs the filter, so a callback which *reads* is the one which
	 * could re-enter it. It does not, because the loaded flag is set before the
	 * filter is applied — which also means the callback is shown the dataset as the
	 * cache produced it, unfiltered, exactly as it was shown before.
	 *
	 * @return void
	 */
	public function test_a_filter_callback_may_read_from_the_registry(): void {

		$entries = 0;
		$answer  = null;

		$callback = static function ( array $registry ) use ( &$entries, &$answer ): array {

			++$entries;

			if ( 2 > $entries ) {
				$answer = Language_Registry::get_instance()->has( 'php' );
			}

			return $registry;

		};

		add_filter( Language_Registry::FILTER_LANGUAGES, $callback );

		$this->_remember_cache_key();

		$registry = Language_Registry::get_instance();

		$registry->has( 'php' );

		remove_filter( Language_Registry::FILTER_LANGUAGES, $callback );

		$this->assertSame( 1, $entries, 'A callback which reads the registry re-entered the load.' );
		$this->assertTrue( $answer, 'And what it read was the dataset, not an empty registry.' );

	}

	/**
	 * Anything which took hold of the registry during the filter ends up holding the
	 * filtered one.
	 *
	 * The renderer is the collaborator that actually does this: it is handed the
	 * registry once, in its constructor, and keeps it for the rest of the request.
	 * Build it from inside a filter callback and it must still see the language that
	 * same callback is in the middle of adding — which is why the filtered registry
	 * is read into the published instance rather than swapped in as a second object.
	 *
	 * @return void
	 */
	public function test_a_registry_taken_during_the_filter_is_the_filtered_one(): void {

		$entries = 0;

		$callback = static function ( array $registry ) use ( &$entries ): array {

			++$entries;

			/*
			 * All of this happens on the first entry only. A second entry is the
			 * recursion this is about; doing the work again inside it would hide the
			 * very thing being measured, as well as running the stack out before any
			 * assertion below was reached.
			 */
			if ( 1 !== $entries ) {
				return $registry;
			}

			Renderer::get_instance();

			$registry['languages'][ self::_LANGUAGE ] = [
				'title' => 'Probe Lang',
			];

			return $registry;

		};

		add_filter( Language_Registry::FILTER_LANGUAGES, $callback );

		$this->_remember_cache_key();

		$registry = Language_Registry::get_instance();

		//asking it anything is what loads it, and this assertion is the asking
		$this->assertTrue( $registry->has( self::_LANGUAGE ), 'The filter did add a language.' );

		remove_filter( Language_Registry::FILTER_LANGUAGES, $callback );

		$renderer = Renderer::get_instance();
		$held     = ( new ReflectionProperty( Renderer::class, '_registry' ) )->getValue( $renderer );

		$this->assertSame( $registry, $held, 'The renderer is not left holding a registry nothing else can reach.' );
		$this->assertSame( self::_LANGUAGE, $renderer->resolve_language( self::_LANGUAGE ), 'A language added by the filter is one the renderer can resolve.' );

	}

	/**
	 * A callback which hands back something that is not a registry is ignored, and
	 * the registry the site already had is what the request goes on to use.
	 *
	 * A callback that forgets to return, or returns early down one branch, hands back
	 * NULL. That used to be cast to an array and read in, which emptied the registry:
	 * every language on the site became unknown, every snippet rendered without
	 * highlighting, and no asset was loaded for one. Nothing said so — the page came
	 * back 200 with the code in it, only plain. Ignoring the return is the far cheaper
	 * reading of a callback which plainly did not mean to replace anything.
	 *
	 * @dataProvider useless_return_provider
	 *
	 * @param mixed  $handed_back What the callback hands back.
	 * @param string $description What that stands for, for the failure message.
	 *
	 * @return void
	 */
	public function test_a_filter_returning_something_useless_is_ignored( $handed_back, string $description ): void {

		$this->_remember_cache_key();

		$expected = Language_Registry::get_instance()->get_languages();

		$this->assertNotEmpty( $expected, 'The unfiltered registry has languages in it to begin with.' );

		$this->_reset_registry();

		$callback = static function () use ( $handed_back ) {

			return $handed_back;

		};

		add_filter( Language_Registry::FILTER_LANGUAGES, $callback );

		$this->_remember_cache_key();

		$registry = Language_Registry::get_instance();

		remove_filter( Language_Registry::FILTER_LANGUAGES, $callback );

		$this->assertSame( $expected, $registry->get_languages(), sprintf( 'A callback returning %s leaves the registry alone.', $description ) );
		$this->assertTrue( $registry->has( 'php' ), sprintf( 'A callback returning %s does not take the site\'s languages away.', $description ) );

	}

	/**
	 * Returns which are not a registry, and the mistake each one stands for.
	 *
	 * @return array
	 */
	public function useless_return_provider(): array {

		return [
			'no return statement' => [ null, 'NULL' ],
			'a string'            => [ 'php', 'a string' ],
			'a count'             => [ 0, 'a number' ],
			'a flag'              => [ false, 'false' ],
		];

	}

	/**
	 * A language the filter added is never written into the cache.
	 *
	 * The filter deliberately runs after the cache, and this is what that buys: a
	 * site which removes its callback gets the plugin's own registry back on the very
	 * next request. Bake the filtered value in instead and the callback's languages
	 * outlive it by up to a day, which is a site rendering snippets against a grammar
	 * nothing is loading any more.
	 *
	 * @return void
	 */
	public function test_a_language_added_by_the_filter_is_not_cached(): void {

		$callback = static function ( array $registry ): array {

			$registry['languages'][ self::_LANGUAGE ] = [
				'title' => 'Probe Lang',
			];

			return $registry;

		};

		add_filter( Language_Registry::FILTER_LANGUAGES, $callback );

		$this->_remember_cache_key();

		$this->assertTrue( Language_Registry::get_instance()->has( self::_LANGUAGE ), 'The filter did add a language.' );

		remove_filter( Language_Registry::FILTER_LANGUAGES, $callback );

		// A later request, with the callback gone but the cache entry the first one wrote still there.
		$this->_reset_registry();

		$this->assertFalse(
			Language_Registry::get_instance()->has( self::_LANGUAGE ),
			'A language the filter added was written into the cache and outlived the callback.'
		);

	}

	/**
	 * The cache is what the registry is built through, and a second request in the
	 * same state does not build it again.
	 *
	 * Parsing three hundred manifest entries and stat-ing a file for each is the
	 * whole cost of this class, and it is paid once per plugin version rather than
	 * once per request. A cache entry which stopped being read would be invisible —
	 * the registry would be right, and every page would be slower.
	 *
	 * @return void
	 */
	public function test_the_registry_is_served_from_the_cache_on_a_later_request(): void {

		$this->_remember_cache_key();

		$key = (string) ( new ReflectionMethod( Language_Registry::class, '_get_cache_key' ) )->invoke( null );

		$this->assertTrue( Language_Registry::get_instance()->has( 'php' ), 'The registry built.' );

		$stored = get_option( Cache::KEY_PREFIX . md5( $key ) );

		$this->assertIsArray( $stored, 'The build was written to the cache.' );
		$this->assertArrayHasKey( 'php', $stored['data']['languages'] ?? [] );

		/*
		 * Put a registry of one language into that entry and ask again. Anything which
		 * rebuilt from disk would answer with the bundled list; only a read of the cache
		 * can answer with this.
		 */
		$stored['data'] = [
			'languages' => [
				self::_LANGUAGE => [
					'title' => 'Probe Lang',
				],
			],
			'aliases'   => [],
		];

		update_option( Cache::KEY_PREFIX . md5( $key ), $stored, false );

		$this->_reset_registry();

		$registry = Language_Registry::get_instance();

		$this->assertTrue( $registry->has( self::_LANGUAGE ), 'The registry was rebuilt rather than read from the cache.' );
		$this->assertFalse( $registry->has( 'php' ), 'The registry was rebuilt rather than read from the cache.' );

	}

}    //end of class


//EOF
