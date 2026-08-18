<?php
/**
 * Helpers for driving the plugin's content pipeline from a test.
 *
 * @package iG_Syntax_Hiliter
 */

declare( strict_types = 1 );

namespace iG\Syntax_Hiliter\Tests\Integration\Traits;

use iG\Syntax_Hiliter\Block;
use ReflectionProperty;

/**
 * The three things nearly every case in this tier does before it can assert
 * anything: run content through one of WordPress' own filters, build a block
 * delimiter, and put a singleton back where it found it.
 *
 * Each of them was written out in full in file after file — nine copies of the
 * filter helper alone, byte for byte, `phpcs:ignore` and all. A copy of a line
 * whose only content is a suppression is not a fixture; it is the same line nine
 * times.
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
	 * would really submit rather than a hand written approximation of them — which
	 * matters most for the attribute escaping, since that is what the save path has
	 * to survive.
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
	 * Every singleton in the plugin lives for the whole process, so an object built
	 * by one test is handed to every test after it. A case which changes what a
	 * singleton was built from has to put the old one back, and one which wants a
	 * fresh build passes `NULL`.
	 *
	 * @param string      $class_name Class whose instance slot is to be set.
	 * @param object|null $instance   Instance to put there, or NULL to leave it unbuilt.
	 *
	 * @return void
	 */
	protected function _set_singleton( string $class_name, ?object $instance ): void {
		( new ReflectionProperty( $class_name, '_instance' ) )->setValue( null, $instance );
	}

}    //end of trait

//EOF
