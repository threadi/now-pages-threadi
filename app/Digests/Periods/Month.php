<?php
/**
 * This file contains the month period.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Digests\Periods;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Object for the month period.
 */
class Month extends Months_Base {
	/**
	 * The slug of this period.
	 *
	 * @var string
	 */
	protected string $slug = 'month';

	/**
	 * Return the period label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Month', 'threadi-now-pages' );
	}

	/**
	 * Return a short description of what this period covers.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'The whole month a date falls into.', 'threadi-now-pages' );
	}

	/**
	 * Return how many months this period covers.
	 *
	 * @return int
	 */
	protected function get_months(): int {
		return 1;
	}
}
