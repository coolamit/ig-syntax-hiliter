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
 */

import { createBlock } from '@wordpress/blocks';
import { dispatch, select, subscribe } from '@wordpress/data';
import { attrs as parseShortcodeAttributes, regexp } from '@wordpress/shortcode';

import { BLOCK_NAME, getLegacyTags, mapShortcodeAttributes } from './attributes';

const BLOCK_EDITOR_STORE = 'core/block-editor';
const FREEFORM_BLOCK = 'core/freeform';

/**
 * How long to wait for the editor to load its blocks before giving up.
 */
const WATCH_TIMEOUT_MS = 10000;

interface EditorBlock {
	clientId: string;
	name: string;
	attributes?: Record< string, unknown >;
	innerBlocks?: EditorBlock[];
}

interface BlockEditorSelectors {
	getBlocks: () => EditorBlock[];
}

interface BlockEditorActions {
	replaceBlocks: ( clientIds: string, blocks: unknown[] ) => void;
	__unstableMarkNextChangeAsNotPersistent: () => void;
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
 * Turns one matched shortcode into a block.
 *
 * @param match Match from the shortcode pattern.
 *
 * @return The block, or NULL when this match must be left where it is.
 */
function createSnippetBlock( match: RegExpExecArray ): unknown | null {
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
	} );
}

/**
 * Appends a Classic block holding one stretch of untouched content.
 *
 * Only a genuinely empty stretch is dropped, which is what keeps a snippet at
 * the very start or end of a post from leaving a stray empty Classic block.
 * Everything else, whitespace included, is carried over unchanged.
 *
 * @param blocks  Blocks being assembled.
 * @param content Content to carry over.
 */
function pushClassicBlock( blocks: unknown[], content: string ): void {
	if ( content === '' ) {
		return;
	}

	blocks.push( createBlock( FREEFORM_BLOCK, { content } ) );
}

/**
 * Splits Classic content into alternating Classic and snippet blocks.
 *
 * @param content Content of one `core/freeform` block.
 * @param pattern Pattern matching this plugin's shortcodes.
 *
 * @return The replacement blocks, or NULL when there is nothing to convert.
 */
function splitFreeformContent(
	content: string,
	pattern: RegExp
): unknown[] | null {
	pattern.lastIndex = 0;

	const replacement: unknown[] = [];
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
			pushClassicBlock(
				replacement,
				content.slice( cursor, match.index )
			);
			replacement.push( snippet );

			cursor = match.index + match[ 0 ].length;
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
 * Collects every Classic block in the tree.
 *
 * @param blocks Blocks to walk.
 * @param found  Accumulator.
 */
function collectFreeformBlocks(
	blocks: EditorBlock[],
	found: EditorBlock[] = []
): EditorBlock[] {
	for ( const block of blocks ) {
		if ( block.name === FREEFORM_BLOCK ) {
			found.push( block );
			continue;
		}

		if ( Array.isArray( block.innerBlocks ) && block.innerBlocks.length ) {
			collectFreeformBlocks( block.innerBlocks, found );
		}
	}

	return found;
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

	if ( ! selectors || ! actions ) {
		return;
	}

	const tags = getLegacyTags();

	if ( tags.length === 0 ) {
		return;
	}

	const pattern = buildTagPattern( tags );

	for ( const block of collectFreeformBlocks( selectors.getBlocks() ) ) {
		const content = block.attributes?.content;

		if ( typeof content !== 'string' || ! content.includes( '[' ) ) {
			continue;
		}

		const replacement = splitFreeformContent( content, pattern );

		if ( replacement === null ) {
			continue;
		}

		/*
		 * A non-persistent block change reaches the post entity through
		 * `onInput`, which edits only transient keys. The post is therefore not
		 * marked dirty: opening a post and closing it again changes nothing.
		 */
		actions.__unstableMarkNextChangeAsNotPersistent();
		actions.replaceBlocks( block.clientId, replacement );
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

		if ( ! selectors ) {
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
