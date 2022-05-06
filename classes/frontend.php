<?php
/**
 * iG Syntax Hiliter Front-end Class to handle front-end processing
 * for the plugin, hilite code etc.
 *
 * @author Amit Gupta <https://amitgupta.in/>
 */

namespace iG\Syntax_Hiliter;

use \GeSHi;

class Frontend extends Base {

	/**
	 * @var const Class constant contains the template for generating tokens for hilited code
	 */
	const TOKEN = '<pre class="igsh-token" id="%s"></pre>';

	/**
	 * @var const Class constant contains the max length allowed for a file's path
	 */
	const FILE_PATH_LENGTH = 30;

	/**
	 * @var array Contains hilited code blocks associated to unique tokens
	 */
	protected $__hilited_code = [];

	/**
	 * @var array Contains IDs/labels etc for use in code boxes
	 */
	protected $__code_box = [
		'counter'   => 0,
		'id_prefix' => 'ig-sh-',
		'plain'     => 'plain text',
		'html'      => 'hilited code',
	];

	/**
	 * @var array Contains filters on which code hiliting is not allowed & code blocks are to be stripped
	 */
	protected $__no_code_hilite_filters = [
		'excerpt_save_pre',
		'get_the_excerpt',
		'the_excerpt',
		'the_excerpt_rss',
	];

	/**
	 * @var array Contains filters on which code hiliting is allowed
	 */
	protected $__code_hilite_filters = [
		'the_content',
	];

	/**
	 * @var array Contains filters on which Github Gist is not allowed & code blocks are to be stripped
	 */
	protected $__no_github_gist_filters = [
		'excerpt_save_pre',
		'get_the_excerpt',
		'the_excerpt',
		'the_excerpt_rss',
	];

	/**
	 * @var array Contains filters on which Github Gist is allowed
	 */
	protected $__github_gist_filters = [
		'the_content',
	];

	/**
	 * @var array Contains file names for GeSHi language files associated with expected tag names
	 */
	protected $__languages = [
		'as'   => 'actionscript',
		'html' => 'html4strict',
		'js'   => 'javascript',
	];

	/**
	 * @var array Contains display names for some languages, like C# for csharp, VB.NET for vbnet
	 */
	protected $__language_display_names = [
		'cpp'         => 'C++',
		'cfm'         => 'Cold Fusion',
		'csharp'      => 'C#',
		'vbnet'       => 'VB.NET',
		'as'          => 'ActionScript',
		'c_mac'       => 'CMac',
		'html'        => 'HTML',
		'html4strict' => 'HTML4',
	];

	/**
	 * @var bool Flag to determine whether CSS & JS assets are to be enqueued or not
	 */
	protected $__enqueue_assets = false;

	/**
	 * @var bool Flag to determine whether JS assets are to be enqueued or not
	 */
	protected $__enqueue_js_assets = false;

	/**
	 * Class constructor
	 */
	protected function __construct() {

		parent::__construct();

		$this->_build_tags_array();
		$this->_determine_filters();

		$this->_setup_hooks();

	}

	/**
	 * Method to separate out filters on which hiliting of code is to be set up or not
	 *
	 * @return void
	 */
	protected function _determine_filters() : void {

		//setup code hilite handling for comments
		if ( $this->_option->get( 'hilite_comments' ) === 'yes' ) {
			//gotta hilite
			$this->__code_hilite_filters[] = 'comment_text';
		} else {
			//no hilite in comments
			$this->__no_code_hilite_filters[] = 'comment_text';
		}

		//setup Github Gist embed in comments
		if ( $this->_option->get( 'gist_in_comments' ) === 'yes' ) {
			//gotta embed
			$this->__github_gist_filters[] = 'comment_text';
		} else {
			//no embedding Gist in comments
			$this->__no_github_gist_filters[] = 'comment_text';
		}

	}

	/**
	 * Method to set up listeners to WP hooks
	 *
	 * @return void
	 */
	protected function _setup_hooks() : void {

		// set on 'wp_footer' hook because we won't know whether to enqueue assets
		// or not before post content is parsed
		add_action( 'wp_footer', [ $this, 'enqueue_stuff' ], 1 );

		//queue up calls for code hiliting
		foreach ( $this->__code_hilite_filters as $filter ) {

			//to grab code blocks to be hilited & replace them with tokens
			add_filter( $filter, [ $this, 'parse_shortcodes' ], 3 );

			//replace code block tokens with hilited code
			add_filter( $filter, [ $this, 'replace_tokens_with_code_blocks' ], 99 );

		}

		//queue up calls for stripping out code blocks
		foreach ( $this->__no_code_hilite_filters as $filter ) {
			add_filter( $filter, [ $this, 'parse_shortcodes' ], 2 );
		}

		//queue up calls for Github Gist embed
		foreach ( $this->__github_gist_filters as $filter ) {
			add_filter( $filter, [ $this, 'parse_github_shortcodes' ], 10 );
		}

		//queue up calls for stripping out Github Gist code blocks
		foreach ( $this->__no_github_gist_filters as $filter ) {
			add_filter( $filter, [ $this, 'parse_github_shortcodes' ], 9 );
		}

	}    //end _setup_hooks()

	/**
	 * Method to enqueue assets on front-end
	 *
	 * @return void
	 */
	public function enqueue_stuff() : void {

		if ( is_admin() || ! $this->__enqueue_assets ) {
			// page is in wp-admin or it has no code blocks to hilite
			// bail out
			return;
		}

		if ( $this->_option->get( 'fe-styles' ) === 'yes' ) {
			//load stylesheet
			wp_enqueue_style(
				parent::PLUGIN_ID,
				Helper::get_asset_url( '/css/front-end.css' ),
				false,
				IG_SYNTAX_HILITER_VERSION
			);
		}

		if ( true === $this->__enqueue_js_assets ) {

			//load utility lib
			wp_enqueue_script(
				'igeek-utils',
				Helper::get_asset_url( '/js/igeek-utils.js' ),
				[],
				IG_SYNTAX_HILITER_VERSION
			);

			//load script
			wp_enqueue_script(
				parent::PLUGIN_ID,
				Helper::get_asset_url( '/js/front-end.js' ),
				[ 'igeek-utils', 'jquery' ],
				IG_SYNTAX_HILITER_VERSION
			);

			//vars for front-end js
			wp_localize_script(
				parent::PLUGIN_ID,
				'ig_syntax_hiliter',
				[
					'label' => [
						'plain' => $this->__code_box['plain'],
						'html'  => $this->__code_box['html'],
					],
				]
			);

		}

	}

	/**
	 * Method to build the array for shorthand tags for all language files
	 * available in supported directories
	 *
	 * @return void
	 *
	 * @throws \ErrorException
	 */
	protected function _build_tags_array() : void {

		$languages = $this->get_languages();

		if ( empty( $languages ) ) {
			return;
		}

		$keys = array_unique(
			array_merge(
				array_keys( $this->__languages ),
				array_keys( $languages )
			)
		);

		$tags = [];

		foreach( $keys as $key ) {

			if ( array_key_exists( $key, $this->__languages ) ) {
				$tags[ $key ] = $this->__languages[ $key ];
				continue;
			}

			if ( array_key_exists( $key, $languages ) ) {
				$tags[ $key ] = $languages[ $key ];
				continue;
			}

		}

		ksort( $tags );

		$this->__languages = $tags;

	}

	/**
	 * Method to truncate file path to required length
	 *
	 * @param string $path   Path to file
	 * @param int    $length Length in number of characters
	 *
	 * @return string Truncated file path
	 */
	protected function _snip_file_path( string $path, int $length ) : string {

		$length = abs( $length );
		$length = min( self::FILE_PATH_LENGTH, $length );

		if ( strlen( $path ) <= $length ) {
			return $path;
		}

		$path = sprintf(
			'&hellip;%s',
			substr( $path, ( 2 - $length ) )
		);

		return $path;

	}

	/**
	 * Method to put hilited code in a presentable box
	 *
	 * @param string $code
	 * @param array  $attrs
	 *
	 * @return string
	 *
	 * @throws \ErrorException
	 */
	protected function _get_code_in_box( string $code, array $attrs = [] ) : string {

		if ( empty( $code ) ) {
			return '';
		}

		$attrs['file'] = ( empty( $attrs['file'] ) ) ? '' : $attrs['file'];

		$this->__code_box['counter']++;

		return Helper::render_template(
			sprintf( '%s/templates/frontend-code-box.php', untrailingslashit( IG_SYNTAX_HILITER_ROOT ) ),
			[
				'id_prefix'  => $this->__code_box['id_prefix'],
				'counter'    => $this->__code_box['counter'],
				'plain_text' => $this->__code_box['plain'],
				'file_path'  => $this->_snip_file_path( $attrs['file'], self::FILE_PATH_LENGTH ),
				'attrs'      => $attrs,
				'code'       => $code,
			]
		);

	}

	/**
	 * Method to hilite code using Geshi and get the HTML
	 *
	 * @param array  $attrs
	 * @param string $code
	 *
	 * @return string
	 *
	 * @throws \ErrorException
	 */
	protected function _get_hilited_code( array $attrs = [], string $code = '' ) : string {

		if ( empty( $code ) ) {
			return '';
		}

		extract(
			shortcode_atts(
				[
					'strict_mode' => '',
					'language'    => 'code',
					'firstline'   => 1,
					'highlight'   => 0,
					'file'        => '',
					'gutter'      => '',
					'plaintext'   => '',
					'toolbar'     => '',
					'lang'        => 'code',
					'num'         => 1,
				],
				$attrs
			)
		);

		$num       = absint( $num );
		$firstline = absint( $firstline );
		$firstline = max( 1, $num, $firstline );
		$language  = ( empty( $language ) && ! empty( $lang ) ) ? $lang : $language;

		unset( $lang );

		$language         = sanitize_title( $language );
		$language_display = $this->__language_display_names[ $language ] ?? $language;
		$language         = $this->__languages[ $language ] ?? $language;

		$non_strict_mode_languages = $this->_option->get( 'non_strict_mode' );

		if ( ! empty( $strict_mode ) ) {
			$strict_mode = strtolower( trim( $strict_mode ) );
		} elseif ( in_array( $language, $non_strict_mode_languages, true ) ) {
			$strict_mode = 'never';
		} else {
			$strict_mode = $this->_option->get( 'strict_mode' );
		}

		switch ( $strict_mode ) {
			case 'always':
				$geshi_mode = GESHI_ALWAYS;
				break;
			case 'never':
				$geshi_mode = GESHI_NEVER;
				break;
			case 'maybe':
			default:
				$geshi_mode = GESHI_MAYBE;
				break;
		}

		unset( $strict_mode );

		$file      = sanitize_text_field( wp_strip_all_tags( $file ) );
		$gutter    = ( ! $this->_validate->is_yesno( $gutter ) ) ? '' : strtolower( trim( $gutter ) );
		$plaintext = ( ! $this->_validate->is_yesno( $plaintext ) ) ? '' : strtolower( trim( $plaintext ) );
		$toolbar   = ( ! $this->_validate->is_yesno( $toolbar ) ) ? '' : strtolower( trim( $toolbar ) );

		$code = trim( $code );

		if ( strpos( $highlight, ',' ) === false && strpos( $highlight, '-' ) === false ) {

			$highlight = [ absint( $highlight ) ];

		} else {

			$highlight = explode( ',', $highlight );
			$ranges    = [];

			foreach ( $highlight as $num ) {

				if ( strpos( $num, '-' ) === false ) {
					$ranges[] = absint( $num );
					continue;
				}

				$range       = explode( '-', $num );
				$range_start = absint( $range[0] );
				$range_end   = absint( $range[1] );

				if ( $range_end === $range_start ) {
					$ranges[] = $range_start;
					continue;
				} elseif ( $range_end < $range_start ) {
					$range_start = absint( $range[1] );
					$range_end   = absint( $range[0] );
				}

				$range  = range( $range_start, $range_end );
				$ranges = array_merge( $ranges, $range );

				unset( $range_end, $range_start, $range );

			}

			unset( $highlight );

			$highlight = $ranges;

			unset( $ranges );

			//make 'em all int & vaporize duplicates
			$highlight = array_unique( array_map( 'intval', $highlight ) );

			sort( $highlight, SORT_NUMERIC );

		}

		$is_language = true;    //assume we have a valid language
		$dir_path    = $this->__dirs['geshi'];    //set default path to our geshi dir

		foreach ( $this->__dirs as $key => $dir ) {

			$language_file_path = sprintf(
				'%s/%s.php',
				untrailingslashit( $dir ),
				$language
			);

			if ( Helper::is_file_path_valid( $language_file_path ) ) {
				$is_language = true;    //language file exists
				$dir_path    = $dir;    //set language file dir
				break;
			}

			$is_language = false;    //language file doesn't exist

		}

		if ( true !== $is_language ) {
			//we don't have a valid language specified in the tag by user
			//set the code block to be hilited using the 'code' lang file
			$language         = 'code';
			$language_display = $language;
		}

		$options = [
			'show_line_numbers' => Helper::yesno_to_bool( $this->_option->get( 'show_line_numbers' ) ),
			'show_plain_text'   => Helper::yesno_to_bool( $this->_option->get( 'plain_text' ) ),
			'show_toolbar'      => Helper::yesno_to_bool( $this->_option->get( 'toolbar' ) ),
		];

		/*
		 * Override global options with values set in shortcode attributes
		 */
		if ( $this->_validate->is_yesno( $gutter ) ) {
			$options['show_line_numbers'] = Helper::yesno_to_bool( $gutter );
		}

		if ( $this->_validate->is_yesno( $plaintext ) ) {
			$options['show_plain_text'] = Helper::yesno_to_bool( $plaintext );
		}

		if ( $this->_validate->is_yesno( $toolbar ) ) {
			$options['show_toolbar'] = Helper::yesno_to_bool( $toolbar );
		}

		/*
		 * Initialize GeSHi
		 */
		$geshi = new GeSHi( $code, $language );

		if ( ! empty( $dir_path ) ) {
			//we have a path to language file
			$geshi->set_language_path( $dir_path );
		}

		$geshi_error = $geshi->error();

		if ( ! empty( $geshi_error ) ) {
			return '';    //there's GeSHi error, bail out
		}

		$geshi->set_header_type( GESHI_HEADER_NONE );    //don't need any wrapper around hilited code, we have our own
		$geshi->enable_line_numbers( GESHI_NORMAL_LINE_NUMBERS );    //show line numbers

		if ( false === $options['show_line_numbers'] ) {

			// hide line numbers
			// doing it this way so that line hiliting still works, otherwise it would not
			$geshi->set_line_style( 'list-style: none;' );

		}

		$geshi->start_line_numbers_at( $firstline );    //where to start line numbering from
		$geshi->set_case_keywords( GESHI_CAPS_NO_CHANGE );    //don't mess with our code
		$geshi->set_tab_width( 4 );    //if you don't know this then go stuff your head in sand, coding is not for you!

		$geshi->enable_keyword_links(
			Helper::yesno_to_bool(
				$this->_option->get( 'link_to_manual' )
			)
		);

		$geshi->highlight_lines_extra( $highlight );    //show these lines as special
		$geshi->set_highlight_lines_extra_style( 'background-color:#FFFFBC;' );    //set bg color for special lines

		$geshi->enable_strict_mode( $geshi_mode );

		$hilited_code = $geshi->parse_code();    //get it all

		if ( empty( $hilited_code ) ) {
			return '';    //geshi banged up somewhere, we have nothing to show for all the hardwork above :(
		}

		unset( $geshi_error, $geshi, $is_language, $language, $highlight, $firstline, $geshi_mode );

		/*
		 * If $__enqueue_js_assets is set to TRUE even once we don't want
		 * to set it to FALSE again.
		 */
		if ( true === $options['show_plain_text'] ) {
			$this->__enqueue_js_assets = $options['show_plain_text'];
		}

		return $this->_get_code_in_box(
			$hilited_code,
			[
				'plain_text' => $options['show_plain_text'],
				'toolbar'    => $options['show_toolbar'],
				'file'       => $file,
				'language'   => $language_display,
			]
		);

	}    //end _get_hilited_code()

	/**
	 * Method to parse code hiliting tag set up in self::parse_shortcodes().
	 * It hiltes the code, generates a unique token for the hilited code, stores
	 * hilited code in a class var with token as key and returns the token
	 * to replace the tag in content which can later be replaced with
	 * the hilited code.
	 *
	 * @param array  $atts
	 * @param string $code
	 * @param string $tag
	 *
	 * @return string
	 *
	 * @throws \ErrorException
	 */
	public function replace_code_blocks_with_tokens( array $atts, string $code = '', string $tag = '' ) : string {

		if ( empty( $tag ) || empty( $code ) ) {
			return $code;    //nothing to do, bail out
		}

		//check if we've to strip code block
		if ( in_array( current_filter(), $this->__no_code_hilite_filters, true ) ) {
			return '';    //code hiliting not allowed for this filter, so remove code block
		}

		if ( ! empty( $this->__languages[ $tag ] ) ) {
			$atts['language'] = $tag;    //shorthand tag used, so tag name is language
		}

		ksort( $atts );    //sort attribute array on keys

		$shortcode_md5 = md5(    //create unique token key for this code block with these attributes
			wp_json_encode(
				[
					'text' => $code,
					'atts' => $atts,
				]
			)
		);

		if ( empty( $this->__hilited_code[ $shortcode_md5 ] ) ) {

			// save hilited code in array
			$this->__hilited_code[ $shortcode_md5 ] = $this->_get_hilited_code( $atts, $code );

			if ( empty( $this->__hilited_code[ $shortcode_md5 ] ) ) {

				// messed up somewhere, we didn't get anything
				// unset key in array and return empty
				unset( $this->__hilited_code[ $shortcode_md5 ] );

				return '';

			}

		}

		$shortcode_token = sprintf( self::TOKEN, $shortcode_md5 );    //generate token HTML

		unset( $shortcode_md5 );

		$this->__enqueue_assets = true;    //yes we should enqueue CSS/JS assets, we have hilited code on page

		// return unique token, we'll replace it with hilited code later
		return $shortcode_token;

	}    //end replace_code_blocks_with_tokens()

	/**
	 * Method called back by a filter early on and sets up shortcode
	 * processing for code hiliting tags
	 *
	 * @param string $content
	 *
	 * @return string
	 */
	public function parse_shortcodes( string $content= '' ) : string {

		if ( is_admin() ) {
			return $content;
		}

		global $shortcode_tags;

		// keep a copy of the registered shortcodes as we need to restore them later
		$original_shortcode_tags = $shortcode_tags;

		remove_all_shortcodes();    //clean the slate, we want only our shortcodes to be processed right now

		$tags = array_merge( array_keys( $this->__languages ), [ 'sourcecode' ] );

		foreach ( $tags as $tag ) {
			add_shortcode( $tag, [ $this, 'replace_code_blocks_with_tokens' ] );
		}

		$content = do_shortcode( $content );    //parse our shortcodes

		$shortcode_tags = $original_shortcode_tags;    //restore original shortcodes

		unset( $tags, $original_shortcode_tags );

		return $content;

	}

	/**
	 * Method, called back by a filter towards the end, to replace code hilite
	 * tokens with corresponding blocks of hilited code
	 *
	 * @param string $content
	 *
	 * @return string
	 */
	public function replace_tokens_with_code_blocks( string $content ) : string {

		if ( is_admin() || empty( $this->__hilited_code ) ) {
			return $content;
		}

		/*
		 * Loop over our tokens array and replace all tokens
		 * with appropriate blocks of hilited code
		 */
		foreach ( $this->__hilited_code as $key => $code_block ) {

			$token   = sprintf( self::TOKEN, $key );
			$content = str_replace( $token, $code_block, $content );

		}

		return $content;

	}

	/**
	 * Method, called on a filter, to set up [github] shortcode parsing
	 *
	 * @param string $content
	 *
	 * @return string
	 */
	public function parse_github_shortcodes( string $content ) : string {

		if ( is_admin() ) {
			return $content;
		}

		global $shortcode_tags;

		// keep a copy of the registered shortcodes as we need to restore them later
		$original_shortcode_tags = $shortcode_tags;

		remove_all_shortcodes();    //clean the slate, we want only our shortcodes to be processed right now

		// setup shortcode for github gist
		add_shortcode( 'github', [ $this, 'get_github_gist_embed' ] );

		$content        = do_shortcode( $content );    //parse our shortcodes
		$shortcode_tags = $original_shortcode_tags;    //restore original shortcodes

		unset( $original_shortcode_tags );

		return $content;

	}

	/**
	 * Method to return Github Gist embed
	 *
	 * @param array  $attrs
	 * @param string $content
	 *
	 * @return string
	 */
	public function get_github_gist_embed( array $attrs, string $content = '' ) : string {

		extract(
			shortcode_atts(
				[
					'id'   => 0,
					'gist' => '',
				],
				$attrs
			)
		);

		$gist = wp_parse_url( untrailingslashit( $gist ), PHP_URL_PATH );

		if ( ! empty( $gist ) ) {

			//gist attr takes priority
			$gist_url_parts = explode( '/', $gist );

			$gist_id = array_pop( $gist_url_parts );

			if ( ! empty( $gist_id ) ) {
				$id = $gist_id;
			}

			unset( $gist_id, $gist_url_parts );

		}

		if ( empty( $id ) ) {
			return '';
		}

		$gist = sprintf(
			'https://gist.github.com/%s',
			sanitize_user( $id, true )
		);

		$returnable = sprintf( '<script src="%s.js"></script>', esc_url( $gist ) );

		if ( in_array( current_filter(), $this->__no_github_gist_filters, true ) ) {

			$returnable = sprintf(
				'<div class="igsh-gist"><span class="igsh-gist__label">Github Gist:</span> <a href="%s" rel="nofollow">%s</a></div>',
				esc_url( $gist ),
				esc_html( $gist )
			);

		}

		return $returnable;

	}    //end get_github_gist_embed()

}    //end of class

//EOF
