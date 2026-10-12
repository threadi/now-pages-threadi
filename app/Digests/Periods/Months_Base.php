<?php
/**
 * This file contains the base for periods covering whole months.
 *
 * One month, two months, six months - they differ in a single number, so
 * they share everything else.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Digests\Periods;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Digests\Period_Base;

/**
 * Object for periods covering whole months.
 */
abstract class Months_Base extends Period_Base {
	/**
	 * Return how many months this period covers.
	 *
	 * @return int
	 */
	abstract protected function get_months(): int;

	/**
	 * Return the raw window this period covers.
	 *
	 * The window ends with the month a date falls into and reaches back
	 * over as many whole months as this period covers.
	 *
	 * @param array<string,mixed> $args The resolved arguments.
	 *
	 * @return array{after:string,before:string}
	 */
	public function get_range( array $args ): array {
		$timestamp = $this->get_timestamp( $args );
		$months    = max( 1, $this->get_months() );

		// calculate from the first day of the month: going back from the 31st
		// would overflow into the following month for shorter months.
		// gmmktime() normalizes months below 1 into the previous years.
		$first = gmmktime( 0, 0, 0, (int) gmdate( 'n', $timestamp ) - ( $months - 1 ), 1, (int) gmdate( 'Y', $timestamp ) );

		return array(
			'after'  => gmdate( 'Y-m-01', (int) $first ) . ' 00:00:00',
			'before' => gmdate( 'Y-m-t', $timestamp ) . ' 23:59:59',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_note( array $args, array $range ): string {
		if ( 1 === $this->get_months() ) {
			return sprintf(
			/* translators: %1$s will be replaced by a month and year. */
				__( 'From %1$s', 'threadi-now-pages' ),
				mysql2date( 'F Y', $range['after'] )
			);
		}

		return sprintf(
		/* translators: %1$s will be replaced by a month and year, %2$s by a month and year. */
			__( 'From %1$s to %2$s', 'threadi-now-pages' ),
			mysql2date( 'F Y', $range['after'] ),
			mysql2date( 'F Y', $range['before'] )
		);
	}
}
