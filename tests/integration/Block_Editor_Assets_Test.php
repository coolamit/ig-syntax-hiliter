<?php
/**
 * What the block editor is handed alongside the block's own script.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Block;
use iG\Syntax_Hiliter\Language_Registry;
use iG\Syntax_Hiliter\Legacy_Map;
use WP_Block_Type_Registry;
use WP_UnitTestCase;

/**
 * The editor script's translations, and the data object `Block::add_editor_data()`
 * attaches to it.
 *
 * Both hang off a handle this plugin does not choose and cannot see from the
 * JavaScript side, so a change in how `register_block_type()` names its scripts
 * takes them away silently: the editor still loads and still works, in English,
 * with an empty language dropdown.
 */
class Block_Editor_Assets_Test extends WP_UnitTestCase {

	/**
	 * The block's generated editor script handle.
	 *
	 * @return string
	 */
	protected function _get_editor_handle(): string {

		$block_type = WP_Block_Type_Registry::get_instance()->get_registered( Block::NAME );

		$this->assertNotNull( $block_type, 'The block is registered, so this checkout has been built.' );

		return (string) ( $block_type->editor_script_handles[0] ?? '' );

	}

	/**
	 * The editor's own strings can be translated.
	 *
	 * Nothing in this plugin calls `wp_set_script_translations()`. Core does it, in
	 * `register_block_script_handle()`, and only when **both** halves of a condition
	 * hold: `block.json` declares a `textdomain`, and the built script lists `wp-i18n`
	 * among its dependencies. Both halves are ours to keep — the first is a line in
	 * `src/block/block.json`, the second comes from the editor actually importing
	 * `@wordpress/i18n` and is written into `index.asset.php` at build time.
	 *
	 * Lose either and every inspector label, the placeholder and the transform names
	 * silently revert to English while `block.json`'s title and description, which
	 * core translates by a different route, go on speaking the site's language. That
	 * split is what makes the gap easy to miss, which is why it is asserted here
	 * rather than trusted to review.
	 *
	 * @return void
	 */
	public function test_the_editor_script_is_set_up_for_translation(): void {

		$handle = $this->_get_editor_handle();

		$this->assertNotSame( '', $handle, 'Block registration generated an editor script handle.' );

		$script = wp_scripts()->registered[ $handle ] ?? null;

		$this->assertNotNull( $script, 'The generated handle is a registered script.' );
		$this->assertContains( 'wp-i18n', $script->deps, 'The editor script still imports the internationalisation library.' );
		$this->assertSame( 'igsyntax-hiliter', $script->textdomain, 'The editor script is pointed at this plugin\'s text domain.' );

	}

	/**
	 * The editor is handed the tag list and the languages PHP knows about.
	 *
	 * The editor claims exactly the tags PHP claims, filter included, rather than
	 * carrying a second copy of the list in JavaScript. If this never arrives the
	 * language dropdown is empty and automatic conversion claims nothing.
	 *
	 * @return void
	 */
	public function test_the_editor_is_handed_the_tag_list_and_the_languages(): void {

		$data = $this->_get_localised_editor_data();

		$this->assertNotEmpty( $data['languages'] ?? [], 'The language dropdown has something to draw.' );
		$this->assertContains( 'php', $data['legacyTags'] ?? [], 'The editor claims the tags PHP claims.' );
		$this->assertSame( 'sourcecode', $data['genericTag'] ?? '', 'The generic tag is named.' );

	}

	/**
	 * The editor can turn a legacy tag or an alias into a canonical language id.
	 *
	 * The dropdown is drawn from canonical ids alone, so an alias which never
	 * reaches the editor is a snippet converted to a block holding a name no option
	 * carries — where the control shows the first option instead and writing that
	 * back destroys a language which was highlighting perfectly well.
	 *
	 * @return void
	 */
	public function test_the_editor_is_handed_the_language_aliases(): void {

		$data    = $this->_get_localised_editor_data();
		$aliases = $data['languageAliases'] ?? [];

		$this->assertIsArray( $aliases, 'The alias table reaches the editor.' );
		$this->assertSame( Language_Registry::NO_LANGUAGE, $data['noLanguage'] ?? '', 'The sentinel is named rather than left for the editor to guess.' );

		// The library's own aliases, which is what `[sourcecode language="js"]` needs.
		$this->assertSame( 'javascript', $aliases['js'] ?? '', 'A library alias resolves.' );

		// This plugin's own tags, which the library has never heard of.
		$this->assertSame( 'markup', $aliases['html'] ?? '', 'A legacy tag resolves.' );
		$this->assertSame( 'markup', $aliases['html4strict'] ?? '', 'A GeSHi era tag resolves.' );
		$this->assertSame( 'apacheconf', $aliases['apache'] ?? '', 'A legacy tag whose id is spelled differently resolves.' );
		$this->assertSame( 'sql', $aliases['mysql'] ?? '', 'A dialect resolves to the language which highlights it.' );

		// `[code]` and `[text]` have meant "show it, do not highlight it" since 2004.
		$this->assertSame( Language_Registry::NO_LANGUAGE, $aliases['code'] ?? '', 'The generic legacy tag resolves to the sentinel.' );
		$this->assertSame( Language_Registry::NO_LANGUAGE, $aliases['text'] ?? '', 'The plain text tag resolves to the sentinel.' );

		$registry = Language_Registry::get_instance();

		foreach ( Legacy_Map::get_language_map() as $tag => $id ) {

			if ( Language_Registry::NO_LANGUAGE === $id ) {
				continue;
			}

			$this->assertTrue(
				$registry->has( $id ),
				sprintf( 'The legacy map sends `%1$s` to `%2$s`, which the bundled library still has a file for.', $tag, $id )
			);

			$this->assertSame( $id, $aliases[ $tag ] ?? '', sprintf( 'The editor resolves `%s` the way the server does.', $tag ) );

		}

	}

	/**
	 * Every alias offered names a language the site can actually load.
	 *
	 * An entry pointing nowhere would put an id into a block attribute which nothing
	 * on the site can highlight, and would throw away the author's own word in the
	 * process — which a drop-in language file may yet have made good.
	 *
	 * @return void
	 */
	public function test_no_alias_points_at_a_language_the_site_cannot_load(): void {

		$aliases  = $this->_get_localised_editor_data()['languageAliases'] ?? [];
		$registry = Language_Registry::get_instance();

		$this->assertNotEmpty( $aliases, 'There is something to check.' );

		foreach ( $aliases as $alias => $id ) {

			if ( Language_Registry::NO_LANGUAGE === $id ) {
				continue;
			}

			$this->assertTrue( $registry->has( $id ), sprintf( 'The alias `%1$s` points at `%2$s`, which the registry holds.', $alias, $id ) );

		}

	}

	/**
	 * The editor data object, as it reaches the browser.
	 *
	 * @return array
	 */
	protected function _get_localised_editor_data(): array {

		$handle = $this->_get_editor_handle();

		/*
		 * `wp_scripts()` is a global which outlives a test, and inline scripts are
		 * appended rather than replaced, so a run which has already called this method
		 * leaves a copy behind. Start from nothing so what is read below is what this
		 * call wrote.
		 */
		unset( wp_scripts()->registered[ $handle ]->extra['before'] );

		Block::get_instance()->add_editor_data();

		$before = wp_scripts()->get_data( $handle, 'before' );
		$before = ( is_array( $before ) ) ? implode( "\n", $before ) : (string) $before;

		$this->assertStringContainsString( Block::EDITOR_DATA_OBJECT, $before, 'The data object reaches the editor.' );

		$this->assertSame(
			1,
			preg_match( sprintf( '/var %s = (\{.*\});/s', preg_quote( Block::EDITOR_DATA_OBJECT, '/' ) ), $before, $matches ),
			'The data object is assigned as one JSON literal.'
		);

		$data = json_decode( $matches[1], true );

		$this->assertIsArray( $data, 'What was printed is readable JSON.' );

		return $data;

	}

}    //end of class


//EOF
