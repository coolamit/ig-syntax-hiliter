/**
 * Editor UI for the Gist block.
 *
 * There is deliberately no live preview. A Gist embed is a third party
 * `<script>` from gist.github.com, and running one inside the editor would put
 * somebody else's code into the page an author is writing in. The editor shows
 * the URL; the front end shows the Gist.
 */

import { __ } from '@wordpress/i18n';
import { useBlockProps } from '@wordpress/block-editor';
import { Placeholder, TextControl } from '@wordpress/components';

interface GistBlockAttributes {
	url: string;
}

interface EditProps {
	attributes: GistBlockAttributes;
	setAttributes: ( attributes: Partial< GistBlockAttributes > ) => void;
}

export default function Edit( { attributes, setAttributes }: EditProps ) {
	const { url } = attributes;

	const blockProps = useBlockProps();

	return (
		<div { ...blockProps }>
			<Placeholder
				icon="editor-code"
				label={ __( 'iG:Syntax Hiliter Gist', 'igsyntax-hiliter' ) }
				instructions={ __(
					'Paste the address of a GitHub Gist. It is embedded when the post is viewed, not here.',
					'igsyntax-hiliter'
				) }
			>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Gist URL', 'igsyntax-hiliter' ) }
					hideLabelFromVision
					placeholder="https://gist.github.com/username/0123456789abcdef"
					value={ url }
					onChange={ ( value: string ) =>
						setAttributes( { url: value } )
					}
				/>
			</Placeholder>
		</div>
	);
}
