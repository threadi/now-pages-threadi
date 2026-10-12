<?php
/**
 * File to test the one place a source is resolved, checked and created.
 *
 * The setup, the abilities and the advisor all end up here. What is
 * covered here does not have to be covered three times.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Tests\Unit;

use ThreadiNowPages\Plugin\Log;
use ThreadiNowPages\Providers\Provider_Interface;
use ThreadiNowPages\Providers\Providers;
use ThreadiNowPages\Sources\Source_Builder;
use ThreadiNowPages\Sources\Sources;
use ThreadiNowPages\Tests\NowPagesTestCase;
use WP_Error;
use WP_Query;

/**
 * Object to test Source_Builder.
 */
class SourceBuilderTest extends NowPagesTestCase {
	/**
	 * Act as an administrator in every test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Return the number of stored entries.
	 *
	 * @return int
	 */
	private function count_entries(): int {
		$query = new WP_Query(
			array(
				'post_type'      => 'threadi_np_entry',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		return count( $query->posts );
	}

	/**
	 * Return the provider with the given slug.
	 *
	 * @param string $slug The slug.
	 *
	 * @return Provider_Interface
	 */
	private function get_provider( string $slug ): Provider_Interface {
		$provider = Providers::get_instance()->get_provider( $slug );

		$this->assertInstanceOf( Provider_Interface::class, $provider );

		return $provider;
	}

	/**
	 * The catalog must name every available preset and enabled provider with its fields.
	 *
	 * @return void
	 */
	public function test_the_catalog_names_presets_and_providers(): void {
		$catalog = Source_Builder::get_instance()->get_catalog();

		$presets   = wp_list_pluck( $catalog['presets'], 'slug' );
		$providers = wp_list_pluck( $catalog['providers'], 'slug' );

		$this->assertContains( 'mastodon', $presets );
		$this->assertContains( 'rss', $providers );
		$this->assertContains( 'github', $providers );
	}

	/**
	 * A provider the administrator switched off must not be offered, nor a preset built on it.
	 *
	 * @return void
	 */
	public function test_the_catalog_leaves_out_disabled_providers(): void {
		update_option( 'threadi_now_pages_provider_rss_enabled', 0 );

		$catalog = Source_Builder::get_instance()->get_catalog();

		delete_option( 'threadi_now_pages_provider_rss_enabled' );

		$this->assertNotContains( 'rss', wp_list_pluck( $catalog['providers'], 'slug' ) );
		$this->assertNotContains( 'mastodon', wp_list_pluck( $catalog['presets'], 'slug' ) );
		$this->assertContains( 'github', wp_list_pluck( $catalog['providers'], 'slug' ) );
	}

	/**
	 * A key must be marked as secret, so a caller knows not to ask for it.
	 *
	 * @return void
	 */
	public function test_the_catalog_marks_secrets(): void {
		$catalog = Source_Builder::get_instance()->get_catalog();

		foreach ( $catalog['providers'] as $provider ) {
			if ( 'lastfm' !== $provider['slug'] ) {
				continue;
			}

			$fields = array_column( $provider['fields'], null, 'name' );

			$this->assertTrue( $fields['lastfm_api_key']['secret'] );
			$this->assertFalse( $fields['lastfm_username']['secret'] );

			return;
		}

		$this->fail( 'The provider lastfm is missing in the catalog.' );
	}

	/**
	 * A description in the catalog must be plain text, it is read by machines.
	 *
	 * @return void
	 */
	public function test_the_catalog_contains_no_markup(): void {
		$this->assertStringNotContainsString( '<a ', (string) wp_json_encode( Source_Builder::get_instance()->get_catalog() ) );
	}

	/**
	 * An unknown preset must be refused with the valid ones named.
	 *
	 * @return void
	 */
	public function test_unknown_preset_is_refused(): void {
		$resolved = Source_Builder::get_instance()->resolve( 'does-not-exist', '', array() );

		$this->assertInstanceOf( WP_Error::class, $resolved );
		$this->assertSame( 'threadi_now_pages_unknown_preset', $resolved->get_error_code() );
		$this->assertStringContainsString( 'mastodon', $resolved->get_error_message() );
	}

	/**
	 * An unknown provider must be refused.
	 *
	 * @return void
	 */
	public function test_unknown_provider_is_refused(): void {
		$resolved = Source_Builder::get_instance()->resolve( '', 'does-not-exist', array() );

		$this->assertInstanceOf( WP_Error::class, $resolved );
		$this->assertSame( 'threadi_now_pages_unknown_provider', $resolved->get_error_code() );
	}

	/**
	 * A provider the administrator switched off must not be usable.
	 *
	 * @return void
	 */
	public function test_disabled_provider_is_refused(): void {
		update_option( 'threadi_now_pages_provider_rss_enabled', 0 );

		$resolved = Source_Builder::get_instance()->resolve( '', 'rss', array( 'feed_url' => 'https://example.com/feed/' ) );

		delete_option( 'threadi_now_pages_provider_rss_enabled' );

		$this->assertInstanceOf( WP_Error::class, $resolved );
		$this->assertSame( 'threadi_now_pages_provider_disabled', $resolved->get_error_code() );
	}

	/**
	 * A preset must derive the configuration from its answers.
	 *
	 * @return void
	 */
	public function test_preset_derives_the_configuration(): void {
		$resolved = Source_Builder::get_instance()->resolve(
			'mastodon',
			'',
			array(
				'mastodon_instance' => 'mastodon.social',
				'mastodon_handle'   => '@tz',
			)
		);

		$this->assertIsArray( $resolved );
		$this->assertSame( 'rss', $resolved['provider']->get_slug() );
		$this->assertSame( 'https://mastodon.social/@tz.rss', $resolved['config']['feed_url'] );
		$this->assertSame( array(), $resolved['missing'] );
		$this->assertSame( 'Mastodon', $resolved['title'] );
	}

	/**
	 * A preset without its answers must name its questions as missing.
	 *
	 * @return void
	 */
	public function test_preset_without_answers_names_its_questions(): void {
		$resolved = Source_Builder::get_instance()->resolve( 'mastodon', '', array( 'mastodon_instance' => 'mastodon.social' ) );

		$this->assertIsArray( $resolved );
		$this->assertSame( array( 'mastodon_handle' ), wp_list_pluck( $resolved['missing'], 'name' ) );
		$this->assertNotSame( '', $resolved['missing'][0]['label'] );
	}

	/**
	 * Fields the provider does not know must never reach the configuration.
	 *
	 * @return void
	 */
	public function test_unknown_fields_are_ignored(): void {
		$resolved = Source_Builder::get_instance()->resolve(
			'',
			'rss',
			array(
				'feed_url'    => 'https://example.com/feed/',
				'post_status' => 'publish',
				'provider'    => 'webhook',
			)
		);

		$this->assertIsArray( $resolved );
		$this->assertSame( array( 'feed_url', 'max_items' ), array_keys( $resolved['config'] ) );
	}

	/**
	 * Each value must be cleaned up by its field type.
	 *
	 * @return void
	 */
	public function test_values_are_cleaned_up_by_their_field(): void {
		$resolved = Source_Builder::get_instance()->resolve(
			'',
			'rss',
			array(
				'feed_url'  => 'javascript:alert(1)',
				'max_items' => '5000',
			)
		);

		$this->assertIsArray( $resolved );
		$this->assertSame( '', $resolved['config']['feed_url'] );
		$this->assertSame( 100, $resolved['config']['max_items'] );
		$this->assertSame( array( 'feed_url' ), wp_list_pluck( $resolved['missing'], 'name' ) );
	}

	/**
	 * A field nobody set must get its default.
	 *
	 * @return void
	 */
	public function test_defaults_are_used(): void {
		$resolved = Source_Builder::get_instance()->resolve( '', 'rss', array( 'feed_url' => 'https://example.com/feed/' ) );

		$this->assertIsArray( $resolved );
		$this->assertSame( 10, $resolved['config']['max_items'] );
	}

	/**
	 * A missing key must be reported apart from the other missing fields.
	 *
	 * @return void
	 */
	public function test_missing_key_is_reported_separately(): void {
		$resolved = Source_Builder::get_instance()->resolve( 'lastfm', '', array( 'lastfm_username' => 'rj' ) );

		$this->assertIsArray( $resolved );
		$this->assertSame( array(), $resolved['missing'] );
		$this->assertSame( array( 'lastfm_api_key' ), wp_list_pluck( $resolved['missing_secrets'], 'name' ) );
	}

	/**
	 * A check must deliver what the provider finds, as samples.
	 *
	 * @return void
	 */
	public function test_check_returns_the_found_entries(): void {
		$check = Source_Builder::get_instance()->check( $this->get_provider( 'github' ), array( 'github_username' => self::$github_user ) );

		$this->assertSame( Source_Builder::CHECK_OK, $check['status'] );
		$this->assertGreaterThan( 0, $check['entries'] );
		$this->assertNotEmpty( $check['samples'] );
		$this->assertNotSame( '', $check['samples'][0]['text'] );
	}

	/**
	 * A check must not store anything.
	 *
	 * @return void
	 */
	public function test_check_stores_nothing(): void {
		$before = $this->count_entries();

		Source_Builder::get_instance()->check( $this->get_provider( 'github' ), array( 'github_username' => self::$github_user ) );

		$this->assertSame( $before, $this->count_entries() );
	}

	/**
	 * A failing check must say why, and must not write to the log.
	 *
	 * @return void
	 */
	public function test_failed_check_is_returned_and_not_logged(): void {
		$before = count( Log::get_instance()->get_entries() );

		$check = Source_Builder::get_instance()->check( $this->get_provider( 'github' ), array( 'github_username' => self::$github_error_user ) );

		$this->assertSame( Source_Builder::CHECK_FAILED, $check['status'] );
		$this->assertStringContainsString( '404', $check['message'] );
		$this->assertCount( $before, Log::get_instance()->get_entries() );
	}

	/**
	 * After a check the errors of providers must be logged again.
	 *
	 * @return void
	 */
	public function test_error_handlers_are_restored_after_a_check(): void {
		$expected = has_action( 'threadi_now_pages_provider_error', array( Providers::get_instance(), 'log_error' ) );

		Source_Builder::get_instance()->check( $this->get_provider( 'github' ), array( 'github_username' => self::$github_error_user ) );

		$this->assertNotFalse( $expected );
		$this->assertSame( $expected, has_action( 'threadi_now_pages_provider_error', array( Providers::get_instance(), 'log_error' ) ) );
	}

	/**
	 * A service which answers without entries is not a failure.
	 *
	 * @return void
	 */
	public function test_check_without_entries_is_empty(): void {
		$check = Source_Builder::get_instance()->check( $this->get_provider( 'github' ), array( 'github_username' => self::$github_empty_user ) );

		$this->assertSame( Source_Builder::CHECK_EMPTY, $check['status'] );
		$this->assertSame( 0, $check['entries'] );
	}

	/**
	 * An address in a private network must not be requested.
	 *
	 * @return void
	 */
	public function test_private_address_is_not_checked(): void {
		$check = Source_Builder::get_instance()->check( $this->get_provider( 'rss' ), array( 'feed_url' => 'http://192.168.1.10/feed/' ) );

		$this->assertSame( Source_Builder::CHECK_UNSAFE, $check['status'] );
	}

	/**
	 * Nothing must be requested as long as a required value is missing.
	 *
	 * @return void
	 */
	public function test_incomplete_configuration_is_not_checked(): void {
		$check = Source_Builder::get_instance()->check( $this->get_provider( 'lastfm' ), array( 'lastfm_username' => 'rj' ) );

		$this->assertSame( Source_Builder::CHECK_INCOMPLETE, $check['status'] );
	}

	/**
	 * A source which could be read must be published with its configuration.
	 *
	 * @return void
	 */
	public function test_readable_source_is_published(): void {
		$resolved = Source_Builder::get_instance()->resolve( 'github', '', array( 'github_username' => self::$github_user ) );

		$this->assertIsArray( $resolved );

		$created = Source_Builder::get_instance()->create_checked( $resolved, array( 'title' => 'My code' ) );

		$this->assertIsArray( $created );
		$this->assertSame( 'publish', $created['status'] );
		$this->assertSame( 'publish', get_post_status( $created['source_id'] ) );
		$this->assertSame( 'My code', get_the_title( $created['source_id'] ) );
		$this->assertSame( 'github', get_post_meta( $created['source_id'], 'provider', true ) );
		$this->assertSame( 'github', get_post_meta( $created['source_id'], 'preset', true ) );
		$this->assertSame( self::$github_user, get_post_meta( $created['source_id'], 'github_username', true ) );
	}

	/**
	 * A source which cannot be read must not be created.
	 *
	 * @return void
	 */
	public function test_unreadable_source_is_not_created(): void {
		$resolved = Source_Builder::get_instance()->resolve( 'github', '', array( 'github_username' => self::$github_error_user ) );

		$this->assertIsArray( $resolved );

		$created = Source_Builder::get_instance()->create_checked( $resolved );

		$this->assertInstanceOf( WP_Error::class, $created );
		$this->assertSame( 'threadi_now_pages_check_failed', $created->get_error_code() );
		$this->assertSame( 0, Sources::get_instance()->get_duplicate( $resolved['provider'], $resolved['config'] ) );
	}

	/**
	 * On request a source which cannot be read is kept as a draft.
	 *
	 * @return void
	 */
	public function test_unreadable_source_can_be_kept_as_draft(): void {
		$resolved = Source_Builder::get_instance()->resolve( 'github', '', array( 'github_username' => self::$github_error_user ) );

		$this->assertIsArray( $resolved );

		$created = Source_Builder::get_instance()->create_checked( $resolved, array( 'draft_on_failure' => true ) );

		$this->assertIsArray( $created );
		$this->assertSame( 'draft', get_post_status( $created['source_id'] ) );
	}

	/**
	 * A source which still needs a key must be a draft: it would fail on every import.
	 *
	 * @return void
	 */
	public function test_source_without_its_key_is_a_draft(): void {
		$resolved = Source_Builder::get_instance()->resolve( 'lastfm', '', array( 'lastfm_username' => 'rj' ) );

		$this->assertIsArray( $resolved );

		$created = Source_Builder::get_instance()->create_checked( $resolved );

		$this->assertIsArray( $created );
		$this->assertSame( 'draft', $created['status'] );
	}

	/**
	 * A source with open questions must not be created at all.
	 *
	 * @return void
	 */
	public function test_source_with_missing_values_is_not_created(): void {
		$resolved = Source_Builder::get_instance()->resolve( '', 'rss', array() );

		$this->assertIsArray( $resolved );

		$created = Source_Builder::get_instance()->create_checked( $resolved );

		$this->assertInstanceOf( WP_Error::class, $created );
		$this->assertSame( 'threadi_now_pages_missing_values', $created->get_error_code() );
	}

	/**
	 * The same source must not be created twice.
	 *
	 * @return void
	 */
	public function test_the_same_source_is_not_created_twice(): void {
		$builder  = Source_Builder::get_instance();
		$resolved = $builder->resolve( 'github', '', array( 'github_username' => self::$github_user ) );

		$this->assertIsArray( $resolved );
		$this->assertIsArray( $builder->create_checked( $resolved ) );

		$again = $builder->resolve( 'github', '', array( 'github_username' => self::$github_user ) );

		$this->assertIsArray( $again );
		$this->assertGreaterThan( 0, $again['duplicate_of'] );

		$created = $builder->create_checked( $again );

		$this->assertInstanceOf( WP_Error::class, $created );
		$this->assertSame( 'threadi_now_pages_duplicate_source', $created->get_error_code() );
	}

	/**
	 * A key must be stored encrypted, and be readable for the provider.
	 *
	 * @return void
	 */
	public function test_a_key_is_stored_encrypted(): void {
		$source_id = Source_Builder::get_instance()->insert(
			'github',
			array(
				'github_username' => self::$github_user,
				'github_token'    => 'secret-token-value',
			),
			array( 'title' => 'With a token' )
		);

		$this->assertIsInt( $source_id );
		$this->assertNotSame( '', get_post_meta( $source_id, 'github_token', true ) );
		$this->assertNotSame( 'secret-token-value', get_post_meta( $source_id, 'github_token', true ) );

		$config = Sources::get_instance()->get_source_config( $source_id, $this->get_provider( 'github' ) );

		$this->assertIsArray( $config );
		$this->assertSame( 'secret-token-value', $config['github_token'] );
	}
}
