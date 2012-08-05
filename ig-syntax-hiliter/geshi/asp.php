<?php
/*************************************************************************************
 * asp.php
 * --------
 * Author: Amit Gupta (http://blog.igeek.info/)
 * Copyright: (c) 2004-2005 Amit Gupta (http://blog.igeek.info/)
 * Release Version: 2.0.1
 * Date Started: 2004/08/13
 * Last Modified: 2006/02/17
 *
 * ASP language file for iG:Syntax Hiliter
 *
 * Acknowledgements:-
 * -------------------------------------------------------------------------
 * Nigel McNie(http://qbnz.com/highlighter/) for GeSHi, on which
 * iG:Syntax Hiliter is built & for improvements in the ASP Lang
 * File distributed with GeSHi, which have been implemented in this file.
 * -------------------------------------------------------------------------
 *
 * NOTICE:-
 * -------------------------------------------------------------------------------
 * This Lang File is not the same as that distributed with GeSHi. While I
 * created that Lang File too, this Lang File distributed with iG:Syntax Hiliter
 * will be the latest in terms of keywords etc. Any syntax or structure changes
 * made in the GeSHi Lang Files will be implemented in the next version of this
 * Lang File but in terms of keyword hiliting etc, this is the latest from me.
 * -------------------------------------------------------------------------------
 *
 * CHANGES
 * -------
 * 2006/02/17 (2.0.1)
 *   -  Some minor changes in structure
 * 2005/06/29 (2.0)
 *   -  Added more Keywords, VBScript is now fully supported
 *   -  Made some structural changes to match the current GeSHi(v1.0.7) language file structure
 * 2004/11/11 (1.1)
 *   -  Added more Keywords
 *   -  Changed the category of some old keywords for better hiliting
 *   -  Changed the styles to make them more effective
 *   -  Changed the structure of the Lang File to reflect the changes in GeSHI v1.0.2
 * 2004/08/13 (1.0.0)
 *   -  First Release
 *
 * TODO (updated 2006/02/17)
 * -------------------------
 * * Add JScript Keywords, as at present this ASP Language file supports VBScript only
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
	'LANG_NAME' => 'ASP',
	'COMMENT_SINGLE' => array(1 => "'", 2 => '//'),
	'COMMENT_MULTI' => array('/*' => '*/'),
	'CASE_KEYWORDS' => 0,
	'QUOTEMARKS' => array('"'),
	'ESCAPE_CHAR' => '\\',
	'KEYWORDS' => array(
		1 => array(
			'include', 'file', 'ByVal', 'ByRef', 'Private', 'Public', 'Exit', 'Resume', 'On',
			'Request', 'Response', 'Server', 'ADODB', 'Session', 'Application', 'Long', 'Integer', 'Byte',
			'Get', 'Int', 'CInt', 'CBool', 'CDate', 'CByte', 'CCur', 'CDbl', 'CLng', 'CSng',
			'CStr', 'Fix', 'Is', 'Sgn', 'Const', 'String', 'Boolean', 'Currency', 'Me', 'Single',
			'Variant', 'Double', 'To', 'Let', 'Xor', 'Error', 'Imp', 'GoTo', 'Call', 'Global',
			'Set', 'Select', 'End', 'If', 'Then', 'Else', 'ElseIf', 'Case', 'With', 'Until',
			'Not', 'While', 'Wend', 'For', 'Loop', 'Step', 'Do', 'Each', 'In', 'Next', 'Chr',
			'DateSerial', 'DateValue', 'Hex', 'Oct', 'FormatCurrency', 'TypeName',
			'IsArray', 'IsDate', 'IsEmpty', 'IsNull', 'IsNumeric', 'IsObject', 'FormatDateTime',
			'DateAdd', 'DateDiff', 'DatePart', 'Round', 'Atn', 'Cos', 'Sec', 'Cosec', 'Sin',
			'Cotan', 'Tan', 'Sqr', 'Arcsin', 'Arccos', 'Arcsec', 'Arccosec', 'Arccotan',
			'HSin', 'HCos', 'HTan', 'HSec', 'HCosec', 'HCotan', 'HArcsin', 'HArccos', 'HArcsec',
			'HArccosec', 'HArccotan', 'HArctan', 'Log', 'LogN', 'Trim', 'LTrim', 'RTrim', 'Exp',
			'Err', 'Eval', 'GetRef', 'Filter', 'FormatNumber', 'FormatPercent', 'Rnd', 'Randomize',
			'InStr', 'Now', 'Day', 'Month', 'Hour', 'Minute', 'Second', 'Year', 'MonthName', 'LCase',
			'UCase', 'Abs', 'Len', 'InStrRev', 'LBound', 'UBound', 'Join', 'Split', 'Left', 'Right', 'Mid',
			'RegExp', 'Replace', 'Array', 'StrComp', 'StrReverse', 'Weekday'
			),
		2 => array(
			'Null', 'Nothing', 'And', 'Dim', 'ReDim', 'As', 'vbCrLf', 'vbNewLine',
			'Option', 'Explicit', 'Implicit', 'Preserve',
			'False', '&lt;%', '%&gt;',
			'&lt;script language=', '&lt;/script&gt;',
			'True', 'var', 'Or', 'BOF', 'EOF', 'Mod', 'Eqv',
			'Function', 'Class', 'New', 'Sub', 'Erase', 'Rem'
			),
		3 => array(
			'CreateObject', 'Write', 'Redirect', 'Cookies', 'BinaryRead', 'ClientCertificate', 'Form', 'QueryString',
			'ServerVariables', 'TotalBytes', 'AddHeader', 'AppendToLog', 'BinaryWrite', 'Buffer', 'CacheControl',
			'Charset', 'Clear', 'ContentType', 'Expires', 'ExpiresAbsolute', 'Flush', 'IsClientConnected',
			'PICS', 'Status', 'Connection', 'Recordset', 'Execute', 'Abandon', 'Lock', 'UnLock', 'Command', 'Fields',
			'Properties', 'Property', 'Send', 'MoveFirst', 'MoveLast', 'MovePrevious',
			'MoveNext', 'Transfer', 'Open', 'Close', 'MapPath', 'FolderExists', 'FileExists', 'OpenTextFile', 'ReadAll',
			'Copy', 'CopyFile', 'CopyFolder', 'Count', 'CreateFolder', 'CreateTextFile', 'DateCreated', 'GetObject',
			'DateLastAccessed', 'DateLastModified', 'Delete', 'DeleteFile', 'DeleteFolder', 'Description', 'Add',
			'CompareMode', 'Exists', 'Item', 'Items', 'Key', 'Keys', 'Remove', 'RemoveAll', 'Attributes',
			'Drive', 'Drives', 'GetDrive', 'DriveLetter', 'GetDriveName', 'AvailableSpace', 'DriveType', 'FileSystem',
			'FreeSpace', 'IsReady', 'Path', 'RootFolder', 'SerialNumber', 'ShareName', 'TotalSize', 'VolumeName',
			'DriveExists', 'HelpContext', 'HelpFile', 'Raise', 'Source', 'Test', 'GetFileName', 'ShortName', 'Move',
			'OpenAsTextStream', 'ParentFolder', 'ShortPath', 'FirstIndex', 'GetAbsolutePathName', 'GetBaseName',
			'GetExtensionName', 'GetParentFolderName', 'GetSpecialFolder', 'GetTempName', 'IgnoreCase', 'IsRootFolder',
			'Line', 'Pattern', 'MoveFile', 'MoveFolder', 'Value', 'Skip', 'SkipLine', 'Write', 'WriteLine', 'WriteBlankLines'
			)
		),
	'CASE_SENSITIVE' => array(
		GESHI_COMMENTS => false,
		1 => false,
		2 => false,
		3 => false,
		),
	'STYLES' => array(
		'KEYWORDS' => array(
			1 => 'color:#990099; font-weight:bold;',
			2 => 'color:#0000FF; font-weight:bold;',
			3 => 'color:#330066;'
			),
		'COMMENTS' => array(
			1 => 'color:#008000;',
			2 => 'color:#FF6600; font-style:italic;',
			'MULTI' => 'color:#008000;'
			),
		'ESCAPE_CHAR' => array(
			0 => 'color:#000099; font-weight:bold;'
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
		'METHODS' => array(
			1 => 'color:#9900CC;'
			),
		'SYMBOLS' => array(
			0 => 'color:#006600; font-weight:bold;'
			),
		'REGEXPS' => array(
			),
		'SCRIPT' => array(
			0 => '',
			1 => '',
			2 => '',
			)
		),
	'URLS' => array(
		1 => '',
		2 => '',
		3 => ''
		),
	'OOLANG' => true,
	'OBJECT_SPLITTERS' => array(
		1 => '.'
		),
	'REGEXPS' => array(
		),
	'STRICT_MODE_APPLIES' => GESHI_MAYBE,
	'SCRIPT_DELIMITERS' => array(
		0 => array(
			'<%' => '%>'
			),
		1 => array(
			'<script language="vbscript" runat="server">' => '</script>'
			),
		2 => array(
			'<script language="javascript" runat="server">' => '</script>'
			)
		),
	'HIGHLIGHT_STRICT_BLOCK' => array(
		0 => true,
		1 => true,
		2 => true,
		)
);

?>