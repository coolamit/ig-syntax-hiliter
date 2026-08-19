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
 * @var string $preview     Markup of the code box which previews the chosen theme.
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

	<div class="igsh-settings__layout">

	<div class="igsh-settings__controls">

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

						<span class="igsh-settings__control">

							<select
								class="igsh-settings__select"
								id="<?php echo esc_attr( $setting['name'] ); ?>"
								data-igsh-option="<?php echo esc_attr( $setting['name'] ); ?>"
								title="<?php echo esc_attr( $setting['description'] ); ?>"
								aria-describedby="<?php echo esc_attr( $setting['name'] ); ?>-description"
							>
								<?php
								/*
								 * A setting may carry a `groups` key beside its `choices`, and the font
								 * setting does. `choices` stays a flat allowlist because that is what the
								 * REST route checks a saved value against; the grouping rides beside it and
								 * is a fact about this screen alone, which is why it is assembled here and
								 * not baked into the list.
								 *
								 * Anything a group does not claim is rendered first and ungrouped — that is
								 * how `None` ends up above both groups rather than inside one.
								 */
								$igsh_option_groups = [ '' => array_keys( $setting['choices'] ) ];

								if ( ! empty( $setting['groups'] ) ) {

									$igsh_grouped = array_merge( [], ...array_values( $setting['groups'] ) );

									$igsh_option_groups = [ '' => array_values( array_diff( array_keys( $setting['choices'] ), $igsh_grouped ) ) ];

									foreach ( $setting['groups'] as $igsh_group_label => $igsh_group_slugs ) {
										$igsh_option_groups[ $igsh_group_label ] = $igsh_group_slugs;
									}
								}
								?>
								<?php foreach ( $igsh_option_groups as $igsh_group_label => $igsh_group_slugs ) : ?>

									<?php if ( ! empty( $igsh_group_label ) ) : ?>
										<optgroup label="<?php echo esc_attr( $igsh_group_label ); ?>">
									<?php endif; ?>

									<?php foreach ( $igsh_group_slugs as $choice_value ) : ?>
										<option value="<?php echo esc_attr( $choice_value ); ?>" <?php selected( $setting['value'], $choice_value ); ?>><?php echo esc_html( $setting['choices'][ $choice_value ] ); ?></option>
									<?php endforeach; ?>

									<?php if ( ! empty( $igsh_group_label ) ) : ?>
										</optgroup>
									<?php endif; ?>

								<?php endforeach; ?>
							</select>

							<?php
							/*
							 * The theme list is a reading of what is on disk and is cached for a
							 * week, so this is how a site owner who has just put a theme there
							 * sees it without waiting. It belongs to the theme control alone;
							 * the font list is a literal in PHP and has nothing to reread.
							 */
							?>
							<?php if ( 'theme' === $setting['name'] ) : ?>
								<?php
								/*
								 * One string, said once. The button carries no text of its
								 * own, so it needs both a tooltip and an accessible name —
								 * but writing the same words twice is two POT entries for
								 * one idea and two chances to diverge in translation.
								 */
								$igsh_refresh_label = __( 'Refresh theme cache', 'igsyntax-hiliter' );
								?>
								<button
									type="button"
									class="button igsh-settings__refresh"
									id="igsh-refresh-themes"
									title="<?php echo esc_attr( $igsh_refresh_label ); ?>"
									aria-label="<?php echo esc_attr( $igsh_refresh_label ); ?>"
								>
									<span class="dashicons dashicons-update" aria-hidden="true"></span>
								</button>
							<?php endif; ?>

						</span>

					<?php endif; ?>

					<p class="description" id="<?php echo esc_attr( $setting['name'] ); ?>-description"><?php echo esc_html( $setting['description'] ); ?></p>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	</div>

	<?php
	/*
	 * The preview. Every setting above which changes how a code box looks changes
	 * this box as it is switched, so that picking one of 43 themes does not mean
	 * saving it, opening the front end and coming back.
	 *
	 * `$preview` is the plugin's own renderer's output — the same markup the front
	 * end gets — so it is printed as it stands. The code inside it was escaped on its
	 * way through the renderer, which is the one place snippet code is ever escaped.
	 *
	 * `match-braces` is on the container unconditionally, for the same reason
	 * `Asset_Manager::enqueue_for_preview()` loads every engine plugin whatever the
	 * settings say. That class is the only one the engine reads **once**, while it is
	 * highlighting, so a box which did not carry it at load can never gain the brace
	 * markup afterwards and the toggle beside it would do nothing. The three classes
	 * which decide what is *shown* — the nesting colours and the two which switch the
	 * hover and the click off — are read at paint time and at event time, so those
	 * are the ones the script toggles.
	 */
	?>
	<aside class="igsh-preview match-braces" id="igsh-preview" aria-labelledby="igsh-preview-heading">

		<h2 class="igsh-preview__heading" id="igsh-preview-heading"><?php esc_html_e( 'Preview', 'igsyntax-hiliter' ); ?></h2>

		<p class="description"><?php esc_html_e( 'How a code box looks with the settings on the left. It follows them as you change them, and nothing here is saved.', 'igsyntax-hiliter' ); ?></p>

		<div class="igsh-preview__box" id="igsh-preview-box">
			<?php echo $preview;    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer output. It escapes the code itself, which is the only untrusted part, and escaping the markup again here would print the tags. ?>
		</div>

	</aside>

	</div>

	<h2 class="igsh-revert__heading"><?php esc_html_e( 'Before you deactivate', 'igsyntax-hiliter' ); ?></h2>

	<div class="igsh-revert">

		<p>
			<?php esc_html_e( 'A snippet or a Gist stored as a Gutenberg block needs this plugin to be active in order to appear at all: switch the plugin off and the Gutenberg block renders as nothing and the code vanishes from the post. The same thing stored as a shortcode stays visible as text you can do something about.', 'igsyntax-hiliter' ); ?>
		</p>

		<p>
			<?php esc_html_e( 'The button below rewrites every Gutenberg block of this plugin on this site back into a shortcode: an "iG:Syntax Hiliter" block becomes a [sourcecode] shortcode carrying the same code and the same settings, and an "iG:Syntax Hiliter Gist" block becomes a [github] shortcode naming the same Gist. It covers every public post type having published, draft, pending, scheduled and private statuses alike. Content in the trash is left alone and nothing outside the blocks themselves is changed.', 'igsyntax-hiliter' ); ?>
		</p>

		<p class="igsh-revert__warning">
			<strong><?php esc_html_e( 'This rewrites your content and it cannot be undone.', 'igsyntax-hiliter' ); ?></strong>
			<?php esc_html_e( 'If post revisions are enabled then they are left as is, so each rewritten post keeps a revision of what it said before. Take a database backup first if you would rather not rely on that or if you do not have post revisions enabled.', 'igsyntax-hiliter' ); ?>
		</p>

		<p>
			<button type="button" class="button button-secondary" id="igsh-revert-blocks"><?php esc_html_e( 'Convert blocks back to shortcodes', 'igsyntax-hiliter' ); ?></button>
		</p>

		<p class="igsh-revert__progress" id="igsh-revert-progress" hidden>
			<progress id="igsh-revert-meter" value="0" max="1"></progress>
		</p>

		<p class="igsh-revert__status" id="igsh-revert-status" role="status" aria-live="polite"></p>

	</div>

</div>


<?php
//EOF
