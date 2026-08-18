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
	 * Every shipped tag and alias is claimed, plus the generic tag, and nothing
	 * else. A tag the plugin never shipped is another plugin's to claim.
	 *
	 * @return void
	 */
	public function test_claims_exactly_the_shipped_tags(): void {

		$expected = array_merge( self::SHIPPED_TAGS, self::SHIPPED_ALIASES, [ 'sourcecode' ] );
		$actual   = Legacy_Map::get_default_tags();

		sort( $expected );
		sort( $actual );

		$this->assertSame( $expected, $actual );

	}

	/**
	 * Tag matching is case insensitive and tolerant of stray whitespace.
	 *
	 * @return void
	 */
	public function test_tag_matching_is_forgiving(): void {

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
		/*
		 * The value and not only the constant. The editor mirrors this literal in
		 * `src/block/attributes.ts`, where PHP sends the constant over precisely so
		 * that the two cannot drift — and every other use of `NO_LANGUAGE` in either
		 * PHP tier names the constant on both sides, so without this line changing it
		 * would break the editor's fallback and fail no PHP test.
		 */
		$this->assertSame( 'none', Language_Registry::NO_LANGUAGE );

		$this->assertSame( Language_Registry::NO_LANGUAGE, Legacy_Map::to_language_id( 'code' ) );
		$this->assertSame( Language_Registry::NO_LANGUAGE, Legacy_Map::to_language_id( 'text' ) );

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

	/**
	 * The escape exists so that a snippet can quote this plugin's own tags. Both a
	 * closing tag, which is what used to cut such a snippet short, and an opening tag
	 * are written with doubled brackets, and reading them back gives the author's
	 * bytes again.
	 *
	 * @return void
	 */
	public function test_a_quoted_tag_survives_a_round_trip(): void {

		$code = "[sourcecode language=\"php\"]\n[php]echo 1;[/php]\n[/sourcecode]";

		$escaped = Legacy_Map::escape_tags( $code );

		$this->assertSame(
			"[[sourcecode language=\"php\"]]\n[[php]]echo 1;[[/php]]\n[[/sourcecode]]",
			$escaped,
			'Every tag in the code has to be doubled, opening and closing alike.'
		);

		$this->assertSame( $code, Legacy_Map::unescape_tags( (string) $escaped ) );

	}

	/**
	 * A tag which is already escaped gains a level rather than being left as it is,
	 * and reading it back takes exactly that level off again. Anything else and an
	 * author who wrote about the escape itself would lose a bracket per conversion.
	 *
	 * @return void
	 */
	public function test_an_already_escaped_tag_gains_one_level(): void {

		$escaped = Legacy_Map::escape_tags( 'a [[/php]] b' );

		$this->assertSame( 'a [[[/php]]] b', $escaped );
		$this->assertSame( 'a [[/php]] b', Legacy_Map::unescape_tags( (string) $escaped ) );

	}

	/**
	 * WordPress's own escape for a whole shortcode is a different construct, and the
	 * round trip has to hand it back exactly as it was found.
	 *
	 * @return void
	 */
	public function test_wordpresss_own_escape_survives_a_round_trip(): void {

		$code = '[[php]echo 1;[/php]]';

		$this->assertSame( $code, Legacy_Map::unescape_tags( (string) Legacy_Map::escape_tags( $code ) ) );

	}

	/**
	 * A tag this plugin never shipped belongs to somebody else, and neither half of
	 * the escape may touch it.
	 *
	 * @return void
	 */
	public function test_a_tag_which_is_not_ours_is_left_alone(): void {

		$this->assertSame( '[email]x[/email]', Legacy_Map::escape_tags( '[email]x[/email]' ) );
		$this->assertSame( '[[email]]', Legacy_Map::unescape_tags( '[[email]]' ) );
		$this->assertSame( '[phpx] and [php-doc]', Legacy_Map::escape_tags( '[phpx] and [php-doc]' ) );

	}

	/**
	 * Code with no bracket in it is handed straight back, which is what keeps the
	 * pattern off the overwhelming majority of snippets.
	 *
	 * @return void
	 */
	public function test_code_without_a_bracket_is_untouched(): void {

		$code = "function f() {\n\treturn 1;\n}";

		$this->assertSame( $code, Legacy_Map::escape_tags( $code ) );
		$this->assertSame( $code, Legacy_Map::unescape_tags( $code ) );

	}

}    //end of class


//EOF
