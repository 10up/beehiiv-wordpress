<?php
/**
 * Unit coverage for Group blocks sent to beehiiv as sections.
 *
 * Block Conversion Framework PRD AC-009 to AC-018, BR-002, BR-007, BR-008.
 * beehiiv HTTP is mocked via `pre_http_request`.
 *
 * @package beehiiv
 */

use Beehiiv\API\Cache;
use Beehiiv\Config;
use Beehiiv\Editor\Meta;
use Beehiiv\Newsletter\BlockConverter;
use Beehiiv\Newsletter\SectionConversion;
use Beehiiv\Newsletter\Sender;
use Beehiiv\Newsletter\SupportedBlocks;
use Beehiiv\OAuth\TokenStore;

/**
 * @covers \Beehiiv\Newsletter\BlockConverter
 * @covers \Beehiiv\Newsletter\SectionConversion
 * @covers \Beehiiv\Newsletter\Sender
 */
class NewsletterGroupSectionTest extends WP_UnitTestCase {

	/**
	 * HTTP requests made during the test.
	 *
	 * @var array<int, array{method: string, url: string, body: array}>
	 */
	private $requests = array();

	/**
	 * HTTP status returned for create (POST) requests.
	 *
	 * @var int
	 */
	private $create_status = 200;

	public function set_up(): void {
		parent::set_up();

		$this->requests      = array();
		$this->create_status = 200;

		TokenStore::save_tokens(
			'client_test',
			array(
				'access_token'  => 'access_test',
				'refresh_token' => 'refresh_test',
				'expires_in'    => DAY_IN_SECONDS,
			)
		);

		update_option(
			Config::OPTION_NAME,
			array(
				'publication_id'   => 'pub_default',
				'post_template_id' => 'tpl_default',
			)
		);

		Cache::set_post_templates(
			'pub_default',
			array(
				array(
					'id'   => 'tpl_default',
					'name' => 'Default template',
				),
				array(
					'id'   => 'tpl_alt',
					'name' => 'Alt template',
				),
			)
		);

		add_filter( 'pre_http_request', array( $this, 'mock_http' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'mock_http' ), 10 );
		Cache::flush_all();
		parent::tear_down();
	}

	/**
	 * Record every request and answer as beehiiv would.
	 *
	 * @param false|array $preempt Short-circuit value.
	 * @param array       $args    Request args.
	 * @param string      $url     Request URL.
	 * @return array
	 */
	public function mock_http( $preempt, $args, $url ) {
		$method = isset( $args['method'] ) ? strtoupper( $args['method'] ) : 'GET';
		$body   = isset( $args['body'] ) && is_string( $args['body'] ) ? json_decode( $args['body'], true ) : array();

		$this->requests[] = array(
			'method' => $method,
			'url'    => $url,
			'body'   => is_array( $body ) ? $body : array(),
		);

		if ( 'DELETE' === $method ) {
			return $this->http_response( 204, array() );
		}

		if ( 'POST' === $method ) {
			if ( 200 !== $this->create_status ) {
				return $this->http_response( $this->create_status, array( 'message' => 'Server error' ) );
			}

			return $this->http_response( 200, array( 'data' => array( 'id' => 'post_new' ) ) );
		}

		return $this->http_response( 200, array( 'data' => array( 'id' => 'post_linked' ) ) );
	}

	/**
	 * Build a WP HTTP API response array.
	 *
	 * @param int   $code HTTP status.
	 * @param array $body JSON body.
	 * @return array
	 */
	private function http_response( int $code, array $body ): array {
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $body ),
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Requests made with a given HTTP method.
	 *
	 * @param string $method HTTP method.
	 * @return array
	 */
	private function requests_with( string $method ): array {
		return array_values(
			array_filter(
				$this->requests,
				static function ( $request ) use ( $method ) {
					return $method === $request['method'];
				}
			)
		);
	}

	/**
	 * Paragraph block markup.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function paragraph( string $text ): string {
		return "<!-- wp:paragraph -->\n<p>{$text}</p>\n<!-- /wp:paragraph -->";
	}

	/**
	 * Heading block markup.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function heading( string $text ): string {
		return "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">{$text}</h2>\n<!-- /wp:heading -->";
	}

	/**
	 * Group block markup.
	 *
	 * @param string $inner Inner block markup.
	 * @param string $attrs Optional JSON attributes.
	 * @return string
	 */
	private function group( string $inner, string $attrs = '' ): string {
		$open = '' === $attrs ? '<!-- wp:group -->' : "<!-- wp:group {$attrs} -->";

		return "{$open}\n<div class=\"wp-block-group\">{$inner}</div>\n<!-- /wp:group -->";
	}

	/**
	 * Columns block markup with one column.
	 *
	 * @param string $inner Column inner block markup.
	 * @return string
	 */
	private function columns( string $inner ): string {
		return "<!-- wp:columns -->\n<div class=\"wp-block-columns\"><!-- wp:column -->\n<div class=\"wp-block-column\">{$inner}</div>\n<!-- /wp:column --></div>\n<!-- /wp:columns -->";
	}

	/**
	 * Create a published post queued for the newsletter.
	 *
	 * @param string $content Post content.
	 * @return int Post ID.
	 */
	private function create_post( string $content ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Weekly roundup',
				'post_content' => $content,
			)
		);

		update_post_meta( $post_id, Meta::SEND_TO_NEWSLETTER, true );
		$this->requests = array();

		return $post_id;
	}

	/**
	 * Convert a post's content.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, array<string, mixed>>
	 */
	private function convert( int $post_id ): array {
		return BlockConverter::convert_supported_blocks( get_post( $post_id ) );
	}

	/**
	 * Mark a post as linked to beehiiv before the section change (no record).
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function link_before_change( int $post_id ): void {
		update_post_meta( $post_id, Meta::BEEHIIV_POST_ID, 'post_linked' );
		update_post_meta( $post_id, Meta::BEEHIIV_LINKED_PUBLICATION_ID, 'pub_default' );
		update_post_meta( $post_id, Meta::BEEHIIV_LINKED_POST_TEMPLATE_ID, 'tpl_default' );
		update_post_meta( $post_id, Meta::SEND_TO_NEWSLETTER, false );
	}

	/**
	 * AC-009, AC-010, BR-002, BR-008: a Group with a heading and two paragraphs is one section
	 * holding exactly those three blocks, in order, with no section settings.
	 */
	public function test_group_converts_to_one_section_with_inner_blocks_in_order(): void {
		$post_id = $this->create_post(
			$this->group( $this->heading( 'Big news' ) . $this->paragraph( 'First.' ) . $this->paragraph( 'Second.' ) )
		);

		$blocks = $this->convert( $post_id );

		$this->assertCount( 1, $blocks );
		$this->assertSame( array( 'type', 'blocks' ), array_keys( $blocks[0] ) );
		$this->assertSame( 'section', $blocks[0]['type'] );
		$this->assertCount( 3, $blocks[0]['blocks'] );
		$this->assertSame( 'heading', $blocks[0]['blocks'][0]['type'] );
		$this->assertSame( 'Big news', $blocks[0]['blocks'][0]['text'] );
		$this->assertSame( 'paragraph', $blocks[0]['blocks'][1]['type'] );
		$this->assertSame( 'First.', $blocks[0]['blocks'][1]['plaintext'] );
		$this->assertSame( 'paragraph', $blocks[0]['blocks'][2]['type'] );
		$this->assertSame( 'Second.', $blocks[0]['blocks'][2]['plaintext'] );
	}

	/**
	 * BR-008: Group styling attributes are never sent as section settings.
	 */
	public function test_group_styles_are_not_sent(): void {
		$post_id = $this->create_post(
			$this->group(
				$this->paragraph( 'Styled.' ),
				'{"backgroundColor":"accent","style":{"spacing":{"padding":{"top":"20px"}}}}'
			)
		);

		$blocks = $this->convert( $post_id );

		$this->assertSame( array( 'type', 'blocks' ), array_keys( $blocks[0] ) );
	}

	/**
	 * Brief acceptance test: the API payload holds one section with the three child blocks.
	 */
	public function test_sent_payload_holds_one_section_with_three_children(): void {
		$post_id = $this->create_post(
			$this->group( $this->heading( 'Big news' ) . $this->paragraph( 'First.' ) . $this->paragraph( 'Second.' ) )
		);

		Sender::send( $post_id );

		$creates = $this->requests_with( 'POST' );
		$this->assertCount( 1, $creates );
		$this->assertCount( 1, $creates[0]['body']['blocks'] );
		$this->assertSame( 'section', $creates[0]['body']['blocks'][0]['type'] );
		$this->assertSame(
			array( 'heading', 'paragraph', 'paragraph' ),
			array_column( $creates[0]['body']['blocks'][0]['blocks'], 'type' )
		);
	}

	/**
	 * AC-011: nested Groups become nested sections at every level.
	 */
	public function test_nested_groups_become_nested_sections(): void {
		$post_id = $this->create_post(
			$this->group(
				$this->paragraph( 'Level one.' ) .
				$this->group(
					$this->paragraph( 'Level two.' ) .
					$this->group( $this->paragraph( 'Level three.' ) )
				)
			)
		);

		$blocks = $this->convert( $post_id );

		$this->assertCount( 1, $blocks );
		$level_one = $blocks[0];
		$this->assertSame( 'section', $level_one['type'] );
		$this->assertSame( 'paragraph', $level_one['blocks'][0]['type'] );
		$level_two = $level_one['blocks'][1];
		$this->assertSame( 'section', $level_two['type'] );
		$this->assertSame( 'Level two.', $level_two['blocks'][0]['plaintext'] );
		$level_three = $level_two['blocks'][1];
		$this->assertSame( 'section', $level_three['type'] );
		$this->assertSame( 'Level three.', $level_three['blocks'][0]['plaintext'] );
	}

	/**
	 * AC-013, AC-017: unsupported, Columns, and empty inner blocks are left out of the section.
	 */
	public function test_unsupported_and_empty_inner_blocks_are_left_out(): void {
		$post_id = $this->create_post(
			$this->group(
				$this->paragraph( 'Keep me.' ) .
				"<!-- wp:spacer -->\n<div style=\"height:100px\" class=\"wp-block-spacer\"></div>\n<!-- /wp:spacer -->" .
				$this->columns( $this->paragraph( 'In a column.' ) ) .
				$this->paragraph( '   ' ) .
				"<!-- wp:list -->\n<ul class=\"wp-block-list\"><!-- wp:list-item -->\n<li>Item</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->"
			)
		);

		$blocks = $this->convert( $post_id );

		$this->assertCount( 1, $blocks );
		$this->assertSame( array( 'paragraph', 'list' ), array_column( $blocks[0]['blocks'], 'type' ) );
		$this->assertSame( 'Keep me.', $blocks[0]['blocks'][0]['plaintext'] );
	}

	/**
	 * AC-014: an empty Group or a Group with nothing to convert sends no section.
	 */
	public function test_group_without_converted_blocks_sends_no_section(): void {
		$post_id = $this->create_post(
			$this->paragraph( 'Before.' ) .
			$this->group( '' ) .
			$this->group( "<!-- wp:spacer -->\n<div class=\"wp-block-spacer\"></div>\n<!-- /wp:spacer -->" . $this->paragraph( '' ) ) .
			$this->paragraph( 'After.' )
		);

		$blocks = $this->convert( $post_id );

		$this->assertSame( array( 'paragraph', 'paragraph' ), array_column( $blocks, 'type' ) );
	}

	/**
	 * AC-014: a nested Group with nothing to convert is left out; the parent keeps its other blocks.
	 */
	public function test_empty_nested_group_is_left_out_of_parent(): void {
		$post_id = $this->create_post(
			$this->group( $this->paragraph( 'Kept.' ) . $this->group( '' ) )
		);

		$blocks = $this->convert( $post_id );

		$this->assertCount( 1, $blocks );
		$this->assertSame( array( 'paragraph' ), array_column( $blocks[0]['blocks'], 'type' ) );
	}

	/**
	 * AC-015: in snippet mode, `core/more` inside a Group ends the whole conversion.
	 */
	public function test_more_inside_group_ends_conversion_in_snippet_mode(): void {
		$post_id = $this->create_post(
			$this->group(
				$this->paragraph( 'Teaser.' ) .
				"<!-- wp:more -->\n<!--more-->\n<!-- /wp:more -->" .
				$this->paragraph( 'Hidden in group.' )
			) .
			$this->paragraph( 'Hidden after group.' )
		);
		update_post_meta( $post_id, Meta::SEND_TO_NEWSLETTER_SNIPPET, true );

		$blocks = $this->convert( $post_id );

		$this->assertCount( 1, $blocks );
		$this->assertSame( 'section', $blocks[0]['type'] );
		$this->assertCount( 2, $blocks[0]['blocks'] );
		$this->assertSame( 'Teaser.', $blocks[0]['blocks'][0]['plaintext'] );
		$this->assertSame( 'button', $blocks[0]['blocks'][1]['type'] );
		$this->assertSame( 'Read More', $blocks[0]['blocks'][1]['text'] );
		$this->assertSame( get_permalink( $post_id ), $blocks[0]['blocks'][1]['href'] );
		$this->assertSame( '_blank', $blocks[0]['blocks'][1]['target'] );
	}

	/**
	 * AC-017, BR-002: Columns stay unsupported at the top level.
	 */
	public function test_columns_stay_unsupported_at_top_level(): void {
		$post_id = $this->create_post(
			$this->columns( $this->paragraph( 'In a column.' ) ) . $this->paragraph( 'Top.' )
		);

		$blocks = $this->convert( $post_id );

		$this->assertSame( array( 'paragraph' ), array_column( $blocks, 'type' ) );
		$this->assertFalse( SupportedBlocks::is_supported( 'core/columns', false ) );
		$this->assertFalse( SupportedBlocks::is_supported( 'core/column', false ) );
	}

	/**
	 * AC-018: a Group inside a Media & Text content column becomes a section inside that column.
	 */
	public function test_group_in_media_text_content_becomes_section(): void {
		$post_id = $this->create_post(
			"<!-- wp:media-text {\"mediaType\":\"image\"} -->\n" .
			'<div class="wp-block-media-text is-stacked-on-mobile"><figure class="wp-block-media-text__media"><img src="https://example.com/a.jpg" alt=""/></figure>' .
			'<div class="wp-block-media-text__content">' .
			$this->group( $this->paragraph( 'In section.' ) . $this->group( '' ) ) .
			"</div></div>\n<!-- /wp:media-text -->"
		);

		$blocks = $this->convert( $post_id );

		$this->assertCount( 1, $blocks );
		$this->assertSame( 'columns', $blocks[0]['type'] );

		$sections = array();
		foreach ( $blocks[0]['columns'] as $column ) {
			foreach ( $column['blocks'] as $block ) {
				if ( 'section' === $block['type'] ) {
					$sections[] = $block;
				}
			}
		}

		$this->assertCount( 1, $sections );
		$this->assertSame( array( 'paragraph' ), array_column( $sections[0]['blocks'], 'type' ) );
	}

	/**
	 * Media & Text block markup with an image and the given content column.
	 *
	 * @param string $content Content column inner block markup.
	 * @return string
	 */
	private function media_text( string $content ): string {
		return "<!-- wp:media-text {\"mediaType\":\"image\"} -->\n" .
			'<div class="wp-block-media-text"><figure class="wp-block-media-text__media"><img src="https://example.com/a.jpg" alt=""/></figure>' .
			'<div class="wp-block-media-text__content">' . $content . "</div></div>\n<!-- /wp:media-text -->";
	}

	/**
	 * AC-015, AC-018: in snippet mode, `core/more` in a Group inside a Media & Text content
	 * column ends that section with the Read More button and ends the whole conversion.
	 */
	public function test_more_in_group_inside_media_text_ends_conversion_in_snippet_mode(): void {
		$post_id = $this->create_post(
			$this->paragraph( 'Intro.' ) .
			$this->media_text(
				$this->paragraph( 'Before group.' ) .
				$this->group(
					$this->paragraph( 'Teaser.' ) .
					"<!-- wp:more -->\n<!--more-->\n<!-- /wp:more -->" .
					$this->paragraph( 'Hidden in group.' )
				) .
				$this->paragraph( 'Hidden in column.' )
			) .
			$this->paragraph( 'Hidden after media text.' )
		);
		update_post_meta( $post_id, Meta::SEND_TO_NEWSLETTER_SNIPPET, true );

		$blocks = $this->convert( $post_id );

		$this->assertSame( array( 'paragraph', 'columns' ), array_column( $blocks, 'type' ) );
		$this->assertSame( 'Intro.', $blocks[0]['plaintext'] );

		$content_column = null;
		foreach ( $blocks[1]['columns'] as $column ) {
			if ( 'image' !== $column['blocks'][0]['type'] ) {
				$content_column = $column;
			}
		}

		$this->assertNotNull( $content_column );
		$this->assertSame( array( 'paragraph', 'section' ), array_column( $content_column['blocks'], 'type' ) );
		$this->assertSame( 'Before group.', $content_column['blocks'][0]['plaintext'] );

		$section = $content_column['blocks'][1];
		$this->assertCount( 2, $section['blocks'] );
		$this->assertSame( 'Teaser.', $section['blocks'][0]['plaintext'] );
		$this->assertSame( 'button', $section['blocks'][1]['type'] );
		$this->assertSame( 'Read More', $section['blocks'][1]['text'] );
		$this->assertSame( get_permalink( $post_id ), $section['blocks'][1]['href'] );
		$this->assertSame( '_blank', $section['blocks'][1]['target'] );
	}

	/**
	 * AC-015, AC-018: the stop also ends an outer section that holds the Media & Text block.
	 */
	public function test_more_in_media_text_group_ends_outer_section_in_snippet_mode(): void {
		$post_id = $this->create_post(
			$this->group(
				$this->media_text(
					$this->group( $this->paragraph( 'Teaser.' ) . "<!-- wp:more -->\n<!--more-->\n<!-- /wp:more -->" )
				) .
				$this->paragraph( 'Hidden in outer group.' )
			) .
			$this->paragraph( 'Hidden after group.' )
		);
		update_post_meta( $post_id, Meta::SEND_TO_NEWSLETTER_SNIPPET, true );

		$blocks = $this->convert( $post_id );

		$this->assertCount( 1, $blocks );
		$this->assertSame( 'section', $blocks[0]['type'] );
		$this->assertSame( array( 'columns' ), array_column( $blocks[0]['blocks'], 'type' ) );
	}

	/**
	 * AC-018: outside snippet mode, `core/more` in a Media & Text Group is left out and conversion continues.
	 */
	public function test_more_in_media_text_group_is_ignored_outside_snippet_mode(): void {
		$post_id = $this->create_post(
			$this->media_text(
				$this->group(
					$this->paragraph( 'First.' ) .
					"<!-- wp:more -->\n<!--more-->\n<!-- /wp:more -->" .
					$this->paragraph( 'Second.' )
				)
			) .
			$this->paragraph( 'After.' )
		);

		$blocks = $this->convert( $post_id );

		$this->assertSame( array( 'columns', 'paragraph' ), array_column( $blocks, 'type' ) );

		$sections = array();
		foreach ( $blocks[0]['columns'] as $column ) {
			foreach ( $column['blocks'] as $block ) {
				if ( 'section' === $block['type'] ) {
					$sections[] = $block;
				}
			}
		}

		$this->assertCount( 1, $sections );
		$this->assertSame( array( 'paragraph', 'paragraph' ), array_column( $sections[0]['blocks'], 'type' ) );
	}

	/**
	 * AC-016, BR-007: a post linked before the change leaves Groups and their content out.
	 */
	public function test_post_linked_before_change_keeps_groups_out(): void {
		$post_id = $this->create_post(
			$this->paragraph( 'Top.' ) . $this->group( $this->paragraph( 'Grouped.' ) )
		);
		$this->link_before_change( $post_id );

		$this->assertFalse( SectionConversion::uses_sections( $post_id ) );
		$this->assertSame( array( 'paragraph' ), array_column( $this->convert( $post_id ), 'type' ) );
		$this->assertSame( 'Top.', $this->convert( $post_id )[0]['plaintext'] );
	}

	/**
	 * AC-016: a Group inside a Media & Text block stays out for a post linked before the change.
	 */
	public function test_post_linked_before_change_keeps_groups_out_of_media_text(): void {
		$post_id = $this->create_post(
			"<!-- wp:media-text {\"mediaType\":\"image\"} -->\n" .
			'<div class="wp-block-media-text"><figure class="wp-block-media-text__media"><img src="https://example.com/a.jpg" alt=""/></figure>' .
			'<div class="wp-block-media-text__content">' .
			$this->paragraph( 'Loose.' ) . $this->group( $this->paragraph( 'Grouped.' ) ) .
			"</div></div>\n<!-- /wp:media-text -->"
		);
		$this->link_before_change( $post_id );

		$blocks = $this->convert( $post_id );

		foreach ( $blocks[0]['columns'] as $column ) {
			$this->assertNotContains( 'section', array_column( $column['blocks'], 'type' ) );
		}
	}

	/**
	 * BR-007: a first send records that the post uses sections, and later updates keep them.
	 */
	public function test_first_send_records_sections_and_updates_keep_them(): void {
		$post_id = $this->create_post( $this->group( $this->paragraph( 'Grouped.' ) ) );

		Sender::send( $post_id );

		$this->assertSame( SectionConversion::SECTIONS, get_post_meta( $post_id, Meta::BEEHIIV_GROUP_SECTIONS, true ) );
		$this->assertTrue( SectionConversion::uses_sections( $post_id ) );

		$this->requests = array();
		Sender::update( $post_id );

		$patches = $this->requests_with( 'PATCH' );
		$this->assertCount( 1, $patches );
		$this->assertSame( 'section', $patches[0]['body']['blocks'][0]['type'] );
	}

	/**
	 * AC-016: a post linked before the change keeps Groups out on a later sync.
	 */
	public function test_update_of_post_linked_before_change_leaves_groups_out(): void {
		$post_id = $this->create_post(
			$this->paragraph( 'Top.' ) . $this->group( $this->paragraph( 'Grouped.' ) )
		);
		$this->link_before_change( $post_id );

		Sender::update( $post_id );

		$patches = $this->requests_with( 'PATCH' );
		$this->assertCount( 1, $patches );
		$this->assertSame( array( 'paragraph' ), array_column( $patches[0]['body']['blocks'], 'type' ) );
	}

	/**
	 * AC-016: recreating the beehiiv post on a move keeps the earlier conversion.
	 */
	public function test_recreate_keeps_earlier_conversion(): void {
		$send_ts = time() + DAY_IN_SECONDS;
		$post_id = $this->create_post(
			$this->paragraph( 'Top.' ) . $this->group( $this->paragraph( 'Grouped.' ) )
		);
		$this->link_before_change( $post_id );
		update_post_meta( $post_id, Meta::SEND_TO_NEWSLETTER_DATE, get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $send_ts ), 'Y-m-d\TH:i:s' ) );
		update_post_meta( $post_id, Meta::BEEHIIV_SCHEDULED_AT, gmdate( 'Y-m-d\TH:i:s\Z', $send_ts ) );
		update_post_meta( $post_id, Meta::BEEHIIV_POST_TEMPLATE_ID, 'tpl_alt' );

		Sender::update( $post_id );

		$this->assertCount( 1, $this->requests_with( 'DELETE' ) );
		$creates = $this->requests_with( 'POST' );
		$this->assertCount( 1, $creates );
		$this->assertSame( array( 'paragraph' ), array_column( $creates[0]['body']['blocks'], 'type' ) );
		$this->assertSame( 'post_new', get_post_meta( $post_id, Meta::BEEHIIV_POST_ID, true ) );
		$this->assertFalse( SectionConversion::uses_sections( $post_id ) );
	}

	/**
	 * AC-016: a failed recreate keeps the earlier conversion for the retry.
	 */
	public function test_failed_recreate_keeps_earlier_conversion_for_retry(): void {
		$send_ts = time() + DAY_IN_SECONDS;
		$post_id = $this->create_post(
			$this->paragraph( 'Top.' ) . $this->group( $this->paragraph( 'Grouped.' ) )
		);
		$this->link_before_change( $post_id );
		update_post_meta( $post_id, Meta::SEND_TO_NEWSLETTER_DATE, get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $send_ts ), 'Y-m-d\TH:i:s' ) );
		update_post_meta( $post_id, Meta::BEEHIIV_SCHEDULED_AT, gmdate( 'Y-m-d\TH:i:s\Z', $send_ts ) );
		update_post_meta( $post_id, Meta::BEEHIIV_POST_TEMPLATE_ID, 'tpl_alt' );
		$this->create_status = 500;

		Sender::update( $post_id );

		$this->assertSame( '', get_post_meta( $post_id, Meta::BEEHIIV_POST_ID, true ) );
		$this->assertFalse( SectionConversion::uses_sections( $post_id ) );

		$this->create_status = 200;
		$this->requests      = array();
		Sender::send( $post_id );

		$creates = $this->requests_with( 'POST' );
		$this->assertCount( 1, $creates );
		$this->assertSame( array( 'paragraph' ), array_column( $creates[0]['body']['blocks'], 'type' ) );
		$this->assertSame( SectionConversion::LEGACY, get_post_meta( $post_id, Meta::BEEHIIV_GROUP_SECTIONS, true ) );
	}

	/**
	 * BR-007: a post whose link is removed counts as a new first sync.
	 */
	public function test_unlinked_post_counts_as_new_first_sync(): void {
		$post_id = $this->create_post(
			$this->paragraph( 'Top.' ) . $this->group( $this->paragraph( 'Grouped.' ) )
		);
		$this->link_before_change( $post_id );

		Sender::cancel_scheduled_newsletter( $post_id );

		$this->assertSame( '', get_post_meta( $post_id, Meta::BEEHIIV_GROUP_SECTIONS, true ) );
		$this->assertTrue( SectionConversion::uses_sections( $post_id ) );

		$this->requests = array();
		Sender::send( $post_id );

		$creates = $this->requests_with( 'POST' );
		$this->assertCount( 1, $creates );
		$this->assertSame( array( 'paragraph', 'section' ), array_column( $creates[0]['body']['blocks'], 'type' ) );
		$this->assertSame( SectionConversion::SECTIONS, get_post_meta( $post_id, Meta::BEEHIIV_GROUP_SECTIONS, true ) );
	}

	/**
	 * Omitted Block Indicator PRD BR-004: the editor reads the same readonly record.
	 */
	public function test_record_is_exposed_readonly_to_the_editor(): void {
		$registered = get_registered_meta_keys( 'post', 'post' );

		$this->assertArrayHasKey( Meta::BEEHIIV_GROUP_SECTIONS, $registered );
		$this->assertTrue( $registered[ Meta::BEEHIIV_GROUP_SECTIONS ]['show_in_rest']['schema']['readonly'] );
		$this->assertSame( 'core/group', SupportedBlocks::get_editor_config()['sectionBlock'] );
	}
}
