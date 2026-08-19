<?php
/**
 * The fonts the plugin offers, and the CSS which applies one.
 *
 * @package iG_Syntax_Hiliter
 */

namespace iG\Syntax_Hiliter;

/**
 * The font catalogue.
 *
 * The single declaration of what a font is, what weight of it is fetched, and what
 * CSS applies it — front end and block editor alike. `Asset_Manager` asks for a URL
 * and a rule and enqueues them; the decision about which font a site is running is
 * the settings screen's and not this class's.
 *
 * All static and deliberately not a service, for the reason `Themes` is: nothing
 * here needs an instance to hold.
 */
class Fonts {

	/**
	 * Font setting value which means "load no webfont at all".
	 *
	 * This is the default, and it is the only value which costs a reader nothing: a
	 * chosen font is fetched from another host, and a plugin which did that without
	 * being asked would be making that decision on a site owner's behalf.
	 *
	 * @var string
	 */
	public const string FONT_NONE = 'none';

	/**
	 * Where the webfont stylesheets are fetched from.
	 *
	 * Bunny Fonts, which serves the same API shape as Google Fonts and states that it
	 * stores no personal data and no logs. That is the whole reason it was chosen over
	 * Google's own service.
	 *
	 * @var string
	 */
	protected const string _FONTS_URL = 'https://fonts.bunny.net/css';

	/**
	 * What a chosen font falls back to.
	 *
	 * The same stack `assets/src/scss/frontend-chrome.scss` sets on a code box, so a
	 * font which fails to load leaves a reader exactly where they would have been with
	 * no font chosen at all.
	 *
	 * @var string
	 */
	public const string FONT_STACK = 'Consolas, Monaco, "Andale Mono", "Ubuntu Mono", monospace';

	/**
	 * Method to settle which font is actually loaded for a stored setting value.
	 *
	 * **This falls back the other way from `Themes::resolve_theme()`, deliberately.**
	 * A theme this plugin does not ship falls back to the default theme, because a code box
	 * with no colours at all looks broken. A font this plugin does not offer falls back
	 * to loading nothing: the only thing worse than the wrong font is fetching a file
	 * from another host that nobody asked for.
	 *
	 * @param string $font Font setting value.
	 *
	 * @return string A font slug this plugin offers, or the "no font" value.
	 */
	public static function resolve_font( string $font ): string {
		return ( isset( static::_get_font_titles()[ $font ] ) ) ? $font : static::FONT_NONE;
	}    //end resolve_font()

	/**
	 * Method to get the fonts the plugin offers, keyed by the name Bunny Fonts knows.
	 *
	 * The single declaration of what a font is here. The title is the family's real
	 * name, which is both what the dropdown shows **and** what the CSS asks for, so
	 * there is one string and not two which could disagree.
	 *
	 * Every value below was read out of the font files Bunny actually serves rather
	 * than from a catalogue page, and two of those readings matter:
	 *
	 * - `weight` is the one weight fetched. Bunny drops a weight a family does not
	 *   have without complaining, so this can never fail a request — but a font asked
	 *   for at a weight it does not have would be synthesised by the browser, which is
	 *   why each one is the weight its own family really ships.
	 * - `ligatures` says this is a programming ligature face. Four of the fifteen are.
	 *   It is not simply "the `GSUB` carries a `liga` or `calt` lookup": Azeret Mono
	 *   carries one `liga` lookup and two `calt`, against Victor Mono's 89, Fira Code's
	 *   100, Cascadia Code's 108 and JetBrains Mono's 138, and grouping three lookups
	 *   with a hundred and thirty-eight oversells it to somebody choosing a font to
	 *   read code in. A browser may switch contextual alternates off for a face it
	 *   treats as fixed pitch, so the four which have them ask for them by name; the
	 *   rest say nothing, because a declaration which does nothing reads as though it
	 *   does.
	 *
	 * @return array Font slug to title, weight and whether it carries code ligatures.
	 */
	protected static function _get_font_titles(): array {

		return [
			'azeret-mono'       => [
				'title'     => 'Azeret Mono',
				'weight'    => 300,
				'ligatures' => false,    //its GSUB has one liga lookup and two calt; that is not a programming ligature face
			],
			'cascadia-code'     => [
				'title'     => 'Cascadia Code',
				'weight'    => 300,
				'ligatures' => true,
			],
			'fira-code'         => [
				'title'     => 'Fira Code',
				'weight'    => 400,
				'ligatures' => true,
			],
			'fira-mono'         => [
				'title'     => 'Fira Mono',
				'weight'    => 400,
				'ligatures' => false,
			],
			'google-sans-code'  => [
				'title'     => 'Google Sans Code',
				'weight'    => 400,
				'ligatures' => false,    //the name says otherwise; its GSUB has ccmp, locl and ss01 and nothing else
			],
			'ibm-plex-mono'     => [
				'title'     => 'IBM Plex Mono',
				'weight'    => 400,
				'ligatures' => false,
			],
			'inconsolata'       => [
				'title'     => 'Inconsolata',
				'weight'    => 400,
				'ligatures' => false,
			],
			'jetbrains-mono'    => [
				'title'     => 'JetBrains Mono',
				'weight'    => 400,
				'ligatures' => true,
			],
			'm-plus-code-latin' => [
				'title'     => 'M PLUS Code Latin',
				'weight'    => 400,
				'ligatures' => false,
			],
			'nova-mono'         => [
				'title'     => 'Nova Mono',
				'weight'    => 400,
				'ligatures' => false,
			],
			'roboto-mono'       => [
				'title'     => 'Roboto Mono',
				'weight'    => 400,
				'ligatures' => false,
			],
			'source-code-pro'   => [
				'title'     => 'Source Code Pro',
				'weight'    => 400,
				'ligatures' => false,
			],
			'space-mono'        => [
				'title'     => 'Space Mono',
				'weight'    => 400,
				'ligatures' => false,
			],
			'ubuntu-mono'       => [
				'title'     => 'Ubuntu Mono',
				'weight'    => 400,
				'ligatures' => false,
			],
			'victor-mono'       => [
				'title'     => 'Victor Mono',
				'weight'    => 400,
				'ligatures' => true,
			],
		];

	}    //end _get_font_titles()

	/**
	 * Method to get the fonts the plugin offers.
	 *
	 * The counterpart of `get_themes()`, without its readability check: a theme is a
	 * file on disk which an upgrade can lose, and a font is a name in the map above.
	 *
	 * @return array Font slug to human readable title.
	 */
	public static function get_fonts(): array {

		return array_map(
			static fn ( array $font ): string => $font['title'],
			static::_get_font_titles()
		);

	}    //end get_fonts()

	/**
	 * Method to ask whether a font is a programming ligature face.
	 *
	 * The one reader outside this class is the settings screen, which groups the
	 * dropdown by it — see `Admin::get_font_groups()`. It is asked here rather than
	 * read off the map, because what "has ligatures" means is this class's decision
	 * and the map is protected precisely so that it stays one.
	 *
	 * @param string $slug Font slug.
	 *
	 * @return bool FALSE for a font this plugin does not offer, which has no ligatures either.
	 */
	public static function has_ligatures( string $slug ): bool {
		return (bool) ( static::_get_font_titles()[ $slug ]['ligatures'] ?? false );
	}    //end has_ligatures()

	/**
	 * Method to get the stylesheet URL for a font.
	 *
	 * Built by hand rather than with `add_query_arg()`, which would encode the colon
	 * the family and its weight are joined with. Nothing here needs escaping: the slug
	 * is a key of the map above and the weight is an integer from it, so a caller
	 * cannot get a string of its own into this URL.
	 *
	 * @param string $slug Font slug.
	 *
	 * @return string URL, or an empty string where no font is to be loaded.
	 */
	public static function get_font_url( string $slug ): string {

		$fonts = static::_get_font_titles();

		if ( ! isset( $fonts[ $slug ] ) ) {
			return '';
		}

		/*
		 * `display=swap` so that a reader is shown the code in the fallback font while
		 * the webfont is still on its way, rather than being shown nothing at all.
		 */
		return sprintf(
			'%s?family=%s:%d&display=swap',
			static::_FONTS_URL,
			$slug,
			$fonts[ $slug ]['weight']
		);

	}    //end get_font_url()

	/**
	 * Method to get the CSS which puts a font on the code boxes.
	 *
	 * **Values and never a rule.** The selectors and the fallbacks live in
	 * `frontend-chrome.scss`, which reads these custom properties; all that is not
	 * known until a site owner has picked a font is what the values are. Keeping it
	 * that way means the cascade is legible where a reader of CSS would look for it,
	 * and adding a font is still an edit to `_get_font_titles()` and nothing else.
	 *
	 * The properties are set on `:root` because they are read on the code element and
	 * on every token span inside it, and a custom property is inherited.
	 *
	 * @param string $slug Font slug.
	 *
	 * @return string CSS, or an empty string where no font is to be loaded.
	 */
	public static function get_font_css( string $slug ): string {

		$declarations = static::_get_font_declarations( $slug );

		if ( empty( $declarations ) ) {
			return '';
		}

		return sprintf( ':root { %s }', $declarations );

	}    //end get_font_css()

	/**
	 * Method to get the custom property values which describe a font.
	 *
	 * The family, and for a family which really has the lookups for them, the
	 * ligatures — plus the one thing that has to go with ligatures and would look
	 * arbitrary anywhere else:
	 *
	 * **A non-zero `letter-spacing` suppresses ligatures outright.** That is specified
	 * behaviour and not a quirk, the property is inherited, and a theme setting it on
	 * its article text — `letter-spacing: 0.013rem` on `.entry-content` is a real
	 * example — reaches inside the code box and silently switches off the ligatures a
	 * site owner chose the font for. So a ligature font zeroes it and nothing else
	 * does: a site running one of the other eleven, or no font at all, keeps whatever
	 * its theme asks for.
	 *
	 * @param string $slug Font slug.
	 *
	 * @return string Declarations, or an empty string for a font this plugin does not offer.
	 */
	protected static function _get_font_declarations( string $slug ): string {

		$fonts = static::_get_font_titles();

		if ( ! isset( $fonts[ $slug ] ) ) {
			return '';
		}

		$declarations = sprintf(
			'--igsh-code-font: "%s", %s;',
			$fonts[ $slug ]['title'],
			static::FONT_STACK
		);

		if ( ! empty( $fonts[ $slug ]['ligatures'] ) ) {
			$declarations .= ' --igsh-code-ligatures: common-ligatures contextual; --igsh-code-letter-spacing: 0;';
		}

		return $declarations;

	}    //end _get_font_declarations()

	/**
	 * Method to get the CSS which puts a font on the block being edited.
	 *
	 * **Custom properties rather than the properties themselves, deliberately.**
	 * `editor.scss` already sets a font on that textarea, at the same specificity this
	 * rule can reach, and inside the editor's iframe this rule is enqueued *before* the
	 * block's own stylesheet — core fires `enqueue_block_assets` first and enqueues the
	 * blocks' editor styles second. Setting the same property would therefore lose.
	 * Nothing else declares that variable, so there is no cascade to win.
	 *
	 * The block wrapper is the whole of the selector, so nothing else a site owner is
	 * editing can be reached by it.
	 *
	 * **The family and nothing else. Ligatures are never asked for here**, and
	 * `editor.scss` switches them off outright: the block is edited in a textarea,
	 * which is where somebody counts characters and puts a caret between them, and a
	 * caret cannot sit inside one glyph standing for two. Typing `__construct` and
	 * reading back what looks like ` _construct` is alarming enough to make an author
	 * correct code which was never wrong. The rendered box and the preview keep their
	 * ligatures, because nobody edits those.
	 *
	 * @param string $slug Font slug.
	 *
	 * @return string CSS, or an empty string where no font is to be loaded.
	 */
	public static function get_editor_font_css( string $slug ): string {

		$fonts = static::_get_font_titles();

		if ( ! isset( $fonts[ $slug ] ) ) {
			return '';
		}

		return sprintf(
			'.wp-block-igsyntax-hiliter-code { --igsh-editor-font: "%1$s", %2$s; }',
			$fonts[ $slug ]['title'],
			static::FONT_STACK
		);

	}    //end get_editor_font_css()

}    //end of class

//EOF
