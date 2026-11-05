<?php
/**
 * Unit coverage for converting WordPress Columns blocks to beehiiv columns blocks.
 *
 * PRD-07.2.01 US-007 (AC-019 to AC-027, BR-002, BR-009, BR-010), PRD-07.2.02 AC-008,
 * and PRD-06.4.01 BR-005 (the editor reads the same supported block list).
 *
 * @package beehiiv
 */

use Beehiiv\Editor\Meta;
use Beehiiv\Newsletter\BlockConverter;
use Beehiiv\Newsletter\SupportedBlocks;

/**
 * Columns block conversion through the top-level block walk.
 *
 * @covers \Beehiiv\Newsletter\BlockConverter::convert_supported_blocks
 * @covers \Beehiiv\Newsletter\BlockConverter::convert_columns_block
 * @covers \Beehiiv\Newsletter\BlockConverter::convert_media_text_block
 * @covers \Beehiiv\Newsletter\SupportedBlocks::is_supported
 * @covers \Beehiiv\Newsletter\SupportedBlocks::get_editor_config
 */
class NewsletterColumnsBlockConversionTest extends WP_UnitTestCase {

	/**
	 * Convert block markup the way a post sync does.
	 *
	 * @param string $content Block markup.
	 * @param int    $post_id Post ID; 0 for a post with no stored meta.
	 * @return array<int, array<string, mixed>>
	 */
	private function convert( string $content, int $post_id = 0 ): array {
		$post = new WP_Post(
			(object) array(
				'ID'           => $post_id,
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_content' => $content,
				'filter'       => 'raw',
			)
		);

		return BlockConverter::convert_supported_blocks( $post );
	}

	/**
	 * Columns block markup.
	 *
	 * @param string               $columns Inner core/column markup.
	 * @param array<string, mixed> $attrs   Columns block attrs.
	 * @return string
	 */
	private function columns( string $columns, array $attrs = array() ): string {
		$json = empty( $attrs ) ? '' : ' ' . wp_json_encode( $attrs );

		return "<!-- wp:columns{$json} --><div class=\"wp-block-columns\">{$columns}</div><!-- /wp:columns -->";
	}

	/**
	 * Column block markup.
	 *
	 * @param string               $inner Inner block markup.
	 * @param array<string, mixed> $attrs Column block attrs.
	 * @return string
	 */
	private function column( string $inner, array $attrs = array() ): string {
		$json  = empty( $attrs ) ? '' : ' ' . wp_json_encode( $attrs );
		$style = isset( $attrs['width'] ) ? ' style="flex-basis:' . $attrs['width'] . '"' : '';

		return "<!-- wp:column{$json} --><div class=\"wp-block-column\"{$style}>{$inner}</div><!-- /wp:column -->";
	}

	/**
	 * Paragraph block markup.
	 *
	 * @param string $text Paragraph text.
	 * @return string
	 */
	private function paragraph( string $text ): string {
		return "<!-- wp:paragraph --><p>{$text}</p><!-- /wp:paragraph -->";
	}

	/**
	 * Heading block markup.
	 *
	 * @param string $text Heading text.
	 * @return string
	 */
	private function heading( string $text ): string {
		return "<!-- wp:heading --><h2 class=\"wp-block-heading\">{$text}</h2><!-- /wp:heading -->";
	}

	/**
	 * Image block markup.
	 *
	 * @param string $url Image URL.
	 * @return string
	 */
	private function image( string $url ): string {
		return '<!-- wp:image --><figure class="wp-block-image">'
			. "<img src=\"{$url}\" alt=\"\"/>"
			. '</figure><!-- /wp:image -->';
	}

	/**
	 * Buttons block markup with one button.
	 *
	 * @param string $text Button text.
	 * @param string $href Button link.
	 * @return string
	 */
	private function buttons( string $text, string $href ): string {
		return '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button">'
			. "<a class=\"wp-block-button__link wp-element-button\" href=\"{$href}\">{$text}</a>"
			. '</div><!-- /wp:button --></div><!-- /wp:buttons -->';
	}

	/**
	 * Every block type found anywhere inside the given column objects.
	 *
	 * @param array<int, array<string, mixed>> $columns beehiiv column objects.
	 * @return array<int, string>
	 */
	private function column_block_types( array $columns ): array {
		$types = array();

		foreach ( $columns as $column ) {
			foreach ( $column['blocks'] as $block ) {
				$types[] = $block['type'];
			}
		}

		return $types;
	}

	/**
	 * AC-026, AC-019, AC-020: a 3-column post gives one columns block with three
	 * column objects holding an image, a heading and paragraph, and a button.
	 */
	public function test_three_column_example_produces_one_columns_block_with_three_columns(): void {
		$blocks = $this->convert(
			$this->columns(
				$this->column( $this->image( 'https://example.com/photo.jpg' ) )
				. $this->column( $this->heading( 'Column heading' ) . $this->paragraph( 'Column text.' ) )
				. $this->column( $this->buttons( 'Read on', 'https://example.com/more' ) )
			)
		);

		$this->assertCount( 1, $blocks );
		$this->assertSame( 'columns', $blocks[0]['type'] );
		$this->assertTrue( $blocks[0]['stackOnMobile'] );

		$columns = $blocks[0]['columns'];
		$this->assertCount( 3, $columns );

		$this->assertCount( 1, $columns[0]['blocks'] );
		$this->assertSame( 'image', $columns[0]['blocks'][0]['type'] );
		$this->assertSame( 'https://example.com/photo.jpg', $columns[0]['blocks'][0]['imageUrl'] );

		$this->assertCount( 2, $columns[1]['blocks'] );
		$this->assertSame( 'heading', $columns[1]['blocks'][0]['type'] );
		$this->assertSame( 'Column heading', $columns[1]['blocks'][0]['text'] );
		$this->assertSame( 'paragraph', $columns[1]['blocks'][1]['type'] );
		$this->assertSame( 'Column text.', $columns[1]['blocks'][1]['plaintext'] );

		$this->assertCount( 1, $columns[2]['blocks'] );
		$this->assertSame( 'button', $columns[2]['blocks'][0]['type'] );
		$this->assertSame( 'https://example.com/more', $columns[2]['blocks'][0]['href'] );
		$this->assertSame( 'Read on', $columns[2]['blocks'][0]['text'] );
	}

	/**
	 * AC-019: any number of columns converts, in the original order, and the
	 * Columns block keeps its place among the other top-level blocks.
	 */
	public function test_four_columns_convert_in_order(): void {
		$blocks = $this->convert(
			$this->paragraph( 'Before' )
			. $this->columns(
				$this->column( $this->paragraph( 'One' ) )
				. $this->column( $this->paragraph( 'Two' ) )
				. $this->column( $this->paragraph( 'Three' ) )
				. $this->column( $this->paragraph( 'Four' ) )
			)
			. $this->paragraph( 'After' )
		);

		$this->assertSame( array( 'paragraph', 'columns', 'paragraph' ), wp_list_pluck( $blocks, 'type' ) );
		$this->assertSame( 'Before', $blocks[0]['plaintext'] );
		$this->assertSame( 'After', $blocks[2]['plaintext'] );

		$texts = array();
		foreach ( $blocks[1]['columns'] as $column ) {
			$this->assertCount( 1, $column['blocks'] );
			$texts[] = $column['blocks'][0]['plaintext'];
		}

		$this->assertSame( array( 'One', 'Two', 'Three', 'Four' ), $texts );
	}

	/**
	 * AC-021: explicit widths round to an integer; no explicit width means no key.
	 */
	public function test_explicit_width_becomes_integer_and_unset_width_has_no_key(): void {
		$blocks = $this->convert(
			$this->columns(
				$this->column( $this->paragraph( 'Narrow' ), array( 'width' => '33.33%' ) )
				. $this->column( $this->paragraph( 'Wide' ), array( 'width' => '66.67%' ) )
				. $this->column( $this->paragraph( 'Auto' ) )
			)
		);

		$columns = $blocks[0]['columns'];

		$this->assertSame( 33, $columns[0]['width'] );
		$this->assertSame( 67, $columns[1]['width'] );
		$this->assertArrayNotHasKey( 'width', $columns[2] );
	}

	/**
	 * AC-022: column alignment maps to verticalAlign and falls back to the
	 * Columns block's alignment.
	 */
	public function test_vertical_alignment_maps_and_falls_back_to_parent(): void {
		$blocks = $this->convert(
			$this->columns(
				$this->column( $this->paragraph( 'Top' ), array( 'verticalAlignment' => 'top' ) )
				. $this->column( $this->paragraph( 'Center' ), array( 'verticalAlignment' => 'center' ) )
				. $this->column( $this->paragraph( 'Inherited' ) ),
				array( 'verticalAlignment' => 'bottom' )
			)
		);

		$columns = $blocks[0]['columns'];

		$this->assertSame( 'top', $columns[0]['verticalAlign'] );
		$this->assertSame( 'middle', $columns[1]['verticalAlign'] );
		$this->assertSame( 'bottom', $columns[2]['verticalAlign'] );
	}

	/**
	 * AC-022: with no column or Columns alignment, there is no verticalAlign key.
	 */
	public function test_no_alignment_means_no_vertical_align_key(): void {
		$blocks = $this->convert(
			$this->columns( $this->column( $this->paragraph( 'Plain' ) ) )
		);

		$this->assertArrayNotHasKey( 'verticalAlign', $blocks[0]['columns'][0] );
	}

	/**
	 * AC-023: the default "Stack on mobile" setting gives stackOnMobile true.
	 */
	public function test_default_stack_on_mobile_is_true(): void {
		$blocks = $this->convert(
			$this->columns(
				$this->column( $this->paragraph( 'A' ) ) . $this->column( $this->paragraph( 'B' ) )
			)
		);

		$this->assertArrayHasKey( 'stackOnMobile', $blocks[0] );
		$this->assertTrue( $blocks[0]['stackOnMobile'] );
	}

	/**
	 * AC-020, BR-009: lists, tables, quotes, and embeds stay in the column, and an
	 * unsupported block is left out while the rest of the column converts.
	 */
	public function test_column_keeps_media_text_inner_block_handling(): void {
		$list  = '<!-- wp:list --><ul class="wp-block-list">'
			. '<!-- wp:list-item --><li>Item</li><!-- /wp:list-item -->'
			. '</ul><!-- /wp:list -->';
		$table = '<!-- wp:table --><figure class="wp-block-table">'
			. '<table><tbody><tr><td>Cell</td></tr></tbody></table>'
			. '</figure><!-- /wp:table -->';
		$quote = '<!-- wp:quote --><blockquote class="wp-block-quote">'
			. $this->paragraph( 'Quoted' )
			. '</blockquote><!-- /wp:quote -->';
		$embed = '<!-- wp:embed {"url":"https://www.youtube.com/watch?v=abc"} --><figure class="wp-block-embed">'
			. '<div class="wp-block-embed__wrapper">https://www.youtube.com/watch?v=abc</div>'
			. '</figure><!-- /wp:embed -->';
		$code  = '<!-- wp:code --><pre class="wp-block-code"><code>echo 1;</code></pre><!-- /wp:code -->';

		$blocks = $this->convert(
			$this->columns(
				$this->column( $list . $table . $code )
				. $this->column( $quote . $embed )
			)
		);

		$this->assertCount( 1, $blocks );

		$columns = $blocks[0]['columns'];

		$this->assertSame( array( 'list', 'table' ), wp_list_pluck( $columns[0]['blocks'], 'type' ) );
		$this->assertSame( array( 'quote', 'embed_link' ), wp_list_pluck( $columns[1]['blocks'], 'type' ) );
	}

	/**
	 * A column with nothing convertible is left out; with no column left, no
	 * columns block is sent.
	 */
	public function test_empty_columns_are_left_out(): void {
		$code = '<!-- wp:code --><pre class="wp-block-code"><code>x</code></pre><!-- /wp:code -->';

		$blocks = $this->convert(
			$this->columns(
				$this->column( $code, array( 'width' => '25%' ) )
				. $this->column( $this->paragraph( 'Kept' ), array( 'width' => '75%' ) )
			)
		);

		$this->assertCount( 1, $blocks[0]['columns'] );
		$this->assertSame( 75, $blocks[0]['columns'][0]['width'] );

		$this->assertSame( array(), $this->convert( $this->columns( $this->column( $code ) ) ) );
	}

	/**
	 * AC-024, BR-010: a Columns block inside a column is not kept in the column.
	 * The blocks inside its columns go to the top level, in their original order.
	 */
	public function test_nested_columns_content_goes_to_top_level(): void {
		$nested = $this->columns(
			$this->column( $this->paragraph( 'Inner one' ) )
			. $this->column( $this->heading( 'Inner two' ) . $this->paragraph( 'Inner three' ) )
		);

		$blocks = $this->convert(
			$this->columns(
				$this->column( $this->paragraph( 'Outer A' ) . $nested )
				. $this->column( $this->paragraph( 'Outer B' ) )
			)
		);

		$columns_blocks = wp_list_filter( $blocks, array( 'type' => 'columns' ) );
		$this->assertCount( 1, $columns_blocks );

		$parent = reset( $columns_blocks );
		$this->assertCount( 2, $parent['columns'] );
		$this->assertNotContains( 'columns', $this->column_block_types( $parent['columns'] ) );
		$this->assertSame( 'Outer A', $parent['columns'][0]['blocks'][0]['plaintext'] );
		$this->assertCount( 1, $parent['columns'][0]['blocks'] );
		$this->assertSame( 'Outer B', $parent['columns'][1]['blocks'][0]['plaintext'] );

		$top_level = array_values( wp_list_filter( $blocks, array( 'type' => 'columns' ), 'NOT' ) );
		$this->assertSame( array( 'paragraph', 'heading', 'paragraph' ), wp_list_pluck( $top_level, 'type' ) );
		$this->assertSame( 'Inner one', $top_level[0]['plaintext'] );
		$this->assertSame( 'Inner two', $top_level[1]['text'] );
		$this->assertSame( 'Inner three', $top_level[2]['plaintext'] );
	}

	/**
	 * AC-024: top-level rules apply to the moved blocks, so unsupported blocks
	 * from a nested Columns block are left out.
	 */
	public function test_nested_columns_content_follows_top_level_rules(): void {
		$code   = '<!-- wp:code --><pre class="wp-block-code"><code>x</code></pre><!-- /wp:code -->';
		$nested = $this->columns( $this->column( $code . $this->paragraph( 'Moved' ) ) );

		$blocks = $this->convert(
			$this->columns( $this->column( $nested ) )
		);

		$this->assertCount( 1, $blocks );
		$this->assertSame( 'paragraph', $blocks[0]['type'] );
		$this->assertSame( 'Moved', $blocks[0]['plaintext'] );
	}

	/**
	 * BR-010: a Columns block inside the content column of a Media & Text block
	 * is not kept in that column either.
	 */
	public function test_nested_columns_in_media_text_goes_to_top_level(): void {
		$nested = $this->columns( $this->column( $this->paragraph( 'From nested' ) ) );

		$media_text = '<!-- wp:media-text {"mediaType":"image"} --><div class="wp-block-media-text">'
			. '<figure class="wp-block-media-text__media"><img src="https://example.com/m.jpg" alt=""/></figure>'
			. '<div class="wp-block-media-text__content">' . $this->paragraph( 'Content' ) . $nested . '</div>'
			. '</div><!-- /wp:media-text -->';

		$blocks = $this->convert( $media_text );

		$this->assertSame( array( 'columns', 'paragraph' ), wp_list_pluck( $blocks, 'type' ) );
		$this->assertNotContains( 'columns', $this->column_block_types( $blocks[0]['columns'] ) );
		$this->assertSame( 'From nested', $blocks[1]['plaintext'] );
	}

	/**
	 * In snippet mode, a core/more block inside a column is left out and does not
	 * end the conversion.
	 */
	public function test_more_block_in_column_does_not_end_snippet_conversion(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Meta::SEND_TO_NEWSLETTER_SNIPPET, true );

		$blocks = $this->convert(
			$this->columns(
				$this->column( $this->paragraph( 'Before more' ) . '<!-- wp:more --><!--more--><!-- /wp:more -->' )
			)
			. $this->paragraph( 'After columns' ),
			$post_id
		);

		$this->assertSame( array( 'columns', 'paragraph' ), wp_list_pluck( $blocks, 'type' ) );
		$this->assertSame( array( 'paragraph' ), $this->column_block_types( $blocks[0]['columns'] ) );
		$this->assertSame( 'After columns', $blocks[1]['plaintext'] );
	}

	/**
	 * AC-027: Columns blocks convert on every sync, but a Columns block inside a
	 * Group that is left out goes with its Group.
	 */
	public function test_columns_inside_left_out_group_stays_left_out(): void {
		$group = '<!-- wp:group --><div class="wp-block-group">'
			. $this->columns( $this->column( $this->paragraph( 'In group' ) ) )
			. '</div><!-- /wp:group -->';

		$this->assertSame( array(), $this->convert( $group ) );
	}

	/**
	 * AC-025, BR-002, PRD-06.4.01 BR-005: core/columns is supported, core/column is
	 * handled by its Columns block, and the editor config carries both.
	 */
	public function test_columns_are_on_the_supported_block_list(): void {
		$this->assertTrue( SupportedBlocks::is_supported( 'core/columns', false ) );
		$this->assertTrue( SupportedBlocks::is_supported( 'core/columns', true ) );
		$this->assertTrue( SupportedBlocks::is_supported( 'core/column', false ) );
		$this->assertFalse( SupportedBlocks::is_supported( 'core/group', false ) );

		$config = SupportedBlocks::get_editor_config();

		$this->assertContains( 'core/columns', $config['supported'] );
		$this->assertContains( 'core/column', $config['nestedOnly'] );
		$this->assertNotContains( 'core/column', $config['supported'] );
	}

	/**
	 * BR-002: a stray core/column outside its Columns block converts to nothing.
	 */
	public function test_column_outside_columns_block_converts_to_nothing(): void {
		$this->assertSame( array(), $this->convert( $this->column( $this->paragraph( 'Orphan' ) ) ) );
	}
}
