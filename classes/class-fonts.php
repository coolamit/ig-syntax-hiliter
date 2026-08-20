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
 * The single declaration of what a font is, what weight is fetched, and what CSS
 * applies it — front end and block editor alike. Which font a site runs is the
 * settings screen's decision.
 */
class Fonts {

	/**
	 * Font setting value which means "load no webfont at all".
	 *
	 * The default, and the only value which costs a reader nothing: a chosen font is
	 * fetched from another host.
	 *
	 * @var string
	 */
	public const string FONT_NONE = 'none';

	/**
	 * Where the webfont stylesheets are fetched from.
	 *
	 * Bunny Fonts serves the same API shape as Google Fonts and states that it stores
	 * no personal data and no logs, which is why it was chosen.
	 *
	 * @var string
	 */
	protected const string _FONTS_URL = 'https://fonts.bunny.net/css';

	/**
	 * What a chosen font falls back to.
	 *
	 * The same stack `frontend-chrome.scss` sets on a code box, so a font which fails
	 * to load leaves a reader where no font would.
	 *
	 * @var string
	 */
	public const string FONT_STACK = 'Consolas, Monaco, "Andale Mono", "Ubuntu Mono", monospace';

	/**
	 * Method to settle which font is actually loaded for a stored setting value.
	 *
	 * Falls back the other way from `Themes::resolve_theme()`: an unknown font falls
	 * back to loading nothing, because fetching a file from another host that nobody
	 * asked for is worse than the wrong font.
	 *
	 * @param string $font Font setting value.
	 *
	 * @return string A font slug this plugin offers, or the "no font" value.
	 */
	public static function resolve_font( string $font ): string {
		return ( isset( static::_get_font_titles()[ $font ] ) ) ? $font : static::FONT_NONE;
	}

	/**
	 * Method to get the fonts the plugin offers, keyed by the name Bunny Fonts knows.
	 *
	 * The title is the family's real name, used by both the dropdown and the CSS.
	 * `weight` is the one weight fetched, and is a weight the family really ships — a
	 * missing weight is synthesised by the browser. `ligatures` marks a programming
	 * ligature face; those ask for contextual alternates by name, because a browser may
	 * switch them off for a fixed pitch face.
	 *
	 * @return array Font slug to title, weight and whether it carries code ligatures.
	 */
	protected static function _get_font_titles(): array {

		return [
			'azeret-mono'       => [
				'title'     => 'Azeret Mono',
				'weight'    => 300,
				'ligatures' => false,
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
				'ligatures' => false,
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

	}

	/**
	 * Method to get the fonts the plugin offers.
	 *
	 * Without `get_themes()`'s readability check: a font is a name in the map, not a file on disk.
	 *
	 * @return array Font slug to human readable title.
	 */
	public static function get_fonts(): array {

		return array_map(
			static fn ( array $font ): string => $font['title'],
			static::_get_font_titles()
		);

	}

	/**
	 * Method to ask whether a font is a programming ligature face.
	 *
	 * Asked here rather than read off the map, so what "has ligatures" means stays this
	 * class's decision.
	 *
	 * @param string $slug Font slug.
	 *
	 * @return bool FALSE for a font this plugin does not offer, which has no ligatures either.
	 */
	public static function has_ligatures( string $slug ): bool {
		return (bool) ( static::_get_font_titles()[ $slug ]['ligatures'] ?? false );
	}

	/**
	 * Method to get the stylesheet URL for a font.
	 *
	 * Built by hand because `add_query_arg()` would encode the colon joining family and
	 * weight. Nothing needs escaping: the slug is a key of the map and the weight an
	 * integer from it.
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

		// `display=swap`: the fallback font is shown while the webfont is on its way.
		return sprintf(
			'%s?family=%s:%d&display=swap',
			static::_FONTS_URL,
			$slug,
			$fonts[ $slug ]['weight']
		);

	}

	/**
	 * Method to get the CSS which puts a font on the code boxes.
	 *
	 * Values and never a rule — the selectors and fallbacks are in
	 * `frontend-chrome.scss`. Set on `:root` because they are read on the code element
	 * and every token span.
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

	}

	/**
	 * Method to get the custom property values which describe a font.
	 *
	 * A non-zero inherited `letter-spacing` suppresses ligatures outright, and a theme
	 * setting it on `.entry-content` reaches inside the code box — so a ligature font
	 * zeroes it and nothing else does.
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

	}

	/**
	 * Method to get the CSS which puts a font on the block being edited.
	 *
	 * Custom properties rather than the properties themselves: `editor.scss` sets a font
	 * at the same specificity and, inside the editor iframe, this rule is enqueued before
	 * the block's own stylesheet, so setting the same property would lose. Ligatures are
	 * never asked for here — a caret cannot sit inside one glyph standing for two.
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

	}

} // end of class

// EOF
