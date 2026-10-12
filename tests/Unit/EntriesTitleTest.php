<?php
/**
 * File to test the title of an entry.
 *
 * An entry is stored without a title, its text is its content. In the
 * backend it must still be recognizable, so the title is derived from
 * the text whenever WordPress asks for one.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Tests\Unit;

use ThreadiNowPages\Tests\NowPagesTestCase;

/**
 * Object to test the title of an entry.
 */
class EntriesTitleTest extends NowPagesTestCase {
	/**
	 * Create an entry.
	 *
	 * @param string $text  The text.
	 * @param string $title The stored title.
	 *
	 * @return int
	 */
	private function create_entry( string $text, string $title = '' ): int {
		return self::factory()->post->create(
			array(
				'post_type'    => 'threadi_np_entry',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $text,
			)
		);
	}

	/**
	 * The text of an entry must be shown as its title.
	 *
	 * @return void
	 */
	public function test_the_text_is_the_title(): void {
		$entry_id = $this->create_entry( 'Released version 2 of the app' );

		$this->assertSame( '', get_post( $entry_id )->post_title );
		$this->assertSame( 'Released version 2 of the app', get_the_title( $entry_id ) );
	}

	/**
	 * A long text must be shortened, the list is not the place to read it in full.
	 *
	 * @return void
	 */
	public function test_a_long_text_is_shortened(): void {
		$entry_id = $this->create_entry( 'one two three four five six seven eight nine ten eleven twelve thirteen fourteen' );

		$this->assertSame( 'one two three four five six seven eight nine ten eleven twelve…', get_the_title( $entry_id ) );
	}

	/**
	 * Markup must not end up in the title.
	 *
	 * @return void
	 */
	public function test_the_title_carries_no_markup(): void {
		$entry_id = $this->create_entry( 'Starred <a href="https://example.com/repo">a repository</a>' );

		$this->assertSame( 'Starred a repository', get_the_title( $entry_id ) );
	}

	/**
	 * A stored title must win: older entries written by hand may have one.
	 *
	 * @return void
	 */
	public function test_a_stored_title_wins(): void {
		$entry_id = $this->create_entry( 'The text of the entry', 'A title somebody gave it' );

		$this->assertSame( 'A title somebody gave it', get_the_title( $entry_id ) );
	}

	/**
	 * Nothing else must get a title this way.
	 *
	 * @return void
	 */
	public function test_other_post_types_stay_untouched(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => '',
				'post_content' => 'A post without a title',
			)
		);

		$this->assertSame( '', get_the_title( $post_id ) );
	}

	/**
	 * The editor must not offer a title: it would never be shown on the now page.
	 *
	 * @return void
	 */
	public function test_entries_have_no_title_field(): void {
		$this->assertFalse( post_type_supports( 'threadi_np_entry', 'title' ) );
		$this->assertTrue( post_type_supports( 'threadi_np_entry', 'editor' ) );
	}

	/**
	 * The column must say what it shows, and must not offer to sort by a title which does not exist.
	 *
	 * @return void
	 */
	public function test_the_list_names_the_column_entry(): void {
		$columns = apply_filters(
			'manage_threadi_np_entry_posts_columns',
			array(
				'cb'    => '',
				'title' => 'Title',
				'date'  => 'Date',
			)
		);

		$this->assertSame( 'Entry', $columns['title'] );

		$sortable = apply_filters(
			'manage_edit-threadi_np_entry_sortable_columns',
			array(
				'title' => 'title',
				'date'  => array( 'date', true ),
			)
		);

		$this->assertArrayNotHasKey( 'title', $sortable );
		$this->assertArrayHasKey( 'date', $sortable );
	}
}
