/**
 * Transforms into and out of the code snippet block.
 *
 * Both directions go through `RichTextData`, which decodes and encodes exactly
 * once — the core blocks hold rich text, this block holds raw text in JSON
 * attributes.
 */

import { createBlock } from '@wordpress/blocks';
import { RichTextData } from '@wordpress/rich-text';

import {
	BLOCK_NAME,
	getLegacyTags,
	mapShortcodeAttributes,
} from './attributes';
import type {
	CodeBlockAttributes,
	ShortcodeNamedAttributes,
} from './attributes';

interface ShortcodeMatch {
	shortcode: {
		tag: string;
		content?: string | undefined;
	};
}

/**
 * Reads a rich text attribute as the plain source code it stands for.
 *
 * @param value Attribute value handed over by the block being transformed.
 */
function toPlainCode( value: unknown ): string {
	if ( value instanceof RichTextData ) {
		return value.toPlainText();
	}

	if ( typeof value === 'string' ) {
		return RichTextData.fromHTMLString( value ).toPlainText();
	}

	return '';
}

/**
 * Undoes what the paste handler's markdown pass did to a snippet.
 *
 * `pasteHandler()` runs a plain-text paste through `marked` (`gfm: true,
 * breaks: true`) before looking for shortcodes, so the body arrives with `<br>`
 * for every newline, `</p><p>` for every blank line and `&amp;`/`&lt;`/`&gt;`
 * for those characters. Works on the string and never parses it as HTML:
 * `marked` passes an inline HTML run through untouched, so a PHP snippet
 * arrives holding a literal `<?php … $a < $b`, and an HTML parser would eat
 * everything to the first `>`.
 *
 * @param value Text as the paste handler matched it.
 */
function decodePastedText( value: unknown ): string {
	if ( typeof value !== 'string' ) {
		return toPlainCode( value );
	}

	return value
		.replace( /<\/p>\s*<p[^>]*>/g, '\n\n' )
		.replace( /<br>/g, '\n' )
		.replace( /&lt;/g, '<' )
		.replace( /&gt;/g, '>' )
		.replace( /&amp;/g, '&' );
}

/**
 * Reads every attribute of a pasted shortcode the same way.
 *
 * The attribute string went through the same markdown pass the body did, so a
 * file label of `a&b.java` arrives as `a&amp;b.java`.
 *
 * @param named Attributes as the paste handler matched them.
 */
function decodePastedAttributes(
	named: ShortcodeNamedAttributes
): ShortcodeNamedAttributes {
	const decoded: ShortcodeNamedAttributes = {};

	Object.keys( named ).forEach( ( key ) => {
		decoded[ key ] = decodePastedText( named[ key ] );
	} );

	return decoded;
}

const transforms = {
	from: [
		{
			type: 'block',
			blocks: [ 'core/code', 'core/preformatted', 'core/paragraph' ],
			transform: ( { content }: { content?: unknown } ) =>
				createBlock( BLOCK_NAME, { code: toPlainCode( content ) } ),
		},
		{
			type: 'raw',
			/*
			 * `findTransform()` settles equal priorities by registration order
			 * and core registers first, so the default loses every paste to
			 * `core/code`.
			 */
			priority: 9,
			isMatch: ( node: Element ) =>
				node.nodeName === 'PRE' &&
				node.children.length === 1 &&
				node.firstChild?.nodeName === 'CODE',
			schema: ( {
				phrasingContentSchema,
			}: {
				phrasingContentSchema: unknown;
			} ) => ( {
				pre: {
					children: {
						code: { children: phrasingContentSchema },
					},
				},
			} ),
			transform: ( node: Element ) =>
				createBlock( BLOCK_NAME, { code: node.textContent ?? '' } ),
		},
		{
			type: 'shortcode',
			tag: getLegacyTags(),
			transform: (
				attributes: { named?: ShortcodeNamedAttributes },
				match: ShortcodeMatch
			) =>
				createBlock( BLOCK_NAME, {
					...mapShortcodeAttributes(
						match.shortcode.tag,
						decodePastedAttributes( attributes.named ?? {} ),
						decodePastedText( match.shortcode.content ?? '' )
					),
				} ),
		},
	],
	to: [
		{
			type: 'block',
			blocks: [ 'core/code' ],
			transform: ( { code }: CodeBlockAttributes ) =>
				createBlock( 'core/code', {
					content: RichTextData.fromPlainText( code ),
				} ),
		},
	],
};

export default transforms;
