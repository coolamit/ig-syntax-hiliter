<?php
/*************************************************************************************
 * code.php
 * -------
 * Author: Amit Gupta (http://blog.igeek.info/)
 * Copyright: (c) 2004-2012 Amit Gupta (http://blog.igeek.info/)
 * Release Version: 1.2
 * Date Started: 2004/11/09
 * Last Modified: 2012/08/22
 *
 * CODE language file for iG:Syntax Hiliter.
 * Its just a dummy file to take advantage of GeSHi Line Numbering & indenting
 * for formatting plain CODE in iG:Syntax Hiliter Plugin
 *
 * NOTICE:-
 * -------------------------------------------------------------------------------
 * This Lang File is not distributed with GeSHi. This Lang File is only 
 * distributed with iG:Syntax Hiliter
 * -------------------------------------------------------------------------------
 *
 * CHANGES
 * -------
 * 2012/08/22 (1.2)
 *   -  Made structural changes to match the current GeSHi(v1.0.8.11) language file structure
 * 2005/06/29 (1.1)
 *   -  Made some structural changes to match the current GeSHi(v1.0.7) language file structure
 * 2004/11/09 (1.0)
 *  -  First Release
 *
 *************************************************************************************
 *
 *     This file is part of iG:Syntax Hiliter
 *     (http://blog.igeek.info/wp-plugins/igsyntax-hiliter/)
 *
 *   iG:Syntax Hiliter is a plugin for WordPress, a state-of-the-art semantic publishing platform.
 *   The plugin & all files under it has been licensed under GNU GPL,
 *   which can be found at http://www.gnu.org/copyleft/gpl.html, unless otherwise stated.
 *   You are free to modify or distribute it as long as you give me credit for original code & keep my
 *   copyright notices intact. The script or application you build using this file or plugin has to be licensed
 *   under a license similar to GNU GPL.
 *
 *   This file & the whole iG:Syntax Hiliter package is distributed on an AS IS BASIS
 *   WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 *   FITNESS FOR A PARTICULAR PURPOSE.
 *
 ************************************************************************************/

$language_data = array (
	'LANG_NAME' => 'CODE',
	'COMMENT_SINGLE' => array(1 =>'//'),
	'COMMENT_MULTI' => array('/*' => '*/'),
	'CASE_KEYWORDS' => GESHI_CAPS_NO_CHANGE,
	'QUOTEMARKS' => array("'", '"'),
	'ESCAPE_CHAR' => '\\',
	'KEYWORDS' => array(),
	'CASE_SENSITIVE' => array(
        GESHI_COMMENTS => false,
		1 => false
	),
	'STYLES' => array(
		'KEYWORDS' => array(),
		'COMMENTS' => array(
            1 => 'color: #666666; font-style: italic;',
            'MULTI' => 'color: #666666; font-style: italic;'
		),
		'ESCAPE_CHAR' => array(
            0 => 'color: #000099; font-weight: bold;',
		),
		'BRACKETS' => array(
			0 => 'color:#006600; font-weight:bold;'
		),
		'STRINGS' => array(
			0 => 'color:#CC0000;'
		),
		'NUMBERS' => array(
			0 => 'color:#800000;'
		),
		'METHODS' => array(),
		'SYMBOLS' => array(
			0 => 'color:#006600; font-weight:bold;'
		),
		'REGEXPS' => array(),
		'SCRIPT' => array(),
	),
	'URLS' => array(),
	'OOLANG' => true,
	'OBJECT_SPLITTERS' => array(
		1 => '.',
		2 => '-&gt;',
		3 => '::'
	),
	'REGEXPS' => array(),
	'STRICT_MODE_APPLIES' => GESHI_MAYBE,
	'SCRIPT_DELIMITERS' => array(
		0 => array(
			'<%' => '%>'
		),
		1 => array(
			'<?php' => '?>'
		),
		2 => array(
			'<?' => '?>'
		),
		3 => array(
			'<script language="vbscript" runat="server">' => '</script>'
		),
		4 => array(
			'<script language="javascript" runat="server">' => '</script>'
		)
	),
	'HIGHLIGHT_STRICT_BLOCK' => array(
		0 => true,
		1 => true,
		2 => true,
	),
    'TAB_WIDTH' => 4
);


//EOF