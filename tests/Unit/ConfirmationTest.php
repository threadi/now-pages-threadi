<?php
/**
 * File to test entries which wait for the confirmation of the user.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Tests\Unit;

use DateTimeImmutable;
use ThreadiNowPages\Dependencies\easyTransientsForWordPress\Transients;
use ThreadiNowPages\Digests\Digest_Builder;
use ThreadiNowPages\NowPages\Confirmation;
use ThreadiNowPages\NowPages\Entries;
use ThreadiNowPages\NowPages\Entry;
use ThreadiNowPages\Sources\Sources;
use ThreadiNowPages\Tests\NowPagesTestCase;
use WP_Post;
use WPDieException;

/**
 * Object to test Confirmation.
 */
class ConfirmationTest extends NowPagesTestCase {
	/**
	 * The source the entries belong to.
	 *
	 * @var int
	 */
	private int $source_id = 0;

	/**
	 * The emails which would have been sent.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $mails = array();

	/**
	 * The request method before the test, null if none was set.
	 *
	 * @var string|null
	 */
	private ?string $request_method = null;

	/**
	 * Create a source and catch every email.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->mails = array();

		// remember the request method, so it can be restored afterwards.
		$this->request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : null;

		add_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10, 2 );

		update_option( 'threadi_now_pages_email_confirm_entry', 1 );
		update_option( 'threadi_now_pages_email_recipients_confirm_entry', array() );
		delete_option( Entries::OPTION_DELETED );

		$this->source_id = $this->create_source();

		Transients::get_instance()->get_transient_by_name( 'threadi_now_pages_entry_decision' )->delete();
	}

	/**
	 * Clean up the request between tests.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'catch_mail' ) );

		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();

		// restore the request method instead of removing it for every later test.
		if ( null === $this->request_method ) {
			unset( $_SERVER['REQUEST_METHOD'] );
		} else {
			$_SERVER['REQUEST_METHOD'] = $this->request_method;
		}

		parent::tear_down();
	}

	/**
	 * Remember an email instead of sending it.
	 *
	 * @param mixed               $result The result so far.
	 * @param array<string,mixed> $atts   The arguments of wp_mail().
	 *
	 * @return bool
	 */
	public function catch_mail( mixed $result, array $atts ): bool {
		$this->mails[] = $atts;

		return true;
	}

	/**
	 * Create a published source.
	 *
	 * @return int
	 */
	private function create_source(): int {
		return self::factory()->post->create(
			array(
				'post_type'   => 'threadi_np_source',
				'post_status' => 'publish',
				'post_title'  => 'Sports',
			)
		);
	}

	/**
	 * Let a source deliver an entry, the way an import does.
	 *
	 * @param string $external_id The external ID.
	 * @param string $status      The status the provider asks for.
	 * @param int    $source_id   The source, 0 for the one of this test.
	 * @param string $date        The date of the entry.
	 *
	 * @return int The ID of the stored entry, 0 if none was stored.
	 */
	private function add_entry( string $external_id, string $status = Confirmation::STATUS, int $source_id = 0, string $date = '2025-10-06 00:00:00' ): int {
		$entry              = new Entry();
		$entry->text        = 'Went to the gym';
		$entry->date        = new DateTimeImmutable( $date, wp_timezone() );
		$entry->source_slug = 'recurring';
		$entry->external_id = $external_id;
		$entry->status      = $status;

		do_action( 'threadi_now_pages_entry_received', $entry, $source_id > 0 ? $source_id : $this->source_id );

		$result = Entries::get_instance()->pull_last_add_result();

		return is_array( $result ) ? $result['post_id'] : 0;
	}

	/**
	 * Return the address of the link with the given text in the last email.
	 *
	 * @param string $text The text of the link.
	 *
	 * @return string
	 */
	private function get_link_from_mail( string $text ): string {
		$mail = end( $this->mails );

		$this->assertIsArray( $mail );
		$this->assertSame( 1, preg_match( '~<a href="([^"]+)">(?:<strong>)?' . preg_quote( $text, '~' ) . '~', (string) $mail['message'], $matches ), 'The link "' . $text . '" is missing in the email.' );

		return html_entity_decode( $matches[1] );
	}

	/**
	 * Open a link from the email, optionally using its button.
	 *
	 * @param string $link     The link.
	 * @param bool   $with_use Whether the button of the page is used.
	 *
	 * @return array{message:string,code:int} What the page shows.
	 */
	private function open_link( string $link, bool $with_use = false ): array {
		parse_str( (string) wp_parse_url( $link, PHP_URL_QUERY ), $query );

		$_GET     = $query;
		$_REQUEST = $query;
		$_POST    = array();

		$_SERVER['REQUEST_METHOD'] = 'GET';

		if ( $with_use ) {
			$_SERVER['REQUEST_METHOD'] = 'POST';

			$_POST = array(
				'token'                    => $query['token'] ?? '',
				'decision'                 => $query['decision'] ?? '',
				'threadi_now_pages_decide' => '1',
			);

			$_REQUEST = array_merge( $query, $_POST );
		}

		try {
			Confirmation::get_instance()->show_decision_page();
		} catch ( WPDieException $e ) {
			return array(
				'message' => $e->getMessage(),
				'code'    => (int) $e->getCode(),
			);
		}

		return array(
			'message' => '',
			'code'    => 0,
		);
	}

	/**
	 * An entry which waits is stored, but not published.
	 *
	 * @return void
	 */
	public function test_a_waiting_entry_is_stored_but_not_published(): void {
		$entry_id = $this->add_entry( 'recurring-2025-10-06' );

		$this->assertGreaterThan( 0, $entry_id );
		$this->assertSame( Confirmation::STATUS, get_post_status( $entry_id ) );
		$this->assertTrue( Confirmation::get_instance()->is_waiting( $entry_id ) );
		$this->assertSame( '2025-10-06 00:00:00', get_post( $entry_id )->post_date );
	}

	/**
	 * A provider cannot ask for any other status than waiting.
	 *
	 * @return void
	 */
	public function test_any_other_status_is_published(): void {
		$this->assertSame( 'publish', get_post_status( $this->add_entry( 'one', 'publish' ) ) );
		$this->assertSame( 'publish', get_post_status( $this->add_entry( 'two', 'private' ) ) );
		$this->assertSame( 'publish', get_post_status( $this->add_entry( 'three', 'trash' ) ) );
	}

	/**
	 * A waiting entry is not part of the digest, a confirmed one is.
	 *
	 * @return void
	 */
	public function test_a_waiting_entry_is_shown_only_after_it_was_confirmed(): void {
		$date     = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( '-1 day' )->format( 'Y-m-d H:i:s' );
		$entry_id = $this->add_entry( 'recurring-yesterday', Confirmation::STATUS, 0, $date );

		$args = array(
			'period' => 'range',
			'start'  => ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( '-2 days' )->format( 'Y-m-d' ),
			'end'    => ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d' ),
		);

		Digest_Builder::reset_cache();
		$this->assertNotContains( $entry_id, wp_list_pluck( ( new Digest_Builder() )->get_entries( $args ), 'ID' ) );

		$this->assertTrue( Confirmation::get_instance()->confirm( $entry_id ) );

		Digest_Builder::reset_cache();
		$this->assertContains( $entry_id, wp_list_pluck( ( new Digest_Builder() )->get_entries( $args ), 'ID' ) );
	}

	/**
	 * Confirming publishes the entry and keeps the day it was due on.
	 *
	 * @return void
	 */
	public function test_confirming_publishes_the_entry_with_its_own_date(): void {
		$entry_id = $this->add_entry( 'recurring-2025-10-06' );

		$this->assertTrue( Confirmation::get_instance()->confirm( $entry_id ) );

		$post = get_post( $entry_id );

		$this->assertInstanceOf( WP_Post::class, $post );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( '2025-10-06 00:00:00', $post->post_date );
		$this->assertSame( 'Went to the gym', $post->post_content );
		$this->assertFalse( Confirmation::get_instance()->is_waiting( $entry_id ) );

		// there is nothing left to confirm.
		$this->assertFalse( Confirmation::get_instance()->confirm( $entry_id ) );
		$this->assertFalse( Confirmation::get_instance()->discard( $entry_id ) );
	}

	/**
	 * Discarding removes the entry, and the same entry is not created again.
	 *
	 * @return void
	 */
	public function test_a_discarded_entry_does_not_come_back(): void {
		$entry_id = $this->add_entry( 'recurring-2025-10-06' );

		$this->assertTrue( Confirmation::get_instance()->discard( $entry_id ) );
		$this->assertSame( 'trash', get_post_status( $entry_id ) );

		$this->assertSame( 0, $this->add_entry( 'recurring-2025-10-06' ) );
	}

	/**
	 * An entry which is published can neither be confirmed nor discarded this way.
	 *
	 * @return void
	 */
	public function test_a_published_entry_is_left_alone(): void {
		$entry_id = $this->add_entry( 'one', 'publish' );

		$this->assertFalse( Confirmation::get_instance()->discard( $entry_id ) );
		$this->assertSame( 'publish', get_post_status( $entry_id ) );
	}

	/**
	 * What was not confirmed is discarded as soon as the next entry of the same source waits.
	 *
	 * @return void
	 */
	public function test_the_next_waiting_entry_discards_the_one_before(): void {
		$other_source = $this->create_source();

		$first     = $this->add_entry( 'recurring-2025-10-06' );
		$foreign   = $this->add_entry( 'recurring-2025-10-06', Confirmation::STATUS, $other_source );
		$published = $this->add_entry( 'published', 'publish' );
		$second    = $this->add_entry( 'recurring-2025-10-13', Confirmation::STATUS, 0, '2025-10-13 00:00:00' );

		$this->assertSame( 'trash', get_post_status( $first ) );
		$this->assertSame( Confirmation::STATUS, get_post_status( $second ) );
		$this->assertSame( Confirmation::STATUS, get_post_status( $foreign ) );
		$this->assertSame( 'publish', get_post_status( $published ) );
	}

	/**
	 * A waiting entry is announced by email, with a link for each decision.
	 *
	 * @return void
	 */
	public function test_a_waiting_entry_is_announced_by_email(): void {
		$entry_id = $this->add_entry( 'recurring-2025-10-06' );

		$this->assertCount( 1, $this->mails );
		$this->assertSame( array( get_option( 'admin_email' ) ), (array) $this->mails[0]['to'] );
		$this->assertStringContainsString( 'Please confirm: Went to the gym', (string) $this->mails[0]['subject'] );
		$this->assertStringContainsString( 'Went to the gym', (string) $this->mails[0]['message'] );
		$this->assertStringContainsString( 'Source: Sports', (string) $this->mails[0]['message'] );

		$confirm = $this->get_link_from_mail( 'Yes, show this entry' );
		$discard = $this->get_link_from_mail( 'No, discard it' );

		$this->assertStringContainsString( 'threadi_now_pages_confirm=' . $entry_id, $confirm );
		$this->assertStringContainsString( 'decision=confirm', $confirm );
		$this->assertStringContainsString( 'decision=discard', $discard );
	}

	/**
	 * The addresses of the links are not readable in the email: its text ends up in the log.
	 *
	 * @return void
	 */
	public function test_the_token_is_not_part_of_the_visible_text(): void {
		// sent emails are only logged in debug mode.
		update_option( 'threadi_now_pages_debug', 1 );

		$this->add_entry( 'recurring-2025-10-06' );

		delete_option( 'threadi_now_pages_debug' );

		$this->assertStringNotContainsString( 'token', wp_strip_all_tags( (string) $this->mails[0]['message'] ) );

		// the log stores the email as JSON without its tags, see Email_Base::send().
		$this->assertStringNotContainsString( 'token', wp_strip_all_tags( (string) wp_json_encode( array( 'body' => $this->mails[0]['message'] ) ) ) );

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- reading the log of this test.
		$logged = (array) $wpdb->get_col( "SELECT log FROM {$wpdb->prefix}threadi_now_pages_logs WHERE category = 'emails'" );

		$this->assertNotEmpty( $logged );
		$this->assertStringContainsString( 'Went to the gym', implode( ' ', $logged ) );
		$this->assertStringNotContainsString( 'token', implode( ' ', $logged ) );
		$this->assertStringNotContainsString( 'threadi_now_pages_confirm', implode( ' ', $logged ) );
	}

	/**
	 * A published entry is not announced, and neither is anything if the email is switched off.
	 *
	 * @return void
	 */
	public function test_no_email_without_a_reason(): void {
		$this->add_entry( 'published', 'publish' );

		$this->assertCount( 0, $this->mails );

		update_option( 'threadi_now_pages_email_confirm_entry', 0 );

		$entry_id = $this->add_entry( 'recurring-2025-10-06' );

		$this->assertCount( 0, $this->mails );
		$this->assertSame( Confirmation::STATUS, get_post_status( $entry_id ) );
	}

	/**
	 * The email is switched on unless somebody switched it off.
	 *
	 * @return void
	 */
	public function test_the_email_is_enabled_by_default(): void {
		delete_option( 'threadi_now_pages_email_confirm_entry' );

		$this->add_entry( 'recurring-2025-10-06' );

		$this->assertCount( 1, $this->mails );
	}

	/**
	 * Opening a link from the email shows the entry, but decides nothing.
	 *
	 * @return void
	 */
	public function test_opening_a_link_decides_nothing(): void {
		$entry_id = $this->add_entry( 'recurring-2025-10-06' );
		$page     = $this->open_link( $this->get_link_from_mail( 'Yes, show this entry' ) );

		$this->assertSame( 200, $page['code'] );
		$this->assertStringContainsString( 'Went to the gym', $page['message'] );
		$this->assertStringContainsString( '<form method="post"', $page['message'] );
		$this->assertStringContainsString( 'Confirm this entry', $page['message'] );
		$this->assertSame( Confirmation::STATUS, get_post_status( $entry_id ) );

		$page = $this->open_link( $this->get_link_from_mail( 'No, discard it' ) );

		$this->assertStringContainsString( 'Discard this entry', $page['message'] );
		$this->assertSame( Confirmation::STATUS, get_post_status( $entry_id ) );
	}

	/**
	 * The button on the page confirms the entry, without anybody being logged in.
	 *
	 * @return void
	 */
	public function test_the_button_confirms_the_entry(): void {
		wp_set_current_user( 0 );

		$entry_id = $this->add_entry( 'recurring-2025-10-06' );
		$link     = $this->get_link_from_mail( 'Yes, show this entry' );
		$page     = $this->open_link( $link, true );

		$this->assertSame( 200, $page['code'] );
		$this->assertStringContainsString( 'has been confirmed', $page['message'] );
		$this->assertSame( 'publish', get_post_status( $entry_id ) );
		$this->assertSame( '2025-10-06 00:00:00', get_post( $entry_id )->post_date );

		// the link is used up.
		$page = $this->open_link( $link, true );

		$this->assertSame( 404, $page['code'] );
		$this->assertStringNotContainsString( '<form', $page['message'] );
	}

	/**
	 * The button on the page discards the entry.
	 *
	 * @return void
	 */
	public function test_the_button_discards_the_entry(): void {
		$entry_id = $this->add_entry( 'recurring-2025-10-06' );
		$page     = $this->open_link( $this->get_link_from_mail( 'No, discard it' ), true );

		$this->assertStringContainsString( 'has been discarded', $page['message'] );
		$this->assertSame( 'trash', get_post_status( $entry_id ) );
	}

	/**
	 * A link with a wrong token, or without one, shows nothing and decides nothing.
	 *
	 * @return void
	 */
	public function test_a_wrong_token_is_refused(): void {
		$entry_id = $this->add_entry( 'recurring-2025-10-06' );
		$link     = $this->get_link_from_mail( 'Yes, show this entry' );

		foreach ( array( add_query_arg( 'token', 'wrong', $link ), remove_query_arg( 'token', $link ) ) as $bad_link ) {
			$page = $this->open_link( $bad_link, true );

			$this->assertSame( 404, $page['code'] );
			$this->assertStringNotContainsString( 'Went to the gym', $page['message'] );
			$this->assertSame( Confirmation::STATUS, get_post_status( $entry_id ) );
		}
	}

	/**
	 * The token of one entry does not open another one.
	 *
	 * @return void
	 */
	public function test_a_token_belongs_to_its_entry(): void {
		$other_source = $this->create_source();

		$first = $this->add_entry( 'recurring-2025-10-06' );
		$link  = $this->get_link_from_mail( 'Yes, show this entry' );

		$second = $this->add_entry( 'recurring-2025-10-06', Confirmation::STATUS, $other_source );

		$page = $this->open_link( str_replace( 'threadi_now_pages_confirm=' . $first, 'threadi_now_pages_confirm=' . $second, $link ), true );

		$this->assertSame( 404, $page['code'] );
		$this->assertSame( Confirmation::STATUS, get_post_status( $second ) );
	}

	/**
	 * An expired link shows nothing and decides nothing.
	 *
	 * @return void
	 */
	public function test_an_expired_link_is_refused(): void {
		$entry_id = $this->add_entry( 'recurring-2025-10-06' );
		$link     = $this->get_link_from_mail( 'Yes, show this entry' );

		update_post_meta( $entry_id, 'confirm_token_expires', time() - 1 );

		$page = $this->open_link( $link, true );

		$this->assertSame( 404, $page['code'] );
		$this->assertSame( Confirmation::STATUS, get_post_status( $entry_id ) );
	}

	/**
	 * The email goes to the owner of the source, if no recipient is configured.
	 *
	 * @return void
	 */
	public function test_the_email_goes_to_the_owner_of_the_source(): void {
		$owner = self::factory()->user->create(
			array(
				'role'       => 'author',
				'user_email' => 'owner@example.com',
			)
		);

		$source_id = self::factory()->post->create(
			array(
				'post_type'   => 'threadi_np_source',
				'post_status' => 'publish',
				'post_title'  => 'Owned',
				'post_author' => $owner,
			)
		);

		$this->add_entry( 'recurring-2025-10-06', Confirmation::STATUS, $source_id );

		$this->assertSame( array( 'owner@example.com' ), (array) end( $this->mails )['to'] );
	}

	/**
	 * A request which is not about an entry is left alone.
	 *
	 * @return void
	 */
	public function test_other_requests_are_left_alone(): void {
		$page = $this->open_link( home_url( '/?p=1' ) );

		$this->assertSame( 0, $page['code'] );
	}

	/**
	 * The list of entries offers both decisions for a waiting entry.
	 *
	 * @return void
	 */
	public function test_the_list_offers_both_decisions(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$waiting   = get_post( $this->add_entry( 'recurring-2025-10-06' ) );
		$published = get_post( $this->add_entry( 'published', 'publish' ) );

		$this->assertInstanceOf( WP_Post::class, $waiting );
		$this->assertInstanceOf( WP_Post::class, $published );

		$actions = Confirmation::get_instance()->add_row_actions( array( 'edit' => 'Edit' ), $waiting );

		$this->assertSame( array( 'threadi_now_pages_confirm', 'threadi_now_pages_discard', 'edit' ), array_keys( $actions ) );
		$this->assertStringContainsString( 'action=threadi_now_pages_confirm_entry', $actions['threadi_now_pages_confirm'] );
		$this->assertStringContainsString( 'entry=' . $waiting->ID, $actions['threadi_now_pages_confirm'] );
		$this->assertStringContainsString( '_wpnonce=', $actions['threadi_now_pages_confirm'] );

		$this->assertSame( array( 'edit' => 'Edit' ), Confirmation::get_instance()->add_row_actions( array( 'edit' => 'Edit' ), $published ) );
	}

	/**
	 * A user who may not edit the entry is not offered a decision.
	 *
	 * @return void
	 */
	public function test_the_list_offers_nothing_to_users_without_the_right(): void {
		$waiting = get_post( $this->add_entry( 'recurring-2025-10-06' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertInstanceOf( WP_Post::class, $waiting );
		$this->assertSame( array( 'edit' => 'Edit' ), Confirmation::get_instance()->add_row_actions( array( 'edit' => 'Edit' ), $waiting ) );
	}

	/**
	 * A decision in the backend is carried out and leaves a note for the next page.
	 *
	 * @return void
	 */
	public function test_a_decision_in_the_backend_leaves_a_note(): void {
		$entry_id = $this->add_entry( 'recurring-2025-10-06' );

		$this->assertTrue( Confirmation::get_instance()->decide( $entry_id, Confirmation::CONFIRM ) );
		$this->assertSame( 'publish', get_post_status( $entry_id ) );

		$note = Transients::get_instance()->get_transient_by_name( 'threadi_now_pages_entry_decision' );

		$this->assertTrue( $note->is_set() );
		$this->assertStringContainsString( 'has been confirmed', $note->get_message() );

		// a second click on an old link.
		$this->assertFalse( Confirmation::get_instance()->decide( $entry_id, Confirmation::DISCARD ) );
		$this->assertSame( 'publish', get_post_status( $entry_id ) );
		$this->assertStringContainsString( 'not waiting', Transients::get_instance()->get_transient_by_name( 'threadi_now_pages_entry_decision' )->get_message() );
	}

	/**
	 * The menu item of the entries shows how many of them wait.
	 *
	 * @return void
	 */
	public function test_the_menu_shows_how_many_entries_wait(): void {
		global $menu;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$backup = $menu;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a menu of our own for this test, restored below.
		$menu = array(
			5  => array( 'Posts', 'edit_posts', 'edit.php' ),
			26 => array( 'Now Page Entries', 'edit_posts', 'edit.php?post_type=threadi_np_entry' ),
		);

		Confirmation::get_instance()->add_menu_count();

		$this->assertSame( 'Now Page Entries', $menu[26][0] );

		$this->add_entry( 'recurring-2025-10-06' );

		Confirmation::get_instance()->add_menu_count();

		$this->assertStringContainsString( 'awaiting-mod count-1', $menu[26][0] );
		$this->assertSame( 'Posts', $menu[5][0] );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restore.
		$menu = $backup;
	}

	/**
	 * The list names the state of a waiting entry in words of this plugin.
	 *
	 * @return void
	 */
	public function test_the_state_of_a_waiting_entry_is_named(): void {
		$waiting   = get_post( $this->add_entry( 'recurring-2025-10-06' ) );
		$published = get_post( $this->add_entry( 'published', 'publish' ) );

		$this->assertInstanceOf( WP_Post::class, $waiting );
		$this->assertInstanceOf( WP_Post::class, $published );

		$this->assertSame( array( 'pending' => 'Waiting for your confirmation' ), Confirmation::get_instance()->set_post_state( array( 'pending' => 'Pending' ), $waiting ) );
		$this->assertSame( array(), Confirmation::get_instance()->set_post_state( array(), $published ) );
	}

	/**
	 * A recurring source which asks first goes the whole way: import, email, confirmation.
	 *
	 * @return void
	 */
	public function test_a_recurring_source_which_asks_first(): void {
		update_post_meta( $this->source_id, 'provider', 'recurring' );
		update_post_meta( $this->source_id, 'recurring_text', 'Went to the market' );
		update_post_meta( $this->source_id, 'recurring_rhythm', 'daily' );
		update_post_meta( $this->source_id, 'recurring_confirm', 1 );

		Sources::get_instance()->import();
		Sources::get_instance()->import();

		$entries = get_posts(
			array(
				'post_type'   => 'threadi_np_entry',
				'post_status' => 'any',
				'post_parent' => $this->source_id,
			)
		);

		// one entry and one email, however often the import runs.
		$this->assertCount( 1, $entries );
		$this->assertCount( 1, $this->mails );
		$this->assertSame( Confirmation::STATUS, $entries[0]->post_status );

		$this->open_link( $this->get_link_from_mail( 'Yes, show this entry' ), true );

		$this->assertSame( 'publish', get_post_status( $entries[0]->ID ) );

		// confirmed, it is neither created nor announced again.
		Sources::get_instance()->import();

		$this->assertCount( 1, $this->mails );
	}

	/**
	 * The test email works without an entry.
	 *
	 * @return void
	 */
	public function test_the_test_email_has_an_example(): void {
		( new \ThreadiNowPages\Plugin\Emails\ConfirmEntry() )->send_test();

		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'This is what an entry waiting for your confirmation looks like.', (string) $this->mails[0]['message'] );
	}
}
