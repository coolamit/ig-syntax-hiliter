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
						attributes.named ?? {},
						match.shortcode.content ?? ''
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
