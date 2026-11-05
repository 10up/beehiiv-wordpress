<?php
/**
 * WordPress blocks supported for beehiiv newsletter conversion.
 *
 * @package beehiiv
 */

namespace Beehiiv\Newsletter;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for which WordPress blocks are included in newsletters.
 *
 * Layout blocks such as `core/columns` and `core/column` are not supported. They
 * and all of their inner blocks are omitted from the newsletter payload, also
 * inside a section. `core/group` ({@see SupportedBlocks::SECTION_BLOCK}) converts
 * to a beehiiv `section` for posts that use sections (see `SectionConversion`);
 * other posts omit it like any other layout block.
 *
 * @since 1.0.0
 */
final class SupportedBlocks {

	/**
	 * Blocks converted to beehiiv newsletter blocks.
	 *
	 * @var array<int, string>
	 */
	public const BLOCKS = [
		'core/heading',
		'core/paragraph',
		'core/image',
		'core/list',
		'core/table',
		'core/quote',
		'core/pullquote',
		'core/embed',
		'core/media-text',
		'core/buttons',
		'core/separator',
		'beehiiv/advertisement',
	];

	/**
	 * Inner blocks handled by a parent; never warned or converted on their own.
	 *
	 * @var array<int, string>
	 */
	public const NESTED_ONLY = [
		'core/button',
		'core/list-item',
	];

	/**
	 * Blocks only included when snippet newsletter mode is enabled.
	 *
	 * @var array<int, string>
	 */
	public const SNIPPET_ONLY = [
		'core/more',
	];

	/**
	 * Block converted to a beehiiv `section` for posts that use sections.
	 *
	 * Not in {@see SupportedBlocks::BLOCKS}: posts linked before the section change
	 * still omit it.
	 *
	 * @since x.x.x
	 */
	public const SECTION_BLOCK = 'core/group';

	/**
	 * Block support config for the block editor (localized to JavaScript).
	 *
	 * @return array<string, array<int, string>|string>
	 * @since 1.0.0
	 */
	public static function get_editor_config(): array {
		return [
			'supported'    => self::BLOCKS,
			'nestedOnly'   => self::NESTED_ONLY,
			'snippetOnly'  => self::SNIPPET_ONLY,
			'sectionBlock' => self::SECTION_BLOCK,
		];
	}

	/**
	 * Whether a block is converted for beehiiv newsletters.
	 *
	 * @param string $block_name   Block name (e.g. core/paragraph).
	 * @param bool   $snippet_mode Whether snippet newsletter mode is enabled.
	 * @return bool
	 * @since 1.0.0
	 */
	public static function is_supported( string $block_name, bool $snippet_mode ): bool {
		if ( in_array( $block_name, self::NESTED_ONLY, true ) ) {
			return true;
		}

		if ( in_array( $block_name, self::SNIPPET_ONLY, true ) ) {
			return $snippet_mode;
		}

		return in_array( $block_name, self::BLOCKS, true );
	}
}
