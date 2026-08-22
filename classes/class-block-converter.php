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
 * A snippet in a block is not portable: with the plugin off the block type is gone
 * and a self closing block has no inner content. Both blocks are converted. Only the
 * plugin's own delimiters are matched and replaced; the post is never parsed into
 * blocks and re-serialised, because that round trip is not byte identical. Work is
 * handed out keyed on the last post id, not an offset, because rows stop matching as
 * they are rewritten.
 */
class Block_Converter {

	use Singleton;

	/**
	 * Name of the code block.
	 *
	 * @var string
	 */
	public const string BLOCK_NAME = Block::NAME;

	/**
	 * The string a post's content must contain for it to hold a code block.
	 *
	 * @var string
	 */
	public const string BLOCK_MARKER = '<!-- wp:' . self::BLOCK_NAME;

	/**
	 * Name of the Gist block.
	 *
	 * Converted for the same reason the code block is: with the plugin off the Gist
	 * renders as nothing and the post no longer says which Gist it meant.
	 *
	 * @var string
	 */
	public const string GIST_BLOCK_NAME = Block::GIST_NAME;

	/**
	 * The string a post's content must contain for it to hold a Gist block.
	 *
	 * @var string
	 */
	protected const string _GIST_BLOCK_MARKER = '<!-- wp:' . self::GIST_BLOCK_NAME;

	/**
	 * Shortcode tag every converted code block is written as.
	 *
	 * One output shape, whatever the language, so what comes out is unambiguous.
	 *
	 * @var string
	 */
	public const string SHORTCODE_TAG = Legacy_Map::GENERIC_TAG;

	/**
	 * Shortcode tag every converted Gist block is written as.
	 *
	 * @var string
	 */
	public const string GIST_SHORTCODE_TAG = Gist_Embed::TAG;

	/**
	 * Post statuses which are never touched.
	 *
	 * Everything else is: a draft or a scheduled post holds snippets too, and
	 * leaving those behind would let the very failure this tool exists to prevent
	 * happen later on.
	 *
	 * @var array
	 */
	protected const array _EXCLUDED_STATUSES = [ 'trash', 'auto-draft' ];

	/**
	 * Number of posts examined per batch.
	 *
	 * @var int
	 */
	protected const int _DEFAULT_BATCH_SIZE = 20;

	/**
	 * The characters the block grammar counts as whitespace, which is PCRE's `\s`.
	 *
	 * @var string
	 */
	protected const string _DELIMITER_WHITESPACE = " \t\n\r\f\v";

	/**
	 * The characters a language name may carry into a shortcode attribute.
	 *
	 * A plain list, not a pattern; membership is tested without PCRE — see
	 * `_sanitize_language()`.
	 *
	 * @var string
	 */
	protected const string _LANGUAGE_CHARS = 'abcdefghijklmnopqrstuvwxyz0123456789_+#.-';

	/**
	 * Largest batch the plugin will accept, whatever the filter asks for.
	 *
	 * @var int
	 */
	protected const int _MAX_BATCH_SIZE = 200;

	/**
	 * Filter which sets how many posts are examined per batch.
	 *
	 * @var string
	 */
	public const string FILTER_BATCH_SIZE = 'ig_syntax_hiliter/revert_batch_size';

	/**
	 * Class constructor.
	 */
	protected function __construct() {

		$this->_register_hooks();

	}

	/**
	 * Method to hook this class up to WordPress.
	 *
	 * @return void
	 */
	protected function _register_hooks(): void {

		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

	}

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
					'permission_callback' => [ Admin::get_instance(), 'rest_permission_check' ],
					'args'                => [],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'process_batch' ],
					'permission_callback' => [ Admin::get_instance(), 'rest_permission_check' ],
					'args'                => [
						'cursor' => [
							'type'              => 'integer',
							'required'          => false,
							'default'           => 0,
							'minimum'           => 0,
							'description'       => __( 'Id of the last post the caller has already been given. Zero starts at the beginning.', 'igsyntax-hiliter' ),
							'sanitize_callback' => 'absint',
							'validate_callback' => [ $this, 'validate_cursor' ],
						],
					],
				],
			]
		);

	}

	/**
	 * Method to check that a cursor is a post id, or the start.
	 *
	 * @param mixed $value Cursor as it was sent.
	 *
	 * @return bool
	 */
	public function validate_cursor( mixed $value ): bool {
		return ( is_numeric( $value ) && 0 <= (int) $value );
	}

	/**
	 * Method to report how much work there is.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_status(): WP_REST_Response {

		return new WP_REST_Response(
			[
				'total'      => $this->count_remaining(),
				'batch_size' => $this->get_batch_size(),
			]
		);

	}

	/**
	 * Method to convert one batch of posts.
	 *
	 * `converted` + `skipped` + `failed` = `processed`. `blocks_left_alone` counts
	 * blocks, not posts, and overlaps all three.
	 *
	 * @param \WP_REST_Request $request Request being served.
	 *
	 * @return \WP_REST_Response Post counts `processed`, `converted`, `skipped` and `failed`;
	 *                           `blocks_left_alone`, how many blocks in the batch the tool
	 *                           deliberately declined to rewrite; the `cursor` to carry on
	 *                           from; and `done`.
	 */
	public function process_batch( WP_REST_Request $request ): WP_REST_Response {

		$cursor     = absint( $request['cursor'] );
		$batch_size = $this->get_batch_size();
		$rows       = $this->_get_batch( $cursor, $batch_size );

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

			$result = $this->convert_content( (string) $row->post_content );

			// Counted ahead of the branches below, which skip the rest of the loop.
			$counts['blocks_left_alone'] += $result['skipped'];

			if ( 0 < $result['converted'] && ! $this->_save_content( $post_id, $result['content'] ) ) {

				++$counts['failed'];

				continue;

			}

			// An unreadable delimiter is a failure even where the rest of the post converted.
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

	}

	/**
	 * Method to write a post's rewritten content back.
	 *
	 * @param int    $post_id Post to write to.
	 * @param string $content Content to write.
	 *
	 * @return bool Whether the content was written.
	 */
	protected function _save_content( int $post_id, string $content ): bool {

		$saved = wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => wp_slash( $content ),
			],
			true
		);

		return ( ! is_wp_error( $saved ) && 0 < (int) $saved );

	}

	/**
	 * Method to rewrite every one of this plugin's blocks in a piece of content.
	 *
	 * Nothing but this plugin's delimiters is touched. A delimiter inside one of this
	 * plugin's shortcodes is a snippet whose code is block markup and is left alone —
	 * that is what makes a second run safe.
	 *
	 * @param string $content Content to rewrite.
	 *
	 * @return array Four keys: `content`, the rewritten content; `converted`, how many blocks
	 *               became shortcodes; `skipped`, how many were deliberately left alone; and
	 *               `failed`, how many could not be read.
	 */
	public function convert_content( string $content ): array {

		$result = [
			'content'   => $content,
			'converted' => 0,
			'skipped'   => 0,
			'failed'    => 0,
		];

		if ( ! str_contains( $content, static::BLOCK_MARKER ) && ! str_contains( $content, static::_GIST_BLOCK_MARKER ) ) {
			return $result;
		}

		$delimiters = $this->_get_delimiters( $content );
		$shortcodes = $this->_get_shortcode_ranges( $content, $delimiters );

		if ( is_null( $shortcodes ) ) {
			return $result;    // where the shortcodes are is unknown, so nothing here can be rewritten safely
		}

		$rewritten = '';
		$copied    = 0;

		foreach ( $delimiters as $delimiter ) {

			$open = $delimiter['open'];

			if ( ! is_null( $this->_get_enclosing_end( $open, $shortcodes ) ) ) {
				continue;    // the author's code, which merely reads like a block
			}

			$attributes = $this->_decode_attributes( $delimiter['attrs'] );

			if ( is_null( $attributes ) ) {

				++$result['failed'];

				continue;    // this plugin's block, and unreadable, which is not the same as choosing to leave it

			}

			$shortcode = ( static::GIST_BLOCK_NAME === $delimiter['block'] )
				? $this->gist_block_to_shortcode( $attributes )
				: $this->block_to_shortcode( $attributes );

			if ( is_null( $shortcode ) ) {

				++$result['skipped'];

				continue;    // left exactly as it was found

			}

			$rewritten .= substr( $content, $copied, $open - $copied ) . $shortcode;
			$copied     = $delimiter['end'];

			++$result['converted'];

		}

		if ( 0 < $result['converted'] ) {
			$result['content'] = $rewritten . substr( $content, $copied );
		}

		return $result;

	}

	/**
	 * Method to find every one of this plugin's self closing block delimiters in a
	 * piece of content.
	 *
	 * @param string $content Content to scan.
	 *
	 * @return array List of delimiters, each with `block`, `open`, `attrs` and `end`, in the
	 *               order they appear.
	 */
	protected function _get_delimiters( string $content ): array {

		$delimiters = [];
		$search     = 0;

		while ( true ) {

			$open = strpos( $content, '<!--', $search );

			if ( false === $open ) {
				break;
			}

			$delimiter = $this->_read_delimiter( $content, $open );

			if ( is_null( $delimiter ) ) {

				$search = $open + 4;

				continue;    // some other comment, or some other block

			}

			$delimiter['open'] = $open;
			$delimiters[]      = $delimiter;
			$search            = $delimiter['end'];

		}

		return $delimiters;

	}

	/**
	 * Method to find where every one of this plugin's shortcodes in a piece of content
	 * begins and ends.
	 *
	 * Matches are walked one at a time because where matching resumes has to be decided
	 * here: a match beginning inside a delimiter is attribute data
	 * (`serialize_block_attributes()` escapes `<>&--` but not `[]`) and can reach far
	 * past the delimiter, so matching resumes at the delimiter's end. A match beginning
	 * outside a delimiter owns every byte it covers.
	 *
	 * @param string $content    Content to scan.
	 * @param array  $delimiters Delimiters from `_get_delimiters()`.
	 *
	 * @return array|null List of ranges, each with `open` and `end`, end exclusive; or NULL
	 *                    when PCRE gave up on the content.
	 */
	protected function _get_shortcode_ranges( string $content, array $delimiters ): ?array {

		$tags = Legacy_Map::get_instance()->get_tags();

		if ( empty( $tags ) || ! str_contains( $content, '[' ) ) {
			return [];
		}

		$pattern = sprintf( '/%s/', Helper::get_shortcode_pattern( $tags ) );
		$length  = strlen( $content );
		$ranges  = [];
		$offset  = 0;

		while ( $offset <= $length && 1 === preg_match( $pattern, $content, $matches, PREG_OFFSET_CAPTURE, $offset ) ) {

			$start     = (int) $matches[0][1];
			$delimiter = $this->_get_enclosing_end( $start, $delimiters );

			if ( ! is_null( $delimiter ) ) {

				$offset = $delimiter;

				continue;    // the block's data, not content

			}

			$end      = $start + strlen( (string) $matches[0][0] );
			$ranges[] = [
				'open' => $start,
				'end'  => $end,
			];
			$offset   = $end;

		}

		// A FALSE from `preg_match()` looks like running out of matches. On the way to a
		// post being written it has to mean "unknown", never "none".
		return ( PREG_NO_ERROR === preg_last_error() ) ? $ranges : null;

	}

	/**
	 * Method to find where the range holding an offset ends.
	 *
	 * @param int   $offset Byte offset to place.
	 * @param array $ranges Ranges, each with `open` and `end`, end exclusive.
	 *
	 * @return int|null End of the range holding the offset, or NULL when it is in none of them.
	 */
	protected function _get_enclosing_end( int $offset, array $ranges ): ?int {

		foreach ( $ranges as $range ) {

			if ( $offset >= $range['open'] && $offset < $range['end'] ) {
				return $range['end'];
			}
		}

		return null;

	}

	/**
	 * Method to read one of this plugin's self closing block delimiters.
	 *
	 * The end is found by scanning for `-->`, not by matching the attribute JSON: the
	 * tempered pattern the block grammar wants backtracks catastrophically past a few
	 * tens of KB, which is the content this tool exists to rescue. Sound because
	 * `serialize_block_attributes()` escapes `-`, `<` and `>`.
	 *
	 * @param string $content Content being read.
	 * @param int    $offset  Offset of the `<!--` which opens the comment.
	 *
	 * @return array|null Three keys, `block`, `attrs` and `end`, or NULL when this is not one
	 *                    of this plugin's self closing delimiters.
	 */
	protected function _read_delimiter( string $content, int $offset ): ?array {

		$after = $offset + 4;
		$gap   = strspn( $content, static::_DELIMITER_WHITESPACE, $after );

		if ( 1 > $gap ) {
			return null;
		}

		$block = $this->_read_block_name( $content, $after + $gap );

		if ( is_null( $block ) ) {
			return null;
		}

		$body = $after + $gap + strlen( $block ) + 3;    // the name, plus the `wp:` in front of it
		$gap  = strspn( $content, static::_DELIMITER_WHITESPACE, $body );

		if ( 1 > $gap ) {
			return null;    // a longer block name which merely begins with this one
		}

		$start = $body + $gap;    // the `{` which opens the attributes, or the `/` which closes an empty delimiter

		if ( '/-->' === substr( $content, $start, 4 ) ) {

			return [
				'block' => $block,
				'attrs' => '',
				'end'   => $start + 4,
			];

		}

		if ( '{' !== substr( $content, $start, 1 ) ) {
			return null;    // not self closing, or carrying something which is not attributes
		}

		$close = $start;

		while ( true ) {

			$close = strpos( $content, '-->', $close + 1 );

			if ( false === $close ) {
				return null;    // the comment is never closed
			}

			// The bound is tested here rather than inferred from where the search started.
			if ( 1 > $close || '/' !== $content[ $close - 1 ] ) {
				continue;    // nothing before the `-->`, or a closing delimiter rather than a self closing one
			}

			$end = $close - 1;

			while ( $end > $start && false !== strpos( static::_DELIMITER_WHITESPACE, $content[ $end - 1 ] ) ) {
				--$end;
			}

			if ( ( $close - 1 ) === $end || '}' !== $content[ $end - 1 ] ) {
				continue;    // the attributes do not end here, so neither does the delimiter
			}

			return [
				'block' => $block,
				'attrs' => substr( $content, $start, $end - $start ),
				'end'   => $close + 3,
			];

		}

	}

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
	protected function _read_block_name( string $content, int $offset ): ?string {

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

	}

	/**
	 * Method to write one block's attributes as a shortcode.
	 *
	 * Attributes the block did not carry are left out, so the site defaults still apply.
	 *
	 * @param array $attributes Block attributes, as they were stored in the delimiter.
	 *
	 * @return string|null The shortcode, an empty string when there is no snippet to write one
	 *                     for, or NULL when the snippet cannot be written as one.
	 */
	public function block_to_shortcode( array $attributes ): ?string {

		$code = ( isset( $attributes['code'] ) && is_scalar( $attributes['code'] ) ) ? (string) $attributes['code'] : '';

		// `'' ===` and not `empty()`: `0` is code and `empty( '0' )` is TRUE, and here
		// dropping a block means the snippet leaves the post for good.
		if ( '' === $code ) {
			return '';
		}

		// A shortcode ends at its own closing tag, so this plugin's tags are escaped first
		// — see `Legacy_Map::escape_tags()`. NULL only where the escape itself failed.
		$code = Legacy_Map::get_instance()->escape_tags( $code );

		if ( is_null( $code ) ) {
			return null;
		}

		$atts = [
			'language' => $this->_sanitize_language( (string) ( $attributes['language'] ?? '' ) ),
		];

		if ( array_key_exists( 'showLineNumbers', $attributes ) ) {
			$atts['gutter'] = ( empty( $attributes['showLineNumbers'] ) ) ? 'no' : 'yes';
		}

		$first_line = absint( $attributes['firstLine'] ?? 0 );

		if ( 1 < $first_line ) {
			$atts['firstline'] = (string) $first_line;
		}

		$highlight = Renderer::get_instance()->compact_line_ranges(
			Snippet::from_block_attributes( $attributes )->highlight_lines
		);

		if ( ! empty( $highlight ) ) {
			$atts['highlight'] = $highlight;
		}

		$file = $this->_sanitize_label( (string) ( $attributes['file'] ?? '' ) );

		if ( ! empty( $file ) ) {
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

	}

	/**
	 * Method to write one Gist block's attributes as a shortcode.
	 *
	 * One output shape, byte for byte the address the embed resolves to. The id comes
	 * from `Gist_Embed::resolve_id()` — the same call the embed makes — and is letters
	 * and digits by construction.
	 *
	 * @param array $attributes Block attributes, as they were stored in the delimiter.
	 *
	 * @return string|null The shortcode, an empty string when there is no Gist to write one
	 *                     for, or NULL when one cannot be written.
	 */
	public function gist_block_to_shortcode( array $attributes ): ?string {

		$url = ( isset( $attributes['url'] ) && is_scalar( $attributes['url'] ) ) ? (string) $attributes['url'] : '';
		$id  = Gist_Embed::get_instance()->resolve_id( [ 'gist' => trim( $url ) ] );

		// A block naming no valid Gist renders nothing today, so it is dropped.
		if ( empty( $id ) ) {
			return '';
		}

		return sprintf(
			'[%1$s gist="https://gist.github.com/%2$s"]',
			static::GIST_SHORTCODE_TAG,
			$id
		);

	}

	/**
	 * Method to count the posts whose content holds either block's marker.
	 *
	 * A cheap and generous `LIKE`: a post whose only marker is inside a snippet's code
	 * stays counted. It is a progress figure, not a promise.
	 *
	 * @return int
	 */
	public function count_remaining(): int {

		global $wpdb;

		$clause = $this->_get_where_clause();

		if ( is_null( $clause ) ) {
			return 0;
		}

		// Placeholders are built by _get_where_clause() and every value goes through
		// prepare(); uncached because the tool is rewriting the rows it is reading.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE {$clause['sql']}",
				$clause['values']
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	}

	/**
	 * Method to get how many posts are examined per batch.
	 *
	 * @return int
	 */
	public function get_batch_size(): int {

		/**
		 * Filters how many posts the revert tool examines per request.
		 *
		 * Smaller batches finish sooner and are kinder to a slow host; larger ones
		 * get through a big site in fewer requests.
		 *
		 * @param int $batch_size Number of posts per batch.
		 */
		$batch_size = (int) apply_filters( static::FILTER_BATCH_SIZE, static::_DEFAULT_BATCH_SIZE );    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook name is the prefixed class constant above.

		return max( 1, min( static::_MAX_BATCH_SIZE, $batch_size ) );

	}

	/**
	 * Method to fetch one batch of posts which still hold a block.
	 *
	 * @param int $cursor Id of the last post already handed out.
	 * @param int $limit  Largest number of posts to return.
	 *
	 * @return array List of row objects with `ID` and `post_content`.
	 */
	protected function _get_batch( int $cursor, int $limit ): array {

		global $wpdb;

		$clause = $this->_get_where_clause();

		if ( is_null( $clause ) ) {
			return [];
		}

		$values = array_merge( $clause['values'], [ max( 0, $cursor ), $limit ] );

		// Placeholders are built by _get_where_clause() and every value goes through
		// prepare(); uncached because the tool is rewriting the rows it is reading.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_content FROM {$wpdb->posts} WHERE {$clause['sql']} AND ID > %d ORDER BY ID ASC LIMIT %d",
				$values
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	}

	/**
	 * Method to build the condition which selects the posts in scope.
	 *
	 * Public post types, every status but the two which mean the content is gone,
	 * and only rows whose content actually holds one of the two block delimiters.
	 * Revisions are excluded by the post type list, since `revision` is not a public
	 * post type.
	 *
	 * @return array|null Two keys, `sql` and `values`, or NULL when there is nothing that
	 *                    could match.
	 */
	protected function _get_where_clause(): ?array {

		global $wpdb;

		$post_types = array_values( (array) get_post_types( [ 'public' => true ], 'names' ) );

		if ( empty( $post_types ) ) {
			return null;
		}

		$types    = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		$statuses = implode( ', ', array_fill( 0, count( static::_EXCLUDED_STATUSES ), '%s' ) );

		return [
			'sql'    => sprintf(
				'( post_content LIKE %%s OR post_content LIKE %%s ) AND post_type IN ( %s ) AND post_status NOT IN ( %s )',
				$types,
				$statuses
			),
			'values' => array_merge(
				[
					'%' . $wpdb->esc_like( static::BLOCK_MARKER ) . '%',
					'%' . $wpdb->esc_like( static::_GIST_BLOCK_MARKER ) . '%',
				],
				$post_types,
				static::_EXCLUDED_STATUSES
			),
		];

	}

	/**
	 * Method to read a block delimiter's attributes.
	 *
	 * @param string $raw Attribute JSON as it appeared in the delimiter.
	 *
	 * @return array|null The attributes, or NULL when they cannot be read.
	 */
	protected function _decode_attributes( string $raw ): ?array {

		$raw = trim( $raw );

		if ( empty( $raw ) ) {
			return [];    // a block with no attributes is still a block
		}

		$attributes = json_decode( $raw, true );

		return ( is_array( $attributes ) ) ? $attributes : null;

	}

	/**
	 * Method to clean up a language name so that it is safe in a shortcode.
	 *
	 * Filtered without PCRE: a `preg_replace()` which gives up returns NULL, and NULL cast
	 * to a string would drop the language from every snippet the run rewrote.
	 *
	 * @param string $language Language as the block carried it.
	 *
	 * @return string
	 */
	protected function _sanitize_language( string $language ): string {

		$language = strtolower( trim( $language ) );
		$safe     = '';
		$length   = strlen( $language );

		for ( $index = 0; $index < $length; $index++ ) {

			if ( str_contains( static::_LANGUAGE_CHARS, $language[ $index ] ) ) {
				$safe .= $language[ $index ];
			}
		}

		return $safe;

	}

	/**
	 * Method to clean up a free text label so that it is safe in a shortcode.
	 *
	 * The three characters that end an attribute or a tag are removed, since a shortcode
	 * has no escape for them. The pattern only tidies whitespace, so when it gives up
	 * the label is kept untidy and whole rather than blanked.
	 *
	 * @param string $label Label as the block carried it.
	 *
	 * @return string
	 */
	protected function _sanitize_label( string $label ): string {

		$label     = str_replace( [ '[', ']', '"' ], '', wp_strip_all_tags( $label ) );
		$collapsed = preg_replace( '/\s+/', ' ', $label );

		return trim( ( is_string( $collapsed ) ) ? $collapsed : $label );

	}

} // end of class

// EOF
