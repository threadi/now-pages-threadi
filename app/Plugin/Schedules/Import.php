<?php
/**
 * This file contains the scheduler for pull-based providers.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Plugin\Schedules;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Plugin\Intervals;
use ThreadiNowPages\Plugin\Log;
use ThreadiNowPages\Plugin\Schedules_Base;
use ThreadiNowPages\Sources\Sources;

/**
 * Schedules and runs the periodic fetch of all configured providers.
 */
class Import extends Schedules_Base {
	/**
	 * Name of this event.
	 *
	 * @var string
	 */
	protected string $name = 'threadi_now_pages_schedule_import';

	/**
	 * Name of the option used to enable this event.
	 *
	 * @var string
	 */
	protected string $option_name = 'threadi_now_pages_schedule_import_status';

	/**
	 * Name of the option used to define the interval for this event.
	 *
	 * @var string
	 */
	protected string $interval_option_name = 'threadi_now_pages_schedule_interval';

	/**
	 * Define the default interval.
	 *
	 * @var string
	 */
	protected string $default_interval = 'threadi_now_pages_hourly';

	/**
	 * Name of the log category.
	 *
	 * @var string
	 */
	protected string $log_category = 'provider';

	/**
	 * Initialize this schedule.
	 */
	public function __construct() {
		// get interval from settings.
		$this->interval = (string) get_option( $this->get_interval_option_name() );

		// fall back to the class default if the option is empty.
		if ( empty( $this->interval ) ) {
			$this->interval = $this->get_default_interval();
		}
	}

	/**
	 * Return whether this schedule is enabled.
	 *
	 * The import is disabled via its interval setting, not only via its option.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		// bail if the import has been disabled via the interval.
		if ( Intervals::DISABLED === $this->get_interval() ) {
			return false;
		}

		return parent::is_enabled();
	}

	/**
	 * Run every configured provider-instance and forward its entries.
	 *
	 * @return void
	 */
	public function run(): void {
		// log this event.
		Log::get_instance()->add( __( 'Import has been started.', 'threadi-now-pages' ), 'info', 'import' );

		Sources::get_instance()->import();

		// log this event.
		Log::get_instance()->add( __( 'Import has been ended.', 'threadi-now-pages' ), 'info', 'import' );
	}
}
