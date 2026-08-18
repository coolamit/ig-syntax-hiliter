/**
 * The block registrations and the paste helper every editor test needs.
 *
 * **Not under `__tests__/`, deliberately.** `@wordpress/jest-preset-default`
 * matches `**\/__tests__\/**\/*.[jt]s?(x)` — every file in that directory,
 * whatever it is called — so a helper module put there would be collected as a
 * suite and fail for having no tests in it. `__fixtures__` matches none of the
 * three patterns, which is why it needs no jest config of its own.
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
 * verbatim, which is the shape `core/freeform` itself has.
 *
 * `@wordpress/jest-console` fails a test on any unexpected console output, and
 * both a lower api version and an invalid parse produce some — so every fixture,
 * including the ones where the block grammar swallows half a post, has to parse
 * to something valid.
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
 * `core/missing` is not asserted on anywhere; it is the floor under the fallback.
 * `createBlock()` falls back to it for a block the fixture has not registered, and
 * calls itself to do it — so with `core/missing` absent as well it recurses until
 * the stack runs out and the failure reads `RangeError` and nothing else.
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
 * A fixture which drives a paste needs this. The schema `pasteHandler()` filters a
 * paste against is built from whatever blocks are registered, so without it a `<p>`
 * is not valid content: every paragraph of the paste is unwrapped and the whole
 * thing is run back together into one. That is a paste no editor would ever
 * produce, and a fixture which answers questions about itself rather than about
 * the editor.
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
 * `plainText` with no `HTML` is what a paste out of a plain text editor or an HTML
 * source view looks like, and it is exactly the branch which sends the clipboard
 * through the markdown converter first — which is the stage every one of these
 * tests is about.
 *
 * `pasteHandler()` logs what it was given and what it made of it whenever the
 * bundle is not a production build, which `@wordpress/jest-console` fails a test
 * for unless it is told to expect it.
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
