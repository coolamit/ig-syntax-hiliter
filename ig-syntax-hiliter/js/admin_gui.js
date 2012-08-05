function confirmReset() {
	var a = confirm("Are you sure you want to reset to the DEFAULT SETTINGS?");
	if (a) {
		return true;
	} else {
		return false;
	}
}

function findPosX(obj) {
	var curleft = 0;
	if (obj.offsetParent) {
		while (obj.offsetParent) {
			curleft += obj.offsetLeft
			obj = obj.offsetParent;
		}
	} else if (obj.x) {
		curleft += obj.x;
	}
	return curleft;
}

function findPosY(obj) {
	var curtop = 0;
	if (obj.offsetParent) {
		while (obj.offsetParent) {
			curtop += obj.offsetTop
			obj = obj.offsetParent;
		}
	} else if (obj.y) {
		curtop += obj.y;
	}
	return curtop;
}

function changePos(eSrc, eTgt) {
	var oSrc = document.getElementById(eSrc);
	var oTgt = document.getElementById(eTgt);
	oTgt.style.left = (findPosX(oSrc)+oSrc.offsetWidth+10)+"px";
	oTgt.style.top = (findPosY(oSrc))+"px";
}

function setWinStatus(sTxt) {
	window.status = sTxt;
}

function generatePicker() {
	var strHTML = "";
//	strHTML += "<div id=\"igColorPicker\">";
	strHTML += "	<div id=\"pickerPanel\" class=\"dragPanel\">";
	strHTML += "		<h4 id=\"pickerHandle\"><a href=\"\" onclick=\"javascript:hidePicker(); return false;\" onmouseover=\"javascript:setWinStatus(' ');\" title=\"Close\"><img src=\""+igsyntax_hiliter_path+"/img/button_close.gif\" border=\"0\" /></a>&nbsp;</h4>";
	strHTML += "		<div id=\"pickerDiv\">";
	strHTML += "		  <img id=\"pickerbg\" src=\""+igsyntax_hiliter_path+"/img/pickerbg.png\" alt=\"\" />";
	strHTML += "		  <div id=\"selector\"><img src=\""+igsyntax_hiliter_path+"/img/select.gif\" /></div>";
	strHTML += "		</div>";
	strHTML += "		<div id=\"hueBg\">";
	strHTML += "			<div id=\"hueThumb\"><img src=\""+igsyntax_hiliter_path+"/img/hline.png\" /></div>";
	strHTML += "		</div>";
	strHTML += "		<div id=\"pickervaldiv\">";
	strHTML += "			<form name=\"pickerform\" onsubmit=\"javascript: return false; pickerUpdate();\">";
	strHTML += "				<br />";
	strHTML += "				<input name=\"pickerrval\" id=\"pickerrval\" type=\"hidden\" value=\"0\" />";
	strHTML += "				<input name=\"pickerhval\" id=\"pickerhval\" type=\"hidden\" value=\"0\" />";
	strHTML += "				<input name=\"pickergval\" id=\"pickergval\" type=\"hidden\" value=\"0\" />";
	strHTML += "				<input name=\"pickergsal\" id=\"pickersval\" type=\"hidden\" value=\"0\" />";
	strHTML += "				<input name=\"pickerbval\" id=\"pickerbval\" type=\"hidden\" value=\"0\" />";
	strHTML += "				<input name=\"pickervval\" id=\"pickervval\" type=\"hidden\" value=\"0\" />";
	strHTML += "				<br /><br />";
	strHTML += "				# <input name=\"pickerhexval\" id=\"pickerhexval\" type=\"text\" class=\"pickerTxt\" value=\"0\" size=\"7\" maxlength=\"6\" />";
	strHTML += "				<br />";
//	strHTML += "				<input type=\"image\" name=\"btnOK\" src=\""+igsyntax_hiliter_path+"/img/button_ok.gif\" class=\"pickerBtnImg\" title=\"Click to Select this Colour\" onclick=\"javascript:getPickerValue('pickerhexval');\" /><br />";
//	strHTML += "				<input type=\"image\" name=\"btnCancel\" src=\""+igsyntax_hiliter_path+"/img/button_cancel.gif\" class=\"pickerBtnImg\" title=\"Click to Cancel your selection\" onclick=\"javascript:hidePicker();\" />";
	strHTML += "				<input type=\"button\" name=\"btnOK\" class=\"pickerBtn\" value=\"OK\" title=\"Click to Select this Colour\" onclick=\"javascript:getPickerValue('pickerhexval');\" /><br />";
	strHTML += "				<input type=\"button\" name=\"btnCancel\" class=\"pickerBtn\" value=\"CANCEL\" title=\"Click to Cancel your selection\" onclick=\"javascript:hidePicker();\" />";
	strHTML += "			</form>";
	strHTML += "		</div>";
	strHTML += "		<div id=\"pickerSwatch\">&nbsp;</div>";
	strHTML += "	</div>";
//	strHTML += "</div>";
	document.write(strHTML);
}

function getPickerLauncher(eID, txtID) {
	var strHTML = "<a href=\"#\" id=\""+eID+"\" class=\"pickerLauncher\" onclick=\"javascript: launchPicker('"+eID+"','"+txtID+"'); return false;\" title=\"Click to open Colour Picker\">";
	strHTML += "<img src=\""+igsyntax_hiliter_path+"/img/colour_palette.gif\" border=\"0\" /></a>";
	document.write(strHTML);
}

function launchPicker(eID, txtID) {
	hidePicker();
	var oClrPkr = document.getElementById(strClrPkr);
	igTxtFld = txtID;
	changePos(eID, strClrPkr);
	oClrPkr.style.visibility = "visible";
}

function getPickerValue(pID) {
	var strHex = document.getElementById(pID).value;
	if(strHex=="") {
		alert("You must specify a Hexadecimal Colour Value");
	} else {
		document.getElementById(igTxtFld).value = "#"+strHex;
		hidePicker();
	}
}

function hidePicker() {
	var oClrPkr = document.getElementById(strClrPkr);
	oClrPkr.style.visibility = "hidden";
}
