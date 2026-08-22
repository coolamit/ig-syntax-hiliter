/**
 * Transforms into the Gist block.
 *
 * A `[github]` on the clipboard becomes this block; existing stored `[github]`
 * shortcodes are left alone. Two transforms, because `pasteHandler()` sends a
 * plain-text paste through `marked` with `gfm` on, which autolinks the bare
 * address inside `gist="…"` — core then declines the self-closing shortcode
 * because an `</a>` follows it, so the shortcode transform never fires. The
 * `raw` transform picks the paste up off the finished paragraph.
 */

import { createBlock } from '@wordpress/blocks';
import { next } from '@wordpress/shortcode';

import metadata from './block.json';

const TAG = 'github';

/**
 * The last text `loneGistShortcode()` was asked about, and what it answered.
 */
let lastText: string | null = null;
let lastMatch: GistShortcodeAttributes | null = null;

interface GistShortcodeAttributes {
	gist?: string;
	id?: string;
}

/**
 * The Gist address one matched shortcode stands for.
 *
 * `gist` is the address; the older `id` is its last segment, as
 * `Gist_Embed::render()` reads them.
 *
 * @param named Named attributes of the shortcode.
 */
function gistUrl( named: GistShortcodeAttributes ): string {
	const url = named.gist ?? '';

	if ( '' !== url ) {
		return url;
	}

	return named.id ? `https://gist.github.com/${ named.id }` : '';
}

/**
 * The attributes of a paragraph which is one `[github]` shortcode and nothing
 * else, or `NULL` for anything else.
 *
 * Matched with core's own shortcode matcher so this reads the paste as the
 * stage before it did; the match must cover the whole text. The last answer is
 * cached because `isMatch` and `transform` are handed the same node
 * consecutively.
 *
 * @param text Text content of the node being offered.
 */
function loneGistShortcode( text: string ): GistShortcodeAttributes | null {
	if ( text === lastText ) {
		return lastMatch;
	}

	const trimmed = text.trim();
	const match = next( TAG, trimmed );

	lastText = text;
	lastMatch =
		! match || 0 !== match.index || trimmed.length !== match.content.length
			? null
			: ( match.shortcode.attrs.named as GistShortcodeAttributes );

	return lastMatch;
}

const transforms = {
	from: [
		{
			type: 'shortcode',
			/*
			 * The `[github id="…"]` form carries no address to autolink, so it
			 * still arrives here.
			 */
			tag: TAG,
			transform: ( attributes: { named?: GistShortcodeAttributes } ) =>
				createBlock( metadata.name, {
					url: gistUrl( attributes.named ?? {} ),
				} ),
		},
		{
			type: 'raw',
			/*
			 * Equal priorities settle by registration order and core registers
			 * first, so the default loses every paragraph to `core/paragraph`.
			 */
			priority: 9,
			/*
			 * Paragraphs only. A `<pre>` whose text is a `[github]` shortcode is
			 * somebody documenting the shortcode, not using it.
			 */
			isMatch: ( node: Element ) =>
				'P' === node.nodeName &&
				null !== loneGistShortcode( node.textContent ?? '' ),
			/*
			 * `textContent` is exact: the autolink left the address as the
			 * element's text as well as in `href`.
			 */
			transform: ( node: Element ) =>
				createBlock( metadata.name, {
					url: gistUrl(
						loneGistShortcode( node.textContent ?? '' ) ?? {}
					),
				} ),
		},
	],
};

export default transforms;
