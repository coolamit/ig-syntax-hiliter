<?php

/**
 * iG Syntax Hiliter Front-end Class to handle front-end processing
 * for the plugin, hilite code etc.
 */

class iG_Syntax_Hiliter_Frontend extends iG_Syntax_Hiliter {

	/**
	 * @var obj Contains class instance
	 */
	private static $_instance;

	/**
	 * @var Constant Class constant contains the template for generating tokens for hilited code
	 */
	const token = '<pre class="igsh-token" id="%s"></pre>';
	/**
	 * @var Constant Class constant contains the max length allowed for a file's path
	 */
	const file_path_length = 30;

	/**
	 * @var array Contains hilited code blocks associated to unique tokens
	 */
	protected $__hilited_code = array();

	/**
	 * @var array Contains IDs/labels etc for use in code boxes
	 */
	protected $__code_box = array(
		'counter' => 0,
		'id_prefix' => 'ig-sh-',
		'plain' => 'plain text',
		'html' => 'hilited code',
	);

	/**
	 * @var Array Contains filters on which code hiliting is not allowed & code blocks are to be stripped
	 */
	protected $__no_code_hilite_filters = array(
		'excerpt_save_pre', 'get_the_excerpt', 'the_excerpt', 'the_excerpt_rss'
	);

	/**
	 * @var Array Contains filters on which code hiliting is allowed
	 */
	protected $__code_hilite_filters = array(
		'the_content'
	);

	/**
	 * @var Array Contains filters on which Github Gist is not allowed & code blocks are to be stripped
	 */
	protected $__no_github_gist_filters = array(
		'excerpt_save_pre', 'get_the_excerpt', 'the_excerpt', 'the_excerpt_rss'
	);

	/**
	 * @var Array Contains filters on which Github Gist is allowed
	 */
	protected $__github_gist_filters = array(
		'the_content'
	);

	/**
	 * @var Array Contains file names for GeSHi language files associated with expected tag names
	 */
	protected $__geshi_language = array(
		'as' => 'actionscript',
		'html' => 'html4strict',
		'js' => 'javascript',
	);

	/**
	 * @var Array Contains display names for some languages, like C# for csharp, VB.NET for vbnet
	 */
	protected $__geshi_language_display = array(
		'cpp' => 'C++',
		'cfm' => 'Cold Fusion',
		'csharp' => 'C#',
		'vbnet' => 'VB.NET',
		'as' => 'ActionScript',
		'c_mac' => 'CMac',
		'html' => 'HTML4',
		'html4strict' => 'HTML4',
	);

	/**
	 * private constructor, singleton pattern implemented
	 */
	private function __construct() {
		//init options
		$this->_init_options();

		$this->_build_tags_array();

		//setup our style/script enqueuing for front-end
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_stuff' ) );

		//setup code hilite handling for comments
		if( $this->_get_option( 'hilite_comments' ) == 'yes' ) {
			//gotta hilite
			$this->__code_hilite_filters[] = 'comment_text';
		} else {
			//no hilite in comments
			$this->__no_code_hilite_filters[] = 'comment_text';
		}

		//setup Github Gist embed in comments
		if( $this->_get_option( 'gist_in_comments' ) == 'yes' ) {
			//gotta embed
			$this->__github_gist_filters[] = 'comment_text';
		} else {
			//no embedding Gist in comments
			$this->__no_github_gist_filters[] = 'comment_text';
		}

		//queue up calls for code hiliting
		foreach( $this->__code_hilite_filters as $filter ) {
			//to grab code blocks to be hilited & replace them with tokens
			add_filter( $filter, array( $this, 'parse_shortcodes' ), 2 );
			//replace code block tokens with hilited code
			add_filter( $filter, array( $this, 'add_hilited_code_blocks' ), 100 );
		}

		//queue up calls for stripping out code blocks
		foreach( $this->__no_code_hilite_filters as $filter ) {
			add_filter( $filter, array( $this, 'parse_shortcodes' ), 2 );
		}

		//queue up calls for Github Gist embed
		foreach( $this->__github_gist_filters as $filter ) {
			add_filter( $filter, array( $this, 'parse_github_gist_tags' ), 9 );
		}

		//queue up calls for stripping out Github Gist code blocks
		foreach( $this->__no_github_gist_filters as $filter ) {
			add_filter( $filter, array( $this, 'parse_github_gist_tags' ), 9 );
		}
	}

	/**
	 * function to give the singleton instance if exists or make one
	 */
	public static function get_instance() {
		if( ! is_a( self::$_instance, __CLASS__ ) ) {
			$class_name = __CLASS__;
			self::$_instance = new $class_name();
		}
		return self::$_instance;
	}

	/**
	 * function to enqueue stuff in front-end head
	 */
	public function enqueue_stuff() {
		if( is_admin() ) {
			//page is in wp-admin, so bail out
			return false;
		}

		//load stylesheet
		wp_enqueue_style( self::plugin_id, plugins_url( 'css/front-end.css', __FILE__ ), false, IG_SYNTAX_HILITER_VERSION );
		//load utility lib
		wp_enqueue_script( 'igeek-utils', plugins_url( 'js/igeek-utils.js', __FILE__ ), array(), IG_SYNTAX_HILITER_VERSION );
		//load script
		wp_enqueue_script( self::plugin_id, plugins_url( 'js/front-end.js', __FILE__ ), array( 'igeek-utils', 'jquery' ), IG_SYNTAX_HILITER_VERSION );

		//vars for front-end js
		wp_localize_script( self::plugin_id, 'ig_syntax_hiliter', array(
			'label' => array(
				'plain' => $this->__code_box['plain'],
				'html' => $this->__code_box['html']
			)
		) );
	}

	/**
	 * This function builds the array for shorthand tags for all language files
	 * available in supported directories
	 */
	protected function _build_tags_array() {
		$languages = $this->_get_languages();
		if( empty( $languages ) ) {
			return;
		}

		$keys = array_unique( array_merge( array_keys( $this->__geshi_language ), array_keys( $languages ) ) );

		$tags = array();

		foreach( $keys as $key ) {
			if( array_key_exists( $key, $this->__geshi_language ) ) {
				$tags[$key] = $this->__geshi_language[$key];
				continue;
			}

			if( array_key_exists( $key, $languages ) ) {
				$tags[$key] = $languages[$key];
				continue;
			}
		}

		ksort( $tags );

		$this->__geshi_language = $tags;

		unset( $tags, $keys, $languages );
	}

	/**
	 * This function is used to truncate file path to required length
	 */
	protected function _snip_file_path( $path, $length ) {
		$length = intval( $length );
		$length = ( $length < 0 || $length > self::file_path_length ) ? self::file_path_length : $length;

		if( strlen($path) <= $length ) {
			return $path;
		}

		$path = "&hellip;" . substr( $path, intval( 2 - $length ) );
		return $path;
	}

	/**
	 * This function is used to put hilited code in a presentable box
	 */
	protected function _get_code_in_box( $code, $attrs ) {
		if( empty($code) ) {
			return;
		}

		$code_box = '';
		$this->__code_box['counter']++;

		$code_box .= '<div id="' . esc_attr( $this->__code_box['id_prefix'] ) . $this->__code_box['counter'] . '" class="syntax_hilite">';

		if( $attrs['toolbar'] === true ) {
			$code_box .= '	<div class="toolbar">';
			$code_box .= '		<div class="language-name">' . esc_html( $attrs['language'] ). '</div>';

			if( $attrs['plain_text'] === true ) {
				$code_box .= '		<a href="#" class="view-different">&lt; view <span>' . esc_html( $this->__code_box['plain'] ) . '</span> &gt;</a>';
			}

			if( ! empty( $attrs['file'] ) ) {
				$code_box .= '		<div class="filename" title="' . esc_attr( $attrs['file'] ) . '">' . esc_html( $this->_snip_file_path( $attrs['file'], self::file_path_length ) ) . '</div>';
				$code_box .= '		<br clear="both">';
			}

			$code_box .= '	</div>';
		}

		$code_box .= '	<div class="code">';
		$code_box .= $code;
		$code_box .= '	</div>';
		$code_box .= '</div>';

		return $code_box;
	}

	/**
	 * This function is used to hilite code using Geshi
	 */
	protected function _get_hilited_code( $attrs = array(), $code = '' ) {
		if( ( empty($code) || ! is_string($code) ) ) {
			return;
		}

		extract( shortcode_atts( array(
			'language' => '',
			'firstline' => 1,
			'highlight' => 0,
			'file' => '',
			'gutter' => '',
			'plaintext' => '',
			'toolbar' => '',
			'lang' => 'code',
			'num' => 1,
		), $attrs ) );

		$num = intval( $num );
		$firstline = ( intval( $firstline ) < 1 ) ? 1 : intval( $firstline );
		$firstline = ( $num > $firstline ) ? $num : $firstline;

		$language = ( empty( $language ) ) ? $lang : $language;
		unset( $lang );

		$language = sanitize_title( $language );
		$language_display = ( array_key_exists( $language, $this->__geshi_language_display ) ) ? $this->__geshi_language_display[$language] : $language;
		$language = ( array_key_exists( $language, $this->__geshi_language ) ) ? $this->__geshi_language[$language] : $language;

		$file = strip_tags( $file );
		$gutter = ( ! $this->_is_yesno( $gutter ) ) ? '' : strtolower( trim( $gutter ) );

		$plaintext = ( ! $this->_is_yesno( $plaintext ) ) ? '' : strtolower( trim( $plaintext ) );
		$toolbar = ( ! $this->_is_yesno( $toolbar ) ) ? '' : strtolower( trim( $toolbar ) );

		$code = trim( $code );

		if( strpos( $highlight, ',' ) === false && strpos( $highlight, '-' ) === false ) {
			$highlight = ( intval($highlight) < 0 ) ? 0 : array( intval($highlight) );
		} else {
			$highlight = explode( ',', $highlight );

			$ranges = array();
			foreach( $highlight as $num ) {
				if( strpos( $num, '-' ) === false ) {
					$ranges[] = $num;
					continue;
				}
				$range = explode( '-', $num );
				$range_start = intval( $range[0] );
				$range_end = intval( $range[1] );
				if( $range_end == $range_start ) {
					$ranges[] = $range_start;
					continue;
				} elseif( $range_end < $range_start ) {
					$range_start = intval( $range[1] );
					$range_end = intval( $range[0] );
				}
				$range = range( $range_start, $range_end );
				$ranges = array_merge( $ranges, $range );
				unset( $range_end, $range_start, $range );
			}

			unset( $highlight );
			$highlight = $ranges;
			unset( $ranges );

			$highlight = array_unique( array_map( 'intval', $highlight ) );	//make 'em all int & vaporize duplicates
			sort( $highlight, SORT_NUMERIC );
		}

		$is_language = true;	//assume we have a valid language
		$dir_path = parent::$__dirs['geshi'];		//set default path to our geshi dir

		if( function_exists('file_exists') ) {
			foreach( parent::$__dirs as $key => $dir ) {
				if( file_exists( $dir . '/' . $language . '.php' ) ) {
					$is_language = true;	//language file exists
					$dir_path = $dir;	//set language file dir
					break;
				}

				$is_language = false;	//language file doesn't exist
			}
		}

		if( $is_language !== true ) {
			//we don't have a valid language specified in the tag by user
			//set the code block to be hilited using the 'code' lang file
			$language = 'code';
			$language_display = $language;
		}

		//check whether to display line numbers & for a tag level override (if any)
		if( $this->_get_option( 'show_line_numbers' ) == 'yes' ) {
			$show_line_numbers = true;	//global option is set to show line numbers
			if( $gutter == 'no' ) {
				$show_line_numbers = false;	//local override for this tag block says hide line numbers
			}
		} else {
			$show_line_numbers = false;	//global option is set to hide line numbers
			if( $gutter == 'yes' ) {
				$show_line_numbers = true;	//local override for this tag block says show line numbers
			}
		}

		//check whether to display plain text option & for a tag level override (if any)
		if( $this->_get_option( 'plain_text' ) == 'yes' ) {
			$show_plain_text = true;	//global option is set to show plain text
			if( $plaintext == 'no' ) {
				$show_plain_text = false;	//local override for this tag block says hide plain text
			}
		} else {
			$show_plain_text = false;	//global option is set to hide plain text
			if( $plaintext == 'yes' ) {
				$show_plain_text = true;	//local override for this tag block says show plain text
			}
		}

		//check whether to display the toolbar above code block & for a tag level override (if any)
		if( $this->_get_option( 'toolbar' ) == 'yes' ) {
			$show_toolbar = true;	//global option is set to show toolbar
			if( $toolbar == 'no' ) {
				$show_toolbar = false;	//local override for this tag block says hide toolbar
			}
		} else {
			$show_toolbar = false;	//global option is set to hide toolbar
			if( $toolbar == 'yes' ) {
				$show_toolbar = true;	//local override for this tag block says show toolbar
			}
		}

		$geshi = new GeSHi( $code, $language );

		if( isset($dir_path) && ! empty($dir_path) ) {
			//we have a path to language file
			$geshi->set_language_path( $dir_path );
		}

		$geshi_error = $geshi->error();
		if( ! empty( $geshi_error ) ) {
			return;	//there's GeSHi error, bail from this function
		}

		$geshi->set_header_type( GESHI_HEADER_NONE );	//don't need any wrapper around hilited code, we've our own

		$geshi->enable_line_numbers( GESHI_NORMAL_LINE_NUMBERS );	//show line numbers

		if( $show_line_numbers === false ) {
			$geshi->set_line_style( 'list-style: none;' );	//hide line numbers
		}

		$geshi->start_line_numbers_at( $firstline );	//where to start line numbering from
		$geshi->set_case_keywords( GESHI_CAPS_NO_CHANGE );	//don't mess with our code
		$geshi->set_tab_width( 4 );		//if you don't know this then go stuff your head in sand, coding is not for you!

		if( $this->_get_option( 'link_to_manual' ) == 'yes' ) {
			$geshi->enable_keyword_links( true );	//link to docs
		} else {
			$geshi->enable_keyword_links( false );	//no linking to docs
		}

		if( is_array( $highlight ) ) {
			$geshi->highlight_lines_extra( $highlight );	//show these lines as special
			$geshi->set_highlight_lines_extra_style( 'background-color:#FFFFBC;' );	//set bg color for special lines
		}

		$geshi->enable_strict_mode();

		$hilited_code = $geshi->parse_code();	//get it all

		if( empty($hilited_code) ) {
			return;		//geshi banged up somewhere, we have nothing to show for all the hardwork above :(
		}

		unset( $geshi_error, $geshi, $is_language, $language, $highlight, $firstline );

		return $this->_get_code_in_box( $hilited_code, array(
			'plain_text' => $show_plain_text,
			'toolbar' => $show_toolbar,
			'file' => $file,
			'language' => $language_display,
		) );
	}

	/*
	 * This function is called for parsing a code hiliting tag setup in
	 * $this->parse_shortcodes().
	 * It hiltes the code, generates a unique token for the code, stores hilited
	 * code in $this->__hilited_code with token as key and returns the token
	 * to replace the tag in content which can later be replaced with
	 * the hilited code.
	 */
	public function tokenize_code_block( $atts, $code = '', $tag = false ) {
		if( empty( $tag ) || empty( $code ) ) {
			return $code;	//nothing to do, bail out
		}

		//check if we've to strip code block
		if( in_array( current_filter(), $this->__no_code_hilite_filters ) ) {
			return '';	//code hiliting not allowed for this filter, so remove code block
		}

		if( array_key_exists( $tag, $this->__geshi_language ) ) {
			$atts['language'] = $tag;	//shorthand tag used, so tag name is language
		}

		ksort( $atts );	//sort attribute array on keys

		$shortcode_md5 = md5( serialize( array(
			'text' => $code,
			'atts' => $atts
		) ) );	//create unique token key for this code block with these attributes

		if( ! array_key_exists( $shortcode_md5, $this->__hilited_code ) ) {
			$this->__hilited_code[$shortcode_md5] = $this->_get_hilited_code( $atts, $code );	//save hilited code in array

			if( empty( $this->__hilited_code[$shortcode_md5] ) ) {
				//banged up somewhere, we didn't get anything, unset key in array & return empty
				unset( $this->__hilited_code[$shortcode_md5] );
				return;
			}
		}

		$shortcode_token = sprintf( self::token, $shortcode_md5 );	//generate token

		unset( $shortcode_md5 );

		return $shortcode_token;	//return unique token, we'll replace it with hilited code later
	}

	/**
	 * This function is called back by a filter early on and sets up shortcode
	 * processing for code hiliting tags
	 */
	public function parse_shortcodes( $content ) {
		if( is_admin() ) {
			return $content;
		}

		global $shortcode_tags;

		//keep a copy of the registered shortcodes as we'd need to restore them later
		$original_shortcode_tags = $shortcode_tags;

		remove_all_shortcodes();	//clean the slate, we want only our shortcodes to be processed right now

		$tags = array_merge( array_keys( $this->__geshi_language ), array( 'sourcecode' ) );

		foreach( $tags as $tag ) {
			add_shortcode( $tag, array( $this, 'tokenize_code_block' ) );
		}

		$content = do_shortcode( $content );	//parse our shortcodes

		$shortcode_tags = $original_shortcode_tags;	//restore original shortcodes

		unset( $tags, $original_shortcode_tags );

		return $content;
	}

	/**
	 * This function is called back by a filter in the last and replaces the unique
	 * tokens with corresponding blocks of hilited code stored in $this->__hilited_code
	 */
	public function add_hilited_code_blocks( $content ) {
		if( is_admin() || empty( $this->__hilited_code ) ) {
			return $content;
		}

		/*
		 * Run a loop on $this->__hilited_code and replace all tokens in $content
		 * with appropriate blocks of hilited code.
		 */
		foreach( $this->__hilited_code as $key => $code_block ) {
			$token = sprintf( self::token, $key );
			$content = str_replace( $token, $code_block, $content );
		}

		return $content;
	}

	/**
	 * This function is called on a filter and it sets up [github] shortcode parsing
	 */
	public function parse_github_gist_tags( $content ) {
		if( is_admin() ) {
			return $content;
		}

		global $shortcode_tags;

		//keep a copy of the registered shortcodes as we'd need to restore them later
		$original_shortcode_tags = $shortcode_tags;

		remove_all_shortcodes();	//clean the slate, we want only our shortcodes to be processed right now

		//setup shortcode for github gist
		add_shortcode( 'github', array( $this, 'parse_github_gist_tag' ) );

		$content = do_shortcode( $content );	//parse our shortcodes

		$shortcode_tags = $original_shortcode_tags;	//restore original shortcodes

		unset( $original_shortcode_tags );

		return $content;
	}

	/**
	 * This function handles the [github] tag
	 */
	public function parse_github_gist_tag( $attrs, $content = '' ) {
		extract( shortcode_atts( array(
			'id' => 0,
			'gist' => '',
		), $attrs ) );

		$gist = esc_url( $gist );

		if( ! empty( $gist ) ) {
			//gist attr takes priority
			$gist = rtrim( $gist, '/' );
			$arr_gist_url = explode( '/', $gist );

			$gist_id = array_pop( $arr_gist_url );

			if( ! empty( $gist_id ) ) {
				$id = $gist_id;
			}

			unset( $gist_id, $arr_gist_url );
		}

		if( empty( $id ) ) {
			return;
		}

		$id = sanitize_user( $id, true );	//since ID can only be alphanumeric
		$gist = esc_url( 'https://gist.github.com/' . $id );

		$returnable = '<script src="' . $gist . '.js"></script>';

		if( in_array( current_filter(), $this->__no_github_gist_filters ) ) {
			$returnable = 'Github Gist: <a href="' . $gist . '" rel="nofollow">' . $gist . '</a>';
		}

		return $returnable;
	}

//end of class
}


//EOF
