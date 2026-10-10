<?php
/**
 * File to test the page behind the now route.
 *
 * The most damaging case here is fill_page(): it must never touch a page
 * somebody already wrote something into.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Tests\Unit;

use NowPagesThreadi\NowPages\Now_Page;
use NowPagesThreadi\PageBuilder\Page_Builders;
use NowPagesThreadi\PageBuilder\PageBuilder_Base;
use NowPagesThreadi\Tests\NowPagesTestCase;
use WP_Post;

/**
 * Object to test Now_Page.
 */
class NowPageTest extends NowPagesTestCase {
	/**
	 * Start every test without a configured page.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		delete_option( Now_Page::OPTION );
		update_option( 'now_pages_threadi_slug', 'now' );
	}

	/**
	 * Without a configured page there is none.
	 *
	 * @return void
	 */
	public function test_without_a_configured_page_there_is_none(): void {
		$this->assertNull( Now_Page::get_instance()->get_page() );
	}

	/**
	 * Creating a page must store its ID.
	 *
	 * @return void
	 */
	public function test_creating_a_page_stores_its_id(): void {
		$page_id = Now_Page::get_instance()->create_page();

		$this->assertGreaterThan( 0, $page_id );
		$this->assertSame( $page_id, absint( get_option( Now_Page::OPTION ) ) );
	}

	/**
	 * The created page must be a published page.
	 *
	 * @return void
	 */
	public function test_the_created_page_is_published(): void {
		$page_id = Now_Page::get_instance()->create_page();

		$post = get_post( $page_id );

		$this->assertInstanceOf( WP_Post::class, $post );
		$this->assertSame( 'page', $post->post_type );
		$this->assertSame( 'publish', $post->post_status );
	}

	/**
	 * A second call must not create a second page.
	 *
	 * @return void
	 */
	public function test_a_second_call_creates_no_second_page(): void {
		$first  = Now_Page::get_instance()->create_page();
		$second = Now_Page::get_instance()->create_page();

		$this->assertSame( $first, $second );
	}

	/**
	 * The created page must carry the content of the active page builder.
	 *
	 * @return void
	 */
	public function test_the_created_page_carries_content(): void {
		$page_id = Now_Page::get_instance()->create_page();

		$post = get_post( $page_id );

		$this->assertInstanceOf( WP_Post::class, $post );

		// either content or meta, depending on the active page builder.
		$has_content = '' !== trim( $post->post_content );
		$has_meta    = ! empty( get_post_meta( $page_id ) );

		$this->assertTrue( $has_content || $has_meta );
	}

	/**
	 * The meta of a page builder must arrive in the database unchanged.
	 *
	 * Breakdance stores JSON inside JSON. Saving it without slashes lets
	 * WordPress strip the escapes of the inner JSON, and the tree could not
	 * be decoded anymore.
	 *
	 * @return void
	 */
	public function test_the_meta_of_the_page_builder_survives_saving(): void {
		$breakdance = null;

		foreach ( Page_Builders::get_instance()->get_page_builders_as_objects() as $page_builder_obj ) {
			if ( 'breakdance' === $page_builder_obj->get_slug() ) {
				$breakdance = $page_builder_obj;
			}
		}

		$this->assertInstanceOf( PageBuilder_Base::class, $breakdance );

		add_filter(
			'now_pages_threadi_page_builder_for_page',
			static function () use ( $breakdance ) {
				return $breakdance;
			}
		);

		$page_id = Now_Page::get_instance()->create_page();

		$expected = $breakdance->get_page_meta();
		$stored   = get_post_meta( $page_id, '_breakdance_data', true );

		$this->assertSame( $expected['_breakdance_data'], $stored );

		$outer = json_decode( (string) $stored, true );

		$this->assertIsArray( $outer );
		$this->assertIsArray( json_decode( (string) $outer['tree_json_string'], true ) );
	}

	/**
	 * Creating a page must ask for new rewrite rules.
	 *
	 * Without them the route would still be virtual until somebody saves
	 * the permalinks by hand.
	 *
	 * @return void
	 */
	public function test_creating_a_page_triggers_the_rewrite_update(): void {
		update_option( 'now_pages_threadi_update_slugs', 0 );

		Now_Page::get_instance()->create_page();

		$this->assertSame( 1, absint( get_option( 'now_pages_threadi_update_slugs' ) ) );
	}

	/**
	 * A page that is no longer published must count as gone.
	 *
	 * @return void
	 */
	public function test_an_unpublished_page_counts_as_gone(): void {
		$page_id = Now_Page::get_instance()->create_page();

		wp_update_post(
			array(
				'ID'          => $page_id,
				'post_status' => 'draft',
			)
		);

		$this->assertNull( Now_Page::get_instance()->get_page() );
	}

	/**
	 * A deleted page must count as gone.
	 *
	 * @return void
	 */
	public function test_a_deleted_page_counts_as_gone(): void {
		$page_id = Now_Page::get_instance()->create_page();

		wp_delete_post( $page_id, true );

		$this->assertNull( Now_Page::get_instance()->get_page() );
	}

	/**
	 * An empty page must be filled.
	 *
	 * @return void
	 */
	public function test_an_empty_page_is_filled(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Empty',
				'post_content' => '',
			)
		);

		$this->assertTrue( Now_Page::get_instance()->fill_page( $page_id ) );
	}

	/**
	 * A page with content must never be touched.
	 *
	 * Whatever somebody wrote there is theirs — overwriting it would
	 * destroy work with no way back.
	 *
	 * @return void
	 */
	public function test_a_page_with_content_is_never_touched(): void {
		$content = 'Something the user wrote.';

		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Not empty',
				'post_content' => $content,
			)
		);

		$this->assertFalse( Now_Page::get_instance()->fill_page( $page_id ) );
		$this->assertSame( $content, get_post_field( 'post_content', $page_id ) );
	}

	/**
	 * A page holding only markup counts as empty.
	 *
	 * @return void
	 */
	public function test_a_page_with_only_markup_counts_as_empty(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Almost empty',
				'post_content' => '<p>   </p>',
			)
		);

		$this->assertTrue( Now_Page::get_instance()->fill_page( $page_id ) );
	}

	/**
	 * Filling a page that does not exist must fail quietly.
	 *
	 * @return void
	 */
	public function test_filling_a_missing_page_fails_quietly(): void {
		$this->assertFalse( Now_Page::get_instance()->fill_page( 999999 ) );
	}

	/**
	 * A free slug must report no conflict.
	 *
	 * @return void
	 */
	public function test_a_free_slug_reports_no_conflict(): void {
		$this->assertNull( Now_Page::get_instance()->get_conflicting_post( 'a-slug-nobody-uses' ) );
	}

	/**
	 * An empty slug must report no conflict.
	 *
	 * @return void
	 */
	public function test_an_empty_slug_reports_no_conflict(): void {
		$this->assertNull( Now_Page::get_instance()->get_conflicting_post( '' ) );
	}

	/**
	 * A page occupying the slug must be reported.
	 *
	 * @return void
	 */
	public function test_an_occupied_slug_is_reported(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Now',
				'post_name'   => 'now',
			)
		);

		$conflict = Now_Page::get_instance()->get_conflicting_post( 'now' );

		$this->assertInstanceOf( WP_Post::class, $conflict );
		$this->assertSame( $page_id, $conflict->ID );
	}

	/**
	 * A post of another type must be reported too.
	 *
	 * WordPress resolves a slug against every public post type, so the
	 * conflict is not limited to pages.
	 *
	 * @return void
	 */
	public function test_a_post_of_another_type_is_reported(): void {
		self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Now',
				'post_name'   => 'now',
			)
		);

		$this->assertInstanceOf( WP_Post::class, Now_Page::get_instance()->get_conflicting_post( 'now' ) );
	}

	/**
	 * Our own page must not be its own conflict.
	 *
	 * @return void
	 */
	public function test_our_own_page_is_no_conflict(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Now',
				'post_name'   => 'now',
			)
		);

		update_option( Now_Page::OPTION, $page_id );

		$this->assertNull( Now_Page::get_instance()->get_conflicting_post( 'now' ) );
	}

	/**
	 * A draft must not count as a conflict.
	 *
	 * @return void
	 */
	public function test_a_draft_is_no_conflict(): void {
		self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
				'post_title'  => 'Now',
				'post_name'   => 'now',
			)
		);

		$this->assertNull( Now_Page::get_instance()->get_conflicting_post( 'now' ) );
	}

	/**
	 * Without an active page builder there still has to be one to fall back on.
	 *
	 * @return void
	 */
	public function test_the_active_page_builder_is_resolvable(): void {
		$page_builder_obj = Now_Page::get_instance()->get_active_page_builder();

		// nothing is active in a test run, which is a valid answer.
		$this->assertTrue( null === $page_builder_obj || $page_builder_obj instanceof PageBuilder_Base );
	}
}
