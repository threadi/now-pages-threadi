<?php
/**
 * File to test the callbacks behind our abilities.
 *
 * These are what an assistant actually ends up calling. They are tested
 * directly, without the abilities API, so the tests run whether or not
 * that plugin is installed in this environment.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Tests\Unit;

use ThreadiNowPages\Digests\Digest_Builder;
use ThreadiNowPages\Digests\Periods;
use ThreadiNowPages\NowPages\Abilities;
use ThreadiNowPages\NowPages\Categories;
use ThreadiNowPages\NowPages\Entry;
use ThreadiNowPages\Providers\Providers;
use ThreadiNowPages\Tests\NowPagesTestCase;
use ThreadiNowPages\Tests\Unit\Stubs\Stub_Provider;
use WP_Error;

/**
 * Object to test the ability callbacks.
 */
class AbilitiesTest extends NowPagesTestCase {
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
	 * Create an entry inside the live period.
	 *
	 * @param string $text The text of the entry.
	 *
	 * @return int
	 */
	private function create_entry( string $text ): int {
		return self::factory()->post->create(
			array(
				'post_type'     => 'threadi_np_entry',
				'post_status'   => 'publish',
				'post_title'    => '',
				'post_content'  => $text,
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - ( 2 * HOUR_IN_SECONDS ) ),
			)
		);
	}

	/**
	 * Every period must be offered with a slug and a description.
	 *
	 * A period without a description is useless to an assistant: it has no
	 * way to tell which one to use.
	 *
	 * @return void
	 */
	public function test_the_periods_ability_names_every_period(): void {
		$periods = Abilities::get_instance()->get_periods();

		$this->assertSameSize( Periods::get_instance()->get_periods(), $periods );

		foreach ( $periods as $period ) {
			$this->assertNotSame( '', $period['slug'] );
			$this->assertNotSame( '', $period['label'] );
			$this->assertNotSame(
				'',
				$period['description'],
				'The period ' . $period['slug'] . ' has no description.'
			);
		}
	}

	/**
	 * The offered slugs must be the ones the digest actually accepts.
	 *
	 * @return void
	 */
	public function test_the_offered_periods_are_resolvable(): void {
		foreach ( Abilities::get_instance()->get_periods() as $period ) {
			$this->assertNotNull(
				Periods::get_instance()->get_period( $period['slug'] ),
				'The period ' . $period['slug'] . ' is offered but cannot be resolved.'
			);
		}
	}

	/**
	 * Without categories the ability must return an empty list.
	 *
	 * @return void
	 */
	public function test_the_categories_ability_without_categories(): void {
		$this->assertSame( array(), Abilities::get_instance()->get_categories() );
	}

	/**
	 * A category must be offered with its ID and name.
	 *
	 * @return void
	 */
	public function test_the_categories_ability_names_a_category(): void {
		$term = wp_insert_term( 'Work', Categories::get_instance()->get_slug() );

		$this->assertIsArray( $term );

		$categories = Abilities::get_instance()->get_categories();

		$this->assertCount( 1, $categories );
		$this->assertSame( $term['term_id'], $categories[0]['id'] );
		$this->assertSame( 'Work', $categories[0]['name'] );
	}

	/**
	 * The returned ID must be usable as category_term_id.
	 *
	 * @return void
	 */
	public function test_the_returned_category_id_is_usable(): void {
		$term = wp_insert_term( 'Work', Categories::get_instance()->get_slug() );

		$this->assertIsArray( $term );

		$categories = Abilities::get_instance()->get_categories();

		$added = Abilities::get_instance()->add_entry(
			array(
				'text'             => 'An entry in a category',
				'date'             => '',
				'category_term_id' => (string) $categories[0]['id'],
			)
		);

		$this->assertIsArray( $added );
		$this->assertTrue( $added['added'] );
		$this->assertSame( $categories[0]['id'], $added['entry']['category_term_id'] );
	}

	/**
	 * Without sources the ability must return an empty list.
	 *
	 * @return void
	 */
	public function test_the_sources_ability_without_sources(): void {
		$this->assertSame( array(), Abilities::get_instance()->get_sources() );
	}

	/**
	 * A configured source must be offered with its provider.
	 *
	 * @return void
	 */
	public function test_the_sources_ability_names_a_source(): void {
		Providers::get_instance()->register_provider( new Stub_Provider() );

		$source_id = self::factory()->post->create(
			array(
				'post_type'   => 'threadi_np_source',
				'post_status' => 'publish',
				'post_title'  => 'My test source',
			)
		);

		update_post_meta( $source_id, 'provider', 'stub' );

		$sources = Abilities::get_instance()->get_sources();

		$this->assertCount( 1, $sources );
		$this->assertSame( $source_id, $sources[0]['id'] );
		$this->assertSame( 'My test source', $sources[0]['title'] );
		$this->assertNotSame( '', $sources[0]['provider'] );
	}

	/**
	 * A source that never ran must say so instead of claiming a date.
	 *
	 * @return void
	 */
	public function test_a_source_that_never_ran_has_no_date(): void {
		Providers::get_instance()->register_provider( new Stub_Provider() );

		$source_id = self::factory()->post->create(
			array(
				'post_type'   => 'threadi_np_source',
				'post_status' => 'publish',
				'post_title'  => 'My test source',
			)
		);

		update_post_meta( $source_id, 'provider', 'stub' );

		$sources = Abilities::get_instance()->get_sources();

		$this->assertSame( '', $sources[0]['last_run'] );
	}

	/**
	 * A source that ran must report when.
	 *
	 * @return void
	 */
	public function test_a_source_that_ran_reports_when(): void {
		Providers::get_instance()->register_provider( new Stub_Provider() );

		$source_id = self::factory()->post->create(
			array(
				'post_type'   => 'threadi_np_source',
				'post_status' => 'publish',
				'post_title'  => 'My test source',
			)
		);

		update_post_meta( $source_id, 'provider', 'stub' );
		update_post_meta( $source_id, 'threadi_now_pages_provider_last_run', time() );

		$sources = Abilities::get_instance()->get_sources();

		$this->assertNotSame( '', $sources[0]['last_run'] );
	}

	/**
	 * An import without new entries must report zero.
	 *
	 * @return void
	 */
	public function test_an_import_without_entries_reports_zero(): void {
		$result = Abilities::get_instance()->run_import();

		$this->assertSame( 0, $result['added'] );
	}

	/**
	 * An import skipped by the lock of another import must report an error, not zero.
	 *
	 * @return void
	 */
	public function test_a_locked_import_reports_an_error(): void {
		add_option( \ThreadiNowPages\Sources\Sources::IMPORT_LOCK_OPTION, time() . '|another-run', '', false );

		$result = Abilities::get_instance()->run_import();

		delete_option( \ThreadiNowPages\Sources\Sources::IMPORT_LOCK_OPTION );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'threadi_now_pages_import_running', $result->get_error_code() );
	}

	/**
	 * An import must report how many entries it added.
	 *
	 * @return void
	 */
	public function test_an_import_reports_what_it_added(): void {
		Stub_Provider::reset();

		$entry              = new Entry();
		$entry->text        = 'An imported entry';
		$entry->date        = new \DateTimeImmutable();
		$entry->source_slug = 'stub';
		$entry->external_id = 'stub-1';

		Stub_Provider::$entries = array( $entry );

		Providers::get_instance()->register_provider( new Stub_Provider() );

		$source_id = self::factory()->post->create(
			array(
				'post_type'   => 'threadi_np_source',
				'post_status' => 'publish',
				'post_title'  => 'My test source',
			)
		);

		update_post_meta( $source_id, 'provider', 'stub' );

		$result = Abilities::get_instance()->run_import();

		$this->assertSame( 1, $result['added'] );
	}

	/**
	 * A second import must report zero, the duplicate check catches it.
	 *
	 * This is what the idempotent annotation on the ability promises.
	 *
	 * @return void
	 */
	public function test_a_second_import_adds_nothing(): void {
		Stub_Provider::reset();

		$entry              = new Entry();
		$entry->text        = 'An imported entry';
		$entry->date        = new \DateTimeImmutable();
		$entry->source_slug = 'stub';
		$entry->external_id = 'stub-1';

		Stub_Provider::$entries = array( $entry );

		Providers::get_instance()->register_provider( new Stub_Provider() );

		$source_id = self::factory()->post->create(
			array(
				'post_type'   => 'threadi_np_source',
				'post_status' => 'publish',
				'post_title'  => 'My test source',
			)
		);

		update_post_meta( $source_id, 'provider', 'stub' );

		Abilities::get_instance()->run_import();

		$result = Abilities::get_instance()->run_import();

		$this->assertSame( 0, $result['added'] );
	}

	/**
	 * Adding an entry must work and show up in the digest.
	 *
	 * @return void
	 */
	public function test_adding_an_entry_works(): void {
		$added = Abilities::get_instance()->add_entry(
			array(
				'text' => 'Added by an assistant',
				'date' => '',
			)
		);

		$this->assertIsArray( $added );
		$this->assertTrue( $added['added'] );
		$this->assertGreaterThan( 0, $added['post_id'] );

		$content = ( new Digest_Builder() )->build( array( 'delay_hours' => 0 ) );

		$this->assertStringContainsString( 'Added by an assistant', $content );
	}

	/**
	 * Adding without a text must be refused.
	 *
	 * @return void
	 */
	public function test_adding_without_a_text_is_refused(): void {
		$this->assertInstanceOf( WP_Error::class, Abilities::get_instance()->add_entry( array( 'date' => '' ) ) );
	}

	/**
	 * Deleting an entry must work.
	 *
	 * @return void
	 */
	public function test_deleting_an_entry_works(): void {
		$entry_id = $this->create_entry( 'An entry' );

		$this->assertTrue( Abilities::get_instance()->delete_entry( array( 'post_id' => $entry_id ) ) );
		$this->assertEquals( 'trash', get_post_status( $entry_id ) );
	}

	/**
	 * Deleting anything that is not our entry must be refused.
	 *
	 * Without this an assistant could delete any post on the website.
	 *
	 * @return void
	 */
	public function test_deleting_a_foreign_post_is_refused(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'A regular post',
			)
		);

		$this->assertInstanceOf( WP_Error::class, Abilities::get_instance()->delete_entry( array( 'post_id' => $post_id ) ) );
		$this->assertNotNull( get_post( $post_id ) );
	}

	/**
	 * Every read must be refused without the capability.
	 *
	 * @return void
	 */
	public function test_reading_is_refused_without_the_capability(): void {
		$this->create_entry( 'An entry' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertInstanceOf( WP_Error::class, Abilities::get_instance()->get_now_page( array() ) );
		$this->assertInstanceOf( WP_Error::class, Abilities::get_instance()->list_entries( array() ) );
		$this->assertInstanceOf( WP_Error::class, Abilities::get_instance()->diagnose_digest( array() ) );
	}

	/**
	 * Every write must be refused without the capability.
	 *
	 * @return void
	 */
	public function test_writing_is_refused_without_the_capability(): void {
		$entry_id = $this->create_entry( 'An entry' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertInstanceOf( WP_Error::class, Abilities::get_instance()->add_entry( array( 'text' => 'Nope', 'date' => '' ) ) );
		$this->assertInstanceOf( WP_Error::class, Abilities::get_instance()->update_entry( array( 'id' => $entry_id, 'text' => 'Nope' ) ) );
		$this->assertInstanceOf( WP_Error::class, Abilities::get_instance()->delete_entry( array( 'post_id' => $entry_id ) ) );
		$this->assertNotNull( get_post( $entry_id ) );
	}

	/**
	 * An entry the content filter or duplicate check drops must not be reported as added.
	 *
	 * @return void
	 */
	public function test_a_duplicate_is_reported_with_a_reason(): void {
		$input = array(
			'text'        => 'Twice',
			'external_id' => 'dup-1',
		);

		$first  = Abilities::get_instance()->add_entry( $input );
		$second = Abilities::get_instance()->add_entry( $input );

		$this->assertIsArray( $first );
		$this->assertTrue( $first['added'] );
		$this->assertIsArray( $second );
		$this->assertFalse( $second['added'] );
		$this->assertSame( 'duplicate', $second['reason'] );
	}

	/**
	 * A date with timezone must be stored as the same moment.
	 *
	 * @return void
	 */
	public function test_a_utc_date_is_stored_as_the_same_moment(): void {
		update_option( 'timezone_string', 'Europe/Berlin' );

		$added = Abilities::get_instance()->add_entry(
			array(
				'text' => 'With timezone',
				'date' => '2026-01-15T10:00:00Z',
			)
		);

		$this->assertIsArray( $added );
		$this->assertSame( '2026-01-15 10:00:00', get_post( $added['post_id'] )->post_date_gmt );
		$this->assertSame( '2026-01-15 11:00:00', get_post( $added['post_id'] )->post_date );
	}

	/**
	 * An unknown category must be refused instead of silently ignored.
	 *
	 * @return void
	 */
	public function test_an_unknown_category_is_refused(): void {
		$this->assertInstanceOf( WP_Error::class, Abilities::get_instance()->add_entry( array( 'text' => 'x', 'category_term_id' => 999999 ) ) );
	}

	/**
	 * A fresh entry must say it is still hidden by the delay.
	 *
	 * @return void
	 */
	public function test_a_fresh_entry_reports_the_delay(): void {
		update_option( 'threadi_now_pages_delay_hours', 6 );

		$added = Abilities::get_instance()->add_entry( array( 'text' => 'Just now' ) );

		$this->assertIsArray( $added );
		$this->assertFalse( $added['entry']['delay_passed'] );
		$this->assertNotSame( '', $added['entry']['visible_from'] );
	}

	/**
	 * Listing entries must return their IDs, so they can be deleted or updated.
	 *
	 * @return void
	 */
	public function test_list_entries_returns_ids(): void {
		$first  = $this->create_entry( 'First' );
		$second = $this->create_entry( 'Second' );

		$result = Abilities::get_instance()->list_entries( array() );

		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['total'] );
		$this->assertEqualsCanonicalizing( array( $first, $second ), wp_list_pluck( $result['entries'], 'id' ) );
	}

	/**
	 * Listing entries must honour the filters.
	 *
	 * @return void
	 */
	public function test_list_entries_filters_by_category_and_search(): void {
		$term = wp_insert_term( 'Work', Categories::TAXONOMY );
		$this->assertIsArray( $term );

		$in_category = $this->create_entry( 'In the category' );
		wp_set_object_terms( $in_category, array( $term['term_id'] ), Categories::TAXONOMY );
		$this->create_entry( 'Somewhere else' );

		$by_category = Abilities::get_instance()->list_entries( array( 'category_term_id' => $term['term_id'] ) );
		$by_search   = Abilities::get_instance()->list_entries( array( 'search' => 'elsewhere' ) );

		$this->assertIsArray( $by_category );
		$this->assertSame( array( $in_category ), wp_list_pluck( $by_category['entries'], 'id' ) );
		$this->assertIsArray( $by_search );
		$this->assertSame( 0, $by_search['total'] );
	}

	/**
	 * Getting anything that is not our entry must be refused.
	 *
	 * @return void
	 */
	public function test_get_entry_refuses_a_foreign_post(): void {
		$post_id = self::factory()->post->create();

		$this->assertInstanceOf( WP_Error::class, Abilities::get_instance()->get_entry( array( 'id' => $post_id ) ) );
	}

	/**
	 * Updating must change only the given fields.
	 *
	 * @return void
	 */
	public function test_update_entry_changes_only_the_given_fields(): void {
		$entry_id = $this->create_entry( 'Old text' );
		$date     = get_post( $entry_id )->post_date;

		$result = Abilities::get_instance()->update_entry(
			array(
				'id'   => $entry_id,
				'text' => 'New text',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'New text', $result['text'] );
		$this->assertSame( $date, get_post( $entry_id )->post_date );
	}

	/**
	 * An assistant confirms a waiting entry by publishing it, and the entry keeps its date.
	 *
	 * @return void
	 */
	public function test_update_entry_confirms_a_waiting_entry(): void {
		$entry_id = wp_insert_post(
			array(
				'post_type'     => 'threadi_np_entry',
				'post_status'   => 'pending',
				'post_content'  => 'Went to the gym',
				'post_date'     => '2025-10-06 00:00:00',
				'post_date_gmt' => '2025-10-06 00:00:00',
			)
		);

		$waiting = Abilities::get_instance()->list_entries( array( 'status' => 'pending' ) );

		$this->assertIsArray( $waiting );
		$this->assertContains( $entry_id, wp_list_pluck( $waiting['entries'], 'id' ) );

		$result = Abilities::get_instance()->update_entry(
			array(
				'id'     => $entry_id,
				'status' => 'publish',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'publish', get_post_status( $entry_id ) );
		$this->assertSame( '2025-10-06 00:00:00', get_post( $entry_id )->post_date );
		$this->assertSame( 1, did_action( 'threadi_now_pages_entry_confirmed' ) );
	}

	/**
	 * Updating the category with 0 must remove it.
	 *
	 * @return void
	 */
	public function test_update_entry_removes_the_category(): void {
		$term = wp_insert_term( 'Work', Categories::TAXONOMY );
		$this->assertIsArray( $term );

		$entry_id = $this->create_entry( 'An entry' );
		wp_set_object_terms( $entry_id, array( $term['term_id'] ), Categories::TAXONOMY );

		$result = Abilities::get_instance()->update_entry(
			array(
				'id'               => $entry_id,
				'category_term_id' => 0,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['category_term_id'] );
	}

	/**
	 * Updating without any change must be refused.
	 *
	 * @return void
	 */
	public function test_update_entry_without_changes_is_refused(): void {
		$entry_id = $this->create_entry( 'An entry' );

		$this->assertInstanceOf( WP_Error::class, Abilities::get_instance()->update_entry( array( 'id' => $entry_id ) ) );
	}

	/**
	 * The rendered now page must refuse an unknown period.
	 *
	 * @return void
	 */
	public function test_the_now_page_refuses_an_unknown_period(): void {
		$this->assertInstanceOf( WP_Error::class, Abilities::get_instance()->get_now_page( array( 'period' => 'decade' ) ) );
	}

	/**
	 * The diagnosis must name the delay as the reason for a fresh entry.
	 *
	 * @return void
	 */
	public function test_diagnose_explains_the_delay(): void {
		update_option( 'threadi_now_pages_delay_hours', 6 );

		$added = Abilities::get_instance()->add_entry( array( 'text' => 'Just now' ) );
		$this->assertIsArray( $added );

		$report = Abilities::get_instance()->diagnose_digest( array( 'entry_id' => $added['post_id'] ) );

		$this->assertIsArray( $report );
		$this->assertFalse( $report['entry']['shown'] );
		$this->assertContains( 'hidden_by_delay', wp_list_pluck( $report['entry']['reasons'], 'code' ) );
		$this->assertTrue( $report['range']['delay_applied'] );
	}

	/**
	 * The diagnosis must confirm an entry that is shown.
	 *
	 * @return void
	 */
	public function test_diagnose_confirms_a_shown_entry(): void {
		update_option( 'threadi_now_pages_delay_hours', 0 );

		$entry_id = $this->create_entry( 'Two hours ago' );

		// the live period counts back from now. with 'day' the entry from
		// two hours ago would belong to yesterday between midnight and 2 am.
		$report = Abilities::get_instance()->diagnose_digest(
			array(
				'entry_id' => $entry_id,
				'period'   => 'live',
				'language' => '',
			)
		);

		$this->assertIsArray( $report );
		$this->assertTrue( $report['entry']['shown'] );
	}

	/**
	 * The diagnosis must name a draft as not published.
	 *
	 * @return void
	 */
	public function test_diagnose_explains_a_draft(): void {
		$entry_id = $this->create_entry( 'A draft' );
		wp_update_post(
			array(
				'ID'          => $entry_id,
				'post_status' => 'draft',
			)
		);

		$report = Abilities::get_instance()->diagnose_digest( array( 'entry_id' => $entry_id ) );

		$this->assertIsArray( $report );
		$this->assertFalse( $report['entry']['shown'] );
		$this->assertContains( 'not_published', wp_list_pluck( $report['entry']['reasons'], 'code' ) );
	}

	/**
	 * The abilities must be offered by default, with write access.
	 *
	 * @return void
	 */
	public function test_abilities_are_offered_by_default(): void {
		delete_option( Abilities::OPTION_ENABLED );
		delete_option( Abilities::OPTION_READ_ONLY );

		$this->assertTrue( Abilities::get_instance()->is_enabled() );
		$this->assertTrue( Abilities::get_instance()->is_read_only() );
	}

	/**
	 * The switches must be reflected in the Site Health information.
	 *
	 * @return void
	 */
	public function test_site_health_shows_the_switches(): void {
		update_option( Abilities::OPTION_ENABLED, 1 );
		update_option( Abilities::OPTION_READ_ONLY, 1 );

		$info = Abilities::get_instance()->add_site_health_info( array() );

		$this->assertArrayHasKey( 'threadi-now-pages-abilities', $info );
		$this->assertSame( __( 'yes', 'threadi-now-pages' ), $info['threadi-now-pages-abilities']['fields']['read_only']['value'] );
	}

	/**
	 * A waiting entry cannot be turned into a draft.
	 *
	 * @return void
	 */
	public function test_update_entry_does_not_make_a_waiting_entry_a_draft(): void {
		$entry_id = wp_insert_post(
			array(
				'post_type'    => 'threadi_np_entry',
				'post_status'  => 'pending',
				'post_content' => 'Went to the gym',
			)
		);

		$result = Abilities::get_instance()->update_entry(
			array(
				'id'     => $entry_id,
				'status' => 'draft',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'pending', get_post_status( $entry_id ) );
	}
}
