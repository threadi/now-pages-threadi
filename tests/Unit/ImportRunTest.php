<?php
/**
 * File to test the complete import run.
 *
 * Covers the whole way from Sources::import() through the provider, the
 * now_pages_threadi_entry_received action and Entries::add_entry() down to the
 * stored post — without any network access.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Tests\Unit;

use DateTimeImmutable;
use NowPagesThreadi\NowPages\Entries;
use NowPagesThreadi\NowPages\Entry;
use NowPagesThreadi\Providers\Providers;
use NowPagesThreadi\Sources\Sources;
use NowPagesThreadi\Tests\NowPagesTestCase;
use NowPagesThreadi\Tests\Unit\Stubs\Stub_Provider;
use WP_Query;

/**
 * Object to test Sources::import().
 */
class ImportRunTest extends NowPagesTestCase {
	/**
	 * Register the stub provider and clean up before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Stub_Provider::reset();

		Providers::get_instance()->register_provider( new Stub_Provider() );
		update_option( 'now_pages_threadi_provider_stub_enabled', 1 );

		delete_option( Entries::OPTION_DELETED );
	}

	/**
	 * Create a source using the stub provider.
	 *
	 * @param array<string,mixed> $meta Additional post meta.
	 * @param array<string,mixed> $args Additional post arguments.
	 *
	 * @return int
	 */
	private function create_source( array $meta = array(), array $args = array() ): int {
		$source_id = self::factory()->post->create(
			array_merge(
				array(
					'post_type'   => 'np_threadi_source',
					'post_status' => 'publish',
					'post_title'  => 'Stub source',
				),
				$args
			)
		);

		update_post_meta( $source_id, 'provider', 'stub' );
		update_post_meta( $source_id, 'stub_url', 'https://example.com/feed' );
		update_post_meta( $source_id, 'stub_limit', 5 );

		foreach ( $meta as $key => $value ) {
			update_post_meta( $source_id, $key, $value );
		}

		return $source_id;
	}

	/**
	 * Build an entry the stub provider will return.
	 *
	 * @param string $text        The text of the entry.
	 * @param string $external_id The external ID.
	 *
	 * @return Entry
	 */
	private function make_entry( string $text, string $external_id = 'ext-1' ): Entry {
		$entry = new Entry();

		$entry->text        = $text;
		$entry->date        = new DateTimeImmutable( '2026-01-15 10:00:00' );
		$entry->source_slug = 'stub';
		$entry->external_id = $external_id;

		return $entry;
	}

	/**
	 * Return the stored entries of a source.
	 *
	 * @param int $source_id The ID of the source.
	 *
	 * @return array<int,int>
	 */
	private function get_stored_entries( int $source_id ): array {
		$query = new WP_Query(
			array(
				'post_type'      => 'np_threadi_entry',
				'post_status'    => array( 'any', 'trash' ),
				'post_parent'    => $source_id,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		return array_map( 'absint', $query->posts );
	}

	/**
	 * A full run must store the entries the provider delivered.
	 *
	 * @return void
	 */
	public function test_import_stores_the_delivered_entries(): void {
		$source_id = $this->create_source();

		Stub_Provider::$entries = array(
			$this->make_entry( 'Erster Eintrag.', 'ext-1' ),
			$this->make_entry( 'Zweiter Eintrag.', 'ext-2' ),
		);

		Sources::get_instance()->import();

		$this->assertCount( 2, $this->get_stored_entries( $source_id ) );
	}

	/**
	 * The provider must be called with the configuration of its source.
	 *
	 * Only the fields the provider declares may be passed through.
	 *
	 * @return void
	 */
	public function test_provider_receives_its_own_configuration(): void {
		$this->create_source( array( 'not_a_provider_field' => 'should-not-arrive' ) );

		Sources::get_instance()->import();

		$this->assertSame( 1, Stub_Provider::$fetch_count );
		$this->assertSame( 'https://example.com/feed', Stub_Provider::$received_config['stub_url'] );
		$this->assertArrayNotHasKey( 'not_a_provider_field', Stub_Provider::$received_config );
	}

	/**
	 * A source with an unknown provider must be skipped, not fatal.
	 *
	 * @return void
	 */
	public function test_source_with_unknown_provider_is_skipped(): void {
		$source_id = $this->create_source();

		update_post_meta( $source_id, 'provider', 'does-not-exist' );

		$errors = 0;

		add_action(
			'now_pages_threadi_provider_error',
			static function () use ( &$errors ): void {
				++$errors;
			}
		);

		Sources::get_instance()->import();

		remove_all_actions( 'now_pages_threadi_provider_error' );

		$this->assertSame( 1, $errors );
		$this->assertCount( 0, $this->get_stored_entries( $source_id ) );
	}

	/**
	 * Reading the sources outside of an import must not report a missing provider.
	 *
	 * Many places read them, e.g. the availability check and the emails.
	 *
	 * @return void
	 */
	public function test_reading_the_sources_does_not_report_an_unknown_provider(): void {
		$source_id = $this->create_source();

		update_post_meta( $source_id, 'provider', 'does-not-exist' );

		$errors = 0;

		add_action(
			'now_pages_threadi_provider_error',
			static function () use ( &$errors ): void {
				++$errors;
			}
		);

		Sources::get_instance()->get_configured_sources();

		remove_all_actions( 'now_pages_threadi_provider_error' );

		$this->assertSame( 0, $errors );
	}

	/**
	 * A source using a disabled provider must not execute the provider.
	 *
	 * The source remains configured; only the actual import is blocked.
	 *
	 * @return void
	 */
	public function test_disabled_provider_is_not_imported(): void {
		$source_id = $this->create_source();
		update_option( 'now_pages_threadi_provider_stub_enabled', 0 );

		Stub_Provider::$entries = array( $this->make_entry( 'Darf nicht importiert werden.' ) );

		Sources::get_instance()->import();

		$this->assertSame( 0, Stub_Provider::$fetch_count );
		$this->assertCount( 0, $this->get_stored_entries( $source_id ) );
	}

	/**
	 * A source that is not published must not be imported.
	 *
	 * @return void
	 */
	public function test_draft_source_is_not_imported(): void {
		$source_id = $this->create_source( array(), array( 'post_status' => 'draft' ) );

		Stub_Provider::$entries = array( $this->make_entry( 'Ein Eintrag.' ) );

		Sources::get_instance()->import();

		$this->assertSame( 0, Stub_Provider::$fetch_count );
		$this->assertCount( 0, $this->get_stored_entries( $source_id ) );
	}

	/**
	 * A provider returning nothing must not create anything.
	 *
	 * @return void
	 */
	public function test_empty_provider_result_stores_nothing(): void {
		$source_id = $this->create_source();

		Sources::get_instance()->import();

		$this->assertSame( 1, Stub_Provider::$fetch_count );
		$this->assertCount( 0, $this->get_stored_entries( $source_id ) );
	}

	/**
	 * A second run over unchanged data must not duplicate anything.
	 *
	 * This is the case that happens on every scheduled import.
	 *
	 * @return void
	 */
	public function test_second_run_does_not_duplicate_entries(): void {
		$source_id = $this->create_source();

		Stub_Provider::$entries = array(
			$this->make_entry( 'Erster Eintrag.', 'ext-1' ),
			$this->make_entry( 'Zweiter Eintrag.', 'ext-2' ),
		);

		$sources_obj = Sources::get_instance();

		$sources_obj->import();
		$sources_obj->import();

		$this->assertCount( 2, $this->get_stored_entries( $source_id ) );
	}

	/**
	 * A second run must pick up entries that are new since the first one.
	 *
	 * @return void
	 */
	public function test_second_run_imports_new_entries(): void {
		$source_id   = $this->create_source();
		$sources_obj = Sources::get_instance();

		Stub_Provider::$entries = array( $this->make_entry( 'Erster Eintrag.', 'ext-1' ) );

		$sources_obj->import();

		Stub_Provider::$entries = array(
			$this->make_entry( 'Erster Eintrag.', 'ext-1' ),
			$this->make_entry( 'Zweiter Eintrag.', 'ext-2' ),
		);

		$sources_obj->import();

		$this->assertCount( 2, $this->get_stored_entries( $source_id ) );
	}

	/**
	 * The imported entries must belong to the author of their source.
	 *
	 * @return void
	 */
	public function test_imported_entries_belong_to_the_source_author(): void {
		$author_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$source_id = $this->create_source( array(), array( 'post_author' => $author_id ) );

		Stub_Provider::$entries = array( $this->make_entry( 'Ein Eintrag.' ) );

		// no current user, as in a cron run.
		wp_set_current_user( 0 );

		Sources::get_instance()->import();

		$entries = $this->get_stored_entries( $source_id );

		$this->assertSame( $author_id, absint( get_post_field( 'post_author', $entries[0] ) ) );
	}

	/**
	 * Every source must be visited, not only the first one.
	 *
	 * @return void
	 */
	public function test_all_sources_are_imported(): void {
		$first  = $this->create_source( array(), array( 'post_title' => 'First' ) );
		$second = $this->create_source( array(), array( 'post_title' => 'Second' ) );

		Stub_Provider::$entries = array( $this->make_entry( 'Ein Eintrag.', 'ext-1' ) );

		Sources::get_instance()->import();

		$this->assertSame( 2, Stub_Provider::$fetch_count );
		$this->assertCount( 1, $this->get_stored_entries( $first ) );
		$this->assertCount( 1, $this->get_stored_entries( $second ) );
	}

	/**
	 * An entry deleted by hand must not return on the next run.
	 *
	 * @return void
	 */
	public function test_deleted_entry_does_not_return_on_the_next_run(): void {
		$source_id   = $this->create_source();
		$sources_obj = Sources::get_instance();

		Stub_Provider::$entries = array( $this->make_entry( 'Bitte endgültig weg.', 'ext-1' ) );

		$sources_obj->import();

		$entries = $this->get_stored_entries( $source_id );

		wp_delete_post( $entries[0], true );

		$sources_obj->import();

		$this->assertCount( 0, $this->get_stored_entries( $source_id ) );
	}

	/**
	 * The imported entries must end up on the now page.
	 *
	 * @return void
	 */
	public function test_imported_entries_appear_in_the_digest(): void {
		$this->create_source();

		$entry       = $this->make_entry( 'Bin gerade unterwegs.' );
		$entry->date = new DateTimeImmutable( gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - ( 2 * HOUR_IN_SECONDS ) ) );

		Stub_Provider::$entries = array( $entry );

		Sources::get_instance()->import();

		$digest = ( new \NowPagesThreadi\Digests\Digest_Builder() )->build(
			array(
				'period'      => 'live',
				'format'      => 'verbatim',
				'delay_hours' => 0,
			)
		);

		$this->assertStringContainsString( 'Bin gerade unterwegs.', $digest );
	}

	/**
	 * A fresh lock of another run must block the import and stay untouched.
	 *
	 * @return void
	 */
	public function test_fresh_lock_of_another_run_blocks_the_import(): void {
		$this->create_source();

		update_option( Sources::IMPORT_LOCK_OPTION, time() . '|other-run', false );

		Sources::get_instance()->import();

		$this->assertSame( 0, Stub_Provider::$fetch_count );
		$this->assertStringEndsWith( '|other-run', (string) get_option( Sources::IMPORT_LOCK_OPTION ) );

		delete_option( Sources::IMPORT_LOCK_OPTION );
	}

	/**
	 * A stale lock must be replaced, and the lock must be gone after the run.
	 *
	 * @return void
	 */
	public function test_stale_lock_is_replaced_and_released(): void {
		$this->create_source();

		update_option( Sources::IMPORT_LOCK_OPTION, ( time() - DAY_IN_SECONDS ) . '|crashed-run', false );

		Sources::get_instance()->import();

		$this->assertSame( 1, Stub_Provider::$fetch_count );

		wp_cache_delete( Sources::IMPORT_LOCK_OPTION, 'options' );
		$this->assertFalse( get_option( Sources::IMPORT_LOCK_OPTION ) );
	}
}
