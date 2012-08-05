var hue;
var picker;
var gLogger;
var dd1, dd2;
var r, g, b;
window.onload = init;
function init() {
	if (typeof(ygLogger) != "undefined") {
		ygLogger.init(document.getElementById("logDiv"));
		gLogger = new ygLogger("");
	}
	pickerInit();
}

// Picker ---------------------------------------------------------
function pickerInit() {
	hue = YAHOO.widget.Slider.getVertSlider("hueBg", "hueThumb", 0, 180);
	hue.onChange = function(newVal) { hueUpdate(newVal); };
	picker = YAHOO.widget.Slider.getSliderRegion("pickerDiv", "selector", 0, 180, 0, 180);
	picker.onChange = function(newX, newY) { pickerUpdate(newX, newY); };
	hueUpdate();
	//start drag code
	dd1 = new YAHOO.util.DD("pickerPanel");
	dd1.setHandleElId("pickerHandle");
	dd1.endDrag = function(e) {
	};
	//end drag code
}

function pickerUpdate(newX, newY) {
	pickerSwatchUpdate();
}

function hueUpdate(newVal) {
	var h = (180 - hue.getValue()) / 180;
	if (h == 1) { h = 0; }
	gLogger.debug("hue " + hue.getValue());
	var a = YAHOO.util.Color.hsv2rgb( h, 1, 1);
	document.getElementById("pickerDiv").style.backgroundColor = "rgb(" + a[0] + ", " + a[1] + ", " + a[2] + ")";
	pickerSwatchUpdate();
}

function pickerSwatchUpdate() {
	var h = (180 - hue.getValue());
	if (h == 180) { h = 0; }
	document.getElementById("pickerhval").value = (h*2);
	h = h / 180;
	gLogger.debug("h " + hue.getValue());
	var s = picker.getXValue() / 180;
	document.getElementById("pickersval").value = Math.round(s * 100);
	gLogger.debug("s " + s);
	var v = (180 - picker.getYValue()) / 180;
	document.getElementById("pickervval").value = Math.round(v * 100);
	gLogger.debug("v " + v);
	var a = YAHOO.util.Color.hsv2rgb( h, s, v );
	document.getElementById("pickerSwatch").style.backgroundColor = "rgb(" + a[0] + ", " + a[1] + ", " + a[2] + ")";
	document.getElementById("pickerrval").value = a[0];
	document.getElementById("pickergval").value = a[1];
	document.getElementById("pickerbval").value = a[2];
	document.getElementById("pickerhexval").value = YAHOO.util.Color.rgb2hex(a[0], a[1], a[2]);
}
