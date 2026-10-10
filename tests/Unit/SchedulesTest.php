<?php
/**
 * File to test the schedule objects.
 *
 * Every test here runs against a database where the interval options do not
 * exist — the state a site is in directly after activation. That is exactly
 * where the typed $interval property blew up before.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Tests\Unit;

use NowPagesThreadi\Plugin\Intervals;
use NowPagesThreadi\Plugin\Schedules;
use NowPagesThreadi\Plugin\Schedules\Import;
use NowPagesThreadi\Plugin\Schedules\Report;
use NowPagesThreadi\Plugin\Schedules_Base;
use NowPagesThreadi\Tests\NowPagesTestCase;

/**
 * Object to test the schedules.
 */
class SchedulesTest extends NowPagesTestCase {
	/**
	 * Remove the interval options before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		delete_option( 'now_pages_threadi_schedule_interval' );
		delete_option( 'now_pages_threadi_email_interval_report' );
		delete_option( 'now_pages_threadi_email_report' );
	}

	/**
	 * The import schedule must be constructible without any option set.
	 *
	 * @return void
	 */
	public function test_import_is_constructible_without_options(): void {
		$schedule = new Import();

		$this->assertInstanceOf( Schedules_Base::class, $schedule );
		$this->assertNotSame( '', $schedule->get_interval() );
	}

	/**
	 * The report schedule must be constructible without any option set.
	 *
	 * @return void
	 */
	public function test_report_is_constructible_without_options(): void {
		$schedule = new Report();

		$this->assertInstanceOf( Schedules_Base::class, $schedule );
		$this->assertNotSame( '', $schedule->get_interval() );
	}

	/**
	 * Without an option the class default must be used.
	 *
	 * @return void
	 */
	public function test_default_interval_is_used_without_an_option(): void {
		$schedule = new Import();

		$this->assertSame( $schedule->get_default_interval(), $schedule->get_interval() );
	}

	/**
	 * A configured option must win over the class default.
	 *
	 * @return void
	 */
	public function test_configured_interval_wins(): void {
		update_option( 'now_pages_threadi_schedule_interval', 'daily' );

		$this->assertSame( 'daily', ( new Import() )->get_interval() );
	}

	/**
	 * An empty option must fall back to the class default.
	 *
	 * @return void
	 */
	public function test_empty_option_falls_back_to_the_default(): void {
		update_option( 'now_pages_threadi_schedule_interval', '' );

		$schedule = new Import();

		$this->assertSame( $schedule->get_default_interval(), $schedule->get_interval() );
	}

	/**
	 * The interval must be filterable.
	 *
	 * @return void
	 */
	public function test_interval_can_be_filtered(): void {
		add_filter( 'now_pages_threadi_schedule_interval', static fn (): string => 'hourly' );

		$interval = ( new Import() )->get_interval();

		remove_all_filters( 'now_pages_threadi_schedule_interval' );

		$this->assertSame( 'hourly', $interval );
	}

	/**
	 * Every schedule must have its own event name.
	 *
	 * @return void
	 */
	public function test_schedules_have_distinct_names(): void {
		$this->assertNotSame( ( new Import() )->get_name(), ( new Report() )->get_name() );
	}

	/**
	 * Every registered schedule must be resolvable back to its object.
	 *
	 * A schedule missing from the registry gets an event without an action,
	 * so it runs into nothing.
	 *
	 * @return void
	 */
	public function test_every_registered_schedule_resolves_to_an_object(): void {
		$schedules_obj = Schedules::get_instance();
		$names         = $schedules_obj->get_schedule_object_names();

		$this->assertNotEmpty( $names );

		foreach ( $names as $class_name ) {
			$this->assertTrue( class_exists( $class_name ), 'The class ' . $class_name . ' does not exist.' );

			$schedule = new $class_name();

			$this->assertInstanceOf( Schedules_Base::class, $schedule );

			$this->assertInstanceOf(
				Schedules_Base::class,
				$schedules_obj->get_schedule_object_by_name( $schedule->get_name() ),
				'The schedule ' . $schedule->get_name() . ' could not be resolved by its event name.'
			);
		}
	}

	/**
	 * The report schedule must be part of the registry.
	 *
	 * @return void
	 */
	public function test_report_schedule_is_registered(): void {
		$this->assertInstanceOf(
			Report::class,
			Schedules::get_instance()->get_schedule_object_by_name( ( new Report() )->get_name() )
		);
	}

	/**
	 * A disabled report must not install an event.
	 *
	 * @return void
	 */
	public function test_disabled_report_installs_no_event(): void {
		update_option( 'now_pages_threadi_email_report', 0 );

		$schedule = new Report();

		$schedule->delete();
		$schedule->install();

		$this->assertFalse( $schedule->get_event() );
	}

	/**
	 * An enabled report must install an event.
	 *
	 * @return void
	 */
	public function test_enabled_report_installs_an_event(): void {
		update_option( 'now_pages_threadi_email_report', 1 );

		$schedule = new Report();

		$schedule->delete();
		$schedule->install();

		$this->assertNotFalse( $schedule->get_event() );

		$schedule->delete();
	}

	/**
	 * Deleting must remove the event again.
	 *
	 * @return void
	 */
	public function test_delete_removes_the_event(): void {
		update_option( 'now_pages_threadi_email_report', 1 );

		$schedule = new Report();

		$schedule->install();
		$schedule->delete();

		$this->assertFalse( $schedule->get_event() );
	}

	/**
	 * Count how often a hook is scheduled, across all timestamps.
	 *
	 * @param string $hook The name of the event.
	 *
	 * @return int
	 */
	private function count_events( string $hook ): int {
		$count = 0;

		foreach ( _get_cron_array() as $events ) {
			if ( isset( $events[ $hook ] ) ) {
				$count += count( $events[ $hook ] );
			}
		}

		return $count;
	}

	/**
	 * Installing twice must not create a second event.
	 *
	 * @return void
	 */
	public function test_installing_twice_creates_only_one_event(): void {
		update_option( 'now_pages_threadi_email_report', 1 );

		$schedule = new Report();

		$schedule->delete();
		$schedule->install();
		$schedule->install();

		$this->assertSame( 1, $this->count_events( $schedule->get_name() ) );

		$schedule->delete();
	}

	/**
	 * A changed interval must reschedule the event.
	 *
	 * wp_schedule_event() refuses to change the recurrence of an existing
	 * event, so install() has to delete it first.
	 *
	 * @return void
	 */
	public function test_changed_interval_reschedules_the_event(): void {
		$intervals = array_keys( Intervals::get_instance()->get_intervals_for_settings( true ) );

		// two different, registered intervals are needed for this test.
		if ( count( $intervals ) < 2 ) {
			$this->markTestSkipped( 'Not enough registered intervals to test a change.' );
		}

		update_option( 'now_pages_threadi_email_report', 1 );
		update_option( 'now_pages_threadi_email_interval_report', $intervals[0] );

		$schedule = new Report();
		$schedule->delete();
		$schedule->install();

		$this->assertSame( $intervals[0], wp_get_schedule( $schedule->get_name(), $schedule->get_args() ) );

		// change the interval and install again.
		update_option( 'now_pages_threadi_email_interval_report', $intervals[1] );

		$schedule = new Report();
		$schedule->install();

		$this->assertSame( $intervals[1], wp_get_schedule( $schedule->get_name(), $schedule->get_args() ) );
		$this->assertSame( 1, $this->count_events( $schedule->get_name() ) );

		$schedule->delete();
	}

	/**
	 * The reconcile must remove duplicates of an event and keep exactly one.
	 *
	 * @return void
	 */
	public function test_reconcile_removes_duplicate_events(): void {
		update_option( 'now_pages_threadi_email_report', 1 );

		$schedule = new Report();
		$schedule->delete();
		$schedule->install();

		// add a duplicate of the event at another timestamp, like a second request would do.
		$cron  = _get_cron_array();
		$key   = md5( serialize( $schedule->get_args() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		$event = array();
		foreach ( $cron as $events ) {
			if ( isset( $events[ $schedule->get_name() ][ $key ] ) ) {
				$event = $events[ $schedule->get_name() ][ $key ];
			}
		}
		$cron[ time() + DAY_IN_SECONDS + 7 ][ $schedule->get_name() ][ $key ] = $event;
		_set_cron_array( $cron );

		$this->assertSame( 2, $schedule->get_event_count() );

		// reconcile only the report schedule.
		$only_report = static fn (): array => array( '\NowPagesThreadi\Plugin\Schedules\Report' );
		add_filter( 'now_pages_threadi_schedules', $only_report );
		Schedules::get_instance()->reconcile_events( Schedules::get_instance()->get_wp_events() );
		remove_filter( 'now_pages_threadi_schedules', $only_report );

		$this->assertSame( 1, $this->count_events( $schedule->get_name() ) );

		$schedule->delete();
	}

	/**
	 * A numeric hook name of another plugin must not break the list of our events.
	 *
	 * Numeric strings become integer keys in the cron array, which str_contains() refuses under strict types.
	 *
	 * @return void
	 */
	public function test_numeric_hook_name_is_ignored(): void {
		$cron = _get_cron_array();
		$copy = $cron;

		$cron[ time() + HOUR_IN_SECONDS ]['12345'] = array(
			md5( serialize( array() ) ) => array( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
				'schedule' => false,
				'args'     => array(),
			),
		);
		_set_cron_array( $cron );

		$events = Schedules::get_instance()->get_wp_events();

		_set_cron_array( $copy );

		$this->assertArrayNotHasKey( 12345, $events );
	}

	/**
	 * An interval that is not registered must fall back to the class default.
	 *
	 * Left-over values from another plugin would otherwise make
	 * wp_schedule_event() fail with "Event schedule does not exist".
	 *
	 * @return void
	 */
	public function test_unknown_interval_falls_back_to_the_default(): void {
		update_option( 'now_pages_threadi_email_report', 1 );
		update_option( 'now_pages_threadi_email_interval_report', 'some_other_plugin_interval' );

		$schedule = new Report();
		$schedule->delete();
		$schedule->install();

		$this->assertSame(
			$schedule->get_default_interval(),
			wp_get_schedule( $schedule->get_name(), $schedule->get_args() )
		);

		$schedule->delete();
	}

	/**
	 * An interval which does not exist must not be saved: the saved one is kept.
	 *
	 * @return void
	 */
	public function test_an_unknown_interval_keeps_the_saved_one(): void {
		update_option( 'now_pages_threadi_schedule_interval', Intervals::DISABLED );

		$callback = array( \NowPagesThreadi\Plugin\Settings::get_instance(), 'sanitize_interval_setting' );
		add_filter( 'sanitize_option_now_pages_threadi_schedule_interval', $callback );

		$this->assertSame( Intervals::DISABLED, sanitize_option( 'now_pages_threadi_schedule_interval', 'now_pages_threadi_does_not_exist' ) );
		$this->assertSame( Intervals::DISABLED, sanitize_option( 'now_pages_threadi_schedule_interval', '' ) );

		remove_filter( 'sanitize_option_now_pages_threadi_schedule_interval', $callback );
	}
}
