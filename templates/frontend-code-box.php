<?php
/**
 * Template for showing hilited code in a box
 *
 * @package igsyntax-hiliter
 */

if ( empty( $attrs ) || empty( $id_prefix ) || empty( $code ) ) {
	return '';
}
?>
<div id="<?php echo esc_attr( $id_prefix . $counter ); ?>" class="syntax_hilite">

	<?php if ( true === $attrs['toolbar'] ) { ?>
	<div class="toolbar">

		<div class="view-different-container">
			<?php if ( true === $attrs['plain_text'] ) { ?>
			<a href="#" class="view-different">&lt; View <span><?php echo esc_html( $plain_text ); ?></span> &gt;</a>
			<?php } ?>
		</div>

		<div class="language-name"><?php echo esc_html( $attrs['language'] ); ?></div>

		<?php if ( ! empty( $attrs['file'] ) && ! empty( $file_path ) ) { ?>
		<div class="filename" title="<?php echo esc_attr( $attrs['file'] ); ?>"><?php echo esc_html( $file_path ); ?></div>
		<?php } ?>

		<br clear="both">

	</div>
	<?php } ?>

	<div class="code">
		<?php echo wp_kses_post( $code ); ?>
	</div>

</div>
