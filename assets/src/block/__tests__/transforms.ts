/**
 * Pasting a legacy snippet into the block editor.
 *
 * `pasteHandler()` runs a plain-text paste through `marked` (`gfm`, `breaks`)
 * before looking for shortcodes, so every newline is a `<br>` by then. These
 * fixtures go through `pasteHandler()` itself.
 */

import { registerBlockType, unregisterBlockType } from '@wordpress/blocks';

import {
	paste,
	registerRawBlocks,
	unregisterRawBlocks,
} from '../__fixtures__/paste';

import { BLOCK_NAME } from '../attributes';

import metadata from '../block.json';
import type { PastedBlock } from '../__fixtures__/paste';

const TAGS = [ 'php', 'html', 'sourcecode' ];

/**
 * The one snippet block a paste produced.
 *
 * @param text Text on the clipboard.
 */
function pastedSnippet( text: string ): PastedBlock {
	const blocks = paste( text ).filter(
		( block ) => block.name === BLOCK_NAME
	);

	expect( blocks ).toHaveLength( 1 );

	return blocks[ 0 ] as PastedBlock;
}

/**
 * The code one pasted snippet carries.
 *
 * @param text Text on the clipboard.
 */
function pastedCode( text: string ): string {
	const code = pastedSnippet( text ).attributes?.code;

	return typeof code === 'string' ? code : '';
}

beforeAll( () => {
	window.igSyntaxHiliterEditor = {
		languages: [
			{ id: 'markup', title: 'Markup' },
			{ id: 'php', title: 'PHP' },
		],
		languageAliases: { html: 'markup', php: 'php' },
		noLanguage: 'none',
		legacyTags: TAGS,
		genericTag: 'sourcecode',
		defaultLineNumbers: true,
	};

	registerRawBlocks();

	/*
	 * `transforms.ts` reads the claimed tag list at module scope; an `import`
	 * is hoisted above the data object set above, so it must be `require`d
	 * here.
	 */

	const transforms = require( '../transforms' ).default;

	registerBlockType(
		metadata as never,
		{
			save: () => null,
			transforms,
		} as never
	);
} );

afterAll( () => {
	unregisterBlockType( BLOCK_NAME );
	unregisterRawBlocks();

	delete window.igSyntaxHiliterEditor;
} );

describe( 'pasting a legacy shortcode', () => {
	it( 'keeps a newline a newline', () => {
		const code = [
			'video-conf-app/',
			'├── app.py              # FastAPI backend',
			'├── .env                # Environment variables',
			'└── static',
			'    └── index.html      # Frontend',
		].join( '\n' );

		const pasted = pastedCode( `[php]\n${ code }\n[/php]` );

		expect( pasted ).not.toMatch( /<br\s*\/?>/ );
		expect( pasted ).toBe( code );
	} );

	/*
	 * `marked` passes an inline HTML run through untouched, so this arrives
	 * holding a literal `<?php … $a < $b` and an HTML parser would eat to the
	 * first `>`.
	 */
	it( 'keeps a snippet an HTML parser would have eaten', () => {
		const code = '<?php\nif ( $a < $b && $c > $d ) {\n\techo "x";\n}\n?>';

		expect( pastedCode( `[php]\n${ code }\n[/php]` ) ).toBe( code );
	} );

	// Only the exact bytes `marked` emits for a line break are read as one.
	it( 'keeps a line break the author typed', () => {
		const code = 'one<br />two<br/>three';

		expect( pastedCode( `[html]\n${ code }\n[/html]` ) ).toBe( code );
	} );

	// Markdown turns a blank line into a paragraph boundary, not a newline.
	it( 'keeps a blank line inside the code', () => {
		const code = 'function one() {}\n\nfunction two() {}';

		expect( pastedCode( `[php]\n${ code }\n[/php]` ) ).toBe( code );
	} );

	it( 'keeps a run of spaces, which is what indentation is', () => {
		const code = 'if ( $a ) {\n        echo 1;\n}';

		expect( pastedCode( `[php]\n${ code }\n[/php]` ) ).toBe( code );
	} );

	it( 'reads the language off the tag and the attributes off the shortcode', () => {
		const snippet = pastedSnippet(
			'[sourcecode language="php" firstline="7" highlight="2,4-6" file="a&b.java"]\necho 1;\n[/sourcecode]'
		);

		expect( snippet.attributes?.code ).toBe( 'echo 1;' );
		expect( snippet.attributes?.language ).toBe( 'php' );
		expect( snippet.attributes?.firstLine ).toBe( 7 );
		expect( snippet.attributes?.highlightLines ).toBe( '2,4-6' );

		// The attribute string went through the same markdown pass the body did.
		expect( snippet.attributes?.file ).toBe( 'a&b.java' );
	} );

	it( 'reads a named tag as its own language', () => {
		expect(
			pastedSnippet( '[html]\n<p>x</p>\n[/html]' ).attributes?.language
		).toBe( 'markup' );
	} );
} );
