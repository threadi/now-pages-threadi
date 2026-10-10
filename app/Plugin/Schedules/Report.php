<?php
/**
 * File to handle the report-schedule.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Plugin\Schedules;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Plugin\Schedules_Base;

/**
 * Object for this schedule.
 */
class Report extends Schedules_Base {

	/**
	 * Name of this event.
	 *
	 * @var string
	 */
	protected string $name = 'now_pages_threadi_schedule_report';

	/**
	 * Name of the option used to enable this event.
	 *
	 * @var string
	 */
	protected string $option_name = 'now_pages_threadi_email_report';

	/**
	 * Name of the option used for the interval of this event.
	 *
	 * @var string
	 */
	protected string $interval_option_name = 'now_pages_threadi_email_interval_report';

	/**
	 * Define the default interval.
	 *
	 * @var string
	 */
	protected string $default_interval = 'now_pages_threadi_weekly';

	/**
	 * Send the first email one interval after the event has been created, not directly.
	 *
	 * @var bool
	 */
	protected bool $delay_first_run = true;

	/**
	 * Name of the log category.
	 *
	 * @var string
	 */
	protected string $log_category = 'emails';

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
	 * Run this schedule.
	 *
	 * @return void
	 */
	public function run(): void {
		// bail if setting is not enabled.
		if ( ! $this->is_enabled() ) {
			return;
		}

		// send the report.
		$report_mail_object = new \NowPagesThreadi\Plugin\Emails\Report();
		$report_mail_object->send();
	}

	/**
	 * Install this schedule.
	 *
	 * @return void
	 */
	public function install(): void {
		// bail if it is not enabled.
		if ( ! $this->is_enabled() ) {
			return;
		}

		// install this schedule.
		parent::install();
	}
}
