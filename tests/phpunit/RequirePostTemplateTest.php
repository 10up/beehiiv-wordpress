<?php
/**
 * Unit coverage for the setting that requires editors to choose a post template.
 *
 * PRD-07.3.01 AC-009, AC-012 to AC-018, BR-005 to BR-007. PRD-08.1.02 AC-014 to
 * AC-017. PRD-08.3.02 AC-005, AC-007, AC-008. PRD-08.3.01 AC-006. beehiiv HTTP
 * is mocked via `pre_http_request`.
 *
 * @package beehiiv
 */

use Beehiiv\Admin\Options;
use Beehiiv\Admin\Registrar;
use Beehiiv\API\Cache;
use Beehiiv\Config;
use Beehiiv\Editor\Meta;
use Beehiiv\Editor\PostSettings;
use Beehiiv\Newsletter\Sender;
use Beehiiv\OAuth\TokenStore;

/**
 * @covers \Beehiiv\Admin\Options
 * @covers \Beehiiv\Admin\Registrar::render_require_post_template_field
 * @covers \Beehiiv\Newsletter\PostSettingsBuilder::get_post_settings
 * @covers \Beehiiv\Newsletter\PostSettingsBuilder::resolve_post_template_id
 * @covers \Beehiiv\Newsletter\Sender::format_save_error_message
 */
class RequirePostTemplateTest extends WP_UnitTestCase {

	/**
	 * HTTP requests made during the test.
	 *
	 * @var array<int, array{method: string, url: string, body: array}>
	 */
	private $requests = array();

	public function set_up(): void {
		parent::set_up();

		$this->requests = array();

		TokenStore::save_tokens(
			'client_test',
			array(
				'access_token'  => 'access_test',
				'refresh_token' => 'refresh_test',
				'expires_in'    => DAY_IN_SECONDS,
			)
		);

		$this->save_settings( true );

		Cache::set_publications(
			array(
				array(
					'id'   => 'pub_default',
					'name' => 'Default Weekly',
				),
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
	 * Save plugin settings with a default publication and template.
	 *
	 * @param bool $require_post_template Whether editors must choose a template.
	 * @return void
	 */
	private function save_settings( bool $require_post_template ): void {
		update_option(
			Config::OPTION_NAME,
			array(
				'publication_id'        => 'pub_default',
				'post_template_id'      => 'tpl_default',
				'require_post_template' => $require_post_template ? '1' : '',
			)
		);
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

		$data = 'POST' === $method ? array( 'id' => 'post_new' ) : array( 'id' => 'post_linked' );

		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( array( 'data' => $data ) ),
			'response' => array(
				'code'    => 200,
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
	 * Create a published post queued for the newsletter.
	 *
	 * @param array<string, string> $meta Meta to set.
	 * @return int Post ID.
	 */
	private function create_post( array $meta = array() ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Weekly roundup',
				'post_content' => "<!-- wp:paragraph -->\n<p>Hello subscribers.</p>\n<!-- /wp:paragraph -->",
			)
		);

		update_post_meta( $post_id, Meta::SEND_TO_NEWSLETTER, true );

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		$this->requests = array();

		return $post_id;
	}

	/**
	 * Create a post linked to a scheduled, unsent beehiiv newsletter.
	 *
	 * @param string $linked_template Template the beehiiv post was created with, or empty for none recorded.
	 * @return int Post ID.
	 */
	private function create_scheduled_linked_post( string $linked_template ): int {
		$send_ts = time() + DAY_IN_SECONDS;
		$post_id = $this->create_post();

		update_post_meta( $post_id, Meta::SEND_TO_NEWSLETTER_DATE, get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $send_ts ), 'Y-m-d\TH:i:s' ) );
		update_post_meta( $post_id, Meta::BEEHIIV_SCHEDULED_AT, gmdate( 'Y-m-d\TH:i:s\Z', $send_ts ) );
		update_post_meta( $post_id, Meta::BEEHIIV_LINKED_PUBLICATION_ID, 'pub_default' );

		if ( '' !== $linked_template ) {
			update_post_meta( $post_id, Meta::BEEHIIV_LINKED_POST_TEMPLATE_ID, $linked_template );
		}

		update_post_meta( $post_id, Meta::BEEHIIV_POST_ID, 'post_linked' );
		update_post_meta( $post_id, Meta::SEND_TO_NEWSLETTER, false );

		$this->requests = array();

		return $post_id;
	}

	/**
	 * Editor config passed to the sidebar script.
	 *
	 * @return array<string, mixed>
	 */
	private function editor_config(): array {
		$method = new ReflectionMethod( PostSettings::class, 'get_editor_config' );
		$method->setAccessible( true );

		return $method->invoke( null );
	}

	/**
	 * PRD-08.3.02 AC-005, AC-008, BR-002: never saved, the setting is off and the other values are empty.
	 */
	public function test_setting_is_off_when_never_saved(): void {
		delete_option( Config::OPTION_NAME );

		$settings = Options::get();

		$this->assertFalse( $settings['require_post_template'] );
		$this->assertSame( '', $settings['publication_id'] );
		$this->assertSame( '', $settings['post_template_id'] );
	}

	/**
	 * PRD-08.3.02 AC-008: settings saved before version 1.1 read the setting as off.
	 */
	public function test_setting_is_off_for_settings_saved_before_it_existed(): void {
		update_option(
			Config::OPTION_NAME,
			array(
				'publication_id'   => 'pub_default',
				'post_template_id' => 'tpl_default',
			)
		);

		$settings = Options::get();

		$this->assertFalse( $settings['require_post_template'] );
		$this->assertSame( 'tpl_default', $settings['post_template_id'] );
	}

	/**
	 * PRD-08.3.02 AC-008: an invalid stored value reads as off.
	 */
	public function test_invalid_stored_value_reads_as_off(): void {
		update_option(
			Config::OPTION_NAME,
			array(
				'publication_id'        => 'pub_default',
				'post_template_id'      => 'tpl_default',
				'require_post_template' => 'yes',
			)
		);

		$this->assertFalse( Options::get()['require_post_template'] );
		$this->assertFalse( Options::is_post_template_required() );
	}

	/**
	 * PRD-08.3.02 AC-007, PRD-08.1.02 AC-016: the saved setting is kept as true with the other settings.
	 */
	public function test_saved_setting_is_retained_as_true(): void {
		$settings = Options::get();

		$this->assertTrue( $settings['require_post_template'] );
		$this->assertSame( 'pub_default', $settings['publication_id'] );
		$this->assertSame( 'tpl_default', $settings['post_template_id'] );
	}

	/**
	 * PRD-08.3.01 AC-006, BR-001: only a checked value saves as on.
	 */
	public function test_sanitize_saves_on_only_when_checked(): void {
		$base = array(
			'publication_id'   => '',
			'post_template_id' => '',
		);

		$this->assertTrue( Options::sanitize( $base + array( 'require_post_template' => '1' ) )['require_post_template'] );
		$this->assertFalse( Options::sanitize( $base )['require_post_template'] );
		$this->assertFalse( Options::sanitize( $base + array( 'require_post_template' => '' ) )['require_post_template'] );
		$this->assertFalse( Options::sanitize( $base + array( 'require_post_template' => 'on' ) )['require_post_template'] );
		$this->assertFalse( Options::sanitize( $base + array( 'require_post_template' => array( '1' ) ) )['require_post_template'] );
	}

	/**
	 * PRD-08.1.02 AC-014, AC-015, AC-016: the settings form shows the toggle with its saved state and description.
	 */
	public function test_settings_field_renders_toggle_with_saved_state(): void {
		ob_start();
		Registrar::render_require_post_template_field();
		$checked = ob_get_clean();

		$this->assertStringContainsString( 'type="checkbox"', $checked );
		$this->assertStringContainsString( 'name="' . Config::OPTION_NAME . '[require_post_template]"', $checked );
		$this->assertStringContainsString( "checked='checked'", $checked );
		$this->assertStringContainsString( 'not sent to beehiiv', $checked );
		$this->assertStringContainsString( 'default post template is not used', $checked );

		$this->save_settings( false );

		ob_start();
		Registrar::render_require_post_template_field();
		$unchecked = ob_get_clean();

		$this->assertStringNotContainsString( "checked='checked'", $unchecked );
	}

	/**
	 * PRD-08.1.02 AC-017: turning the toggle on keeps the saved default template.
	 */
	public function test_default_template_is_kept_while_setting_is_on(): void {
		$this->assertSame( 'tpl_default', Options::get()['post_template_id'] );

		$this->save_settings( false );
		$post_id = $this->create_post();

		Sender::send( $post_id );

		$creates = $this->requests_with( 'POST' );
		$this->assertCount( 1, $creates );
		$this->assertSame( 'tpl_default', $creates[0]['body']['post_template_id'] );
	}

	/**
	 * PRD-07.3.01 AC-013: with the setting on, the editor gets no default template to preselect or label.
	 */
	public function test_editor_config_hides_default_template_when_setting_is_on(): void {
		$config = $this->editor_config();

		$this->assertTrue( $config['requirePostTemplate'] );
		$this->assertSame( '', $config['defaultPostTemplateId'] );
	}

	/**
	 * PRD-07.3.01 AC-012: with the setting off, the editor gets the default template as before.
	 */
	public function test_editor_config_keeps_default_template_when_setting_is_off(): void {
		$this->save_settings( false );

		$config = $this->editor_config();

		$this->assertFalse( $config['requirePostTemplate'] );
		$this->assertSame( 'tpl_default', $config['defaultPostTemplateId'] );
	}

	/**
	 * PRD-07.3.01 AC-009, AC-012: with the setting off, a post with no template uses the default.
	 */
	public function test_setting_off_uses_default_template(): void {
		$this->save_settings( false );
		$post_id = $this->create_post();

		Sender::send( $post_id );

		$creates = $this->requests_with( 'POST' );
		$this->assertCount( 1, $creates );
		$this->assertSame( 'tpl_default', $creates[0]['body']['post_template_id'] );
	}

	/**
	 * PRD-07.3.01 AC-014, AC-015, AC-016, BR-006: no chosen template means no send, a clear message, and the post stays published.
	 */
	public function test_setting_on_without_chosen_template_is_not_sent(): void {
		$post_id = $this->create_post();

		Sender::maybe_send_post_newsletter( get_post( $post_id ) );

		$message = get_post_meta( $post_id, Meta::NEWSLETTER_ERROR, true );

		$this->assertCount( 0, $this->requests_with( 'POST' ) );
		$this->assertSame( '', get_post_meta( $post_id, Meta::BEEHIIV_POST_ID, true ) );
		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$this->assertStringContainsString( 'not sent to beehiiv because no post template was chosen', $message );
		$this->assertStringContainsString( 'Choose a post template', $message );
		$this->assertStringNotContainsString( 'default', $message );
		$this->assertStringNotContainsString( 'settings', $message );
		$this->assertTrue( (bool) get_post_meta( $post_id, Meta::SEND_TO_NEWSLETTER, true ) );
	}

	/**
	 * PRD-07.3.01 AC-014: with the setting on, the chosen template is sent.
	 */
	public function test_setting_on_sends_chosen_template(): void {
		$post_id = $this->create_post( array( Meta::BEEHIIV_POST_TEMPLATE_ID => 'tpl_alt' ) );

		Sender::send( $post_id );

		$creates = $this->requests_with( 'POST' );
		$this->assertCount( 1, $creates );
		$this->assertSame( 'tpl_alt', $creates[0]['body']['post_template_id'] );
	}

	/**
	 * PRD-07.3.01 BR-007: a chosen template that no longer exists does not fall back to the default.
	 */
	public function test_setting_on_with_deleted_template_is_not_sent(): void {
		$post_id = $this->create_post( array( Meta::BEEHIIV_POST_TEMPLATE_ID => 'tpl_removed' ) );

		Sender::send( $post_id );

		$this->assertCount( 0, $this->requests_with( 'POST' ) );
		$this->assertStringContainsString(
			'no post template was chosen',
			get_post_meta( $post_id, Meta::NEWSLETTER_ERROR, true )
		);
	}

	/**
	 * PRD-07.3.01 AC-017: choosing a template and updating the post sends it and clears the message.
	 */
	public function test_choosing_template_and_updating_retries_send(): void {
		$post_id = $this->create_post();
		Sender::maybe_send_post_newsletter( get_post( $post_id ) );
		$this->assertNotSame( '', get_post_meta( $post_id, Meta::NEWSLETTER_ERROR, true ) );

		update_post_meta( $post_id, Meta::BEEHIIV_POST_TEMPLATE_ID, 'tpl_alt' );
		Sender::maybe_send_post_newsletter( get_post( $post_id ) );

		$creates = $this->requests_with( 'POST' );
		$this->assertCount( 1, $creates );
		$this->assertSame( 'tpl_alt', $creates[0]['body']['post_template_id'] );
		$this->assertSame( 'post_new', get_post_meta( $post_id, Meta::BEEHIIV_POST_ID, true ) );
		$this->assertSame( '', get_post_meta( $post_id, Meta::NEWSLETTER_ERROR, true ) );
	}

	/**
	 * PRD-07.3.01 AC-018: a linked post with no template of its own keeps syncing with its recorded template.
	 */
	public function test_linked_post_without_chosen_template_keeps_syncing(): void {
		$post_id = $this->create_scheduled_linked_post( 'tpl_default' );

		Sender::update( $post_id );

		$this->assertCount( 0, $this->requests_with( 'DELETE' ) );
		$this->assertCount( 1, $this->requests_with( 'PATCH' ) );
		$this->assertSame( '', get_post_meta( $post_id, Meta::NEWSLETTER_ERROR, true ) );
	}

	/**
	 * PRD-07.3.01 AC-018: a linked post with no recorded template keeps syncing as before.
	 */
	public function test_legacy_linked_post_keeps_syncing(): void {
		$post_id = $this->create_scheduled_linked_post( '' );

		Sender::update( $post_id );

		$this->assertCount( 1, $this->requests_with( 'PATCH' ) );
		$this->assertSame( '', get_post_meta( $post_id, Meta::NEWSLETTER_ERROR, true ) );
	}
}
