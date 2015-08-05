/**
 * JS for UI of iG:Syntax Hiliter plugin
 *
 * @author: Amit Gupta
 * @since: 2012-12-30
 * @version: 2013-01-28
 * @version: 2015-07-27 - Amit Gupta - code streamlined/re-factored
 */

var igsh_fe = {
	html_sources: [],
	text_sources: [],
	format_text_code: function( text ) {
		if ( typeof text === 'undefined' || ! text ) {
			return;
		}

		var arr_html = [];
		var lines = text.split(/\n/);

		if ( jQuery.trim( lines[0] ) === '' ) {
			lines.splice( 0, 1 );
		}

		if ( jQuery.trim( lines[ ( lines.length - 1 ) ] ) === '' ) {
			lines.splice( ( lines.length - 1 ), 1 );
		}

		for ( var i = 0; i < lines.length; i++ ) {
			arr_html.push( lines[ i ] );
		}

		return arr_html.join( '<br>' );
	}
};

jQuery( document ).ready( function( $ ) {

	$( '.syntax_hilite .toolbar .view-different' ).on( 'click', function() {

		var grandpa_id = $( this ).parent().parent().parent().attr( 'id' );

		if ( grandpa_id.indexOf( '-' ) === -1 || grandpa_id.indexOf( '-' ) === ( grandpa_id.length - 1 ) ) {
			return false;
		}

		var grandpa_id_num = grandpa_id.split( '-' ).pop();

		if ( typeof igsh_fe.html_sources[ grandpa_id_num ] === 'undefined' || ! igsh_fe.html_sources[ grandpa_id_num ] ) {
			//store HTML source
			igsh_fe.html_sources[ grandpa_id_num ] = $( '#' + grandpa_id + ' div.code' ).html();
		}

		if ( typeof igsh_fe.text_sources[ grandpa_id_num ] === 'undefined' || ! igsh_fe.text_sources[ grandpa_id_num ] ) {
			//store text source
			igsh_fe.text_sources[ grandpa_id_num ] = $( '#' + grandpa_id + ' div.code' ).text();
			igsh_fe.text_sources[ grandpa_id_num ] = iGeek.replace_all( '<!--?', '<?', igsh_fe.text_sources[ grandpa_id_num ] );	//take care of opening php tag which gets messed up
			igsh_fe.text_sources[ grandpa_id_num ] = iGeek.replace_all( '<', '&lt;', igsh_fe.text_sources[ grandpa_id_num ] );		//convert < to html entity
			igsh_fe.text_sources[ grandpa_id_num ] = iGeek.replace_all( '>', '&gt;', igsh_fe.text_sources[ grandpa_id_num ] );		//convert > to html entity
			igsh_fe.text_sources[ grandpa_id_num ] = igsh_fe.format_text_code( igsh_fe.text_sources[ grandpa_id_num ] );
		}

		if ( $( this ).children( 'span' ).text() === ig_syntax_hiliter.label.plain ) {

			//change to plain text
			$( '#' + grandpa_id + ' .code' ).fadeOut( 'fast', 'linear', function() {

				$( '#' + grandpa_id + ' .code' ).html( '<div class="pre">' + igsh_fe.text_sources[ grandpa_id_num ] + '</div>' );

				$( '#' + grandpa_id + ' .code' ).fadeIn( 'fast', 'linear', function() {
					$( '#' + grandpa_id + ' .view-different' ).children( 'span' ).text( ig_syntax_hiliter.label.html );
				} );

			} );

		} else {

			//change to html
			$( '#' + grandpa_id + ' .code' ).fadeOut( 'fast', 'linear', function() {

				$( '#' + grandpa_id + ' .code' ).html( igsh_fe.html_sources[ grandpa_id_num ] );

				$( '#' + grandpa_id + ' .code' ).fadeIn( 'fast', 'linear', function() {
					$( '#' + grandpa_id + ' .view-different' ).children( 'span' ).text( ig_syntax_hiliter.label.plain );
				} );

			} );

		}

		return false;
	} );

} );


//EOF