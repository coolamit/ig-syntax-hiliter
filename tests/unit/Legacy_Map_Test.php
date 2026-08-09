<?php
/**
 * Tests for the legacy shortcode tag map.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Unit;

use iG\Syntax_Hiliter\Language_Registry;
use iG\Syntax_Hiliter\Legacy_Map;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/wp-shims.php';

/**
 * The plugin must claim exactly the tags it ships, and no others.
 */
class Legacy_Map_Test extends TestCase {

	/**
	 * The 37 shipped language tags.
	 *
	 * @var array
	 */
	const SHIPPED_TAGS = [
		'actionscript',
		'actionscript3',
		'apache',
		'applescript',
		'asp',
		'bash',
		'c',
		'c_mac',
		'code',
		'cpp',
		'csharp',
		'css',
		'diff',
		'groovy',
		'html4strict',
		'html5',
		'ini',
		'java',
		'java5',
		'javascript',
		'jquery',
		'mysql',
		'oracle11',
		'pcre',
		'perl',
		'perl6',
		'php',
		'postgresql',
		'python',
		'rails',
		'ruby',
		'sql',
		'text',
		'vb',
		'vbnet',
		'xml',
		'yaml',
	];

	/**
	 * The three shorthand aliases registered on top of the shipped tags.
	 *
	 * @var array
	 */
	const SHIPPED_ALIASES = [ 'as', 'html', 'js' ];

	/**
	 * Decision 18 — every shipped tag and alias is claimed, plus the generic tag,
	 * and nothing else. A tag the plugin never shipped is another plugin's to claim.
	 *
	 * @return void
	 */
	public function test_claims_exactly_the_shipped_tags(): void {

		$expected = array_merge( self::SHIPPED_TAGS, self::SHIPPED_ALIASES, [ 'sourcecode' ] );
		$actual   = Legacy_Map::get_default_tags();

		sort( $expected );
		sort( $actual );

		$this->assertSame( $expected, $actual );
		$this->assertCount( 41, $actual );

	}

	/**
	 * Tag matching is case insensitive and tolerant of stray whitespace.
	 *
	 * @return void
	 */
	public function test_tag_matching_is_forgiving(): void {

		$this->assertTrue( Legacy_Map::is_our_tag( ' PHP ' ) );
		$this->assertSame( 'php', Legacy_Map::to_language_id( '  PhP ' ) );

	}

	/**
	 * Every shipped tag and every alias resolves to a language id.
	 *
	 * @return void
	 */
	public function test_every_shipped_tag_resolves(): void {

		foreach ( array_merge( self::SHIPPED_TAGS, self::SHIPPED_ALIASES ) as $tag ) {

			$this->assertNotNull(
				Legacy_Map::to_language_id( $tag ),
				sprintf( '[%s] is a shipped tag and must resolve to a language.', $tag )
			);

		}

		$this->assertCount( 40, Legacy_Map::get_language_map() );

	}

	/**
	 * The two tags which never highlighted anything still do not.
	 *
	 * @return void
	 */
	public function test_plain_tags_map_to_no_language(): void {

		$this->assertSame( Language_Registry::NO_LANGUAGE, Legacy_Map::to_language_id( 'code' ) );
		$this->assertSame( Language_Registry::NO_LANGUAGE, Legacy_Map::to_language_id( 'text' ) );
		$this->assertSame( 'none', Language_Registry::NO_LANGUAGE );

	}

	/**
	 * The renamings that stored content depends on.
	 *
	 * @return void
	 */
	public function test_known_language_translations(): void {

		$expected = [
			'as'            => 'actionscript',
			'js'            => 'javascript',
			'html'          => 'markup',
			'html4strict'   => 'markup',
			'html5'         => 'markup',
			'xml'           => 'markup',
			'apache'        => 'apacheconf',
			'asp'           => 'aspnet',
			'c_mac'         => 'c',
			'java5'         => 'java',
			'jquery'        => 'javascript',
			'mysql'         => 'sql',
			'postgresql'    => 'sql',
			'oracle11'      => 'plsql',
			'pcre'          => 'regex',
			'perl6'         => 'perl',
			'rails'         => 'ruby',
			'vb'            => 'visual-basic',
			'vbnet'         => 'visual-basic',
			'actionscript3' => 'actionscript',
		];

		foreach ( $expected as $tag => $language ) {
			$this->assertSame( $language, Legacy_Map::to_language_id( $tag ) );
		}

	}

	/**
	 * Every language the map points at is one the bundled library can load.
	 *
	 * Catches a library upgrade renaming or dropping a language out from under
	 * stored content.
	 *
	 * @return void
	 */
	public function test_every_mapped_language_exists_in_the_library(): void {

		$library  = dirname( __DIR__, 2 ) . '/assets/lib/prism';
		$registry = new Language_Registry(
			Language_Registry::parse_manifest(
				$library . '/components.json',
				$library . '/components'
			)
		);

		$this->assertNotEmpty( $registry->get_languages(), 'The bundled language manifest could not be read.' );

		foreach ( Legacy_Map::get_language_map() as $tag => $language ) {

			if ( Language_Registry::NO_LANGUAGE === $language ) {
				continue;
			}

			$this->assertTrue(
				$registry->has( $language ),
				sprintf( '[%s] maps to "%s", which the bundled library does not have.', $tag, $language )
			);

		}

	}

}    //end of class


//EOF
