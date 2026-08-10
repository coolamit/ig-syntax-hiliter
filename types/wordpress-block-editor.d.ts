/**
 * Ambient declarations for `@wordpress/block-editor`.
 *
 * The package ships compiled declarations in `build-types/`, but only for
 * `components/` and `utils/` — there is no root `index.d.ts` and no `types`
 * field in its package.json, so TypeScript resolves the entry point to plain
 * JavaScript and `tsc` fails with TS7016. There is no `@types/` package for it
 * either.
 *
 * Without this file the whole of `src/` goes unchecked. With it, the loss is
 * confined to the three symbols below. Delete this file once the package
 * declares its own root types.
 */

declare module '@wordpress/block-editor' {
	import type { ComponentProps, ReactNode } from 'react';

	/**
	 * Renders its children into the block inspector sidebar.
	 */
	export const InspectorControls: React.FC< { children?: ReactNode } >;

	/**
	 * An unformatted textarea. Unlike `RichText` it never interprets its value
	 * as HTML.
	 */
	export const PlainText: React.FC<
		{
			value: string;
			onChange: ( value: string ) => void;
		} & Omit< ComponentProps< 'textarea' >, 'value' | 'onChange' >
	>;

	/**
	 * Returns the props the block's wrapper element must carry.
	 *
	 * @param props Extra props to merge into the generated ones.
	 */
	export function useBlockProps(
		props?: ComponentProps< 'div' >
	): ComponentProps< 'div' >;
}
