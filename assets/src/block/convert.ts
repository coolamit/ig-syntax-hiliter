/**
 * Lifts legacy shortcode snippets out of `core/freeform` blocks into their own
 * block.
 *
 * WordPress parses a classic post into one `core/freeform` block, so editing
 * any paragraph re-serialises every snippet in it through TinyMCE. Snippets
 * are masked out before the grammar runs, because a snippet quoting a block
 * delimiter is taken apart by it — an unclosed opener swallows the rest of the
 * post and a stray closer stops the parse. Everything fails towards leaving
 * the post alone.
 */

import { createBlock, parse } from '@wordpress/blocks';
import { dispatch, select, subscribe } from '@wordpress/data';
import {
	attrs as parseShortcodeAttributes,
	regexp,
} from '@wordpress/shortcode';

import {
	BLOCK_NAME,
	escapeForRegExp,
	getLegacyTags,
	mapShortcodeAttributes,
} from './attributes';

const BLOCK_EDITOR_STORE = 'core/block-editor';
const EDITOR_STORE = 'core/editor';
const CORE_STORE = 'core';
const FREEFORM_BLOCK = 'core/freeform';
const ENTITY_KIND = 'postType';

/**
 * How long to wait for the editor to load its blocks before giving up.
 */
const WATCH_TIMEOUT_MS = 10000;

/**
 * Post types this conversion never touches.
 *
 * Classic content only reaches these by accident, and the site editor loads
 * them through a block editor whose block list is the template's, not the
 * record's.
 */
const EXCLUDED_POST_TYPES = [
	'wp_template',
	'wp_template_part',
	'wp_navigation',
	'wp_global_styles',
];

/**
 * Probe content for the `__unstableSkipAutop` feature detection.
 *
 * A newline is enough: `autop()` turns one into `<br />`.
 */
const PROBE_CONTENT = 'igsh\nprobe';

/**
 * How a masked snippet is named while the grammar runs over the post.
 *
 * Not an HTML comment: a comment carrying `-->` closes the one it lands inside.
 * Carries no `[` either, so a second pass cannot read it as a shortcode.
 */
const PLACEHOLDER_PREFIX = '{igshx';

/**
 * How many times a placeholder is regenerated before the conversion gives up.
 */
const PLACEHOLDER_ATTEMPTS = 8;

interface EditorBlock {
	clientId: string;
	name: string;
	attributes?: Record< string, unknown >;
	innerBlocks?: EditorBlock[];
}

interface ParsedBlock {
	name: string;
	attributes?: Record< string, unknown >;
	innerBlocks?: ParsedBlock[];
}

interface CreatedBlock {
	name: string;
}

interface BlockEditorSelectors {
	getBlocks: () => EditorBlock[];
	getBlockRootClientId?: ( clientId: string ) => string | null;
	canInsertBlockType?: (
		name: string,
		rootClientId?: string | null
	) => boolean;
}

interface BlockEditorActions {
	replaceBlocks: ( clientIds: string | string[], blocks: unknown[] ) => void;
	__unstableMarkNextChangeAsNotPersistent: () => void;
}

interface EditorSelectors {
	getCurrentPostType?: () => string | undefined;
	getCurrentPostId?: () => number | string | undefined;
	getCurrentPost?: () => Record< string, unknown > | undefined;
}

interface CoreSelectors {
	getEntityRecordEdits?: (
		kind: string,
		name: string,
		id: number | string
	) => Record< string, unknown > | undefined;
	getEditedEntityRecord?: (
		kind: string,
		name: string,
		id: number | string
	) => Record< string, unknown > | undefined;
}

/**
 * One Classic block in the store, paired with the bytes it was parsed from.
 */
interface FreeformPair {
	clientId: string;
	content: string;
}

/**
 * A stretch of the stored post which the block grammar must not see.
 */
interface SnippetSlot {
	/**
	 * The author's own bytes, exactly as the post holds them.
	 */
	original: string;

	/**
	 * The block this snippet becomes, or NULL when it stays where it is.
	 */
	block: CreatedBlock | null;
}

/**
 * The stored post with every one of this plugin's snippets masked out.
 */
interface MaskedContent {
	text: string;
	slots: SnippetSlot[];

	/**
	 * What every placeholder in `text` begins with, followed by a slot number
	 * and a closing brace.
	 */
	opener: string;

	/**
	 * Whether any slot became a block. A post holding only escaped or empty
	 * snippets has nothing to convert but may still need repairing.
	 */
	converted: boolean;
}

/**
 * What the store is to be told, once everything has been checked.
 */
interface ConversionPlan {
	/**
	 * Whether the whole root block list is being replaced, which is what a post
	 * the grammar took apart needs.
	 */
	root: boolean;
	replacements: Array< { clientIds: string[]; blocks: CreatedBlock[] } >;
}

/**
 * Half open `[ start, end )` ranges of the stored post, in document order.
 */
type Range = [ number, number ];

/**
 * The one substring of the shortcode pattern that is rewritten, and what it
 * becomes. Mirrors `Helper::get_shortcode_pattern()` in PHP.
 */
const CLOSER_GUARD = '\\[(?!\\/\\2\\])';
const ESCAPED_CLOSER = '(?:\\[\\[\\/\\2\\]\\]|\\[(?!\\/\\2\\]))';

/**
 * Builds the pattern that matches this plugin's shortcodes.
 *
 * A closing tag with doubled brackets is consumed as code rather than ending
 * the snippet, and goes in as the first alternative. A pattern no longer
 * holding the substring exactly once is returned untouched — the same
 * fail-safe PHP has.
 *
 * @param tags Shortcode tags the plugin claims.
 */
function buildTagPattern( tags: string[] ): RegExp {
	const pattern = regexp( tags.map( escapeForRegExp ).join( '|' ) );

	if ( pattern.source.split( CLOSER_GUARD ).length !== 2 ) {
		return pattern;
	}

	return new RegExp(
		pattern.source.replace( CLOSER_GUARD, ESCAPED_CLOSER ),
		pattern.flags
	);
}

/**
 * Parses post content the way the editor did, minus the `autop()` pass.
 *
 * @param content Post content.
 *
 * @return The block list, or NULL when the parse threw.
 */
function parseWithoutAutop( content: string ): ParsedBlock[] | null {
	try {
		return parse( content, {
			__unstableSkipAutop: true,
			__unstableSkipMigrationLogs: true,
		} ) as unknown as ParsedBlock[];
	} catch {
		return null;
	}
}

/**
 * Parses post content exactly as the editor did.
 *
 * @param content Post content.
 *
 * @return The block list, or NULL when the parse threw.
 */
function parseLikeTheEditor( content: string ): ParsedBlock[] | null {
	try {
		return parse( content, {
			__unstableSkipMigrationLogs: true,
		} ) as unknown as ParsedBlock[];
	} catch {
		return null;
	}
}

/**
 * Whether `parse()` still honours `__unstableSkipAutop`.
 *
 * The option is unstable and could be dropped; a silent `autop()` would put
 * mangled bytes into the very attributes this protects, so it is checked on
 * every run. It doubles as the check that `core/freeform` is the registered
 * fallback handler.
 */
function skipAutopWorks(): boolean {
	const probe = parseWithoutAutop( PROBE_CONTENT );

	return (
		probe !== null &&
		probe.length === 1 &&
		probe[ 0 ]?.name === FREEFORM_BLOCK &&
		probe[ 0 ]?.attributes?.content === PROBE_CONTENT
	);
}

/**
 * The stored post content, ie. the exact string the editor parsed into blocks.
 *
 * @return The content, or NULL when it cannot be had or must not be used.
 */
function getStoredContent(): string | null {
	const editor = select( EDITOR_STORE ) as unknown as
		| EditorSelectors
		| undefined;

	// No post editor, no post. The widget editor never registers this store.
	if ( ! editor || typeof editor.getCurrentPostId !== 'function' ) {
		return null;
	}

	const postType = editor.getCurrentPostType?.();

	if ( typeof postType !== 'string' || postType === '' ) {
		return null;
	}

	if ( EXCLUDED_POST_TYPES.includes( postType ) ) {
		return null;
	}

	const postId = editor.getCurrentPostId();

	if ( postId === undefined || postId === null || postId === '' ) {
		return null;
	}

	const core = select( CORE_STORE ) as unknown as CoreSelectors | undefined;

	if (
		! core ||
		typeof core.getEntityRecordEdits !== 'function' ||
		typeof core.getEditedEntityRecord !== 'function'
	) {
		return null;
	}

	/*
	 * Once anything has edited the record or the blocks, the two no longer
	 * describe the same post and clientIds cannot be lined up.
	 */
	const edits = core.getEntityRecordEdits( ENTITY_KIND, postType, postId );

	if ( edits && ( 'content' in edits || 'blocks' in edits ) ) {
		return null;
	}

	const record = core.getEditedEntityRecord( ENTITY_KIND, postType, postId );

	if ( typeof record?.content === 'string' ) {
		return record.content;
	}

	const post = editor.getCurrentPost?.();

	return typeof post?.content === 'string' ? post.content : null;
}

/**
 * Turns one matched shortcode into a block.
 *
 * @param match Match from the shortcode pattern.
 *
 * @return The block, or NULL when this match must be left where it is.
 */
function createSnippetBlock( match: RegExpExecArray ): CreatedBlock | null {
	// `[[php]…[/php]]` is an escaped shortcode, printed as written rather than rendered.
	if ( match[ 1 ] === '[' && match[ 7 ] === ']' ) {
		return null;
	}

	const code = match[ 5 ] ?? '';

	// An empty snippet renders as nothing today. Converting it would put an empty code box on the page.
	if ( code.trim() === '' ) {
		return null;
	}

	return createBlock( BLOCK_NAME, {
		...mapShortcodeAttributes(
			match[ 2 ] ?? '',
			parseShortcodeAttributes( match[ 3 ] ?? '' ).named,
			code
		),
	} ) as unknown as CreatedBlock;
}

/**
 * Appends a Classic block holding one stretch of untouched content.
 *
 * A whitespace-only stretch is dropped: `serialize()` puts a blank line back
 * between blocks, and the next `parse()` would discard the empty Classic block
 * anyway.
 *
 * @param blocks  Blocks being assembled.
 * @param content Content to carry over.
 */
function pushClassicBlock( blocks: CreatedBlock[], content: string ): void {
	if ( content.trim() === '' ) {
		return;
	}

	blocks.push(
		createBlock( FREEFORM_BLOCK, { content } ) as unknown as CreatedBlock
	);
}

/**
 * Where every HTML comment in the post begins and ends.
 *
 * Scanned rather than matched: a tempered pattern over delimiter attribute
 * JSON backtracks catastrophically at snippet sizes. An unclosed comment runs
 * to the end of the post, as it does in a browser.
 *
 * @param content Post content.
 */
function getCommentRanges( content: string ): Range[] {
	const ranges: Range[] = [];
	let search = 0;

	for (;;) {
		const open = content.indexOf( '<!--', search );

		if ( open === -1 ) {
			break;
		}

		const close = content.indexOf( '-->', open + 4 );
		const end = close === -1 ? content.length : close + 3;

		ranges.push( [ open, end ] );
		search = end;
	}

	return ranges;
}

/**
 * Where every Classic block's content sits in the stored post.
 *
 * The only stretches a snippet may be lifted from; everything else is a block
 * delimiter or another block's inner HTML. Read off a parse: a Classic block's
 * content is always one unbroken stretch of the string it came from, so
 * anything not findable means this is not that parse.
 *
 * @param content Post content.
 * @param blocks  The same content, parsed without `autop()`.
 *
 * @return The ranges in document order, or NULL when the two do not line up.
 */
function getFreeformRanges(
	content: string,
	blocks: ParsedBlock[]
): Range[] | null {
	const ranges: Range[] = [];
	let cursor = 0;

	const walk = ( list: ParsedBlock[] ): boolean => {
		for ( const block of list ) {
			if ( block.name === FREEFORM_BLOCK ) {
				const text = block.attributes?.content;

				if ( typeof text !== 'string' ) {
					return false;
				}

				if ( text === '' ) {
					continue;
				}

				const at = content.indexOf( text, cursor );

				if ( at === -1 ) {
					return false;
				}

				ranges.push( [ at, at + text.length ] );
				cursor = at + text.length;

				continue;
			}

			if ( ! walk( block.innerBlocks ?? [] ) ) {
				return false;
			}
		}

		return true;
	};

	return walk( blocks ) ? ranges : null;
}

/**
 * Whether an offset falls inside any of the given ranges.
 *
 * @param ranges Ranges in document order.
 * @param offset Offset into the post.
 */
function isInsideRange( ranges: Range[], offset: number ): boolean {
	return ranges.some( ( [ start, end ] ) => offset >= start && offset < end );
}

/**
 * Where the range holding an offset ends, if one does.
 *
 * @param ranges Ranges in document order.
 * @param offset Offset into the post.
 */
function getRangeEnd( ranges: Range[], offset: number ): number | null {
	for ( const [ start, end ] of ranges ) {
		if ( offset >= start && offset < end ) {
			return end;
		}
	}

	return null;
}

/**
 * Builds a placeholder opener which does not already occur in the post.
 *
 * @param content Post content.
 *
 * @return The opener, or an empty string when one could not be found.
 */
function buildPlaceholderOpener( content: string ): string {
	for ( let attempt = 0; attempt < PLACEHOLDER_ATTEMPTS; attempt++ ) {
		const token = Math.random().toString( 36 ).slice( 2, 12 );
		const opener = `${ PLACEHOLDER_PREFIX }${ token }-`;

		if ( token !== '' && ! content.includes( opener ) ) {
			return opener;
		}
	}

	return '';
}

/**
 * Lifts every one of this plugin's snippets out of the post.
 *
 * Matches are walked with an explicit resume position, not a replace callback:
 * a declined match cannot be un-consumed and one beginning inside a delimiter
 * can run far past it. A match beginning inside a Classic block is masked
 * whole even across a delimiter — that is the point of masking before the
 * grammar runs. Escaped and empty snippets are masked too: they convert to
 * nothing but shred the post the same.
 *
 * @param content  Post content.
 * @param pattern  Pattern matching this plugin's shortcodes.
 * @param freeform Ranges a snippet may be lifted out of.
 *
 * @return The masked post, or NULL when there is nothing of this plugin's in it.
 */
function maskSnippets(
	content: string,
	pattern: RegExp,
	freeform: Range[]
): MaskedContent | null {
	const opener = buildPlaceholderOpener( content );

	if ( opener === '' ) {
		return null;
	}

	const comments = getCommentRanges( content );
	const slots: SnippetSlot[] = [];
	let text = '';
	let copied = 0;
	let converted = false;

	pattern.lastIndex = 0;

	let match = pattern.exec( content );

	while ( match !== null ) {
		if ( match[ 0 ].length === 0 ) {
			pattern.lastIndex += 1;
			match = pattern.exec( content );
			continue;
		}

		/*
		 * The bracket either side of a shortcode is content, not shortcode —
		 * `do_shortcode()` re-emits it.
		 */
		const start = match.index + ( match[ 1 ] ?? '' ).length;
		const end =
			match.index + match[ 0 ].length - ( match[ 7 ] ?? '' ).length;

		const comment = getRangeEnd( comments, start );

		if ( comment !== null ) {
			// A block's own data, or an author's comment. Resume past the whole of it.
			pattern.lastIndex = comment;
			match = pattern.exec( content );
			continue;
		}

		if ( ! isInsideRange( freeform, start ) ) {
			// Another block's content. Left where it is, and not consumed.
			pattern.lastIndex = match.index + 1;
			match = pattern.exec( content );
			continue;
		}

		const block = createSnippetBlock( match );

		text += `${ content.slice( copied, start ) }${ opener }${
			slots.length
		}}`;
		slots.push( { original: content.slice( start, end ), block } );
		converted = converted || block !== null;

		copied = end;
		pattern.lastIndex = end;
		match = pattern.exec( content );
	}

	if ( slots.length === 0 ) {
		return null;
	}

	return {
		text: text + content.slice( copied ),
		slots,
		opener,
		converted,
	};
}

/**
 * Turns one masked Classic block back into blocks.
 *
 * Bytes come from the slot, never from the parse — the parse is only asked
 * where the snippet was.
 *
 * @param masked Content of one masked `core/freeform` block.
 * @param mask   The masked post.
 * @param used   Slots already put back, so that none is put back twice.
 *
 * @return The blocks, and whether any of them is a snippet.
 */
function splitMaskedContent(
	masked: string,
	mask: MaskedContent,
	used: Set< number >
): { blocks: CreatedBlock[]; converted: boolean } {
	const blocks: CreatedBlock[] = [];
	let pending = '';
	let cursor = 0;
	let converted = false;

	for (;;) {
		const at = masked.indexOf( mask.opener, cursor );

		if ( at === -1 ) {
			break;
		}

		const from = at + mask.opener.length;
		const close = masked.indexOf( '}', from );
		const index = close === -1 ? -1 : Number( masked.slice( from, close ) );
		const slot =
			Number.isInteger( index ) && ! used.has( index )
				? mask.slots[ index ]
				: undefined;

		if ( ! slot ) {
			// Not a placeholder of this run's. Carried over as the text it is.
			pending += masked.slice( cursor, from );
			cursor = from;
			continue;
		}

		used.add( index );
		pending += masked.slice( cursor, at );

		if ( slot.block === null ) {
			pending += slot.original;
		} else {
			pushClassicBlock( blocks, pending );
			pending = '';
			blocks.push( slot.block );
			converted = true;
		}

		cursor = close + 1;
	}

	pushClassicBlock( blocks, pending + masked.slice( cursor ) );

	return { blocks, converted };
}

/**
 * Rebuilds a whole block tree out of the masked parse.
 *
 * For a post the grammar took apart: the block list on screen is not this
 * post's, so there is nothing to replace in place.
 *
 * @param list The masked post, parsed without `autop()`.
 * @param mask The masked post.
 * @param used Slots already put back.
 *
 * @return The blocks, or NULL when a Classic block held something other than a string.
 */
function rebuildBlocks(
	list: ParsedBlock[],
	mask: MaskedContent,
	used: Set< number >
): ParsedBlock[] | null {
	const rebuilt: ParsedBlock[] = [];

	for ( const block of list ) {
		if ( block.name === FREEFORM_BLOCK ) {
			const text = block.attributes?.content;

			if ( typeof text !== 'string' ) {
				return null;
			}

			rebuilt.push( ...splitMaskedContent( text, mask, used ).blocks );

			continue;
		}

		if ( block.innerBlocks && block.innerBlocks.length > 0 ) {
			const inner = rebuildBlocks( block.innerBlocks, mask, used );

			if ( inner === null ) {
				return null;
			}

			block.innerBlocks = inner;
		}

		rebuilt.push( block );
	}

	return rebuilt;
}

/**
 * Pairs every Classic block in the store with the masked bytes it came from.
 *
 * The two trees are the same post parsed twice; `autop()` only rewrites
 * freeform content, so they must agree block for block. A disagreement means
 * the grammar had been reading somebody's source code as markup.
 *
 * @param storeBlocks Blocks as the editor holds them.
 * @param cleanBlocks The masked post, parsed without `autop()`.
 * @param pairs       Accumulator.
 *
 * @return FALSE when the two trees disagree.
 */
function pairFreeformBlocks(
	storeBlocks: EditorBlock[],
	cleanBlocks: ParsedBlock[],
	pairs: FreeformPair[]
): boolean {
	if ( storeBlocks.length !== cleanBlocks.length ) {
		return false;
	}

	for ( let index = 0; index < storeBlocks.length; index++ ) {
		const storeBlock = storeBlocks[ index ];
		const cleanBlock = cleanBlocks[ index ];

		if ( ! storeBlock || ! cleanBlock ) {
			return false;
		}

		if ( storeBlock.name !== cleanBlock.name ) {
			return false;
		}

		if ( storeBlock.name === FREEFORM_BLOCK ) {
			const content = cleanBlock.attributes?.content;

			if ( typeof content !== 'string' ) {
				return false;
			}

			pairs.push( { clientId: storeBlock.clientId, content } );
			continue;
		}

		if (
			! pairFreeformBlocks(
				storeBlock.innerBlocks ?? [],
				cleanBlock.innerBlocks ?? [],
				pairs
			)
		) {
			return false;
		}
	}

	return true;
}

/**
 * Whether any Classic block on screen could possibly hold a shortcode.
 *
 * `autop()` never removes a bracket, so a Classic block with no `[` had none
 * before it. Answering NO keeps the extra parses off the load path of every
 * post with nothing to convert.
 *
 * @param blocks Blocks to walk.
 */
function hasClassicBracket( blocks: EditorBlock[] ): boolean {
	for ( const block of blocks ) {
		if ( block.name === FREEFORM_BLOCK ) {
			const content = block.attributes?.content;

			if ( typeof content === 'string' && content.includes( '[' ) ) {
				return true;
			}

			continue;
		}

		if ( hasClassicBracket( block.innerBlocks ?? [] ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Whether the editor's block list really is the parse of the stored content.
 *
 * Everything downstream rests on that. Re-parsing the way the editor did and
 * requiring byte-identical Classic blocks is what settles it.
 *
 * @param storeBlocks Blocks as the editor holds them.
 * @param reparsed    The stored content, parsed the same way the editor did.
 */
function blockTreesAgree(
	storeBlocks: EditorBlock[],
	reparsed: ParsedBlock[]
): boolean {
	if ( storeBlocks.length !== reparsed.length ) {
		return false;
	}

	for ( let index = 0; index < storeBlocks.length; index++ ) {
		const storeBlock = storeBlocks[ index ];
		const reparsedBlock = reparsed[ index ];

		if ( ! storeBlock || ! reparsedBlock ) {
			return false;
		}

		if ( storeBlock.name !== reparsedBlock.name ) {
			return false;
		}

		if (
			storeBlock.name === FREEFORM_BLOCK &&
			storeBlock.attributes?.content !== reparsedBlock.attributes?.content
		) {
			return false;
		}

		if (
			! blockTreesAgree(
				storeBlock.innerBlocks ?? [],
				reparsedBlock.innerBlocks ?? []
			)
		) {
			return false;
		}
	}

	return true;
}

/**
 * Works out what the store should be told, and checks it before saying so.
 *
 * Free of the data stores on purpose, so it can be tested against the real
 * block grammar.
 *
 * @param content     The stored post content.
 * @param storeBlocks Blocks as the editor holds them.
 * @param tags        Shortcode tags the plugin claims.
 *
 * @return The plan, or NULL when the post is to be left alone.
 */
export function planConversion(
	content: string,
	storeBlocks: EditorBlock[],
	tags: string[]
): ConversionPlan | null {
	if ( tags.length === 0 || ! content.includes( '[' ) ) {
		return null;
	}

	if ( ! skipAutopWorks() ) {
		return null;
	}

	const cleanBlocks = parseWithoutAutop( content );

	if ( cleanBlocks === null ) {
		return null;
	}

	const freeform = getFreeformRanges( content, cleanBlocks );

	if ( freeform === null || freeform.length === 0 ) {
		return null;
	}

	const mask = maskSnippets( content, buildTagPattern( tags ), freeform );

	if ( mask === null ) {
		return null;
	}

	const maskedBlocks = parseWithoutAutop( mask.text );

	if ( maskedBlocks === null ) {
		return null;
	}

	const pairs: FreeformPair[] = [];
	const intact = pairFreeformBlocks( storeBlocks, maskedBlocks, pairs );

	// Nothing to convert and nothing broken.
	if ( intact && ! mask.converted ) {
		return null;
	}

	const reparsed = parseLikeTheEditor( content );

	if ( reparsed === null || ! blockTreesAgree( storeBlocks, reparsed ) ) {
		return null;
	}

	const used = new Set< number >();
	const plan = intact
		? planInPlace( pairs, mask, used )
		: planWholeTree( maskedBlocks, mask, used );

	/*
	 * Every masked snippet must have been put back, or a placeholder would be
	 * dispatched where the author's code was.
	 */
	if ( plan === null || used.size !== mask.slots.length ) {
		return null;
	}

	return plan.replacements.length === 0 ? null : plan;
}

/**
 * The plan for a post the grammar read correctly: split each Classic block.
 *
 * Other blocks keep their clientIds, and with them selection and focus.
 *
 * @param pairs Classic blocks in the store, with their masked bytes.
 * @param mask  The masked post.
 * @param used  Accumulator of slots put back.
 */
function planInPlace(
	pairs: FreeformPair[],
	mask: MaskedContent,
	used: Set< number >
): ConversionPlan {
	const replacements: ConversionPlan[ 'replacements' ] = [];

	for ( const pair of pairs ) {
		if ( ! pair.content.includes( mask.opener ) ) {
			continue;
		}

		const split = splitMaskedContent( pair.content, mask, used );

		if ( split.converted ) {
			replacements.push( {
				clientIds: [ pair.clientId ],
				blocks: split.blocks,
			} );
		}
	}

	return { root: false, replacements };
}

/**
 * The plan for a post the grammar took apart: replace the whole root list.
 *
 * The clientIds go — the price of the block list not having been this post's.
 *
 * @param maskedBlocks The masked post, parsed without `autop()`.
 * @param mask         The masked post.
 * @param used         Accumulator of slots put back.
 */
function planWholeTree(
	maskedBlocks: ParsedBlock[],
	mask: MaskedContent,
	used: Set< number >
): ConversionPlan | null {
	const rebuilt = rebuildBlocks( maskedBlocks, mask, used );

	if ( rebuilt === null || rebuilt.length === 0 ) {
		return null;
	}

	return {
		root: true,
		replacements: [
			{
				clientIds: [],
				blocks: rebuilt as CreatedBlock[],
			},
		],
	};
}

/**
 * Whether `replaceBlocks()` will go through with a replacement.
 *
 * `replaceBlocks()` returns without dispatching when a block cannot be
 * inserted, and the not-persistent mark is set beforehand. An unspent mark
 * swallows the user's next real edit out of the undo stack.
 *
 * @param selectors    Block editor selectors.
 * @param rootClientId Container the blocks would land in, NULL for the root.
 * @param blocks       Replacement blocks.
 */
function canInsertAll(
	selectors: BlockEditorSelectors,
	rootClientId: string | null,
	blocks: CreatedBlock[]
): boolean {
	if ( typeof selectors.canInsertBlockType !== 'function' ) {
		return true;
	}

	return blocks.every(
		( block ) =>
			selectors.canInsertBlockType?.( block.name, rootClientId ) !== false
	);
}

/**
 * Whether one Classic block may be replaced where it stands.
 *
 * @param selectors Block editor selectors.
 * @param clientId  Block being replaced.
 * @param blocks    Replacement blocks.
 */
function canReplaceBlock(
	selectors: BlockEditorSelectors,
	clientId: string,
	blocks: CreatedBlock[]
): boolean {
	if (
		typeof selectors.canInsertBlockType !== 'function' ||
		typeof selectors.getBlockRootClientId !== 'function'
	) {
		return true;
	}

	return canInsertAll(
		selectors,
		selectors.getBlockRootClientId( clientId ),
		blocks
	);
}

/**
 * Converts every legacy snippet the post holds outside a block.
 *
 * Idempotent: converted snippets live inside delimiters, which are never
 * lifted from.
 */
function convertFreeformBlocks(): void {
	const selectors = select( BLOCK_EDITOR_STORE ) as unknown as
		| BlockEditorSelectors
		| undefined;
	const actions = dispatch( BLOCK_EDITOR_STORE ) as unknown as
		| BlockEditorActions
		| undefined;

	if (
		! selectors ||
		! actions ||
		typeof selectors.getBlocks !== 'function' ||
		typeof actions.replaceBlocks !== 'function'
	) {
		return;
	}

	// Without this the conversion marks the post dirty on open.
	if (
		typeof actions.__unstableMarkNextChangeAsNotPersistent !== 'function'
	) {
		return;
	}

	const storeBlocks = selectors.getBlocks();

	if (
		! Array.isArray( storeBlocks ) ||
		! hasClassicBracket( storeBlocks )
	) {
		return;
	}

	const content = getStoredContent();

	if ( content === null ) {
		return;
	}

	const plan = planConversion( content, storeBlocks, getLegacyTags() );

	if ( plan === null ) {
		return;
	}

	if ( plan.root ) {
		const replacement = plan.replacements[ 0 ];
		const clientIds = storeBlocks.map( ( block ) => block.clientId );

		if (
			! replacement ||
			clientIds.length === 0 ||
			! canInsertAll( selectors, null, replacement.blocks )
		) {
			return;
		}

		/*
		 * A non-persistent change reaches the post entity through `onInput`,
		 * which edits only transient keys.
		 */
		actions.__unstableMarkNextChangeAsNotPersistent();
		actions.replaceBlocks( clientIds, replacement.blocks );

		return;
	}

	for ( const replacement of plan.replacements ) {
		const clientId = replacement.clientIds[ 0 ];

		if (
			clientId === undefined ||
			! canReplaceBlock( selectors, clientId, replacement.blocks )
		) {
			continue;
		}

		actions.__unstableMarkNextChangeAsNotPersistent();
		actions.replaceBlocks( clientId, replacement.blocks );
	}
}

/**
 * Watches for the editor to finish loading its blocks, then converts once.
 */
export function initLegacyConversion(): void {
	if ( getLegacyTags().length === 0 ) {
		return;
	}

	let unsubscribe: ( () => void ) | undefined;
	let timer = 0;

	const stop = (): void => {
		if ( unsubscribe ) {
			unsubscribe();
			unsubscribe = undefined;
		}

		if ( timer ) {
			window.clearTimeout( timer );
			timer = 0;
		}
	};

	const attempt = (): void => {
		const selectors = select( BLOCK_EDITOR_STORE ) as unknown as
			| BlockEditorSelectors
			| undefined;

		if ( ! selectors || typeof selectors.getBlocks !== 'function' ) {
			return;
		}

		const blocks = selectors.getBlocks();

		if ( ! Array.isArray( blocks ) || blocks.length === 0 ) {
			return;
		}

		stop();

		// Out of the store subscription, so that the replacement is not dispatched mid notification.
		window.setTimeout( convertFreeformBlocks, 0 );
	};

	unsubscribe = subscribe( attempt );
	timer = window.setTimeout( stop, WATCH_TIMEOUT_MS );

	attempt();
}
