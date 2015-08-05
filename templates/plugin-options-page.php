<?php
/*
 * Template for the plugin options page
 */
?>
<div class="wrap">
	<h2><?php echo esc_html( $plugin_name . ' Options' ); ?></h2>

	<p>&nbsp;</p>

	<p>You can change global options here, changes are saved automatically.</p>

	<p>&nbsp;</p>

	<table id="ig-sh-admin-ui" width="60%" border="0">
		<tr>
			<td width="50%"><label for="fe-styles">Use plugin CSS for styling?</label></td>
			<td>
				<select name="fe-styles" id="fe-styles" class="ig-sh-option">
					<option value="yes" <?php selected( $options['fe-styles'], 'yes' ) ?>>YES</option>
					<option value="no" <?php selected( $options['fe-styles'], 'no' ) ?>>NO</option>
				</select>
			</td>
		</tr>
		<tr>
			<td><label for="strict_mode">GeSHi Strict Mode?</label></td>
			<td>
				<select name="strict_mode" id="strict_mode" class="ig-sh-option to-uppercase">
				<?php
				for ( $i = 0; $i < count( $strict_mode_opts ); $i++ ) {
					printf( '<option value="%s" %s>%s</option>', esc_attr( $strict_mode_opts[ $i ] ), selected( $options['strict_mode'], $strict_mode_opts[ $i ], false ), esc_html( strtoupper( $strict_mode_opts[ $i ] ) ) );
				}
				?>
				</select>
			</td>
		</tr>
		<tr>
			<td><label for="non_strict_mode">Languages where GeSHi strict mode is disabled:</label></td>
			<td>
				<textarea name="non_strict_mode" id="non_strict_mode" rows="3" cols="30" class="ig-sh-option"><?php echo esc_html( implode( ', ', $options['non_strict_mode'] ) ) ?></textarea>

				<p class="description">
					Comma separated list of GeSHi language names. Eg. 'C#' would be 'csharp'.
				</p>
			</td>
		</tr>
		<tr>
			<td><label for="toolbar">Show Toolbar?</label></td>
			<td>
				<select name="toolbar" id="toolbar" class="ig-sh-option">
					<option value="yes" <?php selected( $options['toolbar'], 'yes' ) ?>>YES</option>
					<option value="no" <?php selected( $options['toolbar'], 'no' ) ?>>NO</option>
				</select>
			</td>
		</tr>
		<tr>
			<td><label for="plain_text">Show Plain Text Option?</label></td>
			<td>
				<select name="plain_text" id="plain_text" class="ig-sh-option">
					<option value="yes" <?php selected( $options['plain_text'], 'yes' ) ?>>YES</option>
					<option value="no" <?php selected( $options['plain_text'], 'no' ) ?>>NO</option>
				</select>
			</td>
		</tr>
		<tr>
			<td><label for="show_line_numbers">Show line numbers in code?</label></td>
			<td>
				<select name="show_line_numbers" id="show_line_numbers" class="ig-sh-option">
					<option value="yes" <?php selected( $options['show_line_numbers'], 'yes' ) ?>>YES</option>
					<option value="no" <?php selected( $options['show_line_numbers'], 'no' ) ?>>NO</option>
				</select>
			</td>
		</tr>
		<tr>
			<td><label for="hilite_comments">Hilite code in comments?</label></td>
			<td>
				<select name="hilite_comments" id="hilite_comments" class="ig-sh-option">
					<option value="yes" <?php selected( $options['hilite_comments'], 'yes' ) ?>>YES</option>
					<option value="no" <?php selected( $options['hilite_comments'], 'no' ) ?>>NO</option>
				</select>
			</td>
		</tr>
		<tr>
			<td><label for="link_to_manual">Link keywords/function names to Manual (if available)?</label></td>
			<td>
				<select name="link_to_manual" id="link_to_manual" class="ig-sh-option">
					<option value="yes" <?php selected( $options['link_to_manual'], 'yes' ) ?>>YES</option>
					<option value="no" <?php selected( $options['link_to_manual'], 'no' ) ?>>NO</option>
				</select>
			</td>
		</tr>
		<tr>
			<td><label for="gist_in_comments">Enable GitHub Gist embed in comments?</label></td>
			<td>
				<select name="gist_in_comments" id="gist_in_comments" class="ig-sh-option">
					<option value="yes" <?php selected( $options['gist_in_comments'], 'yes' ) ?>>YES</option>
					<option value="no" <?php selected( $options['gist_in_comments'], 'no' ) ?>>NO</option>
				</select>
			</td>
		</tr>
		<tr>
			<td colspan="2">&nbsp;</td>
		</tr>
		<tr>
			<td colspan="2">
				<button id="igsh_refresh_languages" name="igsh_refresh_languages" class="button button-primary" value="rebuild">Rebuild Shorthand Tags</button>
				<p>Click the button above to rebuild the list of shorthand tags. This is needed if you have added any new language file or
				removed any existing language file.</p>
				<p><strong>Note:</strong> This will be automatically done in <span id="igsh-time-to-rebuild"><?php echo esc_html( $human_time_diff ); ?></span></p>
			</td>
		</tr>
	</table>
</div>
