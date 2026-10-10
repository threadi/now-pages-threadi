<?php
/**
 * File to test the provider for recurring entries.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Tests\Unit;

use DateTimeImmutable;
use NowPagesThreadi\NowPages\Confirmation;
use NowPagesThreadi\NowPages\Entries;
use NowPagesThreadi\Providers\Provider_Context;
use NowPagesThreadi\Providers\Providers;
use NowPagesThreadi\Providers\Recurring_Provider;
use NowPagesThreadi\Sources\Source_Builder;
use NowPagesThreadi\Sources\Sources;
use NowPagesThreadi\Tests\NowPagesTestCase;
use WP_Post;

/**
 * Object to test Recurring_Provider.
 */
class RecurringProviderTest extends NowPagesTestCase {
	/**
	 * Start every test from the same settings.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		update_option( 'now_pages_threadi_frontend_period', 'week' );
		update_option( 'now_pages_threadi_email_confirm_entry', 0 );
		delete_option( Entries::OPTION_DELETED );
	}

	/**
	 * Return the provider.
	 *
	 * @return Recurring_Provider
	 */
	private function get_provider(): Recurring_Provider {
		$provider = Providers::get_instance()->get_provider( 'recurring' );

		$this->assertInstanceOf( Recurring_Provider::class, $provider );

		return $provider;
	}

	/**
	 * Return the day an entry is due on.
	 *
	 * @param array<string,mixed> $config The configuration.
	 * @param string              $now    The current time.
	 *
	 * @return string
	 */
	private function get_occurrence( array $config, string $now ): string {
		return $this->get_provider()->get_occurrence( $config, new DateTimeImmutable( $now, wp_timezone() ) )->format( 'Y-m-d H:i' );
	}

	/**
	 * Create a published source of this provider.
	 *
	 * @param array<string,mixed> $config The configuration.
	 *
	 * @return int
	 */
	private function create_source( array $config = array() ): int {
		$source_id = self::factory()->post->create(
			array(
				'post_type'   => 'np_threadi_source',
				'post_status' => 'publish',
				'post_title'  => 'Sports',
			)
		);

		update_post_meta( $source_id, 'provider', 'recurring' );

		foreach ( array_merge( array( 'recurring_text' => 'Went to the gym' ), $config ) as $name => $value ) {
			update_post_meta( $source_id, $name, $value );
		}

		return $source_id;
	}

	/**
	 * Return the entries of a source.
	 *
	 * @param int $source_id The source.
	 *
	 * @return array<int,WP_Post>
	 */
	private function get_entries( int $source_id ): array {
		return get_posts(
			array(
				'post_type'   => 'np_threadi_entry',
				'post_status' => array( 'any', 'trash' ),
				'post_parent' => $source_id,
				'numberposts' => -1,
				'orderby'     => 'ID',
				'order'       => 'ASC',
			)
		);
	}

	/**
	 * The provider is registered, fetches by itself and is no push provider.
	 *
	 * @return void
	 */
	public function test_the_provider_is_registered(): void {
		$provider = $this->get_provider();

		$this->assertFalse( $provider->is_push() );
		$this->assertTrue( $provider->is_available() );
		$this->assertSame( array( 'recurring_text' ), $provider->get_identifying_fields() );
	}

	/**
	 * Without a text there is nothing to repeat.
	 *
	 * @return void
	 */
	public function test_without_a_text_nothing_is_created(): void {
		$this->assertSame( array(), $this->get_provider()->fetch( array( 'recurring_text' => '  ' ), new Provider_Context( 0 ) ) );
		$this->assertSame( array(), $this->get_provider()->fetch( array(), new Provider_Context( 0 ) ) );
	}

	/**
	 * A daily entry is due today.
	 *
	 * @return void
	 */
	public function test_a_daily_entry_is_due_today(): void {
		$this->assertSame( '2026-10-07 00:00', $this->get_occurrence( array( 'recurring_rhythm' => 'daily' ), '2026-10-07 15:30:00' ) );
	}

	/**
	 * A weekly entry without a chosen day is due on the monday of the week.
	 *
	 * @return void
	 */
	public function test_a_weekly_entry_is_due_on_monday_by_default(): void {
		// 2026-10-07 is a wednesday.
		$this->assertSame( '2026-10-05 00:00', $this->get_occurrence( array( 'recurring_rhythm' => 'weekly' ), '2026-10-07 15:30:00' ) );
		$this->assertSame( '2026-10-05 00:00', $this->get_occurrence( array( 'recurring_rhythm' => 'weekly' ), '2026-10-05 00:00:00' ) );
		$this->assertSame( '2026-10-05 00:00', $this->get_occurrence( array( 'recurring_rhythm' => 'weekly' ), '2026-10-11 23:59:00' ) );
	}

	/**
	 * A weekly entry is due on the latest of its chosen days.
	 *
	 * @return void
	 */
	public function test_a_weekly_entry_is_due_on_the_latest_chosen_day(): void {
		$config = array(
			'recurring_rhythm'   => 'weekly',
			'recurring_weekdays' => array( 'tue', 'thu' ),
		);

		// wednesday: tuesday was the latest one.
		$this->assertSame( '2026-10-06 00:00', $this->get_occurrence( $config, '2026-10-07 15:30:00' ) );

		// thursday itself.
		$this->assertSame( '2026-10-08 00:00', $this->get_occurrence( $config, '2026-10-08 08:00:00' ) );

		// monday: the thursday of the week before.
		$this->assertSame( '2026-10-01 00:00', $this->get_occurrence( $config, '2026-10-05 08:00:00' ) );
	}

	/**
	 * Days nobody offered are ignored.
	 *
	 * @return void
	 */
	public function test_unknown_weekdays_are_ignored(): void {
		$config = array(
			'recurring_rhythm'   => 'weekly',
			'recurring_weekdays' => array( 'someday' ),
		);

		$this->assertSame( '2026-10-05 00:00', $this->get_occurrence( $config, '2026-10-07 15:30:00' ) );
	}

	/**
	 * A monthly entry is due on the first day of the month.
	 *
	 * @return void
	 */
	public function test_a_monthly_entry_is_due_on_the_first(): void {
		$this->assertSame( '2026-10-01 00:00', $this->get_occurrence( array( 'recurring_rhythm' => 'monthly' ), '2026-10-31 23:00:00' ) );
	}

	/**
	 * Without a rhythm the entry follows the period of the now page.
	 *
	 * The period reads the clock itself, so these compare with today.
	 *
	 * @return void
	 */
	public function test_the_default_rhythm_follows_the_period_of_the_now_page(): void {
		$now = new DateTimeImmutable( 'now', wp_timezone() );

		update_option( 'now_pages_threadi_frontend_period', 'week' );
		$this->assertSame( $now->modify( 'monday this week' )->format( 'Y-m-d' ), $this->get_provider()->get_occurrence( array(), $now )->format( 'Y-m-d' ) );

		update_option( 'now_pages_threadi_frontend_period', 'month' );
		$this->assertSame( $now->format( 'Y-m-01' ), $this->get_provider()->get_occurrence( array(), $now )->format( 'Y-m-d' ) );

		update_option( 'now_pages_threadi_frontend_period', 'day' );
		$this->assertSame( $now->format( 'Y-m-d' ), $this->get_provider()->get_occurrence( array(), $now )->format( 'Y-m-d' ) );
	}

	/**
	 * A now page which shows hours gets one entry per day, not per hour.
	 *
	 * @return void
	 */
	public function test_short_periods_get_one_entry_per_day(): void {
		$now = new DateTimeImmutable( 'now', wp_timezone() );

		foreach ( array( 'hour', 'live' ) as $period ) {
			update_option( 'now_pages_threadi_frontend_period', $period );

			$this->assertSame( $now->format( 'Y-m-d 00:00' ), $this->get_provider()->get_occurrence( array(), $now )->format( 'Y-m-d H:i' ) );
		}
	}

	/**
	 * An unknown rhythm falls back to the period of the now page.
	 *
	 * @return void
	 */
	public function test_an_unknown_rhythm_falls_back_to_the_period(): void {
		$now = new DateTimeImmutable( 'now', wp_timezone() );

		$this->assertSame(
			$this->get_provider()->get_occurrence( array(), $now )->format( 'c' ),
			$this->get_provider()->get_occurrence( array( 'recurring_rhythm' => 'yearly' ), $now )->format( 'c' )
		);
	}

	/**
	 * The entry carries the sentence, the day it is due on and an ID which belongs to that day.
	 *
	 * @return void
	 */
	public function test_the_entry_carries_the_text_and_its_day(): void {
		$entries = $this->get_provider()->fetch(
			array(
				'recurring_text'   => ' Went to <b>the gym</b> ',
				'recurring_rhythm' => 'daily',
			),
			new Provider_Context( 0 )
		);

		$today = ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d' );

		$this->assertCount( 1, $entries );
		$this->assertSame( 'Went to the gym', $entries[0]->text );
		$this->assertSame( 'recurring-' . $today, $entries[0]->external_id );
		$this->assertSame( $today . ' 00:00', $entries[0]->date->format( 'Y-m-d H:i' ) );
		$this->assertSame( 'publish', $entries[0]->status );
	}

	/**
	 * A source which asks first lets its entry wait.
	 *
	 * @return void
	 */
	public function test_a_source_which_asks_first_lets_its_entry_wait(): void {
		$entries = $this->get_provider()->fetch(
			array(
				'recurring_text'    => 'Went to the gym',
				'recurring_confirm' => 1,
			),
			new Provider_Context( 0 )
		);

		$this->assertSame( Confirmation::STATUS, $entries[0]->status );
	}

	/**
	 * An import creates the entry once, however often it runs.
	 *
	 * @return void
	 */
	public function test_an_import_creates_the_entry_once(): void {
		$source_id = $this->create_source( array( 'recurring_rhythm' => 'daily' ) );

		Sources::get_instance()->import();
		Sources::get_instance()->import();

		$entries = $this->get_entries( $source_id );

		$this->assertCount( 1, $entries );
		$this->assertSame( 'publish', $entries[0]->post_status );
		$this->assertSame( 'Went to the gym', $entries[0]->post_content );
		$this->assertSame( ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d' ) . ' 00:00:00', $entries[0]->post_date );
	}

	/**
	 * A repetition deleted by hand does not come back: the routine was skipped.
	 *
	 * @return void
	 */
	public function test_a_deleted_repetition_does_not_come_back(): void {
		$source_id = $this->create_source( array( 'recurring_rhythm' => 'daily' ) );

		Sources::get_instance()->import();

		wp_delete_post( $this->get_entries( $source_id )[0]->ID, true );

		Sources::get_instance()->import();

		$this->assertCount( 0, $this->get_entries( $source_id ) );
	}

	/**
	 * The entry gets the category of its source, like every other entry.
	 *
	 * @return void
	 */
	public function test_the_entry_gets_the_category_of_its_source(): void {
		$term_id   = self::factory()->term->create(
			array(
				'taxonomy' => \NowPagesThreadi\NowPages\Categories::TAXONOMY,
				'name'     => 'Sports',
			)
		);
		$source_id = $this->create_source( array( \NowPagesThreadi\NowPages\Categories::SOURCE_DEFAULT_META => $term_id ) );

		Sources::get_instance()->import();

		$this->assertSame( array( $term_id ), wp_get_object_terms( $this->get_entries( $source_id )[0]->ID, \NowPagesThreadi\NowPages\Categories::TAXONOMY, array( 'fields' => 'ids' ) ) );
	}

	/**
	 * The check shows the entry which would be created, without creating anything.
	 *
	 * @return void
	 */
	public function test_the_check_shows_the_entry_without_creating_it(): void {
		$check = Source_Builder::get_instance()->check( $this->get_provider(), array( 'recurring_text' => 'Went to the gym' ) );

		$this->assertSame( Source_Builder::CHECK_OK, $check['status'] );
		$this->assertSame( 'Went to the gym', $check['samples'][0]['text'] );
		$this->assertCount( 0, get_posts( array( 'post_type' => 'np_threadi_entry', 'post_status' => 'any' ) ) );
	}

	/**
	 * The check asks for the text instead of running without one.
	 *
	 * @return void
	 */
	public function test_the_check_asks_for_a_missing_text(): void {
		$check = Source_Builder::get_instance()->check( $this->get_provider(), array() );

		$this->assertSame( Source_Builder::CHECK_INCOMPLETE, $check['status'] );
	}

	/**
	 * A source created through the builder is named after its sentence.
	 *
	 * @return void
	 */
	public function test_a_new_source_is_named_after_its_sentence(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$resolved = Source_Builder::get_instance()->resolve(
			'',
			'recurring',
			array(
				'recurring_text'     => 'Went to the gym',
				'recurring_rhythm'   => 'weekly',
				'recurring_weekdays' => 'tue, thu',
				'recurring_confirm'  => 'true',
			)
		);

		$this->assertIsArray( $resolved );
		$this->assertSame( 'Went to the gym', $resolved['title'] );
		$this->assertSame( 'weekly', $resolved['config']['recurring_rhythm'] );
		$this->assertSame( array( 'tue', 'thu' ), $resolved['config']['recurring_weekdays'] );
		$this->assertSame( 1, $resolved['config']['recurring_confirm'] );
		$this->assertSame( array(), $resolved['missing'] );
	}

	/**
	 * A rhythm nobody offered is not stored: the default is used instead.
	 *
	 * @return void
	 */
	public function test_an_invented_rhythm_is_not_stored(): void {
		$resolved = Source_Builder::get_instance()->resolve(
			'',
			'recurring',
			array(
				'recurring_text'   => 'Went to the gym',
				'recurring_rhythm' => 'every full moon',
			)
		);

		$this->assertIsArray( $resolved );
		$this->assertSame( '', $resolved['config']['recurring_rhythm'] );
		$this->assertSame( $this->get_occurrence( array(), '2026-10-07 12:00:00' ), $this->get_occurrence( $resolved['config'], '2026-10-07 12:00:00' ) );
	}

	/**
	 * The same sentence twice is the same source.
	 *
	 * @return void
	 */
	public function test_the_same_sentence_twice_is_a_duplicate(): void {
		$source_id = $this->create_source();

		$resolved = Source_Builder::get_instance()->resolve( '', 'recurring', array( 'recurring_text' => 'Went to the gym' ) );

		$this->assertIsArray( $resolved );
		$this->assertSame( $source_id, $resolved['duplicate_of'] );
	}

	/**
	 * The preset asks for the sentence only.
	 *
	 * @return void
	 */
	public function test_the_preset_asks_for_the_sentence_only(): void {
		$resolved = Source_Builder::get_instance()->resolve( 'routine', '', array( 'text' => 'Went to the market' ) );

		$this->assertIsArray( $resolved );
		$this->assertSame( 'recurring', $resolved['provider']->get_slug() );
		$this->assertSame( 'Went to the market', $resolved['config']['recurring_text'] );
		$this->assertSame( 'period', $resolved['config']['recurring_rhythm'] );
		$this->assertSame( 0, $resolved['config']['recurring_confirm'] );
		$this->assertSame( 'Went to the market', $resolved['title'] );

		$open = Source_Builder::get_instance()->resolve( 'routine', '', array() );

		$this->assertIsArray( $open );
		$this->assertSame( array( 'text' ), wp_list_pluck( $open['missing'], 'name' ) );
	}

	/**
	 * The catalog names the choices of the rhythm, also without the choices which name users.
	 *
	 * @return void
	 */
	public function test_the_catalog_names_the_rhythms(): void {
		$catalog = Source_Builder::get_instance()->get_catalog( false );

		foreach ( $catalog['providers'] as $provider ) {
			if ( 'recurring' !== $provider['slug'] ) {
				continue;
			}

			$fields = array_column( $provider['fields'], null, 'name' );

			$this->assertSame( array( 'period', 'daily', 'weekly', 'monthly' ), array_keys( $fields['recurring_rhythm']['options'] ) );
			$this->assertSame( array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ), array_keys( $fields['recurring_weekdays']['options'] ) );
			$this->assertFalse( $provider['push'] );

			return;
		}

		$this->fail( 'The provider recurring is missing in the catalog.' );
	}

	/**
	 * Choices which are built at runtime still stay on the website.
	 *
	 * @return void
	 */
	public function test_runtime_choices_are_still_left_out(): void {
		$catalog = Source_Builder::get_instance()->get_catalog( false );

		foreach ( $catalog['providers'] as $provider ) {
			if ( 'wordpress_site' !== $provider['slug'] ) {
				continue;
			}

			$fields = array_column( $provider['fields'], null, 'name' );

			$this->assertArrayNotHasKey( 'options', $fields['wp_author'] );

			return;
		}

		$this->fail( 'The provider wordpress_site is missing in the catalog.' );
	}
}
