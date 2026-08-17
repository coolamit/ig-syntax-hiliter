<?php
/**
 * Tests for the language registry.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Unit;

use iG\Syntax_Hiliter\Language_Registry;
use PHPUnit\Framework\TestCase;

/**
 * Manifest parsing, overlaying and resolution.
 *
 * All of it is the WordPress free half of the class; the half which knows about
 * options, caching and filters belongs to the integration tier.
 */
class Language_Registry_Test extends TestCase {

	/**
	 * Temporary directory made for a test, removed afterwards.
	 *
	 * @var string
	 */
	protected string $temp_dir = '';

	/**
	 * Clean up any temporary directory the test made.
	 *
	 * @return void
	 */
	protected function tearDown(): void {

		if ( '' !== $this->temp_dir && is_dir( $this->temp_dir ) ) {

			foreach ( (array) glob( $this->temp_dir . '/*' ) as $file ) {
				unlink( (string) $file );
			}

			rmdir( $this->temp_dir );

		}

		$this->temp_dir = '';

		parent::tearDown();

	}

	/**
	 * Makes a throwaway directory for a test to write fixtures into.
	 *
	 * @return string Absolute path with no trailing slash.
	 */
	protected function make_temp_dir(): string {

		$this->temp_dir = sys_get_temp_dir() . '/igsh-registry-' . uniqid( '', true );

		mkdir( $this->temp_dir, 0777, true );

		return $this->temp_dir;

	}

	/**
	 * A registry which knows a few languages and a few aliases.
	 *
	 * @return \iG\Syntax_Hiliter\Language_Registry
	 */
	protected function get_fixture_registry(): Language_Registry {

		return new Language_Registry(
			[
				'languages' => [
					'javascript' => [
						'title' => 'JavaScript',
						'file'  => 'prism-javascript.min.js',
					],
					'markup'     => [
						'title' => 'Markup',
						'file'  => 'prism-markup.min.js',
					],
				],
				'aliases'   => [
					'js'    => 'javascript',
					'html'  => 'markup',
					'ghost' => 'nowhere',
				],
			]
		);

	}

	/**
	 * A manifest entry is kept only when its language file is actually there.
	 *
	 * @return void
	 */
	public function test_manifest_is_intersected_with_the_files_on_disk(): void {

		$dir = $this->make_temp_dir();

		file_put_contents( $dir . '/prism-present.min.js', '// present' );
		file_put_contents(
			$dir . '/components.json',
			(string) json_encode(
				[
					'languages' => [
						'meta'    => [ 'path' => 'components/prism-{id}' ],
						'present' => [ 'title' => 'Present' ],
						'missing' => [ 'title' => 'Missing' ],
					],
				]
			)
		);

		$registry = Language_Registry::parse_manifest( $dir . '/components.json', $dir );

		$this->assertArrayHasKey( 'present', $registry['languages'] );
		$this->assertArrayNotHasKey( 'missing', $registry['languages'] );
		$this->assertArrayNotHasKey( 'meta', $registry['languages'] );
		$this->assertSame( 'Present', $registry['languages']['present']['title'] );
		$this->assertSame( 'prism-present.min.js', $registry['languages']['present']['file'] );

	}

	/**
	 * Aliases are read whether the manifest gives one or many.
	 *
	 * @return void
	 */
	public function test_manifest_aliases_are_read(): void {

		$dir = $this->make_temp_dir();

		file_put_contents( $dir . '/prism-one.min.js', '// one' );
		file_put_contents( $dir . '/prism-two.min.js', '// two' );
		file_put_contents(
			$dir . '/components.json',
			(string) json_encode(
				[
					'languages' => [
						'one' => [
							'title' => 'One',
							'alias' => 'uno',
						],
						'two' => [
							'title' => 'Two',
							'alias' => [ 'dos', 'DUE' ],
						],
					],
				]
			)
		);

		$registry = Language_Registry::parse_manifest( $dir . '/components.json', $dir );

		$this->assertSame(
			[
				'uno' => 'one',
				'dos' => 'two',
				'due' => 'two',
			],
			$registry['aliases']
		);

	}

	/**
	 * A manifest which is missing, empty or malformed yields an empty registry.
	 *
	 * @return void
	 */
	public function test_unreadable_manifest_is_survivable(): void {

		$dir = $this->make_temp_dir();

		file_put_contents( $dir . '/broken.json', '{ not json at all' );

		$empty = [
			'languages' => [],
			'aliases'   => [],
		];

		$this->assertSame( $empty, Language_Registry::parse_manifest( $dir . '/nope.json', $dir ) );
		$this->assertSame( $empty, Language_Registry::parse_manifest( $dir . '/broken.json', $dir ) );

	}

	/**
	 * An overlay replaces the language of the same name and adds its own.
	 *
	 * The plugin builds with no overlay of its own, so what this covers is the
	 * `ig_syntax_hiliter/languages` filter — the one way a site can still change what
	 * the registry holds.
	 *
	 * @return void
	 */
	public function test_an_overlay_replaces_the_bundled_languages(): void {

		$base = [
			'languages' => [
				'php'  => [
					'title' => 'PHP',
					'file'  => 'prism-php.min.js',
				],
				'ruby' => [
					'title' => 'Ruby',
					'file'  => 'prism-ruby.min.js',
				],
			],
			'aliases'   => [
				'rb'    => 'ruby',
				'stale' => 'perl',
			],
		];

		$overlay = [
			'languages' => [
				'php'    => [
					'title' => 'php',
					'file'  => 'prism-php.js',
				],
				'mylang' => [
					'title' => 'mylang',
					'file'  => 'prism-mylang.min.js',
				],
			],
			'aliases'   => [],
		];

		$merged = Language_Registry::merge( $base, $overlay );

		$this->assertSame( 'prism-php.js', $merged['languages']['php']['file'] );
		$this->assertSame( 'prism-ruby.min.js', $merged['languages']['ruby']['file'] );
		$this->assertArrayHasKey( 'mylang', $merged['languages'] );

		// An alias pointing at a language which is not there is dropped.
		$this->assertSame( [ 'rb' => 'ruby' ], $merged['aliases'] );

	}

	/**
	 * Called with no overlay, the merge still drops a dangling alias and sorts.
	 *
	 * That is the shape `build()` uses it in, and it is what keeps the registry from
	 * carrying an alias which resolves to a language the browser cannot load.
	 *
	 * @return void
	 */
	public function test_merging_nothing_still_tidies_the_registry(): void {

		$merged = Language_Registry::merge(
			[
				'languages' => [
					'ruby' => [
						'title' => 'Ruby',
						'file'  => 'prism-ruby.min.js',
					],
					'php'  => [
						'title' => 'PHP',
						'file'  => 'prism-php.min.js',
					],
				],
				'aliases'   => [
					'rb'    => 'ruby',
					'stale' => 'perl',
					'php'   => 'ruby',
				],
			]
		);

		$this->assertSame( [ 'php', 'ruby' ], array_keys( $merged['languages'] ) );

		// `stale` points nowhere; `php` shadows a language id.
		$this->assertSame( [ 'rb' => 'ruby' ], $merged['aliases'] );

	}

	/**
	 * Resolution is case insensitive, whitespace tolerant and alias aware, and
	 * anything it cannot confirm resolves to nothing at all. An alias pointing
	 * at a language which is not there is one of those, having been discarded when
	 * the registry was built.
	 *
	 * @return void
	 */
	public function test_resolve(): void {

		$registry = $this->get_fixture_registry();

		$this->assertSame( 'javascript', $registry->resolve( 'javascript' ) );
		$this->assertSame( 'javascript', $registry->resolve( 'js' ) );
		$this->assertSame( 'javascript', $registry->resolve( '  JS  ' ) );
		$this->assertSame( 'javascript', $registry->resolve( 'JavaScript' ) );
		$this->assertSame( 'markup', $registry->resolve( 'HTML' ) );

		$this->assertNull( $registry->resolve( 'madeuplang' ) );
		$this->assertNull( $registry->resolve( '' ) );
		$this->assertNull( $registry->resolve( '   ' ) );
		$this->assertNull( $registry->resolve( Language_Registry::NO_LANGUAGE ) );
		$this->assertNull( $registry->resolve( 'ghost' ) );

	}

	/**
	 * The registry answers the questions the renderer and asset manager ask of it.
	 *
	 * @return void
	 */
	public function test_language_lookups(): void {

		$registry = $this->get_fixture_registry();

		$this->assertTrue( $registry->has( 'javascript' ) );
		$this->assertTrue( $registry->has( ' JavaScript ' ) );
		$this->assertFalse( $registry->has( 'js' ) );
		$this->assertFalse( $registry->has( 'madeuplang' ) );

		$this->assertSame( 'JavaScript', $registry->get_title( 'javascript' ) );
		$this->assertNull( $registry->get_title( 'madeuplang' ) );

		$this->assertSame( 'prism-javascript.min.js', $registry->get_file( 'javascript' ) );
		$this->assertNull( $registry->get_file( 'madeuplang' ) );

		$this->assertSame(
			[
				[
					'id'    => 'javascript',
					'title' => 'JavaScript',
				],
				[
					'id'    => 'markup',
					'title' => 'Markup',
				],
			],
			$registry->get_choices()
		);

	}

	/**
	 * The manifest the plugin actually ships parses, and holds what it should.
	 *
	 * The languages the legacy tags point at are `Legacy_Map_Test`'s business; what
	 * is checked here is that the shipped manifest is readable at all, that its own
	 * aliases survive parsing, and that the two ids which are not languages are not
	 * treated as though they were.
	 *
	 * @return void
	 */
	public function test_bundled_manifest(): void {

		$library  = dirname( __DIR__, 2 ) . '/assets/lib/prism';
		$registry = new Language_Registry(
			Language_Registry::parse_manifest(
				$library . '/components.json',
				$library . '/components'
			)
		);

		$this->assertGreaterThan( 250, count( $registry->get_languages() ) );

		$this->assertSame( 'bash', $registry->resolve( 'shell' ) );

		// The unknown language fallback depends on "none" being a convention of the
		// highlighter, not a language it can load.
		$this->assertFalse( $registry->has( Language_Registry::NO_LANGUAGE ) );

		// The core file is not a language either.
		$this->assertFalse( $registry->has( 'core' ) );

	}

}    //end of class

//EOF
