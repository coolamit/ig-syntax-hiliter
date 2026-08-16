/**
 * Entry point for the Gist block.
 *
 * Dynamic, like the code block: `save` returns nothing and PHP renders the
 * embed through the same `[github]` pipeline the shortcode has always used, so
 * there is one embed implementation and the settings apply to both.
 */

import { createBlock, registerBlockType } from '@wordpress/blocks';
import type { BlockConfiguration } from '@wordpress/blocks';

import metadata from './block.json';
import Edit from './edit';

interface GistShortcodeAttributes {
	named?: { gist?: string; id?: string };
}

/*
 * A `[github]` on the clipboard becomes this block. Existing `[github]`
 * shortcodes in stored posts are deliberately left alone: they go on working,
 * and rewriting somebody's post to gain a block is not a trade this plugin makes
 * on its own.
 */
const transforms = {
	from: [
		{
			type: 'shortcode',
			tag: 'github',
			transform: ( attributes: GistShortcodeAttributes ) => {
				const named = attributes.named ?? {};
				const url = named.gist ?? '';

				return createBlock( metadata.name, {
					url:
						url === '' && named.id
							? `https://gist.github.com/${ named.id }`
							: url,
				} );
			},
		},
	],
};

/*
 * `@wordpress/blocks` does not type `tag` on a transform, so the settings are
 * cast rather than the transform being written around the gap.
 */
const settings = {
	edit: Edit,
	save: () => null,
	transforms,
} as unknown as Partial< BlockConfiguration >;

registerBlockType( metadata as unknown as BlockConfiguration, settings );
