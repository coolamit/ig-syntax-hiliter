/**
 * Pasting a legacy snippet into the block editor.
 *
 * A paste of plain text does not reach the shortcode transform as plain text.
 * `pasteHandler()` decides the paste is plain, runs the whole clipboard through
 * its markdown converter — `marked`, configured `gfm: true, breaks: true` — and
 * only then looks for shortcodes in the HTML that came out. Every newline is a
 * `<br>` by then, and the transform used to store that verbatim: a post copied
 * out of an HTML source view came back with `<br />` on the end of every line of
 * code.
 *
 * The whole of that chain belongs to `@wordpress/blocks`, so the fixtures below
 * go through `pasteHandler()` itself rather than through a description of it.
 * Writing the description was tried first and it was wrong twice over — the
 * markdown pass does not escape an inline HTML run, and it does not keep a blank
 * line as anything rich text can see. Neither PHPUnit tier can reach any of this.
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
	 * `transforms.ts` reads the claimed tag list at module scope, the way the
	 * editor bundle does — after PHP has printed the data object above it. An
	 * `import` is hoisted above everything in this file, so it has to be required
	 * here or the shortcode transform claims no tags at all and never fires.
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
	/*
	 * The defect as it was reported: a post copied out of an HTML source view and
	 * pasted into the editor came back with a `<br />` on the end of every line.
	 */
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
	 * The reason the repair works on the string and never parses it as HTML.
	 * `marked` passes an inline HTML run through untouched, so this arrives holding
	 * a literal `<?php … $a < $b && $c > $d` — and an HTML parser eats every byte
	 * from that `<?` to the first `>` after it. Reading it as HTML was tried, and
	 * it stored `$d ) {}\n?>` and threw the rest of the snippet away.
	 */
	it( 'keeps a snippet an HTML parser would have eaten', () => {
		const code = '<?php\nif ( $a < $b && $c > $d ) {\n\techo "x";\n}\n?>';

		expect( pastedCode( `[php]\n${ code }\n[/php]` ) ).toBe( code );
	} );

	/*
	 * The other half of that. Only the exact bytes `marked` emits for a line break
	 * are read as one, so the author's own tag survives being pasted.
	 */
	it( 'keeps a line break the author typed', () => {
		const code = 'one<br />two<br/>three';

		expect( pastedCode( `[html]\n${ code }\n[/html]` ) ).toBe( code );
	} );

	/*
	 * Markdown turns a blank line into a paragraph boundary, which is neither a
	 * newline nor anything rich text has a notion of. Left alone, two stanzas of
	 * code came back run together on one line.
	 */
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
