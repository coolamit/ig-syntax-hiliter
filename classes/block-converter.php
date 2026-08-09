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
 * So the rewrite is surgical. Only the plugin's own block delimiter is matched and
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
	 * Name of the block which is converted.
	 *
	 * @var string
	 */
	const BLOCK_NAME = 'igsyntax-hiliter/code';

	/**
	 * The string a post's content must contain for it to be worth looking at.
	 *
	 * @var string
	 */
	const BLOCK_MARKER = '<!-- wp:' . self::BLOCK_NAME;

	/**
	 * Shortcode tag every converted block is written as.
	 *
	 * One output shape, whatever the language, so what comes out is unambiguous.
	 *
	 * @var string
	 */
	const SHORTCODE_TAG = Legacy_Map::GENERIC_TAG;

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
	 * Whether the hooks have been registered already.
	 *
	 * @var bool
	 */
	protected bool $_hooked = false;

	/**
	 * Method to hook the converter's routes up to WordPress.
	 *
	 * @return void
	 */
	public function register_hooks(): void {

		if ( $this->_hooked ) {
			return;
		}

		$this->_hooked = true;

		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

	}    //end register_hooks()

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
	 * @param \WP_REST_Request $request Request being served.
	 *
	 * @return \WP_REST_Response
	 */
	public function process_batch( WP_REST_Request $request ): WP_REST_Response {

		$cursor     = absint( $request['cursor'] );
		$batch_size = static::get_batch_size();
		$rows       = static::_get_batch( $cursor, $batch_size );

		$counts = [
			'processed' => 0,
			'converted' => 0,
			'skipped'   => 0,
			'failed'    => 0,
		];

		foreach ( $rows as $row ) {

			++$counts['processed'];

			$post_id = (int) $row->ID;
			$cursor  = max( $cursor, $post_id );

			$result = static::convert_content( (string) $row->post_content );

			if ( 1 > $result['converted'] ) {
				++$counts['skipped'];
				continue;
			}

			$saved = wp_update_post(
				[
					'ID'           => $post_id,
					'post_content' => wp_slash( $result['content'] ),
				],
				true
			);

			if ( is_wp_error( $saved ) || 1 > (int) $saved ) {
				++$counts['failed'];
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
	 * Method to rewrite every one of this plugin's blocks in a piece of content.
	 *
	 * Nothing but the matched delimiters is touched.
	 *
	 * @param string $content Content to rewrite.
	 *
	 * @return array Three keys: `content`, the rewritten content; `converted`, how many blocks became shortcodes; and `skipped`, how many could not.
	 */
	public static function convert_content( string $content ): array {

		$result = [
			'content'   => $content,
			'converted' => 0,
			'skipped'   => 0,
		];

		if ( ! str_contains( $content, static::BLOCK_MARKER ) ) {
			return $result;
		}

		$converted = 0;
		$skipped   = 0;

		$rewritten = preg_replace_callback(
			static::_get_block_pattern(),
			static function ( array $matches ) use ( &$converted, &$skipped ): string {

				$attributes = static::_decode_attributes( (string) ( $matches['attrs'] ?? '' ) );
				$shortcode  = ( is_null( $attributes ) ) ? null : static::block_to_shortcode( $attributes );

				if ( is_null( $shortcode ) ) {

					++$skipped;

					return $matches[0];    //left exactly as it was found

				}

				++$converted;

				return $shortcode;

			},
			$content
		);

		if ( is_null( $rewritten ) ) {
			return $result;    //the rewrite failed, so the content is not changed at all
		}

		return [
			'content'   => $rewritten,
			'converted' => $converted,
			'skipped'   => $skipped,
		];

	}    //end convert_content()

	/**
	 * Method to write one block's attributes as a shortcode.
	 *
	 * Attributes the block did not carry are left out, so that a setting the block
	 * took from the site defaults goes on taking it from the site defaults.
	 *
	 * @param array $attributes Block attributes, as they were stored in the delimiter.
	 *
	 * @return string|null The shortcode, or NULL when the snippet cannot be written as one.
	 */
	public static function block_to_shortcode( array $attributes ): ?string {

		$code = ( isset( $attributes['code'] ) && is_scalar( $attributes['code'] ) ) ? (string) $attributes['code'] : '';

		/*
		 * A shortcode ends at its own closing tag, so code which contains that tag
		 * cannot be written as one without losing the rest of the snippet. Such a
		 * block is left alone and reported rather than damaged.
		 */
		if ( false !== stripos( $code, sprintf( '[/%s]', static::SHORTCODE_TAG ) ) ) {
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
	 * Method to count the posts which still hold one of this plugin's blocks.
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
	 * and only rows whose content actually holds the block delimiter. Revisions are
	 * excluded by the post type list, since `revision` is not a public post type.
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
				'post_content LIKE %%s AND post_type IN ( %s ) AND post_status NOT IN ( %s )',
				$types,
				$statuses
			),
			'values' => array_merge(
				[ '%' . $wpdb->esc_like( static::BLOCK_MARKER ) . '%' ],
				$post_types,
				static::EXCLUDED_STATUSES
			),
		];

	}    //end _get_where_clause()

	/**
	 * Method to get the pattern which matches this plugin's block delimiter.
	 *
	 * The block carries everything in its attributes and has no inner content, so it
	 * is always serialised as a single self closing delimiter. The attribute group is
	 * shaped the way the WordPress block parser shapes its own: it runs to the brace
	 * which closes the delimiter rather than to the first brace it meets, so a snippet
	 * with braces in it is matched whole.
	 *
	 * @return string
	 */
	protected static function _get_block_pattern(): string {

		return sprintf(
			'#<!--\s+wp:%s\s+(?P<attrs>\{(?:(?!\}\s+/?-->).)*?\}\s+)?/-->#s',
			preg_quote( static::BLOCK_NAME, '#' )
		);

	}    //end _get_block_pattern()

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
	 * @param string $language Language as the block carried it.
	 *
	 * @return string
	 */
	protected static function _sanitize_language( string $language ): string {
		return (string) preg_replace( '/[^a-z0-9_+#.-]/', '', strtolower( trim( $language ) ) );
	}    //end _sanitize_language()

	/**
	 * Method to clean up a free text label so that it is safe in a shortcode.
	 *
	 * A shortcode has no escape for the three characters that end an attribute or a
	 * tag, so they are removed. Losing a bracket out of a file label is a visible,
	 * harmless loss; a label which broke out of the shortcode would not be.
	 *
	 * @param string $label Label as the block carried it.
	 *
	 * @return string
	 */
	protected static function _sanitize_label( string $label ): string {

		$label = str_replace( [ '[', ']', '"' ], '', wp_strip_all_tags( $label ) );
		$label = preg_replace( '/\s+/', ' ', $label );

		return trim( (string) $label );

	}    //end _sanitize_label()

}    //end of class


//EOF
