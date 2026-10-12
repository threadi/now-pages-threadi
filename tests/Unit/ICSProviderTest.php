<?php
/**
 * File to test the iCalendar provider.
 *
 * Every request is answered locally, see NowPagesTestCase::add_url_filter().
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Tests\Unit;

use ThreadiNowPages\NowPages\Entry;
use ThreadiNowPages\Providers\ICS_Provider;
use ThreadiNowPages\Providers\Provider_Context;
use ThreadiNowPages\Tests\NowPagesTestCase;
use ReflectionMethod;

/**
 * Object to test ICS_Provider.
 */
class ICSProviderTest extends NowPagesTestCase {
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
				'post_title'  => 'Test calendar',
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
		return ( new ICS_Provider() )->fetch(
			array_merge(
				array(
					'ics_url'            => self::$ics_url,
					'ics_lookahead_days' => 0,
					'ics_max_items'      => 50,
				),
				$config
			),
			new Provider_Context( $this->source_id )
		);
	}

	/**
	 * Return the texts of the given entries.
	 *
	 * @param array<int,Entry> $entries The entries.
	 *
	 * @return array<int,string>
	 */
	private function get_texts( array $entries ): array {
		return array_map( static fn ( Entry $entry ): string => $entry->text, $entries );
	}

	/**
	 * Without a URL nothing must be fetched.
	 *
	 * @return void
	 */
	public function test_without_url_nothing_is_fetched(): void {
		$this->assertSame( array(), $this->fetch( array( 'ics_url' => '' ) ) );
	}

	/**
	 * A calendar without events must produce nothing.
	 *
	 * @return void
	 */
	public function test_empty_calendar_produces_nothing(): void {
		$this->assertSame( array(), $this->fetch( array( 'ics_url' => self::$ics_empty_url ) ) );
	}

	/**
	 * A failing request must produce nothing and report the error.
	 *
	 * @return void
	 */
	public function test_failing_request_reports_an_error(): void {
		$errors = 0;

		$handler = static function () use ( &$errors ): void {
			++$errors;
		};

		add_action( 'threadi_now_pages_provider_error', $handler );

		$entries = $this->fetch( array( 'ics_url' => self::$ics_error_url ) );

		remove_action( 'threadi_now_pages_provider_error', $handler );

		$this->assertSame( array(), $entries );
		$this->assertSame( 1, $errors );
	}

	/**
	 * A currently running appointment must produce an entry.
	 *
	 * @return void
	 */
	public function test_running_appointment_produces_an_entry(): void {
		$texts = $this->get_texts( $this->fetch() );

		$this->assertNotEmpty(
			array_filter( $texts, static fn ( string $text ): bool => str_contains( $text, 'Talk about now pages' ) )
		);
	}

	/**
	 * The location must be part of the text when the appointment has one.
	 *
	 * @return void
	 */
	public function test_location_is_part_of_the_text(): void {
		$texts = implode( ' | ', $this->get_texts( $this->fetch() ) );

		$this->assertStringContainsString( 'Room 2', $texts );
	}

	/**
	 * A multi-day appointment must be shown while it is running.
	 *
	 * @return void
	 */
	public function test_running_multi_day_appointment_is_shown(): void {
		$texts = implode( ' | ', $this->get_texts( $this->fetch() ) );

		$this->assertStringContainsString( 'WordCamp Testville 2026', $texts );
	}

	/**
	 * A single-day appointment with DTEND on the start date must be shown.
	 *
	 * @return void
	 */
	public function test_zero_length_appointment_is_shown(): void {
		$texts = implode( ' | ', $this->get_texts( $this->fetch() ) );

		$this->assertStringContainsString( 'WordCamp Zerolength 2026', $texts );
	}

	/**
	 * An appointment that already ended must not be shown.
	 *
	 * @return void
	 */
	public function test_past_appointment_is_not_shown(): void {
		$texts = implode( ' | ', $this->get_texts( $this->fetch() ) );

		$this->assertStringNotContainsString( 'WordCamp Pastville 2026', $texts );
	}

	/**
	 * A cancelled appointment must be skipped.
	 *
	 * @return void
	 */
	public function test_cancelled_appointment_is_skipped(): void {
		$texts = implode( ' | ', $this->get_texts( $this->fetch() ) );

		$this->assertStringNotContainsString( 'Cancelled meeting', $texts );
	}

	/**
	 * An appointment without a title has nothing to show and must be skipped.
	 *
	 * @return void
	 */
	public function test_appointment_without_summary_is_skipped(): void {
		foreach ( $this->fetch() as $entry ) {
			$this->assertNotSame( '', trim( $entry->text ) );
		}
	}

	/**
	 * Without a lookahead an upcoming appointment must not be announced.
	 *
	 * @return void
	 */
	public function test_upcoming_appointment_is_not_announced_by_default(): void {
		$texts = implode( ' | ', $this->get_texts( $this->fetch() ) );

		$this->assertStringNotContainsString( 'WordCamp Nextville 2026', $texts );
	}

	/**
	 * With a lookahead an upcoming appointment must be announced.
	 *
	 * @return void
	 */
	public function test_upcoming_appointment_is_announced_with_lookahead(): void {
		$texts = implode( ' | ', $this->get_texts( $this->fetch( array( 'ics_lookahead_days' => 5 ) ) ) );

		$this->assertStringContainsString( 'WordCamp Nextville 2026', $texts );
	}

	/**
	 * An appointment beyond the lookahead must stay out.
	 *
	 * @return void
	 */
	public function test_appointment_beyond_the_lookahead_is_not_announced(): void {
		$texts = implode( ' | ', $this->get_texts( $this->fetch( array( 'ics_lookahead_days' => 5 ) ) ) );

		$this->assertStringNotContainsString( 'WordCamp Faraway 2027', $texts );
	}

	/**
	 * Two runs on the same day must produce the same external IDs.
	 *
	 * This is what keeps a running appointment from being imported again on
	 * every scheduled run.
	 *
	 * @return void
	 */
	public function test_two_runs_produce_the_same_external_ids(): void {
		$first  = array_map( static fn ( Entry $entry ): string => $entry->external_id, $this->fetch() );
		$second = array_map( static fn ( Entry $entry ): string => $entry->external_id, $this->fetch() );

		$this->assertSame( $first, $second );
		$this->assertNotEmpty( $first );
	}

	/**
	 * A second run must still produce entries, even if the server sends an ETag.
	 *
	 * Which appointments are running depends on the current time, so an
	 * unchanged calendar must not be answered with 304 and no entries.
	 *
	 * @return void
	 */
	public function test_second_run_with_etag_still_produces_entries(): void {
		$config = array(
			'ics_url'            => self::$ics_etag_url,
			'ics_lookahead_days' => 0,
			'ics_max_items'      => 50,
		);

		$provider = new ICS_Provider();

		$context = new Provider_Context( $this->source_id );
		$this->assertNotEmpty( $provider->fetch( $config, $context ) );

		// a stored ETag would be sent on the next run.
		$context->mark_as_run();

		$this->assertNotEmpty( $provider->fetch( $config, new Provider_Context( $this->source_id ) ) );
	}

	/**
	 * Every entry must carry an external ID, otherwise dedupe cannot work.
	 *
	 * @return void
	 */
	public function test_every_entry_has_an_external_id(): void {
		$entries = $this->fetch();

		$this->assertNotEmpty( $entries );

		foreach ( $entries as $entry ) {
			$this->assertStringStartsWith( 'ics-', $entry->external_id );
		}
	}

	/**
	 * The maximum number of items must be respected.
	 *
	 * @return void
	 */
	public function test_max_items_is_respected(): void {
		$this->assertCount( 1, $this->fetch( array( 'ics_max_items' => 1 ) ) );
	}

	/**
	 * The language must stay empty so the source can decide it.
	 *
	 * @return void
	 */
	public function test_language_is_left_to_the_source(): void {
		foreach ( $this->fetch() as $entry ) {
			$this->assertNull( $entry->language );
		}
	}

	/**
	 * A webcal address must be requested over https.
	 *
	 * @return void
	 */
	public function test_webcal_url_is_rewritten(): void {
		$url = 'webcal://' . substr( self::$ics_url, 8 );

		$this->assertNotEmpty( $this->fetch( array( 'ics_url' => $url ) ) );
	}

	/**
	 * A text longer than an entry may be must be shortened.
	 *
	 * @return void
	 */
	public function test_long_text_is_shortened(): void {
		$method = new ReflectionMethod( ICS_Provider::class, 'limit_text_length' );

		$short = $method->invoke( new ICS_Provider(), 'Currently at WordCamp Europe' );
		$long  = $method->invoke( new ICS_Provider(), str_repeat( 'ä', 5000 ) );

		$this->assertSame( 'Currently at WordCamp Europe', $short );
		$this->assertSame( 2000, mb_strlen( $long ) );
		$this->assertStringEndsWith( '…', $long );
	}
}
