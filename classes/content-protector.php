<?php
/**
 * Protect then restore engine for snippet code.
 *
 * @package iG_Syntax_Hiliter
 */

namespace iG\Syntax_Hiliter;

use iG\Syntax_Hiliter\Traits\Singleton;

/**
 * Lifts snippets out of content before a filter chain runs and puts them back
 * after it has finished.
 *
 * Between the two passes the content carries nothing but inert HTML comments, so
 * core filters, third party filters and KSES never see a byte of code. The display
 * path puts rendered code boxes back; the save path puts the original bytes back,
 * byte for byte.
 *
 * The stash lives for the length of the request and restoration only ever touches
 * a placeholder whose key is in it, so a second protect/restore pass over the same
 * content — which is what revisions and autosaves do — is a no-op rather than a
 * corruption.
 */
class Content_Protector {

	use Singleton;

	/**
	 * Marker which identifies a placeholder as this plugin's.
	 *
	 * @var string
	 */
	const PLACEHOLDER_PREFIX = 'ig-shx';

	/**
	 * Stashed snippets, keyed by placeholder key.
	 *
	 * @var array
	 */
	protected array $_stash = [];

	/**
	 * Per request salt mixed into every placeholder key.
	 *
	 * Content which happens to contain a placeholder shaped comment can therefore
	 * never collide with a real one, so authored text is never rewritten.
	 *
	 * @var string
	 */
	protected string $_salt = '';

	/**
	 * How many protected runs are currently in flight.
	 *
	 * @var int
	 */
	protected int $_depth = 0;

	/**
	 * Whether the run in flight separates placeholders into their own paragraphs.
	 *
	 * @var bool
	 */
	protected bool $_isolate = false;

	/**
	 * Counter used to keep stashed markup entries distinct.
	 *
	 * @var int
	 */
	protected int $_counter = 0;

	/**
	 * Shortcode tags the cached pattern was built from.
	 *
	 * @var array
	 */
	protected array $_tags = [];

	/**
	 * Cached shortcode pattern.
	 *
	 * @var string
	 */
	protected string $_pattern = '';

	/**
	 * Method to replace every snippet in the content with a placeholder.
	 *
	 * @param string $content Content to protect.
	 * @param bool   $isolate Whether to surround each placeholder with blank lines so that `wpautop` gives it a paragraph of its own.
	 *
	 * @return string
	 */
	public function protect( string $content, bool $isolate = false ): string {

		$this->_begin_run( $isolate );

		$pattern = $this->_get_shortcode_pattern();

		if ( '' === $pattern || ! str_contains( $content, '[' ) ) {
			return $content;
		}

		return (string) preg_replace_callback(
			$pattern,
			function ( array $matches ): string {
				return $this->_protect_match( $matches );
			},
			$content
		);

	}    //end protect()

	/**
	 * Method to remove every snippet from the content.
	 *
	 * Used where a code box makes no sense, such as an excerpt.
	 *
	 * @param string $content Content to strip.
	 *
	 * @return string
	 */
	public function strip( string $content ): string {

		$pattern = $this->_get_shortcode_pattern();

		if ( '' === $pattern || ! str_contains( $content, '[' ) ) {
			return $content;
		}

		return (string) preg_replace_callback(
			$pattern,
			static function ( array $matches ): string {

				if ( '[' === $matches[1] && ']' === ( $matches[6] ?? '' ) ) {
					return $matches[0];
				}

				return '';

			},
			$content
		);

	}    //end strip()

	/**
	 * Method to replace every placeholder with its rendered code box.
	 *
	 * @param string $content Content to restore.
	 *
	 * @return string
	 */
	public function restore_rendered( string $content ): string {

		$this->_end_run();

		if ( ! $this->_has_placeholder( $content ) ) {
			return $content;
		}

		$restore = function ( array $matches ): string {

			$entry = $this->_stash[ $matches[1] ] ?? null;

			if ( is_null( $entry ) ) {
				return $matches[0];
			}

			return $this->_render_entry( $entry );

		};

		/*
		 * `wpautop` gives an isolated placeholder a paragraph of its own. Unwrapping
		 * it here is what keeps a block level code box out of a `<p>`.
		 */
		$content = (string) preg_replace_callback(
			sprintf( '#<p>\s*%s\s*</p>#', static::_get_placeholder_pattern() ),
			$restore,
			$content
		);

		return (string) preg_replace_callback(
			sprintf( '#%s#', static::_get_placeholder_pattern() ),
			$restore,
			$content
		);

	}    //end restore_rendered()

	/**
	 * Method to replace every placeholder with the bytes it stood in for.
	 *
	 * @param string $content Content to restore.
	 *
	 * @return string
	 */
	public function restore_verbatim( string $content ): string {

		$this->_end_run();

		if ( ! $this->_has_placeholder( $content ) ) {
			return $content;
		}

		return (string) preg_replace_callback(
			sprintf( '#%s#', static::_get_placeholder_pattern() ),
			function ( array $matches ): string {

				$entry = $this->_stash[ $matches[1] ] ?? null;

				if ( is_null( $entry ) ) {
					return $matches[0];
				}

				return $entry['raw'];

			},
			$content
		);

	}    //end restore_verbatim()

	/**
	 * Method to check whether a protected run is in flight.
	 *
	 * A block render callback runs inside `do_blocks()`, which sits at `the_content`
	 * priority 9 — after this class has protected the content and before the filters
	 * it protects against. A callback which asks this and then hands its markup to
	 * `stash_markup()` gets the same immunity a shortcode gets.
	 *
	 * @return bool
	 */
	public function is_protecting(): bool {
		return ( 0 < $this->_depth );
	}    //end is_protecting()

	/**
	 * Method to stash already rendered markup behind a placeholder.
	 *
	 * @param string $markup Markup to hold back until the filter chain has finished.
	 *
	 * @return string Placeholder standing in for the markup.
	 */
	public function stash_markup( string $markup ): string {

		++$this->_counter;

		return $this->_stash_entry(
			sprintf( 'markup:%d:%s', $this->_counter, $markup ),
			[
				'raw'  => $markup,
				'tag'  => '',
				'atts' => '',
				'code' => '',
				'html' => $markup,
			]
		);

	}    //end stash_markup()

	/**
	 * Method to build the placeholder for a key.
	 *
	 * @param string $key Placeholder key.
	 *
	 * @return string
	 */
	public static function get_placeholder( string $key ): string {
		return sprintf( '<!--%s:%s-->', static::PLACEHOLDER_PREFIX, $key );
	}    //end get_placeholder()

	/**
	 * Method to handle one matched shortcode.
	 *
	 * @param array $matches Match from the shortcode pattern.
	 *
	 * @return string
	 */
	protected function _protect_match( array $matches ): string {

		// An escaped shortcode, `[[php]…[/php]]`, is not a snippet. It is left exactly as written.
		if ( '[' === $matches[1] && ']' === ( $matches[6] ?? '' ) ) {
			return $matches[0];
		}

		return $this->_stash_entry(
			$matches[0],
			[
				'raw'  => $matches[0],
				'tag'  => $matches[2],
				'atts' => $matches[3],
				'code' => (string) ( $matches[5] ?? '' ),
				'html' => null,
			]
		);

	}    //end _protect_match()

	/**
	 * Method to stash one entry and get the placeholder standing in for it.
	 *
	 * @param string $identity Value the key is derived from.
	 * @param array  $entry    Entry to stash.
	 *
	 * @return string
	 */
	protected function _stash_entry( string $identity, array $entry ): string {

		$key = md5( $this->_get_salt() . $identity );

		$this->_stash[ $key ] = $entry;

		$placeholder = static::get_placeholder( $key );

		if ( $this->_isolate ) {
			$placeholder = sprintf( "\n\n%s\n\n", $placeholder );
		}

		return $placeholder;

	}    //end _stash_entry()

	/**
	 * Method to turn one stashed entry into markup.
	 *
	 * @param array $entry Stashed entry.
	 *
	 * @return string
	 */
	protected function _render_entry( array $entry ): string {

		if ( ! is_null( $entry['html'] ) ) {
			return $entry['html'];
		}

		if ( '' === trim( $entry['code'] ) ) {
			return '';
		}

		return Renderer::get_instance()->render_snippet(
			Shortcode_Handler::build_snippet( $entry['tag'], $entry['atts'], $entry['code'] )
		);

	}    //end _render_entry()

	/**
	 * Method to note that a protected run has started.
	 *
	 * @param bool $isolate Whether placeholders get a paragraph of their own.
	 *
	 * @return void
	 */
	protected function _begin_run( bool $isolate ): void {

		++$this->_depth;

		$this->_isolate = $isolate;

	}    //end _begin_run()

	/**
	 * Method to note that a protected run has finished.
	 *
	 * @return void
	 */
	protected function _end_run(): void {
		$this->_depth = max( 0, $this->_depth - 1 );
	}    //end _end_run()

	/**
	 * Method to check whether the content can hold one of this plugin's placeholders.
	 *
	 * @param string $content Content to check.
	 *
	 * @return bool
	 */
	protected function _has_placeholder( string $content ): bool {
		return ( ! empty( $this->_stash ) && str_contains( $content, static::PLACEHOLDER_PREFIX ) );
	}    //end _has_placeholder()

	/**
	 * Method to get the per request salt.
	 *
	 * @return string
	 */
	protected function _get_salt(): string {

		if ( '' === $this->_salt ) {
			$this->_salt = wp_generate_password( 32, false, false );
		}

		return $this->_salt;

	}    //end _get_salt()

	/**
	 * Method to get the pattern which matches this plugin's shortcodes.
	 *
	 * WordPress builds the pattern, so escaped, self closing, unclosed and nested
	 * tags are all treated exactly as `do_shortcode()` treats them.
	 *
	 * @return string Pattern with delimiters, or an empty string when the plugin claims no tags.
	 */
	protected function _get_shortcode_pattern(): string {

		$tags = Legacy_Map::get_tags();

		if ( $tags === $this->_tags && '' !== $this->_pattern ) {
			return $this->_pattern;
		}

		$this->_tags    = $tags;
		$this->_pattern = ( empty( $tags ) ) ? '' : sprintf( '/%s/', get_shortcode_regex( $tags ) );

		return $this->_pattern;

	}    //end _get_shortcode_pattern()

	/**
	 * Method to get the pattern which matches a placeholder, without delimiters.
	 *
	 * @return string
	 */
	protected static function _get_placeholder_pattern(): string {
		return sprintf( '<!--%s:([0-9a-f]{32})-->', preg_quote( static::PLACEHOLDER_PREFIX, '#' ) );
	}    //end _get_placeholder_pattern()

}    //end of class


//EOF
