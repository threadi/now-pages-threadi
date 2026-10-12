<?php
/**
 * File to handle the schedule which shares the now page in the Fediverse.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Plugin\Schedules;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Plugin\Log;
use ThreadiNowPages\Plugin\Schedules_Base;

/**
 * Object to share what is due in the Fediverse.
 *
 * Runs hourly, whatever is shared: a summary is due at some hour after
 * its period ended and the privacy delay passed, a single entry as soon
 * as its own delay passed. The check itself costs next to nothing.
 */
class Fediverse extends Schedules_Base {
	/**
	 * Name of this event.
	 *
	 * @var string
	 */
	protected string $name = 'threadi_now_pages_schedule_fediverse';

	/**
	 * The default interval.
	 *
	 * @var string
	 */
	protected string $default_interval = 'threadi_now_pages_hourly';

	/**
	 * Define the log category.
	 *
	 * @var string
	 */
	protected string $log_category = 'system';

	/**
	 * Initialize this schedule.
	 */
	public function __construct() {
		$this->interval = $this->get_default_interval();
	}

	/**
	 * Return whether this schedule is needed.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		// bail if nothing is shared.
		if ( ! \ThreadiNowPages\Fediverse\Fediverse::get_instance()->is_enabled() ) {
			return false;
		}

		return parent::is_enabled();
	}

	/**
	 * Run this schedule.
	 *
	 * @return void
	 */
	public function run(): void {
		$created = \ThreadiNowPages\Fediverse\Fediverse::get_instance()->run();

		// bail if nothing was shared.
		if ( 0 === $created ) {
			return;
		}

		Log::get_instance()->add(
			sprintf(
				/* translators: %1$d will be replaced by a number. */
				_n( '%1$d post has been handed to the ActivityPub plugin to be shared in the Fediverse.', '%1$d posts have been handed to the ActivityPub plugin to be shared in the Fediverse.', $created, 'threadi-now-pages' ),
				$created
			),
			'info',
			$this->get_log_category()
		);
	}
}
