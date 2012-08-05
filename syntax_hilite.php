<?php
/*
Plugin Name: iG:Syntax Hiliter
Plugin URI: http://blog.igeek.info/wp-plugins/igsyntax-hiliter/
Feed URI: http://blog.igeek.info/still-fresh/category/wp-plugins/igsyntax-hiliter/feed/
Description: Syntax Highlighter for various programming languages, using the <a href="http://qbnz.com/highlighter/" target="_blank">GeSHi</a> engine. See the MANUAL for more instructions. <br /><strong>[</strong><a href="http://blog.igeek.info/wp-plugins/igsyntax-hiliter/" target="_blank">Plugin Home</a><strong>]&nbsp;[</strong><a href="http://blog.igeek.info/still-fresh/category/wp-plugins/igsyntax-hiliter/feed/" target="_blank">Plugin Feed (RSS2)</a><strong>]</strong>.
Version: 3.5
Author: Amit Gupta
Author URI: http://blog.igeek.info/
*/

/*******************************************************************
*	DON'T EDIT THIS FILE UNLESS YOU KNOW WHAT YOU ARE DOING
*	YOU NO LONGER NEED TO EDIT THIS FILE TO CONFIGURE THIS PLUGIN
*	LOGIN TO WP-ADMIN SECTION TO CONFIGURE IN GUI, SEE THE
*	MANUAL FOR MORE DETAILS
*******************************************************************/

define('IG_VERSION', "3.5");	// Version of the Plugin
define('IG_FILE', "syntax_hilite.php");	// Plugin File Name
define('IG_OPTIONS_NAME', "igsh_options");	// Name of the Option stored in the DB

global $igsh, $tOffAutoFmt, $cbId;	// iGSyntaxHilite Object, autoFormat ON or OFF, code box id
$tOffAutoFmt = false;				// set autoFormatOff as FALSE to enable auto formatting by default
$cbId = 1;							// initialise the Code Box Id

global $igWpVersion, $igsyntax_hiliter_path;
$igWpVersion = floatval(get_bloginfo('version'));
$ig_geshipath = ABSPATH."wp-content/plugins/ig_syntax_hilite/"; // physical path to the directory where geshi directory resides, should end with a /
$igsyntax_hiliter_path = get_settings('home')."/wp-content/plugins/ig_syntax_hilite";	//URL to the plugin directory
require_once("{$ig_geshipath}geshi.php");	// include the GeSHi Core

class iGSyntaxHilite {	/* Start Class */
	var $ig_geshipath = null;				// variable to store the path of the GeSHi Language Files

	// Class Constructor
	/**
	* @return NOTHING
	* @param $ig_geshipath
	* @desc This is the Constructor of the Class & accepts the path of the language files directory
	*/
	function iGSyntaxHilite($ig_geshipath) {
		$this->ig_geshipath = $ig_geshipath;
	}	// END CONSTRUCTOR iGSyntaxHilite

	// Function for Prefixing the DIV around
	/**
	* @return Starting <DIV> for the CODE BOX
	* @param $hLang, $bId, $bCls
	* @desc This is the function for prefixing the starting portion of the <DIV> code box with the CSS Class & Language Name Set
	*/
	function pFix($hLang='PHP', $bId, $bCls='syntax_hilite') {
		$bBody = "";
		$bId = strtolower($bId);
		if(IG_PLAIN_TEXT) {
			//show the PLAIN TEXT View
			if(IG_PLAIN_TEXT_TYPE=="inbox") {
				$ig_jsPlainTxt = "showPlainTxt";
			} else {
				$ig_jsPlainTxt = "showCodeTxt";
			}
			$bBody .= "<div class=\"igBar\"><span id=\"l{$bId}\"><a href=\"#\" onclick=\"javascript:{$ig_jsPlainTxt}('{$bId}'); return false;\">PLAIN TEXT</a></span></div>";
		}
		if(IG_SHOW_LANG_NAME) {
			$bBody .= "<div class=\"{$bCls}\"><span class=\"langName\">{$hLang}:</span><br /><div id=\"{$bId}\">\n";
		} else {
			$bBody .= "<div class=\"{$bCls}\"><div id=\"{$bId}\">\n";
		}
		return $bBody;
	}	// END pFix

	// Function for Suffixing the DIV
	/**
	* @return Ending <DIV> for the CODE BOX
	* @param $bId
	* @desc This is the function for suffixing the end portion of the <DIV> code box
	*/
	function sFix() {
		$bBody = "\n</div></div><br />";
		return $bBody;
	}	// END sFix

	// Function for Hiliting
	/**
	* @return $hCode
	* @param $mTxt, $mType
	* @desc This Function hilites the Codes
	*/
	function doHilite($mTxt, $mType='html', $sNum=1) {
		global $cbId;
		$sNum = (int) $sNum;
		$sNum = ($sNum<1) ? 1 : $sNum;
		switch($mType) {
			case "as":
				$mType = "actionscript";
				$mTypeShow = "Actionscript";
				break;
			case "cpp":
				$mType = "cpp";
				$mTypeShow = "C++";
				break;
			case "js":
				$mType = "javascript";
				$mTypeShow = "JavaScript";
				break;
			case "csharp":
				$mType = "csharp";
				$mTypeShow = "C#";
				break;
			case "mysql":
				$mType = "mysql";
				$mTypeShow = "MySQL";
				break;
			case "vb":
				$mType = "vb";
				$mTypeShow = "Visual Basic";
				break;
			case "vbnet":
				$mType = "vbnet";
				$mTypeShow = "VB.NET";
				break;
			default:
				$mType = $mType;
				$mTypeShow = strtoupper($mType);
				break;
		}
		if(function_exists("file_exists")) {
			if(file_exists("{$this->ig_geshipath}{$mType}.php")) {
				$igCheckFile = true;
			} else {
				$igCheckFile = false;
			}
		} else {
			$igCheckFile = true;
		}
		if($igCheckFile) {
			$geshi = new GeSHi(trim($mTxt), $mType, $this->ig_geshipath);
			$geshi->set_header_type(GESHI_HEADER_DIV);
			if(IG_LINE_NUMBERS) {
				if(IG_FANCY_NUMBERS) {
					$geshi->enable_line_numbers(GESHI_FANCY_LINE_NUMBERS, 2);
					$geshi->set_line_style('color:'.IG_LINE_COLOUR_1.';', 'color:'.IG_LINE_COLOUR_2.';', true);
				} else {
					$geshi->enable_line_numbers(GESHI_NORMAL_LINE_NUMBERS);
					$geshi->set_line_style('color:'.IG_LINE_COLOUR_1.'; font-weight:bold;', true);
				}
				$geshi->set_numbers_style('color:#800000;', true);
				$geshi->start_line_numbers_at($sNum);		// start Line Number from this number
			} else {
				$geshi->enable_line_numbers(GESHI_NO_LINE_NUMBERS);
			}
			$geshi->set_tab_width(4);
			$hCode = $geshi->parse_code();
			$hCode = $this->pFix($mTypeShow, $mType.'-'.$cbId).$hCode.$this->sFix();
			$cbId++;
		} else {
			//return code as it is
			if($sNum>1) {
				$igAppndNum = " num={$sNum}";
			} else {
				$igAppndNum = "";
			}
			$hCode = "[{$mType}{$igAppndNum}]{$mTxt}[/{$mType}]";
		}
		return $hCode;
	}	// END doHilite

}	/****************************************************************
					END CLASS iGSyntaxHilite
********************************************************************/

function igSynHiliteGetParam($pVal, $sep) {
	$pValArr = explode($sep, $pVal);
	$rVal = $pValArr[1];
	return $rVal;
}

$igsh = new iGSyntaxHilite($ig_geshipath.'geshi/');

//Hilite CODE
function igSynHilite_code($hCode) {
	global $igsh,$tOffAutoFmt;
	$startTag = strtolower(trim($hCode[1]));
	$inTxt = $hCode[4];
	$pVal = (int) $hCode[3];				// get the starting line number
	$hilitedCode = "";
	if(!empty($startTag)) {
		if(strlen($inTxt)>1) {
			$tOffAutoFmt = 1;							// if code is there, disable auto formatting
		}
		$arrSearch = array("< ", "&lt; ", " >", " &gt;", "<&nbsp;", "&lt;&nbsp;", "&nbsp;>", "&nbsp;&gt;");
		$arrReplace = array("<", "&lt;", ">", "&gt;", "<", "&lt;", ">", "&gt;");
		$inTxt = str_replace($arrSearch, $arrReplace, $inTxt);
		$pVal = ((empty($pVal)) || ($pVal<1)) ? 1 : $pVal;
		$hilitedCode = $igsh->doHilite($inTxt, $startTag, $pVal);
	}
	return $hilitedCode;
}


// main function that calls the highlighter
function igSynHilite($inData) {
	$inData = preg_replace_callback('/\[(\w{1,})((?:\s+num+=([0-9]{1,})+)*)\](.+?)\[\/\1\]/ims', 'igSynHilite_code', $inData);		// call code hiliter
	return $inData;
}

// function for enabling/disabling auto-formatting
function igSynHilite_Cond_WPTexturize($inData) {
	global $tOffAutoFmt;
	if($tOffAutoFmt != 1){
		$inData = wptexturize($inData);
	}
	return $inData;
}

// function for outputting styles
function igSynHilite_header() {
	global $cssStyles, $igsyntax_hiliter_path;
	$hHead = "<link rel=\"stylesheet\" href=\"{$igsyntax_hiliter_path}/css/syntax_hilite_css.css\" type=\"text/css\" media=\"all\" />\n";
	if(IG_PLAIN_TEXT) {
		$hHead .= "	<script language=\"javascript\" type=\"text/javascript\" src=\"{$igsyntax_hiliter_path}/js/syntax_hilite_js.js\"></script>\n";
		$hHead .= "	<script language=\"javascript\" type=\"text/javascript\">\n";
		$hHead .= "	var arrCode = new Array();\n";
		$hHead .= "	</script>\n";
	}
	print($hHead);
}

// function for admin head output only
function igSynHilite_adminHeader() {
	global $igsyntax_hiliter_path;
	$hHead = "	<link rel=\"stylesheet\" href=\"{$igsyntax_hiliter_path}/css/picker_css.css\" type=\"text/css\" media=\"screen\" />\n";
	$hHead .= "	<script language=\"javascript\" type=\"text/javascript\" src=\"{$igsyntax_hiliter_path}/js/YAHOO.js\" ></script>\n";
	$hHead .= "	<script language=\"javascript\" type=\"text/javascript\" src=\"{$igsyntax_hiliter_path}/js/log.js\" ></script>\n";
	$hHead .= "	<script language=\"javascript\" type=\"text/javascript\" src=\"{$igsyntax_hiliter_path}/js/color.js\" ></script>\n";
	$hHead .= "	<script language=\"javascript\" type=\"text/javascript\" src=\"{$igsyntax_hiliter_path}/js/key.js\" ></script>\n";
	$hHead .= "	<script language=\"javascript\" type=\"text/javascript\" src=\"{$igsyntax_hiliter_path}/js/event.js\" ></script>\n";
	$hHead .= "	<script language=\"javascript\" type=\"text/javascript\" src=\"{$igsyntax_hiliter_path}/js/dom.js\" ></script>\n";
	$hHead .= "	<script language=\"javascript\" type=\"text/javascript\" src=\"{$igsyntax_hiliter_path}/js/animation.js\" ></script>\n";
	$hHead .= "	<script language=\"javascript\" type=\"text/javascript\" src=\"{$igsyntax_hiliter_path}/js/dragdrop.js\" ></script>\n";
	$hHead .= "	<script language=\"javascript\" type=\"text/javascript\" src=\"{$igsyntax_hiliter_path}/js/slider.js\" ></script>\n";
	$hHead .= "	<script language=\"javascript\" type=\"text/javascript\" src=\"{$igsyntax_hiliter_path}/js/colorPicker.js\" ></script>\n";
	$hHead .= "	<!--[if gte IE 5.5000]>\n";
	$hHead .= "	<script language=\"javascript\" type=\"text/javascript\" src=\"{$igsyntax_hiliter_path}/js/colorPickerIE5_5.js\" ></script>\n";
	$hHead .= "	<![endif]-->\n";
	$hHead .= "	<script language=\"javascript\" type=\"text/javascript\" src=\"{$igsyntax_hiliter_path}/js/admin_gui.js\"></script>\n";
	$hHead .= "	<script language=\"javascript\" type=\"text/javascript\">\n";
	$hHead .= "	var igsyntax_hiliter_path = \"{$igsyntax_hiliter_path}\";\n";
	$hHead .= "	var igTxtFld = \"\";\n";
	$hHead .= "	var strClrPkr = \"pickerPanel\";\n";
	$hHead .= "	</script>\n";
	print($hHead);
}

//function for adding the sub-panel in the Options panel
function igSynHilite_Menu() {
	if(function_exists('add_options_page')) {
		add_options_page('iG:Syntax Hiliter Options', 'iG:Syntax Hiliter', 8, basename(__FILE__), 'igSynHilite_GUI');
	}
}


function getPickerLauncher($ig_eID, $ig_txtID) {
	print("<script language=\"javascript\" type=\"text/javascript\">getPickerLauncher('{$ig_eID}', '{$ig_txtID}');</script>");
}


//function for the Admin GUI
function igSynHilite_GUI() {
	global $user_level, $igWpVersion;
	get_currentuserinfo();
	//if user is not an admin equalling/above level 8, then don't give any GUI
	if ($user_level < 8) {
?><div class="wrap">
	<h2>iG:Syntax Hiliter(v<?php print(IG_VERSION); ?>) Configuration</h2>
	<br /><?php _e("<div style=\"color:#770000;\">You are not a <strong>LEVEL 8</strong> or above USER &amp; 
hence you cannot configure <strong>iG:Syntax Hiliter</strong>. If you are a <strong>LEVEL 8</strong> or above USER, 
then please Logout &amp; Login again.</div>"); ?><br />
</div><?php
		return;
	}
	if(!empty($_POST['igsh_plainTxt'])) {
		$igsh_plainTxt = strtolower(trim($_POST['igsh_plainTxt']));
		$igsh_plainTxtType = strtolower(trim($_POST['igsh_plainTxtType']));
		$igsh_showLangName = strtolower(trim($_POST['igsh_showLangName']));
		$igsh_parseComments = strtolower(trim($_POST['igsh_parseComments']));
		$igsh_showLineNumbers = strtolower(trim($_POST['igsh_showLineNumbers']));
		$igsh_showFancyNumbers = strtolower(trim($_POST['igsh_showFancyNumbers']));
		$igsh_lineColour1 = strtoupper(trim($_POST['igsh_lineColour1']));
		$igsh_lineColour2 = strtoupper(trim($_POST['igsh_lineColour2']));
		//get from DB
		$igSHOptionsDB = get_option(IG_OPTIONS_NAME);
		$igsh_Enabled = $igSHOptionsDB['ENABLED'];
		//check for empty & replace empty with the current option
		if(empty($igsh_plainTxt)) {
			$igsh_plainTxt = $igSHOptionsDB['PLAIN_TEXT'];
		} elseif(empty($igsh_plainTxtType)) {
			$igsh_plainTxtType = $igSHOptionsDB['PLAIN_TEXT_TYPE'];
		} elseif(empty($igsh_showLangName)) {
			$igsh_showLangName = $igSHOptionsDB['SHOW_LANG_NAME'];
		} elseif(empty($igsh_parseComments)) {
			$igsh_parseComments = $igSHOptionsDB['PARSE_COMMENTS'];
		} elseif(empty($igsh_showLineNumbers)) {
			$igsh_showLineNumbers = $igSHOptionsDB['LINE_NUMBERS'];
		} elseif(empty($igsh_showFancyNumbers)) {
			$igsh_showFancyNumbers = $igSHOptionsDB['FANCY_NUMBERS'];
		} elseif(empty($igsh_lineColour1)) {
			$igsh_lineColour1 = $igSHOptionsDB['LINE_COLOUR_1'];
		} elseif(empty($igsh_lineColour2)) {
			$igsh_lineColour2 = $igSHOptionsDB['LINE_COLOUR_2'];
		}
		//put them all in an array properly
		$igSHOptionsNewArr['ENABLED'] = $igsh_Enabled;
		$igSHOptionsNewArr['PLAIN_TEXT'] = ($igsh_plainTxt=="true") ? true : false;
		$igSHOptionsNewArr['PLAIN_TEXT_TYPE'] = ($igsh_plainTxtType=="inbox") ? "inbox" : "pop";
		$igSHOptionsNewArr['SHOW_LANG_NAME'] = ($igsh_showLangName=="true") ? true : false;
		$igSHOptionsNewArr['PARSE_COMMENTS'] = ($igsh_parseComments=="true") ? true : false;
		$igSHOptionsNewArr['LINE_NUMBERS'] = ($igsh_showLineNumbers=="true") ? true : false;
		$igSHOptionsNewArr['FANCY_NUMBERS'] = ($igsh_showFancyNumbers=="true") ? true : false;
		$igSHOptionsNewArr['LINE_COLOUR_1'] = $igsh_lineColour1;
		$igSHOptionsNewArr['LINE_COLOUR_2'] = $igsh_lineColour2;
		//update in the DB
		update_option(IG_OPTIONS_NAME, $igSHOptionsNewArr);
		$igERR = "Successfully Saved Settings";
	}
	if(!empty($_POST['igsh_hidReset']) && trim($_POST['igsh_hidReset'])=="doReset") {
		igSynHilite_Install(true);
		$igERR = "Successfully restored Default Settings";
	}
	//get fresh from DB
	$igSHOptionsDB = get_option(IG_OPTIONS_NAME);
	$igsh_Enabled = $igSHOptionsDB['ENABLED'];
	$igsh_plainTxt = $igSHOptionsDB['PLAIN_TEXT'];
	$igsh_plainTxtType = $igSHOptionsDB['PLAIN_TEXT_TYPE'];
	$igsh_showLangName = $igSHOptionsDB['SHOW_LANG_NAME'];
	$igsh_parseComments = $igSHOptionsDB['PARSE_COMMENTS'];
	$igsh_showLineNumbers = $igSHOptionsDB['LINE_NUMBERS'];
	$igsh_showFancyNumbers = $igSHOptionsDB['FANCY_NUMBERS'];
	$igsh_lineColour1 = $igSHOptionsDB['LINE_COLOUR_1'];
	$igsh_lineColour2 = $igSHOptionsDB['LINE_COLOUR_2'];
	
	if(!empty($igERR)) {
		if($igWpVersion<2) {
			$igErrAttributes = " class=\"updated\"";
		} else {
			$igErrAttributes = " id=\"message\" class=\"updated fade\"";
		}
?>
<div<?php print($igErrAttributes); ?>><br /><strong><?php _e($igERR); ?></strong><br />&nbsp;</div>
<?php
	}
?>
<script language="javascript" type="text/javascript">generatePicker();</script>
<div class="wrap" style="width:86%;">
	<h2>iG:Syntax Hiliter(v<?php print(IG_VERSION); ?>) Configuration</h2>
	<br /><?php _e("You can configure <strong>iG:Syntax Hiliter</strong> here. Its easy to configure 
as there are only a few settings that you can change. These settings are pre-configured and you shouldn't 
require to change any, but should you want, then go ahead.<br />If you cannot understand any option then you should 
refer to the <strong>MANUAL</strong> that you got with this plugin.<br /><br /><div style=\"color:#770000;\"><strong>CAUTION:-</strong> Leaving 
any colour field blank will not remove the colour, its just that your colour won't be changed. Also, if 
you don't save a valid Colour HexCode, then you'll be responsible if your CSS breaks.</div>"); ?><br />
	<fieldset name="igsh_set2">
		<legend><?php _e('RESET PLUGIN'); ?></legend>
		<form method="post" onsubmit="javascript: return confirmReset();">
			<div class="submit" style="text-align:center;">
				<input type="hidden" name="igsh_hidReset" value="doReset" />
				<input type="submit" name="igsh_reset" style="font-weight:bold; width:90%;" value="&laquo;&laquo;&nbsp;&nbsp; RESET&nbsp;&nbsp;DEFAULT&nbsp;&nbsp;SETTINGS &nbsp;&nbsp;&raquo;&raquo;" title="Click to RESET the plugin settings to default" />
			</div>
		</form>
	</fieldset>
	<br /><br />
	<fieldset name="igsh_set3">
		<legend><?php _e('BASE SETTINGS'); ?></legend>
		<form method="post">
			<ul style="list-style:none;">
				<li>
					<label for="ig_plain_text"><strong>Show Plain Text Code Option:</strong></label>&nbsp;&nbsp;
					<select name="igsh_plainTxt" id="ig_plain_text">
						<option value="true"<?php if(($igsh_plainTxt==true)) { _e(" selected"); } ?>>YES</option>
						<option value="false"<?php if(($igsh_plainTxt==false)) { _e(" selected"); } ?>>NO</option>
					</select><br />
					<strong>{</strong> <em>Enabling this option will show the "PLAIN TEXT" link with your code boxes to allow users to get your code 
					as un-hilited Plain Text.</em> <strong>}</strong><br />&nbsp;
				</li>
				<li>
					<label for="ig_plain_text_type"><strong>Show Plain Text Code In:</strong></label>&nbsp;&nbsp;
					<select name="igsh_plainTxtType" id="ig_plain_text_type">
						<option value="pop"<?php if(($igsh_plainTxtType=="pop")) { _e(" selected"); } ?>>New Window</option>
						<option value="inbox"<?php if(($igsh_plainTxtType=="inbox")) { _e(" selected"); } ?>>In CodeBox</option>
					</select><br />
					<strong>{</strong> <em>Select where you want the PLAIN TEXT code to appear, in a new pop-up window or have it 
					appear in the codebox itself with the option to show hilited code back again. This is useful only if you have 
					enabled the PLAIN TEXT option above.</em> <strong>}</strong><br />&nbsp;
				</li>
				<li>
					<label for="ig_show_lang_name"><strong>Show Language Name:</strong></label>&nbsp;&nbsp;
					<select name="igsh_showLangName" id="ig_show_lang_name">
						<option value="true"<?php if(($igsh_showLangName==true)) { _e(" selected"); } ?>>YES</option>
						<option value="false"<?php if(($igsh_showLangName==false)) { _e(" selected"); } ?>>NO</option>
					</select><br />
					<strong>{</strong> <em>Enabling this option will show the NAME of the LANGUAGE whose code is hilited in the code box.</em> 
					<strong>}</strong><br />&nbsp;
				</li>
				<li>
					<label for="ig_parse_comments"><strong>Hilite Code in Comments:</strong></label>&nbsp;&nbsp;
					<select name="igsh_parseComments" id="ig_parse_comments">
						<option value="true"<?php if(($igsh_parseComments==true)) { _e(" selected"); } ?>>YES</option>
						<option value="false"<?php if(($igsh_parseComments==false)) { _e(" selected"); } ?>>NO</option>
					</select><br />
					<strong>{</strong> <em>Enabling this option will hilite the code posted in your comments.</em> 
					<strong>}</strong><br />&nbsp;
				</li>
				<li>
					<label for="ig_line_numbers"><strong>Show Line Numbers:</strong></label>&nbsp;&nbsp;
					<select name="igsh_showLineNumbers" id="ig_line_numbers">
						<option value="true"<?php if(($igsh_showLineNumbers==true)) { _e(" selected"); } ?>>YES</option>
						<option value="false"<?php if(($igsh_showLineNumbers==false)) { _e(" selected"); } ?>>NO</option>
					</select><br />
					<strong>{</strong> <em>Enabling this option will show the Line Numbers in the code boxes. With Line Numbers, the code 
					looks good &amp; is easy to refer and debug.</em> <strong>}</strong><br />&nbsp;
				</li>
				<li>
					<label for="ig_fancy_numbers"><strong>Show Fancy Line Numbers:</strong></label>&nbsp;&nbsp;
					<select name="igsh_showFancyNumbers" id="ig_fancy_numbers">
						<option value="true"<?php if(($igsh_showFancyNumbers==true)) { _e(" selected"); } ?>>YES</option>
						<option value="false"<?php if(($igsh_showFancyNumbers==false)) { _e(" selected"); } ?>>NO</option>
					</select><br />
					<strong>{</strong> <em>Enabling this option will show the Line Numbers in the code boxes with alternate colours. They look cool 
					on the code boxes.</em> <strong>}</strong><br />&nbsp;<br />&nbsp;
				</li>
				<li>
					<label for="ig_line_colour_1"><strong>Line Colour 1:</strong></label>&nbsp;&nbsp;
					<input type="text" name="igsh_lineColour1" id="ig_line_colour_1" size="10" maxlength="7" value="<?php _e($igsh_lineColour1); ?>" /> <?php getPickerLauncher("igClrPkr1", "ig_line_colour_1"); ?><br />
					<strong>{</strong> <em>This is the first colour for the line-numbers of the code boxes.</em> 
					<strong>}</strong><br />&nbsp;
				</li>
				<li>
					<label for="ig_line_colour_2"><strong>Line Colour 2:</strong></label>&nbsp;&nbsp;
					<input type="text" name="igsh_lineColour2" id="ig_line_colour_2" size="10" maxlength="7" value="<?php _e($igsh_lineColour2); ?>" /> <?php getPickerLauncher("igClrPkr2", "ig_line_colour_2"); ?><br />
					<strong>{</strong> <em>This is the second colour for the line-numbers of the code boxes. This is only useful if you have selected FANCY LINE NUMBERS above.</em> 
					<strong>}</strong><br />&nbsp;
				</li>
				<li>
					<div class="submit" style="text-align:left;">
						<input type="submit" name="igsh_update" value="<?php _e('Update Options'); ?> &raquo;&raquo;" title="Click to UPDATE your settings" />
					</div>
				</li>
			</ul>
		</form>
	</fieldset><br />
</div><br />&nbsp;
<?php
}

//function to install the plugin
function igSynHilite_Install($igForceValues=false) {
	global $user_level;
	get_currentuserinfo();
	//if user is not an admin equalling/above level 8, then don't install
	if ($user_level < 8) { return; }
	//*****proceed with installation
	//create an array with the option values
	$igSHOptionsArr = array(
							"ENABLED" => true,
							"PLAIN_TEXT" => true,
							"PLAIN_TEXT_TYPE" => "inbox",
							"SHOW_LANG_NAME" => true,
							"PARSE_COMMENTS" => false,
							"LINE_NUMBERS" => true,
							"FANCY_NUMBERS" => true,
							"LINE_COLOUR_1" => "#3A6A8B",
							"LINE_COLOUR_2" => "#26536A"
						);
	//check if options exist in the DB
	$igSHOptionsDB = get_option(IG_OPTIONS_NAME);
	if(empty($igSHOptionsDB)) {
		//options don't exist, so add them
		add_option(IG_OPTIONS_NAME, $igSHOptionsArr);
	} else {
		//check if forced install
		if($igForceValues==false) {
			//check them one by one & update
			$igSHOptUpdateCount = 0;
			foreach($igSHOptionsDB as $igSHKey => $igSHValue) {
				if(empty($igSHOptionsDB[$igSHKey])) {
					$igSHOptionsDB[$igSHKey] = $igSHOptionsArr[$igSHKey];
					$igSHOptUpdateCount++;
				}
			}
			if($igSHOptUpdateCount>0) {
				update_option(IG_OPTIONS_NAME, $igSHOptionsDB);
			}
		} else {
			update_option(IG_OPTIONS_NAME, $igSHOptionsArr);
		}
	}
}

//define the iG:Syntax Hiliter Options
function igSynHilite_DefineOptions() {
	//check if options exist in the DB
	$igSHOptionsDB = get_option(IG_OPTIONS_NAME);
	if(!empty($igSHOptionsDB)) {
		define('IG_ENABLED', $igSHOptionsDB['ENABLED']);
		define('IG_PLAIN_TEXT', $igSHOptionsDB['PLAIN_TEXT']);
		define('IG_PLAIN_TEXT_TYPE', $igSHOptionsDB['PLAIN_TEXT_TYPE']);
		define('IG_SHOW_LANG_NAME', $igSHOptionsDB['SHOW_LANG_NAME']);
		define('IG_PARSE_COMMENTS', $igSHOptionsDB['PARSE_COMMENTS']);
		define('IG_LINE_NUMBERS', $igSHOptionsDB['LINE_NUMBERS']);
		define('IG_FANCY_NUMBERS', $igSHOptionsDB['FANCY_NUMBERS']);
		define('IG_LINE_COLOUR_1', $igSHOptionsDB['LINE_COLOUR_1']);
		define('IG_LINE_COLOUR_2', $igSHOptionsDB['LINE_COLOUR_2']);
	}
}

if((!empty($_GET['action']) && $_GET['action']=="deactivate") && (!empty($_GET['plugin']) && $_GET['plugin']=="syntax_hilite.php")) {
	//plugin deactivated
} elseif((!empty($_GET['activate'])) && ($_GET['activate']=='true')) {
	add_action('init', 'igSynHilite_Install');
} else {
	igSynHilite_DefineOptions();
}


// output to the <head> section of the page
add_action('wp_head', 'igSynHilite_header');
// output to the <head> section of admin in case of preview
add_action('admin_head', 'igSynHilite_header');
if(!empty($_GET['page']) && trim($_GET['page'])==IG_FILE) {
	add_action('admin_head', 'igSynHilite_adminHeader');
}
// disable wptexturize filter
remove_filter('the_excerpt', 'wptexturize');
remove_filter('the_content', 'wptexturize');
// add conditional wptexturize
add_filter('the_excerpt', 'igSynHilite_Cond_WPTexturize');
add_filter('the_content', 'igSynHilite_Cond_WPTexturize');
// apply filter on 3rd number, making possible for other filters to be applied before this
add_filter('the_excerpt', 'igSynHilite', 3);
add_filter('the_content', 'igSynHilite', 3);

//see if plugin is enabled for comments
if(IG_PARSE_COMMENTS!=false) {
	remove_filter('comment_text', 'wptexturize');
	add_filter('comment_text', 'igSynHilite_Cond_WPTexturize');
	add_filter('comment_text', 'igSynHilite', 3);
}

// add the sub-panel under the OPTIONS panel
add_action('admin_menu', 'igSynHilite_Menu');

?>