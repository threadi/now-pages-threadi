<?php
/**
 * File to test the WordPress provider.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Tests\Unit;

use ThreadiNowPages\NowPages\Entry;
use ThreadiNowPages\Providers\Provider_Context;
use ThreadiNowPages\Providers\WordPress_Provider;
use ThreadiNowPages\Tests\NowPagesTestCase;

/**
 * Object to test WordPress_Provider.
 */
class WordPressProviderTest extends NowPagesTestCase {
	/**
	 * The source the provider runs for.
	 *
	 * @var int
	 */
	private int $source_id = 0;

	/**
	 * Create a source for every test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->source_id = self::factory()->post->create(
			array(
				'post_type'   => 'threadi_np_source',
				'post_status' => 'publish',
				'post_title'  => 'This website',
			)
		);
	}

	/**
	 * Run the provider with the given configuration.
	 *
	 * @param array<string,mixed> $config Additional configuration.
	 *
	 * @return array<int,Entry>
	 */
	private function fetch( array $config = array() ): array {
		$context = new Provider_Context( $this->source_id );

		$entries = ( new WordPress_Provider() )->fetch(
			array_merge(
				array(
					'wp_post_types' => array( 'post' ),
					'wp_author'     => 0,
					'wp_comments'   => 0,
					'wp_max_items'  => 10,
				),
				$config
			),
			$context
		);

		// the state of a run is stored only when the run completes, like Sources::import_source() does.
		$context->mark_as_run();

		return $entries;
	}

	/**
	 * Return the texts of the given entries as one string.
	 *
	 * @param array<int,Entry> $entries The entries.
	 *
	 * @return string
	 */
	private function get_texts( array $entries ): string {
		return implode( ' | ', array_map( static fn ( Entry $entry ): string => $entry->text, $entries ) );
	}

	/**
	 * Create a published post a few minutes ago.
	 *
	 * @param string $title  The title of the post.
	 * @param int    $author The author, 0 for the default.
	 *
	 * @return int
	 */
	private function create_post( string $title, int $author = 0 ): int {
		$args = array(
			'post_type'     => 'post',
			'post_status'   => 'publish',
			'post_title'    => $title,
			'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - ( 5 * MINUTE_IN_SECONDS ) ),
		);

		if ( $author > 0 ) {
			$args['post_author'] = $author;
		}

		return self::factory()->post->create( $args );
	}

	/**
	 * A recently published post must produce an entry.
	 *
	 * @return void
	 */
	public function test_recent_post_produces_an_entry(): void {
		$this->create_post( 'Hello from the test' );

		$this->assertStringContainsString( 'Hello from the test', $this->get_texts( $this->fetch() ) );
	}

	/**
	 * A post older than the first look-back must stay out.
	 *
	 * @return void
	 */
	public function test_old_post_is_not_imported(): void {
		self::factory()->post->create(
			array(
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_title'    => 'Ancient history',
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) ),
			)
		);

		$this->assertStringNotContainsString( 'Ancient history', $this->get_texts( $this->fetch() ) );
	}

	/**
	 * An old sticky post must stay out as well.
	 *
	 * WP_Query would add sticky posts regardless of the date query.
	 *
	 * @return void
	 */
	public function test_old_sticky_post_is_not_imported(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_title'    => 'Sticky history',
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) ),
			)
		);

		stick_post( $post_id );

		$this->create_post( 'Hello from the test' );

		$texts = $this->get_texts( $this->fetch() );

		unstick_post( $post_id );

		$this->assertStringNotContainsString( 'Sticky history', $texts );
		$this->assertStringContainsString( 'Hello from the test', $texts );
	}

	/**
	 * A draft must not be imported.
	 *
	 * @return void
	 */
	public function test_draft_is_not_imported(): void {
		self::factory()->post->create(
			array(
				'post_type'     => 'post',
				'post_status'   => 'draft',
				'post_title'    => 'Not ready yet',
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - ( 5 * MINUTE_IN_SECONDS ) ),
			)
		);

		$this->assertStringNotContainsString( 'Not ready yet', $this->get_texts( $this->fetch() ) );
	}

	/**
	 * A password protected post must not be imported.
	 *
	 * @return void
	 */
	public function test_password_protected_post_is_not_imported(): void {
		self::factory()->post->create(
			array(
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_title'    => 'Only for friends',
				'post_password' => 'secret',
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - ( 5 * MINUTE_IN_SECONDS ) ),
			)
		);

		$this->assertStringNotContainsString( 'Only for friends', $this->get_texts( $this->fetch() ) );
	}

	/**
	 * Our own entries must never be read.
	 *
	 * Without this guard every run would turn every entry into a new entry,
	 * and the external ID cannot catch it because each one is new.
	 *
	 * @return void
	 */
	public function test_own_entries_are_never_read(): void {
		self::factory()->post->create(
			array(
				'post_type'     => 'threadi_np_entry',
				'post_status'   => 'publish',
				'post_title'    => '',
				'post_content'  => 'An existing now page entry',
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - ( 5 * MINUTE_IN_SECONDS ) ),
			)
		);

		$entries = $this->fetch( array( 'wp_post_types' => array( 'threadi_np_entry' ) ) );

		$this->assertStringNotContainsString( 'An existing now page entry', $this->get_texts( $entries ) );
	}

	/**
	 * Our own sources must never be read either.
	 *
	 * @return void
	 */
	public function test_own_sources_are_never_read(): void {
		$entries = $this->fetch( array( 'wp_post_types' => array( 'threadi_np_source' ) ) );

		$this->assertStringNotContainsString( 'This website', $this->get_texts( $entries ) );
	}

	/**
	 * Only the configured author must be read.
	 *
	 * @return void
	 */
	public function test_only_the_configured_author_is_read(): void {
		$first  = self::factory()->user->create( array( 'role' => 'author' ) );
		$second = self::factory()->user->create( array( 'role' => 'author' ) );

		$this->create_post( 'From the first author', $first );
		$this->create_post( 'From the second author', $second );

		$texts = $this->get_texts( $this->fetch( array( 'wp_author' => $first ) ) );

		$this->assertStringContainsString( 'From the first author', $texts );
		$this->assertStringNotContainsString( 'From the second author', $texts );
	}

	/**
	 * A configured author who disabled the own now page must not be read either.
	 *
	 * @return void
	 */
	public function test_a_configured_author_who_opted_out_is_not_read(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		update_user_meta( $author, 'threadi_now_pages_disable_now_page', 1 );

		$this->create_post( 'From the opted out author', $author );

		$this->assertStringNotContainsString( 'From the opted out author', $this->get_texts( $this->fetch( array( 'wp_author' => $author ) ) ) );
	}

	/**
	 * Without comments enabled no comment must be read.
	 *
	 * @return void
	 */
	public function test_comments_are_not_read_by_default(): void {
		$post_id = $this->create_post( 'A post with a comment' );
		$user_id = self::factory()->user->create();

		self::factory()->comment->create(
			array(
				'comment_post_ID'      => $post_id,
				'user_id'              => $user_id,
				'comment_approved'     => '1',
				'comment_date_gmt'     => gmdate( 'Y-m-d H:i:s', time() - ( 2 * MINUTE_IN_SECONDS ) ),
			)
		);

		$this->assertStringNotContainsString( 'Commented', $this->get_texts( $this->fetch() ) );
	}

	/**
	 * With comments enabled a comment of a registered user must be read.
	 *
	 * @return void
	 */
	public function test_comment_of_a_registered_user_is_read(): void {
		$post_id = $this->create_post( 'A post with a comment' );
		$user_id = self::factory()->user->create();

		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'user_id'          => $user_id,
				'comment_approved' => '1',
				'comment_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - ( 2 * MINUTE_IN_SECONDS ) ),
			)
		);

		$this->assertStringContainsString( 'A post with a comment', $this->get_texts( $this->fetch( array( 'wp_comments' => 1 ) ) ) );
	}

	/**
	 * An anonymous comment must not become an entry.
	 *
	 * A now page speaks in the first person, and a visitor is not its author.
	 *
	 * @return void
	 */
	public function test_anonymous_comment_is_not_read(): void {
		$post_id = $this->create_post( 'A post with a comment' );

		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'user_id'          => 0,
				'comment_approved' => '1',
				'comment_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - ( 2 * MINUTE_IN_SECONDS ) ),
			)
		);

		$this->assertStringNotContainsString( 'Commented', $this->get_texts( $this->fetch( array( 'wp_comments' => 1 ) ) ) );
	}

	/**
	 * An unapproved comment must not be read.
	 *
	 * @return void
	 */
	public function test_unapproved_comment_is_not_read(): void {
		$post_id = $this->create_post( 'A post with a comment' );
		$user_id = self::factory()->user->create();

		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'user_id'          => $user_id,
				'comment_approved' => '0',
				'comment_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - ( 2 * MINUTE_IN_SECONDS ) ),
			)
		);

		$this->assertStringNotContainsString( 'Commented', $this->get_texts( $this->fetch( array( 'wp_comments' => 1 ) ) ) );
	}

	/**
	 * A second run must not deliver the same post again.
	 *
	 * @return void
	 */
	public function test_second_run_delivers_nothing_new(): void {
		$this->create_post( 'Hello from the test' );

		$this->assertNotEmpty( $this->fetch() );
		$this->assertSame( array(), $this->fetch() );
	}

	/**
	 * The maximum number of items must be respected.
	 *
	 * @return void
	 */
	public function test_max_items_is_respected(): void {
		$this->create_post( 'First post' );
		$this->create_post( 'Second post' );
		$this->create_post( 'Third post' );

		$this->assertCount( 2, $this->fetch( array( 'wp_max_items' => 2 ) ) );
	}

	/**
	 * Every entry must link to the post it came from.
	 *
	 * @return void
	 */
	public function test_entries_link_to_their_post(): void {
		$post_id = $this->create_post( 'Hello from the test' );

		$entries = $this->fetch();

		$this->assertSame( get_permalink( $post_id ), $entries[0]->source_url );
	}
}
