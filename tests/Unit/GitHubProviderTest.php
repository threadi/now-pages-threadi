<?php
/**
 * File to test the GitHub provider.
 *
 * Every request is answered locally, see NowPagesTestCase::add_url_filter().
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Tests\Unit;

use NowPagesThreadi\NowPages\Entry;
use NowPagesThreadi\Providers\GitHub_Provider;
use NowPagesThreadi\Providers\Provider_Context;
use NowPagesThreadi\Tests\NowPagesTestCase;
use ReflectionMethod;

/**
 * Object to test GitHub_Provider.
 */
class GitHubProviderTest extends NowPagesTestCase {
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
				'post_type'   => 'np_threadi_source',
				'post_status' => 'publish',
				'post_title'  => 'Test account',
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

		$entries = ( new GitHub_Provider() )->fetch(
			array_merge(
				array(
					'github_username'  => self::$github_user,
					'github_max_items' => 30,
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
	 * Without a username nothing must be fetched.
	 *
	 * @return void
	 */
	public function test_without_username_nothing_is_fetched(): void {
		$this->assertSame( array(), $this->fetch( array( 'github_username' => '' ) ) );
	}

	/**
	 * An account without events must produce nothing.
	 *
	 * @return void
	 */
	public function test_account_without_events_produces_nothing(): void {
		$this->assertSame( array(), $this->fetch( array( 'github_username' => self::$github_empty_user ) ) );
	}

	/**
	 * An unknown account must produce nothing and report the error.
	 *
	 * @return void
	 */
	public function test_unknown_account_reports_an_error(): void {
		$errors = 0;

		$handler = static function () use ( &$errors ): void {
			++$errors;
		};

		add_action( 'now_pages_threadi_provider_error', $handler );

		$entries = $this->fetch( array( 'github_username' => self::$github_error_user ) );

		remove_action( 'now_pages_threadi_provider_error', $handler );

		$this->assertSame( array(), $entries );
		$this->assertSame( 1, $errors );
	}

	/**
	 * A push must be described with its number of commits.
	 *
	 * @return void
	 */
	public function test_push_is_described_with_its_commits(): void {
		// the fixture holds one push with two commits.
		$this->assertStringContainsString( 'Pushed 2 commits to octocat/now-pages-threadi', $this->get_texts( $this->fetch() ) );
	}

	/**
	 * A push must link to the commit, not only to the repository.
	 *
	 * @return void
	 */
	public function test_push_links_to_the_commit(): void {
		$entries = $this->fetch();

		$this->assertSame(
			'https://github.com/octocat/now-pages-threadi/commit/aaaabbbbccccddddeeeeffff0000111122223333',
			$entries[0]->source_url
		);
	}

	/**
	 * A star must be described.
	 *
	 * @return void
	 */
	public function test_star_is_described(): void {
		$this->assertStringContainsString( 'octocat/hello-world', $this->get_texts( $this->fetch() ) );
	}

	/**
	 * A release must be described with its tag.
	 *
	 * @return void
	 */
	public function test_release_is_described_with_its_tag(): void {
		$this->assertStringContainsString( '1.2.0', $this->get_texts( $this->fetch() ) );
	}

	/**
	 * A pull request must be described with its title.
	 *
	 * @return void
	 */
	public function test_pull_request_is_described_with_its_title(): void {
		$this->assertStringContainsString( 'Add the ICS provider', $this->get_texts( $this->fetch() ) );
	}

	/**
	 * An issue must be described with its title.
	 *
	 * @return void
	 */
	public function test_issue_is_described_with_its_title(): void {
		$this->assertStringContainsString( 'Recurring events are expanded wrong', $this->get_texts( $this->fetch() ) );
	}

	/**
	 * An issue must link to the issue itself.
	 *
	 * @return void
	 */
	public function test_issue_links_to_the_issue(): void {
		$urls = array_map( static fn ( Entry $entry ): ?string => $entry->source_url, $this->fetch() );

		$this->assertContains( 'https://github.com/octocat/now-pages-threadi/issues/17', $urls );
	}

	/**
	 * An event type we do not describe must be skipped.
	 *
	 * @return void
	 */
	public function test_undescribed_event_type_is_skipped(): void {
		// the fixture holds six events, one of them a MemberEvent.
		$this->assertCount( 5, $this->fetch() );
	}

	/**
	 * The text of an event must be filterable.
	 *
	 * @return void
	 */
	public function test_event_text_can_be_filtered(): void {
		$filter = static fn ( string $text, string $type ): string => 'MemberEvent' === $type ? 'Added a collaborator' : $text;

		add_filter( 'now_pages_threadi_github_event_text', $filter, 10, 2 );

		$texts = $this->get_texts( $this->fetch() );

		remove_filter( 'now_pages_threadi_github_event_text', $filter, 10 );

		$this->assertStringContainsString( 'Added a collaborator', $texts );
	}

	/**
	 * Every entry must carry a prefixed external ID.
	 *
	 * @return void
	 */
	public function test_every_entry_has_a_prefixed_external_id(): void {
		$entries = $this->fetch();

		// without entries the loop below would check nothing.
		$this->assertNotEmpty( $entries );

		foreach ( $entries as $entry ) {
			$this->assertStringStartsWith( 'github-', $entry->external_id );
		}
	}

	/**
	 * The date of the event must be used, not the time of the import.
	 *
	 * @return void
	 */
	public function test_the_event_date_is_used(): void {
		$entries = $this->fetch();

		$this->assertSame( '2026-09-05', $entries[0]->date->format( 'Y-m-d' ) );
	}

	/**
	 * The maximum number of items must be respected.
	 *
	 * @return void
	 */
	public function test_max_items_is_respected(): void {
		$this->assertCount( 2, $this->fetch( array( 'github_max_items' => 2 ) ) );
	}

	/**
	 * A second run must not deliver events that were already seen.
	 *
	 * @return void
	 */
	public function test_second_run_delivers_nothing_new(): void {
		$this->assertNotEmpty( $this->fetch() );
		$this->assertSame( array(), $this->fetch() );
	}

	/**
	 * The marker of the last run must be stored on the source.
	 *
	 * @return void
	 */
	public function test_the_newest_event_is_remembered(): void {
		$this->fetch();

		$context = new Provider_Context( $this->source_id );

		$this->assertSame( '40000000005', $context->get_state( 'github_last_event_id' ) );
	}

	/**
	 * A run which did not complete must not move the marker.
	 *
	 * Otherwise the events of an aborted run would never be imported.
	 *
	 * @return void
	 */
	public function test_aborted_run_does_not_move_the_marker(): void {
		$config = array(
			'github_username'  => self::$github_user,
			'github_max_items' => 30,
		);

		// a run which never reaches mark_as_run().
		$this->assertNotEmpty( ( new GitHub_Provider() )->fetch( $config, new Provider_Context( $this->source_id ) ) );

		$this->assertNull( ( new Provider_Context( $this->source_id ) )->get_state( 'github_last_event_id' ) );
		$this->assertNotEmpty( $this->fetch() );
	}

	/**
	 * An event of an unexpected form must be skipped, not stop the run.
	 *
	 * @return void
	 */
	public function test_event_of_unexpected_form_is_skipped(): void {
		$method = new ReflectionMethod( GitHub_Provider::class, 'get_entry_from_event' );

		$event = array(
			'id'      => '1',
			'type'    => array( 'PushEvent' ),
			'repo'    => array( 'name' => array( 'octocat/now-pages-threadi' ) ),
			'payload' => 'not an array',
		);

		$this->assertNull( $method->invoke( new GitHub_Provider(), $event ) );
	}

	/**
	 * A run without a source must not fail, it just cannot remember anything.
	 *
	 * @return void
	 */
	public function test_run_without_a_source_works(): void {
		$entries = ( new GitHub_Provider() )->fetch(
			array(
				'github_username'  => self::$github_user,
				'github_max_items' => 30,
			),
			new Provider_Context( 0 )
		);

		$this->assertNotEmpty( $entries );
	}

	/**
	 * An ETag must be stored and sent again on the next run.
	 *
	 * A 304 costs nothing against the rate limit, which is what makes a
	 * short import interval possible at all.
	 *
	 * @return void
	 */
	public function test_etag_is_stored_and_reused(): void {
		$config = array(
			'github_username'  => self::$github_etag_user,
			'github_max_items' => 30,
		);

		$provider = new GitHub_Provider();

		$context = new Provider_Context( $this->source_id );
		$this->assertNotEmpty( $provider->fetch( $config, $context ) );

		// the ETag is stored only when the run completes.
		$context->mark_as_run();

		// the second run answers with 304, so there is nothing to return.
		$this->assertSame( array(), $provider->fetch( $config, new Provider_Context( $this->source_id ) ) );
	}
}
