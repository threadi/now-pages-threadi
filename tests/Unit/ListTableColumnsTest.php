<?php
/**
 * File to test the columns of our list tables.
 *
 * These callbacks are the only place where WordPress hands us a post ID
 * straight out of the database, where numeric columns arrive as strings.
 * With declare(strict_types=1) a narrowed parameter type would fatal
 * here — and only here, which is why they deserve their own tests.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Tests\Unit;

use ThreadiNowPages\NowPages\Categories;
use ThreadiNowPages\NowPages\Entries;
use ThreadiNowPages\Providers\Providers;
use ThreadiNowPages\Sources\Sources;
use ThreadiNowPages\Tests\NowPagesTestCase;
use ThreadiNowPages\Tests\Unit\Stubs\Stub_Provider;

/**
 * Object to test the column callbacks.
 */
class ListTableColumnsTest extends NowPagesTestCase {
	/**
	 * Register the stub provider before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Providers::get_instance()->register_provider( new Stub_Provider() );
	}

	/**
	 * Create a source.
	 *
	 * @param string $provider_slug The provider to configure, empty for none.
	 * @param string $language      The language to configure, empty for none.
	 *
	 * @return int
	 */
	private function create_source( string $provider_slug = 'stub', string $language = 'de_DE' ): int {
		$source_id = self::factory()->post->create(
			array(
				'post_type'   => 'threadi_np_source',
				'post_status' => 'publish',
				'post_title'  => 'Test source',
			)
		);

		if ( '' !== $provider_slug ) {
			update_post_meta( $source_id, 'provider', $provider_slug );
		}

		if ( '' !== $language ) {
			update_post_meta( $source_id, 'language', $language );
		}

		return $source_id;
	}

	/**
	 * Create an entry.
	 *
	 * @param int $source_id The source it belongs to, 0 for none.
	 *
	 * @return int
	 */
	private function create_entry( int $source_id = 0 ): int {
		return self::factory()->post->create(
			array(
				'post_type'    => 'threadi_np_entry',
				'post_status'  => 'publish',
				'post_title'   => '',
				'post_content' => 'An entry',
				'post_parent'  => $source_id,
			)
		);
	}

	/**
	 * Render a column of the sources list table.
	 *
	 * The post ID is passed as string on purpose: that is what WordPress
	 * hands over, since WP_Post does not cast the values it read from the
	 * database.
	 *
	 * @param string $column  The column to render.
	 * @param int    $post_id The post to render it for.
	 *
	 * @return string
	 */
	private function render_provider_column( string $column, int $post_id ): string {
		ob_start();

		Sources::get_instance()->render_columns( $column, (string) $post_id );

		return (string) ob_get_clean();
	}

	/**
	 * Render a column of the entries list table.
	 *
	 * @param string $column  The column to render.
	 * @param int    $post_id The post to render it for.
	 *
	 * @return string
	 */
	private function render_source_column( string $column, int $post_id ): string {
		ob_start();

		Entries::get_instance()->render_source_column( $column, (string) $post_id );

		return (string) ob_get_clean();
	}

	/**
	 * The provider column must show the label, not the slug.
	 *
	 * @return void
	 */
	public function test_provider_column_shows_the_label(): void {
		$source_id = $this->create_source();

		$this->assertStringContainsString( 'Stub provider', $this->render_provider_column( 'threadi_now_pages_provider', $source_id ) );
	}

	/**
	 * A source without a provider must show a dash instead of nothing.
	 *
	 * @return void
	 */
	public function test_provider_column_without_a_provider_shows_a_dash(): void {
		$source_id = $this->create_source( '' );

		$this->assertStringContainsString( '—', html_entity_decode( $this->render_provider_column( 'threadi_now_pages_provider', $source_id ) ) );
	}

	/**
	 * An unknown provider must fall back to its raw slug.
	 *
	 * That is the only hint left after an extension was deactivated.
	 *
	 * @return void
	 */
	public function test_provider_column_falls_back_to_the_slug(): void {
		$source_id = $this->create_source( 'gone-with-its-plugin' );

		$this->assertStringContainsString( 'gone-with-its-plugin', $this->render_provider_column( 'threadi_now_pages_provider', $source_id ) );
	}

	/**
	 * The category column must name the category of the source and link to it.
	 *
	 * @return void
	 */
	public function test_category_column_names_the_category(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$source_id = $this->create_source();
		$term_id   = self::factory()->term->create(
			array(
				'taxonomy' => Categories::TAXONOMY,
				'name'     => 'Forum',
			)
		);

		update_post_meta( $source_id, Categories::SOURCE_DEFAULT_META, $term_id );

		$output = $this->render_provider_column( 'threadi_now_pages_category', $source_id );

		$this->assertStringContainsString( '>Forum</a>', $output );
		$this->assertStringContainsString( 'term.php?taxonomy=' . Categories::TAXONOMY, $output );
		$this->assertStringContainsString( 'tag_ID=' . $term_id, $output );
	}

	/**
	 * A user who may not edit categories gets the name without a link.
	 *
	 * @return void
	 */
	public function test_category_column_is_not_linked_for_users_who_may_not_edit_it(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$source_id = $this->create_source();
		$term_id   = self::factory()->term->create(
			array(
				'taxonomy' => Categories::TAXONOMY,
				'name'     => 'Forum',
			)
		);

		update_post_meta( $source_id, Categories::SOURCE_DEFAULT_META, $term_id );

		$this->assertSame( 'Forum', $this->render_provider_column( 'threadi_now_pages_category', $source_id ) );
	}

	/**
	 * A source without a category, or with a category which was deleted, must show a dash.
	 *
	 * @return void
	 */
	public function test_category_column_without_a_category_shows_a_dash(): void {
		$source_id = $this->create_source();

		$this->assertStringContainsString( '—', html_entity_decode( $this->render_provider_column( 'threadi_now_pages_category', $source_id ) ) );

		update_post_meta( $source_id, Categories::SOURCE_DEFAULT_META, 999999 );

		$this->assertStringContainsString( '—', html_entity_decode( $this->render_provider_column( 'threadi_now_pages_category', $source_id ) ) );
	}

	/**
	 * The category column must stand between provider and language.
	 *
	 * @return void
	 */
	public function test_category_column_is_part_of_the_table(): void {
		$columns = Sources::get_instance()->add_columns(
			array(
				'cb'    => '',
				'title' => 'Title',
				'date'  => 'Date',
			)
		);

		$this->assertSame(
			array( 'cb', 'title', 'threadi_now_pages_provider', 'threadi_now_pages_category', 'threadi_now_pages_language', 'threadi_now_pages_entries', 'date' ),
			array_keys( $columns )
		);
	}

	/**
	 * The language column must show a readable name.
	 *
	 * @return void
	 */
	public function test_language_column_shows_a_readable_name(): void {
		$source_id = $this->create_source( 'stub', 'de_DE' );

		$output = $this->render_provider_column( 'threadi_now_pages_language', $source_id );

		$this->assertNotSame( '', trim( $output ) );
		$this->assertStringNotContainsString( 'de_DE', $output );
	}

	/**
	 * A source without a language must show a dash.
	 *
	 * @return void
	 */
	public function test_language_column_without_a_language_shows_a_dash(): void {
		$source_id = $this->create_source( 'stub', '' );

		$this->assertStringContainsString( '—', html_entity_decode( $this->render_provider_column( 'threadi_now_pages_language', $source_id ) ) );
	}

	/**
	 * A column we do not handle must produce nothing.
	 *
	 * @return void
	 */
	public function test_unknown_column_produces_nothing(): void {
		$source_id = $this->create_source();

		$this->assertSame( '', $this->render_provider_column( 'title', $source_id ) );
	}

	/**
	 * The source column of an entry must name its source.
	 *
	 * @return void
	 */
	public function test_source_column_names_the_source(): void {
		$source_id = $this->create_source();
		$entry_id  = $this->create_entry( $source_id );

		$this->assertStringContainsString( 'Test source', $this->render_source_column( 'threadi_np_source', $entry_id ) );
	}

	/**
	 * A manually created entry has no source and must not fail.
	 *
	 * @return void
	 */
	public function test_source_column_of_a_manual_entry_does_not_fail(): void {
		$entry_id = $this->create_entry();

		$output = $this->render_source_column( 'threadi_np_source', $entry_id );

		$this->assertStringNotContainsString( 'Test source', $output );
	}

	/**
	 * A post ID of zero must not produce a fatal error.
	 *
	 * @return void
	 */
	public function test_a_post_id_of_zero_does_not_fail(): void {
		$this->assertSame( '', trim( html_entity_decode( $this->render_provider_column( 'title', 0 ) ) ) );

		// the real columns may print a dash, they must not fatal. reaching
		// the end is the check, the assertion above keeps the test from being risky.
		$this->render_provider_column( 'threadi_now_pages_provider', 0 );
		$this->render_source_column( 'threadi_np_source', 0 );
	}

	/**
	 * The columns must be registered on the list tables.
	 *
	 * @return void
	 */
	public function test_the_columns_are_registered(): void {
		$source_columns = Sources::get_instance()->add_columns( array( 'title' => 'Title' ) );

		$this->assertArrayHasKey( 'threadi_now_pages_provider', $source_columns );
		$this->assertArrayHasKey( 'threadi_now_pages_language', $source_columns );

		$entry_columns = Entries::get_instance()->add_source_column( array( 'title' => 'Title' ) );

		$this->assertArrayHasKey( 'threadi_np_source', $entry_columns );
	}

	/**
	 * WordPress must reach our callbacks through the real action.
	 *
	 * This is the path where the string post ID actually arrives, so it is
	 * worth going through the hook instead of calling the method directly.
	 *
	 * @return void
	 */
	public function test_the_callbacks_are_reachable_through_the_action(): void {
		$source_id = $this->create_source();

		ob_start();

		do_action( 'manage_threadi_np_source_posts_custom_column', 'threadi_now_pages_provider', (string) $source_id );

		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Stub provider', $output );
	}
}
