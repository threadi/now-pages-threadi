<?php
/**
 * File to test adding entries via the import funnel.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Tests\Unit;

use DateTimeImmutable;
use NowPagesThreadi\NowPages\Entries;
use NowPagesThreadi\NowPages\Entry;
use NowPagesThreadi\Tests\NowPagesTestCase;
use WP_Query;

/**
 * Object to test Entries::add_entry().
 */
class EntriesAddEntryTest extends NowPagesTestCase {
	/**
	 * Reset everything add_entry() reads before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		delete_option( Entries::OPTION_DELETED );

		// a test request never reaches its end, so forget what earlier tests deleted.
		Entries::get_instance()->forget_pending_deletions();
	}

	/**
	 * Return an entry object ready to be imported.
	 *
	 * @param string $text        The text of the entry.
	 * @param string $external_id The external ID.
	 *
	 * @return Entry
	 */
	private function get_entry( string $text, string $external_id = 'ext-1' ): Entry {
		$entry = new Entry();

		$entry->text        = $text;
		$entry->date        = new DateTimeImmutable( '2026-01-15 10:00:00' );
		$entry->source_slug = 'rss';
		$entry->external_id = $external_id;

		return $entry;
	}

	/**
	 * Return a source post.
	 *
	 * @param array<string,mixed> $args Arguments for the post factory.
	 *
	 * @return int
	 */
	private function get_source( array $args = array() ): int {
		return self::factory()->post->create(
			array_merge(
				array(
					'post_type'  => 'np_threadi_source',
					'post_title' => 'Test source',
				),
				$args
			)
		);
	}

	/**
	 * Return all entries of a source.
	 *
	 * @param int $source_id The ID of the source.
	 *
	 * @return array<int,int>
	 */
	private function get_entries( int $source_id ): array {
		$query = new WP_Query(
			array(
				'post_type'      => 'np_threadi_entry',
				'post_status'    => array( 'any', 'trash' ),
				'post_parent'    => $source_id,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		return array_map( 'absint', $query->posts );
	}

	/**
	 * A new entry must be stored with its text, date and external ID.
	 *
	 * @return void
	 */
	public function test_new_entry_is_stored(): void {
		$source_id = $this->get_source();

		Entries::get_instance()->add_entry( $this->get_entry( 'Bin gerade einkaufen.' ), $source_id );

		$entries = $this->get_entries( $source_id );

		$this->assertCount( 1, $entries );

		$post = get_post( $entries[0] );

		$this->assertSame( 'Bin gerade einkaufen.', $post->post_content );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( '2026-01-15 10:00:00', $post->post_date );
		$this->assertSame( 'ext-1', get_post_meta( $entries[0], 'external_id', true ) );
	}

	/**
	 * The same external ID must not be imported twice for the same source.
	 *
	 * @return void
	 */
	public function test_same_external_id_is_not_imported_twice(): void {
		$source_id = $this->get_source();

		Entries::get_instance()->add_entry( $this->get_entry( 'Erster Durchlauf.' ), $source_id );
		Entries::get_instance()->add_entry( $this->get_entry( 'Zweiter Durchlauf.' ), $source_id );

		$this->assertCount( 1, $this->get_entries( $source_id ) );
	}

	/**
	 * The same external ID from a different source must be imported.
	 *
	 * Feeds are free to reuse IDs, so they are only unique per source.
	 *
	 * @return void
	 */
	public function test_same_external_id_from_another_source_is_imported(): void {
		$first  = $this->get_source( array( 'post_title' => 'First source' ) );
		$second = $this->get_source( array( 'post_title' => 'Second source' ) );

		Entries::get_instance()->add_entry( $this->get_entry( 'Ein Eintrag.' ), $first );
		Entries::get_instance()->add_entry( $this->get_entry( 'Ein Eintrag.' ), $second );

		$this->assertCount( 1, $this->get_entries( $first ) );
		$this->assertCount( 1, $this->get_entries( $second ) );
	}

	/**
	 * An entry in the trash must still block a re-import.
	 *
	 * @return void
	 */
	public function test_trashed_entry_blocks_reimport(): void {
		$source_id    = $this->get_source();
		$entries_obj  = Entries::get_instance();

		$entries_obj->add_entry( $this->get_entry( 'Bitte weg damit.' ), $source_id );

		$entries = $this->get_entries( $source_id );

		wp_trash_post( $entries[0] );

		$entries_obj->add_entry( $this->get_entry( 'Bitte weg damit.' ), $source_id );

		$this->assertCount( 1, $this->get_entries( $source_id ) );
	}

	/**
	 * A permanently deleted entry must not come back on the next import.
	 *
	 * @return void
	 */
	public function test_deleted_entry_is_not_imported_again(): void {
		$source_id   = $this->get_source();
		$entries_obj = Entries::get_instance();

		$entries_obj->add_entry( $this->get_entry( 'Endgültig weg.' ), $source_id );

		$entries = $this->get_entries( $source_id );

		wp_delete_post( $entries[0], true );

		// the deletion is known before it has been saved.
		$entries_obj->add_entry( $this->get_entry( 'Endgültig weg.' ), $source_id );

		$this->assertCount( 0, $this->get_entries( $source_id ) );

		// the deletion has been remembered at the end of the request.
		$entries_obj->save_pending_deletions();

		$this->assertContains( $source_id . '|ext-1', get_option( Entries::OPTION_DELETED, array() ) );
	}

	/**
	 * Deleting several entries must save the list once, not once per entry.
	 *
	 * @return void
	 */
	public function test_bulk_deletion_saves_the_list_once(): void {
		$source_id   = $this->get_source();
		$entries_obj = Entries::get_instance();

		$entries_obj->add_entry( $this->get_entry( 'Eins.', 'ext-1' ), $source_id );
		$entries_obj->add_entry( $this->get_entry( 'Zwei.', 'ext-2' ), $source_id );
		$entries_obj->add_entry( $this->get_entry( 'Drei.', 'ext-3' ), $source_id );

		$writes = 0;
		$count  = static function () use ( &$writes ): void {
			++$writes;
		};

		add_action( 'add_option_' . Entries::OPTION_DELETED, $count );
		add_action( 'update_option_' . Entries::OPTION_DELETED, $count );

		foreach ( $this->get_entries( $source_id ) as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		$this->assertSame( 0, $writes );

		$entries_obj->save_pending_deletions();

		remove_action( 'add_option_' . Entries::OPTION_DELETED, $count );
		remove_action( 'update_option_' . Entries::OPTION_DELETED, $count );

		$this->assertSame( 1, $writes );
		$this->assertCount( 3, get_option( Entries::OPTION_DELETED, array() ) );
	}

	/**
	 * An entry must inherit the author of its source, not the current user.
	 *
	 * The import usually runs via cron, where there is no current user at all.
	 *
	 * @return void
	 */
	public function test_entry_inherits_the_author_of_its_source(): void {
		$author_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$source_id = $this->get_source( array( 'post_author' => $author_id ) );

		// no current user, as in a cron run.
		wp_set_current_user( 0 );

		Entries::get_instance()->add_entry( $this->get_entry( 'Vom Editor importiert.' ), $source_id );

		$entries = $this->get_entries( $source_id );

		$this->assertSame( $author_id, absint( get_post_field( 'post_author', $entries[0] ) ) );
	}

	/**
	 * The language of the source must win over the one of the entry.
	 *
	 * @return void
	 */
	public function test_language_of_the_source_wins(): void {
		$source_id = $this->get_source();

		update_post_meta( $source_id, 'language', 'de_DE' );

		$entry           = $this->get_entry( 'Ein Eintrag.' );
		$entry->language = 'en_US';

		Entries::get_instance()->add_entry( $entry, $source_id );

		$entries = $this->get_entries( $source_id );

		$this->assertSame( 'de_DE', get_post_meta( $entries[0], 'language', true ) );
	}

	/**
	 * Without a language on the source the one of the entry must be used.
	 *
	 * @return void
	 */
	public function test_language_of_the_entry_is_used_as_fallback(): void {
		$source_id = $this->get_source();

		$entry           = $this->get_entry( 'Ein Eintrag.' );
		$entry->language = 'en_US';

		Entries::get_instance()->add_entry( $entry, $source_id );

		$entries = $this->get_entries( $source_id );

		$this->assertSame( 'en_US', get_post_meta( $entries[0], 'language', true ) );
	}

	/**
	 * Every entry must end up with a language, so it is never invisible.
	 *
	 * An entry without this meta silently drops out of every digest.
	 *
	 * @return void
	 */
	public function test_entry_always_gets_a_language(): void {
		$source_id = $this->get_source();

		Entries::get_instance()->add_entry( $this->get_entry( 'Ganz ohne Sprache.' ), $source_id );

		$entries = $this->get_entries( $source_id );

		$this->assertNotSame( '', get_post_meta( $entries[0], 'language', true ) );
	}

	/**
	 * The URL a provider delivered must be stored, it is needed for linking.
	 *
	 * @return void
	 */
	public function test_source_url_is_stored(): void {
		$source_id = $this->get_source();

		$entry             = $this->get_entry( 'Mit Link.' );
		$entry->source_url = 'https://example.com/note/1';

		Entries::get_instance()->add_entry( $entry, $source_id );

		$entries = $this->get_entries( $source_id );

		$this->assertSame( 'https://example.com/note/1', get_post_meta( $entries[0], 'source_url', true ) );
	}

	/**
	 * A date in UTC (as feeds deliver it) must be stored as the same moment.
	 *
	 * The website runs in another timezone than the feed, so the wall time
	 * of the website differs from the one in the feed.
	 *
	 * @return void
	 */
	public function test_a_utc_date_is_stored_as_the_same_moment(): void {
		update_option( 'timezone_string', 'Europe/Berlin' );

		$source_id   = $this->get_source();
		$entry       = $this->get_entry( 'From a feed', 'utc-1' );
		$entry->date = new DateTimeImmutable( '2026-09-27T17:54:57+00:00' );

		Entries::get_instance()->add_entry( $entry, $source_id );

		$ids = $this->get_entries( $source_id );

		$this->assertCount( 1, $ids );
		$this->assertSame( '2026-09-27 17:54:57', get_post_field( 'post_date_gmt', $ids[0] ) );
		$this->assertSame( '2026-09-27 19:54:57', get_post_field( 'post_date', $ids[0] ) );
	}

	/**
	 * A date in the future must not turn the entry into a scheduled post.
	 *
	 * @return void
	 */
	public function test_a_date_in_the_future_is_published_now(): void {
		$source_id   = $this->get_source();
		$entry       = $this->get_entry( 'From the future', 'future-1' );
		$entry->date = new DateTimeImmutable( '+3 days' );

		Entries::get_instance()->add_entry( $entry, $source_id );

		$ids = $this->get_entries( $source_id );

		$this->assertCount( 1, $ids );
		$this->assertSame( 'publish', get_post_status( $ids[0] ) );
		$this->assertLessThanOrEqual( time(), strtotime( get_post_field( 'post_date_gmt', $ids[0] ) . ' UTC' ) );
	}

	/**
	 * A date with an offset of its own must be converted as well.
	 *
	 * @return void
	 */
	public function test_a_date_with_another_offset_is_converted(): void {
		update_option( 'timezone_string', 'Europe/Berlin' );

		$source_id   = $this->get_source();
		$entry       = $this->get_entry( 'From New York', 'ny-1' );
		$entry->date = new DateTimeImmutable( '2026-09-27T10:00:00-04:00' );

		Entries::get_instance()->add_entry( $entry, $source_id );

		$ids = $this->get_entries( $source_id );

		$this->assertCount( 1, $ids );
		$this->assertSame( '2026-09-27 14:00:00', get_post_field( 'post_date_gmt', $ids[0] ) );
		$this->assertSame( '2026-09-27 16:00:00', get_post_field( 'post_date', $ids[0] ) );
	}
}
