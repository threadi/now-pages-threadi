<?php
/**
 * File to test that uninstalling leaves nothing behind.
 *
 * The point of this class is not to check individual keys, but to make a
 * forgotten one fail on its own: every option, user meta and transient
 * carrying our prefix has to be gone afterwards. A new setting added
 * later without a matching cleanup therefore turns this red.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Tests\Unit;

use ThreadiNowPages\Digests\Display_Settings;
use ThreadiNowPages\Digests\Summarizer_Stats;
use ThreadiNowPages\NowPages\Entries;
use ThreadiNowPages\NowPages\Now_Page;
use ThreadiNowPages\Plugin\Setup;
use ThreadiNowPages\Plugin\Uninstallation;
use ThreadiNowPages\Providers\Webhook_Controller;
use ThreadiNowPages\Tests\NowPagesTestCase;
use WP_Post;
use WP_Query;

/**
 * Object to test Uninstallation.
 */
class UninstallationTest extends NowPagesTestCase {
	/**
	 * The user whose meta is checked.
	 *
	 * @var int
	 */
	private int $user_id = 0;

	/**
	 * Fill the installation with everything the plugin can create.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $this->user_id );

		$this->create_leftovers();
	}

	/**
	 * Create one of everything the plugin stores.
	 *
	 * @return void
	 */
	private function create_leftovers(): void {
		// the settings written outside the settings library.
		update_option( 'threadi_now_pages_slug', 'now' );
		update_option( 'threadi_now_pages_update_slugs', 1 );
		update_option( 'threadi_now_pages_log_error_count', 3 );
		update_option( 'threadi_now_pages_process_values', array( 'foo' => 'bar' ) );
		update_option( 'threadi_now_pages_schedules', array( 'foo' ) );
		update_option( 'threadi_now_pages_email_report_last_run', '2026-09-01 10:00:00' );
		update_option( 'threadi_now_pages_provider_webhook_enabled', 1 );
		update_option( Webhook_Controller::OPTION_TOKEN, 'a-token' );
		update_option( Summarizer_Stats::OPTION, array( '2026-09-01' => array() ) );
		update_option( Entries::OPTION_DELETED, array( '1|ext-1' ) );

		// a page backing the route.
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Now',
			)
		);

		update_option( Now_Page::OPTION, $page_id );

		// the state of a setup run in progress.
		\easySetupForWordPress\Setup::get_instance()->set_user_values( true );
		\easySetupForWordPress\Setup::get_instance()->set_field_value( Setup::get_instance()->get_setup_name(), Setup::OPTION_PRESET, 'mastodon' );

		// the display settings of a user.
		foreach ( Display_Settings::user_meta_keys() as $meta_key ) {
			update_user_meta( $this->user_id, $meta_key, 1 );
		}

		// the marker of the email about an empty now page.
		update_user_meta( $this->user_id, \ThreadiNowPages\Plugin\Emails\EmptyNowPages::USER_META_SENT, 1 );

		// a source with the state a provider left behind.
		$source_id = self::factory()->post->create(
			array(
				'post_type'   => 'threadi_np_source',
				'post_status' => 'publish',
				'post_title'  => 'Test source',
			)
		);

		update_post_meta( $source_id, 'provider', 'rss' );
		update_post_meta( $source_id, 'threadi_now_pages_provider_state', array( 'etag_x' => '"abc"' ) );
		update_post_meta( $source_id, 'threadi_now_pages_provider_last_run', time() );

		// an entry belonging to it.
		self::factory()->post->create(
			array(
				'post_type'    => 'threadi_np_entry',
				'post_status'  => 'publish',
				'post_title'   => '',
				'post_content' => 'An entry',
				'post_parent'  => $source_id,
			)
		);

		// a category.
		wp_insert_term( 'Test category', 'threadi_now_pages_category' );

		// the caches a digest and a provider leave behind.
		set_transient( 'threadi_now_pages_summary_abc', 'a cached summary', HOUR_IN_SECONDS );
		set_transient( 'threadi_now_pages_webhook_fails_abc', 5, HOUR_IN_SECONDS );

		// the lock of an AI summary, left behind by a broken request.
		add_option( 'threadi_now_pages_summary_abc_lock', (string) time(), '', false );
	}

	/**
	 * Return every option name carrying our prefix.
	 *
	 * @return array<int,string>
	 */
	private function get_remaining_options(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- checking the database is the point of this test.
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( 'threadi_now_pages' ) . '%'
			)
		);

		return array_map( 'strval', $names );
	}

	/**
	 * Return every user meta key carrying our prefix.
	 *
	 * @return array<int,string>
	 */
	private function get_remaining_user_meta(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- checking the database is the point of this test.
		$keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
				$wpdb->esc_like( 'threadi_now_pages' ) . '%',
				$wpdb->esc_like( 'esfw_values_' ) . '%'
			)
		);

		return array_map( 'strval', $keys );
	}

	/**
	 * Return every transient carrying our prefix.
	 *
	 * @return array<int,string>
	 */
	private function get_remaining_transients(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- checking the database is the point of this test.
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_threadi_now_pages' ) . '%',
				$wpdb->esc_like( '_transient_timeout_threadi_now_pages' ) . '%'
			)
		);

		return array_map( 'strval', $names );
	}

	/**
	 * Return the posts of our own post types.
	 *
	 * @return array<int,int>
	 */
	private function get_remaining_posts(): array {
		$query = new WP_Query(
			array(
				'post_type'      => array( 'threadi_np_entry', 'threadi_np_source' ),
				'post_status'    => array( 'any', 'trash', 'auto-draft' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		return array_map( 'absint', $query->posts );
	}

	/**
	 * The preparation itself must have created something.
	 *
	 * Without this the other tests would pass on an empty installation and
	 * prove nothing at all.
	 *
	 * @return void
	 */
	public function test_the_preparation_created_something(): void {
		$this->assertNotEmpty( $this->get_remaining_options() );
		$this->assertNotEmpty( $this->get_remaining_user_meta() );
		$this->assertNotEmpty( $this->get_remaining_transients() );
		$this->assertNotEmpty( $this->get_remaining_posts() );
	}

	/**
	 * No option of ours may survive the uninstallation.
	 *
	 * @return void
	 */
	public function test_no_option_survives(): void {
		Uninstallation::get_instance()->run( true );

		$this->assertSame(
			array(),
			$this->get_remaining_options(),
			'These options are left behind: ' . implode( ', ', $this->get_remaining_options() )
		);
	}

	/**
	 * No user meta of ours may survive the uninstallation.
	 *
	 * @return void
	 */
	public function test_no_user_meta_survives(): void {
		Uninstallation::get_instance()->run( true );

		$this->assertSame(
			array(),
			$this->get_remaining_user_meta(),
			'These user meta keys are left behind: ' . implode( ', ', $this->get_remaining_user_meta() )
		);
	}

	/**
	 * No transient of ours may survive the uninstallation.
	 *
	 * @return void
	 */
	public function test_no_transient_survives(): void {
		Uninstallation::get_instance()->run( true );

		$this->assertSame(
			array(),
			$this->get_remaining_transients(),
			'These transients are left behind: ' . implode( ', ', $this->get_remaining_transients() )
		);
	}

	/**
	 * No entry and no source may survive the uninstallation.
	 *
	 * @return void
	 */
	public function test_no_post_survives(): void {
		Uninstallation::get_instance()->run( true );

		$this->assertSame( array(), $this->get_remaining_posts() );
	}

	/**
	 * No category of ours may survive the uninstallation.
	 *
	 * @return void
	 */
	public function test_no_category_survives(): void {
		Uninstallation::get_instance()->run( true );

		$terms = get_terms(
			array(
				'taxonomy'   => 'threadi_now_pages_category',
				'hide_empty' => false,
			)
		);

		// a gone taxonomy answers with an error, which is fine here.
		if ( is_wp_error( $terms ) ) {
			$this->assertSame( 'invalid_taxonomy', $terms->get_error_code() );

			return;
		}

		$this->assertSame( array(), $terms );
	}

	/**
	 * The page backing the route must stay.
	 *
	 * It was created by the plugin, but it is a normal page the user may
	 * have filled with their own content — deleting it would destroy work.
	 *
	 * @return void
	 */
	public function test_the_page_is_not_deleted(): void {
		$page_id = absint( get_option( Now_Page::OPTION ) );

		$this->assertGreaterThan( 0, $page_id );

		Uninstallation::get_instance()->run( true );

		$this->assertInstanceOf( \WP_Post::class, get_post( $page_id ) );
	}

	/**
	 * Posts of other plugins must stay untouched.
	 *
	 * @return void
	 */
	public function test_foreign_posts_are_not_deleted(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'A regular post',
			)
		);

		Uninstallation::get_instance()->run( true );

		$this->assertInstanceOf( \WP_Post::class, get_post( $post_id ) );
	}

	/**
	 * Options of other plugins must stay untouched.
	 *
	 * @return void
	 */
	public function test_foreign_options_are_not_deleted(): void {
		update_option( 'some_other_plugin_setting', 'keep me' );

		Uninstallation::get_instance()->run( true );

		$this->assertSame( 'keep me', get_option( 'some_other_plugin_setting' ) );
	}

	/**
	 * The completion markers of other setups must stay untouched.
	 *
	 * esfw_completed is shared with every other plugin using the same
	 * setup library.
	 *
	 * @return void
	 */
	public function test_other_setups_stay_completed(): void {
		update_option( 'esfw_completed', array( 'threadi-now-pages', 'some-other-plugin' ) );

		Uninstallation::get_instance()->run( true );

		$completed = get_option( 'esfw_completed', array() );

		$this->assertIsArray( $completed );
		$this->assertContains( 'some-other-plugin', $completed );
		$this->assertNotContains( 'threadi-now-pages', $completed );
	}

	/**
	 * Test uninstallation oin multisite.
	 *
	 * @return void
	 */
	public function test_reset_keeps_the_other_sites_of_a_network(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Only relevant in a multisite.' );
		}

		$other_blog = self::factory()->blog->create();
		switch_to_blog( $other_blog );
		$entry_id = self::factory()->post->create( array( 'post_type' => Entries::get_instance()->get_slug() ) );
		restore_current_blog();

		Uninstallation::get_instance()->run();

		switch_to_blog( $other_blog );
		$this->assertInstanceOf( WP_Post::class, get_post( $entry_id ) );
		restore_current_blog();
	}
}
