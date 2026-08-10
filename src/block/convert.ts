/**
 * Automatic conversion of legacy snippets held in Classic blocks.
 *
 * WordPress parses a classic post into a single `core/freeform` block, so the
 * whole post — code included — sits in one TinyMCE instance. TinyMCE parses
 * `<?php echo "<div>x</div>"; ?>` as HTML, and because it is one block for the
 * whole post, editing an unrelated paragraph re-serialises every snippet in it.
 * The fix is to lift each snippet out into its own block, where the code lives
 * in JSON attributes that no editor and no filter rewrites.
 *
 * Everything that is not a snippet is handed straight back to Classic blocks,
 * byte for byte. Nothing else in the post is touched, the post is not marked
 * dirty, and shortcodes living in blocks other than `core/freeform` are left
 * alone — they carry no TinyMCE risk and keep rendering through the shortcode
 * pipeline.
 *
 * No byte of that ever comes from the Classic block's own `content` attribute.
 * `@wordpress/blocks` runs `autop()` over freeform content inside `parse()`, so
 * by the time a block reaches the store its code has already gained `<br />`
 * for every newline and `</p>\n<p>` for every blank line, and the `<p>` that
 * `autop` wrapped around a snippet standing on its own line straddles the
 * snippet — a split there cuts the paragraph in half. Capturing any of that
 * into a block attribute would freeze it into the post permanently. So the
 * conversion goes back to the stored post content and re-parses it with
 * `__unstableSkipAutop`, which is the same string `parse()` was handed and the
 * only place the author's bytes still exist unaltered.
 *
 * That makes the conversion conditional on things it does not control, and
 * every one of them fails towards leaving the post alone: not converting is the
 * status quo the plugin shipped for twenty years, converting from mangled bytes
 * is not recoverable.
 */

import { createBlock, parse } from '@wordpress/blocks';
import { dispatch, select, subscribe } from '@wordpress/data';
import { attrs as parseShortcodeAttributes, regexp } from '@wordpress/shortcode';

import { BLOCK_NAME, getLegacyTags, mapShortcodeAttributes } from './attributes';

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
 * Post types this conversion has no business touching.
 *
 * The whole argument for converting is that classic content damages code in
 * TinyMCE, and classic content only ever reaches templates, template parts,
 * navigation and global styles by accident. Those are also the entities the
 * site editor loads through a block editor that is not the post editor, where
 * the block list on screen is the template's and not the record's — so a
 * conversion run there would be splitting somebody else's blocks.
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
 * A newline is all it takes: `autop()` turns one into `<br />`, so a parse that
 * gives the string back unchanged is a parse that did not run `autop()`.
 */
const PROBE_CONTENT = 'igsh\nprobe';

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
	canInsertBlockType?: ( name: string, rootClientId?: string | null ) => boolean;
}

interface BlockEditorActions {
	replaceBlocks: ( clientIds: string, blocks: unknown[] ) => void;
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
 * One Classic block in the store, paired with the pre-`autop` bytes it was
 * parsed from.
 */
interface FreeformPair {
	clientId: string;
	content: string;
}

function escapeForRegExp( value: string ): string {
	return value.replace( /[\\^$.*+?()[\]{}|]/g, '\\$&' );
}

/**
 * Builds the pattern that matches this plugin's shortcodes.
 *
 * `@wordpress/shortcode` builds the same pattern `get_shortcode_regex()` builds
 * in PHP, so escaped, self closing and unclosed tags are all treated the way
 * `do_shortcode()` treats them. The tag list comes from PHP: a tag the plugin
 * has never shipped belongs to somebody else and is never matched.
 *
 * @param tags Shortcode tags the plugin claims.
 */
function buildTagPattern( tags: string[] ): RegExp {
	return regexp( tags.map( escapeForRegExp ).join( '|' ) );
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
 * The option is unstable by name and could be renamed or dropped, and the day
 * it is, a parse that silently keeps running `autop()` would put the mangled
 * bytes straight back into the block attributes this exists to protect. So it
 * is proved rather than assumed, on every run, by parsing a string `autop()`
 * would visibly rewrite.
 *
 * It doubles as the check that `core/freeform` is the registered fallback
 * handler: where it is not — the widget editor, for one — there are no Classic
 * blocks to convert and the probe says so.
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
	const postId = editor.getCurrentPostId();

	if ( typeof postType !== 'string' || postType === '' ) {
		return null;
	}

	if ( EXCLUDED_POST_TYPES.includes( postType ) ) {
		return null;
	}

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
	 * The block list on screen is the parse of the record's content only while
	 * nothing has edited either. Once something has, the two no longer describe
	 * the same post and the clientIds cannot be lined up against a re-parse.
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
 * A stretch of nothing but whitespace is dropped. It is what sits between two
 * adjacent snippets and at either end of the post, it carries no content, and
 * `serialize()` puts a blank line back between blocks anyway — where keeping it
 * would leave an empty Classic block on screen that the next `parse()` discards
 * regardless, so the post would not even be stable across a reload. Everything
 * else is carried over byte for byte, whitespace included.
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
 * Splits Classic content into alternating Classic and snippet blocks.
 *
 * The bracket the pattern captures either side of a shortcode is content, not
 * shortcode: `do_shortcode()` and `@wordpress/shortcode`'s own `replace()` both
 * re-emit it. So `[note: [php]…[/php]] more` keeps its closing bracket, which
 * is only half of an escape and belongs to the prose.
 *
 * @param content Pre-`autop` content of one `core/freeform` block.
 * @param pattern Pattern matching this plugin's shortcodes.
 *
 * @return The replacement blocks, or NULL when there is nothing to convert.
 */
function splitFreeformContent(
	content: string,
	pattern: RegExp
): CreatedBlock[] | null {
	pattern.lastIndex = 0;

	const replacement: CreatedBlock[] = [];
	let cursor = 0;
	let converted = false;
	let match = pattern.exec( content );

	while ( match !== null ) {
		if ( match[ 0 ].length === 0 ) {
			pattern.lastIndex += 1;
			match = pattern.exec( content );
			continue;
		}

		const snippet = createSnippetBlock( match );

		if ( snippet !== null ) {
			const leadingBracket = match[ 1 ] ?? '';
			const trailingBracket = match[ 7 ] ?? '';

			pushClassicBlock(
				replacement,
				content.slice( cursor, match.index + leadingBracket.length )
			);
			replacement.push( snippet );

			cursor =
				match.index + match[ 0 ].length - trailingBracket.length;
			converted = true;
		}

		match = pattern.exec( content );
	}

	if ( ! converted ) {
		return null;
	}

	pushClassicBlock( replacement, content.slice( cursor ) );

	return replacement;
}

/**
 * Pairs every Classic block in the store with the pre-`autop` bytes it came
 * from.
 *
 * The two trees are the same post parsed twice, so they agree block for block —
 * `autop()` rewrites freeform content and nothing else, and content that is
 * empty before it is empty after it, so no block appears in one and not the
 * other. That is asserted rather than trusted: a single disagreement anywhere
 * in the tree means the block list on screen is not this post, and the whole
 * conversion is abandoned. Shape alone is a weak claim though — most legacy
 * posts are one Classic block and one of anything matches one of anything — so
 * `blockTreesAgree()` proves the rest.
 *
 * @param storeBlocks Blocks as the editor holds them.
 * @param cleanBlocks The same post parsed without `autop()`.
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
 * `autop()` inserts markup, it never removes a bracket, so a Classic block with
 * no `[` in it had none before `autop()` either. Answering NO here is what keeps
 * the two extra parses of the post off the load path of every post that has
 * nothing to convert.
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
 * Everything downstream rests on that: the pre-`autop` bytes are lined up
 * against the blocks on screen by position, and if the two came from different
 * strings the conversion would replace one post's Classic block with another
 * post's code. Parsing the stored content a second time — this time the way the
 * editor parsed it, `autop()` and all — and requiring every Classic block to
 * come back byte identical is what settles it. Anything that has touched the
 * block list, from a restored autosave to a hooked block, shows up here.
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
 * Whether `replaceBlocks()` will go through with this replacement.
 *
 * It returns without dispatching anything when a block cannot be inserted where
 * it would land — a locked template, a restricted container — and the
 * "not persistent" mark is set before the call. A mark that is never spent sits
 * there and swallows the user's next real edit out of the undo stack, so the
 * same check is made first and the mark is not spent at all.
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

	const rootClientId = selectors.getBlockRootClientId( clientId );

	return blocks.every(
		( block ) =>
			selectors.canInsertBlockType?.( block.name, rootClientId ) !== false
	);
}

/**
 * Converts every legacy snippet held in a Classic block.
 *
 * Idempotent by construction: once a Classic block has been split, none of the
 * pieces left behind hold one of this plugin's tags, so a second run finds
 * nothing.
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

	/*
	 * Without this the conversion would mark the post dirty on open, which is
	 * worse than not converting: it invites a save nobody asked for.
	 */
	if (
		typeof actions.__unstableMarkNextChangeAsNotPersistent !== 'function'
	) {
		return;
	}

	const tags = getLegacyTags();

	if ( tags.length === 0 ) {
		return;
	}

	const storeBlocks = selectors.getBlocks();

	if ( ! Array.isArray( storeBlocks ) || ! hasClassicBracket( storeBlocks ) ) {
		return;
	}

	const content = getStoredContent();

	if ( content === null || ! content.includes( '[' ) ) {
		return;
	}

	if ( ! skipAutopWorks() ) {
		return;
	}

	const cleanBlocks = parseWithoutAutop( content );

	if ( cleanBlocks === null ) {
		return;
	}

	const pairs: FreeformPair[] = [];

	if ( ! pairFreeformBlocks( storeBlocks, cleanBlocks, pairs ) ) {
		return;
	}

	const candidates = pairs.filter( ( pair ) => pair.content.includes( '[' ) );

	if ( candidates.length === 0 ) {
		return;
	}

	const reparsed = parseLikeTheEditor( content );

	if ( reparsed === null || ! blockTreesAgree( storeBlocks, reparsed ) ) {
		return;
	}

	const pattern = buildTagPattern( tags );

	for ( const pair of candidates ) {
		const replacement = splitFreeformContent( pair.content, pattern );

		if ( replacement === null ) {
			continue;
		}

		if ( ! canReplaceBlock( selectors, pair.clientId, replacement ) ) {
			continue;
		}

		/*
		 * A non-persistent block change reaches the post entity through
		 * `onInput`, which edits only transient keys. The post is therefore not
		 * marked dirty: opening a post and closing it again changes nothing.
		 */
		actions.__unstableMarkNextChangeAsNotPersistent();
		actions.replaceBlocks( pair.clientId, replacement );
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
