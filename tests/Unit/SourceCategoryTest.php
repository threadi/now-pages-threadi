<?php
/**
 * File to test that the entries of a source follow the category of this source.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Tests\Unit;

use NowPagesThreadi\Dependencies\easyTransientsForWordPress\Transients;
use NowPagesThreadi\NowPages\Categories;
use NowPagesThreadi\Providers\Providers;
use NowPagesThreadi\Sources\Sources;
use NowPagesThreadi\Tests\NowPagesTestCase;
use NowPagesThreadi\Tests\Unit\Stubs\Stub_Provider;

/**
 * Object to test Categories::assign_to_source_entries() and its use when a source is saved.
 */
class SourceCategoryTest extends NowPagesTestCase {
	/**
	 * The name of the hint shown after entries were moved.
	 */
	private const HINT = 'now_pages_threadi_source_category';

	/**
	 * The source.
	 *
	 * @var int
	 */
	private int $source_id = 0;

	/**
	 * Three categories.
	 *
	 * @var array<string,int>
	 */
	private array $terms = array();

	/**
	 * Create a source and the categories.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Stub_Provider::reset();

		update_option( 'now_pages_threadi_provider_stub_enabled', 1 );

		Providers::get_instance()->register_provider( new Stub_Provider() );

		$this->source_id = $this->create_source();

		foreach ( array( 'Forum', 'Translations', 'Code' ) as $name ) {
			$this->terms[ $name ] = self::factory()->term->create(
				array(
					'taxonomy' => Categories::TAXONOMY,
					'name'     => $name,
				)
			);
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		Transients::get_instance()->get_transient_by_name( self::HINT )->delete();
	}

	/**
	 * Clean up the request between tests.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$_POST = array();

		Transients::get_instance()->get_transient_by_name( self::HINT )->delete();

		parent::tear_down();
	}

	/**
	 * Create a source.
	 *
	 * @return int
	 */
	private function create_source(): int {
		return self::factory()->post->create(
			array(
				'post_type'   => 'np_threadi_source',
				'post_status' => 'publish',
				'post_title'  => 'Test source',
			)
		);
	}

	/**
	 * Create an entry of a source.
	 *
	 * @param int    $source_id The source (0 = written by hand).
	 * @param int    $term_id   The category (0 = none).
	 * @param string $status    The post status.
	 *
	 * @return int
	 */
	private function create_entry( int $source_id, int $term_id = 0, string $status = 'publish' ): int {
		$entry_id = self::factory()->post->create(
			array(
				'post_type'    => 'np_threadi_entry',
				'post_status'  => $status,
				'post_parent'  => $source_id,
				'post_content' => 'An entry.',
			)
		);

		Categories::get_instance()->assign_to_entry( $entry_id, $term_id );

		return $entry_id;
	}

	/**
	 * Return the IDs of the categories of an entry.
	 *
	 * @param int $entry_id The entry.
	 *
	 * @return int[]
	 */
	private function get_categories( int $entry_id ): array {
		$term_ids = wp_get_object_terms( $entry_id, Categories::TAXONOMY, array( 'fields' => 'ids' ) );

		return array_map( 'absint', (array) $term_ids );
	}

	/**
	 * Simulate the submitted form of the source.
	 *
	 * @param array<string,mixed> $fields The submitted fields.
	 *
	 * @return void
	 */
	private function save( array $fields ): void {
		$_POST = array_merge(
			array(
				'now_pages_threadi_source_form' => '1',
				'now-pages-threadi-provider'    => 'stub',
				'stub_url'                      => 'https://example.com/feed',
			),
			$fields
		);

		Sources::get_instance()->save_post( $this->source_id, get_post( $this->source_id ), true );
	}

	/**
	 * A category which is assigned later reaches the entries which are already there.
	 *
	 * @return void
	 */
	public function test_a_category_assigned_later_reaches_the_existing_entries(): void {
		$first  = $this->create_entry( $this->source_id );
		$second = $this->create_entry( $this->source_id );

		$changed = Categories::get_instance()->assign_to_source_entries( $this->source_id, $this->terms['Forum'] );

		$this->assertSame( 2, $changed );
		$this->assertSame( array( $this->terms['Forum'] ), $this->get_categories( $first ) );
		$this->assertSame( array( $this->terms['Forum'] ), $this->get_categories( $second ) );
	}

	/**
	 * A changed category moves every entry of the source, whatever category it had.
	 *
	 * @return void
	 */
	public function test_a_changed_category_moves_every_entry_of_the_source(): void {
		$with_previous = $this->create_entry( $this->source_id, $this->terms['Forum'] );
		$with_other    = $this->create_entry( $this->source_id, $this->terms['Code'] );
		$without       = $this->create_entry( $this->source_id );

		$changed = Categories::get_instance()->assign_to_source_entries( $this->source_id, $this->terms['Translations'], $this->terms['Forum'] );

		$this->assertSame( 3, $changed );

		foreach ( array( $with_previous, $with_other, $without ) as $entry_id ) {
			$this->assertSame( array( $this->terms['Translations'] ), $this->get_categories( $entry_id ) );
		}
	}

	/**
	 * An entry which is already in the category is not counted as changed.
	 *
	 * @return void
	 */
	public function test_an_entry_already_in_the_category_is_not_counted(): void {
		$this->create_entry( $this->source_id, $this->terms['Forum'] );
		$this->create_entry( $this->source_id );

		$this->assertSame( 1, Categories::get_instance()->assign_to_source_entries( $this->source_id, $this->terms['Forum'] ) );
	}

	/**
	 * The entries of other sources and entries written by hand stay where they are.
	 *
	 * @return void
	 */
	public function test_other_entries_are_not_touched(): void {
		$other_source = $this->create_source();
		$foreign      = $this->create_entry( $other_source, $this->terms['Code'] );
		$manual       = $this->create_entry( 0, $this->terms['Code'] );

		$this->create_entry( $this->source_id );

		Categories::get_instance()->assign_to_source_entries( $this->source_id, $this->terms['Forum'] );

		$this->assertSame( array( $this->terms['Code'] ), $this->get_categories( $foreign ) );
		$this->assertSame( array( $this->terms['Code'] ), $this->get_categories( $manual ) );
	}

	/**
	 * An entry in the trash follows as well: restored, it would come back with the old category.
	 *
	 * @return void
	 */
	public function test_entries_in_the_trash_follow_as_well(): void {
		$trashed = $this->create_entry( $this->source_id, $this->terms['Forum'], 'trash' );
		$draft   = $this->create_entry( $this->source_id, $this->terms['Forum'], 'draft' );

		Categories::get_instance()->assign_to_source_entries( $this->source_id, $this->terms['Code'], $this->terms['Forum'] );

		$this->assertSame( array( $this->terms['Code'] ), $this->get_categories( $trashed ) );
		$this->assertSame( array( $this->terms['Code'] ), $this->get_categories( $draft ) );
	}

	/**
	 * Without a category the source takes back only what it gave.
	 *
	 * @return void
	 */
	public function test_a_removed_category_takes_back_only_what_the_source_gave(): void {
		$with_previous = $this->create_entry( $this->source_id, $this->terms['Forum'] );
		$with_other    = $this->create_entry( $this->source_id, $this->terms['Code'] );

		$changed = Categories::get_instance()->assign_to_source_entries( $this->source_id, 0, $this->terms['Forum'] );

		$this->assertSame( 1, $changed );
		$this->assertSame( array(), $this->get_categories( $with_previous ) );
		$this->assertSame( array( $this->terms['Code'] ), $this->get_categories( $with_other ) );
	}

	/**
	 * Nothing happens if the category did not change or does not exist.
	 *
	 * @return void
	 */
	public function test_nothing_happens_without_a_change_or_with_an_unknown_category(): void {
		$entry_id = $this->create_entry( $this->source_id, $this->terms['Code'] );

		$this->assertSame( 0, Categories::get_instance()->assign_to_source_entries( $this->source_id, $this->terms['Forum'], $this->terms['Forum'] ) );
		$this->assertSame( 0, Categories::get_instance()->assign_to_source_entries( $this->source_id, 999999 ) );
		$this->assertSame( 0, Categories::get_instance()->assign_to_source_entries( 0, $this->terms['Forum'] ) );
		$this->assertSame( array( $this->terms['Code'] ), $this->get_categories( $entry_id ) );
	}

	/**
	 * The number of entries in a category is right afterwards.
	 *
	 * @return void
	 */
	public function test_the_counts_of_the_categories_are_updated(): void {
		$this->create_entry( $this->source_id, $this->terms['Forum'] );
		$this->create_entry( $this->source_id, $this->terms['Forum'] );

		Categories::get_instance()->assign_to_source_entries( $this->source_id, $this->terms['Code'], $this->terms['Forum'] );

		$this->assertSame( 0, get_term( $this->terms['Forum'], Categories::TAXONOMY )->count );
		$this->assertSame( 2, get_term( $this->terms['Code'], Categories::TAXONOMY )->count );
	}

	/**
	 * Saving a source with another category moves its entries and says so, once.
	 *
	 * @return void
	 */
	public function test_saving_a_source_with_another_category_moves_its_entries(): void {
		update_post_meta( $this->source_id, Categories::SOURCE_DEFAULT_META, $this->terms['Forum'] );

		$first  = $this->create_entry( $this->source_id, $this->terms['Forum'] );
		$second = $this->create_entry( $this->source_id, $this->terms['Forum'] );

		$this->save( array( 'now-pages-threadi-default-category' => (string) $this->terms['Translations'] ) );

		$this->assertSame( $this->terms['Translations'], absint( get_post_meta( $this->source_id, Categories::SOURCE_DEFAULT_META, true ) ) );
		$this->assertSame( array( $this->terms['Translations'] ), $this->get_categories( $first ) );
		$this->assertSame( array( $this->terms['Translations'] ), $this->get_categories( $second ) );

		$transient = Transients::get_instance()->get_transient_by_name( self::HINT );

		$this->assertTrue( $transient->is_set() );
		$this->assertStringContainsString( '2 entries of this source have been moved to the category "Translations".', html_entity_decode( $transient->get_message() ) );
	}

	/**
	 * Saving a source without changing its category leaves an entry where the user put it.
	 *
	 * @return void
	 */
	public function test_saving_without_a_change_keeps_an_entry_moved_by_hand(): void {
		update_post_meta( $this->source_id, Categories::SOURCE_DEFAULT_META, $this->terms['Forum'] );

		$moved_by_hand = $this->create_entry( $this->source_id, $this->terms['Code'] );

		$this->save( array( 'now-pages-threadi-default-category' => (string) $this->terms['Forum'] ) );

		$this->assertSame( array( $this->terms['Code'] ), $this->get_categories( $moved_by_hand ) );
		$this->assertFalse( Transients::get_instance()->get_transient_by_name( self::HINT )->is_set() );
	}

	/**
	 * A form without the category field does not take the category from the entries.
	 *
	 * The field is disabled as long as no provider is chosen, a disabled
	 * field is not sent.
	 *
	 * @return void
	 */
	public function test_a_form_without_the_field_does_not_touch_the_entries(): void {
		update_post_meta( $this->source_id, Categories::SOURCE_DEFAULT_META, $this->terms['Forum'] );

		$entry_id = $this->create_entry( $this->source_id, $this->terms['Forum'] );

		$this->save( array() );

		$this->assertSame( array( $this->terms['Forum'] ), $this->get_categories( $entry_id ) );
	}

	/**
	 * Removing the category of a source is reported with the name of the category which is gone.
	 *
	 * @return void
	 */
	public function test_removing_the_category_is_reported(): void {
		update_post_meta( $this->source_id, Categories::SOURCE_DEFAULT_META, $this->terms['Forum'] );

		$entry_id = $this->create_entry( $this->source_id, $this->terms['Forum'] );

		$this->save( array( 'now-pages-threadi-default-category' => '0' ) );

		$this->assertSame( array(), $this->get_categories( $entry_id ) );
		$this->assertStringContainsString( 'The category "Forum" has been removed from 1 entry of this source.', html_entity_decode( Transients::get_instance()->get_transient_by_name( self::HINT )->get_message() ) );
	}

	/**
	 * The form links to the place where the categories are managed.
	 *
	 * @return void
	 */
	public function test_the_form_links_to_the_categories(): void {
		ob_start();
		Sources::get_instance()->show_provider_additional_settings( get_post( $this->source_id ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'edit-tags.php?taxonomy=' . Categories::TAXONOMY . '&amp;post_type=np_threadi_entry"', $html );
		$this->assertStringContainsString( 'Manage the categories', $html );
	}

	/**
	 * A user who may not manage categories gets no link.
	 *
	 * @return void
	 */
	public function test_the_link_is_hidden_from_users_who_may_not_manage_categories(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$this->assertSame( '', Categories::get_instance()->get_management_url() );

		ob_start();
		Sources::get_instance()->show_provider_additional_settings( get_post( $this->source_id ) );
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'edit-tags.php', $html );
	}

	/**
	 * The term meta of a category is not readable via REST for visitors, but for users who manage categories.
	 *
	 * @return void
	 */
	public function test_the_term_meta_is_hidden_in_rest_from_visitors(): void {
		// the test case unregisters all meta keys after each test: register them again.
		Categories::get_instance()->register();

		update_term_meta( $this->terms['Forum'], Categories::SENTENCE_TEMPLATE_META, 'I posted %s' );
		update_term_meta( $this->terms['Forum'], Categories::OVERRIDE_TEXT_META, 'Secret summary' );

		$route = '/wp/v2/' . Categories::TAXONOMY . '/' . $this->terms['Forum'];

		wp_set_current_user( 0 );
		$data = rest_do_request( new \WP_REST_Request( 'GET', $route ) )->get_data();

		$this->assertIsArray( $data );
		$this->assertArrayNotHasKey( Categories::SENTENCE_TEMPLATE_META, (array) ( $data['meta'] ?? array() ) );
		$this->assertArrayNotHasKey( Categories::OVERRIDE_TEXT_META, (array) ( $data['meta'] ?? array() ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$data = rest_do_request( new \WP_REST_Request( 'GET', $route ) )->get_data();

		$this->assertSame( 'Secret summary', $data['meta'][ Categories::OVERRIDE_TEXT_META ] );
	}
}
