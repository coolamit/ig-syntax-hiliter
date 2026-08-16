/**
 * Entry point for the Gist block.
 *
 * Dynamic, like the code block: `save` returns nothing and PHP renders the
 * embed through the same `[github]` pipeline the shortcode has always used, so
 * there is one embed implementation and the settings apply to both.
 */

import { registerBlockType } from '@wordpress/blocks';
import type { BlockConfiguration } from '@wordpress/blocks';

import metadata from './block.json';
import Edit from './edit';
import transforms from './transforms';

/*
 * `@wordpress/blocks` does not type `tag` or `schema` on a transform, so the
 * settings are cast rather than the transforms being written around the gap.
 */
const settings = {
	edit: Edit,
	save: () => null,
	transforms,
} as unknown as Partial< BlockConfiguration >;

registerBlockType( metadata as unknown as BlockConfiguration, settings );
