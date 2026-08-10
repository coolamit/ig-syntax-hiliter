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
use ReflectionMethod;
use ReflectionProperty;
use WP_UnitTestCase;

/**
 * `get_instance()` end to end — the filter running over a memoised instance, and
 * the cache key noticing a drop-in language arriving.
 *
 * The unit tier covers `parse_manifest()`, `scan_dropins()` and `merge()`, none of
 * which go anywhere near `get_instance()`. Everything here does, because that is
 * where the ordering and the caching live.
 */
class Language_Registry_Filter_Test extends WP_UnitTestCase {

	/**
	 * Id of the language these tests add, by filter or by drop-in file.
	 *
	 * @var string
	 */
	const LANGUAGE = 'igshprobelang';

	/**
	 * Registry cache keys written during a test, deleted afterwards.
	 *
	 * @var array
	 */
	protected array $_cache_keys = [];

	/**
	 * Absolute path of the drop-in directory, once a test has looked it up.
	 *
	 * @var string
	 */
	protected string $_dropin_dir = '';

	/**
	 * Absolute path of the drop-in file a test wrote, if it wrote one.
	 *
	 * @var string
	 */
	protected string $_dropin_file = '';

	/**
	 * Whether the drop-in directory was made by the test rather than found.
	 *
	 * @var bool
	 */
	protected bool $_made_dropin_dir = false;

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
	 * Takes away the drop-in, the cache entries and the memoised objects, so that
	 * whatever runs next sees the registry the plugin actually ships.
	 *
	 * @return void
	 */
	public function tear_down(): void {

		if ( '' !== $this->_dropin_file && file_exists( $this->_dropin_file ) ) {
			unlink( $this->_dropin_file );
		}

		if ( $this->_made_dropin_dir && '' !== $this->_dropin_dir && is_dir( $this->_dropin_dir ) ) {
			rmdir( $this->_dropin_dir );
		}

		clearstatcache();

		foreach ( $this->_cache_keys as $key ) {
			Cache::create( $key )->delete();
		}

		$this->_cache_keys      = [];
		$this->_dropin_dir      = '';
		$this->_dropin_file     = '';
		$this->_made_dropin_dir = false;

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

		( new ReflectionProperty( Language_Registry::class, '_instance' ) )->setValue( null, null );
		( new ReflectionProperty( Renderer::class, '_instance' ) )->setValue( null, null );

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

		remove_filter( Language_Registry::FILTER_LANGUAGES, $callback );

		$this->assertSame( 1, $entries, 'A callback which reads the registry does not send the build round again.' );
		$this->assertSame( $registry, $seen, 'What the callback was shown is the registry the request goes on to use.' );

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

			$registry['languages'][ self::LANGUAGE ] = [
				'title'  => 'Probe Lang',
				'file'   => sprintf( 'prism-%s.min.js', self::LANGUAGE ),
				'dropin' => true,
			];

			return $registry;

		};

		add_filter( Language_Registry::FILTER_LANGUAGES, $callback );

		$this->_remember_cache_key();

		$registry = Language_Registry::get_instance();

		remove_filter( Language_Registry::FILTER_LANGUAGES, $callback );

		$this->assertTrue( $registry->has( self::LANGUAGE ), 'The filter did add a language.' );

		$renderer = Renderer::get_instance();
		$held     = ( new ReflectionProperty( Renderer::class, '_registry' ) )->getValue( $renderer );

		$this->assertSame( $registry, $held, 'The renderer is not left holding a registry nothing else can reach.' );
		$this->assertSame( self::LANGUAGE, $renderer->resolve_language( self::LANGUAGE ), 'A language added by the filter is one the renderer can resolve.' );

	}

	/**
	 * A language file dropped into the uploads directory is visible on the next
	 * request, not on the next plugin release.
	 *
	 * The built registry is cached, and the key used to be the plugin version and
	 * nothing else, with an expiry of a year. A site owner who did exactly what the
	 * settings screen asks — put `prism-{id}.min.js` in the drop-in directory — saw
	 * no change at all until the plugin was next updated.
	 *
	 * @return void
	 */
	public function test_a_dropin_language_does_not_wait_for_a_plugin_update(): void {

		$dir = Language_Registry::get_dropin_dir();

		$this->assertNotSame( '', $dir, 'Drop-in languages live in the uploads directory.' );

		$this->_dropin_dir      = $dir;
		$this->_made_dropin_dir = ( ! is_dir( $dir ) );

		wp_mkdir_p( $dir );
		clearstatcache();

		// A registry built and cached while the site had nothing dropped in.
		$this->_remember_cache_key();

		$this->assertFalse( Language_Registry::get_instance()->has( self::LANGUAGE ), 'Nothing has been dropped in yet.' );

		$before = (int) filemtime( $dir );

		$this->_dropin_file = sprintf( '%s/prism-%s.min.js', $dir, self::LANGUAGE );

		file_put_contents( $this->_dropin_file, '// a grammar the site brought itself' );

		/*
		 * The directory's modification time is what the plugin reads, and it moves in
		 * whole seconds — the build above and this drop land inside the same one often
		 * enough to matter. Moving it on by hand is the passage of time this test
		 * cannot afford to wait for, not a stand-in for the mechanism being tested.
		 */
		touch( $dir, ( $before + 1 ) );

		// The stat cache belongs to this process; a drop-in really arrives in a later request.
		clearstatcache();

		$this->_reset_registry();
		$this->_remember_cache_key();

		$registry = Language_Registry::get_instance();

		$this->assertTrue( $registry->has( self::LANGUAGE ), 'The drop-in is in the registry without the plugin having been updated.' );
		$this->assertTrue( $registry->is_dropin( self::LANGUAGE ), 'It is known to have come from the site, not from the bundle.' );
		$this->assertSame( sprintf( 'prism-%s.min.js', self::LANGUAGE ), $registry->get_file( self::LANGUAGE ) );

	}

	/**
	 * Taking a drop-in away is noticed just as quickly.
	 *
	 * Without this the pair is only half tested: a key which changed when a file
	 * appeared but not when one was removed would leave the registry promising a
	 * language whose file the browser would then fail to fetch.
	 *
	 * @return void
	 */
	public function test_a_removed_dropin_language_leaves_the_registry(): void {

		$dir = Language_Registry::get_dropin_dir();

		$this->_dropin_dir      = $dir;
		$this->_made_dropin_dir = ( ! is_dir( $dir ) );

		wp_mkdir_p( $dir );
		clearstatcache();

		$this->_dropin_file = sprintf( '%s/prism-%s.min.js', $dir, self::LANGUAGE );

		file_put_contents( $this->_dropin_file, '// a grammar the site brought itself' );

		clearstatcache();

		$this->_remember_cache_key();

		$this->assertTrue( Language_Registry::get_instance()->has( self::LANGUAGE ), 'The drop-in is there to begin with.' );

		$before = (int) filemtime( $dir );

		unlink( $this->_dropin_file );

		$this->_dropin_file = '';

		touch( $dir, ( $before + 1 ) );
		clearstatcache();

		$this->_reset_registry();
		$this->_remember_cache_key();

		$this->assertFalse( Language_Registry::get_instance()->has( self::LANGUAGE ), 'A language whose file has gone is not offered.' );

	}

}    //end of class


//EOF
