<?php
/**
 * Helpers for driving the plugin's content pipeline from a test.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration\Traits;

use iG\Syntax_Hiliter\Base;
use iG\Syntax_Hiliter\Block;
use ReflectionProperty;

/**
 * The things nearly every case in this tier does: run content through a WordPress
 * filter, build a block delimiter, put a singleton back, and write one setting.
 */
trait Pipeline_Test_Helpers {

	/**
	 * Method to run content through one of WordPress' own filters.
	 *
	 * @param string $filter  Filter name.
	 * @param string $content Content to filter.
	 *
	 * @return string
	 */
	protected function _filter( string $filter, string $content ): string {
		return (string) apply_filters( $filter, $content );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Running content through core's own hooks is what an integration test does.
	}

	/**
	 * Method to build a block delimiter exactly as WordPress writes it.
	 *
	 * Core does the serializing, so a fixture carries the bytes the block editor
	 * really submits.
	 *
	 * @param array  $attributes Block attributes.
	 * @param string $block_name Optional. Block name. This plugin's code block by default.
	 *
	 * @return string
	 */
	protected static function _block( array $attributes = [], string $block_name = Block::NAME ): string {

		return serialize_block(
			[
				'blockName'    => $block_name,
				'attrs'        => $attributes,
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);

	}

	/**
	 * Method to build a Gist block delimiter.
	 *
	 * @param array $attributes Block attributes.
	 *
	 * @return string
	 */
	protected static function _gist_block( array $attributes = [] ): string {
		return static::_block( $attributes, Block::GIST_NAME );
	}

	/**
	 * Method to put a singleton's instance slot back to a known state.
	 *
	 * A singleton lives for the whole process, so a case which rebuilt one has to
	 * put the old one back; `NULL` leaves it unbuilt.
	 *
	 * @param string      $class_name Class whose instance slot is to be set.
	 * @param object|null $instance   Instance to put there, or NULL to leave it unbuilt.
	 *
	 * @return void
	 */
	protected function _set_singleton( string $class_name, ?object $instance ): void {
		( new ReflectionProperty( $class_name, '_instance' ) )->setValue( null, $instance );
	}

	/**
	 * Method to write one plugin setting straight into the stored option array.
	 *
	 * @param string $name  Option name.
	 * @param string $value Option value.
	 *
	 * @return void
	 */
	protected function _store_option( string $name, string $value ): void {

		$options          = (array) get_option( Base::PLUGIN_ID . '-options', [] );
		$options[ $name ] = $value;

		update_option( Base::PLUGIN_ID . '-options', $options );

	}

} // end of trait

// EOF
