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
 * Between the two passes the content carries nothing but inert placeholders, so
 * core filters, third party filters and KSES never see a byte of code. The display
 * path puts rendered code boxes back; the save path puts the original bytes back,
 * byte for byte.
 *
 * Block delimiters are treated apart from the content around them. A delimiter
 * carries its block's attributes as JSON, and `serialize_block_attributes()`
 * escapes `<`, `>`, `&` and every `--` in there but neither `[` nor `]` — so a
 * shortcode written inside a block attribute is matchable, and whatever is put in
 * its place ends up inside an HTML comment. Shortcodes are therefore matched only
 * outside delimiters, on every path; and on the save path this plugin's own
 * delimiters are lifted out whole, which is what keeps KSES away from a block's
 * code.
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
	const string PLACEHOLDER_PREFIX = 'igshx';

	/**
	 * Characters `WP_Block_Parser` accepts as whitespace inside a block delimiter.
	 *
	 * @var string
	 */
	const string DELIMITER_WHITESPACE = " \t\n\r\f\v";

	/**
	 * Characters a block name is built from.
	 *
	 * @var string
	 */
	const string BLOCK_NAME_CHARS = 'abcdefghijklmnopqrstuvwxyz0123456789_-/';

	/**
	 * Stashed snippets, keyed by placeholder key.
	 *
	 * @var array
	 */
	protected array $_stash = [];

	/**
	 * Per request salt mixed into every placeholder key.
	 *
	 * Content which happens to contain a placeholder shaped token can therefore
	 * never collide with a real one, so authored text is never rewritten.
	 *
	 * @var string
	 */
	protected string $_salt = '';

	/**
	 * Runs currently in flight, innermost last.
	 *
	 * Each entry records the filter the run was started inside and whether that run
	 * isolates its placeholders.
	 *
	 * @var array
	 */
	protected array $_runs = [];

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
	 * Method to replace every snippet in the content with a placeholder, on its way
	 * to the browser.
	 *
	 * Block delimiters are left exactly as they are: `do_blocks()` runs at
	 * `the_content` priority 9 and has to be handed the delimiters it is looking for.
	 *
	 * @param string $content Content to protect.
	 *
	 * @return string
	 */
	public function protect_for_display( string $content ): string {

		$this->_begin_run( true );

		return $this->_replace_shortcodes(
			$content,
			function ( array $matches ): string {
				return $this->_protect_match( $matches, true );
			}
		);

	}    //end protect_for_display()

	/**
	 * Method to replace every snippet in the content with a placeholder, on its way
	 * to the database.
	 *
	 * This plugin's block delimiters go too, whole. KSES reaches block attributes —
	 * `wp_filter_post_kses()` → `pre_kses` → `wp_pre_kses_block_attributes()` →
	 * `filter_block_content()` runs `wp_kses()` over every string attribute of every
	 * block and re-serialises what comes back — so a delimiter left in the content is
	 * a code body handed to KSES.
	 *
	 * Only this plugin's own delimiters are held back. Another plugin's block is
	 * another plugin's business, and shielding it from KSES would be handing an
	 * author without `unfiltered_html` a way around it.
	 *
	 * Shortcodes go first. A snippet whose code is a block delimiter — this plugin's
	 * own documentation, for one — would otherwise be stashed with a placeholder
	 * already inside it, and restoration is a single pass which never looks at what it
	 * has just put back.
	 *
	 * @param string $content Content to protect.
	 *
	 * @return string
	 */
	public function protect_for_save( string $content ): string {

		$this->_begin_run( false );

		$content = $this->_replace_shortcodes(
			$content,
			function ( array $matches ): string {
				return $this->_protect_match( $matches, false );
			}
		);

		return $this->_protect_blocks( $content );

	}    //end protect_for_save()

	/**
	 * Method to remove every snippet from the content.
	 *
	 * Used where a code box makes no sense, such as an excerpt. Nothing is stashed
	 * and nothing is restored, so this is only ever used on content being displayed.
	 *
	 * An escaped shortcode goes with the rest, neither unwrapped nor left as it was
	 * written. Unwrapping is not open to a pass which has no restore stage behind it:
	 * the body an automatic excerpt is built from is stripped at `the_content`
	 * priority 0 and protected at priority 1, so a `[php]…[/php]` this pass left in
	 * the content is a snippet to the next one, and a summary would be handed a
	 * rendered code box for `wp_trim_words()` to take the markup back off and print
	 * the code as prose. Leaving it as written carries the same code into the summary
	 * anyway, where a `<` which opens no element takes everything after it away with
	 * `strip_tags()`. What is between the brackets is source code either way, and a
	 * summary is no place for it.
	 *
	 * That is also what makes the hazard above impossible rather than merely avoided:
	 * once this pass has run, the body carries no form of this plugin's shortcodes at
	 * all, so there is nothing left for the protect pass behind it to match.
	 *
	 * @param string $content Content to strip.
	 *
	 * @return string
	 */
	public function strip( string $content ): string {

		return $this->_replace_shortcodes(
			$content,
			static function (): string {
				return '';
			}
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
		 *
		 * A placeholder which was never isolated stands in for text rather than for a
		 * code box, so the paragraph around it is one the author wrote and it is left
		 * where it is.
		 */
		$content = static::_replace(
			sprintf( '#<p>\s*%s\s*</p>#', static::_get_placeholder_pattern() ),
			function ( array $matches ) use ( $restore ): string {

				$entry = $this->_stash[ $matches[1] ] ?? null;

				if ( is_null( $entry ) || true !== $entry['isolated'] ) {
					return $matches[0];
				}

				return $restore( $matches );

			},
			$content
		);

		return static::_replace(
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

		return static::_replace(
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
	 * The filter the run was begun in has to still be running for the answer to be
	 * yes. A run whose restore pass never happened — a chain torn down mid flight, a
	 * callback which threw — would otherwise leave this saying yes for the rest of
	 * the request, and every later caller would be handed a placeholder that nothing
	 * is ever going to restore.
	 *
	 * @return bool
	 */
	public function is_protecting(): bool {

		$run = $this->_get_current_run();

		if ( is_null( $run ) ) {
			return false;
		}

		return ( '' === $run['filter'] || doing_filter( $run['filter'] ) );

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
	 * Deliberately not an HTML comment. A placeholder carrying `-->` closes any
	 * comment it lands inside, which is what a block delimiter and an authored
	 * comment both are. It has to survive `wptexturize`, `wpautop` and `wp_kses()`
	 * untouched, so it carries nothing any of them acts on.
	 *
	 * @param string $key Placeholder key.
	 *
	 * @return string
	 */
	public static function get_placeholder( string $key ): string {
		return sprintf( '{%s%s}', static::PLACEHOLDER_PREFIX, $key );
	}    //end get_placeholder()

	/**
	 * Method to run a pattern over content, handing the content back untouched when
	 * PCRE gives up on it.
	 *
	 * `preg_replace_callback()` returns NULL when it hits a backtrack, recursion or
	 * JIT stack limit, and `(string) NULL` is the empty string. On a save filter that
	 * stores an empty post, so failure has to mean "changed nothing" and never
	 * "matched everything". `preg_last_error()` says which limit was hit; there is
	 * nothing different to do about any of them, so only the failure itself is read.
	 *
	 * @param string   $pattern  Pattern with delimiters.
	 * @param callable $callback Replacement callback.
	 * @param string   $content  Content to work over.
	 * @param int      $flags    Flags for `preg_replace_callback()`.
	 *
	 * @return string
	 */
	protected static function _replace( string $pattern, callable $callback, string $content, int $flags = 0 ): string {

		$count  = 0;
		$result = preg_replace_callback( $pattern, $callback, $content, -1, $count, $flags );

		if ( ! is_string( $result ) || PREG_NO_ERROR !== preg_last_error() ) {
			return $content;
		}

		return $result;

	}    //end _replace()

	/**
	 * Method to run a callback over every shortcode outside a block delimiter.
	 *
	 * The matches are walked one at a time rather than handed to
	 * `preg_replace_callback()`, because where matching resumes has to be decided
	 * here. A callback can decline a match but cannot un-consume it, and a match
	 * beginning inside a delimiter can reach a long way past the end of it — so a
	 * declined match takes every shortcode it happens to span with it, and they are
	 * never offered to the matcher at all.
	 *
	 * A match which begins outside a delimiter is protected whole even where it runs
	 * across one, because that is a snippet whose code is block markup and the outer
	 * construct is the one the author wrote.
	 *
	 * @param string   $content  Content to work over.
	 * @param callable $callback Callback handed one flattened match, returning what stands in for it.
	 *
	 * @return string
	 */
	protected function _replace_shortcodes( string $content, callable $callback ): string {

		$pattern = $this->_get_shortcode_pattern();

		if ( '' === $pattern || ! str_contains( $content, '[' ) ) {
			return $content;
		}

		$ranges    = static::_get_delimiter_ranges( $content );
		$length    = strlen( $content );
		$protected = '';
		$copied    = 0;
		$offset    = 0;

		while ( $offset <= $length && 1 === preg_match( $pattern, $content, $matches, PREG_OFFSET_CAPTURE, $offset ) ) {

			$start     = (int) $matches[0][1];
			$raw       = (string) $matches[0][0];
			$delimiter = static::_get_delimiter_end( $start, $ranges );

			if ( ! is_null( $delimiter ) ) {

				$offset = $delimiter;

				continue;    //the block's data, not content

			}

			$protected .= substr( $content, $copied, $start - $copied ) . (string) $callback(
				array_map(
					static function ( array $capture ): string {
						return (string) $capture[0];
					},
					$matches
				)
			);

			$copied = $start + strlen( $raw );
			$offset = $copied;

		}

		if ( PREG_NO_ERROR !== preg_last_error() ) {
			return $content;
		}

		return ( 0 === $copied ) ? $content : $protected . substr( $content, $copied );

	}    //end _replace_shortcodes()

	/**
	 * Method to replace every one of this plugin's block delimiters with a placeholder.
	 *
	 * @param string $content Content to protect.
	 *
	 * @return string
	 */
	protected function _protect_blocks( string $content ): string {

		if ( ! str_contains( $content, Block::NAME ) ) {
			return $content;
		}

		$protected = '';
		$copied    = 0;
		$search    = 0;

		while ( true ) {

			$open = strpos( $content, '<!--', $search );

			if ( false === $open ) {
				break;
			}

			$delimiter = static::_read_delimiter( $content, $open );

			if ( is_null( $delimiter ) ) {

				$search = $open + 4;

				continue;    //some other comment

			}

			$search = $delimiter['end'];

			if ( Block::NAME !== $delimiter['name'] ) {
				continue;    //some other plugin's block, and its own business
			}

			$raw = substr( $content, $open, $delimiter['end'] - $open );

			$protected .= substr( $content, $copied, $open - $copied ) . $this->_stash_entry(
				$raw,
				[
					'raw'  => $raw,
					'tag'  => '',
					'atts' => '',
					'code' => '',
					'html' => $raw,
				]
			);

			$copied = $delimiter['end'];

		}

		return ( 0 === $copied ) ? $content : $protected . substr( $content, $copied );

	}    //end _protect_blocks()

	/**
	 * Method to find where every block delimiter in the content begins and ends.
	 *
	 * @param string $content Content to scan.
	 *
	 * @return array List of `[ start, end ]` byte offsets, end exclusive.
	 */
	protected static function _get_delimiter_ranges( string $content ): array {

		$ranges = [];
		$search = 0;

		while ( true ) {

			$open = strpos( $content, '<!--', $search );

			if ( false === $open ) {
				break;
			}

			$delimiter = static::_read_delimiter( $content, $open );

			if ( is_null( $delimiter ) ) {

				$search = $open + 4;

				continue;

			}

			$ranges[] = [ $open, $delimiter['end'] ];
			$search   = $delimiter['end'];

		}

		return $ranges;

	}    //end _get_delimiter_ranges()

	/**
	 * Method to read one block delimiter.
	 *
	 * The delimiter is read by scanning rather than by matching the attribute JSON
	 * with a pattern. The tempered pattern the block grammar is naturally written
	 * with backtracks catastrophically: past a few tens of kilobytes of attributes
	 * PCRE gives up and hands back NULL, and a snippet is exactly the content which
	 * runs to that size. A scan has no such ceiling.
	 *
	 * Scanning for the closing `-->` is sound because `serialize_block_attributes()`
	 * escapes `--`, `<` and `>` before the attributes are written, so no `-->` can
	 * occur inside them. Should a hand written delimiter hold one anyway, the scan
	 * carries on to the next `-->` rather than stopping short.
	 *
	 * @param string $content Content being read.
	 * @param int    $offset  Offset of the `<!--` which opens the comment.
	 *
	 * @return array|null Two keys, `name` and `end`, or NULL when this comment is not a block delimiter.
	 */
	protected static function _read_delimiter( string $content, int $offset ): ?array {

		$length = strlen( $content );
		$cursor = $offset + 4;    //past the `<!--`
		$gap    = strspn( $content, static::DELIMITER_WHITESPACE, $cursor );

		if ( 1 > $gap ) {
			return null;
		}

		$cursor += $gap;

		if ( '/' === substr( $content, $cursor, 1 ) ) {
			++$cursor;    //a closing delimiter
		}

		if ( $length < ( $cursor + 3 ) || 0 !== substr_compare( $content, 'wp:', $cursor, 3 ) ) {
			return null;
		}

		$cursor += 3;
		$name    = strspn( $content, static::BLOCK_NAME_CHARS, $cursor );

		if ( 1 > $name ) {
			return null;
		}

		$block_name = substr( $content, $cursor, $name );
		$cursor    += $name;
		$gap        = strspn( $content, static::DELIMITER_WHITESPACE, $cursor );

		if ( 1 > $gap ) {
			return null;
		}

		$start = $cursor + $gap;    //the `{` which opens the attributes, or the end of an attribute free delimiter

		if ( '-->' === substr( $content, $start, 3 ) ) {

			return [
				'name' => $block_name,
				'end'  => $start + 3,
			];

		}

		if ( '/-->' === substr( $content, $start, 4 ) ) {

			return [
				'name' => $block_name,
				'end'  => $start + 4,
			];

		}

		if ( '{' !== substr( $content, $start, 1 ) ) {
			return null;
		}

		$close = $start;

		while ( true ) {

			$close = strpos( $content, '-->', $close + 1 );

			if ( false === $close ) {
				return null;    //the comment is never closed
			}

			$end  = ( '/' === $content[ $close - 1 ] ) ? $close - 1 : $close;
			$tail = $end;

			while ( $end > $start && false !== strpos( static::DELIMITER_WHITESPACE, $content[ $end - 1 ] ) ) {
				--$end;
			}

			// The block grammar puts whitespace between the attributes and the end of the delimiter.
			if ( $tail === $end || '}' !== $content[ $end - 1 ] ) {
				continue;
			}

			return [
				'name' => $block_name,
				'end'  => $close + 3,
			];

		}

	}    //end _read_delimiter()

	/**
	 * Method to find where the delimiter holding an offset ends.
	 *
	 * @param int   $offset Byte offset to place.
	 * @param array $ranges Ranges from `self::_get_delimiter_ranges()`.
	 *
	 * @return int|null End of the delimiter holding the offset, or NULL when it is in none of them.
	 */
	protected static function _get_delimiter_end( int $offset, array $ranges ): ?int {

		foreach ( $ranges as $range ) {

			if ( $offset >= $range[0] && $offset < $range[1] ) {
				return $range[1];
			}
		}

		return null;

	}    //end _get_delimiter_end()

	/**
	 * Method to handle one matched shortcode.
	 *
	 * An escaped shortcode, `[[php]…[/php]]`, is not a snippet. It is an author showing
	 * this plugin's tags as text, and how it is dealt with depends on where the content
	 * is going.
	 *
	 * On the way to a reader the outer pair of brackets comes off, which is exactly what
	 * `do_shortcode_tag()` and `strip_shortcode_tag()` each do with one. The text that
	 * leaves is stashed rather than written straight back in, because the content goes
	 * on being filtered for another ninety-nine priorities after this — a bare
	 * `[php]…[/php]` sitting in it is a shortcode which any later pass, this plugin's
	 * own included, would match and render.
	 *
	 * On the way to the database it is handed back exactly as it was written. Taking a
	 * bracket off there would take one off per edit, and the edit after that would store
	 * the author's example as a real snippet.
	 *
	 * @param array $matches Match from the shortcode pattern.
	 * @param bool  $unwrap  Whether an escaped shortcode is on its way to a reader.
	 *
	 * @return string
	 */
	protected function _protect_match( array $matches, bool $unwrap ): string {

		if ( '[' === $matches[1] && ']' === ( $matches[6] ?? '' ) ) {

			if ( ! $unwrap ) {
				return $matches[0];
			}

			return $this->_stash_entry(
				$matches[0],
				[
					'raw'  => $matches[0],
					'tag'  => '',
					'atts' => '',
					'code' => '',
					'html' => substr( $matches[0], 1, -1 ),
				],
				false
			);

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
	 * Whether the placeholder was given a paragraph of its own is recorded on the entry,
	 * because the restore pass has to know which paragraph around a placeholder is one
	 * `wpautop` built and which is one the author wrote.
	 *
	 * @param string $identity Value the key is derived from.
	 * @param array  $entry    Entry to stash.
	 * @param bool   $isolate  Whether this entry may have a paragraph of its own.
	 *
	 * @return string
	 */
	protected function _stash_entry( string $identity, array $entry, bool $isolate = true ): string {

		$key     = md5( $this->_get_salt() . $identity );
		$isolate = ( $isolate && $this->_is_isolating() );

		$entry['isolated'] = $isolate;

		$this->_stash[ $key ] = $entry;

		$placeholder = static::get_placeholder( $key );

		if ( $isolate ) {
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
	 * Method to note that a protected run has finished.
	 *
	 * A run is begun in one filter callback and ended in another, so no single frame
	 * spans both and no `finally` can close the pair. `self::is_protecting()` is
	 * therefore built so that a run left in flight is harmless rather than relying on
	 * this always being reached.
	 *
	 * @return void
	 */
	protected function _end_run(): void {
		array_pop( $this->_runs );
	}    //end _end_run()

	/**
	 * Method to note that a protected run has started.
	 *
	 * @param bool $isolate Whether placeholders get a paragraph of their own.
	 *
	 * @return void
	 */
	protected function _begin_run( bool $isolate ): void {

		$this->_runs[] = [
			'filter'  => (string) current_filter(),
			'isolate' => $isolate,
		];

	}    //end _begin_run()

	/**
	 * Method to get the innermost run in flight.
	 *
	 * @return array|null
	 */
	protected function _get_current_run(): ?array {

		$key = array_key_last( $this->_runs );

		return ( is_null( $key ) ) ? null : $this->_runs[ $key ];

	}    //end _get_current_run()

	/**
	 * Method to check whether the run in flight separates placeholders into their own
	 * paragraphs.
	 *
	 * @return bool
	 */
	protected function _is_isolating(): bool {

		$run = $this->_get_current_run();

		return ( ! is_null( $run ) && true === $run['isolate'] );

	}    //end _is_isolating()

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
	 * tags are all treated exactly as `do_shortcode()` treats them, plus the one
	 * addition `Helper::get_shortcode_pattern()` makes for an escaped closing tag.
	 *
	 * @return string Pattern with delimiters, or an empty string when the plugin claims no tags.
	 */
	protected function _get_shortcode_pattern(): string {

		$tags = Legacy_Map::get_tags();

		if ( $tags === $this->_tags && '' !== $this->_pattern ) {
			return $this->_pattern;
		}

		$this->_tags    = $tags;
		$this->_pattern = ( empty( $tags ) ) ? '' : sprintf( '/%s/', Helper::get_shortcode_pattern( $tags ) );

		return $this->_pattern;

	}    //end _get_shortcode_pattern()

	/**
	 * Method to get the pattern which matches a placeholder, without delimiters.
	 *
	 * @return string
	 */
	protected static function _get_placeholder_pattern(): string {
		return sprintf( '\{%s([0-9a-f]{32})\}', preg_quote( static::PLACEHOLDER_PREFIX, '#' ) );
	}    //end _get_placeholder_pattern()

}    //end of class


//EOF
