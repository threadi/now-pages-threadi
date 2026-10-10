<?php
/**
 * File to test the sharing of the now page in the Fediverse.
 *
 * The ActivityPub plugin itself is not part of these tests: what matters
 * here is which posts are handed to it, and when. That is where the
 * privacy delay has to hold - what has reached other servers cannot be
 * taken back.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Tests\Unit;

use NowPagesThreadi\Fediverse\Fediverse;
use NowPagesThreadi\Presets\Presets;
use NowPagesThreadi\Tests\NowPagesTestCase;
use WP_Query;

/**
 * Object to test Fediverse.
 */
class FediverseTest extends NowPagesTestCase {
	/**
	 * Start every test with a public now page and something that federates.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		add_filter( 'now_pages_threadi_fediverse_is_available', '__return_true' );
		add_filter( 'now_pages_threadi_is_public', '__return_true' );

		// no delay unless a test asks for one, so the tests do not depend on the time of day.
		update_option( 'now_pages_threadi_delay_hours', 0 );
		update_option( 'now_pages_threadi_frontend_format', 'verbatim' );
	}

	/**
	 * Switch sharing on, as if it had been switched on some days ago.
	 *
	 * @param string $mode The mode.
	 * @param int    $days How many days ago.
	 *
	 * @return void
	 */
	private function share( string $mode, int $days = 3 ): void {
		update_option( Fediverse::OPTION_MODE, $mode );
		update_option( Fediverse::OPTION_SINCE, time() - ( $days * DAY_IN_SECONDS ) );
	}

	/**
	 * Create an entry.
	 *
	 * @param string $text      The text.
	 * @param int    $age       Its age in seconds.
	 * @param int    $source_id The ID of its source.
	 *
	 * @return int
	 */
	private function create_entry( string $text, int $age, int $source_id = 0 ): int {
		return self::factory()->post->create(
			array(
				'post_type'     => 'np_threadi_entry',
				'post_status'   => 'publish',
				'post_title'    => '',
				'post_content'  => $text,
				'post_parent'   => $source_id,
				'post_date'     => wp_date( 'Y-m-d H:i:s', time() - $age ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - $age ),
				'meta_input'    => array( 'language' => substr( get_locale(), 0, 5 ) ),
			)
		);
	}

	/**
	 * Create an entry in the middle of yesterday.
	 *
	 * @param string $text The text.
	 *
	 * @return int
	 */
	private function create_entry_of_yesterday( string $text ): int {
		$noon = current_datetime()->setTime( 12, 0 )->modify( '-1 day' );

		return self::factory()->post->create(
			array(
				'post_type'     => 'np_threadi_entry',
				'post_status'   => 'publish',
				'post_title'    => '',
				'post_content'  => $text,
				'post_date'     => $noon->format( 'Y-m-d H:i:s' ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $noon->getTimestamp() ),
				'meta_input'    => array( 'language' => substr( get_locale(), 0, 5 ) ),
			)
		);
	}

	/**
	 * Create a source.
	 *
	 * @param string $preset The preset it was created from.
	 *
	 * @return int
	 */
	private function create_source( string $preset = '' ): int {
		return self::factory()->post->create(
			array(
				'post_type'   => 'np_threadi_source',
				'post_status' => 'publish',
				'post_title'  => 'A source',
				'meta_input'  => array(
					'provider'           => 'rss',
					Presets::SOURCE_META => $preset,
				),
			)
		);
	}

	/**
	 * Return the updates which exist.
	 *
	 * @param string $status The status.
	 *
	 * @return array<int,\WP_Post>
	 */
	private function get_updates( string $status = 'publish' ): array {
		$query = new WP_Query(
			array(
				'post_type'      => 'np_threadi_update',
				'post_status'    => $status,
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		return $query->posts;
	}

	/**
	 * Nothing must be shared unless somebody switched it on.
	 *
	 * @return void
	 */
	public function test_nothing_is_shared_by_default(): void {
		$this->create_entry( 'Wrote a test', DAY_IN_SECONDS );

		$this->assertFalse( Fediverse::get_instance()->is_enabled() );
		$this->assertSame( 0, Fediverse::get_instance()->run() );
		$this->assertSame( array(), $this->get_updates() );
	}

	/**
	 * Without the ActivityPub plugin nothing must be created: nobody would deliver it.
	 *
	 * @return void
	 */
	public function test_nothing_is_shared_without_activitypub(): void {
		remove_filter( 'now_pages_threadi_fediverse_is_available', '__return_true' );

		$this->share( Fediverse::MODE_ENTRIES );
		$this->create_entry( 'Wrote a test', HOUR_IN_SECONDS );

		$this->assertSame( defined( 'ACTIVITYPUB_PLUGIN_VERSION' ), Fediverse::get_instance()->is_enabled() );

		if ( ! defined( 'ACTIVITYPUB_PLUGIN_VERSION' ) ) {
			$this->assertSame( 0, Fediverse::get_instance()->run() );
			$this->assertSame( array(), $this->get_updates() );
		}
	}

	/**
	 * What visitors cannot see on the website must not be sent anywhere else.
	 *
	 * @return void
	 */
	public function test_nothing_is_shared_of_a_hidden_now_page(): void {
		remove_filter( 'now_pages_threadi_is_public', '__return_true' );
		add_filter( 'now_pages_threadi_is_public', '__return_false' );

		$this->share( Fediverse::MODE_ENTRIES );
		$this->create_entry( 'Wrote a test', HOUR_IN_SECONDS );

		$this->assertSame( 0, Fediverse::get_instance()->run() );
		$this->assertSame( array(), $this->get_updates() );
	}

	/**
	 * Switching it on must not send what happened before.
	 *
	 * @return void
	 */
	public function test_switching_on_shares_nothing_from_before(): void {
		$this->create_entry( 'Something from this morning', 2 * HOUR_IN_SECONDS );
		$this->create_entry_of_yesterday( 'Something from yesterday' );

		foreach ( array( Fediverse::MODE_ENTRIES, Fediverse::MODE_DIGEST ) as $mode ) {
			update_option( Fediverse::OPTION_MODE, $mode );

			$this->assertEqualsWithDelta( time(), (int) get_option( Fediverse::OPTION_SINCE ), 5 );
			$this->assertSame( 0, Fediverse::get_instance()->run() );
		}

		$this->assertSame( array(), $this->get_updates() );
	}

	/**
	 * A single entry must become one update with its text.
	 *
	 * @return void
	 */
	public function test_a_single_entry_is_shared(): void {
		$this->share( Fediverse::MODE_ENTRIES );

		$entry_id = $this->create_entry( 'Released version 2 of the app', HOUR_IN_SECONDS );

		$this->assertSame( 1, Fediverse::get_instance()->run() );

		$updates = $this->get_updates();

		$this->assertCount( 1, $updates );
		$this->assertStringContainsString( 'Released version 2 of the app', $updates[0]->post_content );
		$this->assertSame( '', $updates[0]->post_title );
		$this->assertSame( 'closed', $updates[0]->comment_status );
		$this->assertSame( $updates[0]->ID, (int) get_post_meta( $entry_id, Fediverse::META_UPDATE, true ) );
		$this->assertSame( $entry_id, (int) get_post_meta( $updates[0]->ID, Fediverse::META_ENTRY, true ) );
	}

	/**
	 * An entry must be shared once, however often the schedule runs.
	 *
	 * @return void
	 */
	public function test_an_entry_is_shared_only_once(): void {
		$this->share( Fediverse::MODE_ENTRIES );
		$this->create_entry( 'Released version 2 of the app', HOUR_IN_SECONDS );

		Fediverse::get_instance()->run();

		$this->assertSame( 0, Fediverse::get_instance()->run() );
		$this->assertCount( 1, $this->get_updates() );
	}

	/**
	 * An entry within the privacy delay must not be shared - and must be as soon as the delay is over.
	 *
	 * @return void
	 */
	public function test_the_privacy_delay_holds_for_single_entries(): void {
		update_option( 'now_pages_threadi_delay_hours', 6 );

		$this->share( Fediverse::MODE_ENTRIES );

		$this->create_entry( 'Still too fresh', 2 * HOUR_IN_SECONDS );
		$this->create_entry( 'Old enough', 7 * HOUR_IN_SECONDS );

		$this->assertSame( 1, Fediverse::get_instance()->run() );

		$updates = $this->get_updates();

		$this->assertCount( 1, $updates );
		$this->assertStringContainsString( 'Old enough', $updates[0]->post_content );

		// the delay of the fresh one is over.
		update_option( 'now_pages_threadi_delay_hours', 1 );
		\NowPagesThreadi\Digests\Digest_Builder::reset_cache();

		$this->assertSame( 1, Fediverse::get_instance()->run() );
		$this->assertCount( 2, $this->get_updates() );
	}

	/**
	 * What comes from Mastodon must not be sent back to the Fediverse.
	 *
	 * @return void
	 */
	public function test_entries_from_mastodon_are_not_sent_back(): void {
		$this->share( Fediverse::MODE_ENTRIES );

		$this->create_entry( 'A post I wrote on Mastodon', HOUR_IN_SECONDS, $this->create_source( 'mastodon' ) );
		$this->create_entry( 'A commit', HOUR_IN_SECONDS, $this->create_source( 'github' ) );

		$this->assertSame( 1, Fediverse::get_instance()->run() );
		$this->assertStringContainsString( 'A commit', $this->get_updates()[0]->post_content );
	}

	/**
	 * The choice made on a source must win in both directions.
	 *
	 * @return void
	 */
	public function test_the_choice_on_a_source_wins(): void {
		$this->share( Fediverse::MODE_ENTRIES );

		$excluded = $this->create_source( 'lastfm' );
		$included = $this->create_source( 'mastodon' );

		update_post_meta( $excluded, Fediverse::META_SOURCE_EXCLUDED, 1 );
		update_post_meta( $included, Fediverse::META_SOURCE_EXCLUDED, 0 );

		$this->create_entry( 'Listened to a lot of music', HOUR_IN_SECONDS, $excluded );
		$this->create_entry( 'A post I do want to share again', HOUR_IN_SECONDS, $included );

		$this->assertFalse( Fediverse::get_instance()->is_source_shared( $excluded ) );
		$this->assertTrue( Fediverse::get_instance()->is_source_shared( $included ) );
		$this->assertSame( 1, Fediverse::get_instance()->run() );
		$this->assertStringContainsString( 'A post I do want to share again', $this->get_updates()[0]->post_content );
	}

	/**
	 * After a big import the followers must not be flooded.
	 *
	 * @return void
	 */
	public function test_single_entries_are_shared_in_small_portions(): void {
		$this->share( Fediverse::MODE_ENTRIES );

		for ( $i = 1; $i <= 8; $i++ ) {
			$this->create_entry( 'Entry ' . $i, ( 20 - $i ) * HOUR_IN_SECONDS );
		}

		$this->assertSame( 5, Fediverse::get_instance()->run() );

		// the oldest ones go first, so the order of the timeline is the order things happened.
		$this->assertStringContainsString( 'Entry 1', $this->get_updates()[0]->post_content );

		\NowPagesThreadi\Digests\Digest_Builder::reset_cache();

		$this->assertSame( 3, Fediverse::get_instance()->run() );
		$this->assertCount( 8, $this->get_updates() );
	}

	/**
	 * Removing an entry must take back what was shared of it.
	 *
	 * @return void
	 */
	public function test_removing_an_entry_takes_its_update_back(): void {
		$this->share( Fediverse::MODE_ENTRIES );

		$entry_id = $this->create_entry( 'Something I regret', HOUR_IN_SECONDS );

		Fediverse::get_instance()->run();

		$this->assertCount( 1, $this->get_updates() );

		wp_delete_post( $entry_id, true );

		$this->assertSame( array(), $this->get_updates() );
		$this->assertCount( 1, $this->get_updates( 'trash' ) );
	}

	/**
	 * Tidying up old entries must not take back what was shared of them.
	 *
	 * @return void
	 */
	public function test_the_cleanup_of_old_entries_keeps_what_was_shared(): void {
		$this->share( Fediverse::MODE_ENTRIES );

		$entry_id = $this->create_entry( 'Something from two days ago', 2 * DAY_IN_SECONDS );

		Fediverse::get_instance()->run();

		$this->assertCount( 1, $this->get_updates() );
		$this->assertSame( 1, \NowPagesThreadi\NowPages\Cleanup::get_instance()->delete_older_than( 1 ) );
		$this->assertNull( get_post( $entry_id ) );
		$this->assertCount( 1, $this->get_updates() );

		// an entry removed afterwards is taken back again.
		$this->assertNotFalse( has_action( 'before_delete_post', array( Fediverse::get_instance(), 'entry_removed' ) ) );
	}

	/**
	 * The list in the backend must show what was shared: an update has no title.
	 *
	 * @return void
	 */
	public function test_the_list_shows_what_was_shared(): void {
		$this->share( Fediverse::MODE_ENTRIES );
		$this->create_entry( 'Released version 2 of the app', HOUR_IN_SECONDS );

		Fediverse::get_instance()->run();

		$columns = apply_filters( 'manage_np_threadi_update_posts_columns', array( 'cb' => '', 'title' => 'Title', 'date' => 'Date' ) );

		$this->assertArrayNotHasKey( 'title', $columns );
		$this->assertSame( array( 'cb', 'now_pages_threadi_update', 'date' ), array_keys( $columns ) );
		$this->assertSame( 'now_pages_threadi_update', apply_filters( 'list_table_primary_column', 'title', 'edit-np_threadi_update' ) );
		$this->assertSame( 'title', apply_filters( 'list_table_primary_column', 'title', 'edit-post' ) );

		ob_start();
		do_action( 'manage_np_threadi_update_posts_custom_column', 'now_pages_threadi_update', $this->get_updates()[0]->ID );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Released version 2 of the app', $output );
		$this->assertStringContainsString( 'Single entry', $output );
	}

	/**
	 * A reply from the Fediverse must not show up in the comment lists of the website.
	 *
	 * @return void
	 */
	public function test_replies_stay_out_of_the_comment_lists(): void {
		$this->share( Fediverse::MODE_ENTRIES );
		$this->create_entry( 'Went for a long walk', HOUR_IN_SECONDS );

		Fediverse::get_instance()->run();

		$post_id = self::factory()->post->create();

		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->get_updates()[0]->ID,
				'comment_content'  => 'A reply from the Fediverse',
				'comment_approved' => 1,
			)
		);
		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_content'  => 'A comment on a post',
				'comment_approved' => 1,
			)
		);

		// the list of the latest comments.
		$comments = get_comments(
			apply_filters(
				'widget_comments_args',
				array(
					'status'      => 'approve',
					'post_status' => 'publish',
				),
				array()
			)
		);

		$this->assertSame( array( 'A comment on a post' ), wp_list_pluck( $comments, 'comment_content' ) );

		// the comment feed.
		$this->go_to( '/?feed=comments-rss2' );

		global $wp_query;

		$this->assertTrue( $wp_query->is_comment_feed );
		$this->assertSame( array( 'A comment on a post' ), wp_list_pluck( (array) $wp_query->comments, 'comment_content' ) );

		// the backend still lists it.
		$this->assertCount( 1, get_comments( array( 'post_id' => $this->get_updates()[0]->ID ) ) );
	}

	/**
	 * The summary of yesterday must be shared today, once.
	 *
	 * @return void
	 */
	public function test_the_summary_of_a_day_is_shared_once(): void {
		$this->share( Fediverse::MODE_DIGEST );

		$this->create_entry_of_yesterday( 'Wrote the first chapter' );
		$this->create_entry_of_yesterday( 'Went for a long walk' );

		$this->assertSame( 1, Fediverse::get_instance()->run() );

		$updates = $this->get_updates();

		$this->assertCount( 1, $updates );
		$this->assertStringContainsString( 'Wrote the first chapter', $updates[0]->post_content );
		$this->assertStringContainsString( 'Went for a long walk', $updates[0]->post_content );
		$this->assertSame( '', (string) get_post_meta( $updates[0]->ID, Fediverse::META_ENTRY, true ) );

		// the single entries are not marked: a summary is not a share of each of them.
		$this->assertSame( 0, Fediverse::get_instance()->run() );
		$this->assertCount( 1, $this->get_updates() );
	}

	/**
	 * What happened today must not be part of the summary of yesterday.
	 *
	 * @return void
	 */
	public function test_a_summary_covers_its_period_only(): void {
		$this->share( Fediverse::MODE_DIGEST );

		$this->create_entry_of_yesterday( 'Something from yesterday' );
		$this->create_entry( 'Something from just now', 60 );

		Fediverse::get_instance()->run();

		$updates = $this->get_updates();

		$this->assertCount( 1, $updates );
		$this->assertStringNotContainsString( 'Something from just now', $updates[0]->post_content );
	}

	/**
	 * The first summary must not contain what happened before sharing was switched on.
	 *
	 * @return void
	 */
	public function test_the_first_summary_starts_when_sharing_was_switched_on(): void {
		$yesterday = current_datetime()->modify( '-1 day' );

		update_option( Fediverse::OPTION_MODE, Fediverse::MODE_DIGEST );
		update_option( Fediverse::OPTION_SINCE, $yesterday->setTime( 18, 0 )->getTimestamp() );

		foreach ( array( 'Before switching on' => 12, 'After switching on' => 20 ) as $text => $hour ) {
			$date = $yesterday->setTime( $hour, 0 );

			self::factory()->post->create(
				array(
					'post_type'     => 'np_threadi_entry',
					'post_status'   => 'publish',
					'post_title'    => '',
					'post_content'  => $text,
					'post_date'     => $date->format( 'Y-m-d H:i:s' ),
					'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $date->getTimestamp() ),
					'meta_input'    => array( 'language' => substr( get_locale(), 0, 5 ) ),
				)
			);
		}

		$this->assertSame( 1, Fediverse::get_instance()->run() );

		$updates = $this->get_updates();

		$this->assertCount( 1, $updates );
		$this->assertStringContainsString( 'After switching on', $updates[0]->post_content );
		$this->assertStringNotContainsString( 'Before switching on', $updates[0]->post_content );
	}

	/**
	 * A summary must wait until the privacy delay of its last hours has passed.
	 *
	 * Shared earlier, the entries of the evening would be missing - and no
	 * later summary would ever include them.
	 *
	 * @return void
	 */
	public function test_a_summary_waits_for_the_privacy_delay(): void {
		update_option( 'now_pages_threadi_delay_hours', 48 );

		$this->share( Fediverse::MODE_DIGEST, 5 );
		$this->create_entry_of_yesterday( 'Something from yesterday' );

		$this->assertSame( 0, Fediverse::get_instance()->run() );
		$this->assertSame( array(), $this->get_updates() );

		// earlier days may have been looked at, but yesterday is still within the delay.
		$this->assertLessThan( wp_date( 'Y-m-d 00:00:00', strtotime( '-1 day' ) ), (string) get_option( Fediverse::OPTION_LAST_DIGEST, '' ) );
	}

	/**
	 * A day without entries must not produce a post saying nothing.
	 *
	 * @return void
	 */
	public function test_an_empty_period_is_not_shared(): void {
		$this->share( Fediverse::MODE_DIGEST );

		$this->assertSame( 0, Fediverse::get_instance()->run() );
		$this->assertSame( array(), $this->get_updates() );

		// and it is not looked at again.
		$this->assertNotSame( '', (string) get_option( Fediverse::OPTION_LAST_DIGEST, '' ) );
	}

	/**
	 * A summary must only carry markup other servers show.
	 *
	 * @return void
	 */
	public function test_a_summary_carries_simple_markup_only(): void {
		$this->share( Fediverse::MODE_DIGEST );
		$this->create_entry_of_yesterday( 'Wrote the first chapter' );

		Fediverse::get_instance()->run();

		$content = $this->get_updates()[0]->post_content;

		$this->assertStringNotContainsString( 'class=', $content );
		$this->assertStringNotContainsString( '<div', $content );
		$this->assertStringNotContainsString( '<h3', $content );
		$this->assertStringNotContainsString( '<time', $content );
		$this->assertStringContainsString( '<li>', $content );
	}

	/**
	 * The period of a weekly summary must be the last complete week.
	 *
	 * @return void
	 */
	public function test_the_last_week_is_a_complete_week(): void {
		update_option( Fediverse::OPTION_INTERVAL, 'week' );
		update_option( 'start_of_week', 1 );

		// a Wednesday.
		$period = Fediverse::get_instance()->get_last_period( new \DateTimeImmutable( '2026-10-07 10:00:00', wp_timezone() ) );

		$this->assertSame( '2026-09-28 00:00:00', $period['start']->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( '2026-10-04 23:59:59', $period['end']->format( 'Y-m-d H:i:s' ) );

		// a Monday, the first day of a week.
		$period = Fediverse::get_instance()->get_last_period( new \DateTimeImmutable( '2026-10-05 00:30:00', wp_timezone() ) );

		$this->assertSame( '2026-09-28 00:00:00', $period['start']->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( '2026-10-04 23:59:59', $period['end']->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * An update must not be a page of the website.
	 *
	 * @return void
	 */
	public function test_an_update_is_not_a_page_of_the_website(): void {
		$post_type = get_post_type_object( 'np_threadi_update' );

		$this->assertNotNull( $post_type );
		$this->assertFalse( $post_type->public );
		$this->assertFalse( $post_type->publicly_queryable );
		$this->assertTrue( $post_type->exclude_from_search );
		$this->assertFalse( $post_type->has_archive );
		$this->assertFalse( $post_type->show_in_rest );
		$this->assertNotContains( 'np_threadi_update', get_post_types( array( 'public' => true ) ) );
	}

	/**
	 * The link of an update must lead to the now page.
	 *
	 * @return void
	 */
	public function test_the_link_of_an_update_leads_to_the_now_page(): void {
		$this->share( Fediverse::MODE_ENTRIES );
		$this->create_entry( 'Released version 2 of the app', HOUR_IN_SECONDS );

		Fediverse::get_instance()->run();

		$this->assertSame( Fediverse::get_instance()->get_now_page_url(), get_permalink( $this->get_updates()[0] ) );
	}
}
