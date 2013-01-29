/**
 * JS for UI of iG:Syntax Hiliter plugin
 * 
 * @author: Amit Gupta
 * @since: 2012-12-30
 * @version: 2013-01-28
 */


jQuery(document).ready(function($){
	var html_sources = [];
	var text_sources = [];
	var link_text_plain = ig_syntax_hiliter.label.plain;
	var link_text_html = ig_syntax_hiliter.label.html;

	function format_text_code( text ) {
		var arr_html = [];
		var lines = text.split(/\n/);

		if( $.trim(lines[0]) == '' ) {
			lines.splice( 0, 1 );
		}

		if( $.trim(lines[ (lines.length - 1) ]) == '' ) {
			lines.splice( (lines.length - 1), 1 );
		}

		for( var i = 0; i < lines.length; i++ ) {
			arr_html.push( lines[i] );
		}

		return arr_html.join("<br>");
	}

	$('.syntax_hilite .toolbar .view-different').on( 'click', function(){
		var grandpa_id = $(this).parent().parent().attr('id');
		if( grandpa_id.indexOf('-') == -1 || grandpa_id.indexOf('-') == ( grandpa_id.length - 1 ) ) {
			return false;
		}
		var tmp = grandpa_id.split('-');
		grandpa_id_num = tmp[ (tmp.length - 1) ];
		tmp = null;
		if( ! html_sources[grandpa_id_num] ) {
			//store HTML source
			html_sources[grandpa_id_num] = $('#' + grandpa_id + ' div.code').html();
		}
		if( ! text_sources[grandpa_id_num] ) {
			//store text source
			text_sources[grandpa_id_num] = $('#' + grandpa_id + ' div.code').text();
			text_sources[grandpa_id_num] = iGeek.replace_all( '<!--?', '<?', text_sources[grandpa_id_num] );	//take care of opening php tag which gets messed up
			text_sources[grandpa_id_num] = iGeek.replace_all( '<', '&lt;', text_sources[grandpa_id_num] );		//convert < to html entity
			text_sources[grandpa_id_num] = iGeek.replace_all( '>', '&gt;', text_sources[grandpa_id_num] );		//convert > to html entity
			text_sources[grandpa_id_num] = format_text_code( text_sources[grandpa_id_num] );
		}

		if( $(this).children('span').text() == link_text_plain ) {
			//change to plain text
			$('#' + grandpa_id + ' .code').fadeOut( 'fast', 'linear', function(){
				$('#' + grandpa_id + ' .code').html( '<div class="pre">' + text_sources[grandpa_id_num] + '</div>' );
				$('#' + grandpa_id + ' .code').fadeIn( 'fast', 'linear', function(){
					$('#' + grandpa_id + ' .view-different').children('span').text(link_text_html);
				} );
			} );
		} else {
			//change to html
			$('#' + grandpa_id + ' .code').fadeOut( 'fast', 'linear', function(){
				$('#' + grandpa_id + ' .code').html( html_sources[grandpa_id_num] );
				$('#' + grandpa_id + ' .code').fadeIn( 'fast', 'linear', function(){
					$('#' + grandpa_id + ' .view-different').children('span').text(link_text_plain);
				} );
			} );
		}

		return false;
	});
});


//EOF
