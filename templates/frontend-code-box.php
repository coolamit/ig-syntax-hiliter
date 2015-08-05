<?php
/*
 * Template for showing code box
 */
?>
<div id="<?php echo esc_attr( $id_prefix . $counter ); ?>" class="syntax_hilite">

	<?php if ( $attrs['toolbar'] === true ) { ?>
	<div class="toolbar">

		<div class="view-different-container">
			<?php if ( $attrs['plain_text'] === true ) { ?>
			<a href="#" class="view-different">&lt; View <span><?php echo esc_html( $plain_text ); ?></span> &gt;</a>
			<?php } ?>
		</div>

		<div class="language-name"><?php echo esc_html( $attrs['language'] ); ?></div>

		<?php if ( ! empty( $attrs['file'] ) ) { ?>
		<div class="filename" title="<?php echo esc_attr( $attrs['file'] ); ?>"><?php echo esc_html( $file_path ); ?></div>
		<?php } ?>

		<br clear="both">

	</div>
	<?php } ?>

	<div class="code">
		<?php echo wp_kses_post( $code ); ?>
	</div>

</div>
