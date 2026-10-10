<?php
/**
 * This file contains the two months period.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Digests\Periods;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Object for the two months period.
 */
class Months2 extends Months_Base {
	/**
	 * The slug of this period.
	 *
	 * @var string
	 */
	protected string $slug = '2month';

	/**
	 * Return the period label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Two months', 'now-pages-threadi' );
	}

	/**
	 * Return a short description of what this period covers.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'The month a date falls into and the one before it.', 'now-pages-threadi' );
	}

	/**
	 * Return how many months this period covers.
	 *
	 * @return int
	 */
	protected function get_months(): int {
		return 2;
	}
}
