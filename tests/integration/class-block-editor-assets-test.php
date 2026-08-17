<?php
/**
 * What the block editor is handed alongside the block's own script.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration;

use iG\Syntax_Hiliter\Asset_Manager;
use iG\Syntax_Hiliter\Block;
use iG\Syntax_Hiliter\Language_Registry;
use iG\Syntax_Hiliter\Legacy_Map;
use iG\Syntax_Hiliter\Option;
use ReflectionProperty;
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
	 * process — which the `ig_syntax_hiliter/languages` filter may yet have made good.
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

	/**
	 * Method to put the asset state back and run the editor's asset hook.
	 *
	 * @param int $times How many times to fire the hook.
	 *
	 * @return void
	 */
	protected function _fire_block_assets( int $times = 1 ): void {

		for ( $run = 0; $run < $times; $run++ ) {
			Block::get_instance()->enqueue_editor_font();
		}

	}

	/**
	 * Method to read the rules added inline against the editor font stylesheet.
	 *
	 * @return string
	 */
	protected function _editor_font_rules(): string {

		$rules = wp_styles()->get_data( 'ig-syntax-hiliter-editor-font', 'after' );

		if ( ! is_array( $rules ) ) {
			return '';
		}

		return implode( '', $rules );

	}

	/**
	 * Method to forget the editor font, so each case starts where a request does.
	 *
	 * @return void
	 */
	protected function _reset_editor_font(): void {

		wp_dequeue_style( 'ig-syntax-hiliter-editor-font' );
		wp_deregister_style( 'ig-syntax-hiliter-editor-font' );

		( new ReflectionProperty( Asset_Manager::class, '_editor_font_styled' ) )
			->setValue( Asset_Manager::get_instance(), false );

		( new ReflectionProperty( Option::class, '_instance' ) )->setValue( null, null );

	}

	/**
	 * The editor asks for no font unless one is chosen.
	 *
	 * The same promise the front end makes, and it has to be kept on a screen a site
	 * owner opens far more often than they open their own posts.
	 *
	 * @return void
	 */
	public function test_the_editor_fetches_no_font_unless_one_is_chosen(): void {

		$this->_reset_editor_font();

		set_current_screen( 'post' );

		try {

			$this->_fire_block_assets();

			$this->assertArrayNotHasKey( 'ig-syntax-hiliter-editor-font', wp_styles()->registered );
			$this->assertSame( '', $this->_editor_font_rules() );

		} finally {
			$this->_reset_editor_font();

			set_current_screen( 'front' );
		}

	}

	/**
	 * A chosen font reaches the editor, and reaches nothing but this plugin's block.
	 *
	 * The rule sets two custom properties on the block wrapper and says nothing else,
	 * which is what keeps it away from every other block on the screen. Firing the
	 * hook twice is not academic: core fires it a second time while it builds the
	 * editor iframe, and the two passes share the registered style objects.
	 *
	 * @return void
	 */
	public function test_a_chosen_font_reaches_the_block_and_nothing_else(): void {

		$this->_reset_editor_font();

		set_current_screen( 'post' );

		Option::get_instance()->save( 'font', 'fira-code' );

		try {

			$this->_fire_block_assets( 2 );

			$style = wp_styles()->registered['ig-syntax-hiliter-editor-font'] ?? null;

			$this->assertNotNull( $style, 'The webfont stylesheet is registered for the editor.' );
			$this->assertSame( Asset_Manager::get_font_url( 'fira-code' ), (string) $style->src );

			$rules = $this->_editor_font_rules();

			$this->assertSame(
				Asset_Manager::get_editor_font_css( 'fira-code' ),
				$rules,
				'The rule is added once, however many times the hook fires.'
			);

			$this->assertStringStartsWith( '.wp-block-igsyntax-hiliter-code {', $rules );
			$this->assertStringContainsString( '--igsh-editor-font: "Fira Code"', $rules );

		} finally {
			$this->_reset_editor_font();

			set_current_screen( 'front' );
		}

	}

	/**
	 * The editor and the front end name the same family, and only the front end asks
	 * for ligatures.
	 *
	 * The family has to match: two rules describing one font is two chances to
	 * disagree, and both are built from the same map.
	 *
	 * The ligatures have to differ, and that is the point of this case. A textarea is
	 * where an author counts characters and puts a caret between them, and a caret
	 * cannot sit inside one glyph standing for two — typing `__construct` and reading
	 * back what looks like ` _construct` is alarming enough to make somebody correct
	 * code which was never wrong. Putting them back here would look like tidying up an
	 * inconsistency, so this is what says the inconsistency is deliberate.
	 *
	 * @return void
	 */
	public function test_only_the_front_end_asks_for_ligatures(): void {

		foreach ( Asset_Manager::get_fonts() as $slug => $title ) {

			$needle = sprintf( '"%s", %s', $title, Asset_Manager::FONT_STACK );

			$this->assertStringContainsString( $needle, Asset_Manager::get_font_css( $slug ) );
			$this->assertStringContainsString( $needle, Asset_Manager::get_editor_font_css( $slug ) );

			$this->assertStringNotContainsString(
				'ligatures',
				Asset_Manager::get_editor_font_css( $slug ),
				sprintf( 'The editor must say nothing about ligatures, and it does for %s.', $slug )
			);

		}

		/*
		 * The control. Without this the case above would go on passing if the front end
		 * quietly stopped asking for ligatures too.
		 */
		$this->assertStringContainsString(
			'--igsh-code-ligatures',
			Asset_Manager::get_font_css( 'fira-code' ),
			'The front end still asks for ligatures where the family has them.'
		);

	}

	/**
	 * The front end never loads the editor's font stylesheet.
	 *
	 * `enqueue_block_assets` fires on the front end too, and the plugin's whole rule
	 * about assets is that a page carrying no code box downloads nothing of ours.
	 *
	 * @return void
	 */
	public function test_the_front_end_is_left_to_its_own_asset_decision(): void {

		$this->_reset_editor_font();

		Option::get_instance()->save( 'font', 'fira-code' );

		try {

			$this->_fire_block_assets();

			$this->assertArrayNotHasKey( 'ig-syntax-hiliter-editor-font', wp_styles()->registered );

		} finally {
			$this->_reset_editor_font();
		}

	}

}    //end of class


//EOF
