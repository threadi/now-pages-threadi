<?php
/**
 * File to test the Steam provider.
 *
 * Steam returns counters, not events, so the interesting part is the
 * difference between two runs. The mocked response is built from
 * NowPagesTestCase::$steam_games, which a test changes between runs to
 * simulate playing.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Tests\Unit;

use NowPagesThreadi\NowPages\Entry;
use NowPagesThreadi\Providers\Provider_Context;
use NowPagesThreadi\Providers\Steam_Provider;
use NowPagesThreadi\Tests\NowPagesTestCase;

/**
 * Object to test Steam_Provider.
 */
class SteamProviderTest extends NowPagesTestCase {
	/**
	 * The source the provider runs for.
	 *
	 * @var int
	 */
	private int $source_id = 0;

	/**
	 * Create a source and reset the mocked account.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		self::$steam_games  = array();
		self::$steam_public = false;

		$this->source_id = self::factory()->post->create(
			array(
				'post_type'   => 'np_threadi_source',
				'post_status' => 'publish',
				'post_title'  => 'Steam',
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

		$entries = ( new Steam_Provider() )->fetch(
			array_merge(
				array(
					'steam_id'          => self::$steam_id,
					'steam_api_key'     => 'test-key',
					'steam_min_minutes' => 15,
					'steam_max_items'   => 5,
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
	 * Set the playtime of a game on the mocked account.
	 *
	 * @param int      $app_id    The application ID.
	 * @param string   $name      The name of the game.
	 * @param int      $minutes   The total playtime in minutes.
	 * @param int|null $two_weeks The playtime of the last two weeks, null to leave it out.
	 *
	 * @return void
	 */
	private function set_playtime( int $app_id, string $name, int $minutes, ?int $two_weeks = null ): void {
		self::$steam_games[ $app_id ] = array(
			'appid'            => $app_id,
			'name'             => $name,
			'playtime_forever' => $minutes,
		);

		if ( null !== $two_weeks ) {
			self::$steam_games[ $app_id ]['playtime_2weeks'] = $two_weeks;
		}
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
	 * Without a complete configuration nothing must be fetched.
	 *
	 * @return void
	 */
	public function test_incomplete_configuration_fetches_nothing(): void {
		$this->assertSame( array(), $this->fetch( array( 'steam_api_key' => '' ) ) );
		$this->assertSame( array(), $this->fetch( array( 'steam_id' => '' ) ) );
	}

	/**
	 * The first run must report nothing, only remember the counters.
	 *
	 * Otherwise the first import would claim the complete playtime of every
	 * game as a single session.
	 *
	 * @return void
	 */
	public function test_first_run_reports_nothing(): void {
		$this->set_playtime( 70, 'Half-Life', 20400 );

		$this->assertSame( array(), $this->fetch() );
	}

	/**
	 * The first run must store the counters for the next one.
	 *
	 * @return void
	 */
	public function test_first_run_stores_the_counters(): void {
		$this->set_playtime( 70, 'Half-Life', 20400 );

		$this->fetch();

		$state = ( new Provider_Context( $this->source_id ) )->get_state( 'steam_playtime' );

		$this->assertSame( 20400, $state[70] );
	}

	/**
	 * A second run must report the difference, not the total.
	 *
	 * @return void
	 */
	public function test_second_run_reports_the_difference(): void {
		$this->set_playtime( 70, 'Half-Life', 20400 );
		$this->fetch();

		$this->set_playtime( 70, 'Half-Life', 20520 );
		$entries = $this->fetch();

		$this->assertCount( 1, $entries );
		$this->assertStringContainsString( 'Half-Life', $entries[0]->text );
		$this->assertStringNotContainsString( '20520', $entries[0]->text );
	}

	/**
	 * A session below the minimum must be skipped.
	 *
	 * @return void
	 */
	public function test_short_session_is_skipped(): void {
		$this->set_playtime( 70, 'Half-Life', 20400 );
		$this->fetch();

		$this->set_playtime( 70, 'Half-Life', 20405 );

		$this->assertSame( array(), $this->fetch() );
	}

	/**
	 * A run without any change must report nothing.
	 *
	 * @return void
	 */
	public function test_unchanged_counter_reports_nothing(): void {
		$this->set_playtime( 70, 'Half-Life', 20400 );
		$this->fetch();

		$this->assertSame( array(), $this->fetch() );
	}

	/**
	 * A counter that shrank must not produce a negative session.
	 *
	 * @return void
	 */
	public function test_shrinking_counter_reports_nothing(): void {
		$this->set_playtime( 70, 'Half-Life', 20400 );
		$this->fetch();

		$this->set_playtime( 70, 'Half-Life', 100 );

		$this->assertSame( array(), $this->fetch() );
	}

	/**
	 * A game that appears for the first time must be reported completely.
	 *
	 * It has no previous counter, so its whole playtime is the session.
	 *
	 * @return void
	 */
	public function test_new_game_is_reported(): void {
		$this->set_playtime( 70, 'Half-Life', 20400 );
		$this->fetch();

		$this->set_playtime( 220, 'Half-Life 2', 90 );
		$entries = $this->fetch();

		$this->assertStringContainsString( 'Half-Life 2', $this->get_texts( $entries ) );
	}

	/**
	 * A game played again after a long time must not be reported with everything ever played.
	 *
	 * Steam lists the last two weeks only. A game coming back has no counter
	 * to compare against, but Steam says how much of it is recent.
	 *
	 * @return void
	 */
	public function test_an_old_game_played_again_reports_only_the_recent_time(): void {
		$this->set_playtime( 70, 'Half-Life', 1000, 120 );
		$this->fetch();

		// 100 hours overall, 30 minutes of them now.
		$this->set_playtime( 220, 'Half-Life 2', 6030, 30 );
		$entries = $this->fetch();

		$this->assertCount( 1, $entries );
		$this->assertSame( 'Played Half-Life 2 for 30 minutes', $entries[0]->text );
	}

	/**
	 * The counter of a game is kept while Steam does not list it.
	 *
	 * @return void
	 */
	public function test_a_game_which_left_the_list_keeps_its_counter(): void {
		$this->set_playtime( 70, 'Half-Life', 1000 );
		$this->set_playtime( 220, 'Half-Life 2', 500 );
		$this->fetch();

		// two weeks without Half-Life.
		unset( self::$steam_games[70] );
		$this->fetch();

		$this->set_playtime( 70, 'Half-Life', 1040 );
		$entries = $this->fetch();

		$this->assertCount( 1, $entries );
		$this->assertSame( 'Played Half-Life for 40 minutes', $entries[0]->text );
	}

	/**
	 * The first game after a time without playing must be reported.
	 *
	 * The source was set up while nothing had been played for two weeks:
	 * there were no counters to store, but it was not the first run either.
	 *
	 * @return void
	 */
	public function test_the_first_game_of_an_idle_account_is_reported(): void {
		self::$steam_public = true;

		$this->assertSame( array(), $this->fetch() );

		$this->set_playtime( 70, 'Half-Life', 20445, 45 );
		$entries = $this->fetch();

		$this->assertCount( 1, $entries );
		$this->assertSame( 'Played Half-Life for 45 minutes', $entries[0]->text );
	}

	/**
	 * An account which played nothing lately is not reported as private.
	 *
	 * @return void
	 */
	public function test_an_idle_account_is_not_reported_as_private(): void {
		self::$steam_public = true;

		$errors = 0;

		$handler = static function () use ( &$errors ): void {
			++$errors;
		};

		add_action( 'now_pages_threadi_provider_error', $handler );
		$this->fetch();
		remove_action( 'now_pages_threadi_provider_error', $handler );

		$this->assertSame( 0, $errors );
	}

	/**
	 * Several sessions must be ordered by their length.
	 *
	 * @return void
	 */
	public function test_sessions_are_ordered_by_length(): void {
		$this->set_playtime( 70, 'Half-Life', 1000 );
		$this->set_playtime( 220, 'Half-Life 2', 1000 );
		$this->fetch();

		$this->set_playtime( 70, 'Half-Life', 1030 );
		$this->set_playtime( 220, 'Half-Life 2', 1200 );

		$entries = $this->fetch();

		$this->assertCount( 2, $entries );
		$this->assertStringContainsString( 'Half-Life 2', $entries[0]->text );
	}

	/**
	 * The maximum number of items must be respected.
	 *
	 * @return void
	 */
	public function test_max_items_is_respected(): void {
		$this->set_playtime( 70, 'Half-Life', 1000 );
		$this->set_playtime( 220, 'Half-Life 2', 1000 );
		$this->fetch();

		$this->set_playtime( 70, 'Half-Life', 1100 );
		$this->set_playtime( 220, 'Half-Life 2', 1200 );

		$this->assertCount( 1, $this->fetch( array( 'steam_max_items' => 1 ) ) );
	}

	/**
	 * Minutes below an hour must be written as minutes.
	 *
	 * @return void
	 */
	public function test_short_sessions_are_written_in_minutes(): void {
		$this->set_playtime( 70, 'Half-Life', 1000 );
		$this->fetch();

		$this->set_playtime( 70, 'Half-Life', 1030 );

		$this->assertStringContainsString( '30', $this->get_texts( $this->fetch() ) );
	}

	/**
	 * Every entry must carry a unique external ID.
	 *
	 * @return void
	 */
	public function test_external_id_is_unique_per_counter(): void {
		$this->set_playtime( 70, 'Half-Life', 1000 );
		$this->fetch();

		$this->set_playtime( 70, 'Half-Life', 1100 );
		$first = $this->fetch();

		$this->assertSame( 'steam-70-1100', $first[0]->external_id );
	}

	/**
	 * Every entry must link to the store page of its game.
	 *
	 * @return void
	 */
	public function test_entries_link_to_the_store_page(): void {
		$this->set_playtime( 70, 'Half-Life', 1000 );
		$this->fetch();

		$this->set_playtime( 70, 'Half-Life', 1100 );

		$this->assertSame( 'https://store.steampowered.com/app/70/', $this->fetch()[0]->source_url );
	}

	/**
	 * A private profile must be reported, but only once.
	 *
	 * Steam answers a closed profile with an empty object instead of an
	 * error, so this is the only place it can be noticed.
	 *
	 * @return void
	 */
	public function test_private_profile_is_reported_once(): void {
		$errors = 0;

		$handler = static function () use ( &$errors ): void {
			++$errors;
		};

		add_action( 'now_pages_threadi_provider_error', $handler );

		// an empty games list without a total count means the profile is closed.
		$this->fetch();
		$this->fetch();

		remove_action( 'now_pages_threadi_provider_error', $handler );

		$this->assertSame( 1, $errors );
	}
}
