<?php
/**
 * Whether a post's Group blocks are sent to beehiiv as sections.
 *
 * @package beehiiv
 */

namespace Beehiiv\Newsletter;

use Beehiiv\Editor\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks, per post, whether `core/group` blocks convert to beehiiv `section` blocks.
 *
 * Groups become sections for posts whose first beehiiv post was created after the
 * section change. A post that was already linked to a beehiiv post before that keeps
 * the earlier conversion, with Groups left out. The record is stored in
 * {@see Meta::BEEHIIV_GROUP_SECTIONS}: `1` for sections, `0` for the earlier conversion.
 * A linked post without a record was linked before the change.
 *
 * @since x.x.x
 */
final class SectionConversion {

	/**
	 * Stored value when Groups convert to sections.
	 */
	public const SECTIONS = '1';

	/**
	 * Stored value when Groups are left out, as before the section change.
	 */
	public const LEGACY = '0';

	/**
	 * Whether the post's Group blocks convert to beehiiv sections.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 * @since x.x.x
	 */
	public static function uses_sections( int $post_id ): bool {
		$value = get_post_meta( $post_id, Meta::BEEHIIV_GROUP_SECTIONS, true );

		if ( self::SECTIONS === $value ) {
			return true;
		}

		if ( self::LEGACY === $value ) {
			return false;
		}

		// A post linked without a record was linked before the section change.
		$beehiiv_post_id = get_post_meta( $post_id, Meta::BEEHIIV_POST_ID, true );

		return ! is_string( $beehiiv_post_id ) || '' === trim( $beehiiv_post_id );
	}

	/**
	 * Store the conversion the post uses.
	 *
	 * @param int  $post_id       Post ID.
	 * @param bool $uses_sections Whether Groups convert to sections.
	 * @return void
	 * @since x.x.x
	 */
	public static function record( int $post_id, bool $uses_sections ): void {
		update_post_meta( $post_id, Meta::BEEHIIV_GROUP_SECTIONS, $uses_sections ? self::SECTIONS : self::LEGACY );
	}

	/**
	 * Forget the record, so the next beehiiv post created for the post counts as a first sync.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 * @since x.x.x
	 */
	public static function forget( int $post_id ): void {
		delete_post_meta( $post_id, Meta::BEEHIIV_GROUP_SECTIONS );
	}
}
