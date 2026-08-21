/**
 * Pasting a `[github]` shortcode into the block editor.
 *
 * `pasteHandler()` runs a plain-text paste through its markdown converter
 * first, `gfm` autolinks the bare address inside `gist="…"`, and core then
 * declines a self-closing shortcode followed by the `</a>` it added. These
 * fixtures go through `pasteHandler()` itself.
 */

import { registerBlockType, unregisterBlockType } from '@wordpress/blocks';

import metadata from '../block.json';
import transforms from '../transforms';
import {
	PARAGRAPH_BLOCK,
	paste,
	registerParagraph,
	registerRawBlocks,
	unregisterRawBlocks,
} from '../../__fixtures__/paste';

const GIST_URL = 'https://gist.github.com/user/abc123';

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
	registerRawBlocks();
	registerParagraph();

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
	unregisterRawBlocks( true );
} );

describe( 'pasting a Gist shortcode', () => {
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

	// The `id` form carries no address and is not autolinked.
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
} );
