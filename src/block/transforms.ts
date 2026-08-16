/**
 * Transforms into and out of the code snippet block.
 *
 * Entity handling is the trap here. `core/code`, `core/preformatted` and
 * `core/paragraph` all hold their content as rich text, which is escaped HTML on
 * the way in and out; this block holds raw text in its JSON attributes. Both
 * directions therefore go through `RichTextData`, which decodes and encodes
 * exactly once — hand-rolled escaping would risk doing it twice and corrupting
 * the code.
 */

import { createBlock } from '@wordpress/blocks';
import { RichTextData } from '@wordpress/rich-text';

import {
	BLOCK_NAME,
	getLegacyTags,
	mapShortcodeAttributes,
} from './attributes';
import type {
	CodeBlockAttributes,
	ShortcodeNamedAttributes,
} from './attributes';

interface ShortcodeMatch {
	shortcode: {
		tag: string;
		content?: string | undefined;
	};
}

/**
 * Reads a rich text attribute as the plain source code it stands for.
 *
 * @param value Attribute value handed over by the block being transformed.
 */
function toPlainCode( value: unknown ): string {
	if ( value instanceof RichTextData ) {
		return value.toPlainText();
	}

	if ( typeof value === 'string' ) {
		return RichTextData.fromHTMLString( value ).toPlainText();
	}

	return '';
}

/**
 * Undoes what the paste handler's markdown pass did to a snippet.
 *
 * A paste of plain text does not reach the shortcode transform as plain text.
 * `pasteHandler()` decides the paste is plain, runs the whole clipboard through
 * `markdownConverter` — `marked`, configured `gfm: true, breaks: true` — and only
 * then looks for shortcodes in the HTML that came out. So the body handed to the
 * transform has a `<br>` where the author had a newline, a paragraph boundary
 * where the author had a blank line, and `&amp;`/`&lt;`/`&gt;` where the author
 * had those characters in ordinary text. Stored as it arrived, that is the
 * `<br />` on the end of every line which is what got this looked at.
 *
 * **This works on the string and never parses it as HTML, and that is the whole
 * design.** Reading it as HTML looks tidier and loses code: `marked` passes an
 * inline HTML run through untouched, so a PHP snippet arrives holding a literal
 * `<?php … if ( $a < $b && $c > $d )`, and an HTML parser eats every byte from
 * that `<?` to the first `>` after it. Failure here has to mean "changed
 * nothing", never "matched everything".
 *
 * What each replacement is, and what it costs:
 *
 * - `</p><p>` is a paragraph boundary `marked` built. An author who typed those
 *   characters had them escaped on the way here, so the sequence can only be the
 *   converter's.
 * - `<br>` is exactly what `marked` emits for a line break, byte for byte. The
 *   author's own `<br />`, `<br/>` and `<BR>` are left alone, because they are
 *   not that sequence. An author who typed a bare lower case `<br>` loses it to a
 *   newline, and that is the one case this cannot tell apart.
 * - The three entities go back to the characters they stand for. An author whose
 *   code contains the literal text `&amp;` gets `&` instead, which is the same
 *   trade the other way round — and `&`, `<` and `>` are in nearly every snippet,
 *   while the spelled out entity is in almost none.
 *
 * `convert.ts` deliberately does none of this. It reads the author's bytes out of
 * the stored post, where no markdown converter has ever been near them.
 *
 * @param value Text as the paste handler matched it.
 */
function decodePastedText( value: unknown ): string {
	if ( typeof value !== 'string' ) {
		return toPlainCode( value );
	}

	return value
		.replace( /<\/p>\s*<p[^>]*>/g, '\n\n' )
		.replace( /<br>/g, '\n' )
		.replace( /&lt;/g, '<' )
		.replace( /&gt;/g, '>' )
		.replace( /&amp;/g, '&' );
}

/**
 * Reads every attribute of a pasted shortcode the same way.
 *
 * The attribute string went through the same markdown pass the body did, so a
 * file label of `a&b.java` arrives as `a&amp;b.java`.
 *
 * @param named Attributes as the paste handler matched them.
 */
function decodePastedAttributes(
	named: ShortcodeNamedAttributes
): ShortcodeNamedAttributes {
	const decoded: ShortcodeNamedAttributes = {};

	Object.keys( named ).forEach( ( key ) => {
		decoded[ key ] = decodePastedText( named[ key ] );
	} );

	return decoded;
}

const transforms = {
	from: [
		{
			type: 'block',
			blocks: [ 'core/code', 'core/preformatted', 'core/paragraph' ],
			transform: ( { content }: { content?: unknown } ) =>
				createBlock( BLOCK_NAME, { code: toPlainCode( content ) } ),
		},
		{
			type: 'raw',
			/*
			 * `core/code` declares a raw transform whose `isMatch` is identical to
			 * this one, and gives it no priority. `findTransform()` puts every
			 * candidate on a hook and takes the first result back, so an equal
			 * priority is settled by registration order — and core's blocks are
			 * always registered before a plugin's. Left at the default this
			 * transform matched every paste and won none of them.
			 */
			priority: 9,
			isMatch: ( node: Element ) =>
				node.nodeName === 'PRE' &&
				node.children.length === 1 &&
				node.firstChild?.nodeName === 'CODE',
			schema: ( {
				phrasingContentSchema,
			}: {
				phrasingContentSchema: unknown;
			} ) => ( {
				pre: {
					children: {
						code: { children: phrasingContentSchema },
					},
				},
			} ),
			transform: ( node: Element ) =>
				createBlock( BLOCK_NAME, { code: node.textContent ?? '' } ),
		},
		{
			type: 'shortcode',
			tag: getLegacyTags(),
			transform: (
				attributes: { named?: ShortcodeNamedAttributes },
				match: ShortcodeMatch
			) =>
				createBlock( BLOCK_NAME, {
					...mapShortcodeAttributes(
						match.shortcode.tag,
						decodePastedAttributes( attributes.named ?? {} ),
						decodePastedText( match.shortcode.content ?? '' )
					),
				} ),
		},
	],
	to: [
		{
			type: 'block',
			blocks: [ 'core/code' ],
			transform: ( { code }: CodeBlockAttributes ) =>
				createBlock( 'core/code', {
					content: RichTextData.fromPlainText( code ),
				} ),
		},
	],
};

export default transforms;
