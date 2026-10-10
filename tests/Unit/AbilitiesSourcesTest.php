<?php
/**
 * File to test the callbacks behind the abilities for sources.
 *
 * With these an assistant can do what the setup does: find out what a
 * source can be created from, try one and create it.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Tests\Unit;

use NowPagesThreadi\NowPages\Abilities;
use NowPagesThreadi\Sources\Source_Builder;
use NowPagesThreadi\Tests\NowPagesTestCase;
use WP_Error;

/**
 * Object to test the ability callbacks for sources.
 */
class AbilitiesSourcesTest extends NowPagesTestCase {
	/**
	 * Act as an administrator in every test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		// mark to get all tests.
		update_option( Abilities::OPTION_READ_ONLY, 0 );

		// set the user to admin.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * The three abilities must be registered, so an assistant can find them.
	 *
	 * @return void
	 */
	public function test_the_abilities_are_registered(): void {
		if ( ! function_exists( 'wp_has_ability' ) ) {
			$this->markTestSkipped( 'The Abilities API is not available.' );
		}

		$this->assertTrue( wp_has_ability( 'now-pages-threadi/list-providers' ) );
		$this->assertTrue( wp_has_ability( 'now-pages-threadi/check-source' ) );
		$this->assertTrue( wp_has_ability( 'now-pages-threadi/create-source' ) );
	}

	/**
	 * Run through the Abilities API, what an assistant gets back must match the declared schemas.
	 *
	 * The API validates the input and the output of every call. A result
	 * which does not fit its schema is turned into an error there.
	 *
	 * @return void
	 */
	public function test_the_results_match_their_schemas(): void {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'The Abilities API is not available.' );
		}

		$list = wp_get_ability( 'now-pages-threadi/list-providers' )->execute();

		$this->assertIsArray( $list, is_wp_error( $list ) ? $list->get_error_message() : '' );

		$input = array(
			'preset' => 'github',
			'values' => array( 'github_username' => self::$github_user ),
		);

		$check = wp_get_ability( 'now-pages-threadi/check-source' )->execute( $input );

		$this->assertIsArray( $check, is_wp_error( $check ) ? $check->get_error_message() : '' );
		$this->assertSame( Source_Builder::CHECK_OK, $check['status'] );

		$created = wp_get_ability( 'now-pages-threadi/create-source' )->execute( $input );

		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );
		$this->assertTrue( $created['created'] );

		// the refusals have to fit the schema as well.
		$duplicate = wp_get_ability( 'now-pages-threadi/create-source' )->execute( $input );

		$this->assertIsArray( $duplicate, is_wp_error( $duplicate ) ? $duplicate->get_error_message() : '' );
		$this->assertSame( 'duplicate', $duplicate['reason'] );

		$incomplete = wp_get_ability( 'now-pages-threadi/create-source' )->execute( array( 'preset' => 'mastodon' ) );

		$this->assertIsArray( $incomplete, is_wp_error( $incomplete ) ? $incomplete->get_error_message() : '' );
		$this->assertSame( 'missing_values', $incomplete['reason'] );
	}

	/**
	 * The list must name presets and providers.
	 *
	 * @return void
	 */
	public function test_the_list_names_presets_and_providers(): void {
		$list = Abilities::get_instance()->get_providers();

		$this->assertIsArray( $list );
		$this->assertContains( 'mastodon', wp_list_pluck( $list['presets'], 'slug' ) );
		$this->assertContains( 'rss', wp_list_pluck( $list['providers'], 'slug' ) );
	}

	/**
	 * Sources are a matter of administrators.
	 *
	 * @return void
	 */
	public function test_editors_cannot_use_them(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertInstanceOf( WP_Error::class, Abilities::get_instance()->get_providers() );
		$this->assertInstanceOf( WP_Error::class, Abilities::get_instance()->check_source( array( 'preset' => 'github' ) ) );
		$this->assertInstanceOf( WP_Error::class, Abilities::get_instance()->create_source( array( 'preset' => 'github' ) ) );
	}

	/**
	 * A source can be tried before it exists.
	 *
	 * @return void
	 */
	public function test_a_source_can_be_tried_before_it_exists(): void {
		$check = Abilities::get_instance()->check_source(
			array(
				'preset' => 'github',
				'values' => array( 'github_username' => self::$github_user ),
			)
		);

		$this->assertIsArray( $check );
		$this->assertSame( Source_Builder::CHECK_OK, $check['status'] );
		$this->assertNotEmpty( $check['samples'] );
		$this->assertSame( 0, $check['duplicate_of'] );
	}

	/**
	 * Trying a source must name what is missing instead of failing.
	 *
	 * @return void
	 */
	public function test_trying_names_what_is_missing(): void {
		$check = Abilities::get_instance()->check_source( array( 'provider' => 'rss' ) );

		$this->assertIsArray( $check );
		$this->assertSame( Source_Builder::CHECK_INCOMPLETE, $check['status'] );
		$this->assertSame( array( 'feed_url' ), wp_list_pluck( $check['missing'], 'name' ) );
	}

	/**
	 * Without a preset or provider there is nothing to try.
	 *
	 * @return void
	 */
	public function test_without_preset_or_provider_it_is_an_error(): void {
		$check = Abilities::get_instance()->check_source( array( 'values' => array( 'feed_url' => 'https://example.com/feed/' ) ) );

		$this->assertInstanceOf( WP_Error::class, $check );
		$this->assertSame( 'now_pages_threadi_missing_provider', $check->get_error_code() );
	}

	/**
	 * A created source must be published and described.
	 *
	 * @return void
	 */
	public function test_a_source_is_created(): void {
		$created = Abilities::get_instance()->create_source(
			array(
				'preset' => 'github',
				'values' => array( 'github_username' => self::$github_user ),
				'title'  => 'My code',
			)
		);

		$this->assertIsArray( $created );
		$this->assertTrue( $created['created'] );
		$this->assertSame( 'publish', $created['status'] );
		$this->assertSame( '', $created['reason'] );
		$this->assertSame( 'My code', get_the_title( $created['source_id'] ) );
		$this->assertNotSame( '', $created['edit_url'] );

		// it must show up for the other abilities.
		$this->assertContains( $created['source_id'], wp_list_pluck( Abilities::get_instance()->get_sources(), 'id' ) );
	}

	/**
	 * A routine can be created by an assistant: a sentence, how often, and whether the user is asked first.
	 *
	 * @return void
	 */
	public function test_a_routine_is_created(): void {
		$input = array(
			'provider' => 'recurring',
			'values'   => array(
				'recurring_text'     => 'Went to the gym',
				'recurring_rhythm'   => 'weekly',
				'recurring_weekdays' => array( 'tue', 'thu' ),
				'recurring_confirm'  => true,
			),
		);

		// tried first: nothing is read, the entry which would be created is shown.
		$check = Abilities::get_instance()->check_source( $input );

		$this->assertIsArray( $check );
		$this->assertSame( Source_Builder::CHECK_OK, $check['status'] );
		$this->assertSame( 'Went to the gym', $check['samples'][0]['text'] );

		$created = Abilities::get_instance()->create_source( $input );

		$this->assertIsArray( $created );
		$this->assertTrue( $created['created'] );
		$this->assertSame( 'publish', $created['status'] );
		$this->assertSame( 'Went to the gym', get_the_title( $created['source_id'] ) );
		$this->assertSame( 'recurring', get_post_meta( $created['source_id'], 'provider', true ) );
		$this->assertSame( 'weekly', get_post_meta( $created['source_id'], 'recurring_rhythm', true ) );
		$this->assertSame( array( 'tue', 'thu' ), get_post_meta( $created['source_id'], 'recurring_weekdays', true ) );
		$this->assertSame( 1, absint( get_post_meta( $created['source_id'], 'recurring_confirm', true ) ) );
	}

	/**
	 * The list of providers tells an assistant which rhythms there are.
	 *
	 * @return void
	 */
	public function test_the_list_names_the_rhythms_of_a_routine(): void {
		$list = Abilities::get_instance()->get_providers();

		foreach ( $list['providers'] as $provider ) {
			if ( 'recurring' !== $provider['slug'] ) {
				continue;
			}

			$fields = array_column( $provider['fields'], null, 'name' );

			$this->assertArrayHasKey( 'weekly', $fields['recurring_rhythm']['options'] );

			return;
		}

		$this->fail( 'The provider recurring is missing in the list.' );
	}

	/**
	 * An existing source can be tried by its ID.
	 *
	 * @return void
	 */
	public function test_an_existing_source_can_be_tried(): void {
		$created = Abilities::get_instance()->create_source(
			array(
				'preset' => 'github',
				'values' => array( 'github_username' => self::$github_user ),
			)
		);

		$this->assertIsArray( $created );

		$check = Abilities::get_instance()->check_source( array( 'source_id' => $created['source_id'] ) );

		$this->assertIsArray( $check );
		$this->assertSame( Source_Builder::CHECK_OK, $check['status'] );
	}

	/**
	 * Any other post must not be usable as a source.
	 *
	 * @return void
	 */
	public function test_another_post_is_not_a_source(): void {
		$post_id = self::factory()->post->create();

		$check = Abilities::get_instance()->check_source( array( 'source_id' => $post_id ) );

		$this->assertInstanceOf( WP_Error::class, $check );
		$this->assertSame( 'now_pages_threadi_not_found', $check->get_error_code() );
	}

	/**
	 * A source which cannot be read must be refused with a reason an assistant can act on.
	 *
	 * @return void
	 */
	public function test_an_unreadable_source_is_refused_with_a_reason(): void {
		$created = Abilities::get_instance()->create_source(
			array(
				'preset' => 'github',
				'values' => array( 'github_username' => self::$github_error_user ),
			)
		);

		$this->assertIsArray( $created );
		$this->assertFalse( $created['created'] );
		$this->assertSame( 'check_failed', $created['reason'] );
		$this->assertSame( Source_Builder::CHECK_FAILED, $created['check']['status'] );
	}

	/**
	 * The same source must not be created twice, and the existing one must be named.
	 *
	 * @return void
	 */
	public function test_a_duplicate_names_the_existing_source(): void {
		$input = array(
			'preset' => 'github',
			'values' => array( 'github_username' => self::$github_user ),
		);

		$first  = Abilities::get_instance()->create_source( $input );
		$second = Abilities::get_instance()->create_source( $input );

		$this->assertIsArray( $first );
		$this->assertIsArray( $second );
		$this->assertFalse( $second['created'] );
		$this->assertSame( 'duplicate', $second['reason'] );
		$this->assertSame( $first['source_id'], $second['duplicate_of'] );
	}

	/**
	 * A source which needs a key must become a draft the user completes.
	 *
	 * @return void
	 */
	public function test_a_source_without_its_key_becomes_a_draft(): void {
		$created = Abilities::get_instance()->create_source(
			array(
				'preset' => 'lastfm',
				'values' => array( 'lastfm_username' => 'rj' ),
			)
		);

		$this->assertIsArray( $created );
		$this->assertTrue( $created['created'] );
		$this->assertSame( 'draft', $created['status'] );
		$this->assertSame( 'needs_secret', $created['reason'] );
		$this->assertSame( array( 'lastfm_api_key' ), wp_list_pluck( $created['missing'], 'name' ) );
	}

	/**
	 * Missing values must be named, and nothing must be created.
	 *
	 * @return void
	 */
	public function test_missing_values_are_named(): void {
		$created = Abilities::get_instance()->create_source( array( 'preset' => 'mastodon' ) );

		$this->assertIsArray( $created );
		$this->assertFalse( $created['created'] );
		$this->assertSame( 'missing_values', $created['reason'] );
		$this->assertCount( 2, $created['missing'] );
	}

	/**
	 * An unknown category must be refused before anything is requested.
	 *
	 * @return void
	 */
	public function test_an_unknown_category_is_refused(): void {
		$created = Abilities::get_instance()->create_source(
			array(
				'preset'           => 'github',
				'values'           => array( 'github_username' => self::$github_user ),
				'category_term_id' => 999999,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $created );
		$this->assertSame( 'now_pages_threadi_invalid_category', $created->get_error_code() );
	}
}
