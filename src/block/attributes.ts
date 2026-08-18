/**
 * Shared vocabulary of the block: its attributes, the editor data PHP hands
 * over, and the one mapping from legacy shortcode attributes onto block
 * attributes.
 *
 * Both the `shortcode` transform and the automatic conversion of Classic blocks
 * import the mapper from here, so the two paths cannot drift apart.
 */

export const BLOCK_NAME = 'igsyntax-hiliter/code';

/**
 * The block's attributes, mirroring `src/block/block.json`.
 *
 * `showLineNumbers` is optional on purpose: when it is absent the site wide
 * setting decides, which is what `Snippet::from_block_attributes()` implements.
 */
export interface CodeBlockAttributes {
	code: string;
	language: string;
	firstLine: number;
	highlightLines: string;
	file: string;
	showLineNumbers?: boolean;
}

export interface LanguageChoice {
	id: string;
	title: string;
}

/**
 * Everything `iG\Syntax_Hiliter\Block` localises for the editor.
 */
export interface EditorData {
	languages: LanguageChoice[];
	languageAliases: Record< string, string >;
	noLanguage: string;
	legacyTags: string[];
	genericTag: string;
	defaultLineNumbers: boolean;
}

declare global {
	interface Window {
		igSyntaxHiliterEditor?: Partial< EditorData >;
	}
}

const DEFAULT_GENERIC_TAG = 'sourcecode';

/**
 * Mirrors `Language_Registry::NO_LANGUAGE`, and is only ever the fallback: PHP
 * sends the constant over so that the two cannot drift.
 */
const DEFAULT_NO_LANGUAGE = 'none';

/**
 * Keeps the string entries of whatever PHP sent, and nothing else.
 *
 * @param value Value localised for the editor.
 */
function toStringMap( value: unknown ): Record< string, string > {
	if (
		typeof value !== 'object' ||
		value === null ||
		Array.isArray( value )
	) {
		return {};
	}

	const map: Record< string, string > = {};

	for ( const [ key, entry ] of Object.entries( value ) ) {
		if ( typeof entry === 'string' ) {
			map[ key ] = entry;
		}
	}

	return map;
}

/**
 * The last object PHP localised, and what was derived from it.
 *
 * Keyed on the source object's *identity*, not on a "have we run yet" flag. The
 * test suite sets and deletes `window.igSyntaxHiliterEditor` between cases, and a
 * plain memo would hand the first case's data to every case after it. Anything
 * which replaces the global — including deleting it, which leaves `undefined` —
 * is a different object and rebuilds. The one thing this no longer notices is a
 * third party mutating the same object in place after it has been read once, and
 * nothing in the plugin does that.
 */
let cachedSource: unknown;
let cachedData: EditorData | null = null;

/**
 * Reads the data PHP localised for the editor.
 *
 * The result is derived rather than returned as it stands — the alias map alone
 * runs to over a hundred entries — and it is memoised because the two hot paths
 * both call this far more often than the data can change. `edit.tsx` calls it in
 * its render body, and `PlainText` is controlled, so every character typed into a
 * code block used to rebuild the whole thing; `mapShortcodeAttributes()` reaches
 * it three times for every snippet converted out of a Classic block.
 *
 * The tag list is never hardcoded here. When PHP has said nothing, the list is
 * empty and nothing is claimed — a tag this plugin has never shipped belongs to
 * somebody else and must be left alone.
 */
export function getEditorData(): EditorData {
	if (
		cachedData !== null &&
		cachedSource === window.igSyntaxHiliterEditor
	) {
		return cachedData;
	}

	const data = window.igSyntaxHiliterEditor ?? {};

	cachedSource = window.igSyntaxHiliterEditor;

	cachedData = {
		languages: Array.isArray( data.languages ) ? data.languages : [],
		languageAliases: toStringMap( data.languageAliases ),
		noLanguage:
			typeof data.noLanguage === 'string' && data.noLanguage !== ''
				? data.noLanguage
				: DEFAULT_NO_LANGUAGE,
		legacyTags: Array.isArray( data.legacyTags ) ? data.legacyTags : [],
		genericTag:
			typeof data.genericTag === 'string' && data.genericTag !== ''
				? data.genericTag
				: DEFAULT_GENERIC_TAG,
		defaultLineNumbers: data.defaultLineNumbers !== false,
	};

	return cachedData;
}

/**
 * Turns a language name as an author wrote it into the id the block stores.
 *
 * The server resolves a language late, when it renders. That is right for
 * rendering and wrong for storing: the inspector's dropdown is built from
 * canonical ids alone, so a block holding `html` matches no option, the control
 * shows the first one instead, and touching it writes that back and destroys a
 * language which was highlighting perfectly well.
 *
 * An unrecognised name is handed straight back rather than replaced with a
 * default. It is the author's own word, it is what the snippet has said for as
 * long as the post has existed, and the site may yet add the language through
 * the `ig_syntax_hiliter/languages` filter — a name overwritten here could never
 * be recovered. It renders as an unhighlighted box until then, which is what it
 * did before.
 *
 * @param value Language name, alias or legacy tag as the author wrote it.
 */
export function resolveLanguage( value: string ): string {
	const normalized = value.toLowerCase().trim();

	if ( normalized === '' ) {
		return '';
	}

	const { languages, languageAliases, noLanguage } = getEditorData();
	const resolved = languageAliases[ normalized ] ?? normalized;

	// The sentinel names no language: `[code]` and `[text]` mean "show it, do not highlight it".
	if ( resolved === noLanguage ) {
		return '';
	}

	return languages.some( ( choice ) => choice.id === resolved )
		? resolved
		: normalized;
}

/**
 * The shortcode tags the plugin claims, as PHP reported them.
 */
export function getLegacyTags(): string[] {
	return getEditorData().legacyTags;
}

/**
 * Escapes a value so it can stand for itself inside a regular expression.
 *
 * @param value Value to escape.
 */
export function escapeForRegExp( value: string ): string {
	return value.replace( /[\\^$.*+?()[\]{}|]/g, '\\$&' );
}

/**
 * Reads an escaped tag inside a snippet back as the text it stands for.
 *
 * A snippet ends at its own closing tag, so one whose code quotes this plugin's
 * tags writes them with doubled brackets — `[[php]]` for the text `[php]`,
 * `[[/php]]` for the text `[/php]`. The matcher steps over the doubled form
 * instead of closing on it, and this is where the brackets come back off.
 *
 * The mirror of `Legacy_Map::escape_tags()` in PHP, and the counterpart of
 * `Shortcode_Handler::build_snippet()`, which does exactly this on the display
 * path. It belongs here rather than in either caller because both ways a
 * shortcode becomes a block — the paste transform and the automatic conversion —
 * go through `mapShortcodeAttributes()`, so doing it once is what keeps the two
 * from drifting.
 *
 * Stored content is never touched: an author who typed `[[/php]]` keeps those
 * bytes in their post, and one level of nesting falls out of the rule rather than
 * being special cased.
 *
 * @param code Source code, as the matcher found it.
 */
export function unescapeTags( code: string ): string {
	const tags = getLegacyTags();

	if ( tags.length === 0 || ! code.includes( '[[' ) ) {
		return code;
	}

	const alternation = tags.map( escapeForRegExp ).join( '|' );
	const pattern = new RegExp(
		`\\[(\\[\\/?(?:${ alternation })(?![\\w-])[^\\]]*\\])\\]`,
		'gi'
	);

	return code.replace( pattern, '$1' );
}

/**
 * Named attributes of a shortcode, as `@wordpress/shortcode` parses them.
 */
export type ShortcodeNamedAttributes = Record< string, string | undefined >;

function normalizeAttributeNames(
	atts: ShortcodeNamedAttributes
): ShortcodeNamedAttributes {
	const normalized: ShortcodeNamedAttributes = {};

	for ( const [ key, value ] of Object.entries( atts ) ) {
		if ( typeof value !== 'string' ) {
			continue;
		}

		normalized[ key.toLowerCase().trim() ] = value;
	}

	return normalized;
}

function readAttribute( atts: ShortcodeNamedAttributes, name: string ): string {
	const value = atts[ name ];

	return typeof value === 'string' ? value.trim() : '';
}

/**
 * `intval()` followed by `abs()`, with PHP's leading-digits parsing.
 *
 * @param value Attribute value as the author wrote it.
 */
function toPositiveInteger( value: string ): number {
	const parsed = parseInt( value, 10 );

	return Number.isNaN( parsed ) ? 0 : Math.abs( parsed );
}

/**
 * A `yes`/`no` attribute value, or `undefined` when the author expressed no
 * opinion.
 *
 * @param value Attribute value as the author wrote it.
 */
function yesNoToBoolean( value: string ): boolean | undefined {
	const normalized = value.toLowerCase().trim();

	if ( normalized === 'yes' ) {
		return true;
	}

	if ( normalized === 'no' ) {
		return false;
	}

	return undefined;
}

/**
 * Turns one legacy shortcode into this block's attributes.
 *
 * Mirrors `Snippet::from_shortcode_atts()` and `Shortcode_Handler::build_snippet()`:
 * a named language tag names its own language, `[sourcecode]` carries it in an
 * attribute with `lang` as a fallback spelling, the first line number is the
 * larger of `firstline` and `num` rather than one falling back to the other,
 * `highlight` keeps its `"2,4-6"` string form, and `gutter` is the per snippet
 * line numbers switch. `plaintext`, `toolbar` and `strict_mode` are parsed and
 * dropped — they never reach a block attribute or the markup.
 *
 * The language is resolved here, on the way in, rather than left for the server
 * to resolve on the way out. See `resolveLanguage()`.
 *
 * The code has one pair of brackets taken off every escaped tag in it, which is
 * what makes block to shortcode and back again byte exact. See `unescapeTags()`.
 *
 * @param tag  Shortcode tag that was matched.
 * @param atts Named shortcode attributes.
 * @param code Shortcode content, ie. the source code.
 */
export function mapShortcodeAttributes(
	tag: string,
	atts: ShortcodeNamedAttributes,
	code: string
): CodeBlockAttributes {
	const normalized = normalizeAttributeNames( atts );
	const normalizedTag = tag.toLowerCase().trim();

	let language = readAttribute( normalized, 'language' );

	if ( language === '' ) {
		language = readAttribute( normalized, 'lang' );
	}

	if ( normalizedTag !== getEditorData().genericTag ) {
		language = normalizedTag;
	}

	const attributes: CodeBlockAttributes = {
		code: unescapeTags( code ).trim(),
		language: resolveLanguage( language ),
		firstLine: Math.max(
			1,
			toPositiveInteger( readAttribute( normalized, 'num' ) ),
			toPositiveInteger( readAttribute( normalized, 'firstline' ) )
		),
		highlightLines: readAttribute( normalized, 'highlight' ),
		file: readAttribute( normalized, 'file' ),
	};

	const gutter = yesNoToBoolean( readAttribute( normalized, 'gutter' ) );

	if ( gutter !== undefined ) {
		attributes.showLineNumbers = gutter;
	}

	return attributes;
}
