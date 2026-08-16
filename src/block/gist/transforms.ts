/**
 * Transforms into the Gist block.
 *
 * A `[github]` on the clipboard becomes this block. Existing `[github]`
 * shortcodes in stored posts are deliberately left alone: a Gist shortcode holds
 * an address and not code, so nothing damages it where it stands, and rewriting
 * somebody's post to gain a block is not a trade this plugin makes on its own.
 *
 * There are two transforms because one of them cannot fire for the form of the
 * shortcode almost everybody writes. `pasteHandler()` sends a plain text paste
 * through its markdown converter first — `marked`, `gfm: true` — and `gfm` turns
 * on autolink literals, so the bare address inside `gist="…"` becomes an `<a>`
 * element before anything has looked for a shortcode. Core then declines to
 * convert: a self-closing shortcode is only converted when a newline, `</p>` or
 * `<br>` follows it, and what follows this one is the `</a>` the converter itself
 * put there. The shortcode transform is never called, and the Gist is lost.
 *
 * The `raw` transform below picks the paste up one stage later, off the finished
 * paragraph. `textContent` is the repair and it is exact: the autolink left the
 * address as the element's own text as well as in its `href`, so the paragraph's
 * text is the author's bytes back, character for character. Nothing is unpicked
 * and nothing is guessed at.
 *
 * The `[github id="…"]` form carries no address, is not autolinked, and goes on
 * being converted by the shortcode transform.
 */

import { createBlock } from '@wordpress/blocks';
import { next } from '@wordpress/shortcode';

import metadata from './block.json';

const TAG = 'github';

interface GistShortcodeAttributes {
	gist?: string;
	id?: string;
}

/**
 * The Gist address one matched shortcode stands for.
 *
 * The `gist` attribute is the address itself. The older `id` attribute is the
 * last segment of one, which is how `Gist_Embed::render()` reads the two as well.
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
 * Matching is done with core's own shortcode matcher rather than a regular
 * expression of this plugin's, so this reads the paste exactly as the stage
 * before it did. The match has to cover the whole of the text: a paragraph which
 * only mentions the shortcode in a sentence stays a paragraph, which is the rule
 * core applies one stage earlier too.
 *
 * @param text Text content of the node being offered.
 */
function loneGistShortcode( text: string ): GistShortcodeAttributes | null {
	const trimmed = text.trim();
	const match = next( TAG, trimmed );

	if (
		! match ||
		0 !== match.index ||
		trimmed.length !== match.content.length
	) {
		return null;
	}

	return match.shortcode.attrs.named as GistShortcodeAttributes;
}

const transforms = {
	from: [
		{
			type: 'shortcode',
			tag: TAG,
			transform: ( attributes: { named?: GistShortcodeAttributes } ) =>
				createBlock( metadata.name, {
					url: gistUrl( attributes.named ?? {} ),
				} ),
		},
		{
			type: 'raw',
			/*
			 * `findTransform()` settles equal priorities by registration order, and
			 * core's blocks are always registered before a plugin's, so the default
			 * would lose every paragraph to `core/paragraph`.
			 */
			priority: 9,
			/*
			 * Paragraphs only. A `<pre>` whose text is a `[github]` shortcode is
			 * somebody documenting the shortcode, not using it.
			 */
			isMatch: ( node: Element ) =>
				'P' === node.nodeName &&
				null !== loneGistShortcode( node.textContent ?? '' ),
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
