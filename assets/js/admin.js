/**
 * JS for admin UI of iG:Syntax Hiliter plugin
 *
 * @author: Amit Gupta
 * @since: 2012-08-25
 * @version: 2013-02-17
 * @version: 2015-07-18 - Amit Gupta - formatting changes, changed images URI
 * @version: 2015-07-26 - Amit Gupta - streamlined code
 */

var igsh_admin = {
	message_hide_timeout: 1500,
	ajax_action: 'ig-sh-save-options',
	get_yesno_reverse: function ( elem_value ) {
		if ( typeof elem_value === 'undefined' || ! elem_value ) {
			return false;
		}

		switch( elem_value ) {
			case 'yes':
				return 'no';
			case 'no':
			default:
				return 'yes';
		}
	},
	show_message: function ( msg ) {
		if ( typeof msg === 'undefined' || ! msg ) {
			msg = '';
		}

		jQuery.msg( {
			msgID : 1,
			bgPath : ig_sh.plugins_url + 'assets/images/',
			autoUnblock : false,
			clickUnblock : false,
			klass : 'black-on-white',
			content : msg + ' &nbsp;&nbsp; <img src="' + ig_sh.plugins_url + 'assets/images/ajax-loader.gif" id="loading-img" />'
		} );
	},
	hide_message: function() {
		jQuery.msg( 'unblock' );
	},
	get_data: function( elem ) {
		if ( typeof elem === 'undefined' || ! elem ) {
			return {};
		}

		return {
			"name": jQuery( elem ).attr( 'name' ),
			"value": jQuery( elem ).val()
		};
	}
};

jQuery( document ).ready( function( $ ) {

	var loading_img = $( "<img />" ).attr( 'src', ig_sh.plugins_url + 'assets/images/ajax-loader.gif' );	//pre-load ajax animation, just-in-case

	$( '.ig-sh-option' ).on( 'change', function(){
		var ui = igsh_admin.get_data( this );

		igsh_admin.show_message( 'Saving' );

		$.post(
			ajaxurl,
			{
				action: igsh_admin.ajax_action,
				_ig_sh_nonce: ig_sh.nonce,
				option_name: ui.name,
				option_value: ui.value
			},
			function( data ) {
				setTimeout( igsh_admin.hide_message, igsh_admin.message_hide_timeout );

				var is_error = 1;

				if ( ! data || ! data.nonce || ! data.msg ) {
					$.msg( 'replace', '<span class="ig-sh-error"><strong>Error:</strong> Unable to save option</span>' );
				} else {
					is_error = parseInt( data.error );
					ig_sh.nonce = data.nonce;
					$.msg( 'replace', data.msg );
				}

				if ( is_error == 1 ) {
					//error, revert the changes made to option control so it can be changed again
					$( '#' + ui.name ).val( igsh_admin.get_yesno_reverse()( ui.value ) );
				}
			},
			"json"
		);
	} );

	$ ( '#igsh_refresh_languages' ).on( 'click', function(){
		var ui = igsh_admin.get_data( this );

		igsh_admin.show_message( 'Rebuilding' );

		$.post(
			ajaxurl,
			{
				action: igsh_admin.ajax_action,
				_ig_sh_nonce: ig_sh.nonce,
				option_name: ui.name,
				option_value: ui.value
			},
			function( data ) {
				setTimeout( igsh_admin.hide_message, igsh_admin.message_hide_timeout );

				if ( ! data || ! data.nonce || ! data.msg || ! data.time ) {
					$.msg( 'replace', '<span class="ig-sh-error"><strong>Error:</strong> Unable to build tags</span>' );
				} else {
					ig_sh.nonce = data.nonce;
					$.msg( 'replace', data.msg );
					$( '#igsh-time-to-rebuild' ).html( data.time );
				}
			},
			"json"
		);
	} );

} );


//EOF