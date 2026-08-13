/**
 * Editor UI for the code snippet block.
 *
 * The code is edited in a `PlainText` textarea, never `RichText`: rich text
 * would treat the snippet as formatted HTML, which is exactly the corruption
 * this block exists to prevent.
 */

import { __ } from '@wordpress/i18n';
import {
	InspectorControls,
	PlainText,
	useBlockProps,
} from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	TextControl,
	ToggleControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis -- @wordpress/components exports no stable number control; TextControl type="number" gives a worse first line field.
	__experimentalNumberControl as NumberControl,
} from '@wordpress/components';

import { getEditorData } from './attributes';
import type { CodeBlockAttributes } from './attributes';

interface EditProps {
	attributes: CodeBlockAttributes;
	setAttributes: ( attributes: Partial< CodeBlockAttributes > ) => void;
}

export default function Edit( { attributes, setAttributes }: EditProps ) {
	const { code, language, showLineNumbers, firstLine, highlightLines, file } =
		attributes;

	const { languages, defaultLineNumbers } = getEditorData();

	const languageOptions = [
		{ value: '', label: __( 'None (plain text)', 'igsyntax-hiliter' ) },
		...languages.map( ( choice ) => ( {
			value: choice.id,
			label: choice.title,
		} ) ),
	];

	const blockProps = useBlockProps();

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __( 'Snippet settings', 'igsyntax-hiliter' ) }
				>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Language', 'igsyntax-hiliter' ) }
						value={ language }
						options={ languageOptions }
						onChange={ ( value: string ) =>
							setAttributes( { language: value } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Show line numbers', 'igsyntax-hiliter' ) }
						help={ __(
							'Unset, this follows the site wide setting.',
							'igsyntax-hiliter'
						) }
						checked={ showLineNumbers ?? defaultLineNumbers }
						onChange={ ( value: boolean ) =>
							setAttributes( { showLineNumbers: value } )
						}
					/>
					<NumberControl
						__next40pxDefaultSize
						label={ __( 'First line number', 'igsyntax-hiliter' ) }
						min={ 1 }
						value={ firstLine }
						onChange={ ( value?: string ) => {
							const parsed = parseInt( value ?? '', 10 );

							setAttributes( {
								firstLine: Number.isNaN( parsed )
									? 1
									: Math.max( 1, Math.abs( parsed ) ),
							} );
						} }
					/>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Highlighted lines', 'igsyntax-hiliter' ) }
						help={
							// eslint-disable-next-line @wordpress/i18n-hyphenated-range -- the hyphen is the syntax the author types into this field, not a range in prose. An en dash here would document something the parser does not accept.
							__(
								'Line numbers and ranges, eg. 2,4-6.',
								'igsyntax-hiliter'
							)
						}
						value={ highlightLines }
						onChange={ ( value: string ) =>
							setAttributes( { highlightLines: value } )
						}
					/>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'File label', 'igsyntax-hiliter' ) }
						help={ __(
							'Shown above the snippet, eg. functions.php.',
							'igsyntax-hiliter'
						) }
						value={ file }
						onChange={ ( value: string ) =>
							setAttributes( { file: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				<PlainText
					className="igsh-code-block__input"
					value={ code }
					onChange={ ( value: string ) =>
						setAttributes( { code: value } )
					}
					placeholder={ __(
						'Write or paste code…',
						'igsyntax-hiliter'
					) }
					aria-label={ __( 'Source code', 'igsyntax-hiliter' ) }
				/>
			</div>
		</>
	);
}
