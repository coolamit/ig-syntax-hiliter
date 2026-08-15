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
 * Reads the data PHP localised for the editor.
 *
 * The tag list is never hardcoded here. When PHP has said nothing, the list is
 * empty and nothing is claimed — a tag this plugin has never shipped belongs to
 * somebody else and must be left alone.
 */
export function getEditorData(): EditorData {
	const data = window.igSyntaxHiliterEditor ?? {};

	return {
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
 * default. Resolution stayed late for twenty years, so a snippet written as
 * `rust` on a site with no `prism-rust.js` starts highlighting the day a drop-in
 * appears — and a name overwritten here could never do that again.
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
		code: code.trim(),
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
