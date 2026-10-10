<?php
/**
 * File to test saving a source and its provider configuration.
 *
 * The most damaging place in the plugin: a mistake here does not show a
 * wrong text somewhere, it wipes the configuration of every source.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Tests\Unit;

use NowPagesThreadi\Providers\Providers;
use NowPagesThreadi\Sources\Sources;
use NowPagesThreadi\Tests\NowPagesTestCase;
use NowPagesThreadi\Tests\Unit\Stubs\Stub_Identified_Provider;
use NowPagesThreadi\Tests\Unit\Stubs\Stub_Provider;

/**
 * Object to test Sources::save_post().
 */
class SaveSourceTest extends NowPagesTestCase {
	/**
	 * The source being saved.
	 *
	 * @var int
	 */
	private int $source_id = 0;

	/**
	 * Register the stub provider and create a source.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Stub_Provider::reset();

		update_option( 'now_pages_threadi_provider_stub_enabled', 1 );

		Providers::get_instance()->register_provider( new Stub_Provider() );

		$this->source_id = self::factory()->post->create(
			array(
				'post_type'   => 'np_threadi_source',
				'post_status' => 'publish',
				'post_title'  => 'Test source',
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Clean up the request between tests.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$_POST = array();

		parent::tear_down();
	}

	/**
	 * Simulate a submitted metabox and trigger the save.
	 *
	 * @param array<string,mixed> $fields  The submitted fields.
	 * @param bool                $with_marker Whether our metabox marker is sent.
	 * @param bool                $with_provider_fields Whether the fields of the provider were part of the form.
	 *
	 * @return void
	 */
	private function save( array $fields, bool $with_marker = true, bool $with_provider_fields = true ): void {
		$_POST = $fields;

		if ( $with_marker ) {
			$_POST['now_pages_threadi_source_form'] = '1';
		}

		if ( $with_provider_fields && isset( $fields['now-pages-threadi-provider'] ) ) {
			$_POST['now-pages-threadi-provider-fields'] = $fields['now-pages-threadi-provider'];
		}

		$post = get_post( $this->source_id );

		Sources::get_instance()->save_post( $this->source_id, $post, true );
	}

	/**
	 * A submitted metabox must store the provider and its fields.
	 *
	 * @return void
	 */
	public function test_submitted_metabox_stores_the_configuration(): void {
		$this->save(
			array(
				'now-pages-threadi-provider' => 'stub',
				'stub_url'           => 'https://example.com/feed',
				'stub_limit'         => '7',
			)
		);

		$this->assertSame( 'stub', get_post_meta( $this->source_id, 'provider', true ) );
		$this->assertSame( 'https://example.com/feed', get_post_meta( $this->source_id, 'stub_url', true ) );
	}

	/**
	 * Choosing a provider must store the defaults of its fields.
	 *
	 * The form is sent as soon as a provider is chosen, before its fields
	 * are shown. Their missing values must not be stored as empty.
	 *
	 * @return void
	 */
	public function test_choosing_a_provider_stores_the_defaults_of_its_fields(): void {
		$this->save(
			array(
				'now-pages-threadi-provider' => 'stub',
			),
			true,
			false
		);

		$this->assertSame( 'stub', get_post_meta( $this->source_id, 'provider', true ) );
		$this->assertSame( '5', get_post_meta( $this->source_id, 'stub_limit', true ) );
		$this->assertFalse( metadata_exists( 'post', $this->source_id, 'stub_url' ) );
	}

	/**
	 * A form without the fields of the provider must not change them.
	 *
	 * @return void
	 */
	public function test_a_form_without_the_provider_fields_keeps_them(): void {
		$this->save(
			array(
				'now-pages-threadi-provider' => 'stub',
				'stub_url'                   => 'https://example.com/feed',
				'stub_limit'                 => '7',
			)
		);

		$this->save(
			array(
				'now-pages-threadi-provider' => 'stub',
			),
			true,
			false
		);

		$this->assertSame( 'https://example.com/feed', get_post_meta( $this->source_id, 'stub_url', true ) );
		$this->assertSame( '7', get_post_meta( $this->source_id, 'stub_limit', true ) );
	}

	/**
	 * A disabled provider must not be selectable for a new source.
	 *
	 * The provider can still remain configured on an existing source; the
	 * global disable is an execution/selection restriction, not deletion of
	 * the source configuration.
	 *
	 * @return void
	 */
	public function test_disabled_provider_cannot_be_selected_for_a_source(): void {
		update_option( 'now_pages_threadi_provider_stub_enabled', 0 );

		$this->save(
			array(
				'now-pages-threadi-provider' => 'stub',
				'stub_url'           => 'https://example.com/feed',
			)
		);

		$this->assertNotSame( 'stub', get_post_meta( $this->source_id, 'provider', true ) );
	}

	/**
	 * A save without our metabox must not touch anything.
	 *
	 * This is the case that happens on every quick edit, every REST save and
	 * on the second request of the block editor.
	 *
	 * @return void
	 */
	public function test_save_without_the_metabox_keeps_everything(): void {
		$this->save(
			array(
				'now-pages-threadi-provider' => 'stub',
				'stub_url'           => 'https://example.com/feed',
			)
		);

		// now save again, as WordPress does without our form.
		$this->save( array(), false );

		$this->assertSame( 'stub', get_post_meta( $this->source_id, 'provider', true ) );
		$this->assertSame( 'https://example.com/feed', get_post_meta( $this->source_id, 'stub_url', true ) );
	}

	/**
	 * A user without the capability must not be able to change anything.
	 *
	 * @return void
	 */
	public function test_user_without_capability_changes_nothing(): void {
		$this->save(
			array(
				'now-pages-threadi-provider' => 'stub',
				'stub_url'           => 'https://example.com/feed',
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->save(
			array(
				'now-pages-threadi-provider' => 'stub',
				'stub_url'           => 'https://evil.example.com/feed',
			)
		);

		$this->assertSame( 'https://example.com/feed', get_post_meta( $this->source_id, 'stub_url', true ) );
	}

	/**
	 * An ampersand in a URL must survive.
	 *
	 * The old sanitization turned it into &amp;, which broke every feed URL
	 * carrying parameters.
	 *
	 * @return void
	 */
	public function test_ampersand_in_a_url_survives(): void {
		$this->save(
			array(
				'now-pages-threadi-provider' => 'stub',
				'stub_url'           => 'https://example.com/feed?a=1&b=2',
			)
		);

		$this->assertStringNotContainsString( '&amp;', get_post_meta( $this->source_id, 'stub_url', true ) );
	}

	/**
	 * An override text that is only markup must be stored as empty.
	 *
	 * Otherwise it would replace a whole group with an empty paragraph.
	 *
	 * @return void
	 */
	public function test_override_of_only_markup_is_stored_as_empty(): void {
		$this->save(
			array(
				'now-pages-threadi-provider'      => 'stub',
				'now-pages-threadi-override-text' => '<p>   </p>',
			)
		);

		$this->assertSame( '', get_post_meta( $this->source_id, 'override_text', true ) );
	}

	/**
	 * The same feed with other content filter rules must not be reported as duplicate.
	 *
	 * One feed can carry several things, e.g. forum replies and translations.
	 * Two sources which each keep another part of it do not import the same entries.
	 *
	 * @return void
	 */
	public function test_the_same_feed_with_other_rules_is_not_a_duplicate(): void {
		Providers::get_instance()->register_provider( new Stub_Identified_Provider() );

		// another source which reads the same account and keeps the forum replies.
		$other = self::factory()->post->create(
			array(
				'post_type'   => 'np_threadi_source',
				'post_status' => 'publish',
				'post_title'  => 'Forum replies',
			)
		);
		update_post_meta( $other, 'provider', 'stub_identified' );
		update_post_meta( $other, 'stub_account', 'tz' );
		update_post_meta( $other, 'content_filter_rules', 'include:text:contains:/support/' );

		$reported = array();
		$listener = static function ( $provider_obj, $duplicate ) use ( &$reported ): void {
			$reported[] = $duplicate;
		};
		add_action( 'now_pages_threadi_source_duplicate', $listener, 10, 2 );

		// keep the translations of the same account.
		$this->save(
			array(
				'now-pages-threadi-provider'             => 'stub_identified',
				'stub_account'                           => 'tz',
				'now-pages-threadi-content-filter-rules' => 'include:text:contains:translate.wordpress.org',
			)
		);

		$this->assertSame( array(), $reported );
		$this->assertSame( 'include:text:contains:translate.wordpress.org', get_post_meta( $this->source_id, 'content_filter_rules', true ) );

		// keep the same as the other source: now both would import the same entries.
		$this->save(
			array(
				'now-pages-threadi-provider'             => 'stub_identified',
				'stub_account'                           => 'tz',
				'now-pages-threadi-content-filter-rules' => 'include:text:contains:/support/',
			)
		);

		remove_action( 'now_pages_threadi_source_duplicate', $listener );

		$this->assertSame( array( $other ), $reported );
	}

	/**
	 * A rule which does not work must be reported when the source is saved, and still be stored as entered.
	 *
	 * @return void
	 */
	public function test_a_broken_filter_rule_is_reported(): void {
		$hint = 'now_pages_threadi_content_filter_rules';

		\NowPagesThreadi\Dependencies\easyTransientsForWordPress\Transients::get_instance()->get_transient_by_name( $hint )->delete();

		$this->save(
			array(
				'now-pages-threadi-provider'             => 'stub',
				'stub_url'                               => 'https://example.com/feed',
				'now-pages-threadi-content-filter-rules' => 'action:link:contains:/support/forum/',
			)
		);

		$transient = \NowPagesThreadi\Dependencies\easyTransientsForWordPress\Transients::get_instance()->get_transient_by_name( $hint );

		$this->assertSame( 'action:link:contains:/support/forum/', get_post_meta( $this->source_id, 'content_filter_rules', true ) );
		$this->assertTrue( $transient->is_set() );
		$this->assertStringContainsString( 'of this source', $transient->get_message() );

		// saved again with a rule which works, nothing is reported.
		$transient->delete();

		$this->save(
			array(
				'now-pages-threadi-provider'             => 'stub',
				'stub_url'                               => 'https://example.com/feed',
				'now-pages-threadi-content-filter-rules' => 'include:url:contains:/support/topic/',
			)
		);

		$this->assertFalse( \NowPagesThreadi\Dependencies\easyTransientsForWordPress\Transients::get_instance()->get_transient_by_name( $hint )->is_set() );
	}

	/**
	 * Regex rules must survive a save unchanged.
	 *
	 * WordPress slashes the request, so the fields are slashed here as well.
	 * Unslashing them twice turned \bprivat\b into bprivatb, and the
	 * sanitizing of a textarea removed lookbehinds and named groups as tags.
	 *
	 * @return void
	 */
	public function test_regex_rules_survive_a_save(): void {
		$rules = "exclude:text:regex:\\bprivat\\b\nexclude:text:regex:(?<=a)b\nexclude:text:regex:(?<name>secret)";

		$this->save(
			wp_slash(
				array(
					'now-pages-threadi-provider'             => 'stub',
					'stub_url'                               => 'https://example.com/feed',
					'now-pages-threadi-content-filter-rules' => $rules,
				)
			)
		);

		$this->assertSame( $rules, get_post_meta( $this->source_id, 'content_filter_rules', true ) );
	}

	/**
	 * A saved 0 must be shown as 0, not replaced by the default of the field.
	 *
	 * Otherwise a checkbox with the default 1 is shown checked again, and the
	 * next save switches it back on.
	 *
	 * @return void
	 */
	public function test_a_saved_zero_is_not_replaced_by_the_default(): void {
		update_post_meta( $this->source_id, 'provider', 'stub' );
		update_post_meta( $this->source_id, 'stub_limit', 0 );

		ob_start();
		Sources::get_instance()->show_provider_settings( get_post( $this->source_id ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="stub_limit" value="0"', $html );
	}

	/**
	 * A field which has never been saved still shows its default.
	 *
	 * @return void
	 */
	public function test_a_never_saved_field_shows_its_default(): void {
		update_post_meta( $this->source_id, 'provider', 'stub' );

		ob_start();
		Sources::get_instance()->show_provider_settings( get_post( $this->source_id ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="stub_limit" value="5"', $html );
	}
}
