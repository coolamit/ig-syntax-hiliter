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
 * Between the two passes the content carries only inert placeholders, so no filter
 * and no KSES sees a byte of code. Display puts rendered boxes back; save puts the
 * original bytes back. `serialize_block_attributes()` escapes neither `[` nor `]`,
 * so shortcodes are matched only outside block delimiters, and on the save path
 * this plugin's own delimiters are lifted out whole. Restoration touches only keys
 * in the request's stash, so a second pass over the same content is a no-op.
 */
class Content_Protector {

	use Singleton;

	/**
	 * Marker which identifies a placeholder as this plugin's.
	 *
	 * @var string
	 */
	public const string PLACEHOLDER_PREFIX = 'igshx';

	/**
	 * Characters `WP_Block_Parser` accepts as whitespace inside a block delimiter.
	 *
	 * @var string
	 */
	protected const string _DELIMITER_WHITESPACE = " \t\n\r\f\v";

	/**
	 * Characters a block name is built from.
	 *
	 * @var string
	 */
	protected const string _BLOCK_NAME_CHARS = 'abcdefghijklmnopqrstuvwxyz0123456789_-/';

	/**
	 * Stashed snippets, keyed by placeholder key.
	 *
	 * @var array
	 */
	protected array $_stash = [];

	/**
	 * Per request salt mixed into every placeholder key, so authored text shaped
	 * like a placeholder can never collide with a real one.
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
	 * Block delimiters are left alone: `do_blocks()` at priority 9 still needs them.
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

	}

	/**
	 * Method to replace every snippet in the content with a placeholder, on its way
	 * to the database.
	 *
	 * This plugin's block delimiters go too, whole, because KSES reaches block
	 * attributes via `wp_pre_kses_block_attributes()`. Only this plugin's own:
	 * shielding another plugin's block would be a way around KSES. Shortcodes go first:
	 * a snippet whose code is a block delimiter would otherwise be stashed with a
	 * placeholder already inside it, and restoration is a single pass.
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

	}

	/**
	 * Method to remove every snippet from the content.
	 *
	 * Nothing is stashed, so this is only ever used on display content. An escaped
	 * shortcode goes with the rest: unwrapping is not open to a pass with no restore
	 * stage behind it. The excerpt body is stripped at priority 0 and protected at
	 * priority 1, so a `[php]…[/php]` left here would be a snippet to the next pass.
	 *
	 * @param string $content Content to strip.
	 *
	 * @return string
	 */
	public function strip( string $content ): string {

		return $this->_replace_shortcodes(
			$content,
			function (): string {
				return '';
			}
		);

	}

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
		$content = $this->_replace(
			sprintf( '#<p>\s*%s\s*</p>#', $this->_get_placeholder_pattern() ),
			function ( array $matches ) use ( $restore ): string {

				$entry = $this->_stash[ $matches[1] ] ?? null;

				if ( is_null( $entry ) || true !== $entry['isolated'] ) {
					return $matches[0];
				}

				return $restore( $matches );

			},
			$content
		);

		return $this->_replace(
			sprintf( '#%s#', $this->_get_placeholder_pattern() ),
			$restore,
			$content
		);

	}

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

		return $this->_replace(
			sprintf( '#%s#', $this->_get_placeholder_pattern() ),
			function ( array $matches ): string {

				$entry = $this->_stash[ $matches[1] ] ?? null;

				if ( is_null( $entry ) ) {
					return $matches[0];
				}

				return $entry['raw'];

			},
			$content
		);

	}

	/**
	 * Method to check whether a protected run is in flight.
	 *
	 * A block render callback inside `do_blocks()` can ask this and hand its markup to
	 * `stash_markup()`. The filter the run began in must still be running, or a run
	 * whose restore pass never happened would say yes for the rest of the request.
	 *
	 * @return bool
	 */
	public function is_protecting(): bool {

		$run = $this->_get_current_run();

		if ( is_null( $run ) ) {
			return false;
		}

		return ( empty( $run['filter'] ) || doing_filter( $run['filter'] ) );

	}

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

	}

	/**
	 * Method to build the placeholder for a key.
	 *
	 * Not an HTML comment: a `-->` would close any comment it lands in, a block
	 * delimiter included. It carries nothing `wptexturize`, `wpautop` or KSES acts on.
	 *
	 * @param string $key Placeholder key.
	 *
	 * @return string
	 */
	public function get_placeholder( string $key ): string {
		return sprintf( '{%s%s}', static::PLACEHOLDER_PREFIX, $key );
	}

	/**
	 * Method to run a pattern over content, handing the content back untouched when
	 * PCRE gives up on it.
	 *
	 * `preg_replace_callback()` returns NULL on a PCRE limit and `(string) NULL` is
	 * empty; on a save filter that stores an empty post, so failure must mean
	 * "changed nothing".
	 *
	 * @param string   $pattern  Pattern with delimiters.
	 * @param callable $callback Replacement callback.
	 * @param string   $content  Content to work over.
	 * @param int      $flags    Flags for `preg_replace_callback()`.
	 *
	 * @return string
	 */
	protected function _replace( string $pattern, callable $callback, string $content, int $flags = 0 ): string {

		$count  = 0;
		$result = preg_replace_callback( $pattern, $callback, $content, -1, $count, $flags );

		if ( ! is_string( $result ) || PREG_NO_ERROR !== preg_last_error() ) {
			return $content;
		}

		return $result;

	}

	/**
	 * Method to run a callback over every shortcode outside a block delimiter.
	 *
	 * Walked one at a time because a callback can decline a match but cannot
	 * un-consume it, and a match beginning inside a delimiter can span real
	 * shortcodes. A match beginning outside one is protected whole even where it
	 * runs across one: that is a snippet whose code is block markup.
	 *
	 * @param string   $content  Content to work over.
	 * @param callable $callback Callback handed one flattened match, returning what stands in
	 *                           for it.
	 *
	 * @return string
	 */
	protected function _replace_shortcodes( string $content, callable $callback ): string {

		$pattern = $this->_get_shortcode_pattern();

		if ( empty( $pattern ) || ! str_contains( $content, '[' ) ) {
			return $content;
		}

		$ranges    = $this->_get_delimiter_ranges( $content );
		$length    = strlen( $content );
		$protected = '';
		$copied    = 0;
		$offset    = 0;

		while ( $offset <= $length && 1 === preg_match( $pattern, $content, $matches, PREG_OFFSET_CAPTURE, $offset ) ) {

			$start     = (int) $matches[0][1];
			$raw       = (string) $matches[0][0];
			$delimiter = $this->_get_delimiter_end( $start, $ranges );

			if ( ! is_null( $delimiter ) ) {

				$offset = $delimiter;

				continue;    // the block's data, not content

			}

			$protected .= substr( $content, $copied, $start - $copied ) . (string) $callback(
				array_map(
					function ( array $capture ): string {
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

	}

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

			$delimiter = $this->_read_delimiter( $content, $open );

			if ( is_null( $delimiter ) ) {

				$search = $open + 4;

				continue;    // some other comment

			}

			$search = $delimiter['end'];

			if ( Block::NAME !== $delimiter['name'] ) {
				continue;    // some other plugin's block
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

	}

	/**
	 * Method to find where every block delimiter in the content begins and ends.
	 *
	 * @param string $content Content to scan.
	 *
	 * @return array List of `[ start, end ]` byte offsets, end exclusive.
	 */
	protected function _get_delimiter_ranges( string $content ): array {

		$ranges = [];
		$search = 0;

		while ( true ) {

			$open = strpos( $content, '<!--', $search );

			if ( false === $open ) {
				break;
			}

			$delimiter = $this->_read_delimiter( $content, $open );

			if ( is_null( $delimiter ) ) {

				$search = $open + 4;

				continue;

			}

			$ranges[] = [ $open, $delimiter['end'] ];
			$search   = $delimiter['end'];

		}

		return $ranges;

	}

	/**
	 * Method to read one block delimiter.
	 *
	 * Read by scanning, not by a tempered pattern, which backtracks catastrophically
	 * past a few tens of kilobytes of attributes — a size a snippet reaches.
	 * `serialize_block_attributes()` escapes `--`, so no `-->` occurs inside the
	 * attributes; a hand written one is stepped over to the next `-->`.
	 *
	 * @param string $content Content being read.
	 * @param int    $offset  Offset of the `<!--` which opens the comment.
	 *
	 * @return array|null Two keys, `name` and `end`, or NULL when this comment is not a
	 *                    block delimiter.
	 */
	protected function _read_delimiter( string $content, int $offset ): ?array {

		$length = strlen( $content );
		$cursor = $offset + 4;
		$gap    = strspn( $content, static::_DELIMITER_WHITESPACE, $cursor );

		if ( 1 > $gap ) {
			return null;
		}

		$cursor += $gap;

		if ( '/' === substr( $content, $cursor, 1 ) ) {
			++$cursor;    // a closing delimiter
		}

		if ( $length < ( $cursor + 3 ) || 0 !== substr_compare( $content, 'wp:', $cursor, 3 ) ) {
			return null;
		}

		$cursor += 3;
		$name    = strspn( $content, static::_BLOCK_NAME_CHARS, $cursor );

		if ( 1 > $name ) {
			return null;
		}

		$block_name = substr( $content, $cursor, $name );
		$cursor    += $name;
		$gap        = strspn( $content, static::_DELIMITER_WHITESPACE, $cursor );

		if ( 1 > $gap ) {
			return null;
		}

		$start = $cursor + $gap;    // the `{` opening the attributes, or the end of an attribute free delimiter

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
				return null;    // the comment is never closed
			}

			$end  = ( '/' === $content[ $close - 1 ] ) ? $close - 1 : $close;
			$tail = $end;

			while ( $end > $start && false !== strpos( static::_DELIMITER_WHITESPACE, $content[ $end - 1 ] ) ) {
				--$end;
			}

			// The block grammar puts whitespace between the attributes and the end of the
			// delimiter.
			if ( $tail === $end || '}' !== $content[ $end - 1 ] ) {
				continue;
			}

			return [
				'name' => $block_name,
				'end'  => $close + 3,
			];

		}

	}

	/**
	 * Method to find where the delimiter holding an offset ends.
	 *
	 * @param int   $offset Byte offset to place.
	 * @param array $ranges Ranges from `_get_delimiter_ranges()`.
	 *
	 * @return int|null End of the delimiter holding the offset, or NULL when it is in none of them.
	 */
	protected function _get_delimiter_end( int $offset, array $ranges ): ?int {

		foreach ( $ranges as $range ) {

			if ( $offset >= $range[0] && $offset < $range[1] ) {
				return $range[1];
			}
		}

		return null;

	}

	/**
	 * Method to handle one matched shortcode.
	 *
	 * An escaped `[[php]…[/php]]` is an author showing the tags as text. On the
	 * display path the outer brackets come off and the text is stashed rather than
	 * written back, because the content is filtered for another 99 priorities. On
	 * the save path it is handed back exactly as written.
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

	}

	/**
	 * Method to stash one entry and get the placeholder standing in for it.
	 *
	 * Isolation is recorded on the entry so the restore pass can tell a paragraph
	 * `wpautop` built from one the author wrote.
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

		$placeholder = $this->get_placeholder( $key );

		if ( $isolate ) {
			$placeholder = sprintf( "\n\n%s\n\n", $placeholder );
		}

		return $placeholder;

	}

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

		// `'' ===` and not `empty()`: `0` is code.
		if ( '' === trim( $entry['code'] ) ) {
			return '';
		}

		return Renderer::get_instance()->render_snippet(
			Shortcode_Handler::get_instance()->build_snippet( $entry['tag'], $entry['atts'], $entry['code'] )
		);

	}

	/**
	 * Method to note that a protected run has finished.
	 *
	 * A run begins in one callback and ends in another, so no `finally` can close the
	 * pair; `is_protecting()` is built so a run left in flight is harmless.
	 *
	 * @return void
	 */
	protected function _end_run(): void {
		array_pop( $this->_runs );
	}

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

	}

	/**
	 * Method to get the innermost run in flight.
	 *
	 * @return array|null
	 */
	protected function _get_current_run(): ?array {

		$key = array_key_last( $this->_runs );

		return ( is_null( $key ) ) ? null : $this->_runs[ $key ];

	}

	/**
	 * Method to check whether the run in flight separates placeholders into their own
	 * paragraphs.
	 *
	 * @return bool
	 */
	protected function _is_isolating(): bool {

		$run = $this->_get_current_run();

		return ( ! is_null( $run ) && true === $run['isolate'] );

	}

	/**
	 * Method to check whether the content can hold one of this plugin's placeholders.
	 *
	 * @param string $content Content to check.
	 *
	 * @return bool
	 */
	protected function _has_placeholder( string $content ): bool {
		return ( ! empty( $this->_stash ) && str_contains( $content, static::PLACEHOLDER_PREFIX ) );
	}

	/**
	 * Method to get the per request salt.
	 *
	 * @return string
	 */
	protected function _get_salt(): string {

		if ( empty( $this->_salt ) ) {
			$this->_salt = wp_generate_password( 32, false, false );
		}

		return $this->_salt;

	}

	/**
	 * Method to get the pattern which matches this plugin's shortcodes.
	 *
	 * WordPress builds the pattern, plus the one addition
	 * `Helper::get_shortcode_pattern()` makes for an escaped closing tag.
	 *
	 * @return string Pattern with delimiters, or an empty string when the plugin claims no tags.
	 */
	protected function _get_shortcode_pattern(): string {

		$tags = Legacy_Map::get_instance()->get_tags();

		if ( $tags === $this->_tags && ! empty( $this->_pattern ) ) {
			return $this->_pattern;
		}

		$this->_tags    = $tags;
		$this->_pattern = ( empty( $tags ) ) ? '' : sprintf( '/%s/', Helper::get_shortcode_pattern( $tags ) );

		return $this->_pattern;

	}

	/**
	 * Method to get the pattern which matches a placeholder, without delimiters.
	 *
	 * @return string
	 */
	protected function _get_placeholder_pattern(): string {
		return sprintf( '\{%s([0-9a-f]{32})\}', preg_quote( static::PLACEHOLDER_PREFIX, '#' ) );
	}

} // end of class

// EOF
