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
	protected const array _SHIPPED_TAGS = [
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
	protected const array _SHIPPED_ALIASES = [ 'as', 'html', 'js' ];

	/**
	 * Every shipped tag and alias is claimed, plus the generic tag, and nothing else.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_claims_exactly_the_shipped_tags(): void {

		$expected = array_merge( self::_SHIPPED_TAGS, self::_SHIPPED_ALIASES, [ 'sourcecode' ] );
		$actual   = Legacy_Map::get_instance()->get_default_tags();

		sort( $expected );
		sort( $actual );

		$this->assertSame( $expected, $actual );

	}

	/**
	 * Tag matching is case insensitive and tolerant of stray whitespace.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_matches_a_tag_case_insensitively_and_through_stray_whitespace(): void {

		$this->assertSame( 'php', Legacy_Map::get_instance()->to_language_id( '  PhP ' ) );

	}

	/**
	 * The two tags which never highlighted anything still do not.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_maps_a_plain_tag_to_no_language(): void {
		// The value and not only the constant: `language-none` is the highlighter's own no-highlight convention.
		$this->assertSame( 'none', Language_Registry::NO_LANGUAGE );

		$this->assertSame( Language_Registry::NO_LANGUAGE, Legacy_Map::get_instance()->to_language_id( 'code' ) );
		$this->assertSame( Language_Registry::NO_LANGUAGE, Legacy_Map::get_instance()->to_language_id( 'text' ) );

	}

	/**
	 * The renamings that stored content depends on.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_translates_the_renamings_stored_content_depends_on(): void {

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
			$this->assertSame( $language, Legacy_Map::get_instance()->to_language_id( $tag ) );
		}

	}

	/**
	 * Every language the map points at is one the bundled library can load.
	 *
	 * Catches a library upgrade renaming or dropping a language out from under
	 * stored content.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_maps_every_language_to_one_the_library_has(): void {

		$library  = dirname( __DIR__, 2 ) . '/assets/lib/prism';
		$registry = new Language_Registry(
			Language_Registry::parse_manifest(
				$library . '/components.json',
				$library . '/components'
			)
		);

		$this->assertNotEmpty( $registry->get_languages(), 'The bundled language manifest could not be read.' );

		foreach ( Legacy_Map::get_instance()->get_language_map() as $tag => $language ) {

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
	 * Opening and closing tags alike are written with doubled brackets, and reading
	 * them back gives the author's bytes again.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_keeps_a_quoted_tag_through_a_round_trip(): void {

		$code = "[sourcecode language=\"php\"]\n[php]echo 1;[/php]\n[/sourcecode]";

		$escaped = Legacy_Map::get_instance()->escape_tags( $code );

		$this->assertSame(
			"[[sourcecode language=\"php\"]]\n[[php]]echo 1;[[/php]]\n[[/sourcecode]]",
			$escaped,
			'Every tag in the code has to be doubled, opening and closing alike.'
		);

		$this->assertSame( $code, Legacy_Map::get_instance()->unescape_tags( (string) $escaped ) );

	}

	/**
	 * An already escaped tag gains a level and reading it back takes exactly that
	 * level off, so an author writing about the escape loses no bracket per conversion.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_gives_an_already_escaped_tag_one_more_level(): void {

		$escaped = Legacy_Map::get_instance()->escape_tags( 'a [[/php]] b' );

		$this->assertSame( 'a [[[/php]]] b', $escaped );
		$this->assertSame( 'a [[/php]] b', Legacy_Map::get_instance()->unescape_tags( (string) $escaped ) );

	}

	/**
	 * WordPress's own escape for a whole shortcode is a different construct, and the
	 * round trip has to hand it back exactly as it was found.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_keeps_wordpresss_own_escape_through_a_round_trip(): void {

		$code = '[[php]echo 1;[/php]]';

		$this->assertSame( $code, Legacy_Map::get_instance()->unescape_tags( (string) Legacy_Map::get_instance()->escape_tags( $code ) ) );

	}

	/**
	 * A tag this plugin never shipped belongs to somebody else, and neither half of
	 * the escape may touch it.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_leaves_a_tag_which_is_not_ours_alone(): void {

		$this->assertSame( '[email]x[/email]', Legacy_Map::get_instance()->escape_tags( '[email]x[/email]' ) );
		$this->assertSame( '[[email]]', Legacy_Map::get_instance()->unescape_tags( '[[email]]' ) );
		$this->assertSame( '[phpx] and [php-doc]', Legacy_Map::get_instance()->escape_tags( '[phpx] and [php-doc]' ) );

	}

	/**
	 * Code with no bracket in it is handed straight back, which is what keeps the
	 * pattern off the overwhelming majority of snippets.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function it_leaves_code_without_a_bracket_untouched(): void {

		$code = "function f() {\n\treturn 1;\n}";

		$this->assertSame( $code, Legacy_Map::get_instance()->escape_tags( $code ) );
		$this->assertSame( $code, Legacy_Map::get_instance()->unescape_tags( $code ) );

	}

} // end of class

// EOF
