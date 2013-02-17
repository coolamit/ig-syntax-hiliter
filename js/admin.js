/**
 * JS for admin UI of iG:Syntax Hiliter plugin
 * 
 * @author: Amit Gupta
 * @since: 2012-08-25
 * @version: 2013-02-17
 */


jQuery(document).ready(function($) {

	function get_yesno_reverse(elem_value) {
		if( ! elem_value ) {
			return false;
		}
		switch(elem_value) {
			case 'yes':
				return 'no';
			case 'no':
				return 'yes';
		}
	}

	var loading_img = $("<img />").attr('src', ig_sh.plugins_url + 'images/ajax-loader.gif');	//pre-load ajax animation, just-in-case

	$('.ig-sh-option').on('change', function(){
		var ui_name = $(this).attr('name');
		var ui_value = $(this).val();
		$.msg({
			msgID : 1,
			bgPath : ig_sh.plugins_url + 'images/',
			autoUnblock : false,
			clickUnblock : false,
			klass : 'black-on-white',
			content : 'Saving &nbsp;&nbsp; <img src="' + ig_sh.plugins_url + 'images/ajax-loader.gif" id="loading-img" />'
		});
		$.post(
			ajaxurl,
			{
				action: 'ig-sh-save-options',
				_ig_sh_nonce: ig_sh.nonce,
				option_name: ui_name,
				option_value: ui_value
			},
			function(data) {
				setTimeout( hide_jq_msg, 1000 );
				var is_error = 1;
				if( ! data || ! data.nonce || ! data.msg ) {
					$.msg( 'replace', '<strong>Error:</strong> Unable to save option' );
				} else {
					is_error = parseInt( data.error );
					ig_sh.nonce = data.nonce;
					$.msg( 'replace', data.msg );
				}
				if( is_error == 1 ) {
					//error, revert the changes made to option control so it can be changed again
					$('#'+ui_name).val( get_yesno_reverse(ui_value) );
				}
			},
			"json"
		);
	});
	
	$('#igsh_refresh_languages').on('click', function(){
		var ui_name = $(this).attr('name');
		var ui_value = $(this).val();
		$.msg({
			msgID : 1,
			bgPath : ig_sh.plugins_url + 'images/',
			autoUnblock : false,
			clickUnblock : false,
			klass : 'black-on-white',
			content : 'Rebuilding &nbsp;&nbsp; <img src="' + ig_sh.plugins_url + 'images/ajax-loader.gif" id="loading-img" />'
		});
		$.post(
			ajaxurl,
			{
				action: 'ig-sh-save-options',
				_ig_sh_nonce: ig_sh.nonce,
				option_name: ui_name,
				option_value: ui_value
			},
			function(data) {
				setTimeout( hide_jq_msg, 1000 );
				if( ! data || ! data.nonce || ! data.msg || ! data.time ) {
					$.msg( 'replace', '<strong>Error:</strong> Unable to build tags' );
				} else {
					ig_sh.nonce = data.nonce;
					$.msg( 'replace', data.msg );
					$('#igsh-time-to-rebuild').html( data.time );
				}
			},
			"json"
		);
	});

	function hide_jq_msg() {
		$.msg( 'unblock' );
	}

});


//EOF
