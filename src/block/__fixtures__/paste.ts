/**
 * Block registrations and the paste helper the editor tests share.
 *
 * Not under `__tests__/` because the jest preset collects every file there as
 * a suite.
 */

import { createElement, RawHTML } from '@wordpress/element';
import {
	pasteHandler,
	registerBlockType,
	setFreeformContentHandlerName,
	unregisterBlockType,
} from '@wordpress/blocks';

export const FREEFORM_BLOCK = 'core/freeform';
export const MISSING_BLOCK = 'core/missing';
export const PARAGRAPH_BLOCK = 'core/paragraph';

export interface PastedBlock {
	name: string;
	attributes?: Record< string, unknown >;
}

/**
 * A block type which keeps whatever inner HTML it is given and saves it back
 * verbatim, the shape `core/freeform` has.
 *
 * `@wordpress/jest-console` fails on unexpected console output, so every
 * fixture must parse to something valid.
 */
export const rawBlock = {
	apiVersion: 3,
	category: 'text',
	attributes: { content: { type: 'string', source: 'raw' } },
	save: ( { attributes }: { attributes: { content: string } } ) =>
		createElement( RawHTML, null, attributes.content ),
};

/**
 * Registers the two blocks a Classic post is parsed with.
 *
 * `core/missing` is the floor under the fallback — without it `createBlock()`
 * recurses until the stack runs out and the failure reads only `RangeError`.
 */
export function registerRawBlocks(): void {
	registerBlockType( FREEFORM_BLOCK, {
		...rawBlock,
		title: 'Classic',
	} as never );

	registerBlockType( MISSING_BLOCK, {
		...rawBlock,
		title: 'Missing',
	} as never );

	setFreeformContentHandlerName( FREEFORM_BLOCK );
}

/**
 * Registers `core/paragraph`, with the raw transform and the schema.
 *
 * The paste schema is built from registered blocks; without `core/paragraph` a
 * `<p>` is not valid content and every paragraph is unwrapped into one.
 */
export function registerParagraph(): void {
	registerBlockType( PARAGRAPH_BLOCK, {
		apiVersion: 3,
		category: 'text',
		title: 'Paragraph',
		attributes: {
			content: { type: 'string', source: 'html', selector: 'p' },
		},
		transforms: {
			from: [
				{
					type: 'raw',
					priority: 20,
					selector: 'p',
					schema: ( {
						phrasingContentSchema,
					}: {
						phrasingContentSchema: unknown;
					} ) => ( { p: { children: phrasingContentSchema } } ),
				},
			],
		},
		save: ( { attributes }: { attributes: { content: string } } ) =>
			createElement(
				'p',
				null,
				createElement( RawHTML, null, attributes.content )
			),
	} as never );
}

/**
 * Takes back everything the two functions above registered.
 *
 * @param withParagraph Whether `core/paragraph` was registered too.
 */
export function unregisterRawBlocks( withParagraph = false ): void {
	setFreeformContentHandlerName( '' );

	if ( withParagraph ) {
		unregisterBlockType( PARAGRAPH_BLOCK );
	}

	unregisterBlockType( MISSING_BLOCK );
	unregisterBlockType( FREEFORM_BLOCK );
}

/**
 * The blocks a plain text paste produces.
 *
 * `plainText` with no `HTML` is the branch that runs the clipboard through the
 * markdown converter. `pasteHandler()` logs outside a production build, which
 * `@wordpress/jest-console` fails unless expected.
 *
 * @param text Text on the clipboard.
 */
export function paste( text: string ): PastedBlock[] {
	const blocks = pasteHandler( {
		plainText: text,
		mode: 'BLOCKS',
	} ) as unknown as PastedBlock[];

	expect( console ).toHaveLogged();

	return blocks;
}
