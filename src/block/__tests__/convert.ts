/**
 * Automatic conversion of legacy snippets, against the real block grammar.
 *
 * The block grammar runs over a post before any of this plugin's code does, so
 * a snippet whose code quotes a block delimiter is taken apart before there is
 * anything to convert. Two of the four ways that happens damage content which
 * has nothing to do with this plugin — an unclosed opener swallows the rest of
 * the post, a stray closer stops the parse dead — and none of it is visible to
 * either PHPUnit tier. So the fixtures below are run through `@wordpress/blocks`
 * itself rather than through a description of what it does.
 */

import { createElement, RawHTML } from '@wordpress/element';
import {
	parse,
	registerBlockType,
	setFreeformContentHandlerName,
	unregisterBlockType,
} from '@wordpress/blocks';

import { BLOCK_NAME } from '../attributes';
import { planConversion } from '../convert';

import metadata from '../block.json';

const FREEFORM_BLOCK = 'core/freeform';
const MARKER_BLOCK = 'test/marker';

const TAGS = [ 'php', 'css', 'sourcecode' ];

/**
 * A block whose whole markup is its two delimiters, so that a fixture can quote
 * one without the parse of it ever being invalid.
 */
const MARKER = {
	void: `<!-- wp:${ MARKER_BLOCK } /-->`,
	open: `<!-- wp:${ MARKER_BLOCK } -->`,
	close: `<!-- /wp:${ MARKER_BLOCK } -->`,
};

interface TestBlock {
	name: string;
	attributes?: Record< string, unknown >;
	innerBlocks?: TestBlock[];
}

type StoreBlocks = Parameters< typeof planConversion >[ 1 ];

/**
 * Reads a post the way the editor reads it, `autop()` and all.
 *
 * @param content Post content.
 */
function asStoreBlocks( content: string ): StoreBlocks {
	return parse( content ) as unknown as StoreBlocks;
}

/**
 * The blocks a plan would put on screen, flattened for reading.
 *
 * @param content Post content.
 */
function planFor( content: string ) {
	return planConversion( content, asStoreBlocks( content ), TAGS );
}

/**
 * Every block a plan replaces with, in order.
 *
 * @param content Post content.
 */
function blocksFor( content: string ): TestBlock[] {
	const plan = planFor( content );

	if ( plan === null ) {
		return [];
	}

	return plan.replacements.flatMap(
		( replacement ) => replacement.blocks as unknown as TestBlock[]
	);
}

/**
 * The content of one Classic block.
 *
 * @param block Block to read.
 */
function classicContent( block: TestBlock | undefined ): string {
	return typeof block?.attributes?.content === 'string'
		? block.attributes.content
		: '';
}

/**
 * The code one snippet block carries.
 *
 * @param block Block to read.
 */
function snippetCode( block: TestBlock | undefined ): string {
	return typeof block?.attributes?.code === 'string'
		? block.attributes.code
		: '';
}

beforeAll( () => {
	window.igSyntaxHiliterEditor = {
		languages: [ { id: 'php', title: 'PHP' } ],
		languageAliases: { php: 'php' },
		noLanguage: 'none',
		legacyTags: TAGS,
		genericTag: 'sourcecode',
		defaultLineNumbers: true,
	};

	/*
	 * Both of these keep whatever inner HTML they are given and save it back
	 * verbatim, which is the shape `core/freeform` itself has. It means every
	 * fixture below — including the ones where the grammar swallows half the post
	 * into a block — parses to something valid, so the only console output a run
	 * produces is output worth reading.
	 */
	const rawBlock = {
		category: 'text',
		attributes: { content: { type: 'string', source: 'raw' } },
		save: ( { attributes }: { attributes: { content: string } } ) =>
			createElement( RawHTML, null, attributes.content ),
	};

	registerBlockType( FREEFORM_BLOCK, {
		...rawBlock,
		apiVersion: 3,
		title: 'Classic',
	} as never );

	setFreeformContentHandlerName( FREEFORM_BLOCK );

	registerBlockType( MARKER_BLOCK, {
		...rawBlock,
		apiVersion: 3,
		title: 'Marker',
	} as never );

	registerBlockType( metadata as never, { save: () => null } as never );
} );

afterAll( () => {
	unregisterBlockType( BLOCK_NAME );
	unregisterBlockType( MARKER_BLOCK );
	setFreeformContentHandlerName( '' );
	unregisterBlockType( FREEFORM_BLOCK );

	delete window.igSyntaxHiliterEditor;
} );

describe( 'planConversion, on a post the grammar reads correctly', () => {
	it( 'leaves a post with no snippet in it alone', () => {
		expect( planFor( 'Nothing to see here.' ) ).toBeNull();
		expect( planFor( 'A [note] and a [list].' ) ).toBeNull();
	} );

	it( 'lifts a snippet out of the Classic block around it', () => {
		const content = 'before\n\n[php]echo 1;[/php]\n\nafter';
		const store = asStoreBlocks( content );
		const plan = planConversion( content, store, TAGS );
		const clientIds = (
			store as unknown as Array< { clientId: string } >
		 ).map( ( block ) => block.clientId );

		expect( plan ).not.toBeNull();
		expect( plan?.root ).toBe( false );
		expect( plan?.replacements ).toHaveLength( 1 );

		// The one Classic block is replaced where it stands, so nothing else moves.
		expect( clientIds ).toHaveLength( 1 );
		expect( plan?.replacements[ 0 ]?.clientIds ).toEqual( clientIds );

		const blocks = plan?.replacements[ 0 ]?.blocks as unknown as
			| TestBlock[]
			| undefined;

		expect( blocks?.map( ( block ) => block.name ) ).toEqual( [
			FREEFORM_BLOCK,
			BLOCK_NAME,
			FREEFORM_BLOCK,
		] );
		expect( snippetCode( blocks?.[ 1 ] ) ).toBe( 'echo 1;' );
		expect( classicContent( blocks?.[ 0 ] ) ).toBe( 'before\n\n' );
		expect( classicContent( blocks?.[ 2 ] ) ).toBe( '\n\nafter' );
	} );

	it( 'takes the code from the stored bytes, not from the block on screen', () => {
		/*
		 * `autop()` has already turned every newline in the store's copy into a
		 * `<br />`. A conversion reading from there would freeze that into the post.
		 */
		const blocks = blocksFor( '[php]one\n\ntwo[/php]' );

		expect( snippetCode( blocks[ 0 ] ) ).toBe( 'one\n\ntwo' );
	} );

	it( 'leaves an escaped snippet as the text it is', () => {
		expect( planFor( 'see [[php]echo 1;[/php]] for how' ) ).toBeNull();
	} );

	/*
	 * A snippet whose code quotes this plugin's own tags writes them with doubled
	 * brackets, which is what the revert tool writes and what an author writing about
	 * the plugin types. The matcher has to step over the escaped closing tag rather
	 * than end the snippet on it, and the code has to arrive in the block holding the
	 * tags the author typed.
	 */
	it( "reads a snippet whose code quotes this plugin's own tags", () => {
		const blocks = blocksFor(
			'[sourcecode language="php"]\n[[sourcecode language="php"]]\nx\n[[/sourcecode]]\n[/sourcecode]'
		);

		expect( blocks.map( ( block ) => block.name ) ).toEqual( [
			BLOCK_NAME,
		] );
		expect( snippetCode( blocks[ 0 ] ) ).toBe(
			'[sourcecode language="php"]\nx\n[/sourcecode]'
		);
	} );

	it( 'leaves an empty snippet alone', () => {
		expect( planFor( '[php][/php]' ) ).toBeNull();
	} );

	it( 'leaves a shortcode inside another block alone', () => {
		const content = `${ MARKER.open }[php]echo 1;[/php]${ MARKER.close }`;

		expect( planFor( content ) ).toBeNull();
	} );
} );

describe( 'planConversion, on a post the grammar took apart', () => {
	it( "repairs a snippet quoting this plugin's own delimiter", () => {
		const code = `<!-- wp:${ BLOCK_NAME } {"language":"php"} /-->`;
		const content = `before\n\n[php]${ code }[/php]\n\nafter`;

		// The block list on screen is not this post: the grammar split it in three.
		expect( asStoreBlocks( content ) ).toHaveLength( 3 );

		const plan = planFor( content );

		expect( plan?.root ).toBe( true );

		const blocks = blocksFor( content );

		expect( blocks.map( ( block ) => block.name ) ).toEqual( [
			FREEFORM_BLOCK,
			BLOCK_NAME,
			FREEFORM_BLOCK,
		] );
		expect( snippetCode( blocks[ 1 ] ) ).toBe( code );
		expect( classicContent( blocks[ 2 ] ) ).toBe( '\n\nafter' );
	} );

	it( "repairs a snippet quoting another block's paired delimiters", () => {
		const code = `${ MARKER.open }${ MARKER.close }`;
		const blocks = blocksFor( `[php]${ code }[/php]\n\nafter` );

		expect( blocks.map( ( block ) => block.name ) ).toEqual( [
			BLOCK_NAME,
			FREEFORM_BLOCK,
		] );
		expect( snippetCode( blocks[ 0 ] ) ).toBe( code );
	} );

	it( 'repairs a snippet quoting an unclosed opener, which swallowed the post', () => {
		const content = `[php]${ MARKER.open }[/php]\n\nthe rest of the post`;

		/*
		 * Left to the grammar, everything from the opener to the end of the document
		 * becomes that block's inner HTML — and a closing delimiter nobody wrote
		 * appears in the post the moment it is saved.
		 */
		const store = asStoreBlocks( content ) as unknown as TestBlock[];

		expect( store.at( -1 )?.name ).toBe( MARKER_BLOCK );

		const blocks = blocksFor( content );

		expect( blocks.map( ( block ) => block.name ) ).toEqual( [
			BLOCK_NAME,
			FREEFORM_BLOCK,
		] );
		expect( snippetCode( blocks[ 0 ] ) ).toBe( MARKER.open );
		expect( classicContent( blocks[ 1 ] ) ).toBe(
			'\n\nthe rest of the post'
		);
	} );

	it( 'repairs a snippet quoting a stray closer, which stopped the parse', () => {
		const content = `[php]${ MARKER.close }[/php]\n\n${ MARKER.void }`;

		// Parsing stops at the stray closer, so the real block after it is lost.
		const store = asStoreBlocks( content ) as unknown as TestBlock[];

		expect( store.some( ( block ) => block.name === MARKER_BLOCK ) ).toBe(
			false
		);

		const blocks = blocksFor( content );

		expect( blocks.map( ( block ) => block.name ) ).toEqual( [
			BLOCK_NAME,
			MARKER_BLOCK,
		] );
		expect( snippetCode( blocks[ 0 ] ) ).toBe( MARKER.close );
	} );

	it( 'repairs a post whose only snippet is escaped, converting nothing', () => {
		const content = `[[php]${ MARKER.open }${ MARKER.close }[/php]]\n\nafter`;
		const blocks = blocksFor( content );

		expect( blocks.map( ( block ) => block.name ) ).toEqual( [
			FREEFORM_BLOCK,
		] );
		expect( classicContent( blocks[ 0 ] ) ).toBe( content );
	} );

	it( 'never leaves a placeholder behind', () => {
		const contents = [
			`before\n\n[php]${ MARKER.open }${ MARKER.close }[/php]\n\nafter`,
			`[php]${ MARKER.open }[/php]\n\ntail`,
			`[php]${ MARKER.close }[/php]\n\n${ MARKER.void }`,
			'plain [php]echo 1;[/php] post',
		];

		for ( const content of contents ) {
			for ( const block of blocksFor( content ) ) {
				expect( classicContent( block ) ).not.toContain( '{igshx' );
				expect( snippetCode( block ) ).not.toContain( '{igshx' );
			}
		}
	} );
} );

describe( 'planConversion, when it must decline', () => {
	it( 'declines when the block list on screen is not this post', () => {
		const storeBlocks = asStoreBlocks( 'a different post entirely' );

		expect(
			planConversion(
				'before [php]echo 1;[/php] after',
				storeBlocks,
				TAGS
			)
		).toBeNull();
	} );

	it( 'declines when the plugin claims no tags', () => {
		const content = 'before [php]echo 1;[/php] after';

		expect(
			planConversion( content, asStoreBlocks( content ), [] )
		).toBeNull();
	} );

	it( 'is idempotent: a converted post has nothing left to convert', () => {
		const content =
			'before\n\n<!-- wp:igsyntax-hiliter/code {"code":"[php]echo 1;[/php]","language":"php"} /-->\n\nafter';

		expect( planFor( content ) ).toBeNull();
	} );

	it( 'never lifts a snippet out of a block delimiter', () => {
		/*
		 * `serialize_block_attributes()` escapes `<`, `>`, `&` and `--`, but neither
		 * bracket — so a snippet's own code, sitting in a block attribute, is
		 * matchable. A match beginning there could run past the end of the delimiter
		 * and take it with it.
		 */
		const content =
			'before\n\n<!-- wp:igsyntax-hiliter/code {"code":"[php]","language":"php"} /-->\n\nafter [/php] here';

		expect( planFor( content ) ).toBeNull();
	} );
} );
