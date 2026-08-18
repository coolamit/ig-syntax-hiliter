/**
 * The one mapping from legacy shortcode attributes onto block attributes.
 *
 * What is being held in place here is that a snippet converted out of a
 * twenty year old post arrives holding the language id the highlighter uses and
 * the dropdown offers — never the tag the author typed, and never an empty
 * string where the author named something real.
 */

import {
	getEditorData,
	mapShortcodeAttributes,
	resolveLanguage,
	unescapeTags,
} from '../attributes';

/**
 * A cut down version of what `Block::_get_editor_data()` localises.
 */
const EDITOR_DATA = {
	languages: [
		{ id: 'apacheconf', title: 'Apache Configuration' },
		{ id: 'javascript', title: 'JavaScript' },
		{ id: 'markup', title: 'Markup' },
		{ id: 'php', title: 'PHP' },
	],
	languageAliases: {
		apache: 'apacheconf',
		code: 'none',
		html: 'markup',
		html5: 'markup',
		js: 'javascript',
		php: 'php',
		text: 'none',
	},
	noLanguage: 'none',
	legacyTags: [ 'php', 'html', 'js', 'apache', 'code', 'text', 'sourcecode' ],
	genericTag: 'sourcecode',
	defaultLineNumbers: true,
};

beforeEach( () => {
	window.igSyntaxHiliterEditor = EDITOR_DATA;
} );

afterEach( () => {
	delete window.igSyntaxHiliterEditor;
} );

describe( 'getEditorData', () => {
	/*
	 * The memo is keyed on the identity of what PHP localised, and that is the whole
	 * of what makes it safe. A "have we run yet" flag would hand this suite's first
	 * case's data to every case after it, and would hand a real editor stale data
	 * after any code that replaced the global.
	 */
	it( 'hands back the same object while the source object is the same', () => {
		expect( getEditorData() ).toBe( getEditorData() );
	} );

	it( 'rebuilds when the source object is replaced', () => {
		const before = getEditorData();

		window.igSyntaxHiliterEditor = { ...EDITOR_DATA };

		const after = getEditorData();

		expect( after ).not.toBe( before );
		expect( after ).toEqual( before );
	} );

	it( 'rebuilds when the source object is taken away', () => {
		expect( getEditorData().legacyTags ).toEqual( EDITOR_DATA.legacyTags );

		delete window.igSyntaxHiliterEditor;

		expect( getEditorData().legacyTags ).toEqual( [] );
	} );
} );

describe( 'resolveLanguage', () => {
	it( 'turns a legacy tag into the id the dropdown holds', () => {
		expect( resolveLanguage( 'html' ) ).toBe( 'markup' );
		expect( resolveLanguage( 'html5' ) ).toBe( 'markup' );
		expect( resolveLanguage( 'apache' ) ).toBe( 'apacheconf' );
	} );

	it( 'turns a library alias into its canonical id', () => {
		expect( resolveLanguage( 'js' ) ).toBe( 'javascript' );
	} );

	it( 'is case insensitive and whitespace tolerant', () => {
		expect( resolveLanguage( '  HTML ' ) ).toBe( 'markup' );
	} );

	it( 'leaves a canonical id alone', () => {
		expect( resolveLanguage( 'php' ) ).toBe( 'php' );
		expect( resolveLanguage( 'markup' ) ).toBe( 'markup' );
	} );

	it( 'reads the sentinel as no language at all', () => {
		expect( resolveLanguage( 'code' ) ).toBe( '' );
		expect( resolveLanguage( 'text' ) ).toBe( '' );
		expect( resolveLanguage( '' ) ).toBe( '' );
	} );

	/*
	 * The one that matters most. A name nothing recognises is the author's own
	 * word, and it is kept: the `ig_syntax_hiliter/languages` filter can make it good
	 * tomorrow, where a name overwritten here could never recover.
	 */
	it( 'hands back a name it does not recognise, rather than replacing it', () => {
		expect( resolveLanguage( 'rust' ) ).toBe( 'rust' );
		expect( resolveLanguage( ' ZIG ' ) ).toBe( 'zig' );
	} );

	it( 'recognises nothing when PHP has said nothing', () => {
		delete window.igSyntaxHiliterEditor;

		expect( getEditorData().noLanguage ).toBe( 'none' );
		expect( resolveLanguage( 'html' ) ).toBe( 'html' );
	} );
} );

describe( 'mapShortcodeAttributes', () => {
	it( 'resolves the language a named tag stands for', () => {
		expect( mapShortcodeAttributes( 'html', {}, 'x' ).language ).toBe(
			'markup'
		);
		expect( mapShortcodeAttributes( 'PHP', {}, 'x' ).language ).toBe(
			'php'
		);
	} );

	it( 'resolves the language attribute of the generic tag', () => {
		expect(
			mapShortcodeAttributes( 'sourcecode', { language: 'JS' }, 'x' )
				.language
		).toBe( 'javascript' );
	} );

	it( 'falls back to the `lang` spelling of that attribute', () => {
		expect(
			mapShortcodeAttributes( 'sourcecode', { lang: 'apache' }, 'x' )
				.language
		).toBe( 'apacheconf' );
	} );

	it( 'takes the larger of `firstline` and `num`', () => {
		expect(
			mapShortcodeAttributes( 'php', { num: '10', firstline: '4' }, 'x' )
				.firstLine
		).toBe( 10 );
		expect( mapShortcodeAttributes( 'php', {}, 'x' ).firstLine ).toBe( 1 );
	} );

	it( 'reads `gutter` as an opinion the author may not have', () => {
		expect(
			mapShortcodeAttributes( 'php', { gutter: 'no' }, 'x' )
				.showLineNumbers
		).toBe( false );
		expect(
			mapShortcodeAttributes( 'php', { gutter: 'yes' }, 'x' )
				.showLineNumbers
		).toBe( true );
		expect( mapShortcodeAttributes( 'php', {}, 'x' ) ).not.toHaveProperty(
			'showLineNumbers'
		);
	} );

	it( 'keeps the highlight list and the file label as written', () => {
		const attributes = mapShortcodeAttributes(
			'php',
			{ highlight: '2,4-6', file: '~/wp-config.php' },
			'  code  '
		);

		expect( attributes.highlightLines ).toBe( '2,4-6' );
		expect( attributes.file ).toBe( '~/wp-config.php' );
		expect( attributes.code ).toBe( 'code' );
	} );

	/*
	 * The mirror of `Shortcode_Handler::build_snippet()`. A snippet whose code quotes
	 * this plugin's tags writes them with doubled brackets, and a block whose code
	 * still carried them would show the reader an escape they never typed — and would
	 * gain another level every time the revert tool ran.
	 */
	it( 'reads an escaped tag in the code back as the text it stands for', () => {
		expect(
			mapShortcodeAttributes(
				'sourcecode',
				{ language: 'php' },
				'[[sourcecode language="php"]]\nx\n[[/sourcecode]]'
			).code
		).toBe( '[sourcecode language="php"]\nx\n[/sourcecode]' );
	} );
} );

describe( 'unescapeTags', () => {
	it( 'takes one level off, so a quoted escape survives', () => {
		expect( unescapeTags( 'a [[[/php]]] b' ) ).toBe( 'a [[/php]] b' );
	} );

	it( "leaves WordPress's own escape of a whole shortcode alone", () => {
		expect( unescapeTags( '[[php]echo 1;[/php]]' ) ).toBe(
			'[[php]echo 1;[/php]]'
		);
	} );

	it( "leaves a tag which is not this plugin's alone", () => {
		expect( unescapeTags( '[[email]] and [[phpx]]' ) ).toBe(
			'[[email]] and [[phpx]]'
		);
	} );

	it( 'claims nothing when PHP has sent no tag list', () => {
		delete window.igSyntaxHiliterEditor;

		expect( unescapeTags( '[[/php]]' ) ).toBe( '[[/php]]' );
	} );
} );
