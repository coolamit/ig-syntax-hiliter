<?php
/**
 * Converts this plugin's blocks back into shortcodes.
 *
 * @package iG_Syntax_Hiliter
 *
 * @author Amit Gupta <https://amitgupta.in/>
 */

namespace iG\Syntax_Hiliter;

use iG\Syntax_Hiliter\Traits\Singleton;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The way out of the block format, in batches, over REST.
 *
 * A snippet held in a block is not portable: with the plugin switched off the
 * block type is gone, a self closing block has no inner content to fall back on,
 * and the snippet renders as nothing at all. The same snippet written as a
 * shortcode is at least still visible in the post, as text its owner can act on.
 * That is the whole reason this exists, and it is why the rewrite has to be
 * trustworthy rather than merely convenient.
 *
 * Both of this plugin's blocks are converted, because both vanish the same way: the
 * code block becomes a `[sourcecode]` shortcode and the Gist block becomes a
 * `[github]` one.
 *
 * So the rewrite is surgical. Only the plugin's own block delimiters are matched and
 * replaced; the post is never parsed into blocks and serialised back, because that
 * round trip is not byte identical and would quietly rewrite content this tool has
 * no business touching. Every byte outside a matched delimiter is left exactly as
 * it was found.
 *
 * Work is handed out a batch at a time, keyed on the last post id seen rather than
 * on an offset. Rows stop matching as they are rewritten, so an offset would slide
 * over posts and skip them; a post id cursor cannot. A batch that never finished
 * costs nothing — running it again picks up from the first post still holding a
 * block.
 */
class Block_Converter {

	use Singleton;

	/**
	 * Name of the code block.
	 *
	 * @var string
	 */
	const BLOCK_NAME = Block::NAME;

	/**
	 * The string a post's content must contain for it to hold a code block.
	 *
	 * @var string
	 */
	const BLOCK_MARKER = '<!-- wp:' . self::BLOCK_NAME;

	/**
	 * Name of the Gist block.
	 *
	 * Converted for the same reason the code block is: with the plugin switched off
	 * the block type is gone and a self closing block has no inner content, so the
	 * Gist renders as nothing and the post no longer says which Gist it meant. That
	 * the Gist block is never created automatically from a `[github]` shortcode is a
	 * decision about the way in, and says nothing about the way out.
	 *
	 * @var string
	 */
	const GIST_BLOCK_NAME = Block::GIST_NAME;

	/**
	 * The string a post's content must contain for it to hold a Gist block.
	 *
	 * @var string
	 */
	const GIST_BLOCK_MARKER = '<!-- wp:' . self::GIST_BLOCK_NAME;

	/**
	 * Shortcode tag every converted code block is written as.
	 *
	 * One output shape, whatever the language, so what comes out is unambiguous.
	 *
	 * @var string
	 */
	const SHORTCODE_TAG = Legacy_Map::GENERIC_TAG;

	/**
	 * Shortcode tag every converted Gist block is written as.
	 *
	 * @var string
	 */
	const GIST_SHORTCODE_TAG = Gist_Embed::TAG;

	/**
	 * Post statuses which are never touched.
	 *
	 * Everything else is: a draft or a scheduled post holds snippets too, and
	 * leaving those behind would let the very failure this tool exists to prevent
	 * happen later on.
	 *
	 * @var array
	 */
	const EXCLUDED_STATUSES = [ 'trash', 'auto-draft' ];

	/**
	 * Number of posts examined per batch.
	 *
	 * @var int
	 */
	const DEFAULT_BATCH_SIZE = 20;

	/**
	 * The characters the block grammar counts as whitespace, which is PCRE's `\s`.
	 *
	 * @var string
	 */
	const DELIMITER_WHITESPACE = " \t\n\r\f\v";

	/**
	 * The characters a language name may carry into a shortcode attribute.
	 *
	 * A plain list rather than a pattern, because membership in it is tested without
	 * PCRE — see `self::_sanitize_language()` for why.
	 *
	 * @var string
	 */
	const LANGUAGE_CHARS = 'abcdefghijklmnopqrstuvwxyz0123456789_+#.-';

	/**
	 * Largest batch the plugin will accept, whatever the filter asks for.
	 *
	 * @var int
	 */
	const MAX_BATCH_SIZE = 200;

	/**
	 * Filter which sets how many posts are examined per batch.
	 *
	 * @var string
	 */
	const FILTER_BATCH_SIZE = 'ig_syntax_hiliter/revert_batch_size';

	/**
	 * Class constructor, which is where this class hooks itself up to WordPress.
	 */
	protected function __construct() {

		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

	}    //end __construct()

	/**
	 * Method to register the converter's REST routes.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {

		register_rest_route(
			Admin::REST_NAMESPACE,
			'/revert',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_status' ],
					'permission_callback' => [ Admin::class, 'rest_permission_check' ],
					'args'                => [],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'process_batch' ],
					'permission_callback' => [ Admin::class, 'rest_permission_check' ],
					'args'                => [
						'cursor' => [
							'type'              => 'integer',
							'required'          => false,
							'default'           => 0,
							'minimum'           => 0,
							'description'       => __( 'Id of the last post the caller has already been given. Zero starts at the beginning.', 'igsyntax-hiliter' ),
							'sanitize_callback' => 'absint',
							'validate_callback' => [ static::class, 'validate_cursor' ],
						],
					],
				],
			]
		);

	}    //end register_rest_routes()

	/**
	 * Method to check that a cursor is a post id, or the start.
	 *
	 * @param mixed $value Cursor as it was sent.
	 *
	 * @return bool
	 */
	public static function validate_cursor( $value ): bool {
		return ( is_numeric( $value ) && 0 <= (int) $value );
	}    //end validate_cursor()

	/**
	 * Method to report how much work there is.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_status(): WP_REST_Response {

		return new WP_REST_Response(
			[
				'total'      => static::count_remaining(),
				'batch_size' => static::get_batch_size(),
			]
		);

	}    //end get_status()

	/**
	 * Method to convert one batch of posts.
	 *
	 * Every post examined lands in exactly one of `converted`, `skipped` and
	 * `failed`, so those three add up to `processed` and can drive a progress meter.
	 * `blocks_left_alone` counts blocks rather than posts and overlaps all three: it
	 * is the only place a block the tool declined to rewrite inside a post which
	 * otherwise converted is reported at all. The two units are never worth adding
	 * together.
	 *
	 * @param \WP_REST_Request $request Request being served.
	 *
	 * @return \WP_REST_Response Post counts `processed`, `converted`, `skipped` and `failed`; `blocks_left_alone`, how many blocks in the batch the tool deliberately declined to rewrite; the `cursor` to carry on from; and `done`.
	 */
	public function process_batch( WP_REST_Request $request ): WP_REST_Response {

		$cursor     = absint( $request['cursor'] );
		$batch_size = static::get_batch_size();
		$rows       = static::_get_batch( $cursor, $batch_size );

		$counts = [
			'processed'         => 0,
			'converted'         => 0,
			'skipped'           => 0,
			'failed'            => 0,
			'blocks_left_alone' => 0,
		];

		foreach ( $rows as $row ) {

			++$counts['processed'];

			$post_id = (int) $row->ID;
			$cursor  = max( $cursor, $post_id );

			$result = static::convert_content( (string) $row->post_content );

			/*
			 * A block the tool chose to leave is left whatever becomes of the rest of
			 * the post, so it is counted here, ahead of the branches below which put
			 * the post in a bucket and skip past the rest of the loop.
			 */
			$counts['blocks_left_alone'] += $result['skipped'];

			if ( 0 < $result['converted'] && ! static::_save_content( $post_id, $result['content'] ) ) {

				++$counts['failed'];

				continue;

			}

			/*
			 * A delimiter the tool could not read is a failure and is reported as one,
			 * even where the rest of the post converted, because the site owner has a
			 * block left that nothing here can turn back into a shortcode. A block the
			 * tool chose to leave alone is not a failure, and neither is a post whose
			 * content held the marker but no delimiter of this plugin's.
			 */
			if ( 0 < $result['failed'] ) {

				++$counts['failed'];

				continue;

			}

			if ( 1 > $result['converted'] ) {

				++$counts['skipped'];

				continue;

			}

			++$counts['converted'];

		}

		return new WP_REST_Response(
			array_merge(
				$counts,
				[
					'cursor' => $cursor,
					'done'   => ( count( $rows ) < $batch_size ),
				]
			)
		);

	}    //end process_batch()

	/**
	 * Method to write a post's rewritten content back.
	 *
	 * @param int    $post_id Post to write to.
	 * @param string $content Content to write.
	 *
	 * @return bool Whether the content was written.
	 */
	protected static function _save_content( int $post_id, string $content ): bool {

		$saved = wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => wp_slash( $content ),
			],
			true
		);

		return ( ! is_wp_error( $saved ) && 0 < (int) $saved );

	}    //end _save_content()

	/**
	 * Method to rewrite every one of this plugin's blocks in a piece of content.
	 *
	 * Both of them: a code block becomes a `[sourcecode]` shortcode and a Gist block
	 * becomes a `[github]` one. Nothing but the delimiters this plugin wrote is
	 * touched: the content is copied across a byte at a time around them, so
	 * everything else arrives on the other side exactly as it went in.
	 *
	 * A delimiter which sits inside one of this plugin's shortcodes is not a block. It
	 * is a snippet whose code is block markup — this plugin's own documentation, for
	 * one — and it is left exactly where it was found. That is what makes running the
	 * tool a second time safe: the shortcode the first run wrote carries the delimiter
	 * from the block's code in its body, and rewriting that would nest a shortcode
	 * inside another one's code and lose the snippet from the closing tag onwards.
	 *
	 * @param string $content Content to rewrite.
	 *
	 * @return array Four keys: `content`, the rewritten content; `converted`, how many blocks became shortcodes; `skipped`, how many were deliberately left alone; and `failed`, how many could not be read.
	 */
	public static function convert_content( string $content ): array {

		$result = [
			'content'   => $content,
			'converted' => 0,
			'skipped'   => 0,
			'failed'    => 0,
		];

		if ( ! str_contains( $content, static::BLOCK_MARKER ) && ! str_contains( $content, static::GIST_BLOCK_MARKER ) ) {
			return $result;
		}

		$delimiters = static::_get_delimiters( $content );
		$shortcodes = static::_get_shortcode_ranges( $content, $delimiters );

		if ( is_null( $shortcodes ) ) {
			return $result;    //where the shortcodes are is unknown, so nothing here can be rewritten safely
		}

		$rewritten = '';
		$copied    = 0;

		foreach ( $delimiters as $delimiter ) {

			$open = $delimiter['open'];

			if ( ! is_null( static::_get_enclosing_end( $open, $shortcodes ) ) ) {
				continue;    //the author's code, which merely reads like a block
			}

			$attributes = static::_decode_attributes( $delimiter['attrs'] );

			if ( is_null( $attributes ) ) {

				++$result['failed'];

				continue;    //this plugin's block, and unreadable, which is not the same as choosing to leave it

			}

			$shortcode = ( static::GIST_BLOCK_NAME === $delimiter['block'] )
				? static::gist_block_to_shortcode( $attributes )
				: static::block_to_shortcode( $attributes );

			if ( is_null( $shortcode ) ) {

				++$result['skipped'];

				continue;    //left exactly as it was found

			}

			$rewritten .= substr( $content, $copied, $open - $copied ) . $shortcode;
			$copied     = $delimiter['end'];

			++$result['converted'];

		}

		if ( 0 < $result['converted'] ) {
			$result['content'] = $rewritten . substr( $content, $copied );
		}

		return $result;

	}    //end convert_content()

	/**
	 * Method to find every one of this plugin's self closing block delimiters in a
	 * piece of content.
	 *
	 * @param string $content Content to scan.
	 *
	 * @return array List of delimiters, each with `block`, `open`, `attrs` and `end`, in the order they appear.
	 */
	protected static function _get_delimiters( string $content ): array {

		$delimiters = [];
		$search     = 0;

		while ( true ) {

			$open = strpos( $content, '<!--', $search );

			if ( false === $open ) {
				break;
			}

			$delimiter = static::_read_delimiter( $content, $open );

			if ( is_null( $delimiter ) ) {

				$search = $open + 4;

				continue;    //some other comment, or some other block

			}

			$delimiter['open'] = $open;
			$delimiters[]      = $delimiter;
			$search            = $delimiter['end'];

		}

		return $delimiters;

	}    //end _get_delimiters()

	/**
	 * Method to find where every one of this plugin's shortcodes in a piece of content
	 * begins and ends.
	 *
	 * The pattern is WordPress's own, so escaped, self closing, unclosed and nested
	 * tags are bounded exactly as `do_shortcode()` bounds them, and it is built by the
	 * same call `Content_Protector` builds its own with, so the two cannot drift.
	 *
	 * The matches are walked one at a time rather than handed to
	 * `preg_replace_callback()`, because where matching resumes has to be decided here.
	 * A match which begins inside a block delimiter is that block's attribute data
	 * rather than a shortcode — `serialize_block_attributes()` escapes `<`, `>`, `&`
	 * and `--` in there but neither `[` nor `]` — and it can reach a long way past the
	 * end of the delimiter. Matching therefore resumes at the end of the delimiter, so
	 * that every real shortcode such a match spanned is still offered to the matcher
	 * instead of being swallowed with it.
	 *
	 * A match which begins outside a delimiter owns every byte it covers, delimiters
	 * included, because that is a snippet whose code is block markup and the shortcode
	 * is the construct the author wrote.
	 *
	 * @param string $content    Content to scan.
	 * @param array  $delimiters Delimiters from `self::_get_delimiters()`.
	 *
	 * @return array|null List of ranges, each with `open` and `end`, end exclusive; or NULL when PCRE gave up on the content.
	 */
	protected static function _get_shortcode_ranges( string $content, array $delimiters ): ?array {

		$tags = Legacy_Map::get_tags();

		if ( empty( $tags ) || ! str_contains( $content, '[' ) ) {
			return [];
		}

		$pattern = sprintf( '/%s/', Helper::get_shortcode_pattern( $tags ) );
		$length  = strlen( $content );
		$ranges  = [];
		$offset  = 0;

		while ( $offset <= $length && 1 === preg_match( $pattern, $content, $matches, PREG_OFFSET_CAPTURE, $offset ) ) {

			$start     = (int) $matches[0][1];
			$delimiter = static::_get_enclosing_end( $start, $delimiters );

			if ( ! is_null( $delimiter ) ) {

				$offset = $delimiter;

				continue;    //the block's data, not content

			}

			$end      = $start + strlen( (string) $matches[0][0] );
			$ranges[] = [
				'open' => $start,
				'end'  => $end,
			];
			$offset   = $end;

		}

		/*
		 * `preg_match()` hands back FALSE when it hits a backtrack, recursion or JIT
		 * stack limit, which from here is indistinguishable from having run out of
		 * matches. This runs on the way to a post being written, so a scan that gave up
		 * has to mean "the shortcodes are unknown" and never "there were none".
		 */
		return ( PREG_NO_ERROR === preg_last_error() ) ? $ranges : null;

	}    //end _get_shortcode_ranges()

	/**
	 * Method to find where the range holding an offset ends.
	 *
	 * @param int   $offset Byte offset to place.
	 * @param array $ranges Ranges, each with `open` and `end`, end exclusive.
	 *
	 * @return int|null End of the range holding the offset, or NULL when it is in none of them.
	 */
	protected static function _get_enclosing_end( int $offset, array $ranges ): ?int {

		foreach ( $ranges as $range ) {

			if ( $offset >= $range['open'] && $offset < $range['end'] ) {
				return $range['end'];
			}
		}

		return null;

	}    //end _get_enclosing_end()

	/**
	 * Method to read one of this plugin's self closing block delimiters.
	 *
	 * The end of the delimiter is found by scanning for the `-->` that closes the
	 * HTML comment, rather than by matching the attribute JSON. Matching the JSON is
	 * what a pattern does, and the tempered pattern the block grammar is written with
	 * backtracks catastrophically once the attributes run to a few tens of kilobytes.
	 * That is precisely the content this tool exists to rescue, so it cannot be the
	 * content the tool gives up on. A scan has no such limit.
	 *
	 * Scanning is sound because `serialize_block_attributes()` escapes `-`, `<` and
	 * `>` before the attributes are written, so however a snippet is written no `-->`
	 * can occur inside them. Should a delimiter be hand written and hold one anyway,
	 * the scan carries on to the next `-->`, so the delimiter is still read whole.
	 *
	 * @param string $content Content being read.
	 * @param int    $offset  Offset of the `<!--` which opens the comment.
	 *
	 * @return array|null Three keys, `block`, `attrs` and `end`, or NULL when this is not one of this plugin's self closing delimiters.
	 */
	protected static function _read_delimiter( string $content, int $offset ): ?array {

		$after = $offset + 4;    //past the `<!--`
		$gap   = strspn( $content, static::DELIMITER_WHITESPACE, $after );

		if ( 1 > $gap ) {
			return null;
		}

		$block = static::_read_block_name( $content, $after + $gap );

		if ( is_null( $block ) ) {
			return null;
		}

		$body = $after + $gap + strlen( $block ) + 3;    //the name, plus the `wp:` in front of it
		$gap  = strspn( $content, static::DELIMITER_WHITESPACE, $body );

		if ( 1 > $gap ) {
			return null;    //a longer block name which merely begins with this one
		}

		$start = $body + $gap;    //the `{` which opens the attributes, or the `/` which closes an empty delimiter

		if ( '/-->' === substr( $content, $start, 4 ) ) {

			return [
				'block' => $block,
				'attrs' => '',
				'end'   => $start + 4,
			];

		}

		if ( '{' !== substr( $content, $start, 1 ) ) {
			return null;    //not self closing, or carrying something which is not attributes
		}

		$close = $start;

		while ( true ) {

			$close = strpos( $content, '-->', $close + 1 );

			if ( false === $close ) {
				return null;    //the comment is never closed
			}

			/*
			 * The bound is tested here rather than left to the fact that `$close` starts
			 * at `$start` and the search above runs from `$close + 1`. That holds, but it
			 * holds thirty lines away from the read which needs it, and a rewrite of the
			 * scan above would take the defence away without touching this line.
			 *
			 * The `$content[ $end - 1 ]` read below needs nothing of its own: `$end` is
			 * only ever decremented while `$end > $start`, and where it is not decremented
			 * at all the `( $close - 1 ) === $end` test short circuits ahead of it.
			 */
			if ( 1 > $close || '/' !== $content[ $close - 1 ] ) {
				continue;    //nothing before the `-->`, or a closing delimiter rather than a self closing one
			}

			$end = $close - 1;    //the `/`

			while ( $end > $start && false !== strpos( static::DELIMITER_WHITESPACE, $content[ $end - 1 ] ) ) {
				--$end;
			}

			if ( ( $close - 1 ) === $end || '}' !== $content[ $end - 1 ] ) {
				continue;    //the attributes do not end here, so neither does the delimiter
			}

			return [
				'block' => $block,
				'attrs' => substr( $content, $start, $end - $start ),
				'end'   => $close + 3,
			];

		}

	}    //end _read_delimiter()

	/**
	 * Method to read which of this plugin's blocks a delimiter names.
	 *
	 * Neither name is a prefix of the other, so whichever matches is the block, and
	 * the caller still has to see whitespace after it before the match means anything.
	 *
	 * @param string $content Content being read.
	 * @param int    $offset  Offset of the `wp:` which opens the block name.
	 *
	 * @return string|null The block name, or NULL where the delimiter names some other block.
	 */
	protected static function _read_block_name( string $content, int $offset ): ?string {

		$length = strlen( $content );

		foreach ( [ static::BLOCK_NAME, static::GIST_BLOCK_NAME ] as $block ) {

			$name        = sprintf( 'wp:%s', $block );
			$name_length = strlen( $name );

			if ( $length < ( $offset + $name_length ) ) {
				continue;
			}

			if ( 0 === substr_compare( $content, $name, $offset, $name_length ) ) {
				return $block;
			}
		}

		return null;

	}    //end _read_block_name()

	/**
	 * Method to write one block's attributes as a shortcode.
	 *
	 * Attributes the block did not carry are left out, so that a setting the block
	 * took from the site defaults goes on taking it from the site defaults.
	 *
	 * @param array $attributes Block attributes, as they were stored in the delimiter.
	 *
	 * @return string|null The shortcode, an empty string when there is no snippet to write one for, or NULL when the snippet cannot be written as one.
	 */
	public static function block_to_shortcode( array $attributes ): ?string {

		$code = ( isset( $attributes['code'] ) && is_scalar( $attributes['code'] ) ) ? (string) $attributes['code'] : '';

		/*
		 * A block holding no code shows a reader nothing, so there is nothing to write
		 * a shortcode around. It is dropped rather than replaced by an empty shortcode,
		 * which would show a reader nothing either and would leave noise behind in the
		 * post content.
		 */
		if ( '' === $code ) {
			return '';
		}

		/*
		 * A shortcode ends at its own closing tag, so this plugin's tags are written as
		 * text before the code goes into one — see `Legacy_Map::escape_tags()`. The
		 * block a post about this plugin is made of holds exactly those tags, and it
		 * used to be the one block this tool refused.
		 *
		 * NULL only where the escape itself failed. A block whose code could not be read
		 * is left where it stands and reported, which is what `blocks_left_alone` has
		 * always counted.
		 */
		$code = Legacy_Map::escape_tags( $code );

		if ( is_null( $code ) ) {
			return null;
		}

		$atts = [
			'language' => static::_sanitize_language( (string) ( $attributes['language'] ?? '' ) ),
		];

		if ( array_key_exists( 'showLineNumbers', $attributes ) ) {
			$atts['gutter'] = ( empty( $attributes['showLineNumbers'] ) ) ? 'no' : 'yes';
		}

		$first_line = absint( $attributes['firstLine'] ?? 0 );

		if ( 1 < $first_line ) {
			$atts['firstline'] = (string) $first_line;
		}

		$highlight = Renderer::compact_line_ranges(
			Snippet::parse_line_ranges( $attributes['highlightLines'] ?? '' )
		);

		if ( '' !== $highlight ) {
			$atts['highlight'] = $highlight;
		}

		$file = static::_sanitize_label( (string) ( $attributes['file'] ?? '' ) );

		if ( '' !== $file ) {
			$atts['file'] = $file;
		}

		$pairs = [];

		foreach ( $atts as $name => $value ) {
			$pairs[] = sprintf( '%s="%s"', $name, $value );
		}

		return sprintf(
			"[%1\$s %2\$s]\n%3\$s\n[/%1\$s]",
			static::SHORTCODE_TAG,
			implode( ' ', $pairs ),
			$code
		);

	}    //end block_to_shortcode()

	/**
	 * Method to write one Gist block's attributes as a shortcode.
	 *
	 * One output shape, always: `[github gist="https://gist.github.com/<id>"]`. That is
	 * byte for byte the address the embed already resolves the block to, so the page a
	 * reader sees is unchanged by the conversion, and with the plugin deactivated the
	 * post is left a working Gist address rather than a bare id.
	 *
	 * The id comes back from `Gist_Embed::resolve_id()` — the same call the embed makes,
	 * so the two cannot disagree about which Gist a block names — and it is letters and
	 * digits by construction. So there is nothing here which could break out of the
	 * attribute it is written into, and no sanitising step of this method's own.
	 *
	 * @param array $attributes Block attributes, as they were stored in the delimiter.
	 *
	 * @return string|null The shortcode, an empty string when there is no Gist to write one for, or NULL when one cannot be written.
	 */
	public static function gist_block_to_shortcode( array $attributes ): ?string {

		$url = ( isset( $attributes['url'] ) && is_scalar( $attributes['url'] ) ) ? (string) $attributes['url'] : '';
		$id  = Gist_Embed::resolve_id( [ 'gist' => trim( $url ) ] );

		/*
		 * A block naming no Gist this plugin will print shows a reader nothing today, so
		 * there is nothing to write a shortcode around. It is dropped rather than replaced
		 * by an empty shortcode, which would show a reader nothing either and would leave
		 * noise behind in the post content.
		 */
		if ( '' === $id ) {
			return '';
		}

		return sprintf(
			'[%1$s gist="https://gist.github.com/%2$s"]',
			static::GIST_SHORTCODE_TAG,
			$id
		);

	}    //end gist_block_to_shortcode()

	/**
	 * Method to count the posts whose content holds either block's marker.
	 *
	 * The count is a `LIKE` over `post_content` and knows nothing about where in the
	 * content the marker sits, so a post whose only marker is inside a snippet's code
	 * goes on being counted after the tool has decided to leave it alone. Reading every
	 * matching post to tell the two apart is the work of a whole run, and this is a
	 * progress figure, so the count stays cheap and generous: it is the number of posts
	 * worth looking at, which is exactly what `self::_get_batch()` hands out.
	 *
	 * @return int
	 */
	public static function count_remaining(): int {

		global $wpdb;

		$clause = static::_get_where_clause();

		if ( is_null( $clause ) ) {
			return 0;
		}

		/*
		 * The placeholders are built by _get_where_clause() and every value that
		 * fills them is passed to prepare(), which is what the sniffs below cannot
		 * see. The count is deliberately uncached: the tool is rewriting the very
		 * rows it is counting.
		 */
		//phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE {$clause['sql']}",
				$clause['values']
			)
		);
		//phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	}    //end count_remaining()

	/**
	 * Method to get how many posts are examined per batch.
	 *
	 * @return int
	 */
	public static function get_batch_size(): int {

		/**
		 * Filters how many posts the revert tool examines per request.
		 *
		 * Smaller batches finish sooner and are kinder to a slow host; larger ones
		 * get through a big site in fewer requests.
		 *
		 * @param int $batch_size Number of posts per batch.
		 */
		$batch_size = (int) apply_filters( static::FILTER_BATCH_SIZE, static::DEFAULT_BATCH_SIZE );    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook name is the prefixed class constant above.

		return max( 1, min( static::MAX_BATCH_SIZE, $batch_size ) );

	}    //end get_batch_size()

	/**
	 * Method to fetch one batch of posts which still hold a block.
	 *
	 * @param int $cursor Id of the last post already handed out.
	 * @param int $limit  Largest number of posts to return.
	 *
	 * @return array List of row objects with `ID` and `post_content`.
	 */
	protected static function _get_batch( int $cursor, int $limit ): array {

		global $wpdb;

		$clause = static::_get_where_clause();

		if ( is_null( $clause ) ) {
			return [];
		}

		$values = array_merge( $clause['values'], [ max( 0, $cursor ), $limit ] );

		/*
		 * As in count_remaining(): the placeholders come from _get_where_clause()
		 * and every value that fills them is passed to prepare(). A cached read
		 * would hand back rows this tool has already rewritten.
		 */
		//phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_content FROM {$wpdb->posts} WHERE {$clause['sql']} AND ID > %d ORDER BY ID ASC LIMIT %d",
				$values
			)
		);
		//phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	}    //end _get_batch()

	/**
	 * Method to build the condition which selects the posts in scope.
	 *
	 * Public post types, every status but the two which mean the content is gone,
	 * and only rows whose content actually holds one of the two block delimiters.
	 * Revisions are excluded by the post type list, since `revision` is not a public
	 * post type.
	 *
	 * @return array|null Two keys, `sql` and `values`, or NULL when there is nothing that could match.
	 */
	protected static function _get_where_clause(): ?array {

		global $wpdb;

		$post_types = array_values( (array) get_post_types( [ 'public' => true ], 'names' ) );

		if ( empty( $post_types ) ) {
			return null;
		}

		$types    = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		$statuses = implode( ', ', array_fill( 0, count( static::EXCLUDED_STATUSES ), '%s' ) );

		return [
			'sql'    => sprintf(
				'( post_content LIKE %%s OR post_content LIKE %%s ) AND post_type IN ( %s ) AND post_status NOT IN ( %s )',
				$types,
				$statuses
			),
			'values' => array_merge(
				[
					'%' . $wpdb->esc_like( static::BLOCK_MARKER ) . '%',
					'%' . $wpdb->esc_like( static::GIST_BLOCK_MARKER ) . '%',
				],
				$post_types,
				static::EXCLUDED_STATUSES
			),
		];

	}    //end _get_where_clause()

	/**
	 * Method to read a block delimiter's attributes.
	 *
	 * @param string $raw Attribute JSON as it appeared in the delimiter.
	 *
	 * @return array|null The attributes, or NULL when they cannot be read.
	 */
	protected static function _decode_attributes( string $raw ): ?array {

		$raw = trim( $raw );

		if ( '' === $raw ) {
			return [];    //a block with no attributes is still a block
		}

		$attributes = json_decode( $raw, true );

		return ( is_array( $attributes ) ) ? $attributes : null;

	}    //end _decode_attributes()

	/**
	 * Method to clean up a language name so that it is safe in a shortcode.
	 *
	 * This was a `preg_replace()` cast to a string, which meant a language dropped
	 * without a word whenever PCRE gave up: NULL cast to a string is an empty one, so
	 * every snippet the run rewrote came back out unhighlighted. Nothing survives that
	 * cast worth keeping either — the only values which reach the pattern's failure
	 * branch are the ones with something in them to strip, so "keep what came in" here
	 * would mean writing the very characters that end a shortcode attribute into one.
	 *
	 * So the filter is done without PCRE instead, and there is no failure branch left to
	 * decide anything about: a language is cleaned the same way whatever state the
	 * engine is in. `LANGUAGE_CHARS` is a list of bytes and the pattern it replaces was
	 * byte-wise as well, so the two agree on multibyte input.
	 *
	 * @param string $language Language as the block carried it.
	 *
	 * @return string
	 */
	protected static function _sanitize_language( string $language ): string {

		$language = strtolower( trim( $language ) );
		$safe     = '';
		$length   = strlen( $language );

		for ( $index = 0; $index < $length; $index++ ) {

			if ( str_contains( static::LANGUAGE_CHARS, $language[ $index ] ) ) {
				$safe .= $language[ $index ];
			}
		}

		return $safe;

	}    //end _sanitize_language()

	/**
	 * Method to clean up a free text label so that it is safe in a shortcode.
	 *
	 * A shortcode has no escape for the three characters that end an attribute or a
	 * tag, so they are removed. Losing a bracket out of a file label is a visible,
	 * harmless loss; a label which broke out of the shortcode would not be.
	 *
	 * Everything which makes the label safe to write has happened by the time the
	 * pattern runs, and the pattern only tidies whitespace. So a pattern which gives up
	 * — NULL, and `(string) NULL` is an empty string — keeps the label as it stood
	 * before the tidying, untidy and whole, rather than blanking a label the author
	 * wrote.
	 *
	 * @param string $label Label as the block carried it.
	 *
	 * @return string
	 */
	protected static function _sanitize_label( string $label ): string {

		$label     = str_replace( [ '[', ']', '"' ], '', wp_strip_all_tags( $label ) );
		$collapsed = preg_replace( '/\s+/', ' ', $label );

		return trim( ( is_string( $collapsed ) ) ? $collapsed : $label );

	}    //end _sanitize_label()

}    //end of class


//EOF
