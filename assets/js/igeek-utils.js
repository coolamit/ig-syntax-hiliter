/**
 * iGeek Utility library
 *
 * @version 0.5
 * @author Amit Gupta
 *
 * @since 2011-11-20
 * @lastModified 2012-08-16 Amit Gupta
 */

var iGeek = {
	/**
	 * pass a var to it to check if its empty/null/undefined or not. If empty/null/undefined then it returns TRUE else FALSE
	 */
	is_empty: function(var_value) {
		if( ! var_value || var_value === null || var_value === undefined ) {
			return true;
		}
		var_value = var_value + "";	//convert to string to be able to do remove spaces from both ends
		var_value = var_value.replace(/^\s+|\s+$/g, "");	//scrub spaces from both ends
		if( var_value === "" ) {
			return true;
		}
		return false;
	},
	/**
	 * rounds `num` to number of decimal places specified in `dec`. If `dec` is empty then default is 2
	 */
	round: function(num, dec) {
		dec = ( this.is_empty(dec) ) ? 2 : dec;
		return Math.round( num * Math.pow( 10, dec ) ) / Math.pow( 10, dec );
	},
	/**
	 * replace all instances of `needle` in `haystack` with `replace_with`
	 */
	replace_all: function(needle, replace_with, haystack) {
		var pattern = new RegExp(needle, 'g');		//create RegEx pattern to replace all
		return haystack.replace(pattern, replace_with);
	},
	/**
	 * cookie sub-class
	 */
	cookie: {
		/**
		 * pass cookie name & get its value / returns NULL if cookie doesn't exist
		 * @TODO: implement the substr approach (under testing - it randomly snips out a char on either boundary), remove usage of loop
		 */
		get: function(cookie_name) {
				var x, y;
				var arr_cookie = document.cookie.split(";");
				var arr_cookie_len = arr_cookie.length;
				for ( var i = 0; i < arr_cookie_len; i++ ) {
					x = arr_cookie[i].substr( 0, arr_cookie[i].indexOf("=") );	//get cookie name
					y = arr_cookie[i].substr( arr_cookie[i].indexOf("=") + 1 );	//get cookie value
					x = x.replace(/^\s+|\s+$/g, "");	//scrub spaces from both ends of cookie name
					if( x == cookie_name ) {
						//cookie name matches the one we want, so return the value
						return unescape(y);
					}
				}
				return null;
		},
		/**
		 * pass cookie name & value to set it, pass expiry time in seconds from current time
		 * 
		 * @TODO - put in domain & secure parameters as well, so it'll be full-fledged & generic
		 */
		set: function(cookie_name, cookie_value, expiry_secs, cookie_path) {
			expiry_secs = parseInt( expiry_secs, 10 ) * 1000;	//convert to milliseconds
			var l_date = new Date();
			var expiry_date = new Date( l_date.getTime() + expiry_secs );
			cookie_value = escape(cookie_value);
			cookie_value += ( iGeek.is_empty(expiry_secs) ) ? "" : "; expires=" + expiry_date.toUTCString();
			cookie_value += ( iGeek.is_empty(cookie_path) ) ? "; path=/" : "; path=" + cookie_path;
			document.cookie = cookie_name + "=" + cookie_value;
		},
		/**
		 * pass cookie name to delete the cookie
		 */
		expire: function(cookie_name, cookie_path) {
			cookie_path = ( iGeek.is_empty(cookie_path) ) ? '' : cookie_path;
			this.set(cookie_name, "", 1, cookie_path);
		}
	}
};


//EOF
