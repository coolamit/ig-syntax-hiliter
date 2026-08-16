<?php
/**
 * The plugin's settings page.
 *
 * Every setting saves on its own, the moment it is changed. There is no form and
 * no submit button, so the markup below is controls and labels only.
 *
 * @package iG_Syntax_Hiliter
 *
 * @var string $plugin_name Plugin name, for display.
 * @var array  $settings    Settings to show: name, type, label, description, choices and current value.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Everything here is a template local. The file is only ever required from inside Helper::render_template(), which runs it in a method scope.

?>
<div class="wrap igsh-settings">

	<h1>
	<?php
		printf(
			/* translators: %s: plugin name. */
			esc_html__( '%s Options', 'igsyntax-hiliter' ),
			esc_html( $plugin_name )
		);
		?>
	</h1>

	<p class="igsh-settings__intro"><?php esc_html_e( 'These settings apply to the whole site. Each one saves by itself, as soon as you change it.', 'igsyntax-hiliter' ); ?></p>

	<table class="form-table igsh-settings__table" role="presentation">
		<tbody>
		<?php foreach ( $settings as $setting ) : ?>
			<tr>
				<th scope="row">
					<label for="<?php echo esc_attr( $setting['name'] ); ?>"><?php echo esc_html( $setting['label'] ); ?></label>
				</th>
				<td>
					<?php if ( 'toggle' === $setting['type'] ) : ?>

						<?php
						/*
						 * A button rather than a checkbox. There is no form and no submit button
						 * on this page, so the control carries a value for nobody but the script
						 * which reads it — and wp-admin styles `input[type="checkbox"]` at a
						 * higher specificity than a class of ours, which cut the clickable area
						 * down to a 16px square in the corner of the switch. `role="switch"`
						 * brings the keyboard and the screen reader announcement with it.
						 */
						?>
						<button
							type="button"
							role="switch"
							class="igsh-toggle"
							id="<?php echo esc_attr( $setting['name'] ); ?>"
							data-igsh-option="<?php echo esc_attr( $setting['name'] ); ?>"
							data-igsh-toggle="1"
							title="<?php echo esc_attr( $setting['description'] ); ?>"
							aria-checked="<?php echo ( 'yes' === $setting['value'] ) ? 'true' : 'false'; ?>"
							aria-describedby="<?php echo esc_attr( $setting['name'] ); ?>-description"
						>
							<span class="igsh-toggle__track" aria-hidden="true"></span>
						</button>

					<?php else : ?>

						<select
							class="igsh-settings__select"
							id="<?php echo esc_attr( $setting['name'] ); ?>"
							data-igsh-option="<?php echo esc_attr( $setting['name'] ); ?>"
							title="<?php echo esc_attr( $setting['description'] ); ?>"
							aria-describedby="<?php echo esc_attr( $setting['name'] ); ?>-description"
						>
							<?php foreach ( $setting['choices'] as $choice_value => $choice_label ) : ?>
								<option value="<?php echo esc_attr( $choice_value ); ?>" <?php selected( $setting['value'], $choice_value ); ?>><?php echo esc_html( $choice_label ); ?></option>
							<?php endforeach; ?>
						</select>

					<?php endif; ?>

					<p class="description" id="<?php echo esc_attr( $setting['name'] ); ?>-description"><?php echo esc_html( $setting['description'] ); ?></p>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<h2 class="igsh-revert__heading"><?php esc_html_e( 'Before you deactivate', 'igsyntax-hiliter' ); ?></h2>

	<div class="igsh-revert">

		<p>
			<?php esc_html_e( 'A snippet stored as a block needs this plugin to be active in order to appear at all: switch the plugin off and the block renders as nothing, and the code vanishes from the post. A snippet stored as a shortcode stays visible as text you can do something about.', 'igsyntax-hiliter' ); ?>
		</p>

		<p>
			<?php esc_html_e( 'The button below rewrites every iG:Syntax Hiliter block on this site back into a [sourcecode] shortcode carrying the same code and the same settings. It covers every public post type, and published, draft, pending, scheduled and private content alike — drafts included. Content in the trash is left alone, and nothing outside the blocks themselves is changed.', 'igsyntax-hiliter' ); ?>
		</p>

		<p class="igsh-revert__warning">
			<strong><?php esc_html_e( 'This rewrites your content and it cannot be undone.', 'igsyntax-hiliter' ); ?></strong>
			<?php esc_html_e( 'Post revisions are left switched on, so each rewritten post keeps a revision of what it said before. Take a database backup first if you would rather not rely on that.', 'igsyntax-hiliter' ); ?>
		</p>

		<p>
			<button type="button" class="button button-secondary" id="igsh-revert-blocks"><?php esc_html_e( 'Convert code blocks back to shortcodes', 'igsyntax-hiliter' ); ?></button>
		</p>

		<p class="igsh-revert__progress" id="igsh-revert-progress" hidden>
			<progress id="igsh-revert-meter" value="0" max="1"></progress>
		</p>

		<p class="igsh-revert__status" id="igsh-revert-status" role="status" aria-live="polite"></p>

	</div>

</div>


<?php
//EOF
