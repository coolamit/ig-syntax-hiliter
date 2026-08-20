/**
 * Entry point for the Gist block.
 *
 * Dynamic: `save` returns nothing and PHP renders through the same `[github]`
 * pipeline as the shortcode, so settings apply to both.
 */

import { registerBlockType } from '@wordpress/blocks';
import type { BlockConfiguration } from '@wordpress/blocks';

import metadata from './block.json';
import Edit from './edit';
import transforms from './transforms';

// `@wordpress/blocks` does not type `tag` or `schema` on a transform.
const settings = {
	edit: Edit,
	save: () => null,
	transforms,
} as unknown as Partial< BlockConfiguration >;

registerBlockType( metadata as unknown as BlockConfiguration, settings );
