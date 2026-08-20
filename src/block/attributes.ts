/**
 * The block's attributes, the editor data PHP hands over, and the one mapping
 * from legacy shortcode attributes onto block attributes — shared by the
 * shortcode transform and the Classic-block conversion.
 */

export const BLOCK_NAME = 'igsyntax-hiliter/code';

/**
 * The block's attributes, mirroring `src/block/block.json`.
 *
 * `showLineNumbers` is optional: absent means the site-wide setting decides.
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
 * Fallback only; PHP sends `Language_Registry::NO_LANGUAGE` over.
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
 * Keyed on the identity of the source object, not a "have we run" flag, so
 * replacing or deleting the global rebuilds.
 */
let cachedSource: unknown;
let cachedData: EditorData | null = null;

/**
 * Reads the data PHP localised for the editor.
 *
 * Memoised because both hot paths call it far more often than the data can
 * change. The tag list is never hardcoded — when PHP has said nothing,
 * nothing is claimed.
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
 * Resolved on the way in, not at render: the inspector dropdown is built from
 * canonical ids, so a block holding `html` matches no option, the control
 * shows the first one, and touching it writes that back. An unrecognised name
 * is handed back rather than defaulted — the `ig_syntax_hiliter/languages`
 * filter may yet add it, and an overwritten name cannot be recovered.
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
 * A snippet ends at its own closing tag, so code quoting this plugin's tags
 * writes them with doubled brackets; this is where the brackets come off.
 * Mirrors `Legacy_Map::escape_tags()`. Stored content is never touched.
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
 * Mirrors `Snippet::from_shortcode_atts()`: a named tag names its language,
 * `[sourcecode]` carries it in `language` with `lang` as a fallback spelling,
 * first line is the larger of `firstline` and `num`, `highlight` keeps its
 * `2,4-6` string, `gutter` is the per-snippet line-numbers switch.
 * `plaintext`, `toolbar`, `strict_mode` are parsed and dropped.
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
