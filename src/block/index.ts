/**
 * Entry point for the block editor bundle.
 *
 * The block is dynamic — `save` returns nothing and PHP renders, so the code
 * never becomes inner HTML the filter chain can reach.
 */

import { registerBlockType } from '@wordpress/blocks';
import type { BlockConfiguration } from '@wordpress/blocks';

import metadata from './block.json';
import Edit from './edit';
import transforms from './transforms';
import { initLegacyConversion } from './convert';

import './editor.scss';

// `@wordpress/blocks` does not type `tag` or `schema` on a transform.
const settings = {
	edit: Edit,
	save: () => null,
	transforms,
} as unknown as Partial< BlockConfiguration >;

registerBlockType( metadata as unknown as BlockConfiguration, settings );

initLegacyConversion();
