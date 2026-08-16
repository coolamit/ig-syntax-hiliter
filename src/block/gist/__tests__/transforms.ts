/**
 * Pasting a `[github]` shortcode into the block editor.
 *
 * The shortcode transform on its own cannot convert the form of the shortcode
 * almost everybody writes. `pasteHandler()` runs a plain text paste through its
 * markdown converter first, `gfm` autolinks the bare address inside `gist="…"`,
 * and core then refuses to convert a self-closing shortcode which is followed by
 * the `</a>` the converter itself added. Nothing appeared in the editor and the
 * Gist was lost on save.
 *
 * So these fixtures go through `pasteHandler()` itself rather than through a
 * description of it, the same as the code block's cases next door. Neither
 * PHPUnit tier can reach any of this.
 */

import { createElement, RawHTML } from '@wordpress/element';
import {
	pasteHandler,
	registerBlockType,
	setFreeformContentHandlerName,
	unregisterBlockType,
} from '@wordpress/blocks';

import metadata from '../block.json';
import transforms from '../transforms';

const FREEFORM_BLOCK = 'core/freeform';
const MISSING_BLOCK = 'core/missing';
const PARAGRAPH_BLOCK = 'core/paragraph';

const GIST_URL = 'https://gist.github.com/user/abc123';

interface PastedBlock {
	name: string;
	attributes?: Record< string, unknown >;
}

/**
 * The blocks a plain text paste produces.
 *
 * `plainText` with no `HTML` is what a paste out of a plain text editor looks
 * like, and it is exactly the branch which sends the clipboard through the
 * markdown converter first.
 *
 * `pasteHandler()` logs what it was given and what it made of it whenever the
 * bundle is not a production build, which `@wordpress/jest-console` fails a test
 * for unless it is told to expect it.
 *
 * @param text Text on the clipboard.
 */
function paste( text: string ): PastedBlock[] {
	const blocks = pasteHandler( {
		plainText: text,
		mode: 'BLOCKS',
	} ) as unknown as PastedBlock[];

	expect( console ).toHaveLogged();

	return blocks;
}

/**
 * The address the one pasted Gist block carries.
 *
 * @param text Text on the clipboard.
 */
function pastedUrl( text: string ): string {
	const blocks = paste( text ).filter(
		( block ) => block.name === metadata.name
	);

	expect( blocks ).toHaveLength( 1 );

	const url = blocks[ 0 ]?.attributes?.url;

	return typeof url === 'string' ? url : '';
}

beforeAll( () => {
	/*
	 * Keeps whatever inner HTML it is given and saves it back verbatim, which is
	 * the shape `core/freeform` itself has. `@wordpress/jest-console` fails a test
	 * on any unexpected console output, and an invalid parse produces some.
	 */
	const rawBlock = {
		apiVersion: 3,
		category: 'text',
		attributes: { content: { type: 'string', source: 'raw' } },
		save: ( { attributes }: { attributes: { content: string } } ) =>
			createElement( RawHTML, null, attributes.content ),
	};

	registerBlockType( FREEFORM_BLOCK, {
		...rawBlock,
		title: 'Classic',
	} as never );

	/*
	 * `createBlock()` falls back to `core/missing` for a block this fixture has not
	 * registered, and calls itself to do it — so with `core/missing` absent as well
	 * it recurses until the stack runs out. Nothing here asserts on it; it is the
	 * floor under the fallback.
	 */
	registerBlockType( MISSING_BLOCK, {
		...rawBlock,
		title: 'Missing',
	} as never );

	/*
	 * `core/paragraph` has to be here, and with its raw transform, because the
	 * schema `pasteHandler()` filters a paste against is built from the registered
	 * blocks. Without it a `<p>` is not valid content, every paragraph of the paste
	 * is unwrapped and then run back together into one — which is a paste this
	 * plugin would never see, and a fixture that answers questions about itself
	 * rather than about the editor.
	 */
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

	setFreeformContentHandlerName( FREEFORM_BLOCK );

	registerBlockType(
		metadata as never,
		{
			save: () => null,
			transforms,
		} as never
	);
} );

afterAll( () => {
	unregisterBlockType( metadata.name );
	setFreeformContentHandlerName( '' );
	unregisterBlockType( PARAGRAPH_BLOCK );
	unregisterBlockType( MISSING_BLOCK );
	unregisterBlockType( FREEFORM_BLOCK );
} );

describe( 'pasting a Gist shortcode', () => {
	/*
	 * The defect. The markdown pass autolinks the address, core declines the
	 * shortcode, and before the raw transform existed this paste produced a
	 * paragraph holding a link and no Gist at all.
	 */
	it( 'reads the address out of a paste the markdown pass rewrote', () => {
		expect( pastedUrl( `[github gist="${ GIST_URL }"]` ) ).toBe( GIST_URL );
	} );

	it( 'reads the address of a shortcode with prose around it', () => {
		const blocks = paste(
			`Look at this:\n\n[github gist="${ GIST_URL }"]\n\nand that.`
		);

		expect( blocks.map( ( block ) => block.name ) ).toEqual( [
			PARAGRAPH_BLOCK,
			metadata.name,
			PARAGRAPH_BLOCK,
		] );

		expect( blocks[ 1 ]?.attributes?.url ).toBe( GIST_URL );
	} );

	/*
	 * The older form, which carries no address, is not autolinked and is converted
	 * by the shortcode transform. It is the one shape which worked already.
	 */
	it( 'builds an address out of the id form', () => {
		expect( pastedUrl( '[github id="abc123"]' ) ).toBe(
			'https://gist.github.com/abc123'
		);
	} );

	it( 'leaves a shortcode mentioned inside a sentence alone', () => {
		const blocks = paste(
			`Write [github gist="${ GIST_URL }"] to embed a Gist.`
		);

		expect(
			blocks.filter( ( block ) => block.name === metadata.name )
		).toHaveLength( 0 );
	} );

	it( 'leaves an ordinary paragraph alone', () => {
		const blocks = paste( 'Nothing to see here.' );

		expect( blocks.map( ( block ) => block.name ) ).toEqual( [
			PARAGRAPH_BLOCK,
		] );
	} );
} );
